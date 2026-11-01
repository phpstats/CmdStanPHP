<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, ModelMethods, NativeBuild, Process, StanFunctions};
use PHPUnit\Framework\Attributes\Group;
#[Group('compatibility')]
final class NativeCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            CmdStan::path(); NativeBuild::requireFfi();
            Process::run(['Rscript', '-e', 'stopifnot(requireNamespace("cmdstanr", quietly=TRUE), requireNamespace("jsonlite", quietly=TRUE), requireNamespace("posterior", quietly=TRUE), requireNamespace("Rcpp", quietly=TRUE))']);
        } catch (\Throwable $e) { self::markTestSkipped('R with cmdstanr, Rcpp, posterior, jsonlite, CmdStan and PHP FFI required: '.$e->getMessage()); }
    }
    public function testModelMethodsAndFunctionsMatchCmdStanR(): void
    {
        foreach (['php', 'r'] as $dir) { mkdir($this->dir.'/'.$dir); copy(__DIR__.'/fixtures/native.stan', $this->dir.'/'.$dir.'/native.stan'); }
        $data = \CmdStan\write_stan_json(['N' => 4, 'y' => [0.5, 1.5, -0.3, 2.2]], $this->dir.'/data.json');
        $u = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 1.1, 1.2, 1.3, 1.4, 0.1, -0.2, 0.3, 0.1, -0.4];
        $variables = ['mu' => 0.4, 'sigma' => 1.3, 'a' => [[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]], 'm' => [[0.5, -0.5, 1.5], [2.5, -1.0, 0.0]], 's' => [0.2, 0.3, 0.5], 'S' => [[2.0, 0.5], [0.5, 1.0]]];
        file_put_contents($this->dir.'/input.json', json_encode(['seed' => 1, 'unconstrained' => $u, 'variables' => $variables], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        Process::run(['Rscript', __DIR__.'/fixtures/native_reference.R', $this->dir.'/r/native.stan', $data, $this->dir.'/input.json', $this->dir.'/reference.json'], env: ['CMDSTAN' => CmdStan::path()], timeout: 900);
        $ref = json_decode(file_get_contents($this->dir.'/reference.json'), true, flags: JSON_THROW_ON_ERROR);
        $model = ModelMethods::compile($this->dir.'/php/native.stan', $data, 1);
        $case = 'native.stan, 19 unconstrained values';
        self::near(['model-methods', $case, 'log_prob()'], $ref['log_prob'], $model->logProb($u), 1e-10);
        self::near(['model-methods', $case, 'log_prob() without Jacobian'], $ref['log_prob_no_jacobian'], $model->logProb($u, jacobian: false), 1e-10);
        foreach ([true => 'grad_log_prob', false => 'grad_log_prob_no_jacobian'] as $jacobian => $key) {
            $grad = $model->gradLogProb($u, (bool)$jacobian); $label = $jacobian ? 'grad_log_prob()' : 'grad_log_prob() without Jacobian';
            self::near(['model-methods', $case, $label.' log density'], $ref[$key]['log_prob'], $grad['log_prob'], 1e-10);
            self::near(['model-methods', $case, $label.' gradient'], $ref[$key]['gradient'], $grad['gradient'], 1e-10, message: $key);
        }
        $hessian = $model->hessian($u);
        self::near(['model-methods', $case, 'hessian() log density'], $ref['hessian']['log_prob'], $hessian['log_prob'], 1e-10);
        self::near(['model-methods', $case, 'hessian() gradient'], $ref['hessian']['grad_log_prob'], $hessian['grad_log_prob'], 1e-8);
        self::near(['model-methods', $case, 'hessian() matrix'], $ref['hessian']['hessian'], $hessian['hessian'], 1e-6, message: 'hessian');
        self::near(['model-methods', $case, 'unconstrain_variables()'], $ref['unconstrain_variables'], $model->unconstrainVariables($variables), 1e-12);
        $constrained = $model->constrainVariables($u, generatedQuantities: false);
        self::exact(['model-methods', $case, 'constrain_variables() names'], array_keys($ref['constrain_variables']), array_keys($constrained));
        $expected = []; $actual = [];
        foreach ($ref['constrain_variables'] as $name => $value) { $expected[$name] = is_array($value) && count($value) === 1 && !is_array($constrained[$name]) ? $value[0] : $value; $actual[$name] = $constrained[$name]; }
        self::near(['model-methods', $case, 'constrain_variables() values'], $expected, $actual, 1e-12, message: 'constrain_variables');
        foreach ($model->variableSkeleton() as $name => $dims) { self::exact(['model-methods', $case, 'variable_skeleton()'], (array)$ref['skeleton'][$name], $dims === [] ? [1] : $dims, $name); }
        self::exact(['model-methods', $case, 'unconstrained parameter names'], $ref['unconstrained_names'], $model->unconstrainedParamNames());
        $unconstrained = $model->unconstrainDraws($ref['draws']);
        self::assertCount(count($ref['unconstrained_draws']), $unconstrained);
        self::near(['model-methods', 'native.stan, CmdStanR draws', 'unconstrain_draws()'], $ref['unconstrained_draws'], $unconstrained, 1e-10, message: 'unconstrained draws');

        $f = StanFunctions::compile($this->dir.'/php/native.stan');
        $expected = $ref['functions'];
        self::near(['stan-functions', 'add(1.5, 2)', 'real'], $expected['add'], $f->add(1.5, 2), 1e-15);
        self::near(['stan-functions', 'scale([1, 2, 3], 2.0)', 'vector'], $expected['scale'], $f->scale([1, 2, 3], 2.0), 1e-15);
        self::near(['stan-functions', 'outer([1, 2], [3, 4, 5])', 'matrix'], $expected['outer'], $f->outer([1, 2], [3, 4, 5]), 1e-15);
        self::near(['stan-functions', 'twice([1.5, -2])', 'array'], $expected['twice'], $f->twice([1.5, -2]), 1e-15);
        self::exact(['stan-functions', 'pairs(3)', 'integer array'], $expected['pairs'], $f->pairs(3));
        self::near(['stan-functions', 'cmul([1, 2], [3, -1])', 'complex'], $expected['cmul'], $f->cmul([1, 2], [3, -1]), 1e-15);
        self::near(['stan-functions', 'draw_rng(0.5), seed 42', 'RNG draw'], $expected['draw_rng'], $f->draw_rng(0.5, seed: 42), 1e-14);
        self::near(['stan-functions', 'shifted_lpdf(0.3, 1.0)', 'log density'], $expected['shifted_lpdf'], $f->shifted_lpdf(0.3, 1.0), 1e-14);
        self::near(['stan-functions', 'counts_lpmf(2, 1.5)', 'log mass'], $expected['counts_lpmf'], $f->counts_lpmf(2, 1.5), 1e-14);
    }
}
