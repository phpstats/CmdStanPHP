<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanFit, Tables};
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
final class TablesTest extends TestCase
{
    public function testRowsBecomeColumnsInFirstSeenOrder(): void
    {
        self::assertSame(['a' => [1, 3], 'b' => [2, null], 'c' => [null, 4]], Tables::fromRows([['a' => 1, 'b' => 2], ['a' => 3, 'c' => 4]]));
        self::assertSame([], Tables::fromRows([]));
    }
    public function testDrawsColumnsMatchDrawsDf(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv'), $this->csv('b.csv', rows: ['-1,0.5,5,6,0,4,1', '-1,0.6,6,7,0,4,1', '-1,0.7,7,8,0,4,1'])]);
        $columns = $fit->drawsColumns();
        self::assertSame(['lp__', 'theta', 'x[1]', 'x[2]', '.chain', '.iteration', '.draw'], array_keys($columns));
        self::assertSame([0.3, 0.4, 0.5, 0.6, 0.7], $columns['theta']);
        self::assertSame([1, 1, 2, 2, 2], $columns['.chain']); self::assertSame([1, 2, 1, 2, 3], $columns['.iteration']); self::assertSame([1, 2, 3, 4, 5], $columns['.draw']);
        self::assertSame(Tables::fromRows($fit->draws(null, 'draws_df')), $columns);
        $theta = $fit->drawsColumns('x', incWarmup: true);
        self::assertSame(['x[1]', 'x[2]', '.chain', '.iteration', '.draw'], array_keys($theta)); self::assertCount(9, $theta['x[1]']); self::assertSame([1, 2, 3, 4], array_slice($theta['.iteration'], 0, 4));
    }
    public function testSummaryColumns(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv()]);
        $columns = $fit->summaryColumns('theta', measures: ['mean', 'sd']);
        self::assertSame(['variable', 'mean', 'sd'], array_keys($columns)); self::assertSame(['theta'], $columns['variable']); self::assertEqualsWithDelta(0.35, $columns['mean'][0], 1e-12);
        $plain = $fit->summaryColumns(['theta', 'x'], [0.5]);
        self::assertSame(['theta', 'x[1]', 'x[2]'], $plain['variable']); self::assertArrayHasKey('q50', $plain);
    }
    public function testFramesNeedPhibble(): void
    {
        if (class_exists(Tables::DATAFRAME_CLASS)) { self::markTestSkipped('phibble is installed.'); }
        $fit = CmdStanFit::fromCsv([$this->csv()]);
        try { $fit->drawsTable(); self::fail('drawsTable() should throw without phibble.'); } catch (\RuntimeException $e) { self::assertStringContainsString('composer require phpstats/phibble', $e->getMessage()); }
        $this->expectExceptionMessage('summaryTable() needs'); $fit->summaryTable();
    }
    #[RunInSeparateProcess]
    public function testFramesUsePhibbleWhenInstalled(): void
    {
        if (!class_exists(Tables::DATAFRAME_CLASS)) { require __DIR__.'/fixtures/PhibbleDataFrameStub.php'; }
        $fit = CmdStanFit::fromCsv([$this->csv()]);
        $df = $fit->drawsTable('theta');
        self::assertInstanceOf(Tables::DATAFRAME_CLASS, $df); self::assertSame($fit->drawsColumns('theta'), $df->toColumns());
        self::assertSame($fit->summaryColumns('theta', measures: ['mean']), $fit->summaryTable('theta', measures: ['mean'])->toColumns());
    }
}
