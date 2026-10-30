<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * CmdStanR's write_stan_json(). With $variables (stanc --info declarations, such as CmdStanModel::variables()['data']),
 * values follow their declarations: int variables become integers (a non-integer is truncated with a warning), a single
 * value for a one-dimensional array becomes a one-element array, and tuples, given as lists of their elements, are
 * written as Stan's {"1": ..., "2": ...} objects. Complex numbers are [real, imaginary] pairs. Without declarations, a
 * tuple cannot be told from a list of arrays, so only numbers, booleans and rectangular arrays are accepted.
 */
final class StanJson
{
    /**
     * @param array|object $data Stan variables, a StanData, or a table (an object with toModelData() or toColumns())
     * @param bool $alwaysDecimal write every value of a non-int variable as a floating-point number
     * @param ?array<string, array{type: mixed, dimensions: int}> $variables declarations by variable name
     */
    public static function write(array|object $data, string $file, bool $alwaysDecimal = false, ?array $variables = null): string
    {
        $data = StanData::normalize($data);
        if (!is_array($data)) { throw new \InvalidArgumentException('Expected Stan data as an array, StanData, or a table with toColumns().'); }
        $out = [];
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) { throw new \InvalidArgumentException('Stan data must have valid variable names.'); }
            $out[$name] = self::convert($value, $name, $variables[$name] ?? null, $alwaysDecimal);
        }
        $json = json_encode((object)$out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT);
        if (file_put_contents($file, $json."\n", LOCK_EX) === false) { throw new \RuntimeException('Cannot write JSON: '.$file); }
        return realpath($file) ?: $file;
    }
    /** One variable, converted following its declaration when there is one. */
    public static function convert(mixed $value, string $name, ?array $declaration = null, bool $alwaysDecimal = false): mixed
    {
        if ($value === null) { throw new \InvalidArgumentException("Variable '".$name."' is NULL."); }
        if ($declaration !== null && is_array($declaration['type'] ?? null)) { return self::tuple($value, $name, $declaration, $alwaysDecimal); }
        if (is_object($value)) { $value = self::table($value, $name, $declaration); }
        $value = self::normalize($value);
        self::shape($value, $name);
        if ($declaration === null) { return $value; }
        if (($declaration['dimensions'] ?? 0) === 1 && !is_array($value)) { $value = [$value]; }
        if (($declaration['type'] ?? null) === 'int') {
            $truncated = false;
            $cast = function (&$x) use (&$truncated) { if (is_float($x)) { if ($x != floor($x)) { $truncated = true; } $x = (int)$x; } };
            if (is_array($value)) { array_walk_recursive($value, $cast); } else { $cast($value); }
            if ($truncated) { trigger_error("A non-integer value was supplied for '".$name."'! It will be truncated to an integer.", E_USER_WARNING); }
        } elseif ($alwaysDecimal) {
            if (is_array($value)) { array_walk_recursive($value, function (&$x) { $x = (float)$x; }); } else { $value = (float)$value; }
        }
        return $value;
    }
    private static function tuple(mixed $value, string $name, array $declaration, bool $alwaysDecimal): array
    {
        if (!is_array($value)) { throw new \InvalidArgumentException("Variable '".$name."' is declared as a tuple and must be a list."); }
        $value = array_is_list($value) ? $value : array_values(self::fromOneBased($value, $name));
        if (($declaration['dimensions'] ?? 0) > 0) {
            $element = ['type' => $declaration['type'], 'dimensions' => $declaration['dimensions'] - 1];
            return array_map(fn($cell) => self::tuple($cell, $name, $element, $alwaysDecimal), $value);
        }
        $types = $declaration['type'];
        if (count($value) !== count($types)) { throw new \InvalidArgumentException("Variable '".$name."' is a tuple with ".count($types).' elements, but '.count($value).' were supplied.'); }
        $out = [];
        foreach ($value as $k => $element) { $out[(string)($k + 1)] = self::convert($element, $name.':'.($k + 1), $types[$k], $alwaysDecimal); }
        return $out;
    }
    /** A tuple given with Stan's keys "1", "2", ... */
    private static function fromOneBased(array $value, string $name): array
    {
        $keys = array_keys($value); sort($keys);
        if ($keys !== range(1, count($value))) { throw new \InvalidArgumentException("Variable '".$name."' is declared as a tuple and must be a list."); }
        ksort($value); return $value;
    }
    /** A table given as one variable's value: a row-major matrix of its numeric, boolean and factor columns. */
    private static function table(object $table, string $name, ?array $declaration): array
    {
        if (!StanData::isTable($table)) { throw new \InvalidArgumentException("Variable '".$name."' is of invalid type."); }
        $columns = StanData::columnsOf($table); $invalid = [];
        foreach ($columns as $column => $c) {
            if ($c['levels'] !== null) {
                if ($declaration !== null && ($declaration['type'] ?? null) !== 'int') { throw new \InvalidArgumentException("A factor was supplied for '".$name."', which is declared as '".(is_string($declaration['type'] ?? null) ? $declaration['type'] : 'tuple')."'."); }
                continue;
            }
            foreach ($c['values'] as $v) { if (!is_int($v) && !is_float($v) && !is_bool($v)) { $invalid[] = $column; break; } }
        }
        if ($invalid !== []) { throw new \InvalidArgumentException("Variable '".$name."' has columns of invalid type: ".implode(', ', $invalid).'.'); }
        $rows = [];
        foreach (array_values($columns) as $j => $c) { foreach ($c['values'] as $i => $v) { $rows[$i][$j] = is_bool($v) ? (int)$v : $v; } }
        return array_values($rows);
    }
    private static function normalize(mixed $value): mixed
    {
        if (is_bool($value)) { return (int)$value; }
        if (is_array($value)) { return array_map(self::normalize(...), $value); }
        return $value;
    }
    private static function shape(mixed $value, string $name): array
    {
        if (is_array($value)) {
            if (!array_is_list($value)) { throw new \InvalidArgumentException('Stan arrays must be contiguous, zero-indexed lists (variable '.$name.').'); }
            $shape = null;
            foreach ($value as $element) {
                if ($element === null) { throw new \InvalidArgumentException("Variable '".$name."' has NA values."); }
                $current = self::shape($element, $name);
                if ($shape !== null && $shape !== $current) { throw new \InvalidArgumentException('Stan arrays must be rectangular (variable '.$name.').'); }
                $shape = $current;
            }
            return array_merge([count($value)], $shape ?? []);
        }
        if (is_float($value) && is_nan($value)) { throw new \InvalidArgumentException("Variable '".$name."' has NA values."); }
        if ((!is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))) { throw new \InvalidArgumentException('Stan JSON accepts only finite numbers, booleans, and rectangular arrays (variable '.$name.').'); }
        return [];
    }
}
