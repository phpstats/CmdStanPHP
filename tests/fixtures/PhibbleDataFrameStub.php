<?php
declare(strict_types=1);
namespace Phibble;
/** Minimal stand-in for phibble's DataFrame, loaded only in isolated test processes. */
final class DataFrame
{
    private function __construct(public readonly array $columns) {}
    public static function fromColumns(array $columns, array $types = []): self { return new self($columns); }
    public function toColumns(): array { return $this->columns; }
}
