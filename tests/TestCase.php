<?php
declare(strict_types=1);
namespace CmdStan\Tests;
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $dir;
    protected function setUp(): void { $this->dir = sys_get_temp_dir().'/cmdstanphp-test-'.bin2hex(random_bytes(8)); mkdir($this->dir, 0700); }
    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->dir);
    }
    /**
     * Asserts that $actual matches the reference $expected value by value: |x − r| ≤ $tolerance, or, when $relative,
     * |x − r| ≤ $tolerance × max(1, |r|). A null reference (R's NA) must be NAN, and "Inf"/"-Inf" must be infinite.
     * With CMDSTANPHP_EVIDENCE set to a directory, the comparison is also written there (see self::record()).
     * $at is [topic, case, quantity].
     */
    protected static function near(array $at, mixed $expected, mixed $actual, float $tolerance, bool $relative = false, string $message = ''): void
    {
        $rule = $relative ? ['kind' => 'tolerance', 'rtol' => $tolerance, 'rel_floor' => 1.0] : ['kind' => 'tolerance', 'atol' => $tolerance];
        $pairs = []; $shape = self::pairs($expected, $actual, '', $pairs);
        $n = 0; $maxAbs = 0.0; $maxRel = 0.0; $failure = $shape;
        foreach ($pairs as [$path, $r, $x]) {
            ++$n;
            if ($r === null || (is_float($r) && is_nan($r))) { $ok = is_float($x) && is_nan($x); $abs = 0.0; }
            elseif (is_string($r) && in_array($r, ['Inf', '-Inf'], true)) { $ok = is_float($x) && is_infinite($x) && ($x > 0) === ($r === 'Inf'); $abs = 0.0; }
            elseif (is_infinite((float)$r)) { $ok = (float)$x === (float)$r; $abs = 0.0; }
            else {
                $abs = abs((float)$x - (float)$r); $rel = $abs / max(1.0, abs((float)$r));
                $ok = is_finite((float)$x) && ($relative ? $abs <= $tolerance * max(1.0, abs((float)$r)) : $abs <= $tolerance);
                $maxAbs = max($maxAbs, $abs); $maxRel = max($maxRel, $rel);
            }
            if (!$ok && $failure === null) { $failure = sprintf('%s%s: expected %s, got %s', $message, $path, var_export($r, true), var_export($x, true)); }
        }
        self::record($at, ['rule' => $rule, 'n_compared' => $n, 'max_abs_error' => $maxAbs, 'max_rel_error' => $maxRel] + ($failure === null ? ['status' => 'pass'] : ['status' => 'fail', 'reason' => $failure]));
        if ($failure !== null) { self::fail($failure); }
        self::assertTrue(true);
    }
    /** Asserts equality, by assertSame or (with $loose) assertEquals, and records the result as an exact comparison. */
    protected static function exact(array $at, mixed $expected, mixed $actual, string $message = '', bool $loose = false): void
    {
        $n = 1; if (is_array($expected)) { $n = 0; array_walk_recursive($expected, function () use (&$n) { ++$n; }); }
        try { $loose ? self::assertEquals($expected, $actual, $message) : self::assertSame($expected, $actual, $message); }
        catch (\PHPUnit\Framework\AssertionFailedError $e) {
            self::record($at, ['rule' => ['kind' => 'exact'], 'n_compared' => $n, 'status' => 'fail', 'reason' => strtok($e->getMessage(), "\n")]);
            throw $e;
        }
        self::record($at, ['rule' => ['kind' => 'exact'], 'n_compared' => $n, 'status' => 'pass']);
    }
    /**
     * With CMDSTANPHP_EVIDENCE set, appends one comparison to <dir>/results.jsonl with the test that made it.
     * verification/compatibility/run.php aggregates these lines into site/verifications records.
     */
    protected static function record(array $at, array $result): void
    {
        $dir = getenv('CMDSTANPHP_EVIDENCE');
        if ($dir === false || $dir === '') { return; }
        $test = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) { if (str_starts_with($frame['function'], 'test') && isset($frame['class'])) { $test = substr(strrchr('\\'.$frame['class'], '\\'), 1).'::'.$frame['function']; break; } }
        [$topic, $case, $quantity] = $at;
        file_put_contents($dir.'/results.jsonl', json_encode(['topic' => $topic, 'case' => $case, 'quantity' => $quantity, 'test' => $test] + $result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n", FILE_APPEND | LOCK_EX);
    }
    /** Pairs up the leaves of a reference and an actual value; returns a message when their shapes differ. */
    private static function pairs(mixed $expected, mixed $actual, string $path, array &$pairs): ?string
    {
        if (!is_array($expected)) { $pairs[] = [$path, $expected, is_array($actual) ? NAN : $actual]; return is_array($actual) ? $path.': expected a scalar, got an array' : null; }
        if (!is_array($actual)) { return $path.': expected an array, got '.get_debug_type($actual); }
        if (count($expected) !== count($actual)) { return $path.': expected '.count($expected).' values, got '.count($actual); }
        $list = array_is_list($expected); $values = array_values($actual); $i = 0; $failure = null;
        foreach ($expected as $key => $value) {
            if (!$list && !array_key_exists($key, $actual)) { return $path.'['.$key.']: missing'; }
            $failure ??= self::pairs($value, $list ? $values[$i] : $actual[$key], $path.'['.$key.']', $pairs); ++$i;
        }
        return $failure;
    }
    protected function csv(string $name = 'chain.csv', string $extra = '', array $rows = []): string
    {
        $file = $this->dir.'/'.$name;
        file_put_contents($file, "# method = sample\n# id = 1\n# num_warmup = 2\n# save_warmup = 1\n# thin = 1\n# max_depth = 10\n".$extra."lp__,theta,x.1,x.2,divergent__,treedepth__,energy__\n-3,0.1,1,2,0,2,1\n-2,0.2,2,3,0,3,2\n# Adaptation terminated\n# Step size = 0.5\n# Diagonal elements of inverse mass matrix:\n# 1,2\n".implode("\n", $rows ?: ['-1,0.3,3,4,0,4,1', '-1,0.4,4,5,1,10,3'])."\n# Elapsed Time: 0.1 seconds (Warm-up)\n# 0.2 seconds (Sampling)\n# 0.3 seconds (Total)\n");
        return $file;
    }
}
