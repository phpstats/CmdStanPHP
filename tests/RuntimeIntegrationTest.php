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
    public function testPedanticReuseKeepsIncludePaths(): void
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        file_put_contents($this->dir.'/part.stan', "real twice(real x) { return 2 * x; }\n");
        $file = write_stan_file("functions {\n#include part.stan\n}\nparameters { real mu; } model { mu ~ normal(twice(0), 1); }", $this->dir.'/inc.stan');
        new CmdStanModel($file); $model = new CmdStanModel($file, pedantic: true);
        self::assertSame([realpath($this->dir)], $model->includePaths()); self::assertTrue($model->isCurrent());
    }
    /**
     * Eight processes that create the same model at once build it once and all run it: before the build lock, the others could
     * find no executable, or a record that did not match it yet, while one was installing, or replace an executable that
     * another had already adopted. Run on a fresh program, then after the program changes, as after a deploy.
     */
    public function testConcurrentProcessesShareOneBuild(): void
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        $stan = write_stan_file("data { int N; array[N] int y; } parameters { real<lower=0,upper=1> theta; } model { y ~ bernoulli(theta); }", $this->dir.'/shared.stan');
        $script = $this->dir.'/worker.php';
        file_put_contents($script, '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';
            try { $fit = \CmdStan\cmdstan_model($argv[1])->sample(data: '.var_export(self::DATA, true).', chains: 1, iter_warmup: 20, iter_sampling: 20, seed: 1, show_messages: false, output_dir: $argv[2]); echo count($fit->draws("theta", "draws_matrix")); }
            catch (\Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }');
        foreach (['fresh', 'changed'] as $round) {
            if ($round === 'changed') { file_put_contents($stan, "\n// changed\n", FILE_APPEND); }
            $workers = [];
            for ($i = 0; $i < 8; ++$i) {
                $out = $this->dir.'/'.$round.$i; mkdir($out);
                $workers[] = proc_open([PHP_BINARY, $script, $stan, $out], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['CMDSTAN' => CmdStan::path()] + getenv());
                $streams[$i] = $pipes;
            }
            foreach ($workers as $i => $process) {
                $stdout = stream_get_contents($streams[$i][1]); stream_get_contents($streams[$i][2]); proc_close($process);
                self::assertSame('20', $stdout, $round.' worker '.$i);
            }
        }
        self::assertTrue((new CmdStanModel($stan))->isCurrent());
    }
    /** A check waits for an install in progress: while the lock is held the executable is missing, and afterwards it is back. */
    public function testCheckWaitsForAnInstallInProgress(): void
    {
        $model = $this->model(); $exe = $model->exeFile(); $script = $this->dir.'/installer.php';
        file_put_contents($script, '<?php $lock = fopen($argv[1], "c"); flock($lock, LOCK_EX); rename($argv[2], $argv[2].".old"); echo "locked\n"; fflush(STDOUT);
            usleep(500000); copy($argv[2].".old", $argv[2]); chmod($argv[2], 0755); unlink($argv[2].".old"); flock($lock, LOCK_UN);');
        $process = proc_open([PHP_BINARY, $script, \CmdStan\Build::lockFile($exe), $exe], [1 => ['pipe', 'w']], $pipes);
        self::assertSame("locked\n", fgets($pipes[1]));
        self::assertFileDoesNotExist($exe);
        self::assertTrue($model->isCurrent(), 'the check saw the executable mid-install');
        proc_close($process);
    }
}
