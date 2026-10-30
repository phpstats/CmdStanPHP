<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanFit, CmdStanModel, NativeBuild, Summary};
use PHPUnit\Framework\Attributes\Group;
/** Fit- and model-level wiring of model methods, exposed functions, fit inits, LOO/WAIC, draws_rvars and summary measures. */
#[Group('integration')]
final class FitIntegrationTest extends TestCase
{
    private const MODEL = <<<'STAN'
        functions { real twice(real x) { return 2 * x; } }
        data { int N; vector[N] y; }
        parameters { real mu; real<lower=0> sigma; vector[2] z; }
        model { y ~ normal(mu, sigma); z ~ std_normal(); }
        generated quantities { vector[N] log_lik; for (n in 1:N) log_lik[n] = normal_lpdf(y[n] | mu, sigma); }
        STAN;
    private function model(): CmdStanModel
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        file_put_contents($this->dir.'/wired.stan', self::MODEL);
        return (new CmdStanModel($this->dir.'/wired.stan'));
    }
    private function data(): array { return ['N' => 6, 'y' => [0.3, -1.2, 0.8, 1.9, 0.1, -0.4]]; }
    private function sample(CmdStanModel $model, mixed ...$options): CmdStanFit
    {
        return $model->sample(...$options + ['data' => $this->data(), 'chains' => 2, 'iter_warmup' => 200, 'iter_sampling' => 100, 'seed' => 7, 'show_messages' => false, 'refresh' => 0, 'output_dir' => $this->dir]);
    }
    public function testModelMethodsOnFits(): void
    {
        try { NativeBuild::requireFfi(); } catch (\RuntimeException $e) { self::markTestSkipped($e->getMessage()); }
        $model = $this->model(); $fit = $this->sample($model);
        $u = $fit->unconstrainVariables(['mu' => 0.5, 'sigma' => 2.0, 'z' => [0.1, -0.2]]);
        self::assertEqualsWithDelta([0.5, log(2.0), 0.1, -0.2], $u, 1e-12);
        $constrained = $fit->constrainVariables($u, generatedQuantities: false); self::assertEqualsWithDelta(2.0, $constrained['sigma'], 1e-12);
        self::assertSame(['mu' => [], 'sigma' => [], 'z' => [2]], $fit->variableSkeleton(false, false));
        $grad = $fit->gradLogProb($u); self::assertEqualsWithDelta($fit->logProb($u), $grad['log_prob'], 1e-12); self::assertCount(4, $grad['gradient']);
        self::assertCount(4, $fit->hessian($u)['hessian']);
        $unconstrained = $fit->unconstrainDraws(format: 'draws_matrix'); self::assertCount(200, $unconstrained);
        $first = $fit->draws(['sigma'], 'draws_matrix')[0]['sigma']; self::assertEqualsWithDelta(log($first), $unconstrained[0]['sigma'], 1e-9);
        self::assertCount(100, $fit->unconstrainDraws(files: [$fit->outputFiles()[1]], format: 'draws_array'));
        $imported = CmdStanFit::fromCsv($fit->outputFiles());
        try { $imported->logProb($u); self::fail('Imported fits need the model.'); } catch (\RuntimeException) {}
        $imported->initModelMethods(model: $model); self::assertSame(['mu', 'sigma', 'z[1]', 'z[2]'], array_keys($imported->unconstrainDraws(format: 'draws_matrix')[0]));
        self::assertEqualsWithDelta(4.0, $fit->exposeFunctions()->twice(2.0), 1e-12); self::assertEqualsWithDelta(3.0, $model->exposeFunctions()->twice(1.5), 1e-12);
        self::assertSame($fit->logProb($u), unserialize(serialize($fit))->logProb($u));
    }
    public function testInitFromFitsLooRvarsAndMeasures(): void
    {
        $model = $this->model(); $fit = $this->sample($model);
        $mle = $model->optimize(data: $this->data(), seed: 3, show_messages: false, output_dir: $this->dir);
        $this->sample($model, init: $mle, iter_warmup: 0, iter_sampling: 1, adapt_engaged: false, output_dir: $this->dir.'/warm');
        $inits = glob($this->dir.'/warm/*-init-*.json'); self::assertCount(2, $inits);
        foreach ($inits as $file) { $init = json_decode(file_get_contents($file), true); self::assertEqualsWithDelta($mle->mle()['mu'], $init['mu'], 1e-12); self::assertCount(2, $init['z']); self::assertArrayNotHasKey('log_lik', $init); }
        $pathfinder = $model->pathfinder(data: $this->data(), seed: 3, show_messages: false, output_dir: $this->dir);
        self::assertSame(2, $this->sample($model, init: $pathfinder)->numChains()); self::assertSame(2, $this->sample($model, init: $fit)->numChains());
        $loo = $fit->loo(); self::assertCount(6, $loo['pointwise']); self::assertEqualsWithDelta($loo['elpd_loo'], -$loo['looic'] / 2, 1e-9);
        self::assertStringContainsString('of 6)', (string)\CmdStan\pareto_k_plot($loo)); self::assertStringContainsString('chain 2 = o', (string)\CmdStan\mcmc_trace($fit, 'mu'));
        self::assertStringContainsString('X = divergent transition', (string)\CmdStan\mcmc_scatter($fit, 'mu', 'sigma')); self::assertStringContainsString('z[2] |', (string)\CmdStan\mcmc_intervals($fit, ['mu', 'z']));
        self::assertCount(6, $fit->waic()['pointwise']);
        $rvars = $fit->draws(['mu', 'z'], 'draws_rvars'); self::assertSame([2], $rvars['z']['dims']); self::assertCount(200, $rvars['z']['draws'][1]); self::assertSame(2, $rvars['mu']['nchains']);
        $summary = $fit->summary('mu', measures: ['mean', 'rhat', 'range' => fn(array $x) => max($x) - min($x)])[0];
        self::assertSame(['variable', 'mean', 'rhat', 'range'], array_keys($summary)); self::assertEqualsWithDelta(Summary::mean(array_column($fit->draws('mu', 'draws_matrix'), 'mu')), $summary['mean'], 1e-12);
    }
}
