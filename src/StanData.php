<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Stan data built from data frames and named columns.
 *
 * A table is any object with toModelData() or toColumns(), such as a phibble Phibble\Frame; phibble is not a dependency.
 * Conversion rules (fromTable(), and every model method's data argument):
 * - int and float columns become Stan arrays unchanged; booleans become 0/1; list cells (arrays) stay nested arrays.
 * - Factor columns become 1-based integer indices, in level order, and add J_<name> (the number of levels). Levels come
 *   from a toModelData() factor descriptor ['levels' => list<string>, 'codes' => list<?int>, 'ordered' => bool], whose
 *   codes are 0-based as phibble's Frame::toModelData() returns them (phibble 93858fa and later) (a 'base' => 1 entry marks 1-based codes), or
 *   from the table's levels(name) when toColumns() is used and types() reports a factor. String columns are
 *   treated as factors with sorted unique levels (strcmp order, as phibble's and R's factor() defaults). The labels
 *   are kept in $levels; they are not written to the Stan JSON, which holds numbers only.
 * - N, the row count, is added unless the table has a column named N. J_<name> is added unless that name is taken.
 * - Missing values (null), dates, and other objects are rejected, since Stan has no missing values.
 *
 * fromColumns() and fromFormula() build the usual regression data block: N, K, X (N x K rows), y, and per grouping
 * factor g the indices g_id (1..J_g) and J_g, plus J when there is exactly one grouping factor. fromFormula() also
 * maps random slopes (Z_g, Q_g), factor and cbind() responses, and offsets; see fromModelMatrix().
 */
final class StanData
{
    /**
     * @param array<string, mixed> $data Stan data, ready for write_stan_json()
     * @param array<string, list<string>> $levels Labels of each factor or group, by data variable; index i is level i + 1
     * @param list<string> $columns Names of the columns of X, when the data has a design matrix
     * @param array $meta Extra bookkeeping, such as the source rows kept by stats_model_matrix()
     */
    public function __construct(public readonly array $data, public readonly array $levels = [], public readonly array $columns = [], public readonly array $meta = []) {}
    public function toArray(): array { return $this->data; }
    /** A copy with additional or replaced data variables, e.g. ->with(['prior_scale' => 2.5]). */
    public function with(array $data): self { return new self(array_merge($this->data, $data), $this->levels, $this->columns, $this->meta); }
    /** True for objects that can be converted to Stan data: a StanData, or a table with toModelData() or toColumns(). */
    public static function isTable(mixed $value): bool { return is_object($value) && (method_exists($value, 'toModelData') || method_exists($value, 'toColumns')); }
    /** Data argument normalization: null and arrays pass through; StanData and tables become Stan data arrays. */
    public static function normalize(mixed $data): mixed
    {
        if ($data instanceof self) { return $data->data; }
        if (self::isTable($data)) { return self::fromTable($data)->data; }
        return $data;
    }
    /** Converts a table to Stan data; see the class description for the conventions. */
    public static function fromTable(object $table, bool $add_n = true): self
    {
        $data = []; $levels = []; $n = null;
        foreach (self::tableColumns($table) as $name => $column) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) { throw new \InvalidArgumentException("Column '".$name."' is not a valid Stan variable name; rename it first."); }
            $values = $column['values'];
            if ($n !== null && count($values) !== $n) { throw new \InvalidArgumentException('Table columns must have equal lengths.'); }
            $n = count($values);
            if ($column['levels'] !== null) { $levels[$name] = $column['levels']; $data[$name] = $values; }
            else { $data[$name] = array_map(fn($value, $row) => self::cell($value, $name, $row), $values, array_keys($values)); }
        }
        foreach ($levels as $name => $labels) { $data['J_'.$name] ??= count($labels); }
        if ($add_n && !array_key_exists('N', $data)) { $data = ['N' => $n ?? 0] + $data; }
        return new self($data, $levels);
    }
    /**
     * Regression data from named columns or a table: N, K, X, y, and for each grouping column g: g_id (1..J_g) and J_g,
     * plus J for a single grouping column. Predictors must be numeric (use fromFormula() for factor predictors and
     * interactions). $intercept prepends a column of ones named '(Intercept)'; it is off by default because Stan
     * programs usually give the intercept its own parameter.
     *
     * @param array<string, list<mixed>>|object $columns
     * @param list<string>|string $x predictor column names, in X column order
     * @param list<string>|string $groups grouping column names
     */
    public static function fromColumns(array|object $columns, string $y, array|string $x = [], array|string $groups = [], bool $intercept = false): self
    {
        $table = is_object($columns) ? self::tableColumns($columns, [$y, ...(array)$x, ...(array)$groups]) : array_map(fn($values) => ['values' => self::listOf($values), 'levels' => null], $columns);
        $get = fn(string $name) => $table[$name] ?? throw new \InvalidArgumentException('Unknown column: '.$name);
        $yValues = $get($y); $n = count($yValues['values']);
        if ($yValues['levels'] !== null) { throw new \InvalidArgumentException("Outcome '".$y."' must be numeric."); }
        $names = (array)$x; $predictors = [];
        foreach ($names as $name) {
            $column = $get($name);
            if ($column['levels'] !== null || count($column['values']) !== $n) { throw new \InvalidArgumentException("Predictor '".$name."' must be a numeric column with one value per row; use fromFormula() for factor predictors."); }
            $predictors[] = array_map(fn($value, $row) => (float)self::cell($value, $name, $row, true), $column['values'], array_keys($column['values']));
        }
        $rows = [];
        for ($i = 0; $i < $n; ++$i) { $rows[] = $intercept ? [1.0] : []; foreach ($predictors as $values) { $rows[$i][] = $values[$i]; } }
        $groupData = [];
        foreach ((array)$groups as $g) {
            $column = $get($g);
            if (count($column['values']) !== $n) { throw new \InvalidArgumentException("Group '".$g."' needs one value per row."); }
            $groupData[$g] = $column['levels'] === null ? self::factor($column['values'], $g) : ['index' => $column['values'], 'levels' => $column['levels']];
        }
        return self::assemble($n, $rows, $intercept ? ['(Intercept)', ...$names] : $names, array_map(fn($value, $row) => self::cell($value, $y, $row, true), $yValues['values'], array_keys($yValues['values'])), $groupData);
    }
    /**
     * Regression data from an R-style formula, e.g. 'y ~ x1 + f + (1 + x1 | g)', using stats_model_matrix() from the
     * php_stats extension. The result has N, K, X, and y as fromColumns() names them, plus, for each grouping factor g
     * of the random-effect terms, g_id and J_g (and J for a single grouping factor), and Z_g and Q_g when the terms
     * on g have columns other than the intercept. See fromModelMatrix() for the mapping. $missing is passed to
     * stats_model_matrix(): 'drop' removes incomplete rows with one E_WARNING, and 'raise' rejects them.
     * $drop_intercept removes the '(Intercept)' column of X for programs with a separate intercept parameter.
     *
     * @param array<string, mixed>|object $data columns, factor descriptors, or a table (toModelData(), or toColumns()
     *     with types() and levels() for factors). Factors reach stats_model_matrix() as 0-based descriptors, so their
     *     level order sets the reference level, a factor response's coding, and the order of group indices. Missing
     *     values are left for $missing to handle.
     */
    public static function fromFormula(string $formula, array|object $data, bool $drop_intercept = false, string $missing = 'drop'): self
    {
        if (!function_exists('stats_model_matrix')) { throw new \RuntimeException('StanData::fromFormula() needs stats_model_matrix() from the php_stats extension (https://github.com/erikwestlund/php-stats), which is not loaded or predates the function. Use StanData::fromColumns() without it.'); }
        $levels = []; $columns = [];
        foreach (self::modelColumns($data) as $name => $column) {
            if (is_array($column) && isset($column['levels'], $column['codes'])) { $levels[$name] = $column['levels']; }
            $columns[$name] = $column;
        }
        return self::fromModelMatrix(\stats_model_matrix($formula, $columns, $missing), $drop_intercept, $levels);
    }
    /**
     * Maps a stats_model_matrix() result to Stan data. This is the only code that depends on the result's shape.
     *
     * - x and columns become X and K; N is the number of retained rows.
     * - A numeric response becomes y, as integers when every value is integral (so it suits int and real data). A
     *   factor response (0-based codes plus response_levels) gives y as 0/1 for two levels (Bernoulli) and 1..C with C,
     *   the number of levels, for more (categorical); the labels are in $levels['y']. A cbind(successes, failures)
     *   response gives y (successes) and trials. Without a response there is no y.
     * - offset becomes offset when present.
     * - Each random-effect term's per-row group labels become g_id (1-based) and J_g, where g is the group name with
     *   characters other than letters, digits, and underscores replaced by '_' (so g2:g1 becomes g2_g1). Groups are
     *   numbered in $group_levels[g] order when given (fromFormula() passes factor levels), else over sorted labels
     *   (numbers numerically, otherwise byte order). When g's terms have any column other than '(Intercept)', Z_g
     *   (N x Q_g rows) concatenates the z of every term on g, including intercept-only ones, so '(1 + x | g)' and
     *   '(1 | g) + (0 + x | g)' both give Z_g = [1, x]; Q_g is its width. Two groups that map to the same g are
     *   rejected. J is added for a single grouping factor.
     * - Every other key (retained_rows, dropped_rows, n_input, n_dropped, terms, assign, ...) is kept in $meta, and
     *   $meta['random'][g] holds each group's label, columns, and correlated flag.
     *
     * @param array<string, list<string>> $group_levels level order by group name
     */
    public static function fromModelMatrix(array $mm, bool $drop_intercept = false, array $group_levels = []): self
    {
        foreach (['x', 'columns'] as $key) { if (!array_key_exists($key, $mm)) { throw new \UnexpectedValueException("stats_model_matrix() result lacks '".$key."'."); } }
        $columns = array_values(array_map('strval', $mm['columns'])); $rows = array_map(fn($row) => array_map('floatval', array_values($row)), array_values($mm['x']));
        $drop = $drop_intercept ? array_search('(Intercept)', $columns, true) : false;
        if ($drop !== false) { unset($columns[$drop]); $columns = array_values($columns); $rows = array_map(function ($row) use ($drop) { unset($row[$drop]); return array_values($row); }, $rows); }
        $n = count($rows); $data = ['N' => $n, 'K' => count($columns), 'X' => $rows]; $levels = [];
        $y = $mm['y'] ?? null;
        if ($y !== null) {
            $y = array_values($y);
            if (count($y) !== $n) { throw new \UnexpectedValueException('stats_model_matrix() returned a response of the wrong length.'); }
            if (isset($mm['response_levels'])) {
                $labels = array_values(array_map('strval', $mm['response_levels'])); $binary = count($labels) <= 2;
                $data['y'] = array_map(fn($code) => (int)$code + ($binary ? 0 : 1), $y); $levels['y'] = $labels;
                if (!$binary) { $data['C'] = count($labels); }
            } elseif ($y !== [] && is_array($y[0])) {
                $data['y'] = array_map(fn($pair) => self::integral((float)$pair[0]), $y); $data['trials'] = array_map(fn($pair) => self::integral((float)$pair[0] + (float)$pair[1]), $y);
            } else {
                $integral = array_filter($y, fn($v) => (float)$v != floor((float)$v) || abs((float)$v) > PHP_INT_MAX / 2) === [];
                $data['y'] = array_map(fn($v) => $integral ? (int)$v : (float)$v, $y);
            }
        }
        if (($mm['offset'] ?? null) !== null) { $data['offset'] = array_map('floatval', array_values($mm['offset'])); }
        $groups = []; $random = [];
        foreach ($mm['random'] ?? [] as $term) {
            $group = (string)($term['group'] ?? throw new \UnexpectedValueException('A random-effect term has no group.'));
            $g = preg_replace('/[^A-Za-z0-9_]/', '_', $group);
            if (!preg_match('/^[A-Za-z]/', $g)) { throw new \InvalidArgumentException("Group '".$group."' does not start with a letter, so it cannot name Stan variables."); }
            $labels = array_values($term['groups'] ?? throw new \UnexpectedValueException("Random-effect term '".$group."' has no group labels."));
            if (count($labels) !== $n) { throw new \UnexpectedValueException("Random-effect term '".$group."' has the wrong number of rows."); }
            $termColumns = array_values(array_map('strval', $term['columns'] ?? ['(Intercept)']));
            if (isset($groups[$g]) && $groups[$g]['group'] !== $group) { throw new \InvalidArgumentException("Groups '".$groups[$g]['group']."' and '".$group."' both become Stan name '".$g."'; rename one of them."); }
            $groups[$g] ??= ['group' => $group, 'labels' => $labels, 'z' => array_fill(0, $n, []), 'columns' => [], 'terms' => []];
            $groups[$g]['terms'][] = ['label' => $term['label'] ?? null, 'columns' => $termColumns, 'correlated' => $term['correlated'] ?? true];
            $z = $termColumns === ['(Intercept)'] && !isset($term['z']) ? array_fill(0, $n, [1.0]) : array_values($term['z'] ?? throw new \UnexpectedValueException("Random-effect term '".$group."' has no z."));
            if (count($z) !== $n) { throw new \UnexpectedValueException("Random-effect term '".$group."' has a z with the wrong number of rows."); }
            foreach ($z as $i => $zrow) { $groups[$g]['z'][$i] = array_merge($groups[$g]['z'][$i], array_map('floatval', array_values($zrow))); }
            array_push($groups[$g]['columns'], ...$termColumns);
        }
        foreach ($groups as $g => $group) {
            $order = $group_levels[$group['group']] ?? null;
            if ($order === null) { $factor = self::factor($group['labels'], $group['group']); $index = $factor['index']; $order = $factor['levels']; }
            else { $positions = array_flip($order); $index = array_map(fn($label) => ($positions[(string)$label] ?? throw new \UnexpectedValueException("Group '".$group['group']."' has an unknown level.")) + 1, $group['labels']); }
            $data[$g.'_id'] = $index; $data['J_'.$g] = count($order); $levels[$g.'_id'] = $order;
            if (array_diff($group['columns'], ['(Intercept)']) !== []) { $data['Z_'.$g] = $group['z']; $data['Q_'.$g] = count($group['columns']); }
            $random[$g] = ['group' => $group['group'], 'columns' => $group['columns'], 'terms' => $group['terms']];
        }
        if (count($groups) === 1) { $data['J'] = $data['J_'.array_key_first($groups)]; }
        $meta = array_diff_key($mm, array_flip(['y', 'x', 'columns', 'random', 'response_levels', 'offset'])) + ($random === [] ? [] : ['random' => $random]);
        return new self($data, $levels, $columns, $meta);
    }
    private static function integral(float $v): int|float { return $v == floor($v) && abs($v) < PHP_INT_MAX / 2 ? (int)$v : $v; }
    private static function assemble(int $n, array $rows, array $columns, array $y, array $groups): self
    {
        if (count($rows) !== $n) { throw new \InvalidArgumentException('The design matrix needs one row per outcome.'); }
        $data = ['N' => $n, 'K' => count($columns), 'X' => $rows, 'y' => $y]; $levels = [];
        foreach ($groups as $g => $group) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $g)) { throw new \InvalidArgumentException("Group '".$g."' is not usable in a Stan variable name."); }
            if (count($group['index']) !== $n) { throw new \InvalidArgumentException("Group '".$g."' needs one index per row."); }
            $data[$g.'_id'] = $group['index']; $data['J_'.$g] = count($group['levels']); $levels[$g.'_id'] = $group['levels'];
        }
        if (count($groups) === 1) { $data['J'] = $data['J_'.array_key_first($groups)]; }
        return new self($data, $levels, $columns);
    }
    /**
     * Columns for stats_model_matrix(): factors (descriptors, or toColumns() factors with levels()) become exact
     * 0-based descriptors with nulls kept, and other columns pass through unchanged.
     *
     * @return array<string, mixed>
     */
    private static function modelColumns(array|object $data): array
    {
        [$raw, $types, $table] = is_object($data) ? self::rawColumns($data) : [$data, [], null];
        $out = [];
        foreach ($raw as $name => $values) {
            $name = (string)$name;
            if (is_array($values) && array_key_exists('levels', $values) && array_key_exists('codes', $values)) {
                $labels = array_values(array_map('strval', $values['levels'])); $base = (int)($values['base'] ?? 0);
                if ($base !== 0 && $base !== 1) { throw new \UnexpectedValueException("Factor '".$name."' has an unsupported code base."); }
                $codes = array_map(fn($code) => $code === null ? null : (int)$code - $base, array_values($values['codes']));
                $out[$name] = ['levels' => $labels, 'codes' => $codes, 'ordered' => (bool)($values['ordered'] ?? false)];
                continue;
            }
            if ($table !== null && self::isFactorType($types[$name] ?? null) && method_exists($table, 'levels')) {
                $labels = array_values(array_map('strval', $table->levels($name))); $positions = array_flip($labels); $codes = [];
                foreach (self::listOf($values) as $row => $label) { $codes[] = $label === null ? null : ($positions[(string)$label] ?? throw new \UnexpectedValueException("Factor '".$name."' has an unknown level at row ".($row + 1).'.')); }
                $out[$name] = ['levels' => $labels, 'codes' => $codes, 'ordered' => false];
                continue;
            }
            $out[$name] = $values instanceof \Traversable ? iterator_to_array($values, false) : $values;
        }
        return $out;
    }
    /** @return array{0: array, 1: array, 2: object} a table's raw columns, its types() (toColumns() only), and the table */
    private static function rawColumns(object $table): array
    {
        if (!self::isTable($table)) { throw new \InvalidArgumentException('Expected a table with toModelData() or toColumns().'); }
        $model = method_exists($table, 'toModelData');
        $raw = $model ? $table->toModelData() : $table->toColumns();
        if (!is_array($raw)) { throw new \UnexpectedValueException(($model ? 'toModelData()' : 'toColumns()').' must return an array of columns.'); }
        return [$raw, !$model && method_exists($table, 'types') ? $table->types() : [], $table];
    }
    private static function isFactorType(mixed $type): bool
    {
        $type = $type instanceof \UnitEnum ? ($type instanceof \BackedEnum ? $type->value : $type->name) : $type;
        return is_string($type) && strtolower($type) === 'factor';
    }
    /**
     * @param ?list<string> $only convert just these columns (others, and their missing values, are ignored)
     * @return array<string, array{values: list<mixed>, levels: ?list<string>}> factors hold 1-based indices
     */
    private static function tableColumns(object $table, ?array $only = null): array
    {
        [$raw, $types] = self::rawColumns($table);
        if ($only !== null) { $raw = array_intersect_key($raw, array_flip($only)); }
        $out = [];
        foreach ($raw as $name => $values) {
            $name = (string)$name;
            if (is_array($values) && array_key_exists('levels', $values) && array_key_exists('codes', $values)) {
                $labels = array_values(array_map('strval', $values['levels'])); $base = (int)($values['base'] ?? 0);
                if ($base !== 0 && $base !== 1) { throw new \UnexpectedValueException("Factor '".$name."' has an unsupported code base."); }
                $index = [];
                foreach (array_values($values['codes']) as $row => $code) {
                    if ($code === null) { throw new \InvalidArgumentException("Factor '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.'); }
                    $code = (int)$code + 1 - $base;
                    if ($code < 1 || $code > count($labels)) { throw new \UnexpectedValueException("Factor '".$name."' has a code outside its levels at row ".($row + 1).'.'); }
                    $index[] = $code;
                }
                $out[$name] = ['values' => $index, 'levels' => $labels];
                continue;
            }
            $values = self::listOf($values);
            if (self::isFactorType($types[$name] ?? null) && method_exists($table, 'levels')) {
                $labels = array_values(array_map('strval', $table->levels($name))); $positions = array_flip($labels); $index = [];
                foreach ($values as $row => $label) { $index[] = $label === null ? throw new \InvalidArgumentException("Factor '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.') : ($positions[(string)$label] ?? throw new \UnexpectedValueException("Factor '".$name."' has an unknown level at row ".($row + 1).'.')) + 1; }
                $out[$name] = ['values' => $index, 'levels' => $labels];
            } elseif ($values !== [] && array_filter($values, 'is_string') !== []) {
                $factor = self::factor($values, $name); $out[$name] = ['values' => $factor['index'], 'levels' => $factor['levels']];
            } else { $out[$name] = ['values' => $values, 'levels' => null]; }
        }
        return $out;
    }
    /** 1-based indices over sorted unique labels: numbers numerically, otherwise in strcmp order. */
    private static function factor(array $values, string $name): array
    {
        foreach ($values as $row => $value) {
            if ($value === null) { throw new \InvalidArgumentException("Column '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.'); }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) { throw new \InvalidArgumentException("Column '".$name."' has a value that cannot be a factor level at row ".($row + 1).'.'); }
        }
        $label = fn($v) => is_bool($v) ? ($v ? 'TRUE' : 'FALSE') : (string)$v;
        $unique = []; foreach ($values as $value) { $unique[$label($value)] = $value; }
        $unique = array_values($unique);
        $numeric = array_filter($unique, fn($v) => !is_int($v) && !is_float($v)) === [];
        $numeric ? sort($unique) : usort($unique, fn($a, $b) => strcmp($label($a), $label($b)));
        $levels = array_map($label, $unique); $positions = array_flip($levels);
        return ['index' => array_map(fn($v) => $positions[$label($v)] + 1, $values), 'levels' => $levels];
    }
    private static function cell(mixed $value, string $name, int $row, bool $scalar = false): mixed
    {
        if ($value === null) { throw new \InvalidArgumentException("Column '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.'); }
        if (is_bool($value)) { return (int)$value; }
        if ((is_int($value) || is_float($value)) || (!$scalar && is_array($value))) { return $value; }
        throw new \InvalidArgumentException("Column '".$name."' has a non-numeric value at row ".($row + 1).'.');
    }
    private static function listOf(mixed $values): array
    {
        if ($values instanceof \Traversable) { $values = iterator_to_array($values, false); }
        if (!is_array($values)) { throw new \InvalidArgumentException('Each column must be a list of values.'); }
        return array_values($values);
    }
}
