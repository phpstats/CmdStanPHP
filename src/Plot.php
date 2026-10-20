<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Computes the figures used with CmdStanR through bayesplot and loo, returning Figure objects that render as ASCII
 * text, Vega-Lite, HTML, or SVG/PNG.
 *
 * Draws come from a fit or a draws_array [iteration][chain][variable => value]. A plotted variable names one column,
 * such as 'theta' or 'beta[2]'. Kernel densities use a Gaussian kernel with R's bw.nrd0 bandwidth on 512 points
 * spanning three bandwidths beyond the data, as R's density() does; quantiles use R's type 7, ranks average ties,
 * and autocorrelations match R's acf().
 */
final class Plot
{
    /** Chains overlaid by iteration (bayesplot's mcmc_trace). */
    public static function trace(CmdStanFit|array $draws, string $variable): Figure
    {
        $rows = []; $chains = self::chains($draws, $variable);
        foreach ($chains as $c => $values) { foreach ($values as $i => $v) { $rows[] = ['chain' => $c + 1, 'iteration' => $i + 1, 'value' => $v]; } }
        return new Figure('trace', $variable.': trace', $rows, ['variable' => $variable, 'chains' => count($chains)]);
    }
    /** Histogram of all chains' draws (bayesplot's mcmc_hist); bins have equal width over the observed range. */
    public static function hist(CmdStanFit|array $draws, string $variable, int $bins = 30): Figure
    {
        if ($bins < 1) { throw new \InvalidArgumentException('bins must be positive.'); }
        $values = array_merge(...self::chains($draws, $variable)); [$lo, $hi] = self::range($values); $counts = array_fill(0, $bins, 0);
        foreach ($values as $v) { ++$counts[min($bins - 1, (int)floor(($v - $lo) / ($hi - $lo) * $bins))]; }
        $rows = array_map(fn($b) => ['x0' => $lo + $b * ($hi - $lo) / $bins, 'x1' => $lo + ($b + 1) * ($hi - $lo) / $bins, 'count' => $counts[$b]], range(0, $bins - 1));
        return new Figure('hist', $variable.': histogram of '.count($values).' draws, '.$bins.' bins', $rows, ['variable' => $variable, 'draws' => count($values), 'bins' => $bins]);
    }
    /** Kernel density of all draws, or one curve per chain with $by_chain (bayesplot's mcmc_dens and mcmc_dens_overlay). */
    public static function dens(CmdStanFit|array $draws, string $variable, bool $by_chain = false): Figure
    {
        $chains = self::chains($draws, $variable); $sets = $by_chain ? $chains : [array_merge(...$chains)]; $rows = []; $bandwidths = [];
        foreach ($sets as $c => $set) {
            $bw = $bandwidths[] = self::bandwidth($set); $lo = min($set) - 3 * $bw; $hi = max($set) + 3 * $bw;
            $at = array_map(fn($i) => $lo + $i * ($hi - $lo) / 511, range(0, 511));
            foreach (self::density($set, $at, $bw) as $i => $d) { $rows[] = ['chain' => $by_chain ? $c + 1 : null, 'x' => $at[$i], 'density' => $d]; }
        }
        return new Figure('dens', $variable.': kernel density', $rows, ['variable' => $variable, 'by_chain' => $by_chain, 'bandwidths' => $bandwidths, 'chains' => count($chains)]);
    }
    /**
     * Inner and outer posterior intervals with a point estimate (bayesplot's mcmc_intervals). A null selection uses
     * every variable except lp__ and other names ending in '__'; base names select all of their elements.
     */
    public static function intervals(CmdStanFit|array $draws, array|string|null $variables = null, float $prob = 0.5, float $prob_outer = 0.9, string $point_est = 'median'): Figure
    {
        if (!($prob > 0 && $prob <= $prob_outer && $prob_outer <= 1)) { throw new \InvalidArgumentException('Require 0 < prob <= prob_outer <= 1.'); }
        if (!in_array($point_est, ['median', 'mean', 'none'], true)) { throw new \InvalidArgumentException("point_est must be 'median', 'mean' or 'none'."); }
        $rows = [];
        foreach (self::columns($draws, $variables) as $name => $values) {
            $q = fn(float $p) => CmdStanFit::quantile($values, $p);
            $rows[] = ['variable' => (string)$name, 'll' => $q((1 - $prob_outer) / 2), 'l' => $q((1 - $prob) / 2), 'm' => match ($point_est) { 'median' => $q(0.5), 'mean' => array_sum($values) / count($values), default => null }, 'h' => $q((1 + $prob) / 2), 'hh' => $q((1 + $prob_outer) / 2)];
        }
        $title = 'intervals: '.self::percent($prob).' inner, '.self::percent($prob_outer).' outer'.($point_est === 'none' ? '' : ', point = '.$point_est);
        return new Figure('intervals', $title, $rows, ['prob' => $prob, 'prob_outer' => $prob_outer, 'point_est' => $point_est]);
    }
    /** Per-chain histograms of pooled ranks (bayesplot's mcmc_rank_hist), with the count expected under uniform ranks. */
    public static function rankHist(CmdStanFit|array $draws, string $variable, int $bins = 20): Figure
    {
        if ($bins < 1) { throw new \InvalidArgumentException('bins must be positive.'); }
        $chains = self::chains($draws, $variable); $ranks = self::ranks(array_merge(...$chains)); $total = count($ranks); $rows = []; $offset = 0;
        foreach ($chains as $c => $values) {
            $counts = array_fill(0, $bins, 0);
            foreach (array_slice($ranks, $offset, count($values)) as $rank) { ++$counts[min($bins - 1, (int)floor(($rank - 1) * $bins / $total))]; }
            foreach ($counts as $b => $count) { $rows[] = ['chain' => $c + 1, 'bin' => $b + 1, 'x0' => 1 + $b * ($total - 1) / $bins, 'x1' => 1 + ($b + 1) * ($total - 1) / $bins, 'count' => $count, 'expected' => (float)(count($values) / $bins)]; }
            $offset += count($values);
        }
        return new Figure('rank_hist', $variable.': rank histograms, '.$bins.' bins of '.$total.' pooled ranks', $rows, ['variable' => $variable, 'bins' => $bins, 'total' => $total, 'chains' => count($chains)]);
    }
    /** Joint draws of two variables (bayesplot's mcmc_scatter); MCMC fits flag divergent transitions. */
    public static function scatter(CmdStanFit|array $draws, string $x_variable, string $y_variable): Figure
    {
        $xs = array_merge(...self::chains($draws, $x_variable)); $ys = array_merge(...self::chains($draws, $y_variable)); $divergent = array_fill(0, count($xs), false); $flagged = false;
        if ($draws instanceof CmdStanMCMC && array_key_exists('divergent__', $draws->samplerDiagnostics('draws_list')[0])) {
            $divergent = array_map(fn($flag) => $flag > 0, array_merge(...array_map(fn($chain) => $chain['divergent__'], $draws->samplerDiagnostics('draws_list')))); $flagged = true;
        }
        $rows = array_map(fn($x, $y, $d) => ['x' => $x, 'y' => $y, 'divergent' => $d], $xs, $ys, $divergent);
        return new Figure('scatter', $y_variable.' against '.$x_variable, $rows, ['x_variable' => $x_variable, 'y_variable' => $y_variable, 'divergences' => $flagged]);
    }
    /** Per-chain autocorrelations for lags 0..$lags (bayesplot's mcmc_acf_bar). */
    public static function acf(CmdStanFit|array $draws, string $variable, int $lags = 20): Figure
    {
        $chains = self::chains($draws, $variable);
        if ($lags < 1 || $lags >= min(array_map('count', $chains))) { throw new \InvalidArgumentException('lags must be between 1 and the chain length minus one.'); }
        $rows = [];
        foreach ($chains as $c => $values) { foreach (self::autocorrelation($values, $lags) as $lag => $r) { $rows[] = ['chain' => $c + 1, 'lag' => $lag, 'acf' => is_nan($r) ? null : $r]; } }
        return new Figure('acf', $variable.': autocorrelation, lags 0-'.$lags, $rows, ['variable' => $variable, 'lags' => $lags, 'chains' => count($chains)]);
    }
    /** Pareto k by observation from a loo() result (loo's plot method). */
    public static function paretoK(array $loo): Figure
    {
        $k = array_values($loo['diagnostics']['pareto_k'] ?? $loo['pareto_k'] ?? throw new \InvalidArgumentException('Expected a loo() result with Pareto k diagnostics.'));
        if ($k === []) { throw new \InvalidArgumentException('No Pareto k values to plot.'); }
        $rows = array_map(fn($i) => ['observation' => $i + 1, 'k' => (float)$k[$i]], array_keys($k));
        return new Figure('pareto_k', 'PSIS diagnostic: Pareto k by observation', $rows, ['threshold' => (float)($loo['k_threshold'] ?? 0.7)]);
    }
    /** R's bw.nrd0 rule-of-thumb bandwidth. */
    public static function bandwidth(array $x): float
    {
        $n = count($x);
        if ($n < 2) { throw new \InvalidArgumentException('Need at least 2 draws for a bandwidth.'); }
        $mean = array_sum($x) / $n; $sd = sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $x)) / ($n - 1));
        $lo = min($sd, (CmdStanFit::quantile($x, 0.75) - CmdStanFit::quantile($x, 0.25)) / 1.34);
        if (!($lo > 0)) { $lo = $sd ?: abs((float)$x[0]) ?: 1.0; }
        return 0.9 * $lo * $n ** -0.2;
    }
    /** Gaussian kernel density of $x evaluated exactly at each point of $at. */
    public static function density(array $x, array $at, ?float $bw = null): array
    {
        $bw ??= self::bandwidth($x); $n = count($x); $norm = 1 / ($n * $bw * sqrt(2 * M_PI));
        return array_map(function ($a) use ($x, $bw, $norm) { $sum = 0.0; foreach ($x as $v) { $sum += exp(-0.5 * (($a - $v) / $bw) ** 2); } return $sum * $norm; }, $at);
    }
    /** Autocorrelations for lags 0..$lags, as R's acf(x, lag.max, plot = FALSE). */
    public static function autocorrelation(array $x, int $lags): array
    {
        $n = count($x); $mean = array_sum($x) / $n; $d = array_map(fn($v) => $v - $mean, $x); $c0 = array_sum(array_map(fn($v) => $v * $v, $d));
        $out = [];
        for ($k = 0; $k <= $lags; ++$k) { $sum = 0.0; for ($t = 0; $t < $n - $k; ++$t) { $sum += $d[$t] * $d[$t + $k]; } $out[] = $c0 > 0 ? $sum / $c0 : NAN; }
        return $out;
    }
    /** One-based ranks with ties averaged, as R's rank(). */
    public static function ranks(array $x): array
    {
        $order = array_keys($x); usort($order, fn($a, $b) => $x[$a] <=> $x[$b]); $ranks = []; $n = count($order);
        for ($i = 0; $i < $n; $i = $j) {
            for ($j = $i; $j < $n && $x[$order[$j]] == $x[$order[$i]]; ++$j) {}
            foreach (array_slice($order, $i, $j - $i) as $index) { $ranks[$index] = ($i + 1 + $j) / 2; }
        }
        ksort($ranks); return array_values($ranks);
    }
    /** Data range, padded when every value is equal. */
    public static function range(array $values): array
    {
        $lo = (float)min($values); $hi = (float)max($values);
        if ($hi - $lo <= 1e-12 * max(1.0, abs($hi))) { $pad = max(0.5, abs($hi) * 0.1); $lo -= $pad; $hi += $pad; }
        return [$lo, $hi];
    }
    private static function chains(CmdStanFit|array $draws, string $variable): array
    {
        if ($draws instanceof CmdStanFit) {
            $list = $draws->draws($variable, 'draws_list'); $names = array_keys($list[0] ?? []);
            if ($names !== [$variable]) { throw new \InvalidArgumentException('Plot one variable element, such as '.($names[0] ?? $variable).'.'); }
            $chains = array_map(fn($chain) => array_map('floatval', $chain[$variable]), $list);
        } else {
            $draws = array_values($draws);
            if ($draws === [] || !array_key_exists($variable, $draws[0][0] ?? [])) { throw new \InvalidArgumentException('Unknown variable: '.$variable); }
            $chains = array_map(fn($c) => array_map(fn($iteration) => (float)$iteration[$c][$variable], $draws), array_keys($draws[0]));
        }
        foreach ($chains as $values) {
            if (count($values) < 2) { throw new \InvalidArgumentException('Each chain needs at least 2 draws to plot.'); }
            foreach ($values as $v) { if (!is_finite($v)) { throw new \InvalidArgumentException('Cannot plot non-finite draws of '.$variable.'.'); } }
        }
        return $chains;
    }
    /** Pooled draws per selected column. */
    private static function columns(CmdStanFit|array $draws, array|string|null $variables): array
    {
        if ($draws instanceof CmdStanFit) { $rows = $draws->draws($variables, 'draws_matrix'); }
        else { $draws = array_values($draws); $rows = []; foreach (array_keys($draws[0] ?? []) as $c) { foreach ($draws as $iteration) { $rows[] = $iteration[$c]; } } }
        $names = array_keys($rows[0] ?? []);
        if ($variables === null) { $names = array_values(array_filter($names, fn($name) => !str_ends_with((string)$name, '__'))); }
        elseif (!$draws instanceof CmdStanFit) {
            $wanted = (array)$variables; $names = array_values(array_filter($names, fn($name) => in_array($name, $wanted, true) || in_array(explode('[', (string)$name)[0], $wanted, true)));
        }
        if ($names === []) { throw new \InvalidArgumentException('No variables selected.'); }
        $out = [];
        foreach ($names as $name) {
            $out[$name] = array_map('floatval', array_column($rows, $name));
            foreach ($out[$name] as $v) { if (!is_finite($v)) { throw new \InvalidArgumentException('Cannot plot non-finite draws of '.$name.'.'); } }
        }
        return $out;
    }
    private static function percent(float $p): string { return rtrim(rtrim(sprintf('%.2f', $p * 100), '0'), '.').'%'; }
}
