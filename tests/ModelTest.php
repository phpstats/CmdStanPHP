<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{Build, CmdStanModel, CmdStanFit, Installer, StaleExecutableException};
use function CmdStan\{draws_to_csv, write_stan_file};
final class ModelTest extends TestCase
{
    private function model(): CmdStanModel { return new CmdStanModel(exeFile: __DIR__.'/fixtures/model.php'); }
    public function testExecutableOnlyModel(): void
    {
        $m = $this->model(); self::assertSame(realpath(__DIR__.'/fixtures/model.php'), $m->exeFile()); self::assertNull($m->stanFile()); self::assertNull($m->hppFile());
        self::assertSame('2.40.0', $m->cmdstanVersion()); self::assertFalse($m->reportedFeatures()['stan_threads']); self::assertTrue($m->isCurrent());
        $warnings = []; set_error_handler(function (int $no, string $message) use (&$warnings) { $warnings[] = $message; return true; }, E_USER_WARNING);
        try { self::assertNull($m->code()); } finally { restore_error_handler(); }
        self::assertStringContainsString('not created with a Stan file', $warnings[0]);
        $this->expectException(\RuntimeException::class); $m->variables();
    }
    public function testMissingModel(): void { $this->expectException(\InvalidArgumentException::class); new CmdStanModel($this->dir.'/missing.stan'); }
    public function testStanFileOrExecutableNotBoth(): void
    {
        foreach ([fn() => new CmdStanModel(), fn() => new CmdStanModel($this->dir.'/a.stan', $this->dir.'/a')] as $i => $call) {
            try { $call(); self::fail('Case '.$i.' should throw.'); } catch (\InvalidArgumentException $e) { self::assertMatchesRegularExpression('/undefined|cannot both/', $e->getMessage()); }
        }
        foreach (['cppOptions' => [], 'stancOptions' => [], 'includePaths' => [], 'pedantic' => true, 'dir' => $this->dir, 'forceRecompile' => false] as $name => $value) {
            try { new CmdStanModel(...['exeFile' => __DIR__.'/fixtures/model.php', $name => $value]); self::fail($name.' should be refused.'); }
            catch (\InvalidArgumentException $e) { self::assertStringStartsWith($name.' cannot be supplied for a model created from an executable alone.', $e->getMessage()); }
        }
    }
    public function testChangedExecutableIsRefused(): void
    {
        copy(__DIR__.'/fixtures/model.php', $this->dir.'/model.php'); chmod($this->dir.'/model.php', 0755);
        $m = new CmdStanModel(exeFile: $this->dir.'/model.php'); file_put_contents($this->dir.'/model.php', "\n// changed", FILE_APPEND);
        self::assertFalse($m->isCurrent());
        $this->expectException(StaleExecutableException::class); $this->expectExceptionMessage('changed after this model was created'); $m->sample();
    }
    public function testThreadsNeedAThreadedExecutable(): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->expectExceptionMessage("`threads_per_chain` is set but the executable does not report threading as enabled.");
        $this->model()->sample(threads_per_chain: 2, show_messages: false, output_dir: $this->dir);
    }
    public function testBadChainIds(): void { $this->expectException(\InvalidArgumentException::class); $this->model()->sample(chains: 2, chain_ids: [1,1]); }
    public function testWrongSeedCount(): void { $this->expectException(\InvalidArgumentException::class); $this->model()->sample(chains: 2, seed: [123]); }
    public function testRejectsPositionalOptions(): void { $this->expectException(\InvalidArgumentException::class); $this->model()->sample(1,2); }
    public function testCppOptionsAreMakeAssignments(): void
    {
        self::assertSame(['A' => '', 'STAN_THREADS' => 'true', 'X' => '2'], Build::cppOptions(['stan_threads' => true, 'x' => 2, 'a' => false, 'skipped' => null]));
        foreach ([['STAN_THREADS=true'], ['CXXFLAGS += -O3'], ['-j4'], ['user_header' => 'h.hpp'], ['STANCFLAGS' => '--O1'], ['1x' => 1], ['CXXFLAGS' => '$(touch /tmp/bad)'], ['CXXFLAGS' => '-O3 && touch unwanted']] as $i => $options) {
            try { Build::cppOptions($options); self::fail('Case '.$i.' should throw.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testStancOptionsAreArguments(): void
    {
        self::assertSame(['--O1', '--warn-uninitialized', '--canonicalize=deprecations', '--max-line-length=78', '--x=a b'], Build::stancOptions(['O1', 'warn-uninitialized', 'canonicalize' => 'deprecations', 'max-line-length' => 78, 'x' => 'a b', 'off' => false]));
        foreach (['include-paths' => ['include-paths' => '.'], 'warn-pedantic' => ['warn-pedantic'], 'allow-undefined' => ['allow-undefined' => true], 'use-opencl' => ['use-opencl'], 'name' => ['name' => 'x'], 'leading' => ['--O1'], 'equals' => ['a=b' => 1]] as $case => $options) {
            try { Build::stancOptions($options); self::fail($case.' should throw.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        self::assertSame("'a b'", Build::makeQuote('a b')); self::assertSame("'it'\\''s'", Build::makeQuote("it's")); self::assertSame('\'$$x\'', Build::makeQuote('$x'));
        self::assertSame(['--b', 'x'], Build::dropOverridden(['--O1', '--a', 'v', '--b', 'x'], ['--O1', '--a']));
    }
    public function testDrawsCsvRoundTrip(): void
    {
        $chains = [[['lp__' => -1.123456789012345, 'x[1,2]' => 0.12345678901234567], ['lp__' => -2.0, 'x[1,2]' => 0.9]]];
        $files = draws_to_csv($chains, $this->dir); $fit = CmdStanFit::fromCsv($files);
        self::assertSame($chains[0], $fit->draws(format: 'draws_matrix'));
    }
    public function testInstallerRejectsInvalidVersionBeforeDownload(): void { $this->expectException(\InvalidArgumentException::class); Installer::install('../../bad', $this->dir); }
    public function testInstallerRefusesExistingInstall(): void { mkdir($this->dir.'/cmdstan-2.37.0'); $this->expectException(\RuntimeException::class); Installer::install('2.37.0', $this->dir); }
}
