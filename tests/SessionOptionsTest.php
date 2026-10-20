<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, CmdStanMCMC};
use function CmdStan\{cmdstan_option, cmdstanr_example, num_threads, options, print_example_program, read_sample_csv, write_stan_file, write_stan_tempfile};
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
        $model = new CmdStanModel(write_stan_file(implode("\n", array_fill(0, 10, '// line')), $this->dir.'/model.stan'), PHP_BINARY);
        ob_start(); $model->print(true); $out = ob_get_clean();
        self::assertStringStartsWith(" 1: // line\n", $out); self::assertStringContainsString("\n10: // line\n", $out);
        options(['cmdstanr_print_line_numbers' => true]); ob_start(); $model->print(); self::assertStringStartsWith(' 1: ', ob_get_clean());
        ob_start(); $model->print(false); self::assertStringStartsWith('// line', ob_get_clean());
    }
    public function testWriteStanFileDirectoryHashAndOverwrite(): void
    {
        options(['cmdstanr_write_stan_file_dir' => $this->dir.'/stan']);
        $a = write_stan_file('model {}'); self::assertMatchesRegularExpression('~/stan/model_[0-9a-f]{32}\.stan$~', $a);
        touch($a, time() - 100); clearstatcache(); $mtime = filemtime($a);
        self::assertSame($a, write_stan_file('model {}')); clearstatcache(); self::assertSame($mtime, filemtime($a));
        self::assertNotSame($a, write_stan_file('model {}', hash_salt: 'x'));
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
    public function testCompileOptionsWithFakeCmdStan(): void
    {
        $root = $this->dir.'/cmdstan'; mkdir($root.'/bin', 0700, true); file_put_contents($root.'/bin/stanc', "#!/bin/sh\n"); chmod($root.'/bin/stanc', 0700);
        file_put_contents($root.'/makefile', "CMDSTAN_VERSION := 2.99.0\n%.hpp: %.stan\n\ttouch \$@\n%: %.stan\n\tprintf '#!/bin/sh\\n' > \$@ && chmod +x \$@\n");
        $property = new \ReflectionProperty(CmdStan::class, 'path'); $previous = $property->getValue(); CmdStan::setPath($root);
        try {
            $model = new CmdStanModel(write_stan_file('model {}', $this->dir.'/m.stan')); options(['cmdstanr_verbose' => true]);
            ob_start(); $model->compile(); self::assertStringContainsString(' -j1 '.realpath($this->dir).'/m ', ob_get_clean()); self::assertFileExists($this->dir.'/m');
            ob_start(); $model->compile(); self::assertSame('', ob_get_clean());
            options(['cmdstanr_force_recompile' => true]); ob_start(); $model->compile(); self::assertStringContainsString('make -C', ob_get_clean());
            $messages = $this->deprecations(function () use ($model) { ob_start(); $model->compile(force_recompile: false, threads: true, compile_hessian_method: true); ob_end_clean(); });
            self::assertCount(2, $messages); self::assertSame(['STAN_THREADS' => true], $model->cppOptions());
        } finally { $property->setValue(null, $previous); }
    }
}
