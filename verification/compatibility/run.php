<?php
// Runs the compatibility suite with CMDSTANPHP_EVIDENCE set, then writes one site/verifications record per topic.
//
//   php verification/compatibility/run.php                 run the suite, then export
//   php verification/compatibility/run.php --export-only   export from an earlier run in verification/compatibility/output
//   php verification/compatibility/run.php -- --fail-on-skipped   pass the arguments after -- to PHPUnit
//
// The exit status is PHPUnit's. PHIT_LIBRARY names the phit extension to load (moment matching needs it).
// CMDSTANR_COMMIT records which CmdStanR commit is installed, since its version number alone does not identify a
// release-candidate build. CI_PASS_COMMIT, when a test is skipped locally, adds the commit at which CI passed it.
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$out = __DIR__.'/output';
$exportOnly = in_array('--export-only', $argv, true);
$separator = array_search('--', $argv, true);
$phpunitArgs = $separator === false ? [] : array_slice($argv, $separator + 1);
$exit = 0;

if (!$exportOnly) {
    if (!is_dir($out)) { mkdir($out, 0777, true); }
    foreach (['results.jsonl', 'junit.xml', 'events.txt', 'environment.json'] as $file) { @unlink($out.'/'.$file); }
    file_put_contents($out.'/environment.json', json_encode(environment($root), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    $php = [PHP_BINARY];
    if (getenv('PHIT_LIBRARY')) { $php[] = '-d'; $php[] = 'extension='.getenv('PHIT_LIBRARY'); }
    $command = array_merge($php, [$root.'/vendor/bin/phpunit', '--group', 'compatibility', '--log-junit', $out.'/junit.xml', '--log-events-text', $out.'/events.txt'], $phpunitArgs);
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, getenv() + ['CMDSTANPHP_EVIDENCE' => $out]);
    $exit = proc_close($process);
    fwrite(STDERR, "phpunit exited with $exit; exporting the results, failures included\n");
}

$environment = json_decode(file_get_contents($out.'/environment.json'), true, flags: JSON_THROW_ON_ERROR);
$lines = array_map(fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($out.'/results.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$tests = junit($out.'/junit.xml', $out.'/events.txt');
$topics = topics($environment['versions']);

$results = [];
foreach ($topics as $id => $topic) {
    $record = record($id, $topic, $lines, $tests, $environment);
    file_put_contents($root.'/site/verifications/'.$id.'.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
    $results[$id] = array_count_values(array_column($record['cases'], 'status'));
    fwrite(STDOUT, sprintf("%-20s %s\n", $id, json_encode($results[$id])));
}
file_put_contents(__DIR__.'/results.json', json_encode(['environment' => $environment, 'tests' => $tests, 'comparisons' => $lines], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
exit($exit);

/** Versions and machine of this run, read before the suite starts. */
function environment(string $root): array
{
    $packages = ['cmdstanr', 'posterior', 'loo', 'bayesplot', 'jsonlite', 'Rcpp', 'RcppEigen', 'data.table'];
    $script = 'v <- sapply(c('.implode(', ', array_map(fn($p) => '"'.$p.'"', $packages)).'), function(p) tryCatch(as.character(packageVersion(p)), error = function(e) NA)); cat(jsonlite::toJSON(c(list(R = paste(R.version$major, R.version$minor, sep = ".")), as.list(v)), auto_unbox = TRUE))';
    $r = json_decode(shell_exec('Rscript -e '.escapeshellarg($script)) ?: '{}', true) ?: [];
    if (($r['cmdstanr'] ?? null) !== null && getenv('CMDSTANR_COMMIT')) { $r['cmdstanr'] .= ' (commit '.substr(getenv('CMDSTANR_COMMIT'), 0, 7).')'; }
    $phit = null;
    if (getenv('PHIT_LIBRARY')) { $phit = trim((string)shell_exec(escapeshellarg(PHP_BINARY).' -d extension='.escapeshellarg(getenv('PHIT_LIBRARY')).' -r '.escapeshellarg('echo phpversion("phit") ?: "";'))) ?: null; }
    $os = PHP_OS_FAMILY === 'Darwin'
        ? 'macOS '.trim((string)shell_exec('sw_vers -productVersion')).' ('.php_uname('m').')'
        : (preg_match('/^PRETTY_NAME="?([^"\n]+)/m', (string)@file_get_contents('/etc/os-release'), $m) ? 'Linux, '.$m[1].' ('.php_uname('m').')' : php_uname('s').' ('.php_uname('m').')');
    $commit = trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse --short=7 HEAD'));
    $dirty = trim((string)shell_exec('git -C '.escapeshellarg($root).' status --porcelain -- src tests')) !== '';
    return [
        'date' => gmdate('Y-m-d'),
        'commit' => $commit.($dirty ? ' (with uncommitted changes)' : ''),
        'php' => PHP_VERSION,
        'os' => $os,
        'versions' => array_filter(['PHP' => PHP_VERSION, 'CmdStan' => CmdStan\cmdstan_version(), 'phit' => $phit] + $r, fn($v) => $v !== null),
    ];
}

/**
 * Outcome of each test method from the JUnit log: pass, fail, or skipped, with the message. Data sets are merged.
 * JUnit logs carry no skip messages, so those come from PHPUnit's event log.
 */
function junit(string $file, string $events): array
{
    if (!is_file($file)) { return []; }
    $skips = [];
    if (is_file($events) && preg_match_all('/^Test Skipped \((?:.*\\\\)?([^\\\\]+?)(?: with data set [^)]*)?\)\n(.+)$/m', file_get_contents($events), $m, PREG_SET_ORDER)) {
        foreach ($m as [, $test, $message]) { $skips[$test] ??= trim($message); }
    }
    $tests = [];
    foreach ((new SimpleXMLElement(file_get_contents($file)))->xpath('//testcase') as $case) {
        $class = substr(strrchr('\\'.(string)$case['class'], '\\'), 1);
        $name = $class.'::'.preg_replace('/ with data set .*$/', '', (string)$case['name']);
        $status = 'pass'; $reason = null;
        foreach (['failure' => 'fail', 'error' => 'fail', 'skipped' => 'skipped'] as $tag => $as) {
            if (isset($case->{$tag})) { $status = $as; $reason = trim(strtok((string)($case->{$tag}['message'] ?? $case->{$tag}) ?: $tag, "\n")); }
        }
        if ($status === 'skipped' && isset($skips[$name])) { $reason = $skips[$name]; }
        $previous = $tests[$name]['status'] ?? 'pass';
        if ($previous === 'pass' || ($previous === 'skipped' && $status === 'fail')) { $tests[$name] = ['status' => $status, 'reason' => $reason]; }
    }
    return $tests;
}

/** Aggregates the comparison lines of one topic into cases, and adds a case for every skipped or failed test. */
function record(string $id, array $topic, array $lines, array $tests, array $environment): array
{
    $cases = []; $rules = [];
    foreach ($lines as $line) {
        if ($line['topic'] !== $id) { continue; }
        $rule = $line['rule']; $ruleId = null;
        if ($rule['kind'] === 'tolerance') {
            $ruleId = isset($rule['rtol']) ? 'rel-'.fmt($rule['rtol']) : 'abs-'.fmt($rule['atol']);
            $rules[$ruleId] ??= ['id' => $ruleId, 'kind' => 'tolerance'] + (isset($rule['rtol']) ? ['rtol' => $rule['rtol'], 'rel_floor' => 1.0] : ['atol' => $rule['atol'], 'rel_floor' => 1.0]);
        } elseif ($rule['kind'] === 'exact') {
            $ruleId = 'exact';
            $rules['exact'] ??= ['id' => 'exact', 'kind' => 'exact', 'applies_to' => []];
            $rules['exact']['applies_to'][$line['quantity']] = true;
        }
        $key = $line['case']."\0".$line['quantity'];
        $case = $cases[$key] ?? ['name' => $line['case'], 'quantity' => $line['quantity'], 'status' => 'pass', 'rule' => $ruleId, 'n_compared' => 0, 'max_abs_error' => null, 'max_rel_error' => null, 'tests' => []];
        $case['n_compared'] += $line['n_compared'];
        foreach (['max_abs_error', 'max_rel_error'] as $field) { if (isset($line[$field])) { $case[$field] = max($case[$field] ?? 0.0, $line[$field]); } }
        if ($line['status'] !== 'pass' && $case['status'] !== 'fail') { $case['status'] = $line['status']; $case['reason'] = $line['reason'] ?? null; }
        $case['tests'][$line['test']] = true;
        $cases[$key] = $case;
    }
    // A test that failed or was skipped stops at that point, so later comparisons never ran; say so in a case.
    foreach ($topic['tests'] as $test => $what) {
        $outcome = $tests[$test] ?? ['status' => 'skipped', 'reason' => 'This test did not run.'];
        if ($outcome['status'] === 'pass') { continue; }
        $recorded = array_filter($cases, fn($c) => isset($c['tests'][$test]) && $c['status'] === $outcome['status']);
        if ($outcome['status'] === 'fail' && $recorded !== []) {
            $cases["\0".$test] = ['name' => $what, 'quantity' => 'comparisons after the first failure', 'status' => 'skipped', 'reason' => 'The test stopped at its first failure, so its later comparisons did not run.'];
            continue;
        }
        $reason = $outcome['reason'] ?? ($outcome['status'] === 'fail' ? 'The test failed.' : 'The test was skipped.');
        if ($outcome['status'] === 'skipped' && getenv('CI_PASS_COMMIT')) { $reason = rtrim($reason, '.').'. It ran and passed in CI (Linux) at commit '.substr(getenv('CI_PASS_COMMIT'), 0, 7).', which saves no per-quantity results.'; }
        $cases["\0".$test] = ['name' => $what, 'quantity' => 'every comparison in '.$test, 'status' => $outcome['status'], 'reason' => $reason];
    }
    $cases = array_values(array_map(function (array $case) {
        unset($case['tests']);
        if (($case['rule'] ?? null) === null) { unset($case['rule']); }
        if (isset($case['n_compared']) && ($case['rule'] ?? null) === 'exact') { unset($case['max_abs_error'], $case['max_rel_error']); }
        return array_filter($case, fn($v) => $v !== null);
    }, $cases));
    $rules = array_values(array_map(function (array $rule) {
        if (isset($rule['applies_to'])) { $rule['applies_to'] = implode('; ', array_keys($rule['applies_to'])); }
        return $rule;
    }, $rules));
    usort($rules, fn($a, $b) => [$a['kind'] === 'exact', $a['atol'] ?? $a['rtol'] ?? 0] <=> [$b['kind'] === 'exact', $b['atol'] ?? $b['rtol'] ?? 0]);
    return array_filter([
        'title' => $topic['title'],
        'label' => $topic['label'],
        'kind' => 'reference',
        'group' => 'Comparisons with R',
        'checked' => $topic['checked'],
        'reference' => $topic['reference'],
        'versions' => array_intersect_key($environment['versions'], array_flip(array_merge(['PHP', 'CmdStan', 'R'], $topic['versions']))),
        'inputs' => $topic['inputs'],
        'comparison' => $rules,
        'cases' => $cases,
        'exclusions' => $topic['exclusions'] ?? null,
        'known_differences' => $topic['known_differences'] ?? null,
        'reproduce' => $topic['reproduce'] ?? 'php verification/compatibility/run.php',
        'source' => 'verification/compatibility/results.json',
        'environment' => array_intersect_key($environment, array_flip(['date', 'commit', 'php', 'os'])),
    ], fn($v) => $v !== null);
}

function fmt(float $x): string { return str_replace('.0e', 'e', sprintf('%.0e', $x)); }

/** What each topic checks, its reference, and the tests that make its comparisons. */
function topics(array $v): array
{
    $at = fn(string $package) => $package.' '.($v[$package] ?? '(not installed)');
    return [
        'cmdstanr-methods' => [
            'title' => 'Fitting methods: agreement with CmdStanR',
            'label' => 'Fitting methods',
            'checked' => 'Draws, approximate log densities, summaries, and sampler diagnostics from sample(), optimize(), variational(), pathfinder(), and laplace(), against CmdStanR running the same executable with the same data, seed, and options.',
            'reference' => $at('cmdstanr').' with '.$at('posterior').', running CmdStanPHP\'s compiled executable through cmdstan_model(exe_file = ...)',
            'versions' => ['cmdstanr', 'posterior'],
            'inputs' => 'examples/bernoulli.stan with N = 10 and y = [0,1,0,0,0,0,0,0,0,1], seed 123, output at 18 significant figures. MCMC runs 2 chains of 200 warmup and 100 or 101 sampling iterations; the approximations take 100 draws.',
            'exclusions' => ['Draws and summary values that CmdStanR reports as missing are not compared.'],
            'tests' => ['CompatibilityTest::testSameSeedDrawsAndDescriptiveSummariesAsCmdStanR' => 'Fitting methods'],
        ],
        'cmdstanr-metadata' => [
            'title' => 'CSV metadata and examples: agreement with CmdStanR',
            'label' => 'Metadata and examples',
            'checked' => 'The metadata read from CmdStan CSV files for each method, and the bundled example programs and data, against CmdStanR.',
            'reference' => $at('cmdstanr').' read_cmdstan_csv(), print_example_program(), and its bundled example data',
            'versions' => ['cmdstanr'],
            'inputs' => 'CSV files from examples/bernoulli.stan for each method (MCMC: 2 chains, 50 warmup and 20 sampling iterations, warmup saved), and the logistic, schools, and schools_ncp examples.',
            'exclusions' => ['The metadata entries time, init, fitted_params, profile_file, file, data_file, diagnostic_file, and start_datetime are not compared: they hold timings, paths, or the run\'s start time.'],
            'tests' => ['CompatibilityTest::testMetadataStructureMatchesCmdStanR' => 'Metadata', 'CompatibilityTest::testExampleProgramsAndDataMatchCmdStanR' => 'Example programs and data'],
        ],
        'posterior-summaries' => [
            'title' => 'Summaries: agreement with posterior',
            'label' => 'Summaries',
            'checked' => 'summary() by default (stansummary columns plus ess_tail) and with measures (the PHP port of summarise_draws()), every named posterior measure, custom quantile probabilities, and diagnosticSummary().',
            'reference' => $at('posterior').' summarise_draws() on the same draws, and '.$at('cmdstanr').' diagnostic_summary() on the same CSV files',
            'versions' => ['posterior', 'cmdstanr'],
            'inputs' => [
                ['name' => 'shoe_latent model', 'detail' => 'tests/fixtures/latent.stan, 60 observations in 12 groups, 4 chains of 1,000 warmup and 1,000 sampling iterations, seed 20260831. R reads CmdStanPHP\'s draws as binary doubles.'],
                ['name' => 'Fixed draws', 'detail' => 'Six variables (autoregressive, heavy-tailed, tied, constant, skewed, and shifted between chains), 4 chains × 101 draws, generated with seed 20240607.'],
            ],
            'tests' => ['CompatibilityTest::testIndexedParameterSummariesMatchPosteriorOnTheSameDraws' => 'shoe_latent model', 'StatisticsCompatibilityTest::testSummaryMeasuresMatchPosterior' => 'Fixed draws'],
        ],
        'loo-psis' => [
            'title' => 'PSIS, LOO, and WAIC: agreement with posterior and loo',
            'label' => 'PSIS, LOO, and WAIC',
            'checked' => 'Pareto smoothing and its diagnostics, Pareto k, the generalized Pareto fit, relative efficiency, PSIS weights, loo() with and without r_eff, and waic().',
            'reference' => $at('posterior').' pareto_smooth(), pareto_khat(), and gpdfit(), and '.$at('loo').' relative_eff(), psis(), loo(), and waic()',
            'versions' => ['posterior', 'loo'],
            'inputs' => 'Generated with seed 20240607: 1,000 and 100 lognormal values, 1,000 normal log weights, 50 generalized Pareto values, and log-likelihoods of 12 observations (one an outlier) over 4 chains × 250 draws.',
            'tests' => ['StatisticsCompatibilityTest::testParetoSmoothingMatchesPosterior' => 'Pareto smoothing', 'StatisticsCompatibilityTest::testPsisRelativeEfficiencyLooAndWaicMatchLoo' => 'PSIS, LOO, and WAIC'],
        ],
        'moment-matching' => [
            'title' => 'Moment matching: agreement with loo and CmdStanR',
            'label' => 'Moment matching',
            'checked' => 'loo(momentMatch: true), with and without the split step, against loo\'s moment matching on analytic posteriors and against CmdStanR\'s loo(moment_match = TRUE) on a same-seed fit.',
            'reference' => $at('loo').' loo_moment_match.default(), and '.$at('cmdstanr').' loo(moment_match = TRUE)',
            'versions' => ['phit', 'loo', 'cmdstanr'],
            'inputs' => [
                ['name' => 'Analytic regressions', 'detail' => 'Conjugate normal regressions whose outlier needs shifts only, shifts and scaling, or a covariance map, with exact log densities (tests/MomentMatchFixture.php).'],
                ['name' => 'Regression fit', 'detail' => 'A Stan regression with an outlier, 4 chains of 500 warmup and 1,000 sampling iterations, seed 20261012.'],
            ],
            'known_differences' => [
                'The split step uses the exact inverse of the accumulated affine map. loo 2.10.1 and earlier use an inverse that is wrong once a shift is combined with scaling or a covariance map, so with the split only shift-only observations are compared with those versions.',
                'The split relative efficiency is the minimum over both halves of the draws; loo uses the second half twice.',
                'Relative efficiencies are computed from exp(log_lik − max). Where loo reports NA because every likelihood value is tiny, CmdStanPHP must report a finite value.',
            ],
            'reproduce' => 'PHIT_LIBRARY=/path/to/phit.so php verification/compatibility/run.php',
            'tests' => ['MomentMatchCompatibilityTest::testUnsplitMomentMatchingMatchesLoo' => 'Analytic regressions, unsplit', 'MomentMatchCompatibilityTest::testSplitMatchesLooWithTheExactInverse' => 'Analytic regressions, split', 'MomentMatchCompatibilityTest::testDifferencesFromLooSplitAreTheDocumentedOnes' => 'Analytic regressions, loo\'s own split', 'MomentMatchIntegrationTest::testSameSeedMomentMatchingMatchesCmdStanR' => 'Regression fit'],
        ],
        'model-methods' => [
            'title' => 'Model methods: agreement with CmdStanR',
            'label' => 'Model methods',
            'checked' => 'log_prob(), grad_log_prob(), hessian(), constrain_variables(), unconstrain_variables(), unconstrain_draws(), and the variable skeleton, called in process through FFI.',
            'reference' => $at('cmdstanr').' model methods, compiled with '.$at('Rcpp').' and '.$at('RcppEigen'),
            'versions' => ['cmdstanr', 'Rcpp', 'RcppEigen'],
            'inputs' => 'tests/fixtures/native.stan (constrained scalars, a matrix, a simplex, and a covariance matrix) with N = 4, at one point of 19 unconstrained values, and at draws from CmdStanR.',
            'tests' => ['NativeCompatibilityTest::testModelMethodsAndFunctionsMatchCmdStanR' => 'Model methods and exposed functions'],
        ],
        'stan-functions' => [
            'title' => 'Exposed Stan functions: agreement with CmdStanR',
            'label' => 'Exposed functions',
            'checked' => 'User-defined Stan functions called from PHP through exposeFunctions(): scalar, vector, matrix, array, integer, complex, RNG, and density functions.',
            'reference' => $at('cmdstanr').' expose_functions(), compiled with '.$at('Rcpp').' and '.$at('RcppEigen'),
            'versions' => ['cmdstanr', 'Rcpp', 'RcppEigen'],
            'inputs' => 'The functions block of tests/fixtures/native.stan, with the arguments shown in each case.',
            'tests' => ['NativeCompatibilityTest::testModelMethodsAndFunctionsMatchCmdStanR' => 'Model methods and exposed functions'],
        ],
        'figures' => [
            'title' => 'Figure values: agreement with R and bayesplot',
            'label' => 'Figure values',
            'checked' => 'The values the figures draw: kernel density bandwidth and estimates, autocorrelations, ranks with ties, and interval quantiles.',
            'reference' => 'R '.($v['R'] ?? '').' bw.nrd0(), dnorm(), acf(), and rank(), and '.$at('bayesplot').' mcmc_intervals_data() with '.$at('posterior'),
            'versions' => ['bayesplot', 'posterior'],
            'inputs' => '400 draws of an AR(1) series and 400 integers from 0 to 9, generated with seed 7; density evaluated at 41 points from −2 to 2.',
            'tests' => ['PlotCompatibilityTest::testPlottedValuesMatchR' => 'Figure values'],
        ],
        'rvars-inits' => [
            'title' => 'draws_rvars and initial values: agreement with posterior and CmdStanR',
            'label' => 'draws_rvars and inits',
            'checked' => 'The names, dimensions, chain counts, and values of draws_rvars, and initial values built from draws and matched to parameter dimensions.',
            'reference' => $at('posterior').' as_draws_rvars(), and the initial values '.$at('cmdstanr').' writes from draws',
            'versions' => ['posterior', 'cmdstanr'],
            'inputs' => 'Draws of a scalar, a 2 × 3 matrix with missing cells, and a vector over 2 chains × 3 iterations; initial values from 2 draws for 2 chains and from 1 draw recycled for 3 chains.',
            'tests' => ['StatisticsCompatibilityTest::testRvarsShapesMatchPosterior' => 'draws_rvars', 'StatisticsCompatibilityTest::testInitDimensionMatchingMatchesCmdStanR' => 'Initial values'],
        ],
    ];
}
