<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Column-oriented results for data frame libraries. Columns are name => list of values, the shape accepted by
 * phibble's Phibble\DataFrame::fromColumns(). phibble is optional: dataFrame() uses it only when it is installed.
 */
final class Tables
{
    public const DATAFRAME_CLASS = 'Phibble\\DataFrame';
    /**
     * Rows of associative arrays to columns. Columns appear in first-seen order; a row without a column holds null there.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<mixed>>
     */
    public static function fromRows(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) { foreach (array_keys($row) as $name) { $names[(string)$name] = true; } }
        $columns = array_fill_keys(array_keys($names), []);
        foreach ($rows as $row) { foreach ($columns as $name => $_) { $columns[$name][] = $row[$name] ?? null; } }
        return $columns;
    }
    /** A Phibble\DataFrame from columns; throws with installation advice when phibble is not installed. */
    public static function dataFrame(array $columns, string $method = 'draws_table()'): object
    {
        if (!class_exists(self::DATAFRAME_CLASS)) { throw new \RuntimeException($method.' needs the phibble data frame package (composer require phpstats/phibble; it requires PHP 8.5). Without it, use the column arrays directly.'); }
        $class = self::DATAFRAME_CLASS;
        return $class::fromColumns($columns);
    }
}
