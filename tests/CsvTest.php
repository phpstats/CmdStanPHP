<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{Csv, CmdStanFit, CmdStanMCMC, CmdStanVB};
use function CmdStan\{as_cmdstan_fit, options, read_cmdstan_csv_list};
final class CsvTest extends TestCase
{
    public function testWarmupMetadataAdaptationAndTiming(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); self::assertInstanceOf(CmdStanMCMC::class, $fit);
        self::assertSame(2, $fit->numDrawsSampling()); self::assertSame(2, $fit->numDrawsWarmup());
        self::assertSame([0.5], $fit->stepSize()); self::assertSame([[1.0,2.0]], $fit->invMetric(matrix: false)); self::assertSame([[[1.0,0.0],[0.0,2.0]]], $fit->invMetric());
        self::assertSame(0.3, $fit->time()[0]['total']); self::assertSame('sample', $fit->metadata()['method']);
    }
    public function testFormatsAndVariableSelection(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv'), $this->csv('b.csv')]);
        self::assertSame(['lp__','theta','x[1]','x[2]'], $fit->variables());
        self::assertSame([[['theta' => 0.3],['theta' => 0.3]], [['theta' => 0.4],['theta' => 0.4]]], $fit->draws('theta'));
        self::assertSame(4, count($fit->draws('x', 'draws_matrix')));
        self::assertSame([['theta' => [0.3,0.4]], ['theta' => [0.3,0.4]]], $fit->draws('theta', 'draws_list'));
        self::assertSame(['theta' => 0.3, '.chain' => 2, '.iteration' => 1, '.draw' => 3], $fit->draws('theta', 'draws_df')[2]);
        self::assertCount(4, $fit->draws('theta', incWarmup: true));
        self::assertSame(['divergent__','treedepth__','energy__'], array_keys($fit->samplerDiagnostics()[0][0]));
    }
    public function testSummaryAndDiagnostics(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); $summary = $fit->summary('theta')[0];
        self::assertEqualsWithDelta(0.35, $summary['mean'], 1e-12); self::assertEqualsWithDelta(0.305, $summary['q5'], 1e-12);
        self::assertEqualsWithDelta(sqrt(0.005), $summary['sd'], 1e-12); self::assertTrue(is_nan($summary['rhat']));
        $diag = $fit->diagnosticSummary(); self::assertSame([1.0], $diag['num_divergent']); self::assertSame([1], $diag['num_max_treedepth']); self::assertTrue(is_nan($diag['ebfmi'][0]));
    }
    public function testSpecialNumbers(): void
    {
        $f = $this->dir.'/special.csv'; file_put_contents($f, "# method = log_prob\nlp__,x.1.2\nnan,inf\n-inf,1e-3\n"); $p = Csv::read($f);
        self::assertTrue(is_nan($p['draws'][0]['lp__'])); self::assertSame(INF, $p['draws'][0]['x[1,2]']); self::assertSame(-INF, $p['draws'][1]['lp__']);
    }
    public function testVariationalMeanIsNotDraw(): void
    {
        $f = $this->dir.'/vb.csv'; file_put_contents($f, "# method = variational\nlp__,theta\n0,0.5\n-1,0.2\n-2,0.3\n");
        $fit = CmdStanFit::fromCsv([$f]); self::assertSame(2, $fit->numDrawsSampling()); self::assertSame(['lp__' => 0.0, 'theta' => 0.5], $fit->variationalMean());
    }
    public function testAdviLogDensityColumnUsesLogP(): void
    {
        $file = $this->dir.'/advi.csv'; file_put_contents($file, "# method = variational\nlp__,log_p__,log_g__,theta\n0,0,0,0.5\n0,-3,-2,0.2\n");
        $fit = CmdStanFit::fromCsv([$file]); self::assertSame(-3.0, $fit->draws('lp__', 'draws_matrix')[0]['lp__']);
    }
    public function testBooleanMetadataDoesNotDropUnsavedWarmup(): void
    {
        $file = $this->dir.'/no-adaptation.csv';
        file_put_contents($file, "# method = sample\n# save_warmup = false (Default)\n# num_warmup = 100\n# jacobian = false\nlp__,theta\n-1,0.3\n-2,0.4\n");
        $csv = Csv::read($file); self::assertFalse($csv['metadata']['save_warmup']); self::assertFalse($csv['metadata']['jacobian']); self::assertCount(2, $csv['draws']);
    }
    public function testHeaderlessGradientDiagnosticCsv(): void
    {
        $file = $this->dir.'/diagnose.csv'; file_put_contents($file, "# method = diagnose\n# Log probability=-1.25\n# param idx value model finite diff error\n# 0 0.5 -1 -1 0\n");
        $fit = CmdStanFit::fromCsv([$file]); self::assertSame(-1.25, $fit->lp()); self::assertStringContainsString('param idx', $fit->gradients());
    }
    public function testMalformedCsv(): void { $f = $this->dir.'/bad.csv'; file_put_contents($f, "lp__,theta\n1\n"); $this->expectException(\RuntimeException::class); Csv::read($f); }
    public function testInvalidNumber(): void { $f = $this->dir.'/bad.csv'; file_put_contents($f, "lp__\nhello\n"); $this->expectException(\RuntimeException::class); Csv::read($f); }
    public function testMissingHeader(): void { $f = $this->dir.'/bad.csv'; file_put_contents($f, "# incomplete\n"); $this->expectException(\RuntimeException::class); Csv::read($f); }
    public function testUnknownVariable(): void { $fit = CmdStanFit::fromCsv([$this->csv()]); $this->expectException(\InvalidArgumentException::class); $fit->draws('missing'); }
    public function testMismatchedChains(): void { $f = $this->dir.'/other.csv'; file_put_contents($f, "lp__\n1\n"); $this->expectException(\InvalidArgumentException::class); CmdStanFit::fromCsv([$this->csv(), $f]); }
    public function testUnequalChainLengths(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv'), $this->csv('b.csv', rows: ['-1,0.3,3,4,0,4,1'])]);
        self::assertCount(3, $fit->draws('theta', 'draws_df')); $this->expectException(\RuntimeException::class); $fit->draws();
    }
    public function testSaveAndSerialize(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); $expected = $fit->draws(); $original = $fit->outputFiles()[0];
        $saved = $fit->saveOutputFiles($this->dir.'/saved'); self::assertFileExists($saved[0]); unlink($original);
        $fit->saveObject($this->dir.'/fit.bin'); $loaded = unserialize(file_get_contents($this->dir.'/fit.bin'), ['allowed_classes' => [CmdStanMCMC::class]]);
        self::assertSame($expected, $loaded->draws());
    }
    public function testSaveRefusesOverwrite(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); mkdir($this->dir.'/saved'); file_put_contents($this->dir.'/saved/chain-01.csv', 'sentinel');
        $this->expectException(\RuntimeException::class); $fit->saveOutputFiles($this->dir.'/saved', 'chain', timestamp: false, random: false);
    }
    public function testCmdStanRMetadataStructure(): void
    {
        $extra = "# model = demo_model\n# stan_version_major = 2\n# num_samples = 2\n# engaged = true\n# delta = 0.8\n# stepsize = 1\n# num_threads = 1\n# seed = 42\n# metric = diag_e\n";
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv', $extra), $this->csv('b.csv', str_replace('# id = 1', '', $extra)."# id = 2\n")]);
        $meta = $fit->metadata();
        foreach (['model_name' => 'demo_model', 'stan_version_major' => 2, 'iter_warmup' => 2, 'iter_sampling' => 2, 'max_treedepth' => 10, 'adapt_engaged' => true, 'adapt_delta' => 0.8, 'threads_per_chain' => 1, 'seed' => 42, 'metric' => 'diag_e', 'max_depth' => 10, 'num_warmup' => 2] as $key => $value) { self::assertSame($value, $meta[$key], $key); }
        self::assertSame([1, 2], $meta['id']); self::assertSame([0.5, 0.5], $meta['step_size_adaptation']); self::assertSame([1, 1], $meta['step_size']);
        self::assertSame(['lp__', 'theta', 'x[1]', 'x[2]'], $meta['variables']); self::assertSame($meta['variables'], $meta['model_params']);
        self::assertSame(['divergent__', 'treedepth__', 'energy__'], $meta['sampler_diagnostics']);
        self::assertSame(['lp__', 'theta', 'x'], $meta['stan_variables']); self::assertSame(['lp__' => [1], 'theta' => [1], 'x' => [2]], $meta['stan_variable_sizes']);
        self::assertSame([['chain_id' => 1, 'warmup' => 0.1, 'sampling' => 0.2, 'total' => 0.3], ['chain_id' => 2, 'warmup' => 0.1, 'sampling' => 0.2, 'total' => 0.3]], $meta['time']);
        self::assertCount(2, $fit->chainMetadata()); self::assertSame(2, $fit->chainMetadata()[1]['id']);
    }
    public function testApproximationColumnsAreRenamedLikeCmdStanR(): void
    {
        $file = $this->dir.'/laplace.csv'; file_put_contents($file, "# method = laplace\n# num_threads = 2\nlog_p__,log_q__,theta\n-1,-2,0.5\n-3,-4,0.6\n");
        $fit = CmdStanFit::fromCsv([$file]); $meta = $fit->metadata();
        self::assertSame(['lp__', 'lp_approx__', 'theta'], $meta['variables']); self::assertSame([], $meta['sampler_diagnostics']); self::assertSame(2, $meta['threads']);
        self::assertSame([-2.0, -4.0], $fit->lpApprox(), "CmdStanR 1.0 returns lp_approx() as a vector"); self::assertSame(['lp__' => -1.0, 'theta' => 0.5], $fit->draws(format: 'draws_matrix')[0]);
        $vb = $this->dir.'/vb.csv'; file_put_contents($vb, "# method = variational\nlp__,log_p__,log_g__,theta\n0,0,0,0.5\n0,-3,-2,0.2\n");
        $fit = CmdStanFit::fromCsv([$vb]); self::assertInstanceOf(CmdStanVB::class, $fit); self::assertSame(['lp__', 'lp_approx__', 'theta'], $fit->metadata()['variables']); self::assertSame([-2.0], $fit->lpApprox());
    }
    public function testDrawsFormatDefaultsAndOption(): void
    {
        $vb = $this->dir.'/vb.csv'; file_put_contents($vb, "# method = variational\nlp__,theta\n0,0.5\n-1,0.2\n");
        self::assertSame([['lp__' => -1.0, 'theta' => 0.2]], CmdStanFit::fromCsv([$vb])->draws());
        $mcmc = CmdStanFit::fromCsv([$this->csv()]); self::assertSame([['theta' => 0.3]], $mcmc->draws('theta')[0]);
        $previous = options(['cmdstanr_draws_format' => 'draws_list']);
        try { self::assertSame([['theta' => [0.3, 0.4]]], $mcmc->draws('theta')); } finally { options($previous); }
        self::assertSame([['theta' => 0.3]], $mcmc->draws('theta')[0]);
    }
    public function testMetricAndConfigJson(): void
    {
        $csv = $this->csv('run-1.csv'); file_put_contents($this->dir.'/run-1_metric.json', '{"stepsize": 0.7, "metric_type": "diag_e", "inv_metric": [3, 4]}');
        file_put_contents($this->dir.'/run-1_config.json', '{"stan_major_version": "2", "model_name": "demo_model", "method": {"value": "sample", "sample": {"num_chains": 1, "adapt": {"delta": 0.9}}}, "mpi_enabled": false}');
        $fit = CmdStanFit::fromCsv([$csv]);
        self::assertSame([[3.0, 4.0]], $fit->invMetric(false)); self::assertSame([[[3.0, 0.0], [0.0, 4.0]]], $fit->invMetric());
        self::assertSame('diag_e', $fit->metric()[0]['metric_type']); self::assertSame('demo_model', $fit->cmdstanConfig()[0]['model_name']);
        $meta = $fit->metadata(); self::assertSame('2', $meta['stan_major_version']); self::assertSame(1, $meta['num_chains']); self::assertFalse($meta['mpi_enabled']); self::assertSame('sample', $meta['method']); self::assertSame(0.9, $meta['delta']);
        $saved = $fit->saveMetricFiles($this->dir.'/saved', 'demo', random: false); self::assertMatchesRegularExpression('~/demo-metric-\d{12}-01\.json$~', $saved[0]);
        self::assertFileExists($this->dir.'/run-1_metric.json'); self::assertSame($saved, $fit->metricFiles());
    }
    public function testDenseMetricFromCsv(): void
    {
        $file = $this->dir.'/dense.csv'; file_put_contents($file, "# method = sample\n# metric = dense_e\nlp__,a,b\n# Adaptation terminated\n# Step size = 0.5\n# Elements of inverse mass matrix:\n# 1,0.5\n# 0.5,2\n-1,0.1,0.2\n");
        self::assertSame([[[1.0, 0.5], [0.5, 2.0]]], CmdStanFit::fromCsv([$file])->invMetric(false));
    }
    public function testReadCmdStanCsvList(): void
    {
        $list = read_cmdstan_csv_list([$this->csv('a.csv'), $this->csv('b.csv', "# id = 2\n")]);
        self::assertSame(['metadata', 'time', 'inv_metric', 'step_size', 'warmup_draws', 'post_warmup_draws', 'warmup_sampler_diagnostics', 'post_warmup_sampler_diagnostics'], array_keys($list));
        self::assertSame([1 => [1.0, 2.0], 2 => [1.0, 2.0]], $list['inv_metric']); self::assertSame([1 => 0.5, 2 => 0.5], $list['step_size']); self::assertNull($list['time']['total']);
        self::assertCount(2, $list['warmup_draws']); self::assertSame(['lp__' => -1.0, 'theta' => 0.3, 'x[1]' => 3.0, 'x[2]' => 4.0], $list['post_warmup_draws'][0][0]);
        self::assertSame(['divergent__' => 0.0, 'treedepth__' => 4.0, 'energy__' => 1.0], $list['post_warmup_sampler_diagnostics'][0][0]);
        $subset = read_cmdstan_csv_list($this->csv('c.csv'), 'x', [], 'draws_matrix');
        self::assertSame([['x[1]' => 3.0, 'x[2]' => 4.0], ['x[1]' => 4.0, 'x[2]' => 5.0]], $subset['post_warmup_draws']); self::assertNull($subset['post_warmup_sampler_diagnostics']);
        $mle = $this->dir.'/mle.csv'; file_put_contents($mle, "# method = optimize\nlp__,theta\n-5,0.25\n");
        self::assertSame([['lp__' => -5.0, 'theta' => 0.25]], read_cmdstan_csv_list($mle)['point_estimates']);
        $diagnose = $this->dir.'/diagnose.csv'; file_put_contents($diagnose, "# method = diagnose\n# Log probability=-1.25\n# param idx value model finite diff error\n# 0 0.5 -1 -1.1 0.1\n");
        $list = read_cmdstan_csv_list($diagnose); self::assertSame(-1.25, $list['lp']); self::assertSame([['param_idx' => 0.0, 'value' => 0.5, 'model' => -1.0, 'finite_diff' => -1.1, 'error' => 0.1]], $list['gradients']); self::assertArrayNotHasKey('lp', $list['metadata']);
        $this->expectException(\InvalidArgumentException::class); read_cmdstan_csv_list($this->csv('d.csv'), 'missing');
    }
    public function testImportedFilesAreCopiedWithCmdStanRNames(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); $original = $fit->outputFiles()[0];
        $saved = $fit->saveOutputFiles($this->dir.'/saved', 'demo');
        self::assertMatchesRegularExpression('~/saved/demo-\d{12}-01-[0-9a-f]{6}\.csv$~', $saved[0]); self::assertFileExists($original); self::assertSame($saved, $fit->outputFiles());
        self::assertMatchesRegularExpression('~/model-01\.csv$~', $fit->saveOutputFiles($this->dir.'/again', timestamp: false, random: false)[0]);
    }
    public function testMissingAuxiliaryFilesThrowLikeCmdStanR(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); self::assertSame([], $fit->configFiles()); self::assertSame([], $fit->metricFiles()); self::assertNull($fit->saveDataFile($this->dir));
        try { $fit->latentDynamicsFiles(); self::fail('Expected exception'); } catch (\RuntimeException $e) { self::assertStringContainsString('save_latent_dynamics', $e->getMessage()); }
        $this->expectException(\RuntimeException::class); $fit->profiles();
    }
    public function testPrintHonoursMaxRows(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); ob_start(); $fit->print(maxRows: 2); $out = ob_get_clean();
        self::assertStringContainsString('# showing 2 of 4 rows', $out); self::assertCount(2, json_decode(substr($out, 0, strpos($out, "\n\n")), true));
        $previous = options(['cmdstanr_max_rows' => 1]); ob_start();
        try { $fit->print('x'); } finally { $out = ob_get_clean(); options($previous); }
        self::assertStringContainsString('showing 1 of 2 rows', $out);
    }
    public function testAsCmdStanFitReadsOnlyTheRequestedVariables(): void
    {
        $fit = as_cmdstan_fit($this->csv(), variables: 'x', format: 'draws_matrix');
        self::assertSame(['lp__', 'x[1]', 'x[2]'], $fit->variables()); self::assertSame(['lp__', 'x[1]', 'x[2]'], $fit->metadata()['variables']);
        self::assertSame(['theta' => [1], 'x' => [2]], array_diff_key($fit->metadata()['stan_variable_sizes'], ['lp__' => 1]), 'sizes still describe the whole file');
        self::assertArrayHasKey('divergent__', $fit->samplerDiagnostics()[0]); self::assertCount(2, $fit->draws('x'), 'the format argument sets the default draws format');
        self::assertArrayNotHasKey('divergent__', as_cmdstan_fit($this->csv('b.csv'), variables: ['theta'], checkDiagnostics: false)->samplerDiagnostics('draws_matrix')[0] ?? []);
        $this->expectExceptionMessage("Can't find the following variable(s) in the output: nope"); as_cmdstan_fit($this->csv('c.csv'), variables: ['theta', 'nope']);
    }
    public function testCompressedCsvFilesAreRead(): void
    {
        $plain = $this->csv(); $gz = $this->dir.'/chain.csv.gz'; file_put_contents($gz, gzencode((string)file_get_contents($plain)));
        self::assertSame(CmdStanFit::fromCsv([$plain])->draws(format: 'draws_matrix'), as_cmdstan_fit($gz)->draws(format: 'draws_matrix'));
        if (function_exists('bzcompress') || \CmdStan\CmdStan::which('bzip2') !== null) {
            $bz = $this->dir.'/chain.csv.bz2';
            if (function_exists('bzcompress')) { file_put_contents($bz, bzcompress((string)file_get_contents($plain))); } else { \CmdStan\Process::run(['sh', '-c', 'bzip2 -c "$0" > "$1"', $plain, $bz]); }
            self::assertSame(CmdStanFit::fromCsv([$plain])->draws(format: 'draws_matrix'), as_cmdstan_fit($bz)->draws(format: 'draws_matrix'));
        }
        file_put_contents($this->dir.'/broken.csv.gz', substr((string)file_get_contents($gz), 0, 40));
        $this->expectExceptionMessage("Compressed CSV 'broken.csv.gz' is truncated or corrupt."); as_cmdstan_fit($this->dir.'/broken.csv.gz');
    }
    public function testTupleAndComplexColumnsUseCmdStanRNames(): void
    {
        $file = $this->dir.'/tc.csv';
        file_put_contents($file, "# method = sample\nlp__,z.real,z.imag,t:1,t:2.1,t:2.2,ap.1:1,ap.1:2,ap.2:1,ap.2:2\n-1,1,2,3,4,5,6,7,8,9\n");
        $fit = CmdStanFit::fromCsv([$file]); $meta = $fit->metadata();
        self::assertSame(['lp__', 'z[real]', 'z[imag]', 't:1', 't:2[1]', 't:2[2]', 'ap[1]:1', 'ap[1]:2', 'ap[2]:1', 'ap[2]:2'], $meta['variables']);
        self::assertSame(['lp__' => [1], 'z' => [2], 't' => [1], 'ap' => [2]], $meta['stan_variable_sizes']);
        self::assertSame(['ap[1]:1', 'ap[1]:2'], array_keys($fit->draws('ap[1]', 'draws_matrix')[0]));
        self::assertSame(['z' => [1.0, 2.0], 't' => [3.0, [4.0, 5.0]], 'ap' => [[6.0, 7.0], [8.0, 9.0]]], array_diff_key(Csv::unflatten($fit->draws(null, 'draws_matrix')[0]), ['lp__' => 1]));
    }
    public function testReviewRegressions(): void
    {
        $fit = as_cmdstan_fit($this->csv(), variables: 'x');
        $fit->saveOutputFiles($this->dir.'/kept', 'kept', timestamp: false, random: false);
        self::assertSame(['lp__', 'x[1]', 'x[2]'], $fit->variables(), 'saving keeps the variable filter');
        $gz = $this->dir.'/kept2/kept-01.csv.gz'; mkdir(dirname($gz)); file_put_contents($gz, 'sentinel');
        try { CmdStanFit::fromCsv([$this->csv('d.csv')])->saveOutputFiles($this->dir.'/kept2', 'kept', timestamp: false, random: false, compress: 'gzip'); self::fail('An existing .csv.gz must not be overwritten.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Destination exists', $e->getMessage()); }
        self::assertSame('sentinel', file_get_contents($gz));
        $laplace = $this->dir.'/lap.csv'; file_put_contents($laplace, "# method = laplace\nlp__,log_p__,log_g__,theta\n0,-1,-2,0.5\n0,-3,-4,0.6\n");
        $fit = as_cmdstan_fit($laplace, variables: ['theta'], checkDiagnostics: false);
        self::assertSame([-2.0, -4.0], $fit->lpApprox()); self::assertSame(-1.0, $fit->draws(null, 'draws_matrix')[0]['lp__']);
        $this->expectExceptionMessage("Can't find initial values files."); CmdStanFit::fromCsv([$this->csv('e.csv')])->init();
    }
}
