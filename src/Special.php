<?php
declare(strict_types=1);
namespace CmdStan;
/** @internal Numerical primitives following R (stats, GPL-2 or later) and matrixStats (Artistic-2.0), for the posterior and loo classes. */
final class Special
{
    private const LN_SQRT_2PI = 0.918938533204672741780329736406;
    /** R's mean(): sum/n followed by a correction pass. */
    public static function mean(array $x): float
    {
        $n = count($x);
        if ($n === 0) { return NAN; }
        $m = array_sum($x) / $n;
        if (!is_finite($m)) { return $m; }
        $t = 0.0; foreach ($x as $v) { $t += $v - $m; }
        return $m + $t / $n;
    }
    /** Sample variance with denominator n - 1 (stats::var). */
    public static function variance(array $x): float
    {
        $n = count($x);
        if ($n < 2) { return NAN; }
        $m = self::mean($x); $s = 0.0;
        foreach ($x as $v) { $s += ($v - $m) ** 2; }
        return $s / ($n - 1);
    }
    /** stats::median: middle value, or the mean of the two middle values. */
    public static function median(array $x): float
    {
        $n = count($x);
        if ($n === 0) { return NAN; }
        foreach ($x as $v) { if (is_nan($v)) { return NAN; } }
        sort($x, SORT_NUMERIC); $half = intdiv($n + 1, 2);
        return $n % 2 === 1 ? $x[$half - 1] : self::mean([$x[$half - 1], $x[$half]]);
    }
    /** stats::quantile type 7, interpolating only between distinct order statistics. */
    public static function quantile(array $x, float $p, bool $sorted = false): float
    {
        $n = count($x);
        if ($n === 0) { return NAN; }
        if (!$sorted) { foreach ($x as $v) { if (is_nan($v)) { throw new \InvalidArgumentException('Missing values are not allowed in quantiles.'); } } sort($x, SORT_NUMERIC); }
        $p = max(0.0, min(1.0, $p)); $index = 1 + ($n - 1) * $p; $lo = (int)floor($index); $hi = (int)ceil($index);
        $q = $x[$lo - 1];
        if ($index > $lo && $x[$hi - 1] != $q) { $h = $index - $lo; $q = (1 - $h) * $q + $h * $x[$hi - 1]; }
        return $q;
    }
    /** Average ranks (rank(ties.method = "average")), one-based. */
    public static function rank(array $x): array
    {
        $order = $x; asort($order, SORT_NUMERIC); $keys = array_keys($order); $n = count($keys); $ranks = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n;) {
            $j = $i; while ($j + 1 < $n && $x[$keys[$j + 1]] == $x[$keys[$i]]) { ++$j; }
            $avg = ($i + $j + 2) / 2;
            for ($k = $i; $k <= $j; ++$k) { $ranks[$keys[$k]] = $avg; }
            $i = $j + 1;
        }
        return $ranks;
    }
    /** matrixStats::logSumExp. */
    public static function logSumExp(array $x): float
    {
        if ($x === []) { return -INF; }
        $max = -INF;
        foreach ($x as $v) { if (is_nan($v)) { return NAN; } if ($v > $max) { $max = $v; } }
        if (!is_finite($max)) { return $max; }
        $s = 0.0; foreach ($x as $v) { $s += exp($v - $max); }
        return $max + log($s);
    }
    /** Wichura's AS241 (R's qnorm for the unit normal). */
    public static function qnorm(float $p): float
    {
        if (is_nan($p) || $p < 0 || $p > 1) { return NAN; }
        if ($p == 0.0) { return -INF; }
        if ($p == 1.0) { return INF; }
        $q = $p - 0.5;
        if (abs($q) <= 0.425) {
            $r = 0.180625 - $q * $q;
            return $q * ((((((($r * 2509.0809287301226727 + 33430.575583588128105) * $r + 67265.770927008700853) * $r + 45921.953931549871457) * $r + 13731.693765509461125) * $r + 1971.5909503065514427) * $r + 133.14166789178437745) * $r + 3.387132872796366608)
                / ((((((($r * 5226.495278852545925 + 28729.085735721942674) * $r + 39307.89580009271061) * $r + 21213.794301586595867) * $r + 5394.1960214247511077) * $r + 687.1870074920579083) * $r + 42.313330701600911252) * $r + 1.0);
        }
        $r = sqrt(-log($q < 0 ? $p : 1 - $p));
        if ($r <= 5) {
            $r -= 1.6;
            $val = ((((((($r * 7.7454501427834140764e-4 + .0227238449892691845833) * $r + .24178072517745061177) * $r + 1.27045825245236838258) * $r + 3.64784832476320460504) * $r + 5.7694972214606914055) * $r + 4.6303378461565452959) * $r + 1.42343711074968357734)
                / ((((((($r * 1.05075007164441684324e-9 + 5.475938084995344946e-4) * $r + .0151986665636164571966) * $r + .14810397642748007459) * $r + .68976733498510000455) * $r + 1.6763848301838038494) * $r + 2.05319162663775882187) * $r + 1.0);
        } else {
            $r -= 5;
            $val = ((((((($r * 2.01033439929228813265e-7 + 2.71155556874348757815e-5) * $r + .0012426609473880784386) * $r + .026532189526576123093) * $r + .29656057182850489123) * $r + 1.7848265399172913358) * $r + 5.4637849111641143699) * $r + 6.6579046435011037772)
                / ((((((($r * 2.04426310338993978564e-15 + 1.4215117583164458887e-7) * $r + 1.8463183175100546818e-5) * $r + 7.868691311456132591e-4) * $r + .0148753612908506148525) * $r + .13692988092273580531) * $r + .59983220655588793769) * $r + 1.0);
        }
        return $q < 0 ? -$val : $val;
    }
    /** Stirling series remainder lgamma(x) - ((x - 0.5) log x - x + log sqrt(2 pi)), x >= 10. */
    private static function lgammacor(float $x): float
    {
        $x2 = 1 / ($x * $x);
        return (1 / 12 + $x2 * (-1 / 360 + $x2 * (1 / 1260 + $x2 * (-1 / 1680 + $x2 * (1 / 1188 + $x2 * (-691 / 360360 + $x2 / 156)))))) / $x;
    }
    /** log(gamma(x)) for x > 0 (upward recurrence to the Stirling series); NAN otherwise. */
    public static function lgamma(float $x): float
    {
        if (is_nan($x) || $x <= 0) { return NAN; }
        $shift = 0.0;
        while ($x < 10) { $shift += log($x); $x += 1; }
        return ($x - 0.5) * log($x) - $x + self::LN_SQRT_2PI + self::lgammacor($x) - $shift;
    }
    /** log(beta(a, b)) following R's lbeta. */
    public static function lbeta(float $a, float $b): float
    {
        $p = min($a, $b); $q = max($a, $b);
        if ($p >= 10) {
            $corr = self::lgammacor($p) + self::lgammacor($q) - self::lgammacor($p + $q);
            return -0.5 * log($q) + self::LN_SQRT_2PI + $corr + ($p - 0.5) * log($p / ($p + $q)) + $q * log1p(-$p / ($p + $q));
        }
        if ($q >= 10) {
            $corr = self::lgammacor($q) - self::lgammacor($p + $q);
            return self::lgamma($p) + $corr + $p - $p * log($p + $q) + ($q - 0.5) * log1p(-$p / ($p + $q));
        }
        return self::lgamma($p) + self::lgamma($q) - self::lgamma($p + $q);
    }
    /** Regularized incomplete beta function I_x(a, b). */
    public static function pbeta(float $x, float $a, float $b): float
    {
        if ($x <= 0) { return 0.0; }
        if ($x >= 1) { return 1.0; }
        if ($x > ($a + 1) / ($a + $b + 2)) { return 1 - self::pbeta(1 - $x, $b, $a); }
        $front = exp($a * log($x) + $b * log1p(-$x) - self::lbeta($a, $b)) / $a;
        $tiny = 1e-300; $c = 1.0; $d = 1 - ($a + $b) * $x / ($a + 1); $d = abs($d) < $tiny ? $tiny : $d; $d = 1 / $d; $f = $d;
        for ($m = 1; $m <= 100000; ++$m) {
            $num = $m * ($b - $m) * $x / (($a + 2 * $m - 1) * ($a + 2 * $m));
            $d = 1 + $num * $d; $d = abs($d) < $tiny ? $tiny : $d; $c = 1 + $num / $c; $c = abs($c) < $tiny ? $tiny : $c; $d = 1 / $d; $f *= $c * $d;
            $num = -($a + $m) * ($a + $b + $m) * $x / (($a + 2 * $m) * ($a + 2 * $m + 1));
            $d = 1 + $num * $d; $d = abs($d) < $tiny ? $tiny : $d; $c = 1 + $num / $c; $c = abs($c) < $tiny ? $tiny : $c; $d = 1 / $d; $delta = $c * $d; $f *= $delta;
            if (abs($delta - 1) < 1e-16) { break; }
        }
        return $front * $f;
    }
    /** Inverse of pbeta by safeguarded Newton iteration. */
    public static function qbeta(float $p, float $a, float $b): float
    {
        if (is_nan($p) || is_nan($a) || is_nan($b) || $a <= 0 || $b <= 0 || $p < 0 || $p > 1) { return NAN; }
        if ($p == 0.0 || $p == 1.0) { return $p; }
        $lo = 0.0; $hi = 1.0; $mean = $a / ($a + $b); $sd = sqrt($a * $b / (($a + $b) ** 2 * ($a + $b + 1)));
        $x = min(1 - 1e-12, max(1e-12, $mean + self::qnorm($p) * $sd)); $lbeta = self::lbeta($a, $b);
        for ($i = 0; $i < 1000; ++$i) {
            $f = self::pbeta($x, $a, $b) - $p;
            if ($f == 0.0) { return $x; }
            if ($f < 0) { $lo = $x; } else { $hi = $x; }
            $density = exp(($a - 1) * log($x) + ($b - 1) * log1p(-$x) - $lbeta);
            $next = $density > 0 && is_finite($density) ? $x - $f / $density : NAN;
            if (!is_finite($next) || $next <= $lo || $next >= $hi) { $next = ($lo + $hi) / 2; }
            if (abs($next - $x) <= 1e-15 * max($x, 1e-300) || $hi - $lo <= 1e-16 * $hi) { return $next; }
            $x = $next;
        }
        return $x;
    }
}
