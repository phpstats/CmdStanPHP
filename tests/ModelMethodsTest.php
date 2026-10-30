<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, ModelMethods, NativeBuild, Process};
use PHPUnit\Framework\Attributes\Group;
#[Group('integration')]
final class ModelMethodsTest extends TestCase
{
    private const NORMAL = 'data { int N; vector[N] y; } parameters { real mu; real<lower=0> sigma; } model { y ~ normal(mu, sigma); }';
    private const SHAPES = <<<'STAN'
        parameters {
          real mu;
          array[2, 3] real a;
          matrix[2, 3] m;
          simplex[3] s;
          cov_matrix[2] S;
          cholesky_factor_corr[3] L;
          ordered[2] o;
          array[2] vector<lower=0>[2] v;
        }
        transformed parameters { real<lower=0> e = exp(mu); array[2] vector[3] tv = {s, 2 * s}; }
        model { mu ~ std_normal(); to_array_1d(a) ~ std_normal(); to_vector(m) ~ std_normal(); S ~ wishart(4, identity_matrix(2)); L ~ lkj_corr_cholesky(2); o ~ normal(0, 3); for (i in 1:2) v[i] ~ exponential(1); }
        generated quantities { real r = normal_rng(0, 1); complex z = to_complex(mu, 2); tuple(real, array[2] int) t = (mu, {1, 2}); }
        STAN;
    private static array $built = [];
    private static ?string $cache = null;
    private function library(string $source): string
    {
        try { CmdStan::path(); NativeBuild::requireFfi(); } catch (\RuntimeException $e) { self::markTestSkipped('CmdStan and PHP FFI are required: '.$e->getMessage()); }
        if (self::$cache === null) { self::$cache = sys_get_temp_dir().'/cmdstanphp-native-test-'.bin2hex(random_bytes(6)); mkdir(self::$cache); }
        $key = md5($source);
        return self::$built[$key] ??= ModelMethods::build(\CmdStan\write_stan_file($source, self::$cache.'/m'.substr($key, 0, 8).'.stan'), timeout: 600);
    }
    public static function tearDownAfterClass(): void
    {
        if (self::$cache !== null) { array_map('unlink', glob(self::$cache.'/*') ?: []); rmdir(self::$cache); self::$cache = null; self::$built = []; }
    }
    private function normal(): ModelMethods { return new ModelMethods($this->library(self::NORMAL), ['N' => 3, 'y' => [0.5, 1.5, -1.0]]); }
    public function testLogDensityGradientAndHessianMatchAnalyticValues(): void
    {
        $model = $this->normal(); $y = [0.5, 1.5, -1.0]; [$mu, $tau] = [0.3, 0.2]; $sigma = exp($tau);
        self::assertSame(2, $model->numUnconstrained()); self::assertSame(['mu', 'sigma'], $model->unconstrainedParamNames());
        $ss = array_sum(array_map(fn($v) => ($v - $mu) ** 2, $y));
        $lp = -3 * log($sigma) - $ss / (2 * $sigma ** 2); // normal_lpdf with constants dropped (propto), as in CmdStanR
        self::assertEqualsWithDelta($lp, $model->logProb([$mu, $tau], jacobian: false), 1e-12);
        self::assertEqualsWithDelta($lp + $tau, $model->logProb([$mu, $tau]), 1e-12);
        $grad = $model->gradLogProb([$mu, $tau]);
        $analytic = [array_sum(array_map(fn($v) => $v - $mu, $y)) / $sigma ** 2, -3 + $ss / $sigma ** 2 + 1];
        self::assertEqualsWithDelta($lp + $tau, $grad['log_prob'], 1e-12); self::assertEqualsWithDelta($analytic, $grad['gradient'], 1e-10);
        $h = 1e-6;
        foreach ([0, 1] as $i) {
            $up = [$mu, $tau]; $down = [$mu, $tau]; $up[$i] += $h; $down[$i] -= $h;
            self::assertEqualsWithDelta(($model->logProb($up) - $model->logProb($down)) / (2 * $h), $grad['gradient'][$i], 1e-6);
        }
        $hessian = $model->hessian([$mu, $tau]);
        $expected = [[-3 / $sigma ** 2, -2 * array_sum(array_map(fn($v) => $v - $mu, $y)) / $sigma ** 2], [0.0, -2 * $ss / $sigma ** 2]]; $expected[1][0] = $expected[0][1];
        self::assertEqualsWithDelta($lp + $tau, $hessian['log_prob'], 1e-12); self::assertEqualsWithDelta($analytic, $hessian['grad_log_prob'], 1e-8);
        foreach ($expected as $i => $row) { self::assertEqualsWithDelta($row, $hessian['hessian'][$i], 1e-5); }
        $noJacobian = $model->hessian([$mu, $tau], jacobian: false); self::assertEqualsWithDelta($lp, $noJacobian['log_prob'], 1e-12);
    }
    public function testConstrainUnconstrainRoundTripSkeletonAndColumnMajorOrder(): void
    {
        $model = new ModelMethods($this->library(self::SHAPES), null, 7);
        self::assertSame(['mu' => [], 'a' => [2, 3], 'm' => [2, 3], 's' => [3], 'S' => [2, 2], 'L' => [3, 3], 'o' => [2], 'v' => [2, 2], 'e' => [], 'tv' => [2, 3], 'r' => [], 'z' => [2], 't.1' => [], 't.2' => [2]], $model->variableSkeleton());
        self::assertSame(['mu', 'a', 'm', 's', 'S', 'L', 'o', 'v'], array_keys($model->variableSkeleton(false, false)));
        self::assertSame(['mu', 'a', 'm', 's', 'S', 'L', 'o', 'v', 'r', 'z', 't.1', 't.2'], array_keys($model->variableSkeleton(false, true)));
        self::assertSame(1 + 6 + 6 + 2 + 3 + 3 + 2 + 4, $model->numUnconstrained());
        $names = $model->constrainedParamNames();
        self::assertSame(['a[1,1]', 'a[2,1]', 'a[1,2]'], array_slice($names, 1, 3)); self::assertSame(['z.real', 'z.imag', 't:1', 't:2[1]', 't:2[2]'], array_slice($names, -5));
        $L = [[1.0, 0.0, 0.0], [0.6, 0.8, 0.0], [0.0, 0.6, 0.8]];
        $variables = ['mu' => 0.25, 'a' => [[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]], 'm' => [[1.5, 2.5, 3.5], [4.5, 5.5, 6.5]], 's' => [0.2, 0.3, 0.5], 'S' => [[2.0, 0.5], [0.5, 1.0]], 'L' => $L, 'o' => [-1.0, 2.0], 'v' => [[0.5, 1.5], [2.5, 3.5]], 'ignored' => 1];
        $u = $model->unconstrainVariables($variables);
        self::assertCount($model->numUnconstrained(), $u); self::assertSame([1.0, 2.0, 3.0, 4.0, 5.0, 6.0], array_slice($u, 1, 6), 'Stan stores array parameters row-major in the unconstrained vector.');
        self::assertSame([1.5, 4.5, 2.5, 5.5, 3.5, 6.5], array_slice($u, 7, 6), 'Matrices are column-major.');
        $c = $model->constrainVariables($u);
        foreach (array_diff_key($variables, ['ignored' => 1]) as $name => $value) { self::assertEqualsWithDelta($value, $c[$name], 1e-12, $name); }
        self::assertEqualsWithDelta(exp(0.25), $c['e'], 1e-12); self::assertEqualsWithDelta([[0.2, 0.3, 0.5], [0.4, 0.6, 1.0]], $c['tv'], 1e-12);
        self::assertSame([0.25, 2.0], $c['z']); self::assertSame(0.25, $c['t.1']); self::assertSame([1.0, 2.0], $c['t.2']); self::assertIsFloat($c['r']);
        self::assertSame(['mu', 'a', 'm', 's', 'S', 'L', 'o', 'v'], array_keys($model->constrainVariables($u, false, false)));
        $gq = $model->constrainVariables($u, false, true);
        self::assertSame(['mu', 'a', 'm', 's', 'S', 'L', 'o', 'v', 'r', 'z', 't.1', 't.2'], array_keys($gq)); self::assertSame([0.25, 2.0], $gq['z']); self::assertSame([1.0, 2.0], $gq['t.2']);
        self::assertCount(35 + 1 + 2 + 1 + 2, $model->constrainedParamNames(false, true));
        $random = array_map(fn($i) => sin($i + 1.0), range(0, $model->numUnconstrained() - 1));
        self::assertEqualsWithDelta($random, $model->unconstrainVariables($model->constrainVariables($random)), 1e-10);
    }
    public function testUnconstrainDrawsFromFitRoundTripsThroughConstrain(): void
    {
        $this->library(self::NORMAL);
        $source = \CmdStan\write_stan_file(self::NORMAL, $this->dir.'/normal.stan');
        $data = ['N' => 3, 'y' => [0.5, 1.5, -1.0]];
        $fit = (new CmdStanModel($source))->sample(data: $data, chains: 1, iter_warmup: 100, iter_sampling: 20, seed: 3, sig_figs: 18, show_messages: false, output_dir: $this->dir);
        $model = ModelMethods::compile($source, $data);
        $draws = $fit->draws(format: 'draws_df'); $unconstrained = $model->unconstrainDraws($draws);
        self::assertCount(20, $unconstrained);
        foreach ($draws as $i => $draw) {
            self::assertEqualsWithDelta([$draw['mu'], log($draw['sigma'])], $unconstrained[$i], 1e-12);
            self::assertEqualsWithDelta($draw['lp__'], $model->logProb($unconstrained[$i]), 1e-8, 'lp__ is the jacobian-adjusted, constant-dropping density');
        }
    }
    public function testGeneratedQuantitiesUseASeededPersistentRng(): void
    {
        $library = $this->library(self::SHAPES); $u = array_fill(0, 27, 0.1);
        $draw = fn(ModelMethods $m) => $m->constrainVariables($u, false, true)['r'];
        $a = new ModelMethods($library, null, 11); $b = new ModelMethods($library, null, 11); $c = new ModelMethods($library, null, 12);
        $first = [$draw($a), $draw($a)];
        self::assertNotSame($first[0], $first[1]); self::assertSame($first, [$draw($b), $draw($b)]); self::assertNotSame($first[0], $draw($c));
        $restored = unserialize(serialize($a)); self::assertSame($first[0], $draw($restored), 'Unserialized objects reconnect with a fresh RNG.');
        $a->seedRng(11); self::assertSame($first[0], $draw($a));
        $clone = clone $b; self::assertSame($first[0], $draw($clone));
    }
    public function testValidationAndStanErrors(): void
    {
        $model = $this->normal();
        try { $model->logProb([1.0]); self::fail('Expected length error.'); }
        catch (\InvalidArgumentException $e) { self::assertSame('Model has 2 unconstrained parameter(s), but 1 were provided!', $e->getMessage()); }
        try { $model->unconstrainVariables(['mu' => 1.0]); self::fail('Expected missing parameter error.'); }
        catch (\InvalidArgumentException $e) { self::assertSame('Model parameter(s): sigma not provided!', $e->getMessage()); }
        try { $model->unconstrainVariables(['mu' => [1.0], 'sigma' => 1.0]); self::fail('Expected shape error.'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('dimensions []', $e->getMessage()); }
        try { $model->unconstrainDraws([['mu' => 1.0]]); self::fail('Expected missing column error.'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('sigma', $e->getMessage()); }
        try { $model->logProb([0.0, NAN]); self::fail('Expected Stan error.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Scale parameter', $e->getMessage()); }
        try { new ModelMethods($model->library(), ['N' => 2, 'y' => [1.0]]); self::fail('Expected data error.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('dims declared=(2)', $e->getMessage()); }
        $this->expectException(\InvalidArgumentException::class); $model->logProb(['mu' => 1.0, 'sigma' => 1.0]);
    }
    public function testLibraryIsCachedAndRebuiltWhenSourceChanges(): void
    {
        $this->library(self::NORMAL);
        $source = \CmdStan\write_stan_file(self::NORMAL, $this->dir.'/cached.stan');
        $first = ModelMethods::build($source); self::assertSame($first, ModelMethods::build($source)); self::assertStringStartsWith(realpath($this->dir).'/cached_model_methods-', $first);
        file_put_contents($source, str_replace('normal(mu, sigma)', 'normal(mu, 2 * sigma)', self::NORMAL));
        $second = ModelMethods::build($source); self::assertNotSame($first, $second); self::assertFileDoesNotExist($first);
        self::assertEqualsWithDelta(-log(2) - 0.125, (new ModelMethods($second, ['N' => 1, 'y' => [1.0]]))->logProb([0.0, 0.0]), 1e-12);
    }
    public function testFfiRequirementIsReported(): void
    {
        $code = 'require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).'; try { CmdStan\NativeBuild::requireFfi(); echo "ok"; } catch (RuntimeException $e) { echo $e->getMessage(); }';
        self::assertStringContainsString('ffi.enable=true', Process::run([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code])['stdout']);
    }
}
