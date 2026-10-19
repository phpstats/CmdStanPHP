<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Posterior-draw kernels from the phit extension, used by Loo::moment_match(). They are detected by function name
 * because the extension's version metadata does not identify releases that include them. Draws are [draw][parameter]
 * lists in one fixed parameter order; results keep draw and parameter order. A failing kernel throws \Exception with
 * phit's code: 1001 invalid input, 1002 incompatible dimensions, 1003 nonfinite input or arithmetic, 1004 singular
 * affine matrix, 1005 covariance not numerically positive definite. Nothing is regularized.
 */
final class DrawKernels
{
    public const FUNCTIONS = ['Phit\draws_moments', 'Phit\draws_covariance_map', 'Phit\draws_affine'];
    public const INVALID = 1001;
    public const NONFINITE = 1003;
    public const SINGULAR_AFFINE = 1004;
    public const SINGULAR_COVARIANCE = 1005;
    /** @return list<string> the kernel functions that are not available */
    public static function missing(array $functions = self::FUNCTIONS): array { return array_values(array_filter($functions, fn($f) => !function_exists($f))); }
    public static function available(): bool { return self::missing() === []; }
    public static function require(): void
    {
        $missing = self::missing();
        if ($missing !== []) { throw new \RuntimeException('Moment matching needs the phit extension with '.implode(', ', $missing).'; load a phit build that includes the posterior-draw kernels.'); }
    }
    /** Mean and covariance; 'unbiased' treats weights as reliability weights and divides by 1 - sum(w^2). @return array{mean: list<float>, covariance: list<list<float>>} */
    public static function moments(array $draws, ?array $weights = null, string $normalization = 'population'): array { self::require(); return \Phit\draws_moments($draws, $weights, $normalization); }
    /** A = L_t L_s^-1, so A * source * A^T = target. @return array{matrix: list<list<float>>, log_abs_det: float} */
    public static function covarianceMap(array $source, array $target): array { self::require(); return \Phit\draws_covariance_map($source, $target); }
    /** y = A x + b for every draw. @return array{draws: list<list<float>>, log_abs_det: float} */
    public static function affine(array $draws, array $matrix, array $shift): array { self::require(); return \Phit\draws_affine($draws, $matrix, $shift); }
    /** y = A (x - from) + to: the affine map sending the point `from` to `to`, with shift to - A * from. */
    public static function affineAbout(array $draws, array $matrix, array $from, array $to): array { return self::affine($draws, $matrix, array_map(fn($t, $m) => $t - $m, $to, self::apply($matrix, $from))); }
    /** Whether a kernel exception reports a numerical failure (nonfinite arithmetic or singularity) rather than invalid input. */
    public static function isNumericalFailure(\Throwable $e): bool { return $e instanceof \Exception && in_array($e->getCode(), [self::NONFINITE, self::SINGULAR_AFFINE, self::SINGULAR_COVARIANCE], true); }
    /** @return list<float> A * x */
    public static function apply(array $matrix, array $x): array
    {
        return array_map(function ($row) use ($x) { $sum = 0.0; foreach ($row as $k => $v) { $sum += $v * $x[$k]; } return $sum; }, $matrix);
    }
    /** @return list<list<float>> A * B for square matrices */
    public static function multiply(array $a, array $b): array
    {
        $n = count($b); $out = [];
        foreach ($a as $i => $row) { for ($j = 0; $j < $n; ++$j) { $sum = 0.0; foreach ($row as $k => $v) { $sum += $v * $b[$k][$j]; } $out[$i][$j] = $sum; } }
        return $out;
    }
    /** @param list<float> $diagonal @return list<list<float>> */
    public static function diagonal(array $diagonal): array
    {
        $n = count($diagonal); $out = [];
        foreach (array_values($diagonal) as $i => $v) { $out[$i] = array_fill(0, $n, 0.0); $out[$i][$i] = (float)$v; }
        return $out;
    }
    public static function identity(int $n): array { return self::diagonal(array_fill(0, $n, 1.0)); }
}
