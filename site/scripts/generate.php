<?php
/**
 * Regenerates the site's data and cached draws: site/data/tutoring.csv (simulated, seeded) and the CmdStan output CSVs
 * under site/data/draws/ that the vignettes read, so the site never runs CmdStan.
 *
 * Needs CmdStan (CMDSTAN or ~/.cmdstan) and the phit extension for StanData::fromFormula():
 *   php -d extension=/path/to/phit.so site/scripts/generate.php
 */
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use CmdStan\{CmdStanModel, StanData};

const SEED = 20261019;
$site = dirname(__DIR__);

// Simulated tutoring data: 80 students in 8 schools; score depends on study hours, tutoring, and the school.
$rng = new Random\Randomizer(new Random\Engine\Mt19937(SEED));
$u = fn() => ($rng->getInt(0, (1 << 53) - 1) + 0.5) / 9007199254740992;
$normal = fn() => sqrt(-2 * log($u())) * cos(2 * M_PI * $u());
$schools = range('A', 'H'); $effects = []; foreach ($schools as $s) { $effects[$s] = 4.0 * $normal(); }
$levels = ['none', 'group', 'private']; $lift = ['none' => 0.0, 'group' => 3.0, 'private' => 6.0];
$rows = [];
foreach ($schools as $s) {
    for ($i = 0; $i < 10; ++$i) {
        $hours = round(24 * $u()) / 2; $tutoring = $levels[$rng->getInt(0, 2)];
        $rows[] = [$s, $hours, $tutoring, round(62 + 1.6 * $hours + $lift[$tutoring] + $effects[$s] + 6 * $normal(), 1)];
    }
}
$csv = fopen($site.'/data/tutoring.csv', 'w'); fwrite($csv, "school,hours,tutoring,score\n");
foreach ($rows as $row) { fwrite($csv, implode(',', $row)."\n"); }
fclose($csv);

$columns = [
    'score' => array_column($rows, 3), 'hours' => array_map('floatval', array_column($rows, 1)),
    'tutoring' => ['levels' => $levels, 'codes' => array_map(fn($t) => array_search($t, $levels, true), array_column($rows, 2)), 'ordered' => false],
    'school' => array_column($rows, 0),
];
$regression = StanData::fromFormula('score ~ hours + tutoring + (1 | school)', $columns, drop_intercept: true);

$runs = [
    'eight_schools' => [json_decode(file_get_contents($site.'/data/eight_schools.json'), true), 1000],
    'regression' => [$regression, 500],
];
foreach ($runs as $name => [$data, $draws]) {
    $model = new CmdStanModel($site.'/models/'.$name.'.stan'); $model->compile();
    $tmp = sys_get_temp_dir().'/cmdstanphp-site-'.$name.'-'.getmypid(); @mkdir($tmp);
    $fit = $model->sample(data: $data, seed: SEED, chains: 4, parallel_chains: 4, iter_warmup: 1000, iter_sampling: $draws, refresh: 0, show_messages: false, output_dir: $tmp);
    $out = $site.'/data/draws/'.$name; @mkdir($out, 0777, true);
    foreach ($fit->output_files() as $c => $file) {
        // Drop machine-specific paths from the header comments; the draws are unchanged.
        $text = preg_replace('/^(#\s*(?:file|init|diagnostic_file|profile_file|output_file|fit_file|stanc_version)?\s*=\s*)\/\S+\/([^\/\s]+)$/m', '$1$2', file_get_contents($file));
        file_put_contents($out.'/'.$name.'-'.($c + 1).'.csv', $text);
    }
    printf("%s: %d chains x %d draws, %d divergent\n", $name, count($fit->output_files()), $draws, array_sum($fit->diagnostic_summary()['num_divergent']));
}
