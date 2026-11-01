<?php
// The timed region of cmdstanphp.php, split into phases. Prints milliseconds per phase.
require __DIR__.'/../../../vendor/autoload.php';

use function CmdStan\cmdstan_model;

[, $exe, $out] = $argv;
$data = ['J' => 8, 'y' => [28, 8, -3, 7, -1, 1, 18, 12], 'sigma' => [15, 10, 16, 11, 9, 11, 10, 18]];

$t0 = hrtime(true);
$model = cmdstan_model(exeFile: $exe);
$t1 = hrtime(true);
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
$t2 = hrtime(true);
$summary = $fit->summary(
    ['mu', 'tau', 'theta'],
    measures: [
        'mean', 'sd', 'quantile2', 'rhat', 'ess_bulk',
    ],
);
$t3 = hrtime(true);

$ms = fn($a, $b) => ($b - $a) / 1e6;
echo json_encode(['load' => $ms($t0, $t1), 'sample' => $ms($t1, $t2), 'summary' => $ms($t2, $t3), 'ms' => $ms($t0, $t3), 'csv' => $fit->outputFiles(), 'mu' => $summary[0]['mean']]), "\n";
