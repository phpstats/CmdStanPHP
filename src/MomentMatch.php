<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Importance-weighted moment matching for PSIS-LOO, ported from loo::loo_moment_match.default (Paananen et al., 2021) as
 * CmdStanMCMC$loo(moment_match = TRUE) calls it. Mean, covariance, covariance-map and affine computations use the
 * phit posterior-draw kernels (see DrawKernels); this class owns model evaluation, PSIS, iteration and diagnostics.
 *
 * Coordinates: $upars are unconstrained draws [draw][parameter] in one fixed parameter order, with rows in the same
 * chain-major order as the log-likelihood draws. $logProbUpars(list<list<float>> $upars): list<float> must return the
 * log density of the posterior these draws came from (for Stan, log_prob with jacobian: true, so the constraining
 * Jacobian is included); $logLikIUpars(list<list<float>> $upars, int $i): list<float> returns observation $i's
 * log-likelihood (zero-based $i). Both return one value per row, in row order.
 *
 * Proposal densities: a transformed draw z = T(x) has density p(x) / |det T|, so every importance ratio subtracts
 * log p(x) - log|det T| using the kernels' log_abs_det. Within the iterations this is a constant that self-normalized
 * PSIS cancels, but the split proposal mixes T and its inverse, where the log determinant matters.
 *
 * Deliberate differences from loo 2.10: the split proposal uses the exact inverse T^-1(y) = A^-1 (y - m - t) + m, where
 * loo uses A^-1 (y - m) + m - t, which is not the inverse once a shift is combined with scaling or a covariance map; the
 * split r_eff is the minimum over both halves, where loo uses the second half twice. Relative efficiencies are computed
 * from exp(log_lik - max) because ESS is scale invariant; loo's unshifted exp(log_lik) can make posterior report NA when
 * every value is tiny. Scale and covariance steps are composed in acceptance order for the split proposal. Otherwise,
 * without split or when only shifts are accepted, results follow loo.
 *
 * Failure policy: covariance matrices are never regularized. A transformation that cannot be built (phit 1003
 * nonfinite, 1004 singular affine map, 1005 covariance not numerically positive definite, or fewer than two effective
 * draws for a required covariance), a model evaluation that throws, or importance ratios that PSIS rejects end that
 * observation's iterations and keep its last accepted state, recorded under moment_match.observations[*].stop. This is
 * handled as a controlled failure for that observation. A failed weighted covariance uses loo's identity-map
 * fallback, retaining the mean shift and recording its reason in covariance_fallbacks. A failing split falls back to
 * the unsplit result.
 */
