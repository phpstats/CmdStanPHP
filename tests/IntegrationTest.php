<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, CmdStanFit, CmdStanMCMC, CmdStanMLE, CmdStanVB, CmdStanPathfinder, CmdStanLaplace, CmdStanGQ, ProcessException};
use PHPUnit\Framework\Attributes\Group;
#[Group('integration')]
final class IntegrationTest extends TestCase
{
    private function model(string $name = 'bernoulli'): CmdStanModel
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        $model = new CmdStanModel(__DIR__.'/../examples/'.$name.'.stan'); $model->compile(); return $model;
    }
    private function data(): array { return ['N' => 10, 'y' => [0,1,0,0,0,0,0,0,0,1]]; }
    public function testCompilationSyntaxMetadataAndFormat(): void
    {
        $model = $this->model(); self::assertFileExists($model->exeFile()); self::assertFileExists($model->hppFile()); self::assertFileExists($model->saveHppFile($this->dir)); self::assertTrue($model->checkSyntax());
        self::assertStringContainsString('bernoulli', $model->code()); self::assertStringContainsString('parameters', $model->format());
        self::assertArrayHasKey('parameters', $model->variables()); self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', CmdStan::version());
    }
    public function testMcmcAndNativeSummary(): void
    {
        $fit = $this->model()->sample(data: $this->data(), chains: 2, parallel_chains: 2, chain_ids: [3,5], iter_warmup: 200, iter_sampling: 400, seed: 123, save_warmup: true, show_messages: false, refresh: 0, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanMCMC::class, $fit); self::assertSame([0,0], $fit->returnCodes()); self::assertSame(400, $fit->numDrawsSampling()); self::assertSame(200, $fit->numDrawsWarmup());
        self::assertCount(800, $fit->draws('theta', 'draws_matrix')); self::assertCount(2, $fit->invMetric());
        $summary = $fit->summary('theta')[0]; self::assertEqualsWithDelta(0.25, $summary['mean'], 0.035); self::assertLessThan(1.05, $summary['rhat']); self::assertGreaterThan(50, $summary['ess_bulk']);
        self::assertStringContainsString('divergent', $fit->cmdstanDiagnose()); self::assertSame([0,0], array_map('intval', $fit->diagnosticSummary()['num_divergent']));
        $loaded = CmdStanFit::fromCsv($fit->outputFiles()); self::assertSame($fit->draws(), $loaded->draws());
    }
    public function testSeedsReproduceAndPerChainInit(): void
    {
        $model = $this->model(); $o = ['data' => $this->data(), 'chains' => 2, 'parallel_chains' => 2, 'iter_warmup' => 100, 'iter_sampling' => 20, 'seed' => [123,456], 'init' => fn(int $id) => ['theta' => $id / 4], 'show_messages' => false, 'refresh' => 0, 'output_dir' => $this->dir];
        $a = $model->sample($o); $b = $model->sample($o); self::assertSame($a->draws(), $b->draws());
    }
    public function testOptimizeAndLaplace(): void
    {
        $model = $this->model(); $fit = $model->optimize(data: $this->data(), seed: 12, jacobian: true, show_messages: false, refresh: 0, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanMLE::class, $fit); self::assertEqualsWithDelta(0.25, $fit->mle()['theta'], 0.001);
        $laplace = $model->laplace(data: $this->data(), mode: $fit, draws: 50, seed: 123, show_messages: false, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanLaplace::class, $laplace); self::assertSame(50, $laplace->numDrawsSampling()); self::assertArrayHasKey('theta', $laplace->mode()); self::assertCount(50, $laplace->lpApprox());
    }
    public function testLaplaceAutomaticMode(): void
    {
        $fit = $this->model('normal')->laplace(draws: 20, seed: 123, show_messages: false, output_dir: $this->dir);
        self::assertSame(20, $fit->numDrawsSampling());
    }
    public function testVariational(): void
    {
        $fit = $this->model('normal')->variational(seed: 123, draws: 40, show_messages: false, refresh: 0, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanVB::class, $fit); self::assertSame(40, $fit->numDrawsSampling()); self::assertCount(40, $fit->lpApprox()); self::assertArrayHasKey('mu', $fit->variationalMean());
    }
    public function testPathfinder(): void
    {
        $fit = $this->model('normal')->pathfinder(seed: 123, num_paths: 2, single_path_draws: 40, draws: 30, show_messages: false, refresh: 0, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanPathfinder::class, $fit); self::assertSame(30, $fit->numDrawsSampling()); self::assertCount(30, $fit->lpApprox());
    }
    public function testGeneratedQuantities(): void
    {
        $fit = $this->model()->sample(data: $this->data(), chains: 2, iter_warmup: 100, iter_sampling: 10, seed: 123, show_messages: false, output_dir: $this->dir);
        $gq = $this->model('generated')->generateQuantities($fit, data: $this->data(), seed: 123, parallel_chains: 2, show_messages: false, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanGQ::class, $gq); self::assertSame(2, $gq->numChains()); self::assertSame($fit->outputFiles(), $gq->fittedParamsFiles()); self::assertSame(10, $gq->numDrawsSampling());
        foreach ($gq->draws('prediction', 'draws_matrix') as $row) { self::assertContains($row['prediction'], [0.0,1.0]); }
    }
    public function testFixedParameters(): void
    {
        $fit = $this->model('generated')->sample(data: $this->data(), fixed_param: true, init: ['theta' => 0.5], iter_sampling: 10, seed: 123, show_messages: false, output_dir: $this->dir);
        self::assertSame(1, $fit->numChains()); self::assertSame(10, $fit->numDrawsSampling());
    }
    public function testGradientDiagnosticsAndLogDensity(): void
    {
        $model = $this->model('normal'); $fit = $model->diagnose(init: 0, show_messages: false, output_dir: $this->dir);
        self::assertStringContainsString('gradient', strtolower($fit->gradients())); self::assertSame('diagnose', $fit->metadata()['method']); self::assertSame(0.0, $fit->lp());
        $lp = $model->logProb(unconstrained_params: ['params_r' => [[0.0],[1.0]]], show_messages: false, output_dir: $this->dir);
        self::assertCount(2, $lp->draws(format: 'draws_matrix'));
    }
    public function testOtherOptimizationAndVariationalAlgorithms(): void
    {
        $model = $this->model('normal');
        foreach (['bfgs', 'newton'] as $algorithm) {
            $fit = $model->optimize(algorithm: $algorithm, init: 0.5, show_messages: false, output_dir: $this->dir);
            self::assertEqualsWithDelta(0.0, $fit->mle()['mu'], 1e-3);
        }
        $fit = $model->variational(algorithm: 'fullrank', draws: 10, seed: 123, show_messages: false, output_dir: $this->dir);
        self::assertSame(10, $fit->numDrawsSampling());
    }
    public function testThreadingProfilingAndAuxiliaryOutputs(): void
    {
        $this->model('normal'); // Verify availability before compiling a new model.
        $source = \CmdStan\write_stan_file('parameters { real mu; } model { profile("density") { mu ~ normal(0, 1); } }', $this->dir.'/threaded.stan');
        $model = new CmdStanModel($source); $model->compile(cppOptions: ['STAN_THREADS' => true]);
        $fit = $model->sample(chains: 2, parallel_chains: 2, threads_per_chain: 2, iter_warmup: 100, iter_sampling: 10, seed: 123, save_latent_dynamics: true, save_metric: true, save_cmdstan_config: true, show_messages: false, output_dir: $this->dir);
        self::assertCount(2, $fit->latentDynamicsFiles()); self::assertCount(2, $fit->profileFiles()); self::assertNotEmpty($fit->profiles()[0]);
        self::assertSame(2, $fit->metadata()['num_threads']); self::assertSame(2, $fit->metadata()['threads_per_chain']);
        self::assertCount(2, $fit->metricFiles()); self::assertCount(2, $fit->configFiles());
        self::assertCount(2, $fit->saveMetricFiles($this->dir.'/saved')); self::assertCount(2, $fit->saveConfigFiles($this->dir.'/saved'));
        self::assertCount(2, $fit->saveLatentDynamicsFiles($this->dir.'/saved'));
        $csvMetric = array_map(fn($file) => \CmdStan\Csv::read($file)['inv_metric'], $fit->outputFiles());
        foreach ($fit->invMetric(false) as $i => $metric) { self::assertEqualsWithDelta($csvMetric[$i], $metric, 1e-6); self::assertEquals($fit->metric()[$i]['inv_metric'], $metric); }
        $meta = $fit->metadata(); self::assertSame([1, 2], $meta['id']); self::assertSame(123, $meta['seed']); self::assertSame('2', $meta['stan_major_version']); self::assertSame(['lp__', 'mu'], $meta['stan_variables']);
        self::assertSame(2, $fit->cmdstanConfig()[1]['id']); self::assertMatchesRegularExpression('~/saved/threaded-metric-\d{12}-2-[0-9a-f]{6}\.json$~', $fit->metricFiles()[1]);
        $outputs = $fit->outputFiles(); $saved = $fit->saveOutputFiles($this->dir.'/kept', 'run', timestamp: false, random: false);
        self::assertSame([realpath($this->dir.'/kept').'/run-1.csv', realpath($this->dir.'/kept').'/run-2.csv'], $saved); self::assertFileDoesNotExist($outputs[0]); self::assertCount(20, $fit->draws('mu', 'draws_matrix'));
    }
    public function testPathfinderSinglePathFits(): void
    {
        $fit = $this->model('normal')->pathfinder(seed: 123, num_paths: 3, single_path_draws: 12, draws: 30, save_single_paths: true, show_messages: false, output_dir: $this->dir);
        self::assertCount(3, $fit->singlePathFiles()); self::assertCount(3, $fit->singlePathJsonFiles());
        self::assertMatchesRegularExpression('/_path_3\.csv$/', $fit->singlePathFiles()[2]);
        $paths = $fit->singlePathFits(); self::assertContainsOnlyInstancesOf(CmdStanPathfinder::class, $paths);
        foreach ($paths as $i => $path) { self::assertSame(12, $path->numDrawsSampling()); self::assertSame([(float)($i + 1)], array_values(array_unique(array_column(\CmdStan\Csv::read($fit->singlePathFiles()[$i])['draws'], 'path__')))); }
        self::assertArrayHasKey('unconstrained_parameters', $fit->singlePathDiagnostics()[0]['0']);
        $fit->saveOutputFiles($this->dir.'/saved'); self::assertCount(3, $fit->singlePathFits());
        $one = $this->model('normal')->pathfinder(seed: 123, num_paths: 1, single_path_draws: 12, save_single_paths: true, show_messages: false, output_dir: $this->dir.'/one');
        self::assertSame($one->outputFiles(), $one->singlePathFiles()); self::assertCount(1, $one->singlePathDiagnostics());
        self::assertSame([], $this->model('normal')->pathfinder(seed: 123, num_paths: 2, draws: 10, show_messages: false, output_dir: $this->dir.'/none')->singlePathFiles());
    }
    public function testCmdStanrExamples(): void
    {
        $this->model();
        $fit = \CmdStan\cmdstanr_example('schools_ncp', chains: 2, parallel_chains: 2, iter_warmup: 100, iter_sampling: 50, seed: 123, refresh: 0, output_dir: $this->dir);
        self::assertInstanceOf(CmdStanMCMC::class, $fit); self::assertSame(['mu', 'tau', 'theta_raw', 'theta'], array_slice($fit->metadata()['stan_variables'], 1)); self::assertSame([8], $fit->metadata()['stan_variable_sizes']['theta']);
        ob_start(); $mle = \CmdStan\cmdstanr_example('logistic', 'optimize', seed: 123, output_dir: $this->dir, quiet: false); $output = ob_get_clean();
        self::assertInstanceOf(CmdStanMLE::class, $mle); self::assertArrayHasKey('beta[3]', $mle->mle()); self::assertStringContainsString('Optimization terminated', $output);
        self::assertFileExists(sys_get_temp_dir().'/cmdstanphp-examples/logistic'.CmdStan::suffix()); self::assertStringContainsString('bernoulli_logit_glm', $mle->code());
    }
    public function testPartialInitsAreReportedOnStderr(): void
    {
        $model = $this->model('normal');
        $script = $this->dir.'/partial.php'; file_put_contents($script, '<?php require '.var_export(__DIR__.'/../vendor/autoload.php', true).'; $m = new CmdStan\\CmdStanModel('.var_export($model->stanFile(), true).'); $m->sample(chains: 2, init: [["other" => 1.0], ["mu" => 0.5]], iter_warmup: 10, iter_sampling: 10, show_messages: false, output_dir: '.var_export($this->dir, true).'); CmdStan\\options(["cmdstanr_warn_inits" => false]); $m->sample(chains: 1, init: ["other" => 1.0], iter_warmup: 10, iter_sampling: 10, show_messages: false, output_dir: '.var_export($this->dir, true).');');
        $stderr = \CmdStan\Process::run([PHP_BINARY, $script], env: ['CMDSTAN' => CmdStan::path()])['stderr'];
        self::assertStringContainsString("Missing init values for the following parameters:\n - chain 1: mu\n", $stderr); self::assertSame(1, substr_count($stderr, 'Missing init values'));
    }
    public function testMetricThinningAndExplicitInitialization(): void
    {
        $fit = $this->model('normal')->sample(chains: 1, init: 0, inv_metric: [1.0], metric: 'diag_e', thin: 3, iter_warmup: 12, iter_sampling: 12, save_warmup: true, adapt_engaged: false, seed: 123, show_messages: false, output_dir: $this->dir);
        self::assertSame(4, $fit->numDrawsSampling()); self::assertSame(4, $fit->numDrawsWarmup());
        $fit->saveObject($this->dir.'/fit.bin'); $expected = $fit->summary('mu');
        foreach ($fit->outputFiles() as $file) { unlink($file); }
        $loaded = unserialize(file_get_contents($this->dir.'/fit.bin'), ['allowed_classes' => [CmdStanMCMC::class]]);
        $actual = $loaded->summary('mu');
        foreach ($expected[0] as $key => $value) {
            if (is_float($value) && is_nan($value)) { self::assertTrue(is_nan($actual[0][$key])); }
            else { self::assertSame($value, $actual[0][$key]); }
        }
    }
    public function testSamplingWithoutAdaptationAndWithoutSavingWarmup(): void
    {
        $fit = $this->model('normal')->sample(chains: 1, iter_warmup: 10, iter_sampling: 10, adapt_engaged: false, seed: 123, show_messages: false, output_dir: $this->dir);
        self::assertSame(10, $fit->numDrawsSampling()); self::assertSame(0, $fit->numDrawsWarmup());
    }
    public function testLaplaceRejectsDifferentJacobianAdjustment(): void
    {
        $model = $this->model(); $mode = $model->optimize(data: $this->data(), jacobian: false, show_messages: false, output_dir: $this->dir);
        $this->expectException(\InvalidArgumentException::class); $model->laplace(data: $this->data(), mode: $mode, jacobian: true, show_messages: false);
    }
    public function testInvalidDataIncludesCmdStanError(): void
    {
        $this->expectException(ProcessException::class); $this->model()->sample(data: ['N' => 3, 'y' => [0,1]], chains: 1, show_messages: false, output_dir: $this->dir);
    }
}
