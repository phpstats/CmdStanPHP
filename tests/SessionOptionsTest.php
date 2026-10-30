<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, CmdStanMCMC};
use function CmdStan\{cmdstan_model, cmdstan_option, cmdstanr_example, print_stan_file, stan_build_info, num_threads, options, print_example_program, read_sample_csv, write_stan_file, write_stan_tempfile};
final class SessionOptionsTest extends TestCase
{
    protected function tearDown(): void { options(array_fill_keys(array_keys(options()), null)); parent::tearDown(); }
    private function deprecations(callable $callback): array
    {
        $messages = []; set_error_handler(function (int $level, string $message) use (&$messages): bool { $messages[] = $message; return true; }, E_USER_DEPRECATED);
        try { $callback(); } finally { restore_error_handler(); }
        return $messages;
    }
    public function testSetReadAndRestore(): void
    {
        self::assertSame([], options()); self::assertSame(7, cmdstan_option('cmdstanr_max_rows', 7));
        self::assertSame(['cmdstanr_max_rows' => null, 'mc.cores' => null], options(['cmdstanr_max_rows' => 3, 'mc.cores' => 4]));
        self::assertSame(3, cmdstan_option('cmdstanr_max_rows', 7)); self::assertSame(['cmdstanr_max_rows' => 3, 'mc.cores' => 4], options());
        $previous = options(['cmdstanr_max_rows' => 5]); self::assertSame(['cmdstanr_max_rows' => 3], $previous);
        options($previous); self::assertSame(3, cmdstan_option('cmdstanr_max_rows'));
        options(['cmdstanr_max_rows' => null]); self::assertNull(cmdstan_option('cmdstanr_max_rows'));
    }
    public function testInvalidOptionsAreRejectedAtomically(): void
    {
        foreach ([['unknown' => 1], ['mc.cores' => 0], ['cmdstanr_verbose' => 1], ['cmdstanr_draws_format' => 'draws_rvars'], ['cmdstanr_output_dir' => ''], ['cmdstanr_max_rows' => 2, 'cmdstanr_force_recompile' => 'yes']] as $set) {
            try { options($set); self::fail('Expected rejection'); } catch (\InvalidArgumentException) {}
        }
        self::assertSame([], options()); $this->expectException(\InvalidArgumentException::class); cmdstan_option('typo');
    }
    public function testPrintLineNumbers(): void
    {
        $file = write_stan_file(implode("\n", array_fill(0, 10, '// line')), $this->dir.'/model.stan');
        ob_start(); print_stan_file($file, true); $out = ob_get_clean();
        self::assertStringStartsWith(" 1: // line\n", $out); self::assertStringContainsString("\n10: // line\n", $out);
        options(['cmdstanr_print_line_numbers' => true]); ob_start(); print_stan_file($file); self::assertStringStartsWith(' 1: ', ob_get_clean());
        ob_start(); print_stan_file($file, false); self::assertStringStartsWith('// line', ob_get_clean());
    }
    public function testWriteStanFileDirectoryHashAndOverwrite(): void
    {
        options(['cmdstanr_write_stan_file_dir' => $this->dir.'/stan']);
        $a = write_stan_file('model {}'); self::assertMatchesRegularExpression('~/stan/model_[0-9a-f]{32}\.stan$~', $a);
        touch($a, time() - 100); clearstatcache(); $mtime = filemtime($a);
        self::assertSame($a, write_stan_file('model {}')); clearstatcache(); self::assertSame($mtime, filemtime($a));
        self::assertNotSame($a, write_stan_file('model {}', hashSalt: 'x'));
        self::assertSame(realpath($this->dir).'/named.stan', write_stan_file(['data {}', 'model {}'], dir: $this->dir, basename: 'named'));
        self::assertSame("data {}\nmodel {}", file_get_contents($this->dir.'/named.stan'));
        $this->expectException(\InvalidArgumentException::class); write_stan_file('model {}', dir: $this->dir, basename: '../escape');
    }
    public function testDeprecatedFunctions(): void
    {
        $file = null; $fit = null; $threads = 0;
        $messages = $this->deprecations(function () use (&$file, &$fit, &$threads) { $file = write_stan_tempfile('model {}', $this->dir); $fit = read_sample_csv($this->csv()); $threads = num_threads(); });
        self::assertCount(3, $messages); self::assertStringContainsString('write_stan_file()', $messages[0]); self::assertStringContainsString('read_cmdstan_csv()', $messages[1]);
        self::assertStringStartsWith(realpath($this->dir).'/model_', $file); self::assertInstanceOf(CmdStanMCMC::class, $fit);
        self::assertSame(getenv('STAN_NUM_THREADS') === false ? null : (int)getenv('STAN_NUM_THREADS'), $threads);
    }
    public function testExampleProgramsAreBundled(): void
    {
        foreach (['logistic', 'schools', 'schools_ncp'] as $example) {
            ob_start(); print_example_program($example); $code = ob_get_clean();
            self::assertStringContainsString('model {', $code); self::assertJson((string)file_get_contents(__DIR__.'/../resources/examples/'.$example.'.data.json'));
        }
        ob_start(); print_example_program(); self::assertStringContainsString('bernoulli_logit_glm', ob_get_clean());
        $this->expectException(\InvalidArgumentException::class); print_example_program('eight_schools');
    }
    public function testExampleArgumentsAreValidatedBeforeCompiling(): void
    {
        foreach ([['logistic', 'generate_quantities'], ['other', 'sample']] as [$example, $method]) {
            try { cmdstanr_example($example, $method); self::fail('Expected rejection'); } catch (\InvalidArgumentException) {}
        }
        $this->expectException(\InvalidArgumentException::class); cmdstanr_example(data: ['N' => 1]);
    }
    /** A stand-in CmdStan: stanc answers --info and writes empty C++, and make turns a .stan file into a script that answers `info`. */
    private function fakeCmdStan(): string
    {
        $root = $this->dir.'/cmdstan'; mkdir($root.'/bin', 0700, true); mkdir($root.'/make', 0700, true);
        file_put_contents($root.'/bin/stanc', "#!/bin/sh\nfor a in \"\$@\"; do case \"\$a\" in --info) echo '{\"inputs\":{},\"parameters\":{\"x\":{\"type\":\"real\",\"dimensions\":0}},\"transformed parameters\":{},\"generated quantities\":{},\"included_files\":[]}'; exit 0;; --o=*) : > \"\${a#--o=}\";; esac; done\n"); chmod($root.'/bin/stanc', 0700);
        file_put_contents($root.'/makefile', "CMDSTAN_VERSION := 2.99.0\n-include make/local\n%: %.stan\n\tprintf '#!/bin/sh\\necho stan_version_major = 2\\necho stan_version_minor = 99\\necho stan_version_patch = 0\\necho STAN_THREADS=\$(or \$(STAN_THREADS),false)\\n' > \$@ && chmod +x \$@\n");
        return $root;
    }
    public function testBuildRecordReuseAndRebuildWithFakeCmdStan(): void
    {
        $property = new \ReflectionProperty(CmdStan::class, 'path'); $previous = $property->getValue(); CmdStan::setPath($this->fakeCmdStan());
        try {
            $file = write_stan_file('parameters { real x; } model {}', $this->dir.'/m.stan');
            ob_start(); $model = cmdstan_model($file, quiet: false); self::assertSame("Compiling Stan program...\n", ob_get_clean());
            self::assertFileExists($this->dir.'/m'); self::assertFileExists($this->dir.'/.m.cmdstanphp.json'); self::assertTrue($model->isCurrent());
            $record = json_decode((string)file_get_contents($this->dir.'/.m.cmdstanphp.json'), true);
            self::assertSame(1, $record['format_version']); self::assertSame('m_model', $record['configuration']['stanc_name']); self::assertSame('2.99.0', $record['cmdstan']['version']);
            self::assertSame(hash_file('xxh128', $this->dir.'/m'), $record['executable_hash']); self::assertSame(['stan_threads' => false, 'stan_mpi' => null, 'stan_opencl' => null, 'stan_no_range_checks' => null, 'stan_version' => '2.99.0'], $model->reportedFeatures());
            ob_start(); cmdstan_model($file, quiet: false); self::assertSame("Model executable is up to date!\n", ob_get_clean());
            ob_start(); $threaded = cmdstan_model($file, quiet: false, cppOptions: ['stan_threads' => true]); self::assertSame("Recompiling:\n  - `cppOptions` changed\n", ob_get_clean());
            self::assertSame(['STAN_THREADS' => 'true'], $threaded->cppOptions()); self::assertTrue($threaded->reportedFeatures()['stan_threads']);
            self::assertFalse($model->isCurrent(), 'the first model no longer matches the rebuilt executable');
            options(['cmdstanr_force_recompile' => true]); ob_start(); cmdstan_model($file, quiet: false, cppOptions: ['stan_threads' => true]);
            self::assertSame("Recompiling:\n  - the `cmdstanr_force_recompile` option is set\n", ob_get_clean());
            ob_start(); cmdstan_model($file, quiet: false, cppOptions: ['stan_threads' => true], forceRecompile: false); self::assertSame("Model executable is up to date!\n", ob_get_clean(), 'an explicit false overrides the option');
            options(['cmdstanr_force_recompile' => null]);
            file_put_contents($file, "\n// edited", FILE_APPEND);
            ob_start(); cmdstan_model($file, quiet: false, cppOptions: ['stan_threads' => true]); self::assertSame("Recompiling:\n  - the Stan program changed\n", ob_get_clean());
            file_put_contents($this->dir.'/.m.cmdstanphp.json', '{');
            ob_start(); cmdstan_model($file, quiet: false, cppOptions: ['stan_threads' => true]); self::assertStringContainsString('could not be read', ob_get_clean());
            self::assertSame([], glob(sys_get_temp_dir().'/cmdstanphp-build-*') ?: []);
            self::assertSame(['status' => 'available', 'reason' => null], stan_build_info($this->dir.'/m')['record']);
        } finally { $property->setValue(null, $previous); options(['cmdstanr_force_recompile' => null]); }
    }
    public function testFailedBuildKeepsThePreviousExecutable(): void
    {
        $property = new \ReflectionProperty(CmdStan::class, 'path'); $previous = $property->getValue(); CmdStan::setPath($root = $this->fakeCmdStan());
        try {
            $file = write_stan_file('model {}', $this->dir.'/m.stan'); cmdstan_model($file); $hash = hash_file('xxh128', $this->dir.'/m');
            file_put_contents($root.'/makefile', "CMDSTAN_VERSION := 2.99.0\n%: %.stan\n\tfalse\n");
            try { cmdstan_model($file, forceRecompile: true); self::fail('The build should fail.'); } catch (\RuntimeException $e) { self::assertStringContainsString('An error occurred during compilation', $e->getMessage()); }
            self::assertSame($hash, hash_file('xxh128', $this->dir.'/m')); self::assertFileExists($this->dir.'/.m.cmdstanphp.json');
        } finally { $property->setValue(null, $previous); }
    }
}
