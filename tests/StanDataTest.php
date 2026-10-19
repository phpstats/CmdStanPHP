<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanModel, StanData};
use PHPUnit\Framework\Attributes\{DataProvider, RunInSeparateProcess};
use function CmdStan\{write_stan_file, write_stan_json};
final class StanDataTest extends TestCase
{
    /** A table shaped like a phibble Frame: toColumns() gives factor labels, types() and levels() describe factors. */
    private static function frame(array $columns, array $types = [], array $levels = []): object
    {
        return new class($columns, $types, $levels) {
            public function __construct(private array $columns, private array $types, private array $levels) {}
            public function toColumns(): array { return $this->columns; }
            public function types(): array { return $this->types; }
            public function levels(string $name): array { return $this->levels[$name]; }
        };
    }
    private static function modelData(array $columns): object
    {
        return new class($columns) {
            public function __construct(private array $columns) {}
            public function toModelData(): array { return $this->columns; }
            public function toColumns(): array { throw new \LogicException('toModelData() must be preferred.'); }
        };
    }
    public function testNumericColumnsBecomeArraysAndNIsAdded(): void
    {
        $data = StanData::fromTable(self::frame(['y' => [0, 1, 1], 'x' => [0.5, 1.5, -2.0], 'flag' => [true, false, true], 'v' => [[1, 2], [3, 4], [5, 6]]]));
        self::assertSame(['N' => 3, 'y' => [0, 1, 1], 'x' => [0.5, 1.5, -2.0], 'flag' => [1, 0, 1], 'v' => [[1, 2], [3, 4], [5, 6]]], $data->data);
        self::assertSame([], $data->levels);
        self::assertSame(['n' => [1, 2], 'N' => [7, 7]], StanData::fromTable(self::frame(['n' => [1, 2], 'N' => [7, 7]]))->data, 'an existing N column is kept');
        self::assertSame(['y' => [1]], StanData::fromTable(self::frame(['y' => [1]]), add_n: false)->data);
    }
    public function testFactorColumnsUseTableLevelsAndAreOneBased(): void
    {
        $data = StanData::fromTable(self::frame(['g' => ['b', 'a', 'c', 'b'], 'y' => [1.0, 2.0, 3.0, 4.0]], ['g' => 'factor', 'y' => 'float'], ['g' => ['c', 'b', 'a']]));
        self::assertSame(['N' => 4, 'g' => [2, 3, 1, 2], 'y' => [1.0, 2.0, 3.0, 4.0], 'J_g' => 3], $data->data);
        self::assertSame(['g' => ['c', 'b', 'a']], $data->levels);
        $enum = StanData::fromTable(self::frame(['g' => ['x', 'y']], ['g' => FactorType::Factor], ['g' => ['y', 'x']]));
        self::assertSame([2, 1], $enum->data['g'], 'backed enum types such as Phibble\Type::Factor are recognized');
    }
    public function testStringColumnsBecomeFactorsWithSortedLevels(): void
    {
        $data = StanData::fromTable(self::frame(['school' => ['b', 'a', 'b', 'c'], 'J_school' => [9, 9, 9, 9]]));
        self::assertSame([2, 1, 2, 3], $data->data['school']); self::assertSame(['a', 'b', 'c'], $data->levels['school']);
        self::assertSame([9, 9, 9, 9], $data->data['J_school'], 'J_<name> never replaces a column');
    }
    public function testModelDataFactorDescriptorsArePreferred(): void
    {
        $data = StanData::fromTable(self::modelData(['g' => ['levels' => ['lo', 'hi'], 'codes' => [1, 0, 1], 'ordered' => false], 'h' => ['levels' => ['a', 'b'], 'codes' => [1, 2, 2], 'base' => 1], 'y' => [1, 2, 3]]));
        self::assertSame(['N' => 3, 'g' => [2, 1, 2], 'h' => [1, 2, 2], 'y' => [1, 2, 3], 'J_g' => 2, 'J_h' => 2], $data->data);
        self::assertSame(['g' => ['lo', 'hi'], 'h' => ['a', 'b']], $data->levels);
    }
    public static function invalidTables(): array
    {
        return [
            'missing number' => [['y' => [1, null]], 'missing at row 2'],
            'missing label' => [['g' => ['a', null]], 'missing at row 2'],
            'bad name' => [['my col' => [1]], 'not a valid Stan variable name'],
            'ragged' => [['a' => [1, 2], 'b' => [1]], 'equal lengths'],
            'date' => [['d' => [new \DateTimeImmutable('2024-01-01')]], 'non-numeric value at row 1'],
            'missing code' => [['__model' => true, 'g' => ['levels' => ['a'], 'codes' => [0, null]]], 'missing at row 2'],
            'code outside levels' => [['__model' => true, 'g' => ['levels' => ['a'], 'codes' => [1]]], 'outside its levels'],
        ];
    }
    #[DataProvider('invalidTables')]
    public function testInvalidTablesNameTheProblem(array $columns, string $message): void
    {
        $model = isset($columns['__model']); unset($columns['__model']);
        $this->expectExceptionMessage($message);
        StanData::fromTable($model ? self::modelData($columns) : self::frame($columns));
    }
    public function testNormalizeAndJsonWriterAcceptTables(): void
    {
        self::assertNull(StanData::normalize(null)); self::assertSame(['N' => 1], StanData::normalize(['N' => 1]));
        self::assertSame(['a' => 1], StanData::normalize(new StanData(['a' => 1], ['a' => ['x']])));
        $file = write_stan_json(self::frame(['g' => ['b', 'a']]), $this->dir.'/table.json');
        self::assertSame(['N' => 2, 'g' => [2, 1], 'J_g' => 2], json_decode(file_get_contents($file), true));
        $file = write_stan_json(StanData::fromColumns(['y' => [1.0], 'x' => [2]], 'y', 'x'), $this->dir.'/columns.json');
        self::assertSame(['N' => 1, 'K' => 1, 'X' => [[2.0]], 'y' => [1.0]], json_decode(file_get_contents($file), true));
        $this->expectException(\InvalidArgumentException::class); write_stan_json(new \stdClass(), $this->dir.'/bad.json');
    }
    public function testModelMethodsWriteTableData(): void
    {
        $model = new CmdStanModel(write_stan_file('model {}', $this->dir.'/model.stan'), __DIR__.'/fixtures/model.php');
        $fit = $model->sample(data: self::frame(['y' => [0, 1], 'g' => ['b', 'a']]), chains: 1, output_dir: $this->dir, show_messages: false, diagnostics: null);
        self::assertSame(['N' => 2, 'y' => [0, 1], 'g' => [2, 1], 'J_g' => 2], json_decode(file_get_contents($fit->data_file()), true));
        $fit = $model->optimize(data: StanData::fromColumns(['y' => [3], 'x' => [1.5]], 'y', ['x'])->with(['scale' => 2]), output_dir: $this->dir, show_messages: false);
        self::assertSame(['N' => 1, 'K' => 1, 'X' => [[1.5]], 'y' => [3], 'scale' => 2], json_decode(file_get_contents($fit->data_file()), true));
    }
    public function testFromColumnsBuildsTheHierarchicalRegressionBlock(): void
    {
        $data = StanData::fromColumns(['y' => [1.0, 2.0, 3.0, 4.0], 'x1' => [1, 2, 3, 4], 'x2' => [0.5, 0.0, 1.0, true], 'school' => [7, 3, 7, 12]], 'y', ['x1', 'x2'], 'school');
        self::assertSame(['N' => 4, 'K' => 2, 'X' => [[1.0, 0.5], [2.0, 0.0], [3.0, 1.0], [4.0, 1.0]], 'y' => [1.0, 2.0, 3.0, 4.0], 'school_id' => [2, 1, 2, 3], 'J_school' => 3, 'J' => 3], $data->data);
        self::assertSame(['school_id' => ['3', '7', '12']], $data->levels, 'numeric group labels sort numerically');
        self::assertSame(['x1', 'x2'], $data->columns);
        $two = StanData::fromColumns(['y' => [0, 1], 'g' => ['b', 'a'], 'h' => ['u', 'u']], 'y', [], ['g', 'h'], intercept: true);
        self::assertSame(['N' => 2, 'K' => 1, 'X' => [[1.0], [1.0]], 'y' => [0, 1], 'g_id' => [2, 1], 'J_g' => 2, 'h_id' => [1, 1], 'J_h' => 1], $two->data, 'no J with several grouping factors');
        self::assertSame(['(Intercept)'], $two->columns);
    }
    public function testFromColumnsAcceptsTablesAndKeepsFactorLevels(): void
    {
        $data = StanData::fromColumns(self::frame(['y' => [1, 0, 1], 'x' => [0.1, 0.2, 0.3], 'g' => ['b', 'a', 'b']], ['g' => 'factor'], ['g' => ['b', 'a']]), 'y', 'x', 'g');
        self::assertSame([1, 2, 1], $data->data['g_id']); self::assertSame(['b', 'a'], $data->levels['g_id']);
    }
    public function testFromColumnsRejectsUnusableColumns(): void
    {
        foreach ([fn() => StanData::fromColumns(['y' => [1]], 'y', 'x'), fn() => StanData::fromColumns(['y' => [1], 'x' => ['a']], 'y', 'x'), fn() => StanData::fromColumns(['y' => [1, 2], 'x' => [1]], 'y', 'x'), fn() => StanData::fromColumns(['y' => [null]], 'y'), fn() => StanData::fromColumns(['y' => [1], 'g' => [null]], 'y', [], 'g'), fn() => StanData::fromColumns(self::frame(['y' => ['a']]), 'y')] as $i => $call) {
            try { $call(); self::fail('Case '.$i.' should throw.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testModelMatrixResultsBecomeRegressionData(): void
    {
        $mm = ['y' => [1.0, 2.5, 3.0], 'x' => [[1.0, 0.5], [1.0, 1.5], [1.0, 2.5]], 'columns' => ['(Intercept)', 'x'], 'intercept' => true, 'terms' => ['x'], 'assign' => [0, 1], 'formula' => 'y ~ x + (1 + x | g)', 'response' => 'y', 'offset' => null,
            'random' => [['label' => '1 + x | g', 'group' => 'g', 'columns' => ['(Intercept)', 'x'], 'correlated' => true, 'z' => [[1.0, 0.5], [1.0, 1.5], [1.0, 2.5]], 'groups' => ['b', 'a', 'b']]],
            'n_input' => 4, 'n_observations' => 3, 'n_dropped' => 1, 'retained_rows' => [0, 1, 3], 'dropped_rows' => [2]];
        $data = StanData::fromModelMatrix($mm);
        self::assertSame(['N' => 3, 'K' => 2, 'X' => [[1.0, 0.5], [1.0, 1.5], [1.0, 2.5]], 'y' => [1.0, 2.5, 3.0], 'g_id' => [2, 1, 2], 'J_g' => 2, 'Z_g' => [[1.0, 0.5], [1.0, 1.5], [1.0, 2.5]], 'Q_g' => 2, 'J' => 2], $data->data);
        self::assertSame(['(Intercept)', 'x'], $data->columns); self::assertSame(['g_id' => ['a', 'b']], $data->levels);
        self::assertSame([0, 1, 3], $data->meta['retained_rows']); self::assertSame([2], $data->meta['dropped_rows']); self::assertSame(1, $data->meta['n_dropped']);
        self::assertSame(['group' => 'g', 'columns' => ['(Intercept)', 'x'], 'terms' => [['label' => '1 + x | g', 'columns' => ['(Intercept)', 'x'], 'correlated' => true]]], $data->meta['random']['g']);
        $dropped = StanData::fromModelMatrix($mm, drop_intercept: true, group_levels: ['g' => ['b', 'a']]);
        self::assertSame([[0.5], [1.5], [2.5]], $dropped->data['X']); self::assertSame(1, $dropped->data['K']); self::assertSame(['x'], $dropped->columns); self::assertSame([1, 2, 1], $dropped->data['g_id']);
    }
    public function testModelMatrixResponsesInterceptGroupsAndOffsets(): void
    {
        $base = ['x' => [[1.0], [1.0], [1.0]], 'columns' => ['(Intercept)'], 'intercept' => true, 'random' => []];
        self::assertSame([2, 0, 7], StanData::fromModelMatrix($base + ['y' => [2.0, 0.0, 7.0]])->data['y'], 'integral responses become integers');
        $binary = StanData::fromModelMatrix($base + ['y' => [0, 1, 1], 'response_levels' => ['no', 'yes']]);
        self::assertSame([0, 1, 1], $binary->data['y']); self::assertArrayNotHasKey('C', $binary->data); self::assertSame(['no', 'yes'], $binary->levels['y']);
        $categorical = StanData::fromModelMatrix($base + ['y' => [2, 0, 1], 'response_levels' => ['a', 'b', 'c']]);
        self::assertSame([3, 1, 2], $categorical->data['y']); self::assertSame(3, $categorical->data['C']);
        $binomial = StanData::fromModelMatrix($base + ['y' => [[3.0, 1.0], [0.0, 2.0], [5.0, 5.0]], 'offset' => [0.1, 0.2, 0.3]]);
        self::assertSame([3, 0, 5], $binomial->data['y']); self::assertSame([4, 2, 10], $binomial->data['trials']); self::assertSame([0.1, 0.2, 0.3], $binomial->data['offset']);
        self::assertArrayNotHasKey('y', StanData::fromModelMatrix($base + ['y' => null])->data);
        $nested = StanData::fromModelMatrix(['y' => [1.0, 2.0, 3.0], 'random' => [
            ['label' => '1 | g1', 'group' => 'g1', 'columns' => ['(Intercept)'], 'correlated' => true, 'z' => [[1.0], [1.0], [1.0]], 'groups' => [10, 3, 10]],
            ['label' => '1 | g2:g1', 'group' => 'g2:g1', 'columns' => ['(Intercept)'], 'correlated' => true, 'z' => [[1.0], [1.0], [1.0]], 'groups' => ['u:10', 'u:3', 'v:10']],
            ['label' => '0 + x | g1', 'group' => 'g1', 'columns' => ['x'], 'correlated' => true, 'z' => [[0.5], [1.5], [2.5]], 'groups' => [10, 3, 10]],
        ]] + $base);
        self::assertSame([2, 1, 2], $nested->data['g1_id']); self::assertSame(['3', '10'], $nested->levels['g1_id'], 'numeric labels sort numerically');
        self::assertSame([1, 2, 3], $nested->data['g2_g1_id']); self::assertSame(3, $nested->data['J_g2_g1']); self::assertArrayNotHasKey('J', $nested->data);
        self::assertSame([[1.0, 0.5], [1.0, 1.5], [1.0, 2.5]], $nested->data['Z_g1'], '(1 | g1) + (0 + x | g1) gives the same Z as (1 + x | g1)'); self::assertSame(2, $nested->data['Q_g1']); self::assertArrayNotHasKey('Z_g2_g1', $nested->data);
        $this->expectException(\UnexpectedValueException::class); StanData::fromModelMatrix(['y' => [1]]);
    }
    public function testModelMatrixRejectsCollidingGroupsAndRaggedZ(): void
    {
        $base = ['y' => [1.0, 2.0], 'x' => [[1.0], [1.0]], 'columns' => ['(Intercept)']];
        $term = fn(string $group, array $z = [[1.0], [1.0]]) => ['group' => $group, 'columns' => ['(Intercept)'], 'z' => $z, 'groups' => ['a', 'b']];
        try { StanData::fromModelMatrix($base + ['random' => [$term('a.b'), $term('a_b')]]); self::fail('Colliding group names should throw.'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString("'a.b' and 'a_b'", $e->getMessage()); }
        $this->expectException(\UnexpectedValueException::class); $this->expectExceptionMessage('wrong number of rows');
        StanData::fromModelMatrix($base + ['random' => [$term('g', [[1.0], [1.0], [1.0]])]]);
    }
    public function testFromColumnsIgnoresUnusedTableColumns(): void
    {
        $data = StanData::fromColumns(self::frame(['y' => [1, 0], 'x' => [0.1, 0.2], 'notes' => [null, 'x']]), 'y', 'x');
        self::assertSame([1, 0], $data->data['y']); self::assertArrayNotHasKey('notes', $data->data);
    }
    public function testFromFormulaRequiresStatsModelMatrix(): void
    {
        if (function_exists('stats_model_matrix')) { self::markTestSkipped('stats_model_matrix() is available.'); }
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('stats_model_matrix()');
        StanData::fromFormula('y ~ x + (1 | g)', ['y' => [1.0], 'x' => [2.0], 'g' => ['a']]);
    }
    #[RunInSeparateProcess]
    public function testFromFormulaPassesTablesToStatsModelMatrix(): void
    {
        if (function_exists('stats_model_matrix')) { self::markTestSkipped('The real stats_model_matrix() is loaded.'); }
        eval('function stats_model_matrix(string $formula, mixed $data, string $missing = "drop"): array { $GLOBALS["stats_model_matrix_call"] = [$formula, $data, $missing]; $labels = isset($data["g"]["codes"]) ? array_map(fn($c) => $c === null ? null : $data["g"]["levels"][$c], $data["g"]["codes"]) : $data["g"]; return ["y" => $data["y"], "x" => array_map(fn($x) => [1.0, $x], $data["x"]), "columns" => ["(Intercept)", "x"], "intercept" => true, "random" => [["label" => "1 | g", "group" => "g", "columns" => ["(Intercept)"], "correlated" => true, "z" => [[1.0], [1.0]], "groups" => $labels]], "retained_rows" => [0, 1], "dropped_rows" => []]; }');
        $data = StanData::fromFormula('y ~ x + (1 | g)', self::frame(['y' => [1.0, 2.5], 'x' => [3.0, 4.0], 'g' => ['b', 'a']], ['g' => 'factor'], ['g' => ['b', 'a']]), drop_intercept: true, missing: 'raise');
        self::assertSame(['y ~ x + (1 | g)', ['y' => [1.0, 2.5], 'x' => [3.0, 4.0], 'g' => ['levels' => ['b', 'a'], 'codes' => [0, 1], 'ordered' => false]], 'raise'], $GLOBALS['stats_model_matrix_call'], 'factor columns are passed as descriptors that keep the level order');
        self::assertSame(['N' => 2, 'K' => 1, 'X' => [[3.0], [4.0]], 'y' => [1.0, 2.5], 'g_id' => [1, 2], 'J_g' => 2, 'J' => 2], $data->data, 'the table\'s factor levels set the group order');
        $model = ['y' => [1.0, 2.5], 'x' => [3.0, 4.0], 'g' => ['levels' => ['b', 'a'], 'codes' => [0, 1], 'ordered' => false]];
        $data = StanData::fromFormula('y ~ x + (1 | g)', self::modelData($model));
        self::assertSame($model, $GLOBALS['stats_model_matrix_call'][1], 'toModelData() is passed unchanged');
        self::assertSame([1, 2], $data->data['g_id'], 'descriptor levels set the group order'); self::assertSame(['b', 'a'], $data->levels['g_id']);
        try { StanData::fromFormula('y ~ x + (1 | g)', ['y' => [1.0, 2.5], 'x' => [3.0, 4.0], 'g' => ['levels' => ['b', 'a'], 'codes' => [1, null], 'base' => 1]]); } catch (\UnexpectedValueException) {} // the fake keeps the missing row; only the call matters
        self::assertSame(['levels' => ['b', 'a'], 'codes' => [0, null], 'ordered' => false], $GLOBALS['stats_model_matrix_call'][1]['g'], "1-based codes become 0-based, and missing codes are left for stats_model_matrix()");
        try { StanData::fromFormula('y ~ x + (1 | g)', self::frame(['y' => [1.0, 2.5], 'x' => [3.0, 4.0], 'g' => ['b', null], 'notes' => [null, 'x']], ['g' => 'factor'], ['g' => ['b', 'a']])); } catch (\UnexpectedValueException) {} // the fake keeps the missing row; only the call matters
        self::assertSame([0, null], $GLOBALS['stats_model_matrix_call'][1]['g']['codes'], 'missing labels in toColumns() factors do not throw');
        self::assertSame([null, 'x'], $GLOBALS['stats_model_matrix_call'][1]['notes']);
    }
    private static function requireModelMatrix(): void { if (!function_exists('stats_model_matrix')) { self::markTestSkipped('Needs stats_model_matrix() from the php_stats extension.'); } }
    private const SCHOOLS = ['y' => [1.0, 2.5, 3.0, null, 5.0, 4.0], 'x' => [0.5, 1.5, 2.5, 3.0, 1.0, 2.0], 'g' => ['b', 'a', 'b', 'c', 'a', 'c'], 'f' => ['lo', 'hi', 'lo', 'hi', 'mid', 'lo'], 'r' => ['no', 'yes', 'no', 'yes', 'yes', 'no']];
    public function testRealModelMatrixRandomIntercept(): void
    {
        self::requireModelMatrix();
        $complete = self::SCHOOLS; $complete['y'][3] = 6.0;
        $data = StanData::fromFormula('y ~ x + (1 | g)', $complete);
        self::assertSame(6, $data->data['N']); self::assertSame(2, $data->data['K']); self::assertSame(['(Intercept)', 'x'], $data->columns);
        self::assertSame([[1.0, 0.5], [1.0, 1.5], [1.0, 2.5], [1.0, 3.0], [1.0, 1.0], [1.0, 2.0]], $data->data['X']);
        self::assertSame([1.0, 2.5, 3.0, 6.0, 5.0, 4.0], $data->data['y']); self::assertSame([2, 1, 2, 3, 1, 3], $data->data['g_id']); self::assertSame(3, $data->data['J']);
        self::assertArrayNotHasKey('Z_g', $data->data); self::assertSame([], $data->meta['dropped_rows']);
        $descriptor = $complete; $descriptor['g'] = ['levels' => ['c', 'b', 'a'], 'codes' => [1, 2, 1, 0, 2, 0], 'ordered' => false];
        self::assertSame([2, 3, 2, 1, 3, 1], StanData::fromFormula('y ~ x + (1 | g)', $descriptor)->data['g_id']);
    }
    public function testRealModelMatrixRandomSlopesFactorsAndMissingRows(): void
    {
        self::requireModelMatrix();
        $warnings = []; set_error_handler(function (int $no, string $message) use (&$warnings) { $warnings[] = $message; return true; }, E_WARNING);
        try { $data = StanData::fromFormula('y ~ x + f + (1 + x | g)', self::SCHOOLS, drop_intercept: true); } finally { restore_error_handler(); }
        self::assertCount(1, $warnings, 'stats_model_matrix() warns once about dropped rows');
        self::assertSame(5, $data->data['N']); self::assertSame(['x', 'flo', 'fmid'], $data->columns); self::assertSame(3, $data->data['K']);
        self::assertSame([[0.5, 1.0, 0.0], [1.5, 0.0, 0.0], [2.5, 1.0, 0.0], [1.0, 0.0, 1.0], [2.0, 1.0, 0.0]], $data->data['X']);
        self::assertSame([2, 1, 2, 1, 3], $data->data['g_id']); self::assertSame(['a', 'b', 'c'], $data->levels['g_id']);
        self::assertSame([[1.0, 0.5], [1.0, 1.5], [1.0, 2.5], [1.0, 1.0], [1.0, 2.0]], $data->data['Z_g']); self::assertSame(2, $data->data['Q_g']);
        self::assertSame([0, 1, 2, 4, 5], $data->meta['retained_rows']); self::assertSame([3], $data->meta['dropped_rows']);
        $this->expectException(\Exception::class); StanData::fromFormula('y ~ x', self::SCHOOLS, missing: 'raise');
    }
    public function testRealModelMatrixFactorResponse(): void
    {
        self::requireModelMatrix();
        $data = StanData::fromFormula('r ~ x', self::SCHOOLS);
        self::assertSame([0, 1, 0, 1, 1, 0], $data->data['y']); self::assertSame(['no', 'yes'], $data->levels['y']); self::assertSame(6, $data->data['N']);
        $three = StanData::fromFormula('f ~ x', self::SCHOOLS);
        self::assertSame(['hi', 'lo', 'mid'], $three->levels['y']); self::assertSame([2, 1, 2, 1, 3, 2], $three->data['y']); self::assertSame(3, $three->data['C']);
    }
    public function testRealModelMatrixKeepsTableLevelOrderAndDropsMissingLabels(): void
    {
        self::requireModelMatrix();
        $columns = self::SCHOOLS; $columns['y'][3] = 6.0; $columns['g'][3] = null;
        $frame = self::frame($columns, ['f' => 'factor', 'r' => 'factor'], ['f' => ['lo', 'mid', 'hi'], 'r' => ['yes', 'no']]);
        $warnings = []; set_error_handler(function (int $no, string $message) use (&$warnings) { $warnings[] = $message; return true; }, E_WARNING);
        try { $data = StanData::fromFormula('r ~ f + (1 | g)', $frame); } finally { restore_error_handler(); }
        self::assertCount(1, $warnings); self::assertSame(5, $data->data['N'], 'the row with a missing group label is dropped');
        self::assertSame(['(Intercept)', 'fmid', 'fhi'], $data->columns, "the table's first level is the reference");
        self::assertSame(['yes', 'no'], $data->levels['y']); self::assertSame([1, 0, 1, 0, 1], $data->data['y'], 'the response follows the table level order');
    }
}
enum FactorType: string { case Factor = 'factor'; case Float = 'float'; }
