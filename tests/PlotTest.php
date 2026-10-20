<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanFit, Figure, Plot};
use function CmdStan\{mcmc_trace, mcmc_hist, mcmc_dens, mcmc_dens_overlay, mcmc_intervals, mcmc_rank_hist, mcmc_scatter, mcmc_acf, pareto_k_plot};
final class PlotTest extends TestCase
{
    /** Two autocorrelated chains of 200 draws as a draws_array. */
    private static function draws(): array
    {
        $draws = []; $state = [0.0, 0.5];
        for ($i = 0; $i < 200; ++$i) {
            $iteration = [];
            foreach ([0, 1] as $c) { $state[$c] = 0.6 * $state[$c] + sin(1.7 * $i + 2.3 * $c) * 0.8; $iteration[] = ['mu' => $state[$c], 'sigma' => exp($state[$c] / 4), 'beta[1]' => $state[$c] + 1, 'beta[2]' => 2 - $state[$c]]; }
            $draws[] = $iteration;
        }
        return $draws;
    }
    /** @return array<string, Figure> */
    private static function figures(): array
    {
        $d = self::draws();
        return ['trace' => mcmc_trace($d, 'mu'), 'hist' => mcmc_hist($d, 'mu'), 'dens' => mcmc_dens($d, 'mu'), 'overlay' => mcmc_dens_overlay($d, 'mu'), 'intervals' => mcmc_intervals($d), 'rank' => mcmc_rank_hist($d, 'mu'), 'scatter' => mcmc_scatter($d, 'mu', 'sigma'), 'acf' => mcmc_acf($d, 'mu', 10), 'pareto' => pareto_k_plot(['diagnostics' => ['pareto_k' => [0.1, 0.8, 1.3]], 'k_threshold' => 0.7])];
    }
    private static function assertAscii(string $figure, int $width): void
    {
        self::assertMatchesRegularExpression('/^[\x20-\x7E\n]+$/D', $figure);
        foreach (explode("\n", $figure) as $line) { self::assertLessThanOrEqual($width + 12, strlen($line)); self::assertSame(rtrim($line), $line); }
    }
    public function testEveryFigureRendersAsPrintableAscii(): void
    {
        foreach (self::figures() as $figure) { self::assertAscii((string)$figure, 72); self::assertSame($figure->ascii(), (string)$figure); }
        $narrow = mcmc_trace(self::draws(), 'mu')->ascii(40, 6); self::assertAscii($narrow, 40); self::assertCount(11, explode("\n", $narrow));
    }
    public function testComputedValuesMatchR(): void
    {
        $x = [1.0, 2.0, 3.0, 4.0, 10.0];
        self::assertEqualsWithDelta(0.97358462285063574, Plot::bandwidth($x), 1e-15);
        self::assertEqualsWithDelta([0.0590232059840053516, 0.1936759067320078798, 0.0014394772690648123], Plot::density($x, [0.0, 2.5, 7.0]), 1e-15);
        self::assertEqualsWithDelta(3.4103622746483957, Plot::bandwidth([5.0, 5.0, 5.0, 5.0]), 1e-14);
        self::assertSame([4.0, 1.0, 4.0, 2.0, 4.0], array_map('floatval', Plot::ranks([3, 1, 3, 2, 3])));
        self::assertEqualsWithDelta([1.0, 0.1, 0.34285714285714286, -0.44285714285714289], Plot::autocorrelation([1, 3, 2, 5, 4, 6], 3), 1e-15);
    }
    public function testFigureDataHoldTheComputedStatistics(): void
    {
        $f = self::figures();
        self::assertCount(400, $f['trace']->data); self::assertSame(['chain' => 2, 'iteration' => 1, 'value' => 0.5 * 0.6 + sin(2.3) * 0.8], $f['trace']->data[200]);
        self::assertSame(400, array_sum(array_column($f['hist']->data, 'count'))); self::assertCount(512, $f['dens']->data); self::assertCount(1024, $f['overlay']->data);
        self::assertSame(['mu', 'sigma', 'beta[1]', 'beta[2]'], array_column($f['intervals']->data, 'variable'));
        self::assertEqualsWithDelta(CmdStanFit::quantile(array_column(array_merge(...self::draws()), 'mu'), 0.95), $f['intervals']->data[0]['hh'], 1e-12);
        self::assertSame(400, array_sum(array_column($f['rank']->data, 'count'))); self::assertSame(10.0, $f['rank']->data[0]['expected']);
        self::assertSame([1.0], array_values(array_unique(array_column(array_filter($f['acf']->data, fn($row) => $row['lag'] === 0), 'acf'))));
        self::assertSame([['observation' => 1, 'k' => 0.1], ['observation' => 2, 'k' => 0.8], ['observation' => 3, 'k' => 1.3]], $f['pareto']->data);
    }
    public function testAsciiPlacesMarksWhereTheDataAre(): void
    {
        $lines = explode("\n", mcmc_hist([[['v' => 0.0]], [['v' => 0.0]], [['v' => 0.0]], [['v' => 1.0]]], 'v', 2)->ascii(40, 6));
        self::assertSame('  |'.str_repeat('#', 19), $lines[1]); self::assertSame('0 +'.str_repeat('#', 19).' '.str_repeat('#', 19), $lines[6]);
        self::assertSame('  ++-------------------+------------------+', $lines[7]);
        $intervals = explode("\n", mcmc_intervals([[['a' => 0.0, 'b' => 10.0]], [['a' => 1.0, 'b' => 11.0]], [['a' => 2.0, 'b' => 12.0]]])->ascii(52));
        self::assertSame('intervals: 50% inner, 90% outer, point = median', $intervals[0]);
        self::assertSame('a |--[=o=]-', $intervals[1]); self::assertSame('b |'.str_repeat(' ', 41).'-[=o=]--', $intervals[2]);
        self::assertSame('                       5                  10', $intervals[4]); self::assertSame('   - outer, [=] inner, o = median', $intervals[5]);
        $k = (string)self::figures()['pareto'];
        self::assertSame(2, substr_count(substr($k, 0, strrpos($k, "\n")), 'X')); self::assertStringContainsString('above threshold (2 of 3)', $k);
    }
    public function testVegaLiteSpecsInlineTheData(): void
    {
        foreach (self::figures() as $name => $figure) {
            $spec = $figure->vegaLite(); self::assertSame(Figure::VEGA_LITE_SCHEMA, $spec['$schema'], $name); self::assertSame($figure->data, $spec['data']['values'], $name);
            self::assertSame($spec, json_decode($figure->json(), true, flags: JSON_THROW_ON_ERROR), $name);
        }
        $trace = self::figures()['trace']->vegaLite(300, 100, 100);
        self::assertSame([300, 100], [$trace['width'], $trace['height']]); self::assertCount(100, $trace['data']['values']); self::assertSame([1, 5, 9], array_slice(array_column($trace['data']['values'], 'iteration'), 0, 3));
        self::assertSame('chain', self::figures()['trace']->vegaLite()['encoding']['color']['field']);
        self::assertSame(['row'], array_keys(self::figures()['acf']->vegaLite()['facet']));
        self::assertSame('datum.k > 0.69999999999999996', self::figures()['pareto']->vegaLite()['transform'][0]['calculate']);
    }
    public function testHtmlEmbedsAnEscapedSpec(): void
    {
        $figure = new Figure('pareto_k', '</script><b>k</b>', [['observation' => 1, 'k' => 0.2]], ['threshold' => 0.7]);
        $html = $figure->html();
        self::assertStringContainsString('<title>&lt;/script&gt;&lt;b&gt;k&lt;/b&gt;</title>', $html); self::assertSame(4, substr_count($html, '</script>'));
        self::assertStringContainsString("vegaEmbed('#figure', ", $html); self::assertStringContainsString('https://cdn.jsdelivr.net/npm/vega-lite@5', $html);
    }
    public function testVlConvertRendersEveryFigure(): void
    {
        if (!getenv('VL_CONVERT') && CmdStan::which('vl-convert') === null) { self::markTestSkipped('vl-convert is not installed; set VL_CONVERT to run this test.'); }
        foreach (self::figures() as $name => $figure) { self::assertStringStartsWith('<svg', $figure->svg(maxRows: 200), $name); }
        self::assertStringStartsWith("\x89PNG", self::figures()['intervals']->png(scale: 1.0));
    }
    public function testFitsSupplyDrawsAndMarkDivergences(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv'), $this->csv('b.csv', '', ['-1,0.5,3,4,0,4,1', '-1,0.6,4,5,0,4,2'])]);
        self::assertStringContainsString('chain 2 = o', (string)mcmc_trace($fit, 'theta'));
        $scatter = mcmc_scatter($fit, 'theta', 'x[1]');
        self::assertStringContainsString('X = divergent transition (1)', (string)$scatter); self::assertSame('red: 1 divergent transition', $scatter->vegaLite()['title']['subtitle']);
        self::assertStringContainsString('x[1] |', (string)mcmc_intervals($fit, 'x'));
        $this->expectExceptionMessage('Plot one variable element, such as x[1].'); mcmc_trace($fit, 'x');
    }
    public function testInvalidRequestsThrow(): void
    {
        $d = self::draws();
        foreach ([fn() => mcmc_trace($d, 'nope'), fn() => mcmc_trace($d, 'mu')->ascii(20), fn() => mcmc_hist($d, 'mu', 0), fn() => mcmc_hist($d, 'mu', 100)->ascii(), fn() => mcmc_intervals($d, prob: 0.95), fn() => mcmc_acf($d, 'mu', 500), fn() => mcmc_trace([[['v' => NAN]], [['v' => 1.0]]], 'v'), fn() => pareto_k_plot([]), fn() => mcmc_trace($d, 'mu')->vegaLite(10), fn() => mcmc_trace($d, 'mu')->vegaLite(maxRows: 0)] as $i => $call) {
            try { $call(); self::fail('Expected an exception for case '.$i.'.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
