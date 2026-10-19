<?php
// A varying-intercept regression built with StanData::fromFormula(), checked with posterior predictive draws.
// The posterior draws are cached CmdStan output (site/scripts/generate.php); replicated data are drawn here in PHP.
declare(strict_types=1);

use CmdStan\{Figure, Plot, StanData};
use function CmdStan\read_cmdstan_csv;

return [
    'inputs' => [
        'statistic' => ['type' => 'radio', 'label' => 'Test statistic', 'options' => ['mean' => 'Mean', 'sd' => 'Standard deviation', 'min' => 'Minimum', 'max' => 'Maximum'], 'default' => 'sd'],
        'replicates' => ['type' => 'select', 'label' => 'Replicated datasets drawn', 'options' => [25 => '25', 50 => '50', 100 => '100'], 'default' => 50],
    ],
    'run' => function (array $input, string $data): array {
        // The data, as named columns; tutoring is a factor with 'none' as its reference level.
        $handle = fopen($data.'/tutoring.csv', 'r'); $header = fgetcsv($handle, escape: ''); $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) { $rows[] = array_combine($header, $row); }
        fclose($handle);
        $levels = ['none', 'group', 'private'];
        $columns = [
            'score' => array_map('floatval', array_column($rows, 'score')), 'hours' => array_map('floatval', array_column($rows, 'hours')),
            'tutoring' => ['levels' => $levels, 'codes' => array_map(fn($t) => array_search($t, $levels, true), array_column($rows, 'tutoring')), 'ordered' => false],
            'school' => array_column($rows, 'school'),
        ];
        $stan = StanData::fromFormula('score ~ hours + tutoring + (1 | school)', $columns, drop_intercept: true);
        ['N' => $n, 'K' => $k, 'X' => $x, 'y' => $y, 'school_id' => $school] = $stan->data;

        $fit = read_cmdstan_csv(glob($data.'/draws/regression/*.csv'));
        $draws = $fit->draws_columns(['alpha', 'beta', 'sigma', 'a']); $s = count($draws['alpha']);

        // Replicated data: y_rep[n] ~ normal(alpha + X[n] * beta + a[school[n]], sigma) for each posterior draw.
        $rng = new Random\Randomizer(new Random\Engine\Mt19937(20261019));
        $normal = function () use ($rng): float { $u1 = ($rng->getInt(0, (1 << 53) - 1) + 0.5) / 9007199254740992; $u2 = $rng->getInt(0, (1 << 53) - 1) / 9007199254740992; return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2); };
        $stat = match ($input['statistic']) {
            'mean' => fn(array $v) => array_sum($v) / count($v),
            'sd' => function (array $v) { $m = array_sum($v) / count($v); return sqrt(array_sum(array_map(fn($e) => ($e - $m) ** 2, $v)) / (count($v) - 1)); },
            'min' => fn(array $v) => min($v),
            'max' => fn(array $v) => max($v),
        };
        $replicate = function (int $d) use ($draws, $x, $school, $n, $k, $normal): array {
            $out = [];
            for ($i = 0; $i < $n; ++$i) {
                $mu = $draws['alpha'][$d] + $draws['a['.$school[$i].']'][$d];
                for ($j = 0; $j < $k; ++$j) { $mu += $x[$i][$j] * $draws['beta['.($j + 1).']'][$d]; }
                $out[] = $mu + $draws['sigma'][$d] * $normal();
            }
            return $out;
        };
        $shown = (int)$input['replicates']; $every = intdiv($s, $shown); $observed = $stat($y);
        $tRep = []; $overlay = [];
        for ($d = 0; $d < $s; ++$d) {
            $yRep = $replicate($d); $tRep[] = $stat($yRep);
            if ($d % $every === 0 && count($overlay) < $shown) { $overlay[] = $yRep; }
        }
        $pValue = count(array_filter($tRep, fn($t) => $t >= $observed)) / $s;

        // Kernel densities of y and of each shown replicate on a shared grid.
        [$lo, $hi] = Plot::range(array_merge($y, ...$overlay)); $grid = [];
        for ($g = 0; $g < 60; ++$g) { $grid[] = round($lo + ($hi - $lo) * $g / 59, 2); }
        $lines = [];
        foreach ($overlay as $r => $values) { foreach (Plot::density($values, $grid) as $g => $density) { $lines[] = ['x' => $grid[$g], 'density' => round($density, 5), 'draw' => $r + 1, 'series' => 'y_rep']; } }
        foreach (Plot::density($y, $grid) as $g => $density) { $lines[] = ['x' => $grid[$g], 'density' => round($density, 5), 'draw' => 0, 'series' => 'y']; }
        $densityChart = ['$schema' => Figure::VEGA_LITE_SCHEMA, 'width' => 560, 'height' => 260, 'data' => ['values' => $lines],
            'mark' => ['type' => 'line', 'strokeWidth' => 1],
            'encoding' => [
                'x' => ['field' => 'x', 'type' => 'quantitative', 'title' => 'score'],
                'y' => ['field' => 'density', 'type' => 'quantitative', 'title' => 'density'],
                'detail' => ['field' => 'draw', 'type' => 'nominal'],
                'color' => ['field' => 'series', 'type' => 'nominal', 'title' => null, 'scale' => ['domain' => ['y_rep', 'y'], 'range' => ['#9ecae1', '#08306b']]],
                'strokeWidth' => ['condition' => ['test' => "datum.series === 'y'", 'value' => 2.5], 'value' => 0.8],
                'opacity' => ['condition' => ['test' => "datum.series === 'y'", 'value' => 1], 'value' => 0.5],
            ]];
        $statChart = ['$schema' => Figure::VEGA_LITE_SCHEMA, 'width' => 560, 'height' => 220,
            'layer' => [
                ['data' => ['values' => array_map(fn($t) => ['t' => round($t, 3)], $tRep)], 'mark' => ['type' => 'bar', 'color' => '#9ecae1'],
                    'encoding' => ['x' => ['field' => 't', 'type' => 'quantitative', 'bin' => ['maxbins' => 40], 'title' => 'T(y_rep), '.$input['statistic']], 'y' => ['aggregate' => 'count', 'type' => 'quantitative', 'title' => 'draws']]],
                ['data' => ['values' => [['t' => $observed]]], 'mark' => ['type' => 'rule', 'color' => '#08306b', 'strokeWidth' => 2.5], 'encoding' => ['x' => ['field' => 't', 'type' => 'quantitative']]],
            ]];

        $names = ['alpha' => '(Intercept)'];
        foreach ($stan->columns as $j => $column) { $names['beta['.($j + 1).']'] = $column; }
        $names += ['sigma' => 'sigma (residual sd)', 'tau' => 'tau (school sd)'];
        $summary = $fit->summary(array_keys($names), [0.05, 0.95], ['mean', 'sd', 'quantile2', 'rhat', 'ess_bulk']);
        $byName = array_column($summary, null, 'variable');
        $coefficients = array_map(fn($v) => [$names[$v], $v, $byName[$v]['mean'], $byName[$v]['sd'], $byName[$v]['q5'], $byName[$v]['q95'], $byName[$v]['rhat'], $byName[$v]['ess_bulk']], array_keys($names));
        $loo = $fit->loo();

        return [
            ['type' => 'text', 'content' => 'Eighty students in eight schools: does tutoring raise test scores once study hours are accounted for? `StanData::fromFormula()` turns the R-style formula into the regression data block, with a treatment-coded design matrix `X` and the school index `school_id`, through `Phit\\model_matrix()`. The Stan program adds a varying intercept per school.'],
            ['type' => 'table', 'title' => 'Data (first rows)', 'columns' => $header, 'rows' => array_map('array_values', array_slice($rows, 0, 6))],
            ['type' => 'code',
                'chain' => <<<'PHP'
$data = CmdStan\StanData::fromFormula('score ~ hours + tutoring + (1 | school)', $columns, drop_intercept: true);
// $data->columns: hours, tutoringgroup, tutoringprivate; $data->data has N, K, X, y, school_id, J

$fit = CmdStan\cmdstan_model('regression.stan')
    ->sample(data: $data, seed: 20261019, chains: 4, parallel_chains: 4, iter_sampling: 500);

$draws = $fit->draws_columns(['alpha', 'beta', 'sigma', 'a']);   // replicate y from these
$loo = $fit->loo();
PHP,
                'pipe' => <<<'PHP'
$fit = $columns
    |> (fn($columns) => CmdStan\StanData::fromFormula('score ~ hours + tutoring + (1 | school)', $columns, drop_intercept: true))
    |> (fn($data) => CmdStan\cmdstan_model('regression.stan')->sample(data: $data, seed: 20261019, chains: 4, parallel_chains: 4, iter_sampling: 500));
PHP],
            ['type' => 'table', 'title' => 'Coefficients', 'columns' => ['term', 'variable', 'mean', 'sd', 'q5', 'q95', 'rhat', 'ess_bulk'], 'rows' => $coefficients, 'digits' => 2],
            ['type' => 'chart', 'title' => sprintf('Observed scores (dark) against %d replicated datasets', count($overlay)), 'plot' => $densityChart],
            ['type' => 'metrics', 'items' => [
                ['label' => 'T(y), '.$input['statistic'], 'value' => $observed, 'format' => '%.2f'],
                ['label' => 'P(T(y_rep) ≥ T(y))', 'value' => $pValue, 'format' => '%.3f'],
                ['label' => 'elpd_loo', 'value' => $loo['estimates']['elpd_loo']['Estimate'], 'format' => '%.1f'],
                ['label' => 'Largest Pareto k', 'value' => max($loo['diagnostics']['pareto_k']), 'format' => '%.2f'],
            ]],
            ['type' => 'chart', 'title' => 'Test statistic across '.number_format($s).' replicated datasets', 'plot' => $statChart],
            ['type' => 'note', 'content' => 'A posterior predictive p-value near 0 or 1 means the model rarely produces data like the observed data for that statistic. The posterior draws are 4 chains of 500, sampled once with seed 20261019 and cached; the replicated datasets are drawn in PHP from those draws with a seeded generator, so the page is the same on every visit.'],
        ];
    },
];
