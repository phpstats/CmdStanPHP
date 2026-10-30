<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\CmdStanFit;
/**
 * Wide fits: the draws are held by column as packed doubles and only the columns that are used are read, so memory for
 * one indexed variable does not grow with the number of other columns.
 */
final class MemoryTest extends TestCase
{
    private const CHAINS = 4;
    private const DRAWS = 250;
    private const LATENT = 12;
    private const OTHER = 2000;
    /** @return list<string> */
    private function wideFit(): array
    {
        $sampler = ['lp__', 'accept_stat__', 'stepsize__', 'treedepth__', 'n_leapfrog__', 'divergent__', 'energy__'];
        $names = array_merge($sampler, array_map(fn($i) => 'line_latent.'.$i, range(1, self::LATENT)), array_map(fn($i) => 'gq.'.$i, range(1, self::OTHER)));
        $files = [];
        for ($c = 1; $c <= self::CHAINS; ++$c) {
            $file = $this->dir.'/wide-'.$c.'.csv'; $handle = fopen($file, 'wb');
            fwrite($handle, "# method = sample (Default)\n# num_samples = ".self::DRAWS."\n# num_warmup = 100\n# save_warmup = false (Default)\n# thin = 1 (Default)\n# max_depth = 10 (Default)\n# id = ".$c."\n".implode(',', $names)."\n# Adaptation terminated\n# Step size = 0.5\n");
            for ($i = 0; $i < self::DRAWS; ++$i) {
                $values = [-10.0 - $i / 7, 0.9, 0.5, 3.0 + $i % 8, 7.0, (float)($i % 50 === 0), 12.5 + sin($i + $c)];
                for ($j = 1; $j <= self::LATENT; ++$j) { $values[] = self::latent($c, $i, $j); }
                for ($j = 1; $j <= self::OTHER; ++$j) { $values[] = 0.123456789012345678 * $j + $i / 997; }
                fwrite($handle, implode(',', array_map(fn($v) => sprintf('%.18g', $v), $values))."\n");
            }
            fwrite($handle, "# Elapsed Time: 1 seconds (Warm-up)\n# 1 seconds (Sampling)\n# 2 seconds (Total)\n"); fclose($handle);
            $files[] = $file;
        }
        return $files;
    }
    private static function latent(int $chain, int $draw, int $index): float { return (float)sprintf('%.18g', $index + sin(31.0 * $chain + 7.0 * $draw + $index)); }
    public function testOneIndexedVariableOfAWideFitStaysSmall(): void
    {
        $files = $this->wideFit(); gc_collect_cycles(); $base = memory_get_usage(); memory_reset_peak_usage();
        $fit = CmdStanFit::fromCsv($files);
        $matrix = $fit->draws('line_latent', 'draws_matrix');
        $summary = $fit->summary('line_latent', [0.05, 0.5, 0.95], ['mean', 'quantile2', 'rhat', 'ess_bulk', 'ess_tail']);
        $diagnostics = $fit->diagnosticSummary();
        $peak = memory_get_peak_usage() - $base;
        self::assertCount(self::CHAINS * self::DRAWS, $matrix);
        self::assertSame(array_map(fn($j) => 'line_latent['.$j.']', range(1, self::LATENT)), array_keys($matrix[0]));
        self::assertSame(self::latent(3, 17, 5), $matrix[2 * self::DRAWS + 17]['line_latent[5]']);
        self::assertCount(self::LATENT, $summary); self::assertEquals([5, 5, 5, 5], $diagnostics['num_divergent']);
        // Reading every row as an associative array took about 60 bytes per value, over 120 MB here. The packed columns
        // that are used take 8 bytes per value; the rest is the draws_matrix rows and the parser's per-line arrays.
        self::assertLessThan(16 * 1024 * 1024, $peak, sprintf('peak %.1f MB', $peak / 1048576));
    }
    public function testMaterializedWideFitHoldsEightBytesPerValue(): void
    {
        $files = $this->wideFit(); gc_collect_cycles(); $base = memory_get_usage();
        $fit = CmdStanFit::fromCsv($files)->materialize(); gc_collect_cycles();
        $held = memory_get_usage() - $base; $values = self::CHAINS * self::DRAWS * (7 + self::LATENT + self::OTHER);
        self::assertLessThan(10 * $values, $held, sprintf('%.1f bytes per value', $held / $values));
        foreach ($files as $file) { unlink($file); }
        self::assertSame(self::latent(4, 0, 12), $fit->draws('line_latent[12]', 'draws_matrix')[3 * self::DRAWS]['line_latent[12]']);
        self::assertCount(self::OTHER, $fit->summary('gq', measures: ['mean']));
    }
}
