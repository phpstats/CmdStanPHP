<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanMCMC, CmdStanModel, DrawKernels, Loo, MomentMatch, NativeBuild, Process};
use PHPUnit\Framework\Attributes\Group;
/** CmdStanMCMC::loo(moment_match: true) on a real Stan fit with a constrained parameter and a high-leverage outlier. */
#[Group('integration')]
final class MomentMatchIntegrationTest extends TestCase
{
    private const MODEL = <<<'STAN'
        data { int N; vector[N] x; vector[N] y; }
        parameters { real alpha; real beta; real<lower=0> sigma; }
        model { alpha ~ normal(0, 10); beta ~ normal(0, 10); sigma ~ normal(0, 2); y ~ normal(alpha + beta * x, sigma); }
        generated quantities {
          vector[N] log_lik;
          for (n in 1:N) log_lik[n] = normal_lpdf(y[n] | alpha + beta * x[n], sigma);
          matrix[N, 1] log_lik_matrix = to_matrix(log_lik);
        }
        STAN;
    private const DATA = ['N' => 12, 'x' => [-1.0, -0.8, -0.6, -0.4, -0.2, 0.0, 0.2, 0.4, 0.6, 0.8, 1.0, 6.0], 'y' => [-0.7, -0.1, 0.2, 0.0, 0.6, 0.4, 0.9, 0.8, 1.3, 1.1, 1.6, -4.0]];
    private static ?CmdStanMCMC $fit = null;
    private static ?string $fitDir = null;
    public static function tearDownAfterClass(): void
    {
        if (self::$fitDir === null) { return; }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$fitDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir(self::$fitDir); self::$fitDir = null; self::$fit = null;
    }
    private function fit(): CmdStanMCMC
    {
        if (!DrawKernels::available()) { self::markTestSkipped('The phit posterior-draw kernels are required: '.implode(', ', DrawKernels::missing())); }
        try { CmdStan::path(); NativeBuild::requireFfi(); } catch (\RuntimeException $e) { self::markTestSkipped('CmdStan and PHP FFI are required: '.$e->getMessage()); }
        if (self::$fit !== null) { return self::$fit; }
        $dir = self::$fitDir = sys_get_temp_dir().'/cmdstanphp-mm-'.bin2hex(random_bytes(6)); mkdir($dir);
        file_put_contents($dir.'/regression.stan', self::MODEL);
        $fit = (new CmdStanModel($dir.'/regression.stan'))->compile()->sample(data: self::DATA, chains: 4, iter_warmup: 500, iter_sampling: 1000, seed: 20261012, show_messages: false, refresh: 0, output_dir: $dir);
        self::assertInstanceOf(CmdStanMCMC::class, $fit);
        return self::$fit = $fit;
    }
    public function testMomentMatchingThroughModelMethods(): void
    {
        $fit = $this->fit(); $plain = $fit->loo(); $mm = $fit->loo(moment_match: true);
        $outlier = 11; $matched = array_column($mm['moment_match']['observations'], null, 'index');
        self::assertGreaterThan($plain['k_threshold'], $plain['diagnostics']['pareto_k'][$outlier]);
        self::assertArrayHasKey($outlier, $matched); self::assertSame('log_lik[12]', $matched[$outlier]['observation']);
        $record = $matched[$outlier]; $details = var_export($record, true);
        self::assertNull($record['message'], $details);
        self::assertLessThanOrEqual($record['pareto_k_initial'], $record['pareto_k'], $details);
        // HMC draws differ across platforms even with a fixed seed. loo may legitimately accept no transform.
        // Analytic-posterior unit tests require improvement; the same-seed R test verifies this real fit's answer.
        if ($record['transforms'] === []) {
            self::assertSame('no_improvement', $record['stop'], $details);
            self::assertSame($record['pareto_k_initial'], $record['pareto_k']);
            self::assertEqualsWithDelta($plain['pointwise'][$outlier]['elpd_loo'], $mm['pointwise'][$outlier]['elpd_loo'], 1e-12);
        } else {
            self::assertLessThan($record['pareto_k_initial'], $record['pareto_k'], $details);
            self::assertLessThan($plain['pointwise'][$outlier]['elpd_loo'], $mm['pointwise'][$outlier]['elpd_loo'], 'PSIS overestimates the outlier\'s LOO density.');
        }
        self::assertSame($matched[$outlier]['pareto_k'], $mm['diagnostics']['pareto_k'][$outlier]);
        foreach ($plain['pointwise'] as $j => $row) { if (!isset($matched[$j])) { self::assertSame($row, $mm['pointwise'][$j]); } }
        // The same run through the generic API with the fit's own callbacks gives an identical result.
        $logLik = $fit->draws('log_lik', 'draws_array'); $upars = array_map('array_values', $fit->unconstrain_draws(format: 'draws_matrix'));
        $generic = MomentMatch::loo(Loo::loo($logLik), $upars, $logLik, fn($rows) => array_map(fn($r) => $fit->log_prob($r), $rows), fn($rows, $i) => array_map(fn($r) => $fit->constrain_variables($r)['log_lik'][$i], $rows));
        self::assertSame($mm, $generic);
        // A matrix-shaped log-likelihood maps "log_lik_matrix[i,1]" to the same values.
        $matrix = $fit->loo('log_lik_matrix', moment_match: true);
        self::assertSame('log_lik_matrix[12,1]', $matrix['moment_match']['observations'][array_search($outlier, array_column($matrix['moment_match']['observations'], 'index'), true)]['observation']);
        self::assertEqualsWithDelta($mm['elpd_loo'], $matrix['elpd_loo'], 1e-12);
    }
    public function testUnconstrainedRowsStayAlignedWithLogLikelihoodDraws(): void
    {
        $fit = $this->fit(); $upars = array_map('array_values', $fit->unconstrain_draws(format: 'draws_matrix'));
        $columns = Loo::columns($fit->draws('log_lik', 'draws_array'))[0]; $draws = $fit->draws(['sigma'], 'draws_matrix');
        self::assertCount(4000, $upars); self::assertSame(['alpha', 'beta', 'sigma'], array_keys($fit->unconstrain_draws(format: 'draws_matrix')[0]));
        foreach ([0, 999, 1000, 2500, 3999] as $s) {
            self::assertEqualsWithDelta(log($draws[$s]['sigma']), $upars[$s][2], 1e-12, 'sigma is unconstrained with log');
            $recomputed = $fit->constrain_variables($upars[$s])['log_lik'];
            foreach ($columns as $j => $column) { self::assertEqualsWithDelta($column[$s], $recomputed[$j], 1e-4 * max(1.0, abs($column[$s])), "draw $s observation $j"); }
            // log_prob includes the log-transform Jacobian, the proposal density moment matching needs.
            self::assertEqualsWithDelta($fit->log_prob($upars[$s], jacobian: false) + $upars[$s][2], $fit->log_prob($upars[$s]), 1e-10);
        }
    }
    #[Group('compatibility')]
    public function testSameSeedMomentMatchingMatchesCmdStanR(): void
    {
        $this->fit();
        try { Process::run(['Rscript', '-e', 'stopifnot(requireNamespace("cmdstanr", quietly=TRUE), requireNamespace("loo", quietly=TRUE), requireNamespace("jsonlite", quietly=TRUE), requireNamespace("Rcpp", quietly=TRUE))']); }
        catch (\Throwable $e) { self::markTestSkipped('R with cmdstanr, loo, Rcpp and jsonlite required: '.$e->getMessage()); }
        $model = new CmdStanModel(self::$fitDir.'/regression.stan');
        $options = ['data' => self::DATA, 'chains' => 4, 'iter_warmup' => 500, 'iter_sampling' => 1000, 'seed' => 20261012, 'refresh' => 0, 'show_messages' => false, 'show_exceptions' => false, 'sig_figs' => 18, 'save_metric' => false, 'save_cmdstan_config' => false];
        $fit = $model->sample($options + ['output_dir' => $this->dir]);
        $options['output_dir'] = $this->dir.'/r'; mkdir($options['output_dir']); file_put_contents($this->dir.'/options.json', json_encode($options, JSON_THROW_ON_ERROR));
        copy($model->stan_file(), $options['output_dir'].'/regression.stan');
        Process::run(['Rscript', __DIR__.'/fixtures/moment_match_cmdstanr.R', $options['output_dir'].'/regression.stan', $this->dir.'/options.json', $this->dir.'/reference.json'], env: ['CMDSTAN' => CmdStan::path()], timeout: 900);
        $ref = json_decode(file_get_contents($this->dir.'/reference.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsWithDelta($ref['lp'], array_column($fit->draws('lp__', 'draws_matrix'), 'lp__'), 1e-12, 'same draws');
        // loo computes r_eff from exp(log_lik) unshifted; when every value is tiny, posterior's absolute constant check
        // returns NA. ESS is scale invariant, so CmdStanPHP shifts by the maximum and reports a finite value instead.
        $close = function (mixed $v, float $actual, string $label) { if ($v === 'NA') { self::assertTrue(is_finite($actual), $label); return; } self::assertEqualsWithDelta($v, $actual, 1e-8 * max(1.0, abs($v)), $label); };
        $compare = function (array $reference, array $actual, array $columns, string $label) use ($close) {
            foreach ($columns as $column) { foreach ($reference['pointwise'][$column] as $i => $v) { $close($v, $actual['pointwise'][$i][$column], "$label $column [$i]"); } }
            foreach ($reference['pareto_k'] as $i => $v) { self::assertEqualsWithDelta($v, $actual['diagnostics']['pareto_k'][$i], 1e-8, "$label pareto_k [$i]"); }
        };
        $compare($ref['unsplit'], $unsplit = $fit->loo(moment_match: true, split: false), ['elpd_loo', 'mcse_elpd_loo', 'p_loo', 'looic'], 'unsplit');
        foreach ($ref['unsplit']['n_eff'] as $i => $v) { $close($v, $unsplit['diagnostics']['n_eff'][$i], "unsplit n_eff [$i]"); }
        // With shifts only, the exact and loo split inverses coincide; mcse and n_eff use the two-half r_eff.
        $split = $fit->loo(moment_match: true);
        foreach ($split['moment_match']['observations'] as $o) { self::assertSame([], array_diff($o['transforms'], ['shift'])); }
        $compare($ref['split'], $split, ['elpd_loo', 'p_loo', 'looic'], 'split');
    }
    public function testMomentMatchOptions(): void
    {
        $fit = $this->fit();
        $unsplit = $fit->loo(moment_match: true, split: false, cov: false, max_iters: 5);
        self::assertFalse($unsplit['moment_match']['split']); self::assertFalse($unsplit['moment_match']['cov']);
        foreach ($unsplit['moment_match']['observations'] as $o) { self::assertNotContains('cov', $o['transforms']); self::assertLessThanOrEqual(5, count($o['transforms'])); self::assertFalse($o['split']); }
        $strict = $fit->loo(moment_match: true, k_threshold: 0.3);
        self::assertSame(0.3, $strict['moment_match']['k_threshold']); self::assertGreaterThanOrEqual(count($fit->loo(moment_match: true)['moment_match']['observations']), count($strict['moment_match']['observations']));
        try { $fit->loo('log_lik', moment_match: true, max_iters: 0); self::fail('max_iters must be positive.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
    }
}
