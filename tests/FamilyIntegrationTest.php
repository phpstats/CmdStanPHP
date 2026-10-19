<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, StanData};
use PHPUnit\Framework\Attributes\Group;
use function CmdStan\write_stan_file;
#[Group('integration')]
final class FamilyIntegrationTest extends TestCase
{
    private const PROGRAM = <<<'STAN'
data {
  int<lower=0> N;
  int<lower=0> K;
  matrix[N, K] X;
  vector[N] y;
  int<lower=1> J;
  array[N] int<lower=1, upper=J> school_id;
}
parameters {
  real alpha;
  vector[K] beta;
  vector[J] z;
  real<lower=0> tau;
  real<lower=0> sigma;
}
model {
  alpha ~ normal(0, 5); beta ~ normal(0, 5); z ~ std_normal(); tau ~ normal(0, 1); sigma ~ normal(0, 2);
  y ~ normal(alpha + X * beta + tau * z[school_id], sigma);
}
STAN;
    private function model(string $code, string $name): CmdStanModel
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        $dir = sys_get_temp_dir().'/cmdstanphp-family-models'; if (!is_dir($dir)) { mkdir($dir, 0700, true); }
        $model = new CmdStanModel(write_stan_file($code, $dir.'/'.$name.'.stan')); $model->compile(); return $model;
    }
    public function testHierarchicalRegressionFromColumnsAndDrawsColumns(): void
    {
        mt_srand(7); $x = []; $y = []; $school = [];
        for ($i = 0; $i < 60; ++$i) { $school[] = ['north', 'south', 'east'][$i % 3]; $x[] = $i / 30 - 1; $y[] = 1.0 + 2.0 * $x[$i] + ['north' => 0.5, 'south' => -0.5, 'east' => 0.0][$school[$i]] + (mt_rand() / mt_getrandmax() - 0.5) * 0.4; }
        $data = StanData::fromColumns(['y' => $y, 'x' => $x, 'school' => $school], 'y', ['x'], 'school');
        self::assertSame(['east', 'north', 'south'], $data->levels['school_id']);
        $fit = $this->model(self::PROGRAM, 'family_hier')->sample(data: $data, chains: 2, iter_warmup: 300, iter_sampling: 300, seed: 11, adapt_delta: 0.95, show_messages: false, refresh: 0, output_dir: $this->dir, diagnostics: null);
        $columns = $fit->draws_columns(['alpha', 'beta']);
        self::assertSame(['alpha', 'beta[1]', '.chain', '.iteration', '.draw'], array_keys($columns)); self::assertCount(600, $columns['beta[1]']);
        self::assertEqualsWithDelta(2.0, array_sum($columns['beta[1]']) / 600, 0.25);
        $summary = $fit->summary_columns('beta'); self::assertSame(['beta[1]'], $summary['variable']); self::assertLessThan(1.05, $summary['rhat'][0]);
    }
    private const FORMULA_PROGRAM = <<<'STAN'
data {
  int<lower=0> N;
  int<lower=1> K;
  matrix[N, K] X;
  vector[N] y;
  int<lower=1> J;
  array[N] int<lower=1, upper=J> school_id;
}
parameters {
  vector[K] beta;
  vector[J] z;
  real<lower=0> tau;
  real<lower=0> sigma;
}
model {
  beta ~ normal(0, 100); z ~ std_normal(); tau ~ student_t(3, 0, 10); sigma ~ student_t(3, 0, 10);
  y ~ normal(X * beta + tau * z[school_id], sigma);
}
STAN;
    /** score ~ study_hours + (1 | school) on phit's schools data, compared with Phit\fit_lmer()'s REML estimates. */
    public function testFormulaHierarchicalFitAgreesWithStatsLmer(): void
    {
        if (!function_exists('Phit\model_matrix') || !function_exists('Phit\fit_lmer')) { self::markTestSkipped('Needs Phit\model_matrix() and Phit\fit_lmer() from the phit extension.'); }
        $handle = fopen(__DIR__.'/fixtures/schools.csv', 'rb'); $header = fgetcsv($handle, escape: ''); $columns = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) { foreach ($header as $i => $name) { $columns[$name][] = $name === 'school' ? $row[$i] : (float)$row[$i]; } }
        fclose($handle);
        $formula = 'score ~ study_hours + (1 | school)';
        $data = StanData::fromFormula($formula, $columns);
        self::assertSame(250, $data->data['N']); self::assertSame(12, $data->data['J']); self::assertSame(['(Intercept)', 'study_hours'], $data->columns);
        $lmer = \Phit\fit_lmer($formula, $columns);
        $fit = $this->model(self::FORMULA_PROGRAM, 'family_formula')->sample(data: $data, chains: 4, parallel_chains: 4, iter_warmup: 1000, iter_sampling: 2000, seed: 2024, adapt_delta: 0.95, show_messages: false, refresh: 0, output_dir: $this->dir, diagnostics: null);
        $summary = array_column($fit->summary(['beta', 'tau', 'sigma']), null, 'variable');
        // A smoke-test convergence bound; tau's R-hat varies by CmdStan release (1.0103 on 2.37 with 4 x 1000 draws).
        foreach ($summary as $row) { self::assertLessThan(1.05, $row['rhat'], $row['variable']); }
        foreach ([0, 1] as $k) { self::assertEqualsWithDelta($lmer['coefficients'][$k], $summary['beta['.($k + 1).']']['mean'], 0.5 * $lmer['std_errors'][$k], 'beta['.($k + 1).']'); }
        self::assertEqualsWithDelta($lmer['sigma'], $summary['sigma']['mean'], 0.05 * $lmer['sigma']);
        $tau = sqrt($lmer['random'][0]['covariance'][0][0]); self::assertEqualsWithDelta($tau, $summary['tau']['mean'], 0.35 * $tau);
    }
    public function testTableDataRunsBernoulli(): void
    {
        $table = new class { public function toColumns(): array { return ['y' => [0, 1, 0, 0, 0, 0, 0, 0, 0, 1]]; } };
        $model = new CmdStanModel(__DIR__.'/../examples/bernoulli.stan');
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        $fit = $model->compile()->optimize(data: $table, seed: 1, show_messages: false, output_dir: $this->dir);
        self::assertEqualsWithDelta(0.2, $fit->mle()['theta'], 1e-3);
    }
}