final class MomentMatch
{
    public const MAX_ITERS_WARNING = "The maximum number of moment matching iterations ('maxIters' argument) was reached. Increasing the value may improve accuracy.";
    public const KF_WARNING = "The accuracy of self-normalized importance sampling may be bad. Setting the argument 'split' to true will likely improve accuracy.";
    public const FAILURE_WARNING = 'Moment matching stopped early after a numerical or model failure for %d observation(s); see moment_match.observations.';
    private const PARETO_WARNING = "Some Pareto k diagnostic values are too high. See help('pareto-k-diagnostic') for details.";
    /**
     * @param array $loo result of Loo::loo() for $logLik
     * @param array $logLik the draws_array or draws_matrix given to Loo::loo()
     * @return array $loo with updated pointwise rows, diagnostics, estimates and warnings, plus moment_match: {k_threshold,
     *   split, cov, observations: list<{index, observation, pareto_k_initial, pareto_k, pareto_kf, r_eff, transforms,
     *   log_abs_det, split, stop, message, covariance_fallbacks}>}
     */
    public static function loo(array $loo, array $upars, array $logLik, callable $logProbUpars, callable $logLikIUpars, int $maxIters = 30, ?float $kThreshold = null, bool $split = true, bool $cov = true): array
    {
        DrawKernels::require();
        [$columns, $chains, $names] = Loo::columns($logLik);
        $s = count($columns[0]); $n = count($columns);
        if (($loo['dims'] ?? null) !== [$s, $n] || count($loo['pointwise'] ?? []) !== $n || count($loo['diagnostics']['pareto_k'] ?? []) !== $n) { throw new \InvalidArgumentException('The loo result does not match the log-likelihood draws.'); }
        if ($kThreshold !== null && !is_finite($kThreshold)) { throw new \InvalidArgumentException('kThreshold must be finite.'); }
        if ($maxIters < 1) { throw new \InvalidArgumentException('maxIters must be positive.'); }
        $upars = self::draws($upars, $s);
        $p = count($upars[0]);
        $kThreshold ??= min(1 - fdiv(1, log10($s)), 0.7);
        $cov = $cov && $s >= 10 * $p;
        $origLp = self::values($logProbUpars($upars), $s, 'logProbUpars');
        foreach ($origLp as $v) { if (!is_finite($v)) { throw new \InvalidArgumentException('logProbUpars must be finite for every posterior draw.'); } }
        $model = ['log_prob' => $logProbUpars, 'log_lik' => $logLikIUpars, 'orig_lp' => $origLp];
        $observations = []; $maxReached = false; $failures = 0;
        foreach ($loo['diagnostics']['pareto_k'] as $i => $k) {
            if (!($k > $kThreshold)) { continue; }
            $r = self::observation($i, $upars, $columns[$i], $chains, $model, (float)$k, $maxIters, $kThreshold, $split, $cov);
            $loo['pointwise'][$i] = ['elpd_loo' => $r['elpd_loo'], 'mcse_elpd_loo' => $r['mcse_elpd_loo'], 'p_loo' => $r['p_loo'], 'looic' => $r['looic']] + $loo['pointwise'][$i];
            $loo['diagnostics']['pareto_k'][$i] = $r['record']['pareto_k']; $loo['diagnostics']['n_eff'][$i] = $r['n_eff'];
            $loo['diagnostics']['r_eff'][$i] = $r['record']['r_eff'];
            $maxReached = $maxReached || $r['max_iters_reached'];
            if ($r['record']['message'] !== null) { ++$failures; }
            $observations[] = ['index' => $i, 'observation' => $names[$i]] + $r['record'];
        }
        $loo['estimates'] = Loo::estimates($loo['pointwise'], ['elpd_loo', 'p_loo', 'looic']);
        foreach (['elpd_loo', 'p_loo', 'looic'] as $name) { $loo[$name] = $loo['estimates'][$name]['Estimate']; $loo['se_'.$name] = $loo['estimates'][$name]['SE']; }
        $warnings = array_values(array_filter($loo['warnings'] ?? [], fn($w) => $w !== self::PARETO_WARNING));
        if ($maxReached) { $warnings[] = self::MAX_ITERS_WARNING; }
        if ($failures > 0) { $warnings[] = sprintf(self::FAILURE_WARNING, $failures); }
        if (!$split && count(array_filter($observations, fn($o) => $o['pareto_kf'] > $kThreshold)) > 0) { $warnings[] = self::KF_WARNING; }
        if (count(array_filter($loo['diagnostics']['pareto_k'], fn($k) => $k > $kThreshold)) > 0) { $warnings[] = self::PARETO_WARNING; }
        $loo['warnings'] = $warnings;
        $loo['moment_match'] = ['k_threshold' => $kThreshold, 'split' => $split, 'cov' => $cov, 'observations' => $observations];
        return $loo;
    }
    /** CmdStanMCMC$loo(moment_match = TRUE): PSIS-LOO, then moment matching with the fit's model methods. */
    public static function fromFit(CmdStanFit $fit, string $variables = 'log_lik', array|float|bool|null $rEff = true, int $maxIters = 30, ?float $kThreshold = null, bool $split = true, bool $cov = true): array
    {
        DrawKernels::require();
        $logLik = $fit->draws($variables, 'draws_array');
        $loo = Loo::loo($logLik, $rEff);
        $fit->initModelMethods();
        $upars = array_map('array_values', $fit->unconstrainDraws(format: 'draws_matrix'));
        $paths = array_map(fn($name) => self::path((string)$name, $variables), $loo['observations']);
        $logProb = fn(array $rows) => array_map(fn($row) => $fit->logProb($row), $rows);
        $logLikI = function (array $rows, int $i) use ($fit, $variables, $paths) {
            return array_map(function ($row) use ($fit, $variables, $paths, $i) {
                $value = $fit->constrainVariables($row)[$variables];
                foreach ($paths[$i] as $index) { $value = $value[$index]; }
                return $value;
            }, $rows);
        };
        return self::loo($loo, $upars, $logLik, $logProb, $logLikI, $maxIters, $kThreshold, $split, $cov);
    }
    /**
     * One shift, scale or covariance step of loo:::shift(), shift_and_scale() and shift_and_cov() on the current draws.
     * @return array{upars: list<list<float>>, shift: list<float>, scaling?: list<float>, mapping?: list<list<float>>, source?: list<list<float>>, target?: list<list<float>>, log_abs_det: float}
     */
    public static function transform(string $kind, array $upars, array $lwi): array
    {
        $weights = array_map('exp', $lwi); $p = count($upars[0]);
        $original = DrawKernels::moments($upars, null, $kind === 'shift' ? 'population' : 'unbiased');
        $weighted = DrawKernels::moments($upars, $weights);
        $shift = array_map(fn($w, $o) => $w - $o, $weighted['mean'], $original['mean']);
        if ($kind === 'shift') { $t = DrawKernels::affine($upars, DrawKernels::identity($p), $shift); return ['upars' => $t['draws'], 'shift' => $shift, 'log_abs_det' => $t['log_abs_det']]; }
        if ($kind === 'scale') {
            // loo scales the weighted variance by S / (S - 1) and divides by the unbiased variance of the draws.
            $s = count($upars); $scaling = [];
            for ($j = 0; $j < $p; ++$j) { $scaling[] = sqrt(fdiv($weighted['covariance'][$j][$j] * $s / ($s - 1), $original['covariance'][$j][$j])); }
            $t = DrawKernels::affineAbout($upars, DrawKernels::diagonal($scaling), $original['mean'], $weighted['mean']);
            return ['upars' => $t['draws'], 'shift' => $shift, 'scaling' => $scaling, 'log_abs_det' => $t['log_abs_det']];
        }
        if ($kind !== 'cov') { throw new \InvalidArgumentException('Unknown moment matching transformation: '.$kind); }
        // stats::cov() and stats::cov.wt(): unbiased, with reliability weights for the weighted covariance.
        try { $target = DrawKernels::moments($upars, $weights, 'unbiased')['covariance']; }
        catch (\Exception $e) {
            if ($e->getCode() !== DrawKernels::INVALID) { throw $e; }
            return self::covarianceFallback($upars, $shift, 'Weighted covariance is undefined: '.$e->getMessage());
        }
        // loo checks the weighted covariance first and retains only the mean shift when its factorization fails.
        try { DrawKernels::covarianceMap($target, $target); }
        catch (\Exception $e) {
            if ($e->getCode() !== DrawKernels::SINGULAR_COVARIANCE) { throw $e; }
            return self::covarianceFallback($upars, $shift, $e->getMessage());
        }
        $map = DrawKernels::covarianceMap($original['covariance'], $target);
        $t = DrawKernels::affineAbout($upars, $map['matrix'], $original['mean'], $weighted['mean']);
        return ['upars' => $t['draws'], 'shift' => $shift, 'mapping' => $map['matrix'], 'source' => $original['covariance'], 'target' => $target, 'log_abs_det' => $t['log_abs_det']];
    }
    private static function covarianceFallback(array $upars, array $shift, string $reason): array
    {
        $identity = DrawKernels::identity(count($upars[0]));
        $t = DrawKernels::affine($upars, $identity, $shift);
        return ['upars' => $t['draws'], 'shift' => $shift, 'mapping' => $identity, 'source' => $identity, 'target' => $identity, 'log_abs_det' => $t['log_abs_det'], 'fallback' => $reason];
    }
    /**
     * The split proposal's affine map T(x) = A (x - m) + m + t and its exact inverse built from reverse
     * covariance maps (each L_s L_t^-1 inverts L_t L_s^-1), so no matrix is inverted. An explicit step history preserves
     * noncommuting scale and covariance operations; without one, the provided maps follow the provided scaling.
     * @param list<array{source: list<list<float>>, target: list<list<float>>, mapping: list<list<float>>}> $maps accepted covariance steps in order
     * @param array|null $steps accepted scaling/covariance steps in their original order
     * @return array{forward: array{draws: list<list<float>>, log_abs_det: float}, inverse: array{draws: list<list<float>>, log_abs_det: float}, matrix: list<list<float>>, inverse_matrix: list<list<float>>}
     */
    public static function splitTransform(array $upars, array $shift, array $scaling, array $maps, ?array $steps = null): array
    {
        $p = count($upars[0]); $mean = DrawKernels::moments($upars)['mean'];
        // The optional history preserves order when a scaling step follows a non-diagonal covariance step.
        $steps ??= array_merge([['scaling' => $scaling]], $maps);
        $a = DrawKernels::identity($p); $aInv = DrawKernels::identity($p);
        foreach ($steps as $step) {
            if (isset($step['scaling'])) {
                $matrix = DrawKernels::diagonal($step['scaling']);
                $inverse = DrawKernels::diagonal(array_map(fn($v) => fdiv(1.0, $v), $step['scaling']));
            } else {
                $matrix = $step['mapping'];
                $inverse = DrawKernels::covarianceMap($step['target'], $step['source'])['matrix'];
            }
            $a = DrawKernels::multiply($matrix, $a);
            $aInv = DrawKernels::multiply($aInv, $inverse);
        }
        $to = array_map(fn($x, $t) => $x + $t, $mean, $shift);
        return ['forward' => DrawKernels::affineAbout($upars, $a, $mean, $to), 'inverse' => DrawKernels::affineAbout($upars, $aInv, $to, $mean), 'matrix' => $a, 'inverse_matrix' => $aInv];
    }
    /**
     * Split-proposal log importance ratios for p(z) / p(y_i | z) against the mixture of the posterior and T(posterior):
     * log p(z) - log p(y_i | z) - log(p(z) + p(T^-1 z) / |det T|), with NaN mapped to -Inf as in loo.
     * @return list<float>
     */
    public static function splitLogRatios(array $lpZ, array $lpInverse, array $llZ, float $logAbsDet): array
    {
        return array_map(function ($lp, $lpInv, $ll) use ($logAbsDet) { $v = $lp - $ll - self::logAddExp($lp, $lpInv - $logAbsDet); return is_nan($v) ? -INF : $v; }, $lpZ, $lpInverse, $llZ);
    }
    private static function observation(int $i, array $upars, array $ll, int $chains, array $model, float $k, int $maxIters, float $threshold, bool $split, bool $cov): array
    {
        $s = count($upars); $p = count($upars[0]);
        $rEff = self::relativeEff($ll, $chains);
        $lpd = Special::logSumExp($ll) - log($s);
        $state = ['upars' => $upars, 'll' => $ll, 'lwi' => Psis::psis(array_map(fn($v) => -$v, $ll), $rEff)['log_weights'], 'lwfi' => array_fill(0, $s, -log($s)), 'ki' => $k, 'kfi' => 0.0];
        $shift = array_fill(0, $p, 0.0); $scaling = array_fill(0, $p, 1.0); $maps = []; $steps = []; $fallbacks = []; $logDet = 0.0; $transforms = [];
        $stop = 'k_below_threshold'; $message = null; $maxReached = false; $iteration = 1;
        while ($iteration <= $maxIters && $state['ki'] > $threshold) {
            if ($iteration === $maxIters) { $maxReached = true; }
            foreach ($cov ? ['shift', 'scale', 'cov'] : ['shift', 'scale'] as $kind) {
                try { $t = self::transform($kind, $state['upars'], $state['lwi']); }
                catch (\Exception $e) {
                    if (!DrawKernels::isNumericalFailure($e)) { throw $e; }
                    [$stop, $message] = [$e->getCode() === DrawKernels::SINGULAR_COVARIANCE ? 'covariance_not_positive_definite' : ($e->getCode() === DrawKernels::SINGULAR_AFFINE ? 'singular_transform' : 'nonfinite_transform'), $kind.': '.$e->getMessage()];
                    break 2;
                }
                if (isset($t['fallback'])) { $fallbacks[] = $t['fallback']; }
                $q = self::quantities($t['upars'], $i, $model, $logDet + $t['log_abs_det'], $rEff);
                if (isset($q['stop'])) { [$stop, $message] = [$q['stop'], $kind.': '.$q['message']]; break 2; }
                if ($q['ki'] < $state['ki']) {
                    $state = ['upars' => $t['upars']] + $q + $state;
                    $shift = array_map(fn($a, $b) => $a + $b, $shift, $t['shift']);
                    if (isset($t['scaling'])) { $scaling = array_map(fn($a, $b) => $a * $b, $scaling, $t['scaling']); $steps[] = $t; }
                    if (isset($t['mapping'])) { $maps[] = $t; $steps[] = $t; }
                    $logDet += $t['log_abs_det']; $transforms[] = $kind; ++$iteration;
                    continue 2;
                }
            }
            $stop = 'no_improvement';
            break;
        }
        if ($stop === 'k_below_threshold' && $state['ki'] > $threshold) { $stop = 'max_iters'; }
        $splitApplied = false; $rEffFinal = null;
        if ($split && $transforms !== []) {
            $sp = self::split($upars, $i, $model, $shift, $scaling, $maps, $rEff, $steps);
            if (isset($sp['stop'])) { $message = 'split: '.$sp['message'].'; using the unsplit result.'; }
            else { $state = ['ll' => $sp['ll'], 'lwi' => $sp['lwi'], 'lwfi' => $sp['lwfi']] + $state; $rEffFinal = $sp['r_eff']; $splitApplied = true; }
        }
        $rEffFinal ??= self::relativeEff($state['ll'], $chains);
        $elpd = Special::logSumExp(array_map(fn($a, $b) => $a + $b, $state['ll'], $state['lwi']));
        $ess = fn(array $lw) => 1 / array_sum(array_map(fn($v) => exp(2 * $v), $lw));
        return ['elpd_loo' => $elpd, 'mcse_elpd_loo' => Loo::mcseElpd($state['ll'], $state['lwi'], $elpd, $rEffFinal), 'p_loo' => $lpd - $elpd, 'looic' => -2 * $elpd,
            'n_eff' => min($ess($state['lwi']), $ess($state['lwfi'])) * $rEffFinal, 'max_iters_reached' => $maxReached,
            'record' => ['pareto_k_initial' => $k, 'pareto_k' => $state['ki'], 'pareto_kf' => $state['kfi'], 'r_eff' => $rEffFinal, 'transforms' => $transforms, 'log_abs_det' => $logDet, 'split' => $splitApplied, 'stop' => $stop, 'message' => $message, 'covariance_fallbacks' => $fallbacks]];
    }
    /** loo:::update_quantities_i with the proposal density log p(x) - log|det T| of each transformed row. */
    private static function quantities(array $upars, int $i, array $model, float $logAbsDet, float $rEff): array
    {
        $s = count($upars);
        try { $lp = $model['log_prob']($upars); $ll = $model['log_lik']($upars, $i); }
        catch (\Exception $e) { return ['stop' => 'model_error', 'message' => $e->getMessage()]; }
        $lp = self::values($lp, $s, 'logProbUpars'); $ll = self::values($ll, $s, 'logLikIUpars');
        $lr = []; $lf = [];
        foreach ($lp as $j => $v) { $proposal = $model['orig_lp'][$j] - $logAbsDet; $r = $v - $ll[$j] - $proposal; $lr[] = is_nan($r) ? -INF : $r; $lf[] = $v - $proposal; }
        try { $is = Psis::psis($lr, $rEff); $isf = Psis::psis($lf, $rEff); }
        catch (\InvalidArgumentException $e) { return ['stop' => 'invalid_log_ratios', 'message' => $e->getMessage()]; }
        return ['lwi' => $is['log_weights'], 'lwfi' => $isf['log_weights'], 'ki' => $is['pareto_k'][0], 'kfi' => $isf['pareto_k'][0], 'll' => $ll];
    }
    /** loo:::loo_moment_match_split: the first half of draws moved by T, the second half kept; densities against their mixture. */
    private static function split(array $upars, int $i, array $model, array $shift, array $scaling, array $maps, float $rEff, array $steps): array
    {
        $s = count($upars); $half = intdiv($s, 2);
        try { $t = self::splitTransform($upars, $shift, $scaling, $maps, $steps); }
        catch (\Exception $e) { if (!DrawKernels::isNumericalFailure($e)) { throw $e; } return ['stop' => 'split_failed', 'message' => $e->getMessage()]; }
        $z = array_merge(array_slice($t['forward']['draws'], 0, $half), array_slice($upars, $half));
        $u = array_slice($t['inverse']['draws'], $half);
        try { $lpForward = $model['log_prob'](array_slice($z, 0, $half)); $lpInverse = $model['log_prob']($u); $ll = $model['log_lik']($z, $i); }
        catch (\Exception $e) { return ['stop' => 'split_failed', 'message' => $e->getMessage()]; }
        // Untransformed rows reuse the posterior log density: p(z) for the second half and p(T^-1 z) for the first.
        $lpZ = array_merge(self::values($lpForward, $half, 'logProbUpars'), array_slice($model['orig_lp'], $half));
        $lpU = array_merge(array_slice($model['orig_lp'], 0, $half), self::values($lpInverse, $s - $half, 'logProbUpars'));
        $ll = self::values($ll, $s, 'logLikIUpars');
        try {
            $lwi = Psis::psis(self::splitLogRatios($lpZ, $lpU, $ll, $t['forward']['log_abs_det']), $rEff)['log_weights'];
            $lwfi = Psis::psis(array_map(fn($a, $b) => is_nan($a + $b) ? -INF : $a + $b, $lwi, $ll), $rEff)['log_weights'];
        } catch (\InvalidArgumentException $e) { return ['stop' => 'split_failed', 'message' => $e->getMessage()]; }
        $r1 = self::relativeEff(array_slice($ll, 0, $half), 1); $r2 = self::relativeEff(array_slice($ll, $half), 1);
        return ['ll' => $ll, 'lwi' => $lwi, 'lwfi' => $lwfi, 'r_eff' => is_nan($r1) || is_nan($r2) ? NAN : min($r1, $r2)];
    }
    /** loo::relative_eff(exp(ll)); ESS is scale invariant, so ll is shifted by its maximum to avoid underflow. */
    private static function relativeEff(array $ll, int $chains): float
    {
        $max = max($ll);
        return Loo::relativeEffColumns([array_map(fn($v) => exp($v - $max), $ll)], $chains)[0];
    }
    private static function logAddExp(float $a, float $b): float
    {
        $max = max($a, $b);
        return $max == -INF ? -INF : $max + log1p(exp(min($a, $b) - $max));
    }
    /** @return list<list<float>> validated rectangular unconstrained draws with one row per log-likelihood draw */
    private static function draws(array $upars, int $s): array
    {
        if (!array_is_list($upars) || count($upars) !== $s) { throw new \InvalidArgumentException('Unconstrained draws need one row per log-likelihood draw ('.$s.'), in the same chain-major order.'); }
        $p = null;
        foreach ($upars as $row) {
            if (!is_array($row) || !array_is_list($row) || $row === []) { throw new \InvalidArgumentException('Each unconstrained draw must be a non-empty list of numbers.'); }
            $p ??= count($row);
            if (count($row) !== $p) { throw new \InvalidArgumentException('Unconstrained draws must be rectangular.'); }
            foreach ($row as $v) { if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v)) { throw new \InvalidArgumentException('Unconstrained draws must be finite numbers.'); } }
        }
        return array_map(fn($row) => array_map('floatval', $row), $upars);
    }
    /** @return list<float> */
    private static function values(mixed $values, int $s, string $name): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) !== $s) { throw new \InvalidArgumentException($name.' must return one value per draw ('.$s.').'); }
        foreach ($values as $v) { if (!is_int($v) && !is_float($v)) { throw new \InvalidArgumentException($name.' must return numbers.'); } }
        return array_map('floatval', $values);
    }
    /** Zero-based indices into constrainVariables()[$variable] for a draws column name such as "log_lik[2,3]". */
    private static function path(string $name, string $variable): array
    {
        if ($name === $variable) { return []; }
        if (!preg_match('/^'.preg_quote($variable, '/').'\[(\d+(?:,\d+)*)\]$/D', $name, $m)) { throw new \InvalidArgumentException('Moment matching needs a single log-likelihood variable; cannot map column '.$name.' to '.$variable.'.'); }
        return array_map(fn($index) => (int)$index - 1, explode(',', $m[1]));
    }
}
