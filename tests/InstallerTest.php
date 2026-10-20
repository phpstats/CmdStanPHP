<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, Installer, Process};
use function CmdStan\{cmdstan_make_local, install_cmdstan};
final class InstallerTest extends TestCase
{
    private ?string $previousPath;
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPath = (new \ReflectionProperty(CmdStan::class, 'path'))->getValue();
        mkdir($this->dir.'/install');
    }
    protected function tearDown(): void { (new \ReflectionProperty(CmdStan::class, 'path'))->setValue(null, $this->previousPath); parent::tearDown(); }
    /** A fake release whose build target records make/local and creates an executable bin/stanc. */
    private function tarball(string $name = 'release', string $build = "\tmkdir -p bin\n\tcp make/local bin/local-at-build 2>/dev/null || true\n\tprintf '#!/bin/sh\\necho stanc\\n' > bin/stanc\n\tchmod +x bin/stanc\n", ?callable $extra = null): string
    {
        $src = $this->dir.'/'.$name; mkdir($src.'/cmdstan-2.99.0/make', 0700, true);
        file_put_contents($src.'/cmdstan-2.99.0/makefile', "CMDSTAN_VERSION := 2.99.0\nbuild:\n".$build);
        if ($extra !== null) { $extra($src.'/cmdstan-2.99.0'); }
        $archive = $this->dir.'/'.$name.'.tar.gz';
        Process::run(['tar', '-czf', $archive, '-C', $src, 'cmdstan-2.99.0'], env: ['COPYFILE_DISABLE' => '1']);
        return $archive;
    }
    public function testReleaseFileInstallWritesMakeLocalBeforeBuilding(): void
    {
        $target = install_cmdstan(dir: $this->dir.'/install', releaseFile: $this->tarball(), cppOptions: ['stan_threads' => true, 'CXXFLAGS += -O2'], checkToolchain: false);
        self::assertSame(realpath($this->dir.'/install').'/cmdstan-2.99.0', $target); self::assertSame($target, CmdStan::path()); self::assertSame('2.99.0', CmdStan::version());
        self::assertSame("STAN_THREADS=true\nCXXFLAGS += -O2\n", file_get_contents($target.'/bin/local-at-build'));
        self::assertSame(['STAN_THREADS=true', 'CXXFLAGS += -O2'], cmdstan_make_local());
        self::assertSame(['.', '..', 'cmdstan-2.99.0'], scandir($this->dir.'/install'));
    }
    public function testExistingInstallIsKeptWithoutOverwrite(): void
    {
        mkdir($this->dir.'/install/cmdstan-2.99.0'); file_put_contents($this->dir.'/install/cmdstan-2.99.0/marker', 'old');
        try { Installer::install(dir: $this->dir.'/install', releaseFile: $this->tarball(), checkToolchain: false); self::fail('Expected refusal'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('already exists', $e->getMessage()); }
        self::assertFileExists($this->dir.'/install/cmdstan-2.99.0/marker'); self::assertSame(['.', '..', 'cmdstan-2.99.0'], scandir($this->dir.'/install'));
    }
    public function testOverwriteReplacesOnlyAfterSuccessfulBuild(): void
    {
        mkdir($this->dir.'/install/cmdstan-2.99.0'); file_put_contents($this->dir.'/install/cmdstan-2.99.0/marker', 'old');
        try { Installer::install(dir: $this->dir.'/install', releaseFile: $this->tarball('broken', "\texit 3\n"), overwrite: true, checkToolchain: false); self::fail('Expected build failure'); }
        catch (\RuntimeException) {}
        self::assertSame('old', file_get_contents($this->dir.'/install/cmdstan-2.99.0/marker')); self::assertSame(['.', '..', 'cmdstan-2.99.0'], scandir($this->dir.'/install'));
        $target = Installer::install(dir: $this->dir.'/install', releaseFile: $this->tarball(), overwrite: true, checkToolchain: false);
        self::assertFileDoesNotExist($target.'/marker'); self::assertFileExists($target.'/bin/stanc'); self::assertSame(['.', '..', 'cmdstan-2.99.0'], scandir($this->dir.'/install'));
    }
    public function testArchivesWithEscapingLinksAreRejected(): void
    {
        foreach (['/etc', '../../outside'] as $i => $link) {
            $archive = $this->tarball('link'.$i, extra: fn($root) => symlink($link, $root.'/escape'));
            try { Installer::install(dir: $this->dir.'/install', releaseFile: $archive, checkToolchain: false); self::fail('Expected unsafe link rejection'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('Unsafe link', $e->getMessage()); }
        }
        $archive = $this->tarball('inside', extra: fn($root) => symlink('../makefile', $root.'/make/link'));
        self::assertSame('cmdstan-2.99.0', Installer::archiveRoot($archive)); self::assertSame(['.', '..'], scandir($this->dir.'/install'));
    }
    public function testSourceValidation(): void
    {
        foreach ([['releaseUrl' => 'http://example.com/cmdstan.tar.gz'], ['releaseUrl' => 'https://example.com/cmdstan.zip'], ['releaseFile' => $this->dir.'/missing.tar.gz'], ['version' => '2.36.0', 'releaseFile' => $this->tarball()], ['version' => '2.36'], ['cores' => 0], ['cppOptions' => ["A=1\nB=2"]]] as $args) {
            try { Installer::install(...$args + ['dir' => $this->dir.'/install', 'checkToolchain' => false]); self::fail('Expected rejection: '.json_encode($args)); }
            catch (\InvalidArgumentException) {}
        }
        self::assertSame(['.', '..'], scandir($this->dir.'/install'));
    }
    public function testMakeLocal(): void
    {
        mkdir($this->dir.'/cmdstan/make', 0700, true); $dir = $this->dir.'/cmdstan';
        self::assertSame([], cmdstan_make_local($dir));
        self::assertSame(['STAN_THREADS=true', 'STAN_OPENCL=false'], cmdstan_make_local($dir, ['stan_threads' => true, 'STAN_OPENCL' => false]));
        self::assertSame(['STAN_THREADS=true', 'STAN_OPENCL=false', 'CXX=clang++', 'CXXFLAGS += -O3'], cmdstan_make_local($dir, ['cxx' => 'clang++', 'CXXFLAGS += -O3']));
        self::assertSame(['TBB_CXX_TYPE=gcc'], cmdstan_make_local($dir, ['TBB_CXX_TYPE' => 'gcc'], append: false));
        self::assertSame([], cmdstan_make_local($dir, append: false));
        $this->expectException(\InvalidArgumentException::class); cmdstan_make_local($this->dir, ['A' => 1]);
    }
    public function testToolchainCheckIsStructured(): void
    {
        try { $result = CmdStan::checkToolchain($this->dir); } catch (\RuntimeException $e) { self::markTestSkipped($e->getMessage()); }
        self::assertSame(['make', 'cxx', 'stanc'], array_keys($result)); self::assertNull($result['stanc']); self::assertFileExists($result['make']['path']); self::assertNotSame('', $result['cxx']['version']);
    }
}
