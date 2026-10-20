<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Pareto smoothing ported from posterior (pareto_smooth, pareto_khat, gpdfit) and loo (psis).
 * Warnings that R emits are returned in a 'warnings' list instead of raising PHP warnings.
 */
final class Psis
{
    /**
     * Zhang & Stephens (2009) generalized Pareto fit with the weakly informative prior on k (posterior::gpdfit).
     * @return array{k: float, sigma: float} NAN for both when the fit is undefined (R's NA).
     */
    public static function gpdfit(array $x, bool $wip = true, int $minGridPts = 30, bool $sortX = true): array
    {
        $x = array_map('floatval', array_values($x));
        if ($sortX) { sort($x, SORT_NUMERIC); }
        $n = count($x);
        if ($n < 2) { return ['k' => NAN, 'sigma' => NAN]; }
        $prior = 3; $m = $minGridPts + (int)floor(sqrt($n)); $xstar = $x[(int)floor($n / 4 + 0.5) - 1];
        if (!($xstar > $x[0])) { return ['k' => NAN, 'sigma' => NAN]; }
        $theta = []; $l = [];
        for ($j = 1; $j <= $m; ++$j) {
            $t = fdiv(1, $x[$n - 1]) + fdiv((1 - sqrt($m / ($j - 0.5))) / $prior, $xstar); $s = 0.0;
            foreach ($x as $v) { $s += log1p(-$t * $v); }
            $k = $s / $n; $theta[] = $t; $l[] = $n * (log(fdiv(-$t, $k)) - $k - 1);
        }
        $norm = Special::logSumExp($l); $thetaHat = 0.0;
        foreach ($theta as $j => $t) { $thetaHat += $t * exp($l[$j] - $norm); }
        $s = 0.0; foreach ($x as $v) { $s += log1p(-$thetaHat * $v); }
        $k = $s / $n; $sigma = fdiv(-$k, $thetaHat);
        if ($wip) { $k = ($k * $n + 0.5 * 10) / ($n + 10); }
        if (is_nan($k)) { return ['k' => INF, 'sigma' => NAN]; }
        return ['k' => $k, 'sigma' => $sigma];
    }
    /** Generalized Pareto quantile function (posterior::qgeneralized_pareto). */
    public static function qgeneralizedPareto(array|float $p, float $mu = 0.0, float $sigma = 1.0, float $k = 0.0): array|float
    {
        if (is_array($p)) { return array_map(fn($q) => self::qgeneralizedPareto((float)$q, $mu, $sigma, $k), $p); }
        if (is_nan($sigma) || $sigma <= 0) { return NAN; }
        return $k == 0.0 ? $mu - $sigma * log1p(-$p) : $mu + $sigma * expm1(-$k * log1p(-$p)) / $k;
    }
    public static function psTailLength(int $ndraws, float $rEff): float
    {
        return is_nan($rEff) ? NAN : floor($ndraws * $rEff > 225 ? 3 * sqrt(fdiv($ndraws, $rEff)) : $ndraws / 5);
    }
    public static function psKhatThreshold(int $ndraws): float { return 1 - fdiv(1, log10($ndraws)); }
    public static function psMinSs(float $k): float { return is_nan($k) ? NAN : ($k < 1 ? 10 ** (1 / (1 - max(0.0, $k))) : INF); }
    public static function psConvergenceRate(float $k, int $ndraws): float
    {
        if (is_nan($k)) { return NAN; }
        if ($k < 0) { return 1.0; }
        if ($k > 1) { return 0.0; }
        if ($k == 0.5) { return 1 - 1 / log($ndraws); }
        if ($k == 0.0 || $k == 1.0) { return 0.0; }
        return max(0.0, fdiv(2 * ($k - 1) * $ndraws ** (2 * $k + 1) + (1 - 2 * $k) * $ndraws ** (2 * $k) + $ndraws ** 2, ($ndraws - 1) * ($ndraws - $ndraws ** (2 * $k))));
    }
    /**
     * posterior::pareto_smooth.default with return_k = TRUE and extra_diags = TRUE.
     * @param string $tail 'both', 'right' or 'left' (forced to 'right' for log weights)
     * @param ?float $rEff null uses ess_tail(x) / ndraws, treating x as one chain
     * @return array{x: list<float>, diagnostics: array{khat: float, min_ss: float, khat_threshold: float, convergence_rate: float}, warnings: list<string>}
     */
    public static function paretoSmooth(array $x, string $tail = 'both', ?float $rEff = null, ?int $ndrawsTail = null, bool $areLogWeights = false, bool $smoothDraws = true): array
    {
        $x = array_map('floatval', array_values($x)); $warnings = [];
        $na = fn(array $x, array $warnings) => ['x' => $x, 'diagnostics' => ['khat' => NAN, 'min_ss' => NAN, 'khat_threshold' => NAN, 'convergence_rate' => NAN], 'warnings' => $warnings];
        $finite = count(array_filter($x, 'is_finite')) === count($x);
        if ($x === [] || !$finite || abs(max($x) - min($x)) < PHP_FLOAT_EPSILON) {
            return $na($x, ['Input contains infinite or NA values, is constant or has constant tail. Fitting of generalized Pareto distribution not performed.']);
        }
        if ($areLogWeights) { $tail = 'right'; }
        if (!in_array($tail, ['both', 'right', 'left'], true)) { throw new \InvalidArgumentException("tail must be 'both', 'right' or 'left'."); }
        $ndraws = count($x); $rEff ??= Summary::essTail([$x]) / $ndraws;
        $length = $ndrawsTail ?? self::psTailLength($ndraws, $rEff);
        if (is_nan($length)) { return $na($x, ['Input contains infinite or NA values, is constant, or has constant tail. Fitting of generalized Pareto distribution not performed.']); }
        if ($length < 5) { $warnings[] = 'Number of tail draws cannot be less than 5. Changing to 5.'; $length = 5; }
        if ($length >= $ndraws) { $warnings[] = 'Tail draws ('.$length.') not strictly less than total draws ('.$ndraws.'). Fitting of generalized Pareto distribution not performed.'; return $na($x, $warnings); }
        if ($tail === 'both') {
            if ($length > $ndraws / 2) { $length = $ndraws / 2; $warnings[] = 'Number of tail draws cannot be more than half the total number of draws if both tails are fit, changing to '.$length.'.'; }
            [$x, $left] = self::psTail($x, $length, 'left', $areLogWeights, $smoothDraws);
            [$x, $right] = self::psTail($x, $length, 'right', $areLogWeights, $smoothDraws);
            $k = is_nan($left) && is_nan($right) ? NAN : max(is_nan($left) ? -INF : $left, is_nan($right) ? -INF : $right);
        } else { [$x, $k] = self::psTail($x, $length, $tail, $areLogWeights, $smoothDraws); }
        if (is_nan($k)) { return $na($x, $warnings); }
        return ['x' => $x, 'diagnostics' => ['khat' => $k, 'min_ss' => self::psMinSs($k), 'khat_threshold' => self::psKhatThreshold($ndraws), 'convergence_rate' => self::psConvergenceRate($k, $ndraws)], 'warnings' => $warnings];
    }
    /** posterior::pareto_khat: the Pareto k diagnostic without smoothing the draws. */
    public static function paretoKhat(array $x, string $tail = 'both', ?float $rEff = null, ?int $ndrawsTail = null, bool $areLogWeights = false): float
    {
        return self::paretoSmooth($x, $tail, $rEff, $ndrawsTail, $areLogWeights, false)['diagnostics']['khat'];
    }
    /** posterior::pareto_smooth_log_weights: right-tail smoothing of log weights (shifted so that the maximum is 0). */
    public static function paretoSmoothLogWeights(array $logWeights): array
    {
        return self::paretoSmooth($logWeights, 'right', areLogWeights: true);
    }
    /** posterior:::ps_tail. Returns [x, k]; k is NAN when no fit was performed. */
    private static function psTail(array $x, float $ndrawsTail, string $tail, bool $areLogWeights, bool $smoothDraws): array
    {
        if ($ndrawsTail < 5) { return [$x, NAN]; }
        if ($areLogWeights) { $max = max($x); $x = array_map(fn($v) => $v - $max, $x); }
        if ($tail === 'left') { $x = array_map(fn($v) => -$v, $x); }
        $ndraws = count($x); $first = (int)ceil($ndraws - $ndrawsTail + 1); $sorted = $x; asort($sorted, SORT_NUMERIC);
        $ids = array_keys($sorted); $values = array_values($sorted);
        $tailValues = array_slice($values, $first - 1); $tailIds = array_slice($ids, $first - 1);
        if (abs(max($tailValues) - min($tailValues)) < PHP_FLOAT_EPSILON) { return [$tail === 'left' ? array_map(fn($v) => -$v, $x) : $x, NAN]; }
        $cutoff = $values[$first - 2];
        if ($cutoff == $values[$first - 1]) { $cutoff -= PHP_FLOAT_EPSILON; }
        $maxTail = max($tailValues);
        if ($areLogWeights) { $tailValues = array_map('exp', $tailValues); $cutoff = exp($cutoff); }
        $fit = self::gpdfit(array_map(fn($v) => $v - $cutoff, $tailValues), sortX: false); $k = $fit['k'];
        if (is_finite($k) && $smoothDraws) {
            $count = count($tailValues);
            foreach ($tailIds as $i => $id) {
                $value = self::qgeneralizedPareto(($i + 1 - 0.5) / $count, $cutoff, $fit['sigma'], $k);
                if ($areLogWeights) { $value = log($value); }
                $x[$id] = $value > $maxTail ? $maxTail : $value;
            }
        }
        if ($tail === 'left') { $x = array_map(fn($v) => -$v, $x); }
        return [$x, $k];
    }
    /**
     * loo::psis for a vector or an S x N matrix of log importance ratios (rows are draws, columns observations).
     * @param array $logRatios list<float> (one column) or list<list<float>> rows of draws
     * @param array|float|null $rEff one value, one per column, or null (= 1); NAN entries are replaced by 1
     * @return array{log_weights: array, pareto_k: list<float>, n_eff: list<float>, r_eff: list<float>, tail_len: list<int>, norm_const_log: list<float>, k_threshold: float, warnings: list<string>}
     *   log_weights are normalized and have the same orientation as the input.
     */
    public static function psis(array $logRatios, array|float|null $rEff = 1.0): array
    {
        $vector = $logRatios !== [] && !is_array(reset($logRatios));
        $columns = $vector ? [array_map('floatval', array_values($logRatios))] : self::columns($logRatios);
        $result = self::psisColumns($columns, $rEff);
        $result['log_weights'] = $vector ? $result['log_weights'][0] : self::transpose($result['log_weights']);
        return $result;
    }
    /** @internal PSIS on a list of columns; log_weights are returned as columns. */
    public static function psisColumns(array $columns, array|float|null $rEff = 1.0): array
    {
        $n = count($columns); $s = count($columns[0] ?? []);
        if ($n === 0 || $s === 0) { throw new \InvalidArgumentException('Log ratios must not be empty.'); }
        foreach ($columns as $column) {
            if (count($column) !== $s) { throw new \InvalidArgumentException('Log ratios must be rectangular.'); }
            $max = -INF;
            foreach ($column as $v) {
                if (is_nan($v)) { throw new \InvalidArgumentException('NAs not allowed in input.'); }
                if ($v == INF) { throw new \InvalidArgumentException('All input values must be finite or -Inf.'); }
                $max = max($max, $v);
            }
            if ($max == -INF) { throw new \InvalidArgumentException('Each column of log ratios must contain at least one finite value.'); }
        }
        $rEff = self::prepareReff($rEff, $n); $warnings = []; $threshold = min(1 - fdiv(1, log10($s)), 0.7);
        $tailLen = array_map(fn($r) => (int)ceil(min(0.2 * $s, 3 * sqrt(fdiv($s, $r)))), $rEff);
        $bad = array_keys(array_filter($tailLen, fn($t) => $t < 5));
        if ($bad !== []) { $warnings[] = $n === 1 ? 'Not enough tail samples to fit the generalized Pareto distribution.' : 'Not enough tail samples to fit the generalized Pareto distribution in some or all columns of matrix of log importance ratios. Skipping the following columns: '.implode(', ', array_map(fn($i) => $i + 1, array_slice($bad, 0, 10))).(count($bad) > 10 ? ', ... ['.(count($bad) - 10).' more not printed].' : ''); }
        $logWeights = []; $k = []; $norm = []; $nEff = [];
        foreach ($columns as $j => $column) {
            [$lw, $k[$j], $warning] = self::doPsis($column, $tailLen[$j]);
            if ($warning !== null) { $warnings[] = $warning; }
            $norm[$j] = Special::logSumExp($lw); $ss = 0.0;
            $lw = array_map(fn($v) => $v - $norm[$j], $lw);
            foreach ($lw as $v) { $ss += exp($v) ** 2; }
            $logWeights[$j] = $lw; $nEff[$j] = 1 / $ss * $rEff[$j];
        }
        if (count(array_filter($k, fn($v) => $v > $threshold)) > 0) { $warnings[] = "Some Pareto k diagnostic values are too high. See help('pareto-k-diagnostic') for details."; }
        return ['log_weights' => $logWeights, 'pareto_k' => $k, 'n_eff' => $nEff, 'r_eff' => $rEff, 'tail_len' => $tailLen, 'norm_const_log' => $norm, 'k_threshold' => $threshold, 'warnings' => $warnings];
    }
    /** loo:::do_psis_i. Returns [unnormalized log weights, khat, warning]. */
    private static function doPsis(array $logRatios, int $tailLen): array
    {
        $s = count($logRatios); $max = max($logRatios); $lw = array_map(fn($v) => $v - $max, $logRatios); $k = INF; $warning = null;
        if ($tailLen >= 5) {
            $sorted = $lw; asort($sorted, SORT_NUMERIC); $ids = array_keys($sorted); $values = array_values($sorted);
            $tail = array_slice($values, $s - $tailLen); $tailIds = array_slice($ids, $s - $tailLen);
            if (abs(max($tail) - min($tail)) < PHP_FLOAT_EPSILON / 100) { $warning = "Can't fit generalized Pareto distribution because all tail values are the same."; }
            else {
                $cutoff = $values[$s - $tailLen - 1];
                $fit = self::gpdfit(array_map(fn($v) => $v == $cutoff ? 0.0 : -exp($v) * expm1($cutoff - $v), $tail), sortX: false);
                $k = is_nan($fit['k']) ? INF : $fit['k'];
                if (is_finite($k)) {
                    $expCutoff = exp($cutoff);
                    foreach ($tailIds as $i => $id) { $lw[$id] = log(self::qgeneralizedPareto(($i + 1 - 0.5) / $tailLen, 0.0, $fit['sigma'], $k) + $expCutoff); }
                }
            }
        }
        return [array_map(fn($v) => ($v > 0 ? 0.0 : $v) + $max, $lw), $k, $warning];
    }
    /** loo:::prepare_psis_r_eff */
    public static function prepareReff(array|float|null $rEff, int $len): array
    {
        if ($rEff === null || (is_array($rEff) && $rEff !== [] && count(array_filter($rEff, fn($r) => $r === null || is_nan((float)$r))) === count($rEff))) { return array_fill(0, $len, 1.0); }
        if (!is_array($rEff)) { return array_fill(0, $len, is_nan($rEff) ? 1.0 : $rEff); }
        $rEff = array_values($rEff);
        if (count($rEff) === 1) { return array_fill(0, $len, $rEff[0] === null || is_nan((float)$rEff[0]) ? 1.0 : (float)$rEff[0]); }
        if (count($rEff) !== $len) { throw new \InvalidArgumentException("'rEff' must have one value or one value per observation."); }
        return array_map(fn($r) => $r === null || is_nan((float)$r) ? 1.0 : (float)$r, $rEff);
    }
    private static function columns(array $rows): array
    {
        $rows = array_values($rows); $columns = [];
        foreach ($rows as $row) { foreach (array_values($row) as $j => $v) { $columns[$j][] = (float)$v; } }
        return $columns;
    }
    private static function transpose(array $columns): array
    {
        $rows = [];
        foreach ($columns as $j => $column) { foreach ($column as $i => $v) { $rows[$i][$j] = $v; } }
        return $rows;
    }
}
