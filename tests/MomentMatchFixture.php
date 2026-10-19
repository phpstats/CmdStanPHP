<?php
declare(strict_types=1);
namespace CmdStan\Tests;
/**
 * Conjugate regression y_j ~ normal(a + b x_j, 1), a, b ~ normal(0, 10), with one high-leverage last observation.
 * Posterior draws are exact (seeded phit normal draws through the posterior Cholesky factor), so log densities
 * and leave-one-out predictive densities are analytic. Rows are chain-major; log_lik is [iteration][chain]['log_lik[j]'], as CmdStanFit::draws() returns it.
 */
final class MomentMatchFixture
{
    public const PRIOR_PRECISION = 0.01;
    /** regression() designs, named by the transformations moment matching accepts for the last observation. */
    public const SHIFT_ONLY = [1.0, 3.0, -5.0, 5];        // shift, shift, shift
    public const SHIFT_SCALE = [1.0, 6.0, -6.0, 6, 3.0];  // shift x5, scale
    public const COV = [1.0, -6.0, 8.0, 6, 3.0];          // scale, cov, shift, shift
    /** @return array{x: list<float>, y: list<float>, upars: list<list<float>>, log_lik: array, chains: int} */
    public static function regression(float $spread, float $leverageX, float $leverageY, int $observations, float $offset = 0.0, int $draws = 4000, int $chains = 4, int $seed = 1): array
    {
        $noise = \Phit\dist_normal_rng($observations, 11); $x = []; $y = [];
        for ($j = 0; $j < $observations; ++$j) { $x[] = $offset + $spread * (-1 + 2 * $j / ($observations - 1)); $y[] = 0.5 + $x[$j] + $noise[$j]; }
        $x[] = $leverageX; $y[] = $leverageY;
        [$mean, $covariance] = self::posterior($x, $y);
        $l00 = sqrt($covariance[0][0]); $l10 = $covariance[1][0] / $l00; $l11 = sqrt($covariance[1][1] - $l10 ** 2);
        $e1 = \Phit\dist_normal_rng($draws, 2 * $seed - 1); $e2 = \Phit\dist_normal_rng($draws, 2 * $seed); $upars = [];
        for ($s = 0; $s < $draws; ++$s) { $upars[] = [$mean[0] + $l00 * $e1[$s], $mean[1] + $l10 * $e1[$s] + $l11 * $e2[$s]]; }
        $perChain = intdiv($draws, $chains); $logLik = []; $names = array_map(fn($j) => 'log_lik['.($j + 1).']', array_keys($x));
        foreach ($upars as $s => $theta) { $logLik[$s % $perChain][intdiv($s, $perChain)] = array_combine($names, array_map(fn($xi, $yi) => self::pointwise($theta, $xi, $yi), $x, $y)); }
        return ['x' => $x, 'y' => $y, 'upars' => $upars, 'log_lik' => $logLik, 'chains' => $chains];
    }
    public static function pointwise(array $theta, float $x, float $y): float { return -0.5 * log(2 * M_PI) - 0.5 * ($y - $theta[0] - $theta[1] * $x) ** 2; }
    /** log posterior density up to a constant; extra columns (if any) are ignored. */
    public static function logProb(array $fixture): \Closure
    {
        return fn(array $rows) => array_map(fn($t) => array_sum(array_map(fn($xi, $yi) => self::pointwise($t, $xi, $yi), $fixture['x'], $fixture['y'])) - 0.5 * self::PRIOR_PRECISION * ($t[0] ** 2 + $t[1] ** 2), $rows);
    }
    public static function logLikI(array $fixture): \Closure
    {
        return fn(array $rows, int $i) => array_map(fn($t) => self::pointwise($t, $fixture['x'][$i], $fixture['y'][$i]), $rows);
    }
    /** Exact log predictive density log p(y_i | y_-i). */
    public static function exactElpd(array $fixture, int $i): float
    {
        $x = $fixture['x']; $y = $fixture['y'];
        [$mean, $cov] = self::posterior(array_values(array_diff_key($x, [$i => 0])), array_values(array_diff_key($y, [$i => 0])));
        $mu = $mean[0] + $mean[1] * $x[$i]; $var = 1 + $cov[0][0] + 2 * $x[$i] * $cov[0][1] + $x[$i] ** 2 * $cov[1][1];
        return -0.5 * log(2 * M_PI * $var) - 0.5 * ($y[$i] - $mu) ** 2 / $var;
    }
    /** @return array{0: list<float>, 1: list<list<float>>} posterior mean and covariance of (a, b) given these observations */
    public static function posterior(array $x, array $y): array
    {
        $n = count($x); $sx = array_sum($x); $sxx = array_sum(array_map(fn($v) => $v * $v, $x));
        $q = [[$n + self::PRIOR_PRECISION, $sx], [$sx, $sxx + self::PRIOR_PRECISION]]; $det = $q[0][0] * $q[1][1] - $q[0][1] ** 2;
        $cov = [[$q[1][1] / $det, -$q[0][1] / $det], [-$q[0][1] / $det, $q[0][0] / $det]];
        $b = [array_sum($y), array_sum(array_map(fn($a, $c) => $a * $c, $x, $y))];
        return [[$cov[0][0] * $b[0] + $cov[0][1] * $b[1], $cov[1][0] * $b[0] + $cov[1][1] * $b[1]], $cov];
    }
}
