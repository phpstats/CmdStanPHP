<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, Process, Summary};
use PHPUnit\Framework\Attributes\{Group, DataProvider};
#[Group('compatibility')]
final class CompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            CmdStan::path();
            Process::run(['Rscript', '-e', 'stopifnot(requireNamespace("cmdstanr", quietly=TRUE), requireNamespace("jsonlite", quietly=TRUE), requireNamespace("posterior", quietly=TRUE))']);
        } catch (\Throwable $e) { self::markTestSkipped('R with cmdstanr, posterior, jsonlite and CmdStan required: '.$e->getMessage()); }
    }
    public static function algorithms(): array { return [['sample', 100], ['sample', 101], ['optimize'], ['variational'], ['pathfinder'], ['laplace']]; }
    #[DataProvider('algorithms')]
    public function testSameSeedDrawsAndDescriptiveSummariesAsCmdStanR(string $method, int $iterations = 100): void
    {
        $model = new CmdStanModel(__DIR__.'/../examples/bernoulli.stan');
        $options = ['data' => ['N' => 10, 'y' => [0,1,0,0,0,0,0,0,0,1]], 'seed' => 123, 'refresh' => 0, 'show_messages' => false, 'show_exceptions' => false, 'sig_figs' => 18];
        if ($method === 'sample') { $options += ['chains' => 2, 'parallel_chains' => 2, 'iter_warmup' => 200, 'iter_sampling' => $iterations, 'save_metric' => false, 'save_cmdstan_config' => false]; }
        if ($method === 'optimize') { $options += ['jacobian' => true]; }
        if ($method === 'variational') { $options += ['draws' => 100]; }
        if ($method === 'pathfinder') { $options += ['draws' => 100, 'single_path_draws' => 100, 'num_paths' => 2]; }
        if ($method === 'laplace') { $options += ['draws' => 100]; }
        $phpFit = $model->$method($options + ['output_dir' => $this->dir.'/php']);
        $options['output_dir'] = $this->dir.'/r'; mkdir($options['output_dir']);
        $config = $this->dir.'/options.json'; file_put_contents($config, json_encode($options, JSON_THROW_ON_ERROR));
        $output = $this->dir.'/reference.json';
        Process::run(['Rscript', __DIR__.'/fixtures/reference.R', $method, $model->stanFile(), $model->exeFile(), $config, $output], env: ['CMDSTAN' => CmdStan::path()], timeout: 120);
        $reference = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $draws = $phpFit->draws(format: 'draws_df'); self::assertCount(count($reference['draws']), $draws);
        foreach ($reference['draws'] as $i => $row) {
            foreach (['theta', 'lp__'] as $name) {
                if (isset($row[$name])) { self::assertEqualsWithDelta($row[$name], $draws[$i][$name], 1e-13, $method.' draw '.$i.' '.$name); }
            }
        }
        if ($method === 'sample') {
            $diagnostics = $phpFit->diagnosticSummary();
            foreach (['num_divergent', 'num_max_treedepth', 'ebfmi'] as $name) {
                foreach ($reference['diagnostics'][$name] as $i => $value) { self::assertEqualsWithDelta($value, $diagnostics[$name][$i], 1e-12, $name); }
            }
        }
        if (in_array($method, ['variational', 'pathfinder', 'laplace'], true)) {
            $approx = $phpFit->lpApprox();
            self::assertCount(count($reference['approx_lp']), $approx);
            foreach ($reference['approx_lp'] as $i => $value) { self::assertEqualsWithDelta($value, $approx[$i], 1e-13, $method.' approximate lp'); }
        }
        $summary = array_column($phpFit->summary('theta'), null, 'variable')['theta'];
        $refSummary = array_column($reference['summary'], null, 'variable')['theta'];
        foreach ($method === 'optimize' ? ['estimate'] : ($method === 'sample' ? ['mean', 'median', 'sd', 'mad', 'q5', 'q95', 'rhat', 'ess_bulk', 'ess_tail'] : ['mean', 'median', 'sd', 'mad', 'q5', 'q95']) as $name) {
            if ($refSummary[$name] !== null) { self::assertEqualsWithDelta($refSummary[$name], $summary[$name], in_array($name, ['rhat', 'ess_bulk', 'ess_tail'], true) ? 1e-8 : 1e-12, $method.' summary '.$name); }
        }
    }
    public static function metadataMethods(): array { return [['sample'], ['optimize'], ['variational'], ['pathfinder'], ['laplace']]; }
    #[DataProvider('metadataMethods')]
    public function testMetadataStructureMatchesCmdStanR(string $method): void
    {
        $model = new CmdStanModel(__DIR__.'/../examples/bernoulli.stan');
        $options = ['data' => ['N' => 10, 'y' => [0,1,0,0,0,0,0,0,0,1]], 'seed' => 123, 'show_messages' => false, 'output_dir' => $this->dir] + ($method === 'sample' ? ['chains' => 2, 'iter_warmup' => 50, 'iter_sampling' => 20, 'save_warmup' => true] : []);
        $fit = $model->$method($options);
        $script = 'm <- cmdstanr::read_cmdstan_csv(commandArgs(TRUE)[-1])$metadata; m$time <- NULL; m$stan_variable_sizes <- lapply(m$stan_variable_sizes, as.list); jsonlite::write_json(list(names = names(m), metadata = m[!names(m) %in% c("init", "fitted_params", "profile_file", "file", "data_file", "diagnostic_file", "start_datetime")]), commandArgs(TRUE)[1], digits = NA, auto_unbox = TRUE)';
        Process::run(array_merge(['Rscript', '-e', $script, $this->dir.'/meta.json'], $fit->outputFiles()), env: ['CMDSTAN' => CmdStan::path()], timeout: 120);
        $reference = json_decode(file_get_contents($this->dir.'/meta.json'), true, flags: JSON_THROW_ON_ERROR); $meta = $fit->metadata();
        self::assertSame([], array_values(array_diff($reference['names'], array_keys($meta), ['time'])), 'CmdStanR metadata keys missing in PHP');
        foreach ($reference['metadata'] as $key => $value) {
            $actual = $meta[$key];
            if (is_bool($actual)) { $actual = (int)$actual; }
            if ($key === 'id' && count($actual) === 1) { $actual = $actual[0]; }
            if ($key === 'stan_variable_sizes') { $value = array_map(fn($dims) => (array)$dims, $value); }
            if (is_array($actual) && array_is_list($actual) && count($actual) === 1 && !is_array($value)) { $actual = $actual[0]; }
            self::assertEquals($value, $actual, $method.' metadata '.$key);
        }
    }
    public function testExampleProgramsAndDataMatchCmdStanR(): void
    {
        foreach (['logistic', 'schools', 'schools_ncp'] as $example) {
            $r = Process::run(['Rscript', '-e', 'cmdstanr::print_example_program("'.$example.'")'])['stdout'];
            ob_start(); \CmdStan\print_example_program($example); self::assertSame($r, ob_get_clean(), $example);
            $data = Process::run(['Rscript', '-e', 'cat(readLines(system.file("'.$example.'.data.json", package = "cmdstanr")), sep = "\n")'])['stdout'];
            self::assertSame(json_decode($data, true), json_decode(file_get_contents(__DIR__.'/../resources/examples/'.$example.'.data.json'), true), $example);
        }
    }
    /**
     * An indexed parameter, a fixed quantile set, and both summary paths (stansummary by default, the posterior port with
     * measures) against posterior::summarise_draws() on the same doubles, and diagnosticSummary() against CmdStanR's
     * diagnostic_summary() on the same CSV files. See posterior_fit.R for why R gets the doubles rather than the CSVs.
     */
    public function testIndexedParameterSummariesMatchPosteriorOnTheSameDraws(): void
    {
        copy(__DIR__.'/fixtures/latent.stan', $this->dir.'/latent.stan'); $model = new CmdStanModel($this->dir.'/latent.stan');
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20260831)); $shoe = []; $y = [];
        for ($i = 0; $i < 60; ++$i) { $shoe[] = $i % 12 + 1; $y[] = 0.1 * ($i % 12) + $rng->getInt(-1000, 1000) / 1000; }
        $fit = $model->sample(['data' => ['N' => 60, 'L' => 12, 'shoe' => $shoe, 'y' => $y], 'seed' => 20260831, 'chains' => 4, 'parallel_chains' => 4, 'iter_warmup' => 1000, 'iter_sampling' => 1000, 'adapt_delta' => 0.95, 'sig_figs' => 18, 'refresh' => 0, 'show_messages' => false, 'output_dir' => $this->dir]);
        $probs = [0.05, 0.25, 0.5, 0.75, 0.95]; $variables = ['mu', 'tau', 'shoe_latent'];
        $columns = $fit->draws($variables, 'draws_list'); $binary = '';
        foreach (array_keys($columns[0]) as $name) { foreach ($columns as $chain) { $binary .= pack('E*', ...$chain[$name]); } }
        file_put_contents($this->dir.'/draws.bin', $binary);
        file_put_contents($this->dir.'/input.json', json_encode(['files' => $fit->outputFiles(), 'binary' => $this->dir.'/draws.bin', 'variables' => array_keys($columns[0]), 'iterations' => 1000, 'chains' => 4, 'probs' => $probs], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        Process::run(['Rscript', __DIR__.'/fixtures/posterior_fit.R', $this->dir.'/input.json', $this->dir.'/output.json'], env: ['CMDSTAN' => CmdStan::path()], timeout: 120);
        $reference = json_decode(file_get_contents($this->dir.'/output.json'), true, flags: JSON_THROW_ON_ERROR);
        $matrix = $fit->draws('shoe_latent', 'draws_matrix');
        self::assertSame(array_map(fn($i) => 'shoe_latent['.$i.']', range(1, 12)), array_keys($matrix[0]));
        $all = $fit->draws($variables, 'draws_matrix'); self::assertSame($reference['columns'], array_keys($all[0])); self::assertCount(count($reference['matrix']), $all);
        foreach ($reference['matrix'] as $i => $row) { foreach (array_values($all[$i]) as $j => $value) { self::assertEqualsWithDelta($row[$j], $value, 1e-13 * max(1.0, abs($value)), 'draw '.$i); } }
        $quantiles = array_map(fn($p) => 'q'.Summary::percent($p), $probs);
        $posterior = array_column($fit->summary($variables, $probs, ['mean', 'median', 'sd', 'mad', 'quantile2', 'rhat', 'ess_bulk', 'ess_tail']), null, 'variable');
        $default = array_column($fit->summary($variables, $probs), null, 'variable');
        self::assertCount(14, $reference['summary']);
        foreach ($reference['summary'] as $row) {
            foreach (array_merge(['mean', 'median', 'sd', 'mad', 'rhat', 'ess_bulk', 'ess_tail'], $quantiles) as $name) {
                $tolerance = 1e-8 * max(1.0, abs($row[$name]));
                self::assertEqualsWithDelta($row[$name], $posterior[$row['variable']][$name], $tolerance, 'measures '.$row['variable'].' '.$name);
                self::assertEqualsWithDelta($row[$name], $default[$row['variable']][$name], $tolerance, 'default '.$row['variable'].' '.$name);
            }
        }
        $diagnostics = $fit->diagnosticSummary();
        foreach (['num_divergent', 'num_max_treedepth', 'ebfmi'] as $name) {
            self::assertCount(4, $reference['diagnostics'][$name]);
            foreach ($reference['diagnostics'][$name] as $i => $value) { self::assertEqualsWithDelta($value, $diagnostics[$name][$i], 1e-12, $name.' chain '.($i + 1)); }
        }
    }
}
