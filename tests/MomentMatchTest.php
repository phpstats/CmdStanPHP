<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{DrawKernels, Loo, MomentMatch, Psis, Special};
/** Moment matching through the phit posterior-draw kernels on analytic regression posteriors (no CmdStan needed). */
final class MomentMatchTest extends TestCase
{
    private const SHIFT_ONLY = MomentMatchFixture::SHIFT_ONLY;
    private const SHIFT_SCALE = MomentMatchFixture::SHIFT_SCALE;
    private const COV = MomentMatchFixture::COV;
    protected function setUp(): void
    {
        parent::setUp();
        if (!DrawKernels::available()) { self::markTestSkipped('The phit posterior-draw kernels are required: '.implode(', ', DrawKernels::missing())); }
    }
    private static function fixture(array $design): array { return MomentMatchFixture::regression(...$design); }
    private static function match(array $f, mixed ...$options): array
    {
        return MomentMatch::loo(Loo::loo($f['log_lik']), $f['upars'], $f['log_lik'], MomentMatchFixture::logProb($f), MomentMatchFixture::logLikI($f), ...$options);
    }
    private static function only(array $mm): array { self::assertCount(1, $mm['moment_match']['observations']); return $mm['moment_match']['observations'][0]; }
    private static function assertMatrix(array $expected, array $actual, float $tolerance, string $message = ''): void
    {
        self::assertCount(count($expected), $actual, $message);
        foreach ($expected as $i => $row) { foreach ($row as $j => $v) { self::assertEqualsWithDelta($v, $actual[$i][$j], $tolerance * max(1.0, abs($v)), $message."[$i][$j]"); } }
    }
    private static function code(callable $call): int
    {
        try { $call(); } catch (\Exception $e) { return $e->getCode(); }
        self::fail('Expected a phit exception.');
    }
    public function testKernelDetectionIsByFunction(): void
    {
        self::assertTrue(DrawKernels::available()); self::assertSame([], DrawKernels::missing());
        self::assertSame(['Phit\draws_not_a_kernel'], DrawKernels::missing(['Phit\draws_moments', 'Phit\draws_not_a_kernel']));
    }
    public function testWeightedAndUnweightedMomentsFollowLoo(): void
    {
        $x = [[-3.0, -5.0], [0.0, 1.0], [2.0, 3.0], [5.0, 8.0], [1.0, -2.0], [4.0, 0.5]]; $raw = [1.0, 0.5, 2.0, 4.0, 3.0, 0.25];
        $total = array_sum($raw); $w = array_map(fn($v) => $v / $total, $raw); $lwi = array_map('log', $w); $s = count($x);
        $mean = [array_sum(array_column($x, 0)) / $s, array_sum(array_column($x, 1)) / $s];
        $wmean = [0.0, 0.0]; foreach ($x as $i => $row) { foreach ($row as $j => $v) { $wmean[$j] += $w[$i] * $v; } }
        $cov = [[0.0, 0.0], [0.0, 0.0]]; $wpop = [[0.0, 0.0], [0.0, 0.0]];
        foreach ($x as $i => $row) { for ($a = 0; $a < 2; ++$a) { for ($b = 0; $b < 2; ++$b) { $cov[$a][$b] += ($row[$a] - $mean[$a]) * ($row[$b] - $mean[$b]) / ($s - 1); $wpop[$a][$b] += $w[$i] * ($row[$a] - $wmean[$a]) * ($row[$b] - $wmean[$b]); } } }
        $reliability = 1 - array_sum(array_map(fn($v) => $v * $v, $w));
        // shift(): colSums(exp(lwi) * upars) - colMeans(upars), applied to every row.
        $shift = MomentMatch::transform('shift', $x, $lwi);
        self::assertEqualsWithDelta([$wmean[0] - $mean[0], $wmean[1] - $mean[1]], $shift['shift'], 1e-12); self::assertSame(0.0, $shift['log_abs_det']);
        self::assertMatrix(array_map(fn($row) => [$row[0] + $shift['shift'][0], $row[1] + $shift['shift'][1]], $x), $shift['upars'], 1e-12);
        // shift_and_scale(): weighted population variance times S / (S - 1) over the unbiased variance.
        $scale = MomentMatch::transform('scale', $x, $lwi);
        $scaling = [sqrt($wpop[0][0] * $s / ($s - 1) / $cov[0][0]), sqrt($wpop[1][1] * $s / ($s - 1) / $cov[1][1])];
        self::assertEqualsWithDelta($scaling, $scale['scaling'], 1e-12); self::assertEqualsWithDelta(log($scaling[0]) + log($scaling[1]), $scale['log_abs_det'], 1e-12);
        self::assertEqualsWithDelta($wmean, DrawKernels::moments($scale['upars'])['mean'], 1e-12);
        // shift_and_cov(): stats::cov() to stats::cov.wt(), i.e. unbiased with reliability weights (1 - sum w^2), not frequency counts.
        $map = MomentMatch::transform('cov', $x, $lwi);
        self::assertMatrix($cov, $map['source'], 1e-12, 'unweighted');
        self::assertMatrix(array_map(fn($row) => array_map(fn($v) => $v / $reliability, $row), $wpop), $map['target'], 1e-12, 'weighted');
        self::assertGreaterThan(0.1, abs($map['target'][0][0] - $wpop[0][0]), 'Reliability normalization differs from population moments.');
        // Weights are normalized by the kernels, so unnormalized log weights give the same transformation.
        self::assertMatrix($map['upars'], MomentMatch::transform('cov', $x, array_map(fn($v) => $v + 7.5, $lwi))['upars'], 1e-12, 'rescaled weights');
        $uniform = MomentMatch::transform('cov', $x, array_fill(0, $s, -log($s)));
        self::assertMatrix($uniform['source'], $uniform['target'], 1e-12, 'uniform weights'); self::assertMatrix(DrawKernels::identity(2), $uniform['mapping'], 1e-12);
    }
    public function testCovarianceTransportMatchesWeightedMoments(): void
    {
        $f = self::fixture(self::COV); $u = array_slice($f['upars'], 0, 800);
        $lwi = Psis::psis(array_map(fn($t) => -MomentMatchFixture::pointwise($t, $f['x'][6], $f['y'][6]), $u))['log_weights'];
        $weighted = DrawKernels::moments($u, array_map('exp', $lwi), 'unbiased');
        $t = MomentMatch::transform('cov', $u, $lwi);
        $moved = DrawKernels::moments($t['upars'], null, 'unbiased');
        self::assertEqualsWithDelta($weighted['mean'], $moved['mean'], 1e-10); self::assertMatrix($weighted['covariance'], $moved['covariance'], 1e-10, 'transported covariance');
        $a = $t['mapping']; $at = array_map(null, ...$a);
        self::assertMatrix($t['target'], DrawKernels::multiply(DrawKernels::multiply($a, $t['source']), $at), 1e-12, 'A C A^T');
        self::assertEqualsWithDelta(DrawKernels::covarianceMap($t['source'], $t['target'])['log_abs_det'], $t['log_abs_det'], 1e-12);
    }
    public function testParameterOrderIsPreservedAndRowsStayAligned(): void
    {
        $f = self::fixture(self::SHIFT_SCALE); $u = array_slice($f['upars'], 0, 500); $swap = fn(array $rows) => array_map(fn($r) => [$r[1], $r[0]], $rows);
        $lwi = Psis::psis(array_map(fn($t) => -MomentMatchFixture::pointwise($t, $f['x'][6], $f['y'][6]), $u))['log_weights'];
        foreach (['shift', 'scale'] as $kind) {
            $t = MomentMatch::transform($kind, $u, $lwi); $swapped = MomentMatch::transform($kind, $swap($u), $lwi);
            self::assertMatrix($swap($t['upars']), $swapped['upars'], 1e-12, $kind.' is equivariant under parameter permutation');
        }
        // The Cholesky-based covariance map depends on parameter order, but it transports the same moments either way.
        $cov = MomentMatch::transform('cov', $u, $lwi); $covSwapped = MomentMatch::transform('cov', $swap($u), $lwi);
        self::assertMatrix(DrawKernels::moments($swap($cov['upars']), null, 'unbiased')['covariance'], DrawKernels::moments($covSwapped['upars'], null, 'unbiased')['covariance'], 1e-10, 'transport');
        self::assertGreaterThan(1e-6, abs($swap($cov['upars'])[0][0] - $covSwapped['upars'][0][0]), 'a different but valid map');
        // Whole-run equivariance without the covariance step: swapped columns with swapped callbacks give the same result.
        $loo = Loo::loo($f['log_lik']);
        $plain = MomentMatch::loo($loo, $f['upars'], $f['log_lik'], MomentMatchFixture::logProb($f), MomentMatchFixture::logLikI($f), cov: false);
        $swappedRun = MomentMatch::loo($loo, $swap($f['upars']), $f['log_lik'], fn($rows) => MomentMatchFixture::logProb($f)($swap($rows)), fn($rows, $i) => MomentMatchFixture::logLikI($f)($swap($rows), $i), cov: false);
        self::assertSame(self::only($plain)['transforms'], self::only($swappedRun)['transforms']);
        self::assertEqualsWithDelta($plain['elpd_loo'], $swappedRun['elpd_loo'], 1e-9);
        // Callbacks see transformed rows in the original draw order; the split keeps the second half untransformed.
        $calls = [];
        $logLik = function (array $rows, int $i) use ($f, &$calls) { $calls[] = $rows; return MomentMatchFixture::logLikI($f)($rows, $i); };
        $run = MomentMatch::loo($loo, $f['upars'], $f['log_lik'], MomentMatchFixture::logProb($f), $logLik);
        $first = $calls[0]; $shift = [$first[0][0] - $f['upars'][0][0], $first[0][1] - $f['upars'][0][1]];
        foreach ([1, 1999, 3999] as $s) { self::assertEqualsWithDelta([$f['upars'][$s][0] + $shift[0], $f['upars'][$s][1] + $shift[1]], $first[$s], 1e-12, 'row '.$s); }
        $split = end($calls); self::assertTrue(self::only($run)['split']); self::assertCount(4000, $split);
        self::assertSame(array_slice($f['upars'], 2000), array_slice($split, 2000)); self::assertNotEquals(array_slice($f['upars'], 0, 2000), array_slice($split, 0, 2000));
        foreach ([[], ['a' => [1.0, 2.0]], array_fill(0, 4000, ['a' => 1.0, 'b' => 2.0])] as $bad) {
            try { MomentMatch::loo($loo, $bad, $f['log_lik'], MomentMatchFixture::logProb($f), MomentMatchFixture::logLikI($f)); self::fail('Rows must be ordered lists.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testSplitTransformRoundTripsExactly(): void
    {
        $f = self::fixture(self::COV); $u = array_slice($f['upars'], 0, 400); $mean = DrawKernels::moments($u)['mean'];
        $c1 = [[2.0, 0.5], [0.5, 1.0]]; $c2 = [[1.0, -0.3], [-0.3, 3.0]]; $c3 = [[0.5, 0.1], [0.1, 0.2]];
        $maps = [['source' => $c1, 'target' => $c2, 'mapping' => DrawKernels::covarianceMap($c1, $c2)['matrix']], ['source' => $c2, 'target' => $c3, 'mapping' => DrawKernels::covarianceMap($c2, $c3)['matrix']]];
        $shift = [0.3, -0.2]; $scaling = [1.5, 0.7];
        $t = MomentMatch::splitTransform($u, $shift, $scaling, $maps);
        self::assertMatrix(DrawKernels::identity(2), DrawKernels::multiply($t['inverse_matrix'], $t['matrix']), 1e-12, 'A^-1 A');
        $to = [$mean[0] + $shift[0], $mean[1] + $shift[1]];
        self::assertMatrix($u, DrawKernels::affineAbout($t['forward']['draws'], $t['inverse_matrix'], $to, $mean)['draws'], 1e-12, 'T^-1(T(x))');
        self::assertMatrix($u, DrawKernels::affineAbout($t['inverse']['draws'], $t['matrix'], $mean, $to)['draws'], 1e-12, 'T(T^-1(x))');
        $expected = log(1.5) + log(0.7) + DrawKernels::covarianceMap($c1, $c2)['log_abs_det'] + DrawKernels::covarianceMap($c2, $c3)['log_abs_det'];
        self::assertEqualsWithDelta($expected, $t['forward']['log_abs_det'], 1e-12); self::assertEqualsWithDelta(0.0, $t['forward']['log_abs_det'] + $t['inverse']['log_abs_det'], 1e-12);
        // T(x) = M diag(c) (x - m) + m + t with M = M2 M1.
        $m = DrawKernels::multiply($maps[1]['mapping'], $maps[0]['mapping']); $d = [$u[5][0] - $mean[0], $u[5][1] - $mean[1]];
        self::assertEqualsWithDelta(array_map(fn($row, $k) => $row[0] * 1.5 * $d[0] + $row[1] * 0.7 * $d[1] + $to[$k], $m, [0, 1]), $t['forward']['draws'][5], 1e-12);
        // loo's split "inverse" A^-1 (y - m) + m - t is not an inverse once a shift is combined with a linear map.
        $looInverse = DrawKernels::affineAbout($t['forward']['draws'], $t['inverse_matrix'], $mean, [$mean[0] - $shift[0], $mean[1] - $shift[1]])['draws'];
        self::assertGreaterThan(0.1, abs($looInverse[0][0] - $u[0][0]) + abs($looInverse[0][1] - $u[0][1]));
    }
    public function testSplitPreservesCovarianceThenScalingOrder(): void
    {
        $draws = [[-2.0, 0.5], [1.0, -1.0], [3.0, 2.0], [0.0, 4.0]];
        $source = DrawKernels::moments($draws, null, 'unbiased')['covariance'];
        $target = [[2.0, 0.8], [0.8, 1.0]];
        $map = ['source' => $source, 'target' => $target, 'mapping' => DrawKernels::covarianceMap($source, $target)['matrix']];
        $scaling = [1.7, 0.6]; $mean = DrawKernels::moments($draws)['mean']; $shift1 = [0.3, -0.2]; $shift2 = [-0.1, 0.4];
        $mid = array_map(fn($m, $b) => $m + $b, $mean, $shift1);
        $end = array_map(fn($m, $b) => $m + $b, $mid, $shift2);
        $covariance = DrawKernels::affineAbout($draws, $map['mapping'], $mean, $mid);
        $sequential = DrawKernels::affineAbout($covariance['draws'], DrawKernels::diagonal($scaling), $mid, $end);
        $combined = MomentMatch::splitTransform($draws, [0.2, 0.2], $scaling, [$map], [$map, ['scaling' => $scaling]]);
        self::assertMatrix($sequential['draws'], $combined['forward']['draws'], 1e-12);
        self::assertMatrix($draws, DrawKernels::affineAbout($combined['forward']['draws'], $combined['inverse_matrix'], $end, $mean)['draws'], 1e-12);
        self::assertEqualsWithDelta($covariance['log_abs_det'] + $sequential['log_abs_det'], $combined['forward']['log_abs_det'], 1e-12);
        self::assertEqualsWithDelta(0.0, $combined['forward']['log_abs_det'] + $combined['inverse']['log_abs_det'], 1e-12);
        $grouped = MomentMatch::splitTransform($draws, [0.2, 0.2], $scaling, [$map]);
        self::assertGreaterThan(0.1, abs($grouped['forward']['draws'][0][0] - $sequential['draws'][0][0]) + abs($grouped['forward']['draws'][0][1] - $sequential['draws'][0][1]));
    }
    public function testAffineLogDeterminantEntersTheSplitProposal(): void
    {
        // Split importance sampling with the analytic leave-one-out map T from the full to the LOO posterior.
        $f = self::fixture(self::COV); $i = 6; $u = $f['upars']; $s = count($u); $half = intdiv($s, 2);
        [, $v] = MomentMatchFixture::posterior($f['x'], $f['y']);
        [$looMean, $looV] = MomentMatchFixture::posterior(array_slice($f['x'], 0, $i), array_slice($f['y'], 0, $i));
        $map = DrawKernels::covarianceMap($v, $looV); $mean = DrawKernels::moments($u)['mean'];
        $t = MomentMatch::splitTransform($u, [$looMean[0] - $mean[0], $looMean[1] - $mean[1]], [1.0, 1.0], [['source' => $v, 'target' => $looV, 'mapping' => $map['matrix']]]);
        self::assertEqualsWithDelta($map['log_abs_det'], $t['forward']['log_abs_det'], 1e-12); self::assertGreaterThan(0.5, $t['forward']['log_abs_det']);
        $z = array_merge(array_slice($t['forward']['draws'], 0, $half), array_slice($u, $half)); $inverse = array_merge(array_slice($u, 0, $half), array_slice($t['inverse']['draws'], $half));
        $logProb = MomentMatchFixture::logProb($f); $ll = MomentMatchFixture::logLikI($f)($z, $i);
        $elpd = function (float $logAbsDet, ?array $inversePoints = null) use ($logProb, $z, $inverse, $ll) {
            $lw = MomentMatch::splitLogRatios($logProb($z), $logProb($inversePoints ?? $inverse), $ll, $logAbsDet);
            return Special::logSumExp(array_map(fn($a, $b) => $a + $b, $lw, $ll)) - Special::logSumExp($lw);
        };
        $exact = MomentMatchFixture::exactElpd($f, $i);
        self::assertEqualsWithDelta($exact, $elpd($t['forward']['log_abs_det']), 0.01, 'with log|det T|');
        self::assertGreaterThan(0.5, abs($exact - $elpd(0.0)), 'omitting log|det T| biases the mixture proposal');
        // loo's split "inverse" A^-1 (y - m) + m - t evaluates the second half's mixture density at the wrong points.
        $looInverse = DrawKernels::affineAbout($u, $t['inverse_matrix'], $mean, [2 * $mean[0] - $looMean[0], 2 * $mean[1] - $looMean[1]])['draws'];
        $wrong = $elpd($t['forward']['log_abs_det'], array_merge(array_slice($u, 0, $half), array_slice($looInverse, $half)));
        self::assertGreaterThan(0.02, abs($exact - $wrong)); self::assertGreaterThan(5 * abs($exact - $elpd($t['forward']['log_abs_det'])), abs($exact - $wrong));
        // Within iterations the proposal term log p(x) - log|det T| is constant, so normalized PSIS weights do not change.
        $lr = array_map(fn($a, $b) => $a - $b, $logProb($z), $ll);
        self::assertEqualsWithDelta(Psis::psis($lr)['log_weights'], Psis::psis(array_map(fn($v) => $v + 1.75, $lr))['log_weights'], 1e-10);
    }
    public function testMomentMatchingRecoversExactLooDensities(): void
    {
        foreach (['shift only' => self::SHIFT_ONLY, 'shift and scale' => self::SHIFT_SCALE, 'covariance' => self::COV] as $label => $design) {
            $f = self::fixture($design); $loo = Loo::loo($f['log_lik']); $mm = self::match($f); $o = self::only($mm); $i = $o['index'];
            self::assertSame('log_lik['.($i + 1).']', $o['observation'], $label); self::assertSame(count($f['x']) - 1, $i, $label);
            self::assertGreaterThan($loo['k_threshold'], $o['pareto_k_initial'], $label); self::assertLessThanOrEqual($mm['moment_match']['k_threshold'], $o['pareto_k'], $label);
            self::assertSame('k_below_threshold', $o['stop'], $label); self::assertTrue($o['split'], $label); self::assertNull($o['message'], $label);
            self::assertSame($o['pareto_k'], $mm['diagnostics']['pareto_k'][$i]); self::assertSame($o['r_eff'], $mm['diagnostics']['r_eff'][$i]);
            $exact = MomentMatchFixture::exactElpd($f, $i);
            self::assertLessThan(abs($loo['pointwise'][$i]['elpd_loo'] - $exact) / 10, abs($mm['pointwise'][$i]['elpd_loo'] - $exact), $label.': moment matching improves elpd');
            self::assertEqualsWithDelta($exact, $mm['pointwise'][$i]['elpd_loo'], 0.2, $label);
            foreach ($loo['pointwise'] as $j => $row) { if ($j !== $i) { self::assertSame($row, $mm['pointwise'][$j]); } }
            self::assertSame(array_keys($loo['pointwise'][$i]), array_keys($mm['pointwise'][$i]));
            self::assertEqualsWithDelta(array_sum(array_column($mm['pointwise'], 'elpd_loo')), $mm['elpd_loo'], 1e-9); self::assertSame($mm['estimates']['looic']['SE'], $mm['se_looic']);
            self::assertSame([], $mm['warnings'], $label);
        }
        self::assertSame(['shift', 'shift', 'shift'], self::only(self::match(self::fixture(self::SHIFT_ONLY)))['transforms']);
        self::assertSame(0.0, self::only(self::match(self::fixture(self::SHIFT_ONLY)))['log_abs_det']);
        $cov = self::only(self::match(self::fixture(self::COV))); self::assertContains('cov', $cov['transforms']); self::assertContains('scale', $cov['transforms']);
        $unsplit = self::match(self::fixture(self::SHIFT_SCALE), split: false); self::assertFalse(self::only($unsplit)['split']);
        self::assertContains(MomentMatch::KF_WARNING, $unsplit['warnings']);
        $capped = self::match(self::fixture(self::SHIFT_SCALE), max_iters: 2); self::assertSame('max_iters', self::only($capped)['stop']); self::assertCount(2, self::only($capped)['transforms']);
        self::assertContains(MomentMatch::MAX_ITERS_WARNING, $capped['warnings']);
    }
    public function testSingularWeightedCovarianceFallsBackWithoutRegularization(): void
    {
        $x = [[1.0, 2.0, 1.0], [2.0, 1.0, 2.0], [3.0, 5.0, 3.0], [4.0, 3.0, 4.0], [0.0, 1.0, 0.0]]; $lwi = array_map('log', [0.1, 0.2, 0.3, 0.25, 0.15]);
        $fallback = MomentMatch::transform('cov', $x, $lwi); self::assertSame(DrawKernels::identity(3), $fallback['mapping']); self::assertArrayHasKey('fallback', $fallback);
        $one = MomentMatch::transform('cov', array_map(fn($r) => [$r[0], $r[1]], $x), [0.0, -INF, -INF, -INF, -INF]); self::assertSame(DrawKernels::identity(2), $one['mapping']); self::assertSame(0.0, $one['log_abs_det']); self::assertArrayHasKey('fallback', $one);
        self::assertSame(DrawKernels::NONFINITE, self::code(fn() => MomentMatch::transform('scale', array_map(fn($r) => [$r[0], 7.0], $x), $lwi)), 'constant parameter');
        self::assertCount(3, DrawKernels::moments($x, null, 'unbiased')['covariance'], 'the singular moments themselves are a valid result');
        // A duplicated parameter column leaves the density unchanged but makes weighted covariance singular:
        // the identity fallback repeats the rejected shift, keeping the accepted scaling without jitter.
        $f = self::fixture(self::COV); $dup = $f; $dup['upars'] = array_map(fn($r) => [$r[0], $r[1], $r[0]], $f['upars']);
        $mm = self::match($dup); $o = self::only($mm);
        self::assertSame('no_improvement', $o['stop']); self::assertSame(['scale'], $o['transforms']); self::assertNull($o['message']); self::assertNotEmpty($o['covariance_fallbacks']);
        self::assertGreaterThan($mm['moment_match']['k_threshold'], $o['pareto_k']); self::assertLessThan($o['pareto_k_initial'], $o['pareto_k']);
        self::assertNotContains(sprintf(MomentMatch::FAILURE_WARNING, 1), $mm['warnings']); self::assertStringContainsString('Pareto k diagnostic values are too high', end($mm['warnings']));
        // A model evaluation failure stops before any transformation, leaving the PSIS result.
        $f = self::fixture(self::SHIFT_ONLY); $loo = Loo::loo($f['log_lik']); $calls = 0;
        $failing = function (array $rows) use ($f, &$calls) { if (++$calls > 1) { throw new \RuntimeException('Rejecting initial value'); } return MomentMatchFixture::logProb($f)($rows); };
        $mm = MomentMatch::loo($loo, $f['upars'], $f['log_lik'], $failing, MomentMatchFixture::logLikI($f)); $o = self::only($mm); $i = $o['index'];
        self::assertSame('model_error', $o['stop']); self::assertSame('shift: Rejecting initial value', $o['message']); self::assertSame([], $o['transforms']);
        foreach (['elpd_loo', 'mcse_elpd_loo', 'p_loo'] as $key) { self::assertEqualsWithDelta($loo['pointwise'][$i][$key], $mm['pointwise'][$i][$key], 1e-12, $key); }
        self::assertEqualsWithDelta($loo['diagnostics']['n_eff'][$i], $mm['diagnostics']['n_eff'][$i], 1e-9);
        // Nonfinite log ratios on transformed draws (log-likelihood +Inf) are a recorded stop, not an exception.
        $mm = MomentMatch::loo($loo, $f['upars'], $f['log_lik'], MomentMatchFixture::logProb($f), fn($rows, $i) => array_fill(0, count($rows), -INF));
        self::assertSame('invalid_log_ratios', self::only($mm)['stop']);
    }
    public function testInvalidInputsAreRejected(): void
    {
        $f = self::fixture(self::SHIFT_ONLY); $loo = Loo::loo($f['log_lik']); $lp = MomentMatchFixture::logProb($f); $ll = MomentMatchFixture::logLikI($f);
        $nan = $f['upars']; $nan[10][1] = NAN; $ragged = $f['upars']; $ragged[3] = [1.0]; $strings = $f['upars']; $strings[0][0] = '1.0';
        $cases = [
            'too few rows' => fn() => MomentMatch::loo($loo, array_slice($f['upars'], 1), $f['log_lik'], $lp, $ll),
            'NAN draw' => fn() => MomentMatch::loo($loo, $nan, $f['log_lik'], $lp, $ll),
            'ragged' => fn() => MomentMatch::loo($loo, $ragged, $f['log_lik'], $lp, $ll),
            'string value' => fn() => MomentMatch::loo($loo, $strings, $f['log_lik'], $lp, $ll),
            'loo mismatch' => fn() => MomentMatch::loo(Loo::loo(array_slice($f['log_lik'], 1)), $f['upars'], $f['log_lik'], $lp, $ll),
            'log_prob length' => fn() => MomentMatch::loo($loo, $f['upars'], $f['log_lik'], fn($rows) => array_slice($lp($rows), 1), $ll),
            'nonfinite posterior density' => fn() => MomentMatch::loo($loo, $f['upars'], $f['log_lik'], fn($rows) => array_fill(0, count($rows), -INF), $ll),
            'log_lik length' => fn() => MomentMatch::loo($loo, $f['upars'], $f['log_lik'], $lp, fn($rows, $i) => [0.0]),
            'NAN threshold' => fn() => MomentMatch::loo($loo, $f['upars'], $f['log_lik'], $lp, $ll, k_threshold: NAN),
            'max_iters' => fn() => MomentMatch::loo($loo, $f['upars'], $f['log_lik'], $lp, $ll, max_iters: 0),
            'unknown transform' => fn() => MomentMatch::transform('rotate', $f['upars'], array_fill(0, 4000, 0.0)),
        ];
        foreach ($cases as $label => $call) {
            try { $call(); self::fail($label.' should be rejected.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
