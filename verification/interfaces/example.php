<?php
require 'vendor/autoload.php';

use function CmdStan\cmdstan_model;

$model = cmdstan_model('schools.stan');

$fit = $model->sample(
    data: [
        'J' => 8,
        'y' => [28, 8, -3, 7, -1, 1, 18, 12],
        'sigma' => [15, 10, 16, 11, 9, 11, 10, 18],
    ],
    seed: 20261101,
    chains: 4,
    iter_warmup: 1000,
    iter_sampling: 1000,
);

print_r($fit->summary(['mu', 'tau', 'theta'], measures: ['mean', 'sd', 'quantile2', 'rhat', 'ess_bulk']));
