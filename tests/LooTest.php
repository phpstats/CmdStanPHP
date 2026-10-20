<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanFit, Loo};
final class LooTest extends TestCase
{
    /** ll[i, c, j] = -0.5 * (y[j] - 0.5 * sin(0.7 i + 1.3 c))^2 with y = (0, 0.5, 3), 30 iterations and 2 chains. */
    private static function logLik(): array
    {
        $out = [];
        for ($i = 1; $i <= 30; ++$i) { for ($c = 1; $c <= 2; ++$c) { $mu = sin($i * 0.7 + $c * 1.3) * 0.5; $out[$i - 1][$c - 1] = ['log_lik[1]' => -0.5 * (0 - $mu) ** 2, 'log_lik[2]' => -0.5 * (0.5 - $mu) ** 2, 'log_lik[3]' => -0.5 * (3 - $mu) ** 2]; } }
        return $out;
    }
    public function testLooMatchesCmdStanRAndLoo(): void
    {
        // Reference values from loo 2.10 via loo.array(ll, r_eff = relative_eff(exp(ll - max))), as CmdStanMCMC$loo() does.
        $ll = self::logLik();
        foreach ([0.81381630518640447, 0.43086491115097808, 0.45634201814248365] as $j => $r) { self::assertEqualsWithDelta($r, Loo::relativeEffLogLik($ll)[$j], 1e-12); }
        $loo = Loo::loo($ll);
        self::assertSame([60, 3], $loo['dims']); self::assertSame(['log_lik[1]', 'log_lik[2]', 'log_lik[3]'], $loo['observations']);
        $expected = ['elpd_loo' => [-5.3744328541781794, 4.9690123282117193], 'p_loo' => [1.0410599969386696, 0.98784104378770221], 'looic' => [10.748865708356359, 9.9380246564234387]];
        foreach ($expected as $name => [$estimate, $se]) { self::assertEqualsWithDelta($estimate, $loo['estimates'][$name]['Estimate'], 1e-12); self::assertEqualsWithDelta($se, $loo['estimates'][$name]['SE'], 1e-12); }
        self::assertSame($loo['estimates']['elpd_loo']['Estimate'], $loo['elpd_loo']); self::assertSame($loo['estimates']['looic']['SE'], $loo['se_looic']);
        foreach ([-0.98622826312956302, -0.97957552052794805, -0.94595503953218152] as $j => $k) { self::assertEqualsWithDelta($k, $loo['diagnostics']['pareto_k'][$j], 1e-12); }
        foreach ([48.73101355108021, 24.922516042200382, 14.702000510313772] as $j => $n) { self::assertEqualsWithDelta($n, $loo['diagnostics']['n_eff'][$j], 1e-10); }
        $row = [-0.062858310652318394, 0.0063751383949212717, 0.0019956751114262872, 0.12571662130463679, -0.98622826312956302];
        self::assertSame(['elpd_loo', 'mcse_elpd_loo', 'p_loo', 'looic', 'influence_pareto_k'], array_keys($loo['pointwise'][0]));
        foreach (array_values($loo['pointwise'][0]) as $i => $value) { self::assertEqualsWithDelta($row[$i], $value, 1e-12); }
        self::assertSame([], $loo['warnings']);
    }
    public function testWaicMatchesLoo(): void
    {
        $waic = Loo::waic(self::logLik());
        $expected = ['elpd_waic' => [-5.5042343259267792, 5.0988037044085495], 'p_waic' => [1.1708614686872694, 1.1176287476188713], 'waic' => [11.008468651853558, 10.197607408817099]];
        foreach ($expected as $name => [$estimate, $se]) { self::assertEqualsWithDelta($estimate, $waic['estimates'][$name]['Estimate'], 1e-12); self::assertEqualsWithDelta($se, $waic['estimates'][$name]['SE'], 1e-12); }
        self::assertSame(['elpd_waic', 'p_waic', 'waic'], array_keys($waic['pointwise'][0])); self::assertCount(1, $waic['warnings']);
        self::assertStringStartsWith('1 (33.3%) p_waic estimates greater than 0.4', $waic['warnings'][0]);
    }
    public function testRelativeEfficiencyAndMatrixInput(): void
    {
        $matrix = array_merge(...array_map(fn($c) => array_column(self::logLik(), $c), [0, 1]));
        $loo = Loo::loo($matrix, 1.0); self::assertSame([60, 3], $loo['dims']); self::assertSame([1.0, 1.0, 1.0], $loo['diagnostics']['r_eff']);
        $plain = Loo::loo(self::logLik(), false); self::assertSame($plain['estimates'], $loo['estimates']);
        self::assertNan(Loo::relativeEff([[[1.0, 2.0]], [[1.0, 3.0]], [[1.0, 4.0]], [[1.0, 5.0]]])[0]);
        $constant = Loo::loo(array_map(fn($row) => [0.0, $row['log_lik[1]']], $matrix), true); self::assertSame(1.0, $constant['diagnostics']['r_eff'][0]);
    }
    public function testParetoKTable(): void
    {
        $table = Loo::paretoKTable(['k_threshold' => 0.7, 'diagnostics' => ['pareto_k' => [0.1, 0.8, 1.5, 0.2], 'n_eff' => [40.0, 5.0, 1.0, 30.0]]]);
        self::assertSame(['(-Inf, 0.7]', '(0.7, 1]', '(1, Inf)'], array_column($table['rows'], 'interval'));
        self::assertSame([2, 1, 1], array_column($table['rows'], 'count')); self::assertSame(30.0, $table['rows'][0]['min_n_eff']); self::assertNan($table['rows'][1]['min_n_eff']);
    }
    public function testInvalidLogLikelihood(): void
    {
        foreach ([fn() => Loo::loo([[[1.0, -INF]], [[1.0, 2.0]]]), fn() => Loo::waic([[[1.0, -INF]], [[1.0, 2.0]]]), fn() => Loo::loo([[[1.0, NAN]]]), fn() => Loo::loo([])] as $call) {
            try { $call(); self::fail('Expected exception'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testFromFitUsesLogLikDraws(): void
    {
        $rows = []; foreach (self::logLik() as $iteration) { $rows[] = '-1,'.implode(',', $iteration[0]); }
        $file = $this->dir.'/ll.csv'; file_put_contents($file, "# method = sample\nlp__,log_lik.1,log_lik.2,log_lik.3\n".implode("\n", $rows)."\n");
        $fit = CmdStanFit::fromCsv([$file]);
        self::assertSame(Loo::loo($fit->draws('log_lik')), Loo::fromFit($fit)); self::assertSame(Loo::waic($fit->draws('log_lik')), Loo::waicFromFit($fit));
    }
}
