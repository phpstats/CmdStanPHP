<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel};
use PHPUnit\Framework\Attributes\Group;
use function CmdStan\write_stan_file;
/** CmdStanR 1.0's tuple and complex support: data, CSV names and sizes, selection by tuple name, and init from a fit. */
#[Group('integration')]
final class TupleComplexTest extends TestCase
{
    private const PROGRAM = <<<'STAN'
        data {
          int N;
          tuple(int, vector[2]) d_pair;
          array[2] tuple(real, real) d_pairs;
          complex_vector[2] d_z;
        }
        parameters {
          real mu;
          tuple(real, vector[2]) p;
        }
        model {
          mu ~ normal(d_pair.1, 1);
          p.1 ~ normal(d_pairs[2].2, 1);
          p.2 ~ normal(get_real(d_z[1]), 1);
        }
        generated quantities {
          complex z = to_complex(mu, p.1);
          tuple(int, tuple(real, complex)) nested = (N, (mu, z));
        }
        STAN;
    private function model(): CmdStanModel
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        return new CmdStanModel(write_stan_file(self::PROGRAM, $this->dir.'/tuples.stan'));
    }
    public function testTuplesAndComplexNumbersRoundTrip(): void
    {
        $model = $this->model();
        $data = ['N' => 3, 'd_pair' => [4, [1, 2]], 'd_pairs' => [[1, 2], [3, 5]], 'd_z' => [[1, 2], [3, 4]]];
        $fit = $model->sample(data: $data, chains: 2, iter_warmup: 100, iter_sampling: 50, seed: 1, show_messages: false, output_dir: $this->dir);
        $written = json_decode((string)file_get_contents($fit->dataFile()), true);
        self::assertSame(['1' => 4, '2' => [1.0, 2.0]], $written['d_pair']); self::assertSame(['1' => 3.0, '2' => 5.0], $written['d_pairs'][1]);
        $meta = $fit->metadata();
        self::assertContains('p:2[1]', $meta['variables']); self::assertContains('z[imag]', $meta['variables']); self::assertContains('nested:2:2[real]', $meta['variables']);
        self::assertSame([1], $meta['stan_variable_sizes']['p']); self::assertSame([2], $meta['stan_variable_sizes']['z']); self::assertSame(2, $meta['num_chains']);
        self::assertSame(['p:1', 'p:2[1]', 'p:2[2]'], array_keys($fit->draws('p', 'draws_matrix')[0]), 'a tuple name selects all its columns');
        self::assertSame(['nested:1', 'nested:2:1', 'nested:2:2[real]', 'nested:2:2[imag]'], array_keys($fit->draws('nested', 'draws_matrix')[0]));
        $again = $model->sample(data: $data, init: $fit, chains: 2, iter_warmup: 50, iter_sampling: 10, seed: 2, show_messages: false, output_dir: $this->dir);
        $init = json_decode((string)file_get_contents($again->metadata()['init'][0]), true);
        self::assertSame(['mu', 'p'], array_keys($init)); self::assertSame(['1', '2'], array_map('strval', array_keys($init['p']))); self::assertCount(2, $init['p']['2']);
        try { $model->sample(data: ['N' => 3], show_messages: false, output_dir: $this->dir); self::fail('Missing data should be refused.'); }
        catch (\InvalidArgumentException $e) { self::assertSame('Missing input data for the following data variables: d_pair, d_pairs, d_z.', $e->getMessage()); }
    }
}
