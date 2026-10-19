<?php
/**
 * Verifies the site's cached CmdStanPHP draws (site/data/draws, from site/scripts/generate.php) and the statistics
 * computed from them against R: CmdStanR draws at the same seed and settings, posterior::summarise_draws(), and
 * loo::loo()/waic(). Writes site/verifications/draws.json, summary.json, and loo.json.
 *
 *   php -d extension=/path/to/phit.so site/verifications/scripts/verify.php
 *
 * Needs CmdStan, Rscript with cmdstanr, posterior, loo, and jsonlite, and phit (the regression data comes from
 * StanData::fromFormula()). Exits with status 1 when a case fails.
 */
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use CmdStan\{CmdStan, CmdStanModel, Process, StanData};
use function CmdStan\{read_cmdstan_csv, write_stan_json};

const SEED = 20261019;
const TOLERANCE = 1e-8;
$site = dirname(__DIR__, 2); $tmp = sys_get_temp_dir().'/cmdstanphp-verify-'.getmypid(); @mkdir($tmp);

// The regression data, rebuilt as the generator and the vignette build it.
$handle = fopen($site.'/data/tutoring.csv', 'r'); $header = fgetcsv($handle, escape: ''); $rows = [];
while (($row = fgetcsv($handle, escape: '')) !== false) { $rows[] = array_combine($header, $row); }
fclose($handle); $levels = ['none', 'group', 'private'];
$regression = StanData::fromFormula('score ~ hours + tutoring + (1 | school)', [
    'score' => array_map('floatval', array_column($rows, 'score')), 'hours' => array_map('floatval', array_column($rows, 'hours')),
    'tutoring' => ['levels' => $levels, 'codes' => array_map(fn($t) => array_search($t, $levels, true), array_column($rows, 'tutoring')), 'ordered' => false],
    'school' => array_column($rows, 'school'),
], drop_intercept: true);
$models = [
    'eight schools' => ['eight_schools', json_decode(file_get_contents($site.'/data/eight_schools.json'), true), 1000],
    'regression' => ['regression', $regression, 500],
];

/** One verification case: the largest absolute and relative errors, and whether every value is within tolerance. */
$compare = function (string $name, string $quantity, array $reference, array $actual, float $tolerance = TOLERANCE): array {
    if (count($reference) !== count($actual)) { throw new RuntimeException($name.' '.$quantity.': '.count($reference).' reference values but '.count($actual).' computed.'); }
    $abs = 0.0; $rel = 0.0; $passed = true;
    foreach (array_values($reference) as $i => $ref) {
        $value = array_values($actual)[$i];
        if (!is_numeric($ref)) { $ok = is_float($value) && is_nan($value) && $ref === 'NA'; $passed = $passed && $ok; continue; }
        $error = abs((float)$value - (float)$ref); $abs = max($abs, $error);
        if ((float)$ref != 0.0) { $rel = max($rel, $error / abs((float)$ref)); }
        $passed = $passed && $error <= $tolerance * max(1.0, abs((float)$ref));
    }
    return ['name' => $name, 'quantity' => $quantity, 'n_compared' => count($reference), 'max_abs_error' => $abs, 'max_rel_error' => $rel, 'passed' => $passed];
};

