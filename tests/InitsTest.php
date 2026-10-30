<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanFit, Inits};
final class InitsTest extends TestCase
{
    private const PARAMETERS = ['theta' => ['type' => 'real', 'dimensions' => 0], 'x' => ['type' => 'vector', 'dimensions' => 1]];
    private function file(string $name, string $contents): string { file_put_contents($this->dir.'/'.$name, $contents); return $this->dir.'/'.$name; }
    public function testMcmcDrawsAreResampledWithoutReplacement(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv'), $this->csv('b.csv', rows: ['-1,0.5,5,6,0,4,1', '-1,0.6,6,7,1,10,3'])]);
        $result = Inits::fromFit($fit, 3, self::PARAMETERS, seed: 7);
        self::assertSame([], $result['warnings']); self::assertCount(3, $result['inits']);
        $thetas = array_column($result['inits'], 'theta'); self::assertCount(3, array_unique($thetas));
        $draws = [[0.3, [3.0, 4.0]], [0.4, [4.0, 5.0]], [0.5, [5.0, 6.0]], [0.6, [6.0, 7.0]]];
        foreach ($result['inits'] as $init) { self::assertSame(['theta', 'x'], array_keys($init)); self::assertContains([$init['theta'], $init['x']], $draws); }
        self::assertSame($result, Inits::fromFit($fit, 3, self::PARAMETERS, seed: 7));
        $this->expectException(\InvalidArgumentException::class); Inits::fromFit($fit, 5, self::PARAMETERS, seed: 7);
    }
    public function testOptimizationEstimateIsRepeated(): void
    {
        $fit = CmdStanFit::fromCsv([$this->file('mle.csv', "# method = optimize\nlp__,theta,x.1,gq\n-3,0.1,1,9\n-1,0.25,2,9\n")]);
        $result = Inits::fromFit($fit, 2, self::PARAMETERS);
        self::assertSame([['theta' => 0.25, 'x' => [2.0]], ['theta' => 0.25, 'x' => [2.0]]], $result['inits']);
        self::assertSame(['theta' => 0.25, 'x' => [2.0], 'gq' => 9.0], Inits::fromFit($fit, 1)['inits'][0]);
    }
    public function testVariationalDrawsAreParetoSmoothedImportanceResampled(): void
    {
        $rows = ['0,0,0,0.5']; for ($i = 1; $i <= 30; ++$i) { $rows[] = '0,'.(-0.01 * $i * $i).','.(-0.02 * $i).','.($i / 10); }
        $fit = CmdStanFit::fromCsv([$this->file('vb.csv', "# method = variational\nlp__,log_p__,log_g__,theta\n".implode("\n", $rows)."\n")]);
        $result = Inits::fromFit($fit, 4, ['theta' => ['type' => 'real', 'dimensions' => 0]], seed: 3);
        $thetas = array_column($result['inits'], 'theta'); self::assertCount(4, array_unique($thetas));
        foreach ($thetas as $theta) { self::assertContains($theta, array_map(fn($i) => $i / 10, range(1, 30))); }
        self::assertSame($result, Inits::fromFit($fit, 4, ['theta' => ['type' => 'real', 'dimensions' => 0]], seed: 3));
    }
    public function testApproximationDuplicatesAreRemovedAndCounted(): void
    {
        $rows = []; for ($i = 0; $i < 20; ++$i) { $rows[] = '0,'.(-($i % 2)).',0,'.($i % 2); }
        $fit = CmdStanFit::fromCsv([$this->file('laplace.csv', "# method = laplace\nlp__,log_p__,log_q__,theta\n".implode("\n", $rows)."\n")]);
        $result = Inits::fromFit($fit, 2, seed: 1); self::assertEqualsCanonicalizing([0.0, 1.0], array_column($result['inits'], 'theta'));
        $this->expectExceptionMessage('Not enough distinct draws (3) in Laplace fit to create inits.'); Inits::fromFit($fit, 3);
    }
    public function testPathfinderWithoutLpUsesUniformWeights(): void
    {
        $rows = []; for ($i = 1; $i <= 10; ++$i) { $rows[] = '0,nan,'.$i; }
        $fit = CmdStanFit::fromCsv([$this->file('pf.csv', "# method = pathfinder\n# calculate_lp = false\nlp_approx__,lp__,theta\n".implode("\n", $rows)."\n")]);
        self::assertCount(10, array_unique(array_column(Inits::fromFit($fit, 10, seed: 2)['inits'], 'theta')));
        $fit = CmdStanFit::fromCsv([$this->file('pf2.csv', "# method = pathfinder\nlp_approx__,lp__,theta\n".implode("\n", $rows)."\n")]);
        $this->expectExceptionMessage('Every draw in the Pathfinder fit has a non-finite log importance weight'); Inits::fromFit($fit, 2);
    }
    public function testRepeatedDrawsKeepTheirMassAndResampledPathfinderIsUniform(): void
    {
        // theta = 1, 1, 2, 3 with equal log weights: three candidates, and theta = 1 carries twice the weight.
        $rows = ['0,0,1', '0,0,1', '0,0,2', '0,0,3'];
        $fit = CmdStanFit::fromCsv([$this->file('lap.csv', "# method = laplace\nlp__,log_p__,log_q__,theta\n".implode("\n", array_map(fn($r) => '0,'.$r, $rows))."\n")]);
        $counts = [1 => 0, 2 => 0, 3 => 0];
        for ($seed = 1; $seed <= 400; ++$seed) { ++$counts[(int)Inits::fromFit($fit, 1, seed: $seed)['inits'][0]['theta']]; }
        self::assertGreaterThan($counts[2] * 1.5, $counts[1]); self::assertGreaterThan($counts[3] * 1.5, $counts[1]);
        self::assertEqualsCanonicalizing([1.0, 2.0, 3.0], array_column(Inits::fromFit($fit, 3, seed: 1)['inits'], 'theta'));
        // Several PSIS-resampled paths: equal weights even though the log weights differ.
        $pf = []; for ($i = 1; $i <= 20; ++$i) { $pf[] = (-$i).','.(-2 * $i).','.$i; }
        $fit = CmdStanFit::fromCsv([$this->file('pf3.csv', "# method = pathfinder\n# num_paths = 4\n# psis_resample = true\n# calculate_lp = true\nlp_approx__,lp__,theta\n".implode("\n", $pf)."\n")]);
        $seen = []; for ($seed = 1; $seed <= 200; ++$seed) { $seen[(int)Inits::fromFit($fit, 1, seed: $seed)['inits'][0]['theta']] = true; }
        self::assertGreaterThan(15, count($seen), 'uniform weights reach nearly every draw');
    }
    public function testDrawsAreRecycledAndNonFiniteValuesRejected(): void
    {
        $result = Inits::fromDraws([['theta' => 1.0, 'x[1]' => 2.0, 'x[2]' => 3.0]], 3, self::PARAMETERS);
        self::assertSame(array_fill(0, 3, ['theta' => 1.0, 'x' => [2.0, 3.0]]), $result['inits']);
        $array = Inits::fromDraws([[['theta' => 1.0], ['theta' => 2.0]]], 2); self::assertSame([['theta' => 1.0], ['theta' => 2.0]], $array['inits']);
        try { Inits::fromDraws([['theta' => NAN, 'x[1]' => INF]], 1, self::PARAMETERS); self::fail('Expected exception'); }
        catch (\InvalidArgumentException $e) { self::assertSame('Variables: theta, x contains NA or Inf values!', $e->getMessage()); }
        $this->expectExceptionMessage('Variable: x contains NA or Inf values!'); Inits::fromDraws([['theta' => 1.0, 'x[2]' => 1.0]], 1, self::PARAMETERS);
    }
    public function testValidationWarnsAndWrapsLikeCmdStanR(): void
    {
        $result = Inits::validate([['theta' => 1.0, 'x' => 2.0], ['x' => [1.0, 2.0], 'other' => 3]], 2, self::PARAMETERS);
        self::assertSame([['theta' => 1.0, 'x' => [2.0]], ['x' => [1.0, 2.0], 'other' => 3]], $result['inits']);
        self::assertSame([['chain' => 2, 'type' => 'missing_parameters', 'parameters' => ['theta']], ['chain' => 2, 'type' => 'unknown_parameters', 'parameters' => ['other']]], array_map(fn($w) => array_diff_key($w, ['message' => 1]), $result['warnings']));
        self::assertSame([], Inits::validate([['x' => 1.0]], 1, self::PARAMETERS, false)['warnings']);
        foreach ([[[['theta' => 1.0]], 2], [[[]], 1], [[['x[1]' => 1.0]], 1], [[[1.0]], 1], [['a' => ['theta' => 1.0]], 1]] as [$inits, $chains]) {
            try { Inits::validate($inits, $chains, self::PARAMETERS); self::fail('Expected exception'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testFitValidation(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]);
        try { Inits::fromFit($fit, 1, ['sigma' => ['type' => 'real', 'dimensions' => 0]]); self::fail('Expected exception'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('None of the names', $e->getMessage()); }
        $diagnose = CmdStanFit::fromCsv([$this->file('diagnose.csv', "# method = diagnose\n# Log probability=-1.25\n")]);
        $this->expectException(\InvalidArgumentException::class); Inits::fromFit($diagnose, 1);
    }
    public function testWeightedSamplingWithoutReplacement(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(5));
        for ($i = 0; $i < 20; ++$i) { $picked = Inits::sampleNoReplace([0.0, 1.0, 0.0, 2.0, 3.0], 3, $rng); sort($picked); self::assertSame([1, 3, 4], $picked); }
        $this->expectException(\InvalidArgumentException::class); Inits::sampleNoReplace([0.0, 1.0], 2, $rng);
    }
}
