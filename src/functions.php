<?php
declare(strict_types=1);
namespace CmdStan;
/** forceRecompile defaults to the cmdstanr_force_recompile option. */
function cmdstan_model(string $stanFile, bool $compile = true, ?string $exeFile = null, array $stancOptions = [], array $cppOptions = [], ?bool $forceRecompile = null): CmdStanModel
{
    $model = new CmdStanModel($stanFile, $exeFile);
    if ($compile) { $model->compile(forceRecompile: $forceRecompile, stancOptions: $stancOptions, cppOptions: $cppOptions); }
    return $model;
}
function set_cmdstan_path(string $path): void { CmdStan::setPath($path); }
function cmdstan_path(): string { return CmdStan::path(); }
function cmdstan_version(): string { return CmdStan::version(); }
/** Throws when make or the C++ compiler is missing; returns their paths and versions (and stanc's, when installed). */
function check_cmdstan_toolchain(): array { return CmdStan::checkToolchain(); }
/**
 * Session options, like R's options(). Supported: cmdstanr_output_dir, cmdstanr_write_stan_file_dir, cmdstanr_force_recompile,
 * cmdstanr_draws_format, cmdstanr_warn_inits, cmdstanr_verbose, cmdstanr_no_ver_check (accepted; this port never checks for
 * updates), cmdstanr_max_rows, mc.cores, cmdstanr_print_line_numbers, and the PHP-specific cmdstanphp_example_dir.
 * With $set, returns the previous values so they can be restored with options($previous); null unsets an option.
 */
function options(array $set = []): array { return CmdStan::setOptions($set); }
/** Reads one option, like R's getOption(name, default). */
function cmdstan_option(string $name, mixed $default = null): mixed { return CmdStan::option($name, $default); }
/** $data may also be a StanData or a table such as a phibble DataFrame; see StanData. */
function write_stan_json(array|object $data, string $file): string { return StanJson::write($data, $file); }
/**
 * Writes Stan code (a string or list of lines) to $file, or to dir/basename where dir defaults to the cmdstanr_write_stan_file_dir
 * option or the system temporary directory and basename to model_<hash>.stan. An identical existing file is left untouched
 * (keeping compiled executables current) unless forceOverwrite.
 */
function write_stan_file(string|array $code, ?string $file = null, ?string $dir = null, ?string $basename = null, bool $forceOverwrite = false, string $hashSalt = ''): string
{
    $code = is_array($code) ? implode("\n", $code) : $code;
    if ($file !== null && ($dir !== null || $basename !== null)) { throw new \InvalidArgumentException('Specify file or dir/basename, not both.'); }
    if ($file === null) {
        $dir ??= CmdStan::option('cmdstanr_write_stan_file_dir') ?? sys_get_temp_dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('Cannot create Stan file directory.'); }
        if ($basename !== null && (in_array($basename, ['.', '..'], true) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $basename))) { throw new \InvalidArgumentException('basename must be a simple filename.'); }
        $file = realpath($dir).'/'.($basename === null ? 'model_'.hash('xxh128', $hashSalt.$code).'.stan' : (str_ends_with($basename, '.stan') ? $basename : $basename.'.stan'));
        if (!$forceOverwrite && is_file($file) && str_replace("\r\n", "\n", (string)file_get_contents($file)) === str_replace("\r\n", "\n", $code)) { return $file; }
    }
    if (file_put_contents($file, $code, LOCK_EX) === false) { throw new \RuntimeException('Cannot write Stan file.'); }
    return realpath($file) ?: $file;
}
/** @deprecated Use write_stan_file(). */
function write_stan_tempfile(string|array $code, ?string $dir = null): string
{
    trigger_error('write_stan_tempfile() is deprecated. Please use write_stan_file() instead.', E_USER_DEPRECATED);
    return write_stan_file($code, dir: $dir ?? sys_get_temp_dir());
}
function read_cmdstan_csv(array|string $files): CmdStanFit { return CmdStanFit::fromCsv((array)$files); }
/** CmdStanR's read_cmdstan_csv() list shape; see CmdStanFit::readCmdStanCsv(). */
function read_cmdstan_csv_list(array|string $files, array|string|null $variables = null, array|string|null $samplerDiagnostics = null, ?string $format = null): array { return CmdStanFit::readCmdStanCsv((array)$files, $variables, $samplerDiagnostics, $format); }
/** @deprecated Use read_cmdstan_csv(). */
function read_sample_csv(array|string $files): CmdStanFit
{
    trigger_error('read_sample_csv() is deprecated. Please use read_cmdstan_csv().', E_USER_DEPRECATED);
    return read_cmdstan_csv($files);
}
function as_cmdstan_fit(array|string $files): CmdStanFit { return read_cmdstan_csv($files); }
/** @deprecated Use the fit's metadata(). Returns STAN_NUM_THREADS, or null when unset. */
function num_threads(): ?int
{
    trigger_error("'num_threads()' is deprecated. Please use the 'metadata()' method of the fit object to obtain the number of threads used.", E_USER_DEPRECATED);
    $threads = getenv('STAN_NUM_THREADS');
    return $threads !== false && is_numeric($threads) ? (int)$threads : null;
}
/** Version first for compatibility with earlier releases; see Installer::install(). cores defaults to the mc.cores option, else 1. */
function install_cmdstan(?string $version = null, ?string $dir = null, ?int $cores = null, ?float $timeout = null, bool $overwrite = false, ?string $releaseUrl = null, ?string $releaseFile = null, array $cppOptions = [], bool $checkToolchain = true): string
{
    return Installer::install($version, $dir, $cores, $timeout, $overwrite, $releaseUrl, $releaseFile, $cppOptions, $checkToolchain);
}
function rebuild_cmdstan(?int $cores = null, ?float $timeout = null): void { Installer::rebuild($cores, $timeout); }
/**
 * Writes cppOptions to <dir>/make/local (named entries as NAME=value with booleans as true/false, list entries as raw lines),
 * appending unless append is false, and returns the current make/local lines. dir defaults to the selected CmdStan.
 */
