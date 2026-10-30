<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\StanJson;
use PHPUnit\Framework\Attributes\DataProvider;
use function CmdStan\write_stan_json;
/** write_stan_json() with stanc --info declarations, as CmdStanR 1.0 writes data and initial values. */
final class StanJsonTest extends TestCase
{
    private const DECLARATIONS = [
        'n' => ['type' => 'int', 'dimensions' => 0],
        'k' => ['type' => 'int', 'dimensions' => 1],
        'x' => ['type' => 'vector', 'dimensions' => 1],
        't' => ['type' => [['type' => 'int', 'dimensions' => 0], ['type' => 'vector', 'dimensions' => 1]], 'dimensions' => 0],
        'ts' => ['type' => [['type' => 'real', 'dimensions' => 0], ['type' => 'real', 'dimensions' => 0]], 'dimensions' => 2],
        'nested' => ['type' => [['type' => 'real', 'dimensions' => 0], ['type' => [['type' => 'int', 'dimensions' => 0], ['type' => 'complex', 'dimensions' => 0]], 'dimensions' => 0]], 'dimensions' => 0],
        'z' => ['type' => 'complex_vector', 'dimensions' => 1],
    ];
    public function testRoundTrip(): void
    {
        $file = StanJson::write(['N' => 2, 'flags' => [true, false], 'x' => [[1.0,2.0],[3.0,4.0]], 'empty' => []], $this->dir.'/data.json');
        self::assertSame(['N' => 2, 'flags' => [1,0], 'x' => [[1.0,2.0],[3.0,4.0]], 'empty' => []], json_decode(file_get_contents($file), true));
    }
    public function testEmptyDataIsObject(): void { $file = StanJson::write([], $this->dir.'/data.json'); self::assertSame('{}', trim(file_get_contents($file))); }
    public static function invalid(): array { return [['x' => null], ['x' => 'text'], ['x' => NAN], ['x' => INF], ['x' => [[1],[2,3]]], ['x' => [1 => 2]], ['bad name' => 1], [0 => 1]]; }
    #[DataProvider('badData')]
    public function testRejectsInvalidData(array $data): void { $this->expectException(\InvalidArgumentException::class); StanJson::write($data, $this->dir.'/bad.json'); }
    public static function badData(): array { return array_map(fn($x) => [$x], self::invalid()); }
    private function write(array $data, bool $alwaysDecimal = false): string
    {
        return (string)file_get_contents(write_stan_json($data, $this->dir.'/data.json', $alwaysDecimal, self::DECLARATIONS));
    }
    public function testDeclarationsShapeTheJson(): void
    {
        $json = $this->write(['n' => 3.0, 'k' => 2, 'x' => [1, 2], 't' => [3, [1, 2]], 'ts' => [[[1, 2], [3, 4]], [[5, 6], [7, 8]]], 'nested' => [1.5, [2, [1, 2]]], 'z' => [[1, 2], [3, 4]]], true);
        $data = json_decode($json, true);
        self::assertSame(3, $data['n'], 'an integral float for an int variable becomes an integer');
        self::assertSame([2], $data['k'], 'one value for a one-dimensional array becomes an array');
        self::assertStringContainsString('"x": [', $json); self::assertSame([1.0, 2.0], $data['x'], 'alwaysDecimal writes reals as decimals');
        self::assertSame(['1' => 3, '2' => [1.0, 2.0]], $data['t']); self::assertStringContainsString('"1": 3,', $json, 'tuple int elements stay integers');
        self::assertSame(['1' => 7.0, '2' => 8.0], $data['ts'][1][1]); self::assertSame(['1' => 1.5, '2' => ['1' => 2, '2' => [1.0, 2.0]]], $data['nested']);
        self::assertSame([[1.0, 2.0], [3.0, 4.0]], $data['z']);
        self::assertSame(['1' => 3, '2' => [1, 2]], json_decode($this->write(['t' => [1 => 3, 2 => [1, 2]]]), true)['t'], 'tuples may use Stan\'s 1-based keys');
    }
    public function testIntegersAreTruncatedWithAWarning(): void
    {
        $warnings = []; set_error_handler(function (int $no, string $message) use (&$warnings) { $warnings[] = $message; return true; }, E_USER_WARNING);
        try { $data = json_decode($this->write(['n' => 2.7]), true); } finally { restore_error_handler(); }
        self::assertSame(2, $data['n']); self::assertSame(["A non-integer value was supplied for 'n'! It will be truncated to an integer."], $warnings);
    }
    public function testErrorsNameTheVariable(): void
    {
        $table = new class { public function toColumns(): array { return ['a' => [1, 2], 'b' => ['x', 'y']]; } };
        $factor = new class { public function toModelData(): array { return ['f' => ['levels' => ['a', 'b'], 'codes' => [0, 1], 'ordered' => false]]; } };
        foreach ([
            [['n' => null], "Variable 'n' is NULL."],
            [['t' => [1, [2], 3]], "Variable 't' is a tuple with 2 elements, but 3 were supplied."],
            [['t' => 5], "Variable 't' is declared as a tuple and must be a list."],
            [['t' => [1, null]], "Variable 't:2' is NULL."],
            [['x' => $table], "Variable 'x' has columns of invalid type: b."],
            [['x' => $factor], "A factor was supplied for 'x', which is declared as 'vector'."],
            [['x' => [1, NAN]], "Variable 'x' has NA values."],
        ] as [$data, $message]) {
            try { $this->write($data); self::fail('Expected: '.$message); } catch (\InvalidArgumentException $e) { self::assertSame($message, $e->getMessage()); }
        }
    }
    public function testTablesBecomeMatricesForIntVariables(): void
    {
        $table = new class { public function toModelData(): array { return ['a' => [1, 2], 'f' => ['levels' => ['lo', 'hi'], 'codes' => [1, 0], 'ordered' => false], 'b' => [true, false]]; } };
        self::assertSame([[1, 2, 1], [2, 1, 0]], StanJson::convert($table, 'm', ['type' => 'int', 'dimensions' => 2]));
    }
}
