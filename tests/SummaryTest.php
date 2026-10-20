<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanFit, Summary};
final class SummaryTest extends TestCase
{
    private const CHAINS = [[1.2, 0.4, 2.2, -0.3, 0.9, 1.7, 0.1, 1.1, 2.5, 0.6], [0.8, 1.9, -0.5, 1.4, 0.2, 2.1, 1.0, 0.7, 1.6, 0.3]];
    public function testConvergenceMeasuresMatchPosterior(): void
    {
        // Reference values from posterior 1.7.1.
        $x = self::CHAINS;
        self::assertEqualsWithDelta(0.92748682491962331, Summary::rhat($x), 1e-14); self::assertEqualsWithDelta(0.9248959974913783, Summary::rhatBasic($x), 1e-14);
        foreach (['ess_bulk', 'ess_tail', 'ess_basic', 'ess_sd', 'ess_mean'] as $measure) { self::assertEqualsWithDelta(10.0, Summary::measure($measure, $x), 1e-12, $measure); }
        self::assertEqualsWithDelta(0.26466960535732093, Summary::mcseMean($x), 1e-14); self::assertEqualsWithDelta(0.13837633051596696, Summary::mcseSd($x), 1e-14);
        self::assertEqualsWithDelta(0.3, Summary::mcseMedian($x), 1e-14);
        $mcse = Summary::mcseQuantile($x); self::assertSame(['mcse_q5', 'mcse_q95'], array_keys($mcse)); self::assertEqualsWithDelta(0.4, $mcse['mcse_q5'], 1e-14); self::assertEqualsWithDelta(0.45, $mcse['mcse_q95'], 1e-14);
        self::assertSame(['ess_q5', 'ess_q95'], array_keys(Summary::essQuantile($x)));
        self::assertEqualsWithDelta(0.96369, Summary::mad($x), 1e-14); self::assertEqualsWithDelta(0.95, Summary::median($x), 1e-15);
        $q = Summary::quantile2($x, [0.1, 0.5, 0.9]); self::assertSame(['q10', 'q50', 'q90'], array_keys($q));
        self::assertEqualsWithDelta(0.06, $q['q10'], 1e-14); self::assertEqualsWithDelta(2.11, $q['q90'], 1e-14);
    }
    public function testFlatVectorsAreOneChainAndOddChainsDropTheMiddleDraw(): void
    {
        $flat = array_merge(...self::CHAINS);
        self::assertSame(Summary::essBulk([$flat]), Summary::essBulk($flat));
        self::assertSame([[1.0, 2.0], [4.0, 5.0]], Summary::splitChains([[1, 2, 3, 4, 5]]));
    }
    public function testDegenerateInputsAreNan(): void
    {
        foreach (['rhat', 'ess_bulk', 'ess_tail', 'mcse_mean', 'mcse_sd'] as $measure) {
            self::assertNan(Summary::measure($measure, [[1, 1, 1, 1], [1, 1, 1, 1]]), $measure);
            self::assertNan(Summary::measure($measure, [[1, 2, NAN, 4], [1, 2, 3, 4]]), $measure);
        }
        self::assertNan(Summary::essBasic([[1, 2], [3, 4]]));
        self::assertNan(Summary::quantile2([1, NAN])['q5']);
        $this->expectException(\InvalidArgumentException::class); Summary::rhat([[1, 2, 3], [1, 2]]);
    }
    public function testDefaultSummaryColumns(): void
    {
        $rows = Summary::summarise([['a' => self::CHAINS[0]], ['a' => self::CHAINS[1]]]);
        self::assertSame(['variable', 'mean', 'median', 'sd', 'mad', 'q5', 'q95', 'rhat', 'ess_bulk', 'ess_tail'], array_keys($rows[0]));
        self::assertEqualsWithDelta(0.92748682491962331, $rows[0]['rhat'], 1e-14);
    }
    public function testMeasureGroupsCallablesAndNaming(): void
    {
        $draws = [['a' => self::CHAINS[0], 'b' => [1, 2, 3, 4]], ['a' => self::CHAINS[1], 'b' => [2, 3, 4, 5]]];
        $rows = Summary::summarise([['a' => self::CHAINS[0]], ['a' => self::CHAINS[1]]], ['default_mcse_measures']);
        self::assertSame(['variable', 'mcse_mean', 'mcse_median', 'mcse_sd', 'mcse_q5', 'mcse_q95'], array_keys($rows[0]));
        self::assertSame([['a' => [1, 3]], ['a' => [2, 4]]], Summary::fromDrawsArray([[['a' => 1], ['a' => 2]], [['a' => 3], ['a' => 4]]]));
        $rows = Summary::summarise($draws, [
            'mean', 'centre' => 'median', 'range' => fn(array $x) => max($x) - min($x), 'interval' => fn(array $x) => ['lo' => min($x), 'hi' => max($x)],
            'pair' => fn(array $x) => [min($x), max($x)], 'nchains' => fn(array $x, array $chains) => count($chains), 'one' => fn(array $x) => [7],
        ], [0.25]);
        self::assertSame(['variable', 'mean', 'centre', 'range', 'lo', 'hi', 'pair.1', 'pair.2', 'nchains', 'one'], array_keys($rows[1]));
        self::assertSame(['variable' => 'b', 'mean' => 3.0, 'centre' => 3.0, 'range' => 4.0, 'lo' => 1.0, 'hi' => 5.0, 'pair.1' => 1.0, 'pair.2' => 5.0, 'nchains' => 2.0, 'one' => 7.0], $rows[1]);
        self::assertSame(['variable', 'q25'], array_keys(Summary::summarise($draws, ['quantile2'], [0.25])[0]));
    }
    public function testInvalidMeasures(): void
    {
        foreach ([['nope'], [fn($x) => 1.0], ['variable' => 'mean'], ['bad' => fn($x) => ['variable' => 1]]] as $measures) {
            try { Summary::summarise([['a' => [1.0, 2.0]]], $measures); self::fail('Expected exception'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $this->expectException(\InvalidArgumentException::class); Summary::summarise([['a' => [1.0, 2.0]]], ['quantile2'], [1.5]);
    }
    public function testFitDrawsList(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv'), $this->csv('b.csv', rows: ['-1,0.5,3,4,0,4,1', '-1,0.9,4,5,1,10,3'])]);
        $rows = array_column(Summary::summarise($fit->draws(['theta', 'x'], 'draws_list'), ['mean', 'sd']), null, 'variable');
        self::assertSame(['theta', 'x[1]', 'x[2]'], array_keys($rows)); self::assertEqualsWithDelta(0.525, $rows['theta']['mean'], 1e-15);
    }
}
