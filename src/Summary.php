<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * posterior::summarise_draws port with posterior's convergence diagnostics (rank-normalized split R-hat, bulk/tail ESS, MCSE).
 *
 * Every measure function accepts either a list of chains (list<list<float>>, equal lengths for convergence measures)
 * or a flat list of draws, which is treated as a single chain (R's vector behaviour).
 *
 * Measures given to summarise() may be:
 * - strings: 'mean', 'median', 'sd', 'var', 'mad', 'quantile2', 'rhat', 'rhat_basic', 'ess_bulk', 'ess_tail', 'ess_basic',
 *   'ess_mean', 'ess_sd', 'ess_median', 'ess_quantile', 'mcse_mean', 'mcse_median', 'mcse_sd', 'mcse_quantile',
 *   and the groups 'default_summary_measures', 'default_convergence_measures', 'default_mcse_measures';
 * - callables under a string key: ['range' => fn(array $values) => max($values) - min($values)]. The first argument is the
 *   chain-major flattened draws; callables declaring a second parameter also receive the list of chains.
 *   A float result becomes column <key>; an array with string keys contributes those keys; a list with several values
 *   contributes <key>.1, <key>.2, ... as in R.
 * $probs is passed to quantile2, ess_quantile and mcse_quantile (posterior's `.args = list(probs = ...)`).
 */
final class Summary
{
    private const MEASURES = ['mean', 'median', 'sd', 'var', 'mad', 'quantile2', 'rhat', 'rhat_basic', 'ess_bulk', 'ess_tail', 'ess_basic', 'ess_mean', 'ess_sd', 'ess_median', 'ess_quantile', 'mcse_mean', 'mcse_median', 'mcse_sd', 'mcse_quantile'];
    public static function defaultSummaryMeasures(): array { return ['mean', 'median', 'sd', 'mad', 'quantile2']; }
    public static function defaultConvergenceMeasures(): array { return ['rhat', 'ess_bulk', 'ess_tail']; }
    public static function defaultMcseMeasures(): array { return ['mcse_mean', 'mcse_median', 'mcse_sd', 'mcse_quantile']; }
    /**
     * @param array $draws draws_list as returned by CmdStanFit::draws(format: 'draws_list'): [chain][variable => list<float>]
     * @return list<array> one row per variable: ['variable' => name, <measure columns>...]
     */
    public static function summarise(array $draws, array $measures = [], array $probs = [0.05, 0.95]): array
    {
        foreach ($probs as $p) { if (!is_numeric($p) || $p < 0 || $p > 1) { throw new \InvalidArgumentException("'probs' must contain values between 0 and 1."); } }
        $funs = self::measures($measures === [] ? [...self::defaultSummaryMeasures(), ...self::defaultConvergenceMeasures()] : $measures);
        $variables = []; foreach ($draws as $chain) { foreach ($chain as $name => $values) { $variables[$name] = true; } }
        $out = [];
        foreach (array_keys($variables) as $name) {
            $chains = array_map(fn($chain) => array_map('floatval', $chain[$name] ?? throw new \InvalidArgumentException('Variable missing from a chain: '.$name)), array_values($draws));
            $row = ['variable' => (string)$name];
            foreach ($funs as $key => $fun) {
                $result = is_string($fun) ? self::measure($fun, $chains, $probs) : self::call($fun, $chains);
                if (is_array($result)) {
                    if ($result !== [] && !array_is_list($result)) { foreach ($result as $k => $v) { if ((string)$k === 'variable') { throw new \InvalidArgumentException("Name 'variable' is reserved in 'summarise_draws'."); } $row[(string)$k] = (float)$v; } }
                    elseif (count($result) === 1) { $row[$key] = (float)$result[0]; }
                    else { foreach (array_values($result) as $i => $v) { $row[$key.'.'.($i + 1)] = (float)$v; } }
                } else { $row[$key] = (float)$result; }
            }
            $out[] = $row;
        }
        return $out;
    }
    /** Convert a draws_array [iteration][chain][variable => value] into the draws_list shape used by summarise(). */
    public static function fromDrawsArray(array $draws): array
    {
        $out = [];
        foreach ($draws as $iteration) { foreach ($iteration as $c => $values) { foreach ($values as $name => $value) { $out[$c][$name][] = $value; } } }
        return $out;
    }
    /** A measure group, named as posterior names it; null when the string is not a group. */
    private static function group(string $name): ?array
    {
        return match ($name) {
            'default_summary_measures' => self::defaultSummaryMeasures(),
            'default_convergence_measures' => self::defaultConvergenceMeasures(),
            'default_mcse_measures' => self::defaultMcseMeasures(),
            default => null,
        };
    }
    private static function measures(array $measures): array
    {
        $funs = [];
        foreach ($measures as $key => $measure) {
            $group = is_string($measure) ? self::group($measure) : null;
            if ($group !== null) {
                foreach ($group as $m) { $funs[$m] = $m; }
                continue;
            }
            if (is_string($measure)) {
                if (!in_array($measure, self::MEASURES, true)) { throw new \InvalidArgumentException("Cannot find function '".$measure."'."); }
                $funs[is_string($key) ? $key : $measure] = $measure;
                continue;
            }
            if (!is_callable($measure)) { throw new \InvalidArgumentException('Summary measures must be measure names or callables.'); }
            if (!is_string($key)) { throw new \InvalidArgumentException('Callable summary measures need a string key naming the column.'); }
            $funs[$key] = $measure;
        }
        if (isset($funs['variable'])) { throw new \InvalidArgumentException("Name 'variable' is reserved in 'summarise_draws'."); }
        return $funs;
    }
    private static function call(callable $fun, array $chains): float|int|array
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($fun));
        $values = array_merge(...$chains);
        return $reflection->isVariadic() || $reflection->getNumberOfParameters() >= 2 ? $fun($values, $chains) : $fun($values);
    }
    /** Evaluate one named measure; quantile-type measures return named arrays (q5, ess_q5, mcse_q5, ...). */
    public static function measure(string $name, array $x, array $probs = [0.05, 0.95]): float|array
    {
        return match ($name) {
            'mean' => self::mean($x), 'median' => self::median($x), 'sd' => self::sd($x), 'var' => self::var($x), 'mad' => self::mad($x),
            'quantile2' => self::quantile2($x, $probs), 'ess_quantile' => self::essQuantile($x, $probs), 'mcse_quantile' => self::mcseQuantile($x, $probs),
            'rhat' => self::rhat($x), 'rhat_basic' => self::rhatBasic($x), 'ess_bulk' => self::essBulk($x), 'ess_tail' => self::essTail($x), 'ess_basic' => self::essBasic($x),
            'ess_mean' => self::essMean($x), 'ess_sd' => self::essSd($x), 'ess_median' => self::essMedian($x),
            'mcse_mean' => self::mcseMean($x), 'mcse_median' => self::mcseMedian($x), 'mcse_sd' => self::mcseSd($x),
            default => throw new \InvalidArgumentException("Cannot find function '".$name."'."),
        };
    }
    /** Normalize to a list of float chains. */
    public static function chains(array $x): array
    {
        if ($x === []) { return []; }
        $x = array_values($x);
        if (!is_array($x[0])) { return [array_map('floatval', $x)]; }
        return array_map(fn($chain) => array_map('floatval', array_values($chain)), $x);
    }
    private static function matrix(array $x): array
    {
        $chains = self::chains($x);
        if ($chains === [] || count(array_unique(array_map('count', $chains))) !== 1) { throw new \InvalidArgumentException('Convergence diagnostics require chains of equal length.'); }
        return $chains;
    }
    private static function flat(array $x): array { $chains = self::chains($x); return $chains === [] ? [] : array_merge(...$chains); }
    private static function shouldReturnNa(array $values): bool
    {
        if ($values === []) { return true; }
        foreach ($values as $v) { if (!is_finite($v)) { return true; } }
        return abs(max($values) - min($values)) < PHP_FLOAT_EPSILON;
    }
    /** posterior:::.split_chains: halves each chain, dropping the middle draw of odd-length chains. */
    public static function splitChains(array $x): array
    {
        $chains = self::matrix($x); $n = count($chains[0]);
        if ($n === 1) { return $chains; }
        $half = $n / 2; $first = []; $second = [];
        foreach ($chains as $chain) { $first[] = array_slice($chain, 0, (int)floor($half)); $second[] = array_slice($chain, (int)ceil($half + 1) - 1); }
        return array_merge($first, $second);
    }
    /** posterior::z_scale over all draws, keeping the chain structure. */
    private static function zScale(array $chains): array
    {
        $values = array_merge(...$chains); $n = count($values); $ranks = Special::rank($values); $z = []; $i = 0;
        foreach ($chains as $c => $chain) { foreach ($chain as $j => $v) { $z[$c][$j] = is_nan($v) ? NAN : Special::qnorm(($ranks[$i] - 0.375) / ($n - 0.75 + 1)); ++$i; } }
        return $z;
    }
    /** posterior:::.ess, the Geyer initial monotone sequence estimator (CmdStan/posterior agree; see Diagnostics::ess). */
    private static function essRaw(array $chains): float
    {
        if (count($chains[0] ?? []) < 3 || self::shouldReturnNa(array_merge(...$chains))) { return NAN; }
        return Diagnostics::ess($chains);
    }
    /** posterior:::.rhat */
    private static function rhatRaw(array $chains): float
    {
        if (self::shouldReturnNa(array_merge(...$chains))) { return NAN; }
        $n = count($chains[0]); $means = array_map(Special::mean(...), $chains);
        $vars = array_map(function ($chain, $mean) use ($n) { $s = 0.0; foreach ($chain as $v) { $s += ($v - $mean) ** 2; } return $n > 1 ? $s / ($n - 1) : NAN; }, $chains, $means);
        $between = $n * Special::variance($means); $within = Special::mean($vars);
        return sqrt((fdiv($between, $within) + $n - 1) / $n);
    }
    public static function rhat(array $x): float
    {
        $chains = self::matrix($x); $values = array_merge(...$chains);
        foreach ($values as $v) { if (is_nan($v)) { return NAN; } }
        $bulk = self::rhatRaw(self::zScale(self::splitChains($chains)));
        $median = Special::median($values);
        $folded = array_map(fn($chain) => array_map(fn($v) => abs($v - $median), $chain), $chains);
        foreach (array_merge(...$folded) as $v) { if (is_nan($v)) { return NAN; } }
        $tail = self::rhatRaw(self::zScale(self::splitChains($folded)));
        return is_nan($bulk) || is_nan($tail) ? NAN : max($bulk, $tail);
    }
    public static function rhatBasic(array $x, bool $split = true): float { $chains = self::matrix($x); return self::rhatRaw($split ? self::splitChains($chains) : $chains); }
    public static function essBasic(array $x, bool $split = true): float { $chains = self::matrix($x); return self::essRaw($split ? self::splitChains($chains) : $chains); }
    public static function essMean(array $x): float { return self::essRaw(self::splitChains($x)); }
    public static function essBulk(array $x): float
    {
        $chains = self::matrix($x);
        foreach (array_merge(...$chains) as $v) { if (is_nan($v)) { return NAN; } }
        return self::essRaw(self::zScale(self::splitChains($chains)));
    }
    public static function essSd(array $x): float
    {
        $chains = self::matrix($x); $mean = Special::mean(array_merge(...$chains));
        return self::essRaw(self::splitChains(array_map(fn($chain) => array_map(fn($v) => ($v - $mean) ** 2, $chain), $chains)));
    }
    private static function essQuantileAt(array $chains, float $prob): float
    {
        $values = array_merge(...$chains);
        if (self::shouldReturnNa($values)) { return NAN; }
        if ($prob == 1.0) { $len = count($values); $prob = ($len - 0.5) / $len; }
        $threshold = Special::quantile($values, $prob);
        return self::essRaw(self::splitChains(array_map(fn($chain) => array_map(fn($v) => $v <= $threshold ? 1.0 : 0.0, $chain), $chains)));
    }
    /** @return array<string, float> keyed ess_q5, ess_q95, ... */
    public static function essQuantile(array $x, array $probs = [0.05, 0.95]): array
    {
        $chains = self::matrix($x); $out = [];
        foreach ($probs as $p) { self::checkProb($p); $out['ess_q'.self::percent((float)$p)] = self::essQuantileAt($chains, (float)$p); }
        return $out;
    }
    public static function essMedian(array $x): float { return self::essQuantileAt(self::matrix($x), 0.5); }
    public static function essTail(array $x): float
    {
        $chains = self::matrix($x); $low = self::essQuantileAt($chains, 0.05); $high = self::essQuantileAt($chains, 0.95);
        return is_nan($low) || is_nan($high) ? NAN : min($low, $high);
    }
    public static function mcseMean(array $x): float { return fdiv(self::sd($x), sqrt(self::essMean($x))); }
    public static function mcseSd(array $x): float
    {
        $chains = self::matrix($x); $mean = Special::mean(array_merge(...$chains));
        $squared = array_map(fn($chain) => array_map(fn($v) => ($v - $mean) ** 2, $chain), $chains); $flat = array_merge(...$squared);
        $ess = self::essMean($squared); $evar = Special::mean($flat);
        $varvar = fdiv(Special::mean(array_map(fn($v) => $v * $v, $flat)) - $evar ** 2, $ess);
        return sqrt(fdiv($varvar, $evar) / 4);
    }
    private static function mcseQuantileAt(array $chains, float $prob): float
    {
        $ess = self::essQuantileAt($chains, $prob);
        if (is_nan($ess)) { return NAN; }
        $a1 = Special::qbeta(0.1586553, $ess * $prob + 1, $ess * (1 - $prob) + 1); $a2 = Special::qbeta(0.8413447, $ess * $prob + 1, $ess * (1 - $prob) + 1);
        $sorted = array_merge(...$chains); sort($sorted, SORT_NUMERIC); $s = count($sorted);
        return ($sorted[min((int)ceil($a2 * $s), $s) - 1] - $sorted[max((int)floor($a1 * $s), 1) - 1]) / 2;
    }
    /** @return array<string, float> keyed mcse_q5, mcse_q95, ... */
    public static function mcseQuantile(array $x, array $probs = [0.05, 0.95]): array
    {
        $chains = self::matrix($x); $out = [];
        foreach ($probs as $p) { self::checkProb($p); $out['mcse_q'.self::percent((float)$p)] = self::mcseQuantileAt($chains, (float)$p); }
        return $out;
    }
    public static function mcseMedian(array $x): float { return self::mcseQuantileAt(self::matrix($x), 0.5); }
    public static function mean(array $x): float { return Special::mean(self::flat($x)); }
    public static function median(array $x): float { return Special::median(self::flat($x)); }
    public static function var(array $x): float
    {
        $values = self::flat($x);
        foreach ($values as $v) { if (is_nan($v)) { return NAN; } }
        return Special::variance($values);
    }
    public static function sd(array $x): float { return sqrt(self::var($x)); }
    /** stats::mad with constant 1.4826. */
    public static function mad(array $x): float
    {
        $values = self::flat($x); $median = Special::median($values);
        return 1.4826 * Special::median(array_map(fn($v) => abs($v - $median), $values));
    }
    /** @return array<string, float> keyed q5, q95, ...; all NAN when draws contain NaN. */
    public static function quantile2(array $x, array $probs = [0.05, 0.95]): array
    {
        $values = self::flat($x); $missing = count(array_filter($values, 'is_nan')) > 0; $out = [];
        if (!$missing) { sort($values, SORT_NUMERIC); }
        foreach ($probs as $p) { self::checkProb($p); $out['q'.self::percent((float)$p)] = $missing ? NAN : Special::quantile($values, (float)$p, true); }
        return $out;
    }
    private static function checkProb(mixed $p): void { if (!is_numeric($p) || $p < 0 || $p > 1) { throw new \InvalidArgumentException("'probs' must contain values between 0 and 1."); } }
    /** R's paste0(probs * 100) formatting (15 significant digits). */
    public static function percent(float $p): string { return sprintf('%.15g', $p * 100); }
}
