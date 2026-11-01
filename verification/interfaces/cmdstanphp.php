<?php
// Fits the model with CmdStanPHP and writes its summary to output/cmdstanphp/summary.json.
require __DIR__.'/../../vendor/autoload.php';

use function CmdStan\cmdstan_model;
use function CmdStan\cmdstan_version;

$dir = __DIR__.'/output/cmdstanphp';
@mkdir($dir, 0777, true);
copy(__DIR__.'/schools.stan', $dir.'/schools.stan');

$model = cmdstan_model($dir.'/schools.stan');
$fit = $model->sample(
    data: json_decode(file_get_contents(__DIR__.'/data.json'), true),
    seed: 20261101,
    chains: 4,
    parallel_chains: 4,
    chain_ids: [1, 2, 3, 4],
    iter_warmup: 1000,
    iter_sampling: 1000,
    init: 2,
    output_dir: $dir,
    show_messages: false,
);

$variables = array_merge(['mu', 'tau'], array_map(fn($j) => "theta[$j]", range(1, 8)));
$rows = [];
foreach ($fit->summary($variables, measures: ['mean', 'sd', 'quantile2', 'rhat', 'ess_bulk']) as $row) {
    $rows[] = ['variable' => $row['variable'], 'mean' => $row['mean'], 'sd' => $row['sd'], 'q5' => $row['q5'],
        'q95' => $row['q95'], 'rhat' => $row['rhat'], 'ess_bulk' => $row['ess_bulk']];
}
file_put_contents($dir.'/summary.json', json_encode([
    'versions' => ['CmdStanPHP' => Composer\InstalledVersions::getPrettyVersion('phpstats/cmdstanphp'),
        'CmdStan' => cmdstan_version(), 'PHP' => PHP_VERSION],
    'csv' => $fit->outputFiles(),
    'summary' => $rows,
], JSON_PRETTY_PRINT));
