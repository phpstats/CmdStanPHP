<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{Inits, Loo, Process, Psis, Rvars, Summary};
use PHPUnit\Framework\Attributes\Group;
/** Compares the posterior/loo/cmdstanr ports with the installed R packages on deterministic inputs. */
#[Group('compatibility')]
final class StatisticsCompatibilityTest extends TestCase
{
    private static ?array $input = null;
    private static ?array $reference = null;
    protected function setUp(): void
    {
        parent::setUp();
        if (self::$reference !== null) { return; }
        try { Process::run(['Rscript', '-e', 'stopifnot(requireNamespace("cmdstanr", quietly=TRUE), requireNamespace("jsonlite", quietly=TRUE), requireNamespace("posterior", quietly=TRUE), requireNamespace("loo", quietly=TRUE))']); }
        catch (\Throwable $e) { self::markTestSkipped('R with cmdstanr, posterior, loo and jsonlite required: '.$e->getMessage()); }
        self::$input = self::input(); file_put_contents($this->dir.'/input.json', json_encode(self::$input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $env = getenv('CMDSTAN') ? ['CMDSTAN' => getenv('CMDSTAN')] : [];
        Process::run(['Rscript', __DIR__.'/fixtures/statistics.R', $this->dir.'/input.json', $this->dir.'/output.json'], env: $env, timeout: 300);
        self::$reference = json_decode(file_get_contents($this->dir.'/output.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    private static function input(): array
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20240607));
        $u = fn() => ($rng->getInt(0, (1 << 53) - 1) + 0.5) / 9007199254740992;
        $normal = fn() => sqrt(-2 * log($u())) * cos(2 * M_PI * $u());
        $chains = ['ar' => [], 'heavy' => [], 'ties' => [], 'const' => [], 'skew' => [], 'shifted' => []];
        for ($c = 0; $c < 4; ++$c) {
            $previous = 0.0;
            for ($i = 0; $i < 101; ++$i) {
                $previous = 0.7 * $previous + $normal(); $chains['ar'][$c][] = $previous;
                $chains['heavy'][$c][] = $normal() / sqrt($u()); $chains['ties'][$c][] = round(2 * $normal());
                $chains['const'][$c][] = 1.5; $chains['skew'][$c][] = exp($normal()); $chains['shifted'][$c][] = $normal() + 0.5 * $c;
            }
        }
        $smooth = []; for ($i = 0; $i < 1000; ++$i) { $smooth[] = exp(1.2 * $normal()); }
        $small = []; for ($i = 0; $i < 100; ++$i) { $small[] = exp($normal()); }
        $logWeights = []; for ($i = 0; $i < 1000; ++$i) { $logWeights[] = 2 * $normal(); }
        $gpd = []; for ($i = 0; $i < 50; ++$i) { $gpd[] = 1.3 * ($u() ** -0.3 - 1) / 0.3; }
        $y = [0.1, -0.4, 0.8, 0.3, 1.2, -0.9, 0.0, 0.5, -0.2, 0.7, 5.0, 0.2]; $logLik = [];
        for ($c = 0; $c < 4; ++$c) {
            $theta = 0.3; $sigma = 0.0;
            for ($i = 0; $i < 250; ++$i) {
                $theta = 0.3 + 0.5 * ($theta - 0.3) + 0.2 * $normal(); $sigma = 0.3 * $sigma + 0.15 * $normal(); $sd = exp($sigma);
                $logLik[$i][$c] = array_map(fn($yi) => -0.5 * log(2 * M_PI) - log($sd) - 0.5 * (($yi - $theta) / $sd) ** 2, $y);
            }
        }
        $rvars = ['.chain' => [1, 1, 1, 2, 2, 2], '.iteration' => [1, 2, 3, 1, 2, 3], 'theta' => [0.1, 0.2, 0.3, 0.4, 0.5, 0.6], 'x[1,1]' => [1, 2, 3, 4, 5, 6], 'x[2,1]' => [7, 8, 9, 10, 11, 12], 'x[1,3]' => [13, 14, 15, 16, 17, 18], 'y[2]' => [-1, -2, -3, -4, -5, -6], 'lp__' => [-1, -1, -2, -2, -3, -3]];
        $parameters = ['theta' => ['type' => 'real', 'dimensions' => 0], 'x' => ['type' => 'matrix', 'dimensions' => 2], 'y' => ['type' => 'vector', 'dimensions' => 1], 'z' => ['type' => 'vector', 'dimensions' => 1], 'm' => ['type' => 'matrix', 'dimensions' => 2], 'a' => ['type' => 'array', 'dimensions' => 3]];
        $row = fn(float $s) => ['theta' => 0.5 * $s, 'x[1,1]' => 1 * $s, 'x[2,1]' => 2 * $s, 'x[1,2]' => 3 * $s, 'x[2,2]' => 4 * $s, 'x[1,3]' => 5 * $s, 'x[2,3]' => 6 * $s, 'y[1]' => -1 * $s, 'y[2]' => -2 * $s, 'y[3]' => -3 * $s, 'z[1]' => 9 * $s, 'm[1,1]' => 7 * $s, 'm[1,2]' => 8 * $s, 'a[1,1,1]' => 1.5 * $s, 'a[1,2,1]' => 2.5 * $s, 'gq' => 42.0, 'lp__' => -7.0];
        $columns = fn(array $rows) => array_map(fn($name) => array_column($rows, $name), array_combine(array_keys($rows[0]), array_keys($rows[0])));
        return ['chains' => $chains, 'probs' => [0.025, 0.5, 0.975], 'smooth' => $smooth, 'smooth_small' => $small, 'log_weights' => $logWeights, 'gpd' => $gpd, 'log_lik' => $logLik, 'rvars' => $rvars, 'parameters' => $parameters, 'init_draws' => $columns([$row(1.0), $row(2.0)]), 'init_one' => $columns([$row(1.5)])];
    }
    private static function close(mixed $expected, mixed $actual, string $message, float $tolerance = 1e-8): void
    {
        if ($expected === null) { self::assertTrue(is_nan($actual), $message.': expected NA, got '.var_export($actual, true)); return; }
        if (is_array($expected)) {
            self::assertIsArray($actual, $message); self::assertCount(count($expected), $actual, $message);
            foreach (array_values($expected) as $i => $value) { self::close($value, array_values($actual)[$i], $message.'['.$i.']', $tolerance); }
            return;
        }
        if (is_infinite((float)$actual) && is_string($expected)) { self::assertSame($expected, $actual > 0 ? 'Inf' : '-Inf', $message); return; }
        self::assertEqualsWithDelta((float)$expected, (float)$actual, $tolerance * max(1.0, abs((float)$expected)), $message);
    }
    private function drawsList(): array
    {
        $out = [];
        foreach (self::$input['chains'] as $name => $chains) { foreach ($chains as $c => $values) { $out[$c][$name] = $values; } }
        return $out;
    }
    private function compareSummary(array $reference, array $actual, string $label): void
    {
        $actual = array_column($actual, null, 'variable');
        foreach ($reference as $row) {
            foreach ($row as $column => $value) {
                if ($column === 'variable') { continue; }
                self::assertArrayHasKey($column, $actual[$row['variable']], $label.' column '.$column);
                self::close($value, $actual[$row['variable']][$column], $label.' '.$row['variable'].' '.$column);
            }
        }
    }
    public function testSummaryMeasuresMatchPosterior(): void
    {
        $this->compareSummary(self::$reference['summary_default'], Summary::summarise($this->drawsList()), 'default');
        $measures = ['default_summary_measures', 'default_convergence_measures', 'default_mcse_measures', 'rhat_basic', 'ess_basic', 'ess_mean', 'ess_sd', 'ess_median', 'ess_quantile'];
        $all = Summary::summarise($this->drawsList(), $measures); $this->compareSummary(self::$reference['summary_all'], $all, 'all');
        self::assertSame(array_keys(self::$reference['summary_all'][0]), array_keys($all[0]));
        $this->compareSummary(self::$reference['summary_probs'], Summary::summarise($this->drawsList(), ['quantile2', 'mcse_quantile', 'ess_quantile'], self::$input['probs']), 'probs');
    }
    public function testParetoSmoothingMatchesPosterior(): void
    {
        $in = self::$input; $ref = self::$reference['pareto'];
        $cases = ['both' => Psis::paretoSmooth($in['smooth']), 'right' => Psis::paretoSmooth($in['smooth'], 'right', 1.0), 'left' => Psis::paretoSmooth($in['smooth'], 'left', 0.8), 'small' => Psis::paretoSmooth($in['smooth_small'], 'right'), 'log_weights' => Psis::paretoSmooth($in['log_weights'], are_log_weights: true)];
        foreach ($cases as $name => $result) {
            self::close($ref[$name]['x'], $result['x'], $name.' x');
            foreach ($ref[$name]['diagnostics'] as $key => $value) { self::close($value, $result['diagnostics'][$key], $name.' '.$key); }
        }
        self::close($ref['khat'], Psis::paretoKhat($in['smooth']), 'khat');
        $fit = Psis::gpdfit($in['gpd']); self::close($ref['gpdfit']['k'], $fit['k'], 'gpd k'); self::close($ref['gpdfit']['sigma'], $fit['sigma'], 'gpd sigma');
    }
    public function testPsisRelativeEfficiencyLooAndWaicMatchLoo(): void
    {
        $in = self::$input; $ref = self::$reference;
        $rEff = Loo::relativeEffLogLik($in['log_lik']); self::close($ref['relative_eff'], $rEff, 'relative_eff');
        $rows = []; foreach ([0, 1, 2, 3] as $c) { foreach ($in['log_lik'] as $iteration) { $rows[] = array_map(fn($v) => -$v, $iteration[$c]); } }
        $psis = Psis::psis($rows, $rEff);
        self::close($ref['psis']['log_weights'], $psis['log_weights'], 'psis log weights'); self::close($ref['psis']['pareto_k'], $psis['pareto_k'], 'psis k'); self::close($ref['psis']['n_eff'], $psis['n_eff'], 'psis n_eff');
        $vector = Psis::psis($in['smooth_small']);
        self::close($ref['psis_vector']['log_weights'], $vector['log_weights'], 'vector log weights'); self::close((array)$ref['psis_vector']['pareto_k'], $vector['pareto_k'], 'vector k'); self::close((array)$ref['psis_vector']['n_eff'], $vector['n_eff'], 'vector n_eff');
        $loo = Loo::loo($in['log_lik']);
        self::close($ref['loo']['estimates'], array_map(fn($row) => [$row['Estimate'], $row['SE']], array_values($loo['estimates'])), 'loo estimates');
        self::close($ref['loo']['pointwise'], array_map('array_values', $loo['pointwise']), 'loo pointwise');
        self::close($ref['loo']['pareto_k'], $loo['diagnostics']['pareto_k'], 'loo k'); self::close($ref['loo']['n_eff'], $loo['diagnostics']['n_eff'], 'loo n_eff');
        self::assertGreaterThan($loo['k_threshold'], max($loo['diagnostics']['pareto_k']));
        $plain = Loo::loo($in['log_lik'], false);
        self::close($ref['loo_noreff']['estimates'], array_map(fn($row) => [$row['Estimate'], $row['SE']], array_values($plain['estimates'])), 'loo without r_eff');
        self::close($ref['loo_noreff']['pointwise'], array_map('array_values', $plain['pointwise']), 'loo pointwise without r_eff'); self::close($ref['loo_noreff']['n_eff'], $plain['diagnostics']['n_eff'], 'n_eff without r_eff');
        $waic = Loo::waic($in['log_lik']);
        self::close($ref['waic']['estimates'], array_map(fn($row) => [$row['Estimate'], $row['SE']], array_values($waic['estimates'])), 'waic estimates');
        self::close($ref['waic']['pointwise'], array_map('array_values', $waic['pointwise']), 'waic pointwise');
    }
    public function testRvarsShapesMatchPosterior(): void
    {
        $columns = self::$input['rvars']; $rows = [];
        foreach ($columns['theta'] as $i => $unused) { $rows[] = array_map(fn($values) => $values[$i], $columns); }
        $rvars = Rvars::fromDrawsDf($rows);
        self::assertSame(array_column(self::$reference['rvars'], 'name'), array_keys($rvars));
        foreach (self::$reference['rvars'] as $ref) {
            $rvar = $rvars[$ref['name']];
            self::assertSame((array)$ref['dim'], $rvar['dims'] === [] ? [1] : $rvar['dims'], $ref['name'].' dims'); self::assertSame($ref['nchains'], $rvar['nchains']);
            self::close($ref['values'], self::columnMajor($rvar), $ref['name'].' values');
        }
    }
    private static function columnMajor(array $rvar): array
    {
        if ($rvar['dims'] === []) { return $rvar['draws']; }
        $out = []; $total = array_product($rvar['dims']);
        for ($cell = 0; $cell < $total; ++$cell) {
            $node = $rvar['draws']; $rest = $cell;
            foreach ($rvar['dims'] as $dim) { $node = $node[$rest % $dim]; $rest = intdiv($rest, $dim); }
            array_push($out, ...$node);
        }
        return $out;
    }
    public function testInitDimensionMatchingMatchesCmdStanR(): void
    {
        $in = self::$input;
        foreach (['two' => [$in['init_draws'], 2], 'recycled' => [$in['init_one'], 3]] as $name => [$columns, $chains]) {
            $rows = [];
            foreach ($columns['theta'] as $i => $unused) { $rows[] = array_map(fn($values) => $values[$i], $columns); }
            $result = Inits::fromDraws($rows, $chains, $in['parameters'], warn_partial: false);
            self::assertCount($chains, self::$reference['inits'][$name]);
            foreach (self::$reference['inits'][$name] as $i => $json) {
                $expected = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(array_keys($expected), array_keys($result['inits'][$i]), $name.' names');
                self::assertSame(self::shape($expected), self::shape($result['inits'][$i]), $name.' shapes');
                self::close(self::flatten($expected), self::flatten($result['inits'][$i]), $name.' values', 1e-15);
            }
        }
    }
    private static function shape(mixed $value): mixed { return is_array($value) ? array_map(self::shape(...), $value) : 'x'; }
    private static function flatten(array $value): array { $out = []; array_walk_recursive($value, function ($v) use (&$out) { $out[] = $v; }); return $out; }
}
