<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanModel, CmdStanFit, Options};
use function CmdStan\{options, write_stan_file};
final class ExecutionTest extends TestCase
{
    private function model(): CmdStanModel { return new CmdStanModel(exeFile: __DIR__.'/fixtures/model.php'); }
    public function testRunInputsAndLiteralPathsWithSpaces(): void
    {
        $out = $this->dir.'/output with spaces';
        $fit = $this->model()->sample(data: ['N' => 1], init: [['theta' => 0.2], ['theta' => 0.3]], inv_metric: [1.0], chains: 2, parallel_chains: 2, seed: [12,34], output_dir: $out, show_messages: false, diagnostics: null);
        self::assertSame([0,0], $fit->returnCodes()); self::assertCount(2, $fit->outputFiles());
        $a = json_decode($fit->output()[0], true); $b = json_decode($fit->output()[1], true);
        self::assertContains('seed=12', $a); self::assertContains('seed=34', $b); self::assertContains('id=2', $b);
        $data = array_values(array_filter($a, fn($x) => str_contains($x, '-data.json')))[0];
        self::assertSame(['N' => 1], json_decode(file_get_contents(substr($data, 5)), true));
        $init = array_values(array_filter($b, fn($x) => str_starts_with($x, 'init=')))[0];
        self::assertSame(['theta' => 0.3], json_decode(file_get_contents(substr($init, 5)), true));
        self::assertSame([], $fit->diagnosticWarnings());
    }
    public function testMpiLauncherArgumentVector(): void
    {
        $script = $this->dir.'/launcher.php'; $marker = $this->dir.'/launcher-args.json';
        file_put_contents($script, '<?php require '.var_export(__DIR__.'/../vendor/autoload.php', true).'; $args = array_slice($argv, 1); file_put_contents('.var_export($marker, true).', json_encode($args)); $result = CmdStan\\Process::run($args); echo $result["stdout"];');
        $fit = $this->model()->sampleMpi(mpiCmd: PHP_BINARY, mpiArgs: [$script], iter_sampling: 2, show_messages: false, output_dir: $this->dir);
        self::assertSame(1, $fit->numChains()); $args = json_decode(file_get_contents($marker), true);
        self::assertSame(realpath(__DIR__.'/fixtures/model.php'), $args[0]); self::assertContains('num_samples=2', $args);
    }
    public function testOutputCollisionRefused(): void
    {
        file_put_contents($this->dir.'/model-1.csv', 'sentinel'); file_put_contents($this->dir.'/model-data.json', 'original'); $this->expectException(\RuntimeException::class);
        $this->model()->sample(data: ['N' => 2], chains: 1, output_dir: $this->dir, output_basename: 'model', show_messages: false);
    }
    public function testBasenameWithDots(): void
    {
        $fit = $this->model()->sample(chains: 1, output_dir: $this->dir, output_basename: 'model.v2', show_messages: false);
        self::assertStringEndsWith('model.v2-1.csv', $fit->outputFiles()[0]);
    }
    public function testUnsupportedOptionsFailEarly(): void { $this->expectException(\InvalidArgumentException::class); $this->model()->sample(silent_typo: true); }
    public function testProfileReader(): void
    {
        $csv = $this->csv(); $profile = $this->dir.'/profile.csv'; file_put_contents($profile, "name,thread_id,total_time,num_calls\ndensity,123,0.001,5\n");
        $fit = new CmdStanFit([$csv], profileFiles: [$profile]);
        self::assertSame('density', $fit->profiles()[0][0]['name']); self::assertSame(5.0, $fit->profiles()[0][0]['num_calls']);
        self::assertCount(1, $fit->saveProfileFiles($this->dir.'/saved'));
    }
    public function testDiagnosticsMessages(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]); self::assertCount(2, $fit->checkDiagnostics()); self::assertCount(1, $fit->checkDiagnostics(['divergences']));
    }
    public function testBooleanDisplayOptions(): void { Options::validate('sample', ['show_messages' => false, 'show_exceptions' => false]); self::assertTrue(true); }
    /** Runs $callback while collecting E_USER_DEPRECATED messages. */
    private function deprecations(callable $callback): array
    {
        $messages = []; set_error_handler(function (int $level, string $message) use (&$messages): bool { $messages[] = $message; return true; }, E_USER_DEPRECATED);
        try { $callback(); } finally { restore_error_handler(); }
        return $messages;
    }
    public function testArgumentsRemovedInCmdStanR1AreRefused(): void
    {
        foreach (['cores' => 2, 'num_cores' => 2, 'num_chains' => 2, 'num_warmup' => 3, 'num_samples' => 7, 'max_depth' => 5, 'stepsize' => 0.5, 'save_extra_diagnostics' => true, 'validate_csv' => false] as $name => $value) {
            try { $this->model()->sample(...[$name => $value, 'show_messages' => false, 'output_dir' => $this->dir]); self::fail($name.' should be refused.'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Unknown sample option: '.$name, $e->getMessage()); }
        }
        foreach ([['optimize', 'jacobian_adjustment'], ['variational', 'output_samples'], ['optimize', 'save_latent_dynamics'], ['pathfinder', 'save_latent_dynamics'], ['sample', 'threads'], ['optimize', 'threads_per_chain']] as [$method, $name]) {
            try { $this->model()->$method(...[$name => true, 'show_messages' => false, 'output_dir' => $this->dir]); self::fail($method.' '.$name.' should be refused.'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Unknown '.$method.' option: '.$name, $e->getMessage()); }
        }
    }
    public function testPathfinderNumThreadsIsDeprecated(): void
    {
        $fit = null;
        $messages = $this->deprecations(function () use (&$fit) { $fit = $this->model()->pathfinder(num_threads: 1, show_messages: false, output_dir: $this->dir); });
        self::assertSame(['`num_threads` is deprecated as of CmdStanR 1.0.0 and will be removed in a future release. Please use `threads` instead.'], $messages);
        self::assertContains('num_threads=1', json_decode($fit->output()[0], true));
        $this->expectException(\InvalidArgumentException::class); $this->model()->pathfinder(num_threads: 1, threads: 1, show_messages: false, output_dir: $this->dir);
    }
    public function testRunOutputsAreMovedWhenSaved(): void
    {
        $fit = $this->model()->sample(data: ['N' => 1], chains: 2, chain_ids: [3, 4], show_messages: false, output_dir: $this->dir.'/run');
        $original = $fit->outputFiles(); $data = $fit->dataFile();
        $saved = $fit->saveOutputFiles($this->dir.'/saved');
        self::assertMatchesRegularExpression('~/saved/model-\d{12}-3-([0-9a-f]{6})\.csv$~', $saved[0]); self::assertMatchesRegularExpression('~/saved/model-\d{12}-4-[0-9a-f]{6}\.csv$~', $saved[1]);
        foreach ($original as $file) { self::assertFileDoesNotExist($file); }
        self::assertSame($saved, $fit->outputFiles()); self::assertSame(2, $fit->numChains());
        $newData = $fit->saveDataFile($this->dir.'/saved', 'input', timestamp: false, random: false);
        self::assertSame(realpath($this->dir.'/saved').'/input.json', $newData); self::assertFileDoesNotExist($data); self::assertSame($newData, $fit->dataFile());
        $user = $this->dir.'/user.json'; file_put_contents($user, '{"N": 1}');
        $fit = $this->model()->sample(data: $user, chains: 1, show_messages: false, output_dir: $this->dir.'/run2');
        $fit->saveDataFile($this->dir.'/saved2'); self::assertFileExists($user);
    }
    public function testSessionOptionsSetDefaults(): void
    {
        $previous = options(['cmdstanr_output_dir' => $this->dir.'/session', 'mc.cores' => 2, 'cmdstanr_verbose' => true]);
        try { ob_start(); $fit = $this->model()->sample(chains: 2, show_messages: false); $out = ob_get_clean(); }
        finally { options($previous); }
        self::assertStringStartsWith(realpath($this->dir.'/session').'/', $fit->outputFiles()[0]);
        self::assertSame(2, substr_count($out, realpath(__DIR__.'/fixtures/model.php').' sample '));
        self::assertSame([], options());
    }
}
