<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanFit, Plot, Process};
use PHPUnit\Framework\Attributes\Group;
/** Compares the values behind the ASCII figures with R, bayesplot and posterior. */
#[Group('compatibility')]
final class PlotCompatibilityTest extends TestCase
{
    public function testPlottedValuesMatchR(): void
    {
        try { Process::run(['Rscript', '-e', 'stopifnot(requireNamespace("bayesplot", quietly=TRUE), requireNamespace("posterior", quietly=TRUE), requireNamespace("jsonlite", quietly=TRUE))']); }
        catch (\Throwable $e) { self::markTestSkipped('R with bayesplot, posterior and jsonlite required: '.$e->getMessage()); }
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(7)); $x = []; $ties = []; $state = 0.0;
        for ($i = 0; $i < 400; ++$i) { $state = 0.5 * $state + $rng->getFloat(-1, 1); $x[] = $state; $ties[] = (float)$rng->getInt(0, 9); }
        $at = array_map(fn($i) => -2 + $i * 0.1, range(0, 40));
        file_put_contents($this->dir.'/in.json', json_encode(['x' => $x, 'ties' => $ties, 'at' => $at], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        Process::run(['Rscript', __DIR__.'/fixtures/plot.R', $this->dir.'/in.json', $this->dir.'/out.json'], timeout: 120);
        $r = json_decode(file_get_contents($this->dir.'/out.json'), true, flags: JSON_THROW_ON_ERROR);
        $case = '400 AR(1) draws and 400 tied integers';
        self::near(['figures', $case, 'density bandwidth'], $r['bandwidth'], Plot::bandwidth($x), 1e-14);
        self::near(['figures', $case, 'density at 41 points'], $r['density'], Plot::density($x, $at), 1e-13);
        self::near(['figures', $case, 'autocorrelation, lags 0 to 20'], $r['acf'], Plot::autocorrelation($x, 20), 1e-12);
        self::near(['figures', $case, 'ranks with ties'], $r['ranks'], Plot::ranks($ties), 1e-12);
        foreach ($r['intervals'] as $row) {
            $values = $row['parameter'] === 'a' ? $x : $ties;
            foreach (['ll' => 0.05, 'l' => 0.25, 'm' => 0.5, 'h' => 0.75, 'hh' => 0.95] as $key => $p) { self::near(['figures', $case, 'interval quantiles'], $row[$key], CmdStanFit::quantile($values, $p), 1e-12, message: $row['parameter'].' '.$key); }
        }
    }
}