function cmdstan_make_local(?string $dir = null, array $cppOptions = [], bool $append = true): array { return Installer::makeLocal($dir, $cppOptions, $append); }
const EXAMPLES = ['logistic', 'schools', 'schools_ncp'];
/**
 * CmdStanR's cmdstanr_example(): compiles a bundled example (logistic, schools, schools_ncp) in a cache directory (the
 * cmdstanphp_example_dir option, else <tmp>/cmdstanphp-examples) and fits it with its bundled data. Options are passed to the
 * method; quiet (default true) discards console output and force_recompile defaults to the cmdstanr_force_recompile option.
 */
function cmdstanr_example(string $example = 'logistic', string $method = 'sample', mixed ...$options): CmdStanFit
{
    if (!in_array($example, EXAMPLES, true)) { throw new \InvalidArgumentException('example must be one of: '.implode(', ', EXAMPLES)); }
    if (!in_array($method, ['sample', 'optimize', 'laplace', 'variational', 'pathfinder', 'diagnose'], true)) { throw new \InvalidArgumentException('Unsupported example method: '.$method); }
    if (array_is_list($options) && count($options) === 1 && is_array($options[0])) { $options = $options[0]; }
    elseif ($options !== [] && array_is_list($options)) { throw new \InvalidArgumentException('Use named arguments or one options array.'); }
    $quiet = $options['quiet'] ?? true; $force = $options['force_recompile'] ?? CmdStan::option('cmdstanr_force_recompile', false); unset($options['quiet'], $options['force_recompile']);
    if (!is_bool($quiet) || !is_bool($force)) { throw new \InvalidArgumentException('quiet and force_recompile must be boolean.'); }
    if (array_key_exists('data', $options)) { throw new \InvalidArgumentException('Example data is fixed; use cmdstan_model() for other data.'); }
    $dir = CmdStan::option('cmdstanphp_example_dir') ?? sys_get_temp_dir().'/cmdstanphp-examples';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('Cannot create example directory.'); }
    $source = (string)file_get_contents(__DIR__.'/../resources/examples/'.$example.'.stan');
    $file = realpath($dir).'/'.$example.'.stan';
    if (!is_file($file) || file_get_contents($file) !== $source) {
        $temporary = $file.'.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $source, LOCK_EX) === false || !rename($temporary, $file)) { throw new \RuntimeException('Cannot write example program.'); }
    }
    $model = cmdstan_model($file, forceRecompile: $force);
    $options['data'] = realpath(__DIR__.'/../resources/examples/'.$example.'.data.json');
    if (!$quiet) { return $model->$method($options); }
    ob_start();
    try { return $model->$method($options); } finally { ob_end_clean(); }
}
function print_example_program(string $example = 'logistic'): void
{
    if (!in_array($example, EXAMPLES, true)) { throw new \InvalidArgumentException('example must be one of: '.implode(', ', EXAMPLES)); }
    echo rtrim((string)file_get_contents(__DIR__.'/../resources/examples/'.$example.'.stan'))."\n";
}
/** Write one chain-major row matrix per file, preserving CmdStan's column naming. */
function draws_to_csv(array $chains, string $dir): array
{
    if ($chains === []) { throw new \InvalidArgumentException('At least one chain is required.'); }
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('Cannot create output directory.'); }
    $files = []; $columns = null;
    foreach ($chains as $i => $rows) {
        if (!is_array($rows) || $rows === []) { throw new \InvalidArgumentException('Each chain must contain associative draw rows.'); }
        $columns ??= array_keys($rows[0]);
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $columns) { throw new \InvalidArgumentException('All draw rows must have the same columns in the same order.'); }
            foreach ($row as $value) { if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) { throw new \InvalidArgumentException('Draws must be finite numbers.'); } }
        }
        $file = realpath($dir).'/draws-'.bin2hex(random_bytes(8)).'-'.($i + 1).'.csv';
        $handle = fopen($file, 'xb');
        if ($handle === false) { throw new \RuntimeException('Cannot create draw CSV.'); }
        try {
            fwrite($handle, "# method = sample\n# id = ".($i + 1)."\n# num_samples = ".count($rows)."\n# num_warmup = 0\n# save_warmup = 0\n# thin = 1\n");
            $header = array_map(function ($name) {
                if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\[\d+(?:,\d+)*\])?$/D', $name)) { throw new \InvalidArgumentException('Invalid draw variable name.'); }
                return str_replace([ '[', ',', ']' ], ['.', '.', ''], $name);
            }, $columns);
            fputcsv($handle, $header, ',', '"', '');
            foreach ($rows as $row) { fputcsv($handle, array_map(fn($x) => sprintf('%.17g', $x), $row), ',', '"', ''); }
        } finally { fclose($handle); }
        $files[] = $file;
    }
    return $files;
}
/**
 * Figures named after their bayesplot counterparts. Each returns a Figure: echo it for ASCII text, or call vegaLite(),
 * html(), svg() or png() (see Figure and Plot).
 */
