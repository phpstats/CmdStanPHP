<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, Process};
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
}
