<?php
// One timed run: model from the precompiled executable, 4 parallel chains, summary. Prints the elapsed milliseconds.
require __DIR__.'/../../../vendor/autoload.php';

use function CmdStan\cmdstan_model;

[, $exe, $out] = $argv;
$data = ['J' => 8, 'y' => [28, 8, -3, 7, -1, 1, 18, 12], 'sigma' => [15, 10, 16, 11, 9, 11, 10, 18]];

$start = hrtime(true);
$model = cmdstan_model(exeFile: $exe);
$fit = $model->sample(
    data: $data,
    seed: 20261101,
    chains: 4,
    parallel_chains: 4,
    iter_warmup: 1000,
    iter_sampling: 1000,
    output_dir: $out,
    show_messages: false,
);
$summary = $fit->summary(
    ['mu', 'tau', 'theta'],
    measures: [
        'mean', 'sd', 'quantile2', 'rhat', 'ess_bulk',
    ],
);
$elapsed = (hrtime(true) - $start) / 1e6;

echo json_encode(['ms' => $elapsed, 'csv' => $fit->outputFiles(), 'mu' => $summary[0]['mean']]), "\n";
