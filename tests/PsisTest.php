<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{Psis, Special};
final class PsisTest extends TestCase
{
    /** exp(1.5 * qnorm(((0:99 * 37) %% 100 + 0.5) / 100)) in R. */
    private static function draws(): array { return array_map(fn($i) => exp(1.5 * Special::qnorm((($i * 37) % 100 + 0.5) / 100)), range(0, 99)); }
    public function testGeneralizedParetoFitMatchesPosterior(): void
    {
        $fit = Psis::gpdfit(array_map(fn($i) => $i ** 1.5 / 10, range(1, 20)));
        self::assertEqualsWithDelta(-0.12595626041574046, $fit['k'], 1e-12); self::assertEqualsWithDelta(5.3595509754188733, $fit['sigma'], 1e-11);
        self::assertNan(Psis::gpdfit([1, 1, 1, 1, 2])['k']);
        self::assertEqualsWithDelta(1.0, Psis::qgeneralizedPareto(1 - exp(-1)), 1e-15); self::assertNan(Psis::qgeneralizedPareto(0.5, 0, -1, 0.2));
    }
    public function testRightTailSmoothingMatchesPosterior(): void
    {
        $x = self::draws(); $smoothed = Psis::paretoSmooth($x, 'right', 1.0); $d = $smoothed['diagnostics'];
        self::assertEqualsWithDelta(0.51488191575094633, $d['khat'], 1e-12); self::assertEqualsWithDelta(115.17382406854071, $d['min_ss'], 1e-9);
        self::assertEqualsWithDelta(0.77773198861635207, $d['convergence_rate'], 1e-12); self::assertEqualsWithDelta(1 - 1 / log10(100), $d['khat_threshold'], 1e-15);
        self::assertEqualsWithDelta(47.459354076397318, max($smoothed['x']), 1e-10); self::assertSame(27, array_search(max($x), $x, true));
        self::assertCount(20, array_filter(array_keys($x), fn($i) => $x[$i] !== $smoothed['x'][$i])); self::assertSame([], $smoothed['warnings']);
        self::assertEqualsWithDelta(0.51488191575094633, Psis::paretoKhat($x), 1e-12);
    }
    public function testUnsmoothableInputsReturnNaDiagnosticsAndWarnings(): void
    {
        $constant = Psis::paretoSmooth([2, 2, 2, 2, 2, 2]); self::assertNan($constant['diagnostics']['khat']); self::assertCount(1, $constant['warnings']);
        self::assertSame([2.0, 2.0, 2.0, 2.0, 2.0, 2.0], $constant['x']);
        $short = Psis::paretoSmooth([1, 2, 3, 4, 5], 'right', 1.0); self::assertNan($short['diagnostics']['khat']); self::assertStringContainsString('not strictly less', end($short['warnings']));
        self::assertNan(Psis::paretoKhat([1, INF, 3, 4, 5, 6, 7]));
        $this->expectException(\InvalidArgumentException::class); Psis::paretoSmooth(self::draws(), 'middle');
    }
    public function testLogWeightSmoothingShiftsToZeroMaximum(): void
    {
        $lw = array_map('log', self::draws()); $smoothed = Psis::paretoSmoothLogWeights($lw);
        self::assertLessThanOrEqual(0.0, max($smoothed['x'])); self::assertTrue(is_finite($smoothed['diagnostics']['khat']));
        self::assertSame(Psis::paretoSmooth($lw, 'left', are_log_weights: true), $smoothed);
    }
    public function testPsisMatchesLoo(): void
    {
        $lr = array_map(fn($i) => 2 * Special::qnorm((($i * 53) % 200 + 0.5) / 200), range(0, 199));
        $psis = Psis::psis($lr, 0.9); $w = $psis['log_weights'];
        self::assertEqualsWithDelta(0.70675098679456028, $psis['pareto_k'][0], 1e-12); self::assertEqualsWithDelta(13.754845243903103, $psis['n_eff'][0], 1e-10);
        self::assertEqualsWithDelta(-1.581205041220171, max($w), 1e-12); self::assertSame(83, array_search(max($w), $w, true)); self::assertEqualsWithDelta(-12.748214124065596, $w[0], 1e-12);
        self::assertSame([40], $psis['tail_len']); self::assertEqualsWithDelta(1.0, array_sum(array_map('exp', $w)), 1e-12);
        self::assertEqualsWithDelta(min(1 - 1 / log10(200), 0.7), $psis['k_threshold'], 1e-15); self::assertStringContainsString('Pareto k', $psis['warnings'][0]);
        $matrix = Psis::psis(array_map(fn($v) => [$v, -$v], $lr), [0.9, 1.0]);
        self::assertCount(200, $matrix['log_weights']); self::assertSame($w[5], $matrix['log_weights'][5][0]); self::assertCount(2, $matrix['pareto_k']);
    }
    public function testShortTailsAreNotSmoothed(): void
    {
        $psis = Psis::psis([0.1, 0.5, -0.3, 2.0, 1.0, 0.0, -1.0, 0.2, 0.3, 0.4]);
        self::assertSame([INF], $psis['pareto_k']); self::assertStringContainsString('Not enough tail samples', $psis['warnings'][0]);
        self::assertEqualsWithDelta(1.0, array_sum(array_map('exp', $psis['log_weights'])), 1e-12);
    }
    public function testInvalidLogRatios(): void
    {
        foreach ([[1, NAN, 2], [1, INF, 2], [-INF, -INF]] as $bad) {
            try { Psis::psis($bad); self::fail('Expected exception'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $this->expectException(\InvalidArgumentException::class); Psis::psis([[1, 2], [3, 4]], [1, 1, 1]);
    }
    public function testExtraDiagnostics(): void
    {
        self::assertSame(1.0, Psis::psConvergenceRate(-0.2, 100)); self::assertSame(0.0, Psis::psConvergenceRate(1.2, 100));
        self::assertEqualsWithDelta(1 - 1 / log(100), Psis::psConvergenceRate(0.5, 100), 1e-15);
        self::assertSame(10.0, Psis::psMinSs(-0.5)); self::assertSame(INF, Psis::psMinSs(1.0)); self::assertSame(300.0, Psis::psTailLength(10000, 1.0));
    }
}
