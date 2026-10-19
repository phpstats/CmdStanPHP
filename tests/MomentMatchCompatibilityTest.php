<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{DrawKernels, Loo, MomentMatch, Process};
use PHPUnit\Framework\Attributes\Group;
/**
 * Compares moment matching with loo::loo_moment_match.default on identical draws and analytic densities. Without split
 * the result must match loo; with split it must match loo's split with the exact inverse and two-half r_eff. loo builds
 * before stan-dev/loo#405 must differ wherever a shift is combined with scaling or a covariance map; later builds,
 * which made the same corrections, must match exactly.
 */
#[Group('compatibility')]
final class MomentMatchCompatibilityTest extends TestCase
{
    private const DESIGNS = ['shift_only' => MomentMatchFixture::SHIFT_ONLY, 'shift_scale' => MomentMatchFixture::SHIFT_SCALE, 'cov' => MomentMatchFixture::COV];
    private static ?array $reference = null;
    private static array $fixtures = [];
    protected function setUp(): void
    {
        parent::setUp();
        if (!DrawKernels::available()) { self::markTestSkipped('The phit posterior-draw kernels are required: '.implode(', ', DrawKernels::missing())); }
        if (self::$reference !== null) { return; }
        try { Process::run(['Rscript', '-e', 'stopifnot(requireNamespace("jsonlite", quietly=TRUE), requireNamespace("loo", quietly=TRUE))']); }
        catch (\Throwable $e) { self::markTestSkipped('R with loo and jsonlite required: '.$e->getMessage()); }
        $input = [];
        foreach (self::DESIGNS as $name => $design) {
            $f = self::$fixtures[$name] = MomentMatchFixture::regression(...$design);
            $input[$name] = ['x' => $f['x'], 'y' => $f['y'], 'prior' => MomentMatchFixture::PRIOR_PRECISION, 'upars' => $f['upars'], 'log_lik' => array_map(fn($it) => array_map('array_values', $it), $f['log_lik'])];
        }
        file_put_contents($this->dir.'/input.json', json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        Process::run(['Rscript', __DIR__.'/fixtures/moment_match.R', $this->dir.'/input.json', $this->dir.'/output.json'], timeout: 600);
        self::$reference = json_decode(file_get_contents($this->dir.'/output.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    private static function matched(string $name, bool $split): array
    {
        $f = self::$fixtures[$name];
        return MomentMatch::loo(Loo::loo($f['log_lik']), $f['upars'], $f['log_lik'], MomentMatchFixture::logProb($f), MomentMatchFixture::logLikI($f), split: $split);
    }
    private static function compare(array $reference, array $actual, string $label, float $tolerance = 1e-8): void
    {
        foreach (['elpd_loo', 'mcse_elpd_loo', 'p_loo', 'looic'] as $column) {
            foreach ($reference['pointwise'][$column] as $i => $v) { self::assertEqualsWithDelta($v, $actual['pointwise'][$i][$column], $tolerance * max(1.0, abs($v)), "$label $column [$i]"); }
        }
        foreach (['pareto_k', 'n_eff'] as $key) {
            foreach ($reference[$key] as $i => $v) { self::assertEqualsWithDelta($v, $actual['diagnostics'][$key][$i], $tolerance * max(1.0, abs($v)), "$label $key [$i]"); }
        }
        foreach (['elpd_loo', 'p_loo', 'looic'] as $j => $name) {
            self::assertEqualsWithDelta($reference['estimates']['Estimate'][$j], $actual['estimates'][$name]['Estimate'], $tolerance * max(1.0, abs($reference['estimates']['Estimate'][$j])), "$label $name");
            self::assertEqualsWithDelta($reference['estimates']['SE'][$j], $actual['estimates'][$name]['SE'], $tolerance * max(1.0, abs($reference['estimates']['SE'][$j])), "$label SE $name");
        }
    }
    public function testUnsplitMomentMatchingMatchesLoo(): void
    {
        foreach (array_keys(self::DESIGNS) as $name) { self::compare(self::$reference[$name]['unsplit'], self::matched($name, false), $name.' unsplit'); }
    }
    public function testSplitMatchesLooWithTheExactInverse(): void
    {
        foreach (array_keys(self::DESIGNS) as $name) { self::compare(self::$reference[$name]['exact_split'], self::matched($name, true), $name.' split'); }
    }
    /**
     * loo 2.10.1.9000 builds before stan-dev/loo#405 (merged 2026-10-13, d69d44f) differ from CmdStanPHP's split as
     * documented. Later builds made the same corrections, so their own split must match CmdStanPHP everywhere.
     */
    public function testDifferencesFromLooSplitAreTheDocumentedOnes(): void
    {
        $i = count(self::$fixtures['shift_only']['x']) - 1;
        // Shifts only: the inverses agree, so elpd matches loo (the two-half r_eff can still change mcse and n_eff).
        $loo = self::$reference['shift_only']['loo_split']; $exact = self::$reference['shift_only']['exact_split'];
        self::assertEqualsWithDelta($loo['pointwise']['elpd_loo'][$i], $exact['pointwise']['elpd_loo'][$i], 1e-12);
        $same = fn(string $name) => abs(self::$reference[$name]['loo_split']['pointwise']['elpd_loo'][count(self::$fixtures[$name]['x']) - 1] - self::$reference[$name]['exact_split']['pointwise']['elpd_loo'][count(self::$fixtures[$name]['x']) - 1]) <= 1e-6;
        if ($same('shift_scale') || $same('cov')) {
            // Corrected loo: its split is the reference for every design.
            foreach (array_keys(self::DESIGNS) as $name) { self::compare(self::$reference[$name]['loo_split'], self::matched($name, true), $name.' corrected loo split'); }
            return;
        }
        // Earlier loo: with scaling or a covariance map, it evaluates the split proposal density at the wrong inverse
        // points (MomentMatchTest::testAffineLogDeterminantEntersTheSplitProposal measures the resulting error).
        foreach (['shift_scale', 'cov'] as $name) {
            $i = count(self::$fixtures[$name]['x']) - 1;
            self::assertNotEqualsWithDelta(self::$reference[$name]['loo_split']['pointwise']['elpd_loo'][$i], self::$reference[$name]['exact_split']['pointwise']['elpd_loo'][$i], 1e-6, $name);
        }
    }
}
