<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, CmdStanGQ};
use PHPUnit\Framework\Attributes\Group;
use function CmdStan\write_stan_file;
/** CmdStanR 1.0 runtime behaviour against real CmdStan: file names, compression, progress, defaults, and generated quantities from any fit. */
#[Group('integration')]
final class RuntimeIntegrationTest extends TestCase
{
    private const DATA = ['N' => 10, 'y' => [0, 1, 0, 0, 0, 0, 0, 0, 0, 1]];
    private function model(): CmdStanModel
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        return new CmdStanModel(write_stan_file("data { int N; array[N] int y; } parameters { real<lower=0,upper=1> theta; } model { y ~ bernoulli(theta); } generated quantities { real odds = theta / (1 - theta); }", $this->dir.'/odds.stan'));
    }
    public function testGeneratedQuantitiesFromEveryFitType(): void
    {
        $model = $this->model();
        $mle = $model->optimize(data: self::DATA, seed: 1, show_messages: false, output_dir: $this->dir);
        self::assertArrayNotHasKey('lp__', $mle->mle(), 'CmdStanR 1.0 drops lp__ from mle()'); self::assertIsFloat($mle->lp());
        $gq = $model->generateQuantities($mle, data: self::DATA, seed: 2, show_messages: false, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanGQ::class, $gq); self::assertEqualsWithDelta($mle->mle()['theta'] / (1 - $mle->mle()['theta']), $gq->draws('odds', 'draws_matrix')[0]['odds'], 1e-6);
        $pf = $model->pathfinder(data: self::DATA, seed: 3, draws: 40, show_messages: false, output_dir: $this->dir);
        self::assertCount(40, $pf->lpApprox()); self::assertSame(['lp__', 'lp_approx__'], array_slice($pf->metadata()['variables'], 0, 2));
        self::assertCount(40, $model->generateQuantities($pf, data: self::DATA, seed: 4, show_messages: false, output_dir: $this->dir)->draws('odds', 'draws_matrix'));
    }
    public function testFileNamesCompressionAndPerPathInits(): void
    {
        $model = $this->model();
        $laplace = $model->laplace(data: self::DATA, seed: 1, draws: 20, output_basename: 'lap', show_messages: false, output_dir: $this->dir);
        self::assertSame(realpath($this->dir).'/lap-01.csv', $laplace->outputFiles()[0]); self::assertFileExists($this->dir.'/lap-mode-01.csv', 'the optimization keeps its own file');
        $fit = $model->sample(data: self::DATA, chains: 2, seed: 5, iter_warmup: 200, iter_sampling: 100, compress: 'gzip', show_messages: false, output_dir: $this->dir);
        self::assertStringEndsWith('.csv.gz', $fit->outputFiles()[0]); self::assertGreaterThan(1.0, array_column($fit->summary('theta'), 'ess_bulk')[0], 'stansummary reads decompressed copies');
        $inits = [['theta' => 0.1], ['theta' => 0.2], ['theta' => 0.3], ['theta' => 0.4]];
        $pf = $model->pathfinder(data: self::DATA, init: $inits, num_paths: 4, seed: 6, show_messages: false, output_dir: $this->dir);
        $command = $pf->command()[0]; preg_match("/'init=([^']*)'/", $command, $m);
        self::assertCount(4, explode(',', $m[1]), 'each Pathfinder path gets its own init file');
        self::assertSame(0.3, json_decode((string)file_get_contents(explode(',', $m[1])[2]), true)['theta']);
    }
    public function testProgressBarAndDefaults(): void
    {
        $model = $this->model();
        $defaults = $model->cmdstanDefaults('sample'); self::assertSame(1000, $defaults['iter_sampling']); self::assertSame(0.8, $defaults['adapt_delta']);
        ob_start(); $model->sample(data: self::DATA, chains: 2, seed: 7, iter_warmup: 100, iter_sampling: 100, refresh: 50, show_progress_bar: true, output_dir: $this->dir); $out = ob_get_clean();
        self::assertStringNotContainsString('Iteration:', $out, 'the bar replaces the iteration lines');
    }
}
