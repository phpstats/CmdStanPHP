<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * draws_rvars following the R source of posterior::as_draws_rvars (BSD 3-Clause).
 *
 * Returns variable base name => ['dims' => list<int>, 'nchains' => int, 'ndraws' => int, 'draws' => nested], in order of
 * first appearance. 'draws' is a zero-based nested array shaped by dims whose leaves are lists of the ndraws draws in
 * chain-major order (all of chain 1, then chain 2, ...). Dimensions are the maximum one-based index seen at each
 * position of flat names such as 'x[2,3]'; positions without a column hold NAN draws (R's NA). Scalars (names without
 * brackets) have dims [] and 'draws' is the list of draws itself, whereas R reports dim 1. Only positive integer indices
 * are supported (Stan's output); posterior's named or zero-based indices are rejected.
 */
final class Rvars
{
    /** @param list<array> $rows draws_df rows; reserved columns starting with '.' are dropped, .chain gives nchains */
    public static function fromDrawsDf(array $rows): array
    {
        $chains = []; $columns = [];
        foreach (array_values($rows) as $i => $row) {
            if (isset($row['.chain'])) { $chains[(int)$row['.chain']] = true; }
            foreach ($row as $name => $value) { if (!str_starts_with((string)$name, '.')) { $columns[(string)$name][$i] = (float)$value; } }
        }
        return self::build($columns, count(array_values($rows)), max(1, count($chains)));
    }
    /** @param array $draws draws_array [iteration][chain][variable => value] */
    public static function fromDrawsArray(array $draws): array
    {
        $draws = array_values($draws); $nchains = count($draws[0] ?? []); $columns = []; $i = 0;
        for ($c = 0; $c < $nchains; ++$c) {
            foreach ($draws as $iteration) {
                foreach ($iteration[$c] ?? throw new \InvalidArgumentException('Every iteration needs the same number of chains.') as $name => $value) { $columns[(string)$name][$i] = (float)$value; }
                ++$i;
            }
        }
        return self::build($columns, $i, max(1, $nchains));
    }
    public static function fromFit(CmdStanFit $fit, array|string|null $variables = null): array
    {
        return self::fromDrawsDf($fit->draws($variables, 'draws_df'));
    }
    /** Values of one zero-based draw, nested by dims (a float for scalars). */
    public static function draw(array $rvar, int $draw): float|array
    {
        if ($draw < 0 || $draw >= $rvar['ndraws']) { throw new \OutOfRangeException('Draw index out of range.'); }
        $pick = function (array $node, int $depth) use (&$pick, $draw, $rvar) {
            return $depth === count($rvar['dims']) ? $node[$draw] : array_map(fn($child) => $pick($child, $depth + 1), $node);
        };
        return $pick($rvar['draws'], 0);
    }
    /** Split 'x[1,2]' into ['x', [1, 2]]; names without brackets give an empty index list. */
    public static function parseName(string $name): array
    {
        if (!preg_match('/^([^\[\]]+)\[([^\[\]]*)\]$/D', $name, $m)) {
            if (str_contains($name, '[') || str_contains($name, ']')) { throw new \InvalidArgumentException('Malformed variable name: '.$name); }
            return [$name, []];
        }
        $indices = [];
        foreach (explode(',', $m[2]) as $index) {
            $index = trim($index);
            if (!preg_match('/^[1-9][0-9]*$/D', $index)) { throw new \InvalidArgumentException('Only positive integer indices are supported: '.$name); }
            $indices[] = (int)$index;
        }
        return [$m[1], $indices];
    }
    private static function build(array $columns, int $ndraws, int $nchains): array
    {
        $groups = [];
        foreach ($columns as $name => $values) {
            [$base, $indices] = self::parseName((string)$name);
            if (isset($groups[$base]) && count($groups[$base][0][1]) !== count($indices)) { throw new \InvalidArgumentException('Inconsistent number of indices for variable '.$base.'.'); }
            if (count($values) !== $ndraws) { throw new \InvalidArgumentException('Variable '.$name.' is missing from some draws.'); }
            $groups[$base][] = [$name, $indices, array_values($values)];
        }
        $out = [];
        foreach ($groups as $base => $entries) {
            if ($entries[0][1] === []) {
                if (count($entries) > 1) { throw new \InvalidArgumentException('Duplicate variable '.$base.'.'); }
                $out[$base] = ['dims' => [], 'nchains' => $nchains, 'ndraws' => $ndraws, 'draws' => $entries[0][2]]; continue;
            }
            $dims = array_fill(0, count($entries[0][1]), 0);
            foreach ($entries as [, $indices]) { foreach ($indices as $d => $index) { $dims[$d] = max($dims[$d], $index); } }
            $draws = self::filled($dims, array_fill(0, $ndraws, NAN));
            foreach ($entries as [, $indices, $values]) {
                $node = &$draws;
                foreach ($indices as $index) { $node = &$node[$index - 1]; }
                $node = $values; unset($node);
            }
            $out[$base] = ['dims' => $dims, 'nchains' => $nchains, 'ndraws' => $ndraws, 'draws' => $draws];
        }
        return $out;
    }
    private static function filled(array $dims, array $leaf): array
    {
        if ($dims === []) { return $leaf; }
        $inner = self::filled(array_slice($dims, 1), $leaf);
        return array_fill(0, $dims[0], $inner);
    }
}
