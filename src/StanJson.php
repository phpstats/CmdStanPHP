<?php
declare(strict_types=1);
namespace CmdStan;
final class StanJson
{
    /** $data is an array of Stan variables, a StanData, or a table (an object with toModelData() or toColumns()). */
    public static function write(array|object $data, string $file): string
    {
        $data = StanData::normalize($data);
        if (!is_array($data)) { throw new \InvalidArgumentException('Expected Stan data as an array, StanData, or a table with toColumns().'); }
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) { throw new \InvalidArgumentException('Stan data must have valid variable names.'); }
            self::shape($value);
        }
        $data = self::normalize($data);
        $json = json_encode((object)$data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT);
        if (file_put_contents($file, $json."\n", LOCK_EX) === false) { throw new \RuntimeException('Cannot write JSON: '.$file); }
        return realpath($file) ?: $file;
    }
    private static function normalize(mixed $value): mixed
    {
        if (is_bool($value)) { return (int)$value; }
        if (is_array($value)) { return array_map(self::normalize(...), $value); }
        return $value;
    }
    private static function shape(mixed $value): array
    {
        if (is_array($value)) {
            if (!array_is_list($value)) { throw new \InvalidArgumentException('Stan arrays must be contiguous, zero-indexed lists.'); }
            $shape = null;
            foreach ($value as $element) {
                $current = self::shape($element);
                if ($shape !== null && $shape !== $current) { throw new \InvalidArgumentException('Stan arrays must be rectangular.'); }
                $shape = $current;
            }
            return array_merge([count($value)], $shape ?? []);
        }
        if ((!is_int($value) && !is_float($value) && !is_bool($value)) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Stan JSON accepts only finite numbers, booleans, and rectangular arrays.');
        }
        return [];
    }
}
