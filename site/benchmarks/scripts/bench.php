<?php
/**
 * Sampling overhead of CmdStanPHP against CmdStanR and against running the CmdStan executable directly, on the same
 * compiled models with the same seed and settings (4 parallel chains, 1,000 warmup and 1,000 sampling iterations).
 * Writes site/benchmarks/sample.json and site/benchmarks/summary.json.
 *
 *   php site/benchmarks/scripts/bench.php [reps=7]
 *
 * Needs CmdStan (CMDSTAN or ~/.cmdstan) and Rscript with cmdstanr, posterior, and jsonlite.
 */
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use CmdStan\{CmdStan, CmdStanModel, Process};

const SEED = 20261019;
$reps = (int)($argv[1] ?? 7);
$site = dirname(__DIR__, 2); $root = dirname($site);
$tmp = sys_get_temp_dir().'/cmdstanphp-bench-'.getmypid(); @mkdir($tmp);
$cases = [
    'bernoulli' => [$root.'/examples/bernoulli.stan', ['N' => 10, 'y' => [0, 1, 0, 0, 0, 0, 0, 0, 0, 1]]],
    'eight schools' => [$site.'/models/eight_schools.stan', json_decode(file_get_contents($site.'/data/eight_schools.json'), true)],
];
$ms = fn(int $start) => (hrtime(true) - $start) / 1e6;
$median = function (array $v): float { sort($v); $n = count($v); return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; };
$results = [];
foreach ($cases as $case => [$stan, $data]) {
    $model = new CmdStanModel($stan); $model->compile();
    $dataFile = \CmdStan\write_stan_json($data, $tmp.'/'.basename($stan, '.stan').'.json');
    $options = ['data' => $dataFile, 'seed' => SEED, 'chains' => 4, 'parallel_chains' => 4, 'iter_warmup' => 1000, 'iter_sampling' => 1000, 'refresh' => 0, 'show_messages' => false];
    for ($r = -1; $r < $reps; ++$r) {   // run -1 is an untimed warm-up
        // CmdStan directly: the four chain processes CmdStanPHP and CmdStanR start, launched and awaited with no interface.
        $dir = $tmp.'/direct-'.$r; @mkdir($dir); $procs = []; $start = hrtime(true);
        for ($id = 1; $id <= 4; ++$id) {
            $cmd = [$model->exe_file(), 'id='.$id, 'random', 'seed='.SEED, 'data', 'file='.$dataFile, 'output', 'file='.$dir.'/out-'.$id.'.csv', 'refresh=0', 'method=sample', 'num_samples=1000', 'num_warmup=1000'];
            $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        }
        foreach ($procs as $p) { if (proc_close($p) !== 0) { throw new RuntimeException('CmdStan failed.'); } }
        $direct = $ms($start);

        $dir = $tmp.'/php-'.$r; @mkdir($dir);
        $start = hrtime(true); $fit = $model->sample($options + ['output_dir' => $dir]); $sample = $ms($start);
        $start = hrtime(true); $fit->summary(); $summary = $ms($start);
        $fit = \CmdStan\read_cmdstan_csv($fit->output_files());
        $start = hrtime(true); $fit->summary(measures: ['default_summary_measures', 'default_convergence_measures']); $measures = $ms($start);
        if ($r < 0) { continue; }
        $results[$case]['CmdStan (direct)']['sample'][] = $direct; $results[$case]['CmdStanPHP']['sample'][] = $sample;
        $results[$case]['CmdStanPHP summary()']['summary'][] = $summary; $results[$case]['CmdStanPHP summary(measures)']['summary'][] = $measures;
    }
    $out = $tmp.'/r-'.basename($stan, '.stan').'.json';
    Process::run(['Rscript', __DIR__.'/bench.R', $stan, $model->exe_file(), $dataFile, (string)$reps, (string)SEED, $out], env: ['CMDSTAN' => CmdStan::path()], timeout: 3600);
    $r = json_decode(file_get_contents($out), true, flags: JSON_THROW_ON_ERROR);
    $results[$case]['CmdStanR']['sample'] = (array)$r['sample']; $results[$case]['CmdStanR summary()']['summary'] = (array)$r['summary'];
    $versions = ['r' => $r['r'], 'cmdstanr' => $r['cmdstanr'], 'posterior' => $r['posterior']];
}

$environment = [
    'cpu' => trim(Process::run(['sysctl', '-n', 'machdep.cpu.brand_string'])['stdout'] ?? '') ?: php_uname('m'),
    'php' => PHP_VERSION, 'date' => date('Y-m-d'),
    'commit' => trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD')),
    'os' => php_uname('s').' '.php_uname('r'), 'cmdstan' => CmdStan::version(),
] + $versions;
$rows = function (string $measure) use ($results, $median): array {
    $out = [];
    foreach ($results as $case => $implementations) {
        foreach ($implementations as $implementation => $measures) {
            if (!isset($measures[$measure])) { continue; }
            $reps = array_map(fn($v) => round($v, 1), $measures[$measure]);
            $out[] = ['case' => $case, 'implementation' => $implementation, 'value' => round($median($measures[$measure]), 1), 'reps' => $reps];
        }
    }
    return $out;
};
$write = fn(string $file, array $doc) => file_put_contents($site.'/benchmarks/'.$file, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
$write('sample.json', [
    'title' => 'Sampling wall-clock time',
    'description' => 'Wall-clock time of sample() with 4 parallel chains, 1,000 warmup and 1,000 sampling iterations, and seed '.SEED.', from the call until the fit is returned. Both interfaces read the output CSVs and check divergences, tree depth, and E-BFMI before returning. All three run the same compiled executable with the same arguments. "CmdStan (direct)" launches the four chain processes and waits for them with no interface, so the gap above it is the interface\'s own overhead: argument and data handling, process management, and reading the output. Median of '.$reps.' runs.',
    'measure' => 'sample() wall-clock time', 'unit' => 'ms', 'environment' => $environment, 'rows' => $rows('sample'),
]);
$write('summary.json', [
    'title' => 'Posterior summary time',
    'description' => 'Time from a fit object that has not computed a summary yet (4 chains of 1,000 draws) to its posterior summary of every variable. CmdStanR\'s fit$summary() uses posterior::summarise_draws(). CmdStanPHP\'s default summary() runs CmdStan\'s stansummary for R-hat and bulk ESS and computes the rest in PHP. summary(measures: ...) computes posterior\'s default summary and convergence measures in pure PHP. CmdStanR\'s time includes reading the draws from the CSVs, which cmdstanr defers until first use, while CmdStanPHP has already parsed them in sample() or read_cmdstan_csv(). Median of'.$reps.' runs.',
    'measure' => 'summary() wall-clock time', 'unit' => 'ms', 'environment' => $environment, 'rows' => $rows('summary'),
]);
exec('rm -rf '.escapeshellarg($tmp));
echo json_encode(array_map(fn($i) => array_map(fn($m) => array_map(fn($v) => round($median($v), 1), $m), $i), $results), JSON_PRETTY_PRINT), "\n";
