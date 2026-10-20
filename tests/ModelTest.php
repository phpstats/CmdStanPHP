<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanModel, CmdStanFit, Installer};
use function CmdStan\{draws_to_csv, write_stan_file};
final class ModelTest extends TestCase
{
    private function model(): CmdStanModel { return new CmdStanModel(write_stan_file('parameters { real x; } model { x ~ normal(0, 1); }', $this->dir.'/model.stan'), PHP_BINARY); }
    public function testModelCodeAndPaths(): void
    {
        $m = $this->model(); self::assertStringContainsString('normal', $m->code()); self::assertSame(realpath($this->dir).'/model.stan', $m->stanFile());
        self::assertSame(realpath($this->dir).'/model.hpp', $m->hppFile()); self::assertSame(realpath(PHP_BINARY), $m->exeFile());
    }
    public function testMissingModel(): void { $this->expectException(\InvalidArgumentException::class); new CmdStanModel($this->dir.'/missing.stan'); }
    public function testUncompiledModel(): void
    {
        $m = new CmdStanModel(write_stan_file('model {}', $this->dir.'/model.stan'));
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('not compiled'); $m->sample();
    }
    public function testBadChainIds(): void { $this->expectException(\InvalidArgumentException::class); $this->model()->sample(chains: 2, chain_ids: [1,1]); }
    public function testWrongSeedCount(): void { $this->expectException(\InvalidArgumentException::class); $this->model()->sample(chains: 2, seed: [123]); }
    public function testRejectsPositionalOptions(): void { $this->expectException(\InvalidArgumentException::class); $this->model()->sample(1,2); }
    public function testUnsafeMakeOptions(): void { $this->expectException(\InvalidArgumentException::class); (new CmdStanModel(write_stan_file('model {}', $this->dir.'/model.stan')))->compile(cpp_options: ['CXXFLAGS' => '$(touch /tmp/bad)']); }
    public function testMakeShellOperatorsAreRejected(): void
    {
        $model = new CmdStanModel(write_stan_file('model {}', $this->dir.'/model.stan'));
        $this->expectException(\InvalidArgumentException::class); $model->compile(cpp_options: ['CXXFLAGS' => '-O3 && touch unwanted']);
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
