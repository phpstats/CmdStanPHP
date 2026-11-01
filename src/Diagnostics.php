<?php
declare(strict_types=1);
namespace CmdStan;
/** Tail ESS using posterior's inclusive quantile indicators and Geyer's truncation. */
final class Diagnostics
{
    public static function essTail(array $chains): float
    {
        if ($chains === [] || count(array_unique(array_map('count', $chains))) !== 1) { return NAN; }
        $values = array_merge(...$chains);
        if ($values === [] || count(array_filter($values, 'is_finite')) !== count($values) || max($values) - min($values) < PHP_FLOAT_EPSILON) { return NAN; }
        $results = [];
        foreach ([0.05, 0.95] as $p) {
            $threshold = CmdStanFit::quantile($values, $p); $split = [];
            foreach ($chains as $chain) {
                $indicator = array_map(fn($x) => $x <= $threshold ? 1.0 : 0.0, $chain);
                $half = intdiv(count($indicator), 2);
                $split[] = array_slice($indicator, 0, $half); $split[] = array_slice($indicator, -$half);
            }
            $results[] = self::ess($split);
        }
        return is_nan($results[0]) || is_nan($results[1]) ? NAN : min($results);
    }
    public static function ess(array $chains): float
    {
        $m = count($chains); $n = count($chains[0] ?? []);
        if ($m === 0 || $n < 3 || count(array_unique(array_map('count', $chains))) !== 1) { return NAN; }
        $min = INF; $max = -INF;
        foreach ($chains as $chain) {
            foreach ($chain as $x) { if (!is_finite($x)) { return NAN; } if ($x < $min) { $min = $x; } if ($x > $max) { $max = $x; } }
        }
        if ($max - $min < PHP_FLOAT_EPSILON) { return NAN; }
        $means = []; $centered = [];
        foreach ($chains as $i => $chain) {
            $means[$i] = $mean = array_sum($chain) / $n; $c = [];
            foreach ($chain as $x) { $c[] = $x - $mean; }
            $centered[] = $c;
        }
        $covariance = static function (int $lag) use ($centered, $m, $n): float {
            $sum = 0.0; $last = $n - $lag;
            foreach ($centered as $chain) { for ($i = 0, $j = $lag; $i < $last; ++$i, ++$j) { $sum += $chain[$i] * $chain[$j]; } }
            return $sum / ($n * $m);
        };
        $within = $covariance(0) * $n / ($n - 1);
        $variance = $within * ($n - 1) / $n;
        if ($m > 1) {
            $mean = array_sum($means) / $m;
            $variance += array_sum(array_map(fn($x) => ($x - $mean) ** 2, $means)) / ($m - 1);
        }
        if ($variance <= 0) { return NAN; }
        $rho = array_fill(0, $n, 0.0); $even = 1.0; $rho[0] = $even;
        $odd = 1 - ($within - $covariance(1)) / $variance; $rho[1] = $odd; $t = 0;
        while ($t < $n - 5 && is_finite($even + $odd) && $even + $odd > 0) {
            $t += 2;
            $even = 1 - ($within - $covariance($t)) / $variance;
            $odd = 1 - ($within - $covariance($t + 1)) / $variance;
            if ($even + $odd >= 0) { $rho[$t] = $even; $rho[$t + 1] = $odd; }
        }
        $maxLag = $t;
        if ($even > 0) { $rho[$maxLag] = $even; }
        for ($t = 2; $t <= $maxLag - 2; $t += 2) {
            $previous = $rho[$t - 2] + $rho[$t - 1];
            if ($rho[$t] + $rho[$t + 1] > $previous) { $rho[$t] = $rho[$t + 1] = $previous / 2; }
        }
        $total = $m * $n;
        $tau = -1 + 2 * array_sum(array_slice($rho, 0, max(1, $maxLag))) + $rho[$maxLag];
        return $total / max($tau, 1 / log10($total));
    }
}
