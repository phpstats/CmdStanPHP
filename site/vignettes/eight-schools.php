<?php
// Eight schools: a non-centered hierarchical model, read from cached CmdStan output (site/scripts/generate.php).
declare(strict_types=1);

use function CmdStan\{mcmc_intervals, pareto_k_plot, read_cmdstan_csv};

return [
    'inputs' => [
        'variables' => ['type' => 'radio', 'label' => 'Parameters', 'options' => ['theta' => 'School effects (theta)', 'hyper' => 'Population (mu, tau)'], 'default' => 'theta'],
        'prob' => ['type' => 'select', 'label' => 'Inner interval', 'options' => ['0.5' => '50%', '0.8' => '80%'], 'default' => '0.5'],
        'prob_outer' => ['type' => 'select', 'label' => 'Outer interval', 'options' => ['0.9' => '90%', '0.95' => '95%'], 'default' => '0.9'],
    ],
    'run' => function (array $input, string $data): array {
        $fit = read_cmdstan_csv(glob($data.'/draws/eight_schools/*.csv'));
        $schools = json_decode(file_get_contents($data.'/eight_schools.json'), true);
        $variables = $input['variables'] === 'hyper' ? ['mu', 'tau'] : 'theta';
        $prob = (float)$input['prob']; $outer = (float)$input['prob_outer'];
        $lower = (1 - $outer) / 2; $upper = 1 - $lower;

        $summary = $fit->summary($variables, [$lower, 0.5, $upper], ['mean', 'sd', 'quantile2', 'rhat', 'ess_bulk', 'ess_tail']);
        $all = $fit->summary(['mu', 'tau', 'theta'], measures: ['rhat', 'ess_bulk']);
        $diagnostics = $fit->diagnostic_summary();
        $loo = $fit->loo();
        $q = fn(float $p) => 'q'.CmdStan\Summary::percent($p);

        $rows = array_map(fn($r) => [$r['variable'], $r['mean'], $r['sd'], $r[$q($lower)], $r[$q(0.5)], $r[$q($upper)], $r['rhat'], $r['ess_bulk'], $r['ess_tail']], $summary);
        $observed = array_map(fn($j) => ['school '.($j + 1), $schools['y'][$j], $schools['sigma'][$j]], range(0, $schools['J'] - 1));
        $estimates = array_map(fn($name, $row) => [$name, $row['Estimate'], $row['SE']], array_keys($loo['estimates']), array_values($loo['estimates']));

        return [
            ['type' => 'text', 'content' => 'Eight schools ran a coaching program for the SAT-V, and each reported an estimated effect \\( y_j \\) with a standard error \\( \\sigma_j \\). The hierarchical model pools the schools toward a common mean \\( \\mu \\) by an amount set by the between-school spread \\( \\tau \\). The non-centered form, \\( \\theta_j = \\mu + \\tau \\, \\tilde\\theta_j \\) with \\( \\tilde\\theta_j \\sim \\mathrm{normal}(0, 1) \\), avoids the funnel that makes the centered model diverge.'],
            ['type' => 'table', 'title' => 'Data', 'columns' => ['school', 'y (effect)', 'sigma (standard error)'], 'rows' => $observed, 'digits' => 0],
            ['type' => 'code',
                'chain' => <<<'PHP'
$model = CmdStan\cmdstan_model('eight_schools.stan');
$fit = $model->sample(data: 'eight_schools.json', seed: 20261019, chains: 4, parallel_chains: 4);

$summary = $fit->summary('theta', [0.05, 0.5, 0.95], ['mean', 'sd', 'quantile2', 'rhat', 'ess_bulk', 'ess_tail']);
$loo = $fit->loo();                     // PSIS-LOO from the log_lik generated quantity
$chart = CmdStan\mcmc_intervals($fit, 'theta', prob: 0.5, prob_outer: 0.9)->vegaLite();
PHP,
                'pipe' => <<<'PHP'
$fit = 'eight_schools.stan'
    |> CmdStan\cmdstan_model(...)
    |> (fn($model) => $model->sample(data: 'eight_schools.json', seed: 20261019, chains: 4, parallel_chains: 4));

$chart = $fit
    |> (fn($fit) => CmdStan\mcmc_intervals($fit, 'theta', prob: 0.5, prob_outer: 0.9))
    |> (fn($figure) => $figure->vegaLite());
PHP],
            ['type' => 'metrics', 'items' => [
                ['label' => 'Divergent transitions', 'value' => (int)array_sum($diagnostics['num_divergent']), 'format' => '%d'],
                ['label' => 'Largest R-hat', 'value' => max(array_column($all, 'rhat')), 'format' => '%.3f'],
                ['label' => 'Smallest bulk ESS', 'value' => min(array_column($all, 'ess_bulk')), 'format' => '%.0f'],
                ['label' => 'elpd_loo', 'value' => $loo['estimates']['elpd_loo']['Estimate'], 'format' => '%.2f'],
            ]],
            ['type' => 'table', 'title' => 'Posterior summary', 'columns' => ['variable', 'mean', 'sd', $q($lower), 'median', $q($upper), 'rhat', 'ess_bulk', 'ess_tail'], 'rows' => $rows, 'digits' => 2],
            ['type' => 'chart', 'title' => sprintf('Posterior intervals (%s%% and %s%%)', $prob * 100, $outer * 100), 'plot' => mcmc_intervals($fit, $variables, prob: $prob, prob_outer: $outer)->vegaLite(560, 260)],
            ['type' => 'table', 'title' => 'PSIS-LOO', 'columns' => ['estimate', 'value', 'SE'], 'rows' => $estimates, 'digits' => 2],
            ['type' => 'chart', 'title' => 'Pareto k by school', 'plot' => pareto_k_plot($loo)->vegaLite(560, 200)],
            ['type' => 'note', 'content' => sprintf('These are 4 chains of 1,000 draws each, sampled once with seed 20261019 and cached, since the site does not run CmdStan. Every number on this page is computed in PHP from those CSV files. With a Pareto k threshold of %.2f, PSIS-LOO is reliable for every school when no point is above the line.', $loo['k_threshold'])],
        ];
    },
];
