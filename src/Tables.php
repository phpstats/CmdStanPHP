<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Column-oriented results for data frame libraries. Columns are name => list of values, the shape accepted by
 * phibble's Phibble\Frame::fromColumns(). phibble is optional: frame() uses it only when it is installed.
 */
final class Tables
{
    public const FRAME_CLASS = 'Phibble\\Frame';
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
    /** A Phibble\Frame from columns; throws with installation advice when phibble is not installed. */
    public static function frame(array $columns, string $method = 'to_frame()'): object
    {
        if (!class_exists(self::FRAME_CLASS)) { throw new \RuntimeException($method.' needs the phibble data frame package (composer require erikwestlund/phibble; it requires PHP 8.5). Without it, use the column arrays directly.'); }
        $class = self::FRAME_CLASS;
        return $class::fromColumns($columns);
    }
}
