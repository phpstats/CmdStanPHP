<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * PSIS-LOO and WAIC ported from loo (loo.array, waic.array) as called by CmdStanR's CmdStanMCMC$loo(r_eff = TRUE).
 *
 * Log-likelihood input is a draws_array [iteration][chain][observation] (inner arrays may be keyed by names such as
 * 'log_lik[1]', as CmdStanFit::draws() returns) or a draws_matrix [draw][observation], treated as a single chain.
 * Draws are flattened chain-major like loo's llarray_to_matrix(). Moment matching is in MomentMatch (CmdStanMCMC::loo(momentMatch: true)).
 */
final class Loo
{
    /**
     * @param array|float|bool|null $rEff true computes relative efficiencies like CmdStanR; false/null uses 1; floats are passed to PSIS
     * @return array{estimates: array<string, array{Estimate: float, SE: float}>, pointwise: list<array>, diagnostics: array{pareto_k: list<float>, n_eff: list<float>, r_eff: list<float>}, elpd_loo: float, p_loo: float, looic: float, se_elpd_loo: float, se_p_loo: float, se_looic: float, dims: array{0: int, 1: int}, observations: list<string|int>, k_threshold: float, warnings: list<string>}
     */
    public static function loo(array $logLik, array|float|bool|null $rEff = true): array
    {
        [$columns, $chains, $names] = self::columns($logLik);
        foreach ($columns as $column) { foreach ($column as $v) { if (!is_finite($v)) { throw new \InvalidArgumentException(is_nan($v) ? 'NAs not allowed in input.' : 'All log-likelihood values must be finite.'); } } }
        if ($rEff === true) { $rEff = self::relativeEffColumns(array_map(function ($column) { $max = max($column); return array_map(fn($v) => exp($v - $max), $column); }, $columns), $chains); }
        elseif ($rEff === false) { $rEff = null; }
        $psis = Psis::psisColumns(array_map(fn($column) => array_map(fn($v) => -$v, $column), $columns), $rEff);
        $s = count($columns[0]); $pointwise = [];
        foreach ($columns as $j => $ll) {
            $lw = $psis['log_weights'][$j];
            $elpd = Special::logSumExp(array_map(fn($a, $b) => $a + $b, $ll, $lw));
            $lpd = Special::logSumExp($ll) - log($s);
            $pointwise[] = ['elpd_loo' => $elpd, 'mcse_elpd_loo' => self::mcseElpd($ll, $lw, $elpd, $psis['r_eff'][$j]), 'p_loo' => $lpd - $elpd, 'looic' => -2 * $elpd, 'influence_pareto_k' => $psis['pareto_k'][$j]];
        }
        $estimates = self::estimates($pointwise, ['elpd_loo', 'p_loo', 'looic']);
        return ['estimates' => $estimates, 'pointwise' => $pointwise, 'diagnostics' => ['pareto_k' => $psis['pareto_k'], 'n_eff' => $psis['n_eff'], 'r_eff' => $psis['r_eff']],
            'elpd_loo' => $estimates['elpd_loo']['Estimate'], 'p_loo' => $estimates['p_loo']['Estimate'], 'looic' => $estimates['looic']['Estimate'],
            'se_elpd_loo' => $estimates['elpd_loo']['SE'], 'se_p_loo' => $estimates['p_loo']['SE'], 'se_looic' => $estimates['looic']['SE'],
            'dims' => [$s, count($columns)], 'observations' => $names, 'k_threshold' => $psis['k_threshold'], 'warnings' => $psis['warnings']];
    }
    /** CmdStanFit equivalent of CmdStanMCMC$loo(variables, r_eff); uses the post-warmup draws_array. */
    public static function fromFit(CmdStanFit $fit, string $variables = 'log_lik', array|float|bool|null $rEff = true): array
    {
        return self::loo($fit->draws($variables, 'draws_array'), $rEff);
    }
    /**
     * @return array{estimates: array<string, array{Estimate: float, SE: float}>, pointwise: list<array{elpd_waic: float, p_waic: float, waic: float}>, elpd_waic: float, p_waic: float, waic: float, se_elpd_waic: float, se_p_waic: float, se_waic: float, dims: array{0: int, 1: int}, observations: list<string|int>, warnings: list<string>}
     */
    public static function waic(array $logLik): array
    {
        [$columns, , $names] = self::columns($logLik);
        foreach ($columns as $column) {
            foreach ($column as $v) {
                if (is_nan($v)) { throw new \InvalidArgumentException('NAs not allowed in input.'); }
                if ($v == INF) { throw new \InvalidArgumentException('All input values must be finite or -Inf.'); }
                if ($v == -INF) { throw new \InvalidArgumentException('All log-likelihood values must be finite for WAIC.'); }
            }
        }
        $s = count($columns[0]); $pointwise = [];
        foreach ($columns as $ll) {
            $lpd = Special::logSumExp($ll) - log($s); $p = Special::variance($ll);
            $pointwise[] = ['elpd_waic' => $lpd - $p, 'p_waic' => $p, 'waic' => -2 * ($lpd - $p)];
        }
        $warnings = []; $bad = count(array_filter($pointwise, fn($row) => $row['p_waic'] > 0.4));
        if ($bad > 0) { $warnings[] = $bad.' ('.number_format(round(100 * $bad / count($pointwise), 1), 1, '.', '').'%) p_waic estimates greater than 0.4. We recommend trying loo instead.'; }
        $estimates = self::estimates($pointwise, ['elpd_waic', 'p_waic', 'waic']);
        return ['estimates' => $estimates, 'pointwise' => $pointwise, 'elpd_waic' => $estimates['elpd_waic']['Estimate'], 'p_waic' => $estimates['p_waic']['Estimate'], 'waic' => $estimates['waic']['Estimate'],
            'se_elpd_waic' => $estimates['elpd_waic']['SE'], 'se_p_waic' => $estimates['p_waic']['SE'], 'se_waic' => $estimates['waic']['SE'], 'dims' => [$s, count($columns)], 'observations' => $names, 'warnings' => $warnings];
    }
    public static function waicFromFit(CmdStanFit $fit, string $variables = 'log_lik'): array { return self::waic($fit->draws($variables, 'draws_array')); }
    /**
     * loo::relative_eff for a draws_array [iteration][chain][observation] of (exponentiated) likelihood values:
     * posterior::ess_mean per observation divided by the total number of draws. NAN where ESS is undefined.
     * @return list<float>
     */
    public static function relativeEff(array $x): array
    {
        [$columns, $chains] = self::columns($x);
        return self::relativeEffColumns($columns, $chains);
    }
    /** relative_eff(exp(log_lik - max per observation)), exactly as CmdStanMCMC$loo(r_eff = TRUE) computes it. */
    public static function relativeEffLogLik(array $logLik): array
    {
        [$columns, $chains] = self::columns($logLik);
        return self::relativeEffColumns(array_map(function ($column) { $max = max($column); return array_map(fn($v) => exp($v - $max), $column); }, $columns), $chains);
    }
    /**
     * Pareto k summary like loo::pareto_k_table: counts, proportions and minimum n_eff per k interval.
     * @return array{k_threshold: float, rows: list<array{interval: string, count: int, proportion: float, min_n_eff: float}>}
     */
    public static function paretoKTable(array $loo): array
    {
        $threshold = $loo['k_threshold']; $label = number_format(round($threshold, 2), 2, '.', '');
        $label = rtrim(rtrim($label, '0'), '.');
        $bins = [['(-Inf, '.$label.']', -INF, $threshold], ['('.$label.', 1]', $threshold, 1.0], ['(1, Inf)', 1.0, INF]]; $rows = [];
        $total = count($loo['diagnostics']['pareto_k']);
        foreach ($bins as [$interval, $low, $high]) {
            $count = 0; $min = NAN;
            foreach ($loo['diagnostics']['pareto_k'] as $i => $k) {
                if ($k > $low && $k <= $high) {
                    ++$count; $n = $k > $threshold ? NAN : $loo['diagnostics']['n_eff'][$i];
                    if (!is_nan($n)) { $min = is_nan($min) ? $n : min($min, $n); }
                }
            }
            $rows[] = ['interval' => $interval, 'count' => $count, 'proportion' => $total ? $count / $total : NAN, 'min_n_eff' => $min];
        }
        return ['k_threshold' => $threshold, 'rows' => $rows];
    }
    /** @internal */
    public static function relativeEffColumns(array $columns, int $chains): array
    {
        $s = count($columns[0] ?? []); $n = intdiv($s, $chains);
        return array_map(fn($column) => Summary::essMean(array_chunk($column, $n)) / $s, $columns);
    }
    /** @internal loo:::mcse_elpd for one observation. */
    public static function mcseElpd(array $ll, array $lw, float $elpd, float $rEff): float
    {
        if (is_infinite($elpd) && $elpd < 0) { return NAN; }
        $sum = 0.0;
        foreach ($ll as $i => $v) { $sum += (exp($lw[$i]) * expm1($v - $elpd)) ** 2; }
        $ratio = fdiv($sum, $rEff);
        if (is_finite($ratio)) { return sqrt(log1p($ratio)); }
        $terms = [];
        foreach ($ll as $i => $v) {
            $d = $v - $elpd; $logAbs = $d > 700 ? $d + log(-expm1(-$d)) : log(abs(expm1($d)));
            $terms[] = 2 * ($lw[$i] + $logAbs);
        }
        $lvr = Special::logSumExp($terms) - log($rEff);
        return sqrt($lvr > 0 ? $lvr + log1p(exp(-$lvr)) : log1p(exp($lvr)));
    }
    /** @internal loo:::table_of_estimates: column sums and sqrt(N * var). */
    public static function estimates(array $pointwise, array $names): array
    {
        $out = [];
        foreach ($names as $name) { $values = array_column($pointwise, $name); $out[$name] = ['Estimate' => array_sum($values), 'SE' => sqrt(count($values) * Special::variance($values))]; }
        return $out;
    }
    /** @internal @return array{0: list<list<float>>, 1: int, 2: list<string|int>} observation columns (chain-major draws), number of chains, observation names */
    public static function columns(array $x): array
    {
        $x = array_values($x);
        if ($x === []) { throw new \InvalidArgumentException('Log-likelihood draws must not be empty.'); }
        $first = reset($x[0]);
        if (!is_array($first)) { $x = array_map(fn($row) => [$row], $x); }
        $chains = count($x[0]); $names = array_keys(reset($x[0])); $columns = array_fill(0, count($names), []);
        if ($names === [] || $chains === 0) { throw new \InvalidArgumentException('Log-likelihood draws need at least one observation.'); }
        for ($c = 0; $c < $chains; ++$c) {
            foreach ($x as $iteration) {
                $values = $iteration[$c] ?? throw new \InvalidArgumentException('Every iteration needs the same number of chains.');
                if (array_keys($values) !== $names) { throw new \InvalidArgumentException('Every draw needs the same observations.'); }
                foreach (array_values($values) as $j => $v) { $columns[$j][] = (float)$v; }
            }
        }
        return [$columns, $chains, $names];
    }
}
