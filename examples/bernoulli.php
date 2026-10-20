<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use function CmdStan\cmdstan_model;
$model = cmdstan_model(__DIR__.'/bernoulli.stan');
$fit = $model->sample(data: ['N' => 10, 'y' => [0,1,0,0,0,0,0,0,0,1]], seed: 123, chains: 4, parallel_chains: 2, iter_warmup: 500, iter_sampling: 500, refresh: 0, show_messages: false);
$fit->print('theta');
echo json_encode($fit->diagnosticSummary(), JSON_PRETTY_PRINT)."\n";