$cases = ['draws' => [], 'summary' => [], 'loo' => []]; $versions = [];
foreach ($models as $label => [$name, $data, $iterations]) {
    $model = new CmdStanModel($site.'/models/'.$name.'.stan'); $model->compile();
    $dataFile = write_stan_json($data, $tmp.'/'.$name.'.json'); $out = $tmp.'/'.$name.'-r.json';
    Process::run(['Rscript', __DIR__.'/verify.R', $model->stan_file(), $model->exe_file(), $dataFile, (string)SEED, (string)$iterations, $out], env: ['CMDSTAN' => CmdStan::path()], timeout: 600);
    $r = json_decode(file_get_contents($out), true, flags: JSON_THROW_ON_ERROR); $versions = $r['versions'];
    $fit = read_cmdstan_csv(glob($site.'/data/draws/'.$name.'/*.csv'));

    // Draws: every value of every variable, chain by chain.
    $columns = $fit->draws_columns();
    $missing = array_diff($r['variables'], array_keys($columns));
    if ($missing !== []) { throw new RuntimeException($label.': CmdStanPHP draws lack '.implode(', ', $missing)); }
    $cases['draws'][] = $compare($label, sprintf('draws of %d variables, 4 chains x %d iterations, same seed', count($r['variables']), $iterations), array_merge(...$r['draws']), array_merge(...array_map(fn($v) => $columns[$v], $r['variables'])), 0.0);

    // Summaries: posterior's default summary and convergence measures for every variable, on each side's own draws.
    $summary = array_column($fit->summary($r['variables'], measures: ['default_summary_measures', 'default_convergence_measures']), null, 'variable');
    foreach (['mean', 'median', 'sd', 'mad', 'q5', 'q95', 'rhat', 'ess_bulk', 'ess_tail'] as $measure) {
        $cases['summary'][] = $compare($label, $measure, array_column($r['summary'], $measure), array_map(fn($row) => $summary[$row['variable']][$measure], $r['summary']));
    }

    // PSIS-LOO (r_eff from the chains) and WAIC on log_lik.
    $loo = $fit->loo(); $waic = $fit->waic();
    $cases['loo'][] = $compare($label, 'loo estimates (elpd_loo, p_loo, looic and their SEs)', array_merge(...$r['loo']['estimates']), array_merge(...array_map(fn($row) => [$row['Estimate'], $row['SE']], array_values($loo['estimates']))));
    $cases['loo'][] = $compare($label, 'pointwise elpd_loo', $r['loo']['pointwise'], array_column($loo['pointwise'], 'elpd_loo'));
    $cases['loo'][] = $compare($label, 'Pareto k', $r['loo']['pareto_k'], $loo['diagnostics']['pareto_k']);
    $cases['loo'][] = $compare($label, 'PSIS effective sample sizes', $r['loo']['n_eff'], $loo['diagnostics']['n_eff']);
    $cases['loo'][] = $compare($label, 'waic estimates (elpd_waic, p_waic, waic and their SEs)', array_merge(...$r['waic']['estimates']), array_merge(...array_map(fn($row) => [$row['Estimate'], $row['SE']], array_values($waic['estimates']))));
    $cases['loo'][] = $compare($label, 'pointwise elpd_waic', $r['waic']['pointwise'], array_column($waic['pointwise'], 'elpd_waic'));
}

$documents = [
    'draws.json' => ['title' => 'Same-seed draws match CmdStanR', 'description' => 'The cached draws behind the vignettes, sampled by CmdStanPHP, against CmdStanR sampling the same compiled model with the same data, seed '.SEED.', and settings (4 chains, 1,000 warmup iterations). Every value of every variable, including lp__, transformed parameters, and generated quantities, must be identical.', 'reference' => 'CmdStanR '.$versions['cmdstanr'].' with CmdStan '.CmdStan::version(), 'tolerance' => 'exact (0)', 'cases' => $cases['draws']],
    'summary.json' => ['title' => 'Posterior summaries match posterior', 'description' => 'CmdStanPHP\'s summary(measures: [\'default_summary_measures\', \'default_convergence_measures\']), computed in PHP from its cached draws, against posterior::summarise_draws() on the CmdStanR draws, for every variable of each model. The measures are mean, median, sd, mad, the 5% and 95% quantiles, rank-normalized split R-hat, and bulk and tail ESS.', 'reference' => 'posterior '.$versions['posterior'], 'tolerance' => sprintf('|difference| <= %.0e x max(1, |reference|)', TOLERANCE), 'cases' => $cases['summary']],
    'loo.json' => ['title' => 'PSIS-LOO and WAIC match loo', 'description' => 'CmdStanPHP\'s loo() (r_eff from the chains) and waic() on the log_lik generated quantity against loo::loo(log_lik, r_eff = relative_eff(exp(log_lik))) and loo::waic() on the CmdStanR draws.', 'reference' => 'loo '.$versions['loo'], 'tolerance' => sprintf('|difference| <= %.0e x max(1, |reference|)', TOLERANCE), 'cases' => $cases['loo']],
];
$failed = 0;
foreach ($documents as $file => $document) {
    file_put_contents($site.'/verifications/'.$file, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
    foreach ($document['cases'] as $case) {
        printf("%-5s %-14s %-60s n=%-6d abs=%.2e rel=%.2e\n", $case['passed'] ? 'ok' : 'FAIL', $case['name'], $case['quantity'], $case['n_compared'], $case['max_abs_error'], $case['max_rel_error']);
        $failed += $case['passed'] ? 0 : 1;
    }
}
exec('rm -rf '.escapeshellarg($tmp));
exit($failed > 0 ? 1 : 0);