function mcmc_trace(CmdStanFit|array $draws, string $variable): Figure { return Plot::trace($draws, $variable); }
function mcmc_hist(CmdStanFit|array $draws, string $variable, int $bins = 30): Figure { return Plot::hist($draws, $variable, $bins); }
function mcmc_dens(CmdStanFit|array $draws, string $variable): Figure { return Plot::dens($draws, $variable); }
function mcmc_dens_overlay(CmdStanFit|array $draws, string $variable): Figure { return Plot::dens($draws, $variable, true); }
function mcmc_intervals(CmdStanFit|array $draws, array|string|null $variables = null, float $prob = 0.5, float $probOuter = 0.9, string $pointEst = 'median'): Figure { return Plot::intervals($draws, $variables, $prob, $probOuter, $pointEst); }
function mcmc_rank_hist(CmdStanFit|array $draws, string $variable, int $bins = 20): Figure { return Plot::rankHist($draws, $variable, $bins); }
function mcmc_scatter(CmdStanFit|array $draws, string $xVariable, string $yVariable): Figure { return Plot::scatter($draws, $xVariable, $yVariable); }
function mcmc_acf(CmdStanFit|array $draws, string $variable, int $lags = 20): Figure { return Plot::acf($draws, $variable, $lags); }
/** loo's Pareto k diagnostic plot for a CmdStanMCMC::loo() or Loo::loo() result. */
function pareto_k_plot(array $loo): Figure { return Plot::paretoK($loo); }
