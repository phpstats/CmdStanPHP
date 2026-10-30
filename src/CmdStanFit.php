<?php
declare(strict_types=1);
namespace CmdStan;
class CmdStanFit
{
    private ?array $csv = null;
    private array $diagnosticWarnings = [];
    private ?array $nativeStatistics = null;
    public function __construct(protected array $files, protected string $method = 'sample', protected array $results = [], protected array $diagnosticFiles = [], protected array $profileFiles = [], protected array $context = [])
    {
        if ($files === []) { throw new \InvalidArgumentException('At least one CSV is required.'); }
        foreach ($files as &$file) {
            if (!is_string($file) || !is_file($file)) { throw new \InvalidArgumentException('Output file does not exist.'); }
            $file = realpath($file);
        }
        unset($file); $this->files = $files;
    }
    public static function classForMethod(string $method): string
    {
        return match ($method) { 'sample' => CmdStanMCMC::class, 'optimize' => CmdStanMLE::class, 'variational' => CmdStanVB::class, 'pathfinder' => CmdStanPathfinder::class, 'laplace' => CmdStanLaplace::class, 'generate_quantities' => CmdStanGQ::class, 'diagnose' => CmdStanDiagnose::class, default => self::class };
    }
    public static function fromCsv(array $files, ?array $variables = null, bool $checkDiagnostics = true, ?string $format = null): self
    {
        if ($files === []) { throw new \InvalidArgumentException('At least one CSV is required.'); }
        $parsed = array_map(fn($file) => Csv::read($file, $variables, $checkDiagnostics), $files); $method = $parsed[0]['metadata']['method'] ?? 'sample';
        foreach ($parsed as $csv) { if (($csv['metadata']['method'] ?? 'sample') !== $method || $csv['variables'] !== $parsed[0]['variables']) { throw new \InvalidArgumentException('CSV files must share method and columns.'); } }
        $class = self::classForMethod($method); $fit = new $class($files, $method, context: $format === null ? [] : ['draws_format' => $format]); $fit->csv = $parsed;
        return $fit;
    }
    protected function parsed(): array
    {
        if ($this->csv === null) {
            $this->csv = array_map(Csv::read(...), $this->files);
            foreach ($this->csv as $csv) { if ($csv['variables'] !== $this->csv[0]['variables']) { throw new \RuntimeException('Chain CSV columns do not match.'); } }
        }
        return $this->csv;
    }
    public function code(): string { return $this->context['code'] ?? throw new \RuntimeException('Stan source is unavailable for imported CSVs.'); }
    /** Imported fits fall back to the data file recorded in the CSV header when it still exists. */
    public function dataFile(): ?string { return array_key_exists('data_file', $this->context) ? $this->context['data_file'] : $this->context['data_file'] = $this->recordedDataFile(); }
    private function recordedDataFile(): ?string
    {
        $comments = $this->parsed()[0]['comments']; $section = array_search('data', $comments, true);
        if ($section === false || !preg_match('/^file = (.+)$/', $comments[$section + 1] ?? '', $m)) { return null; }
        return is_file($m[1]) ? realpath($m[1]) : null;
    }
    public function numProcs(): int { return count($this->files); }
    /** Fits only contain successful runs (a failing chain throws), so includeFailed never adds files. */
    public function configFiles(bool $includeFailed = false): array { return $this->context['config_files'] ??= $this->relatedFiles('_config.json'); }
    public function metricFiles(bool $includeFailed = false): array { return $this->context['metric_files'] ??= $this->relatedFiles('_metric.json'); }
    private function relatedFiles(string $suffix): array
    {
        return array_values(array_filter(array_map(fn($file) => preg_replace('/\.csv(\.gz|\.bz2)?$/i', '', $file).$suffix, $this->files), 'is_file'));
    }
    /** Decoded save_cmdstan_config JSON (CmdStan 2.34+), one array per process. */
    public function cmdstanConfig(): array { return array_map(fn($file) => json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $this->configFiles()); }
    /** Decoded save_metric JSON (stepsize, metric_type, inv_metric), one array per chain. */
    public function metric(): array { return array_map(fn($file) => json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $this->metricFiles()); }
    public function lp(): array|float
    {
        return array_map(fn($iteration) => array_map(fn($chain) => $chain['lp__'], $iteration), $this->draws('lp__', 'draws_array'));
    }
    /** One column's post-warmup values for every chain, in draw order (chain by chain). */
    protected function column(string $column): array
    {
        $out = [];
        foreach ($this->parsed() as $csv) { foreach ($csv['draws'] as $row) { $out[] = (float)($row[$column] ?? NAN); } }
        return $out;
    }
    public function cmdstanSummary(): string
    {
        return $this->withPlainFiles(fn(array $files) => Process::run(array_merge([CmdStan::path().'/bin/stansummary'.CmdStan::suffix()], $files))['stdout']);
    }
    /** Runs $use with uncompressed copies of the output files (CmdStan's tools cannot read .csv.gz or .csv.bz2). */
    protected function withPlainFiles(callable $use): mixed
    {
        $copies = array_map(fn($f) => Csv::isCompressed($f) ? Csv::decompress($f) : $f, $this->files);
        try { return $use($copies); } finally { foreach ($copies as $i => $copy) { if ($copy !== $this->files[$i]) { @unlink($copy); } } }
    }
    public function outputFiles(bool $includeFailed = false): array { return $this->files; }
    public function latentDynamicsFiles(bool $includeFailed = false): array
    {
        $files = array_values(array_filter($this->diagnosticFiles, 'is_file'));
        return $files !== [] ? $files : throw new \RuntimeException("No latent dynamics files found. Set 'save_latent_dynamics: true' when fitting the model.");
    }
    public function profileFiles(bool $includeFailed = false): array
    {
        $files = array_values(array_filter($this->profileFiles, 'is_file'));
        return $files !== [] ? $files : throw new \RuntimeException('No profile files found. The model that produced the fit did not use any profiling.');
    }
    public function returnCodes(): array { return array_column($this->results, 'exit_code'); }
    /**
     * CmdStanR 1.0's command(): the command line run for each CmdStan process, shell-quoted. STAN_NUM_THREADS (set in
     * the environment) and an MPI launcher are not included.
     */
    public function command(): array
    {
        $commands = $this->context['commands'] ?? throw new \RuntimeException('This method is not available for objects created using as_cmdstan_fit().');
        return array_map(fn(array $argv) => implode(' ', array_map(escapeshellarg(...), $argv)), $commands);
    }
    /** CmdStanR's init(): the initial values each process used, read from the init files in the metadata. */
    public function init(): array
    {
        if (isset($this->context['init_values'])) { return $this->context['init_values']; }
        $files = $this->context['init_files'] ?? array_merge(...array_map(fn($v) => is_string($v) ? explode(',', $v) : [], (array)($this->metadata()['init'] ?? [])) ?: [[]]);
        $files = array_values(array_filter($files, fn($f) => !is_numeric($f)));
        if ($files === [] || array_filter($files, fn($f) => !is_file($f)) !== []) { throw new \RuntimeException("Can't find initial values files."); }
        return $this->context['init_values'] = array_map(fn($f) => json_decode((string)file_get_contents($f), true, flags: JSON_THROW_ON_ERROR), $files);
    }
    /** CmdStanR 1.0's materialize(): reads the draws, initial values and profiles into memory now, so the fit no longer needs its files. */
    public function materialize(): static
    {
        $this->parsed();
        foreach ([fn() => $this->init(), fn() => $this->profiles()] as $read) { try { $this->context['materialized'][] = $read(); } catch (\Throwable) {} }
        unset($this->context['materialized']);
        return $this;
    }
    public function output(): array { return array_map(fn($r) => $r['stdout'].$r['stderr'], $this->results); }
    /** Per-chain CSV metadata (CmdStanR read_csv_metadata() keys plus CmdStan's raw argument keys). */
    public function chainMetadata(): array { return array_column($this->parsed(), 'metadata'); }
    /**
     * CmdStanR-style merged metadata: the first chain's metadata with id/seed/init/step_size/step_size_adaptation/fitted_params
     * collected across chains (seed collapses to a scalar when shared), time as per-chain rows, and missing keys filled from config JSON.
     */
    public function metadata(): array
    {
        $chains = $this->chainMetadata(); $meta = $chains[0];
        foreach (['seed', 'init', 'step_size', 'step_size_adaptation', 'fitted_params'] as $key) {
            $values = array_values(array_filter(array_column($chains, $key), fn($v) => $v !== null));
            if ($values !== []) { $meta[$key] = $values; }
        }
        $meta['id'] = $this->procIds();
        if (($meta['method'] ?? 'sample') === 'sample') { $meta['num_chains'] = count($chains); }
        if (isset($meta['seed']) && count(array_unique($meta['seed'], SORT_REGULAR)) === 1) { $meta['seed'] = $meta['seed'][0]; }
        $meta['time'] = array_values(array_filter(array_column($chains, 'time')));
        $config = $this->configFiles() === [] ? [] : self::flattenConfig($this->cmdstanConfig()[0]);
        return $meta + $config;
    }
    private static function flattenConfig(array $config, array $out = []): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value) && is_string($value['value'] ?? null)) { $out[$key] ??= $value['value']; $out = self::flattenConfig(array_diff_key($value, ['value' => true]), $out); }
            elseif (is_array($value) && $value !== [] && !array_is_list($value)) { $out = self::flattenConfig($value, $out); }
            else { $out[$key] ??= $value; }
        }
        return $out;
    }
    private function procIds(): array { return $this->context['ids'] ?? array_map(fn($csv, $i) => (int)($csv['metadata']['id'] ?? $i + 1), $this->parsed(), array_keys($this->parsed())); }
    private function modelName(): string { return $this->context['model_name'] ?? preg_replace('/_model$/', '', (string)($this->parsed()[0]['metadata']['model_name'] ?? 'model')); }
    public function numChains(): int { return count($this->files); }
    public function numDrawsSampling(): int { return count($this->parsed()[0]['draws']); }
    public function numDrawsWarmup(): int { return count($this->parsed()[0]['warmup']); }
    public function variables(): array { return array_values(array_filter($this->parsed()[0]['variables'], fn($v) => !str_ends_with($v, '__') || $v === 'lp__')); }
    private function select(array|string|null $variables, bool $diagnostics = false): array
    {
        $available = $diagnostics ? array_values(array_filter($this->parsed()[0]['variables'], fn($v) => str_ends_with($v, '__') && $v !== 'lp__')) : $this->variables();
        if ($variables === null) { return $available; }
        $selected = [];
        foreach ((array)$variables as $v) {
            $match = array_values(array_filter($available, fn($name) => Csv::matches($name, $v)));
            if ($match === []) { throw new \InvalidArgumentException('Unknown variable: '.$v); }
            array_push($selected, ...$match);
        }
        return array_values(array_unique($selected));
    }
    /**
     * draws_array is [iteration][chain][variable]; draws_matrix is chain-major rows. A null format uses the cmdstanr_draws_format
     * option, else CmdStanR's per-method default: draws_array for MCMC and generated quantities, draws_matrix otherwise.
     */
    public function draws(array|string|null $variables = null, ?string $format = null, bool $incWarmup = false): array
    {
        return $this->extract($this->select($variables), $format ?? $this->defaultFormat(), $incWarmup);
    }
    public function samplerDiagnostics(?string $format = null, bool $incWarmup = false): array
    {
        return $this->extract($this->select(null, true), $format ?? $this->defaultFormat(), $incWarmup);
    }
    private function defaultFormat(): string { return $this->context['draws_format'] ?? CmdStan::option('cmdstanr_draws_format') ?? ($this instanceof CmdStanMCMC || $this instanceof CmdStanGQ ? 'draws_array' : 'draws_matrix'); }
    /** $part selects post-warmup 'draws', 'warmup', 'both', or the optimizer 'estimate'. */
    private function extract(array $variables, string $format, bool $incWarmup, ?string $part = null): array
    {
        if (!in_array($format, ['draws_array', 'draws_matrix', 'draws_df', 'draws_list', 'draws_rvars'], true)) { throw new \InvalidArgumentException('Unsupported draws format: '.$format); }
        $chains = []; $part ??= $incWarmup ? 'both' : 'draws';
        foreach ($this->parsed() as $csv) {
            $rows = match ($part) { 'both' => array_merge($csv['warmup'], $csv['draws']), 'warmup' => $csv['warmup'], 'estimate' => $csv['estimate'] === null ? [] : [$csv['estimate']], default => $csv['draws'] };
            $chains[] = array_map(fn($row) => array_intersect_key($row, array_flip($variables)), $rows);
        }
        return self::shape($chains, $variables, $format);
    }
    /** Arranges per-chain associative rows in a draws format; draws_rvars nests variables by dimension (see Rvars). */
    private static function shape(array $chains, array $variables, string $format): array
    {
        if ($format === 'draws_rvars') { return Rvars::fromDrawsDf(self::shape($chains, $variables, 'draws_df')); }
        if ($format === 'draws_list') {
            return array_map(function ($rows) use ($variables) { $out = []; foreach ($variables as $v) { $out[$v] = array_column($rows, $v); } return $out; }, $chains);
        }
        if ($format === 'draws_matrix') { return array_merge(...$chains); }
        if ($format === 'draws_df') {
            $out = []; $draw = 0;
            foreach ($chains as $chain => $rows) { foreach ($rows as $iteration => $row) { $out[] = $row + ['.chain' => $chain + 1, '.iteration' => $iteration + 1, '.draw' => ++$draw]; } }
            return $out;
        }
        $sizes = array_map('count', $chains);
        if (count(array_unique($sizes)) > 1) { throw new \RuntimeException('draws_array requires equal chain lengths; use draws_list or draws_df.'); }
        $out = [];
        for ($i = 0; $i < ($sizes[0] ?? 0); ++$i) { $out[] = array_column($chains, $i); }
        return $out;
    }
    /**
     * Draws as columns, like posterior::as_draws_df(): one column per variable element (e.g. 'beta[2]'), then one-based
     * '.chain', '.iteration' and '.draw' columns. The result is ready for Phibble\DataFrame::fromColumns().
     *
     * @return array<string, list<float|int>>
     */
    public function drawsColumns(array|string|null $variables = null, bool $incWarmup = false): array
    {
        $chains = $this->draws($variables, 'draws_list', $incWarmup); $columns = array_fill_keys(array_keys($chains[0] ?? []), []); $meta = ['.chain' => [], '.iteration' => [], '.draw' => []]; $draw = 0;
        foreach ($chains as $c => $chain) {
            foreach ($columns as $name => $_) { array_push($columns[$name], ...$chain[$name]); }
            $n = count(reset($chain) ?: []);
            for ($i = 0; $i < $n; ++$i) { $meta['.chain'][] = $c + 1; $meta['.iteration'][] = $i + 1; $meta['.draw'][] = ++$draw; }
        }
        return $columns + $meta;
    }
    /** drawsTable(): drawsColumns() as a Phibble\DataFrame. Needs phpstats/phibble, which is optional; throws when it is not installed. */
    public function drawsTable(array|string|null $variables = null, bool $incWarmup = false): object { return Tables::dataFrame($this->drawsColumns($variables, $incWarmup), 'drawsTable()'); }
    /** summary() turned into columns: 'variable' and one column per measure, one value per variable. */
    public function summaryColumns(array|string|null $variables = null, array $probs = [0.05, 0.95], array $measures = []): array { return Tables::fromRows($this->summary($variables, $probs, $measures)); }
    /** summaryTable(): summaryColumns() as a Phibble\DataFrame. Needs phpstats/phibble; throws when it is not installed. */
    public function summaryTable(array|string|null $variables = null, array $probs = [0.05, 0.95], array $measures = []): object { return Tables::dataFrame($this->summaryColumns($variables, $probs, $measures), 'summaryTable()'); }
    public static function quantile(array $values, float $p): float
    {
        if ($values === []) { return NAN; }
        sort($values, SORT_NUMERIC); $index = (count($values) - 1) * $p; $lower = (int)floor($index); $fraction = $index - $lower;
        if ($fraction === 0.0) { return $values[$lower]; }
        return $values[$lower] * (1 - $fraction) + $values[min($lower + 1, count($values) - 1)] * $fraction;
    }
    /**
     * Descriptive summaries; MCMC convergence statistics are computed by CmdStan stansummary. Non-empty $measures uses
     * posterior's summarise_draws() semantics instead (measure names or keyed callables; see Summary::summarise()).
     */
    public function summary(array|string|null $variables = null, array $probs = [0.05, 0.95], array $measures = []): array
    {
        if ($measures !== []) { return Summary::summarise($this->draws($variables, 'draws_list'), $measures, $probs); }
        foreach ($probs as $p) { if (!is_numeric($p) || !is_finite((float)$p) || $p < 0 || $p > 1) { throw new \InvalidArgumentException('Quantile probabilities must be in [0,1].'); } }
        $vars = $this->select($variables); $rows = $this->draws($vars, 'draws_matrix'); $out = [];
        $native = $this instanceof CmdStanMCMC && $this->numDrawsSampling() >= 4 ? $this->nativeSummary() : [];
        foreach ($vars as $v) {
            $values = array_column($rows, $v); $n = count($values); $mean = $n ? array_sum($values) / $n : NAN;
            $sd = $n > 1 ? sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $values)) / ($n - 1)) : NAN;
            $median = self::quantile($values, 0.5);
            $entry = ['variable' => $v, 'mean' => $mean, 'median' => $median, 'sd' => $sd, 'mad' => 1.4826 * self::quantile(array_map(fn($x) => abs($x - $median), $values), 0.5)];
            foreach ($probs as $p) { $entry['q'.($p * 100)] = self::quantile($values, (float)$p); }
            $entry += ['rhat' => $native[$v]['R_hat'] ?? $native[$v]['Rhat'] ?? NAN, 'ess_bulk' => $native[$v]['ESS_bulk'] ?? NAN, 'ess_tail' => $this instanceof CmdStanMCMC ? $this->tailEss($v) : NAN, 'mcse_mean' => $native[$v]['MCSE'] ?? NAN];
            $out[] = $entry;
        }
        return $out;
    }
    private function tailEss(string $variable): float
    {
        return Diagnostics::essTail(array_map(fn($csv) => array_column($csv['draws'], $variable), $this->parsed()));
    }
    private function nativeSummary(): array
    {
        if ($this->nativeStatistics !== null) { return $this->nativeStatistics; }
        $file = sys_get_temp_dir().'/cmdstanphp-summary-'.bin2hex(random_bytes(8)).'.csv';
        try {
            $this->withPlainFiles(fn(array $files) => Process::run(array_merge([CmdStan::path().'/bin/stansummary'.CmdStan::suffix(), '--csv_filename='.$file, '--sig_figs=18'], $files)));
            $handle = fopen($file, 'rb'); $header = null; $out = [];
            try {
                while (($line = fgets($handle)) !== false) {
                    if (trim($line) === '' || str_starts_with(ltrim($line), '#')) { continue; }
                    $fields = str_getcsv(trim($line), ',', '"', '');
                    if ($header === null) { $header = $fields; continue; }
                    $name = Csv::variableName(array_shift($fields));
                    $keys = array_slice($header, 1);
                    $out[$name] = array_combine($keys, array_map(fn($v) => is_numeric($v) ? (float)$v : NAN, $fields));
                }
            } finally { fclose($handle); }
            return $this->nativeStatistics = $out;
        } finally { if (is_file($file)) { unlink($file); } }
    }
    public function diagnosticSummary(): array
    {
        $result = ['num_divergent' => [], 'num_max_treedepth' => [], 'ebfmi' => []];
        foreach ($this->parsed() as $csv) {
            $rows = $csv['draws']; $depth = $csv['metadata']['max_treedepth'] ?? 10;
            $result['num_divergent'][] = array_sum(array_column($rows, 'divergent__'));
            $result['num_max_treedepth'][] = count(array_filter(array_column($rows, 'treedepth__'), fn($x) => $x >= $depth));
            $energy = array_column($rows, 'energy__'); $n = count($energy); $bfmi = NAN;
            if ($n >= 3) {
                $mean = array_sum($energy) / $n; $variance = array_sum(array_map(fn($x) => ($x - $mean) ** 2, $energy)) / ($n - 1); $diffs = 0.0;
                for ($i = 1; $i < $n; ++$i) { $diffs += ($energy[$i] - $energy[$i - 1]) ** 2; }
                if ($variance > 0) { $bfmi = $diffs / $n / $variance; }
            }
            $result['ebfmi'][] = $bfmi;
        }
        return $result;
    }
    /** Checks are retained as structured messages, rather than PHP runtime warnings. */
    public function checkDiagnostics(array $checks = ['divergences', 'treedepth', 'ebfmi']): array
    {
        $summary = $this->diagnosticSummary(); $warnings = [];
        foreach ($summary['num_divergent'] as $i => $divergent) {
            if (in_array('divergences', $checks, true) && $divergent > 0) { $warnings[] = 'Chain '.($i + 1).': '.(int)$divergent.' divergent transitions.'; }
            if (in_array('treedepth', $checks, true) && $summary['num_max_treedepth'][$i] > 0) { $warnings[] = 'Chain '.($i + 1).': '.$summary['num_max_treedepth'][$i].' transitions at maximum tree depth.'; }
            if (in_array('ebfmi', $checks, true) && is_finite($summary['ebfmi'][$i]) && $summary['ebfmi'][$i] < 0.3) { $warnings[] = 'Chain '.($i + 1).': E-BFMI below 0.3.'; }
        }
        return $this->diagnosticWarnings = $warnings;
    }
    public function diagnosticWarnings(): array { return $this->diagnosticWarnings; }
    public function profiles(): array
    {
        if (isset($this->context['profiles'])) { return $this->context['profiles']; }
        $profiles = [];
        foreach ($this->profileFiles() as $file) {
            $handle = fopen($file, 'rb'); $rows = [];
            try {
                $header = fgetcsv($handle, escape: '');
                while (($row = fgetcsv($handle, escape: '')) !== false) {
                    if (count($row) !== count($header)) { throw new \RuntimeException('Invalid profile CSV row.'); }
                    $rows[] = array_combine($header, array_map(fn($value) => is_numeric($value) ? (float)$value : $value, $row));
                }
            } finally { fclose($handle); }
            $profiles[] = $rows;
        }
        return $this->context['profiles'] = $profiles;
    }
    public function cmdstanDiagnose(): string
    {
        $r = $this->withPlainFiles(fn(array $files) => Process::run(array_merge([CmdStan::path().'/bin/diagnose'.CmdStan::suffix()], $files)));
        return $r['stdout'].$r['stderr'];
    }
    /**
     * Per-chain inverse metric, from save_metric JSON when every chain has one, else from the CSV adaptation comments.
     * With matrix (CmdStanR's default) diagonal metrics are expanded to matrices; otherwise diagonal metrics are vectors.
     */
    public function invMetric(bool $matrix = true): array
    {
        $json = $this->metricFiles() !== [] && count($this->metricFiles()) === count($this->files) ? $this->metric() : null; $out = [];
        foreach ($this->parsed() as $i => $csv) {
            $value = $json === null ? $csv['inv_metric'] : $json[$i]['inv_metric'] ?? [];
            $value = array_map(fn($x) => is_array($x) ? array_map('floatval', $x) : (float)$x, (array)$value);
            if (($json[$i]['metric_type'] ?? $csv['metadata']['metric'] ?? null) === 'dense_e' && $value !== [] && !is_array($value[0])) { $value = [$value]; }
            if ($matrix && $value !== [] && !is_array($value[0])) { $value = array_map(fn($k) => array_map(fn($j) => $j === $k ? $value[$k] : 0.0, array_keys($value)), array_keys($value)); }
            $out[] = $value;
        }
        return $out;
    }
    public function stepSize(): array { return array_column($this->parsed(), 'step_size'); }
    public function time(): array
    {
        $chains = [];
        foreach ($this->parsed() as $i => $csv) {
            $timing = ['chain' => $csv['metadata']['id'] ?? $i + 1, 'warmup' => 0.0, 'sampling' => 0.0, 'total' => $this->results[$i]['duration'] ?? null];
            foreach ($csv['comments'] as $comment) { if (preg_match('/([0-9.eE+-]+) seconds \((Warm-up|Sampling|Total)\)/', $comment, $m)) { $key = match($m[2]) { 'Warm-up' => 'warmup', 'Sampling' => 'sampling', default => 'total' }; $timing[$key] = (float)$m[1]; } }
            $chains[] = $timing;
        }
        return $chains;
    }
    /** CmdStanR's copy_temp_files(): basename[-timestamp][-id][-random]ext. Run outputs are moved; user-supplied files are copied. Existing destinations are refused. */
    private function saveFiles(array $files, string $dir, string $basename, ?array $ids, string $ext, bool $timestamp, bool $random, bool $move): array
    {
        if (in_array($basename, ['.', '..'], true) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $basename)) { throw new \InvalidArgumentException('basename must contain only letters, digits, dots, underscores and hyphens.'); }
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('Cannot create destination directory.'); }
        $files = array_values($files); $ids = $ids !== null && count($ids) === count($files) ? $ids : ($ids === null ? null : range(1, count($files)));
        $name = realpath($dir).'/'.$basename.($timestamp ? '-'.date('YmdHi') : ''); $suffix = ($random ? '-'.bin2hex(random_bytes(3)) : '').$ext;
        $width = $ids === null ? 0 : max(2, strlen((string)max($ids)));
        $targets = array_map(fn($i) => $name.($ids === null ? '' : '-'.str_pad((string)$ids[$i], $width, '0', STR_PAD_LEFT)).$suffix, array_keys($files));
        if (count(array_unique($targets)) !== count($targets)) { throw new \RuntimeException('Destination file names are not unique.'); }
        foreach ($targets as $i => $target) { if ($target !== $files[$i] && file_exists($target)) { throw new \RuntimeException('Destination exists: '.$target); } }
        foreach ($targets as $i => $target) { if ($target !== $files[$i] && !copy($files[$i], $target)) { throw new \RuntimeException('Cannot save output.'); } }
        if ($move) { foreach ($files as $i => $file) { if ($file !== $targets[$i] && is_file($file)) { unlink($file); } } }
        return $targets;
    }
    private function saveArgs(?string $basename, string $suffix = ''): string { return ($basename ?? $this->modelName()).$suffix; }
    /**
     * CSV files saved under new names; compress (null keeps the current compression, else 'none', 'gzip' or 'bzip2')
     * recompresses them on the way.
     */
    private function saveCsvFiles(array $files, string $dir, string $basename, bool $timestamp, bool $random, ?string $compress): array
    {
        $current = preg_match('/\.csv\.gz$/i', $files[0] ?? '') ? 'gzip' : (preg_match('/\.csv\.bz2$/i', $files[0] ?? '') ? 'bzip2' : 'none');
        $move = $this->context['owned'] ?? false;
        if ($compress === null || $compress === $current) { return $this->saveFiles($files, $dir, $basename, $this->procIds(), '.csv'.['none' => '', 'gzip' => '.gz', 'bzip2' => '.bz2'][$current], $timestamp, $random, $move); }
        Csv::assertCompress($compress);
        $plain = array_map(fn($f) => $current === 'none' ? $f : Csv::decompress($f), $files);
        try { $saved = $this->saveFiles($plain, $dir, $basename, $this->procIds(), '.csv', $timestamp, $random, false); }
        finally { if ($current !== 'none') { foreach ($plain as $f) { @unlink($f); } } }
        $saved = Csv::compress($saved, $compress);
        if ($move) { foreach ($files as $f) { if (!in_array($f, $saved, true) && is_file($f)) { unlink($f); } } }
        return $saved;
    }
    public function saveOutputFiles(string $dir = '.', ?string $basename = null, bool $timestamp = true, bool $random = true, ?string $compress = null): array
    {
        $this->configFiles(); $this->metricFiles(); $this->pinFiles();
        $this->csv = null; $this->parsed();
        return $this->files = $this->saveCsvFiles($this->files, $dir, $this->saveArgs($basename), $timestamp, $random, $compress);
    }
    /** Hook for subclasses to resolve paths derived from output file names before those files move. */
    protected function pinFiles(): void {}
    public function saveLatentDynamicsFiles(string $dir = '.', ?string $basename = null, bool $timestamp = true, bool $random = true, ?string $compress = null): array
    {
        return $this->diagnosticFiles = $this->saveCsvFiles($this->latentDynamicsFiles(true), $dir, $this->saveArgs($basename, '-diagnostic'), $timestamp, $random, $compress);
    }
    public function saveProfileFiles(string $dir = '.', ?string $basename = null, bool $timestamp = true, bool $random = true): array
    {
        return $this->profileFiles = $this->saveFiles($this->profileFiles(true), $dir, $this->saveArgs($basename, '-profile'), $this->procIds(), '.csv', $timestamp, $random, $this->context['owned'] ?? false);
    }
    /** Copies the data file (the original stays in place, as in CmdStanR 1.0) and points the fit at the copy. */
    public function saveDataFile(string $dir = '.', ?string $basename = null, bool $timestamp = true, bool $random = true): ?string
    {
        $file = $this->dataFile();
        if ($file === null) { return null; }
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        return $this->context['data_file'] = $this->saveFiles([$file], $dir, $this->saveArgs($basename), null, $ext === '' ? '' : '.'.$ext, $timestamp, $random, false)[0];
    }
    public function saveConfigFiles(string $dir = '.', ?string $basename = null, bool $timestamp = true, bool $random = true): array
    {
        return $this->context['config_files'] = $this->saveFiles($this->configFiles(true), $dir, $this->saveArgs($basename, '-config'), $this->procIds(), '.json', $timestamp, $random, $this->context['owned'] ?? false);
    }
    public function saveMetricFiles(string $dir = '.', ?string $basename = null, bool $timestamp = true, bool $random = true): array
    {
        return $this->context['metric_files'] = $this->saveFiles($this->metricFiles(true), $dir, $this->saveArgs($basename, '-metric'), $this->procIds(), '.json', $timestamp, $random, $this->context['owned'] ?? false);
    }
    /**
     * CmdStanR 1.0's init_model_methods(data, seed, verbose, quiet): compiles (or reuses) the in-process model-methods
     * library via PHP FFI and binds it to data, by default the data the fit was run with. Pass data (an array, StanData,
     * table or JSON file) for a fit reloaded after its data file is gone; the fit's dataFile() then points to it.
     * Imported CSV fits must pass the model. The Hessian is always available.
     */
    public function initModelMethods(array|object|string|null $data = null, int $seed = 1, bool $verbose = false, bool $quiet = false, ?CmdStanModel $model = null): void
    {
        if ($model !== null) {
            $this->context['stan_file'] = $model->stanFile() ?? throw new \RuntimeException('Model methods cannot be used with a model created from an executable alone. There is no Stan program to compile them from.');
            $this->context['stanc_options'] = $model->nativeStancOptions(); $this->context['variables'] = $model->variables(); unset($this->context['model_methods']);
        }
        if ($data === null && isset($this->context['model_methods'])) { return; }
        $stan = $this->context['stan_file'] ?? throw new \RuntimeException('Model methods need the Stan model; pass model: to initModelMethods() for imported CSVs.');
        if ($data !== null && !is_string($data)) {
            $file = sys_get_temp_dir().'/standata-'.bin2hex(random_bytes(8)).'.json';
            $data = StanJson::write($data, $file, isset($this->context['variables']), $this->context['variables']['data'] ?? null);
        }
        $file = $data ?? $this->dataFile();
        if ($file !== null && !is_file($file)) {
            throw new \RuntimeException("Can't find data file '".$file."', so the model methods can't be initialized.".($data === null ? ' Pass the data the fit was run with to initModelMethods() as data, or call saveDataFile() before saving a fit for a later session.' : ''));
        }
        $this->context['model_methods'] = new ModelMethods(ModelMethods::build($stan, $this->context['stanc_options'] ?? []), $file, $seed);
        if ($data !== null) { $this->context['data_file'] = realpath($file) ?: $file; }
    }
    private function methods(): ModelMethods { $this->initModelMethods(); return $this->context['model_methods']; }
    public function logProb(array $unconstrainedVariables, bool $jacobian = true): float { return $this->methods()->logProb($unconstrainedVariables, $jacobian); }
    /** @return array{log_prob: float, gradient: list<float>} */
    public function gradLogProb(array $unconstrainedVariables, bool $jacobian = true): array { return $this->methods()->gradLogProb($unconstrainedVariables, $jacobian); }
    /** @return array{log_prob: float, grad_log_prob: list<float>, hessian: list<list<float>>} */
    public function hessian(array $unconstrainedVariables, bool $jacobian = true): array { return $this->methods()->hessian($unconstrainedVariables, $jacobian); }
    public function unconstrainVariables(array $variables): array { return $this->methods()->unconstrainVariables($variables); }
    /** Constrained values by variable, structured as Csv::unflatten() describes (tuples as lists, complex as [re, im]). */
    public function constrainVariables(array $unconstrainedVariables, bool $transformedParameters = true, bool $generatedQuantities = true): array { return $this->methods()->constrainVariables($unconstrainedVariables, $transformedParameters, $generatedQuantities); }
    /** Unconstrains this fit's draws, the draws in other CmdStan CSV files, or draws_df/draws_matrix rows, returned in a draws format. */
    public function unconstrainDraws(?array $files = null, ?array $draws = null, ?string $format = null, bool $incWarmup = false): array
    {
        if ($files !== null && $draws !== null) { throw new \InvalidArgumentException('Either a list of CSV files or a draws object can be passed, not both'); }
        $rows = $draws ?? ($files !== null ? self::fromCsv($files) : $this)->draws(null, 'draws_df', $incWarmup);
        $names = $this->methods()->unconstrainedParamNames(); $chains = [];
        foreach (array_map(null, $rows, $this->methods()->unconstrainDraws($rows)) as [$row, $values]) { $chains[(int)($row['.chain'] ?? 1) - 1][] = array_combine($names, $values); }
        ksort($chains);
        return self::shape(array_values($chains), $names, $format ?? $this->defaultFormat());
    }
    /** CmdStanR's expose_functions() for the model that produced the fit. */
    public function exposeFunctions(?float $timeout = null): StanFunctions
    {
        return $this->context['functions'] ??= StanFunctions::compile($this->context['stan_file'] ?? throw new \RuntimeException('Exposing functions needs the Stan model; use CmdStanModel::exposeFunctions() for imported CSVs.'), $this->context['stanc_options'] ?? [], timeout: $timeout);
    }
    public function functions(): StanFunctions { return $this->context['functions'] ?? throw new \RuntimeException('Functions have not been exposed; call exposeFunctions() first.'); }
    public function saveObject(string $file): string
    {
        $this->materialize();
        if ($this instanceof CmdStanMCMC && $this->numDrawsSampling() >= 4) { $this->nativeSummary(); }
        if (file_put_contents($file, serialize($this), LOCK_EX) === false) { throw new \RuntimeException('Cannot save fit object.'); }
        return $file;
    }
    /** Emits summary JSON for at most maxRows variables (default: cmdstanr_max_rows option, else 10). */
    public function print(array|string|null $variables = null, ?int $maxRows = null): void
    {
        $max = $maxRows ?? CmdStan::option('cmdstanr_max_rows', 10);
        if ($max < 1) { throw new \InvalidArgumentException('maxRows must be positive.'); }
        $selected = $this->select($variables);
        echo json_encode($this->summary(array_slice($selected, 0, $max)), JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
        if ($max < count($selected)) { echo "\n # showing ".$max.' of '.count($selected)." rows (change via 'maxRows' argument or 'cmdstanr_max_rows' option)\n"; }
    }
    /**
     * CmdStanR read_cmdstan_csv()'s list: metadata plus, by method, time/inv_metric/step_size/warmup_draws/post_warmup_draws/
     * warmup_sampler_diagnostics/post_warmup_sampler_diagnostics (sample), draws (variational, laplace, pathfinder),
     * point_estimates (optimize), generated_quantities, or gradients/lp (diagnose). Empty variables/diagnostics select none.
     */
    public static function readCmdStanCsv(array $files, array|string|null $variables = null, array|string|null $samplerDiagnostics = null, ?string $format = null): array
    {
        $fit = self::fromCsv($files); $meta = $fit->metadata(); $method = $meta['method'] ?? 'sample';
        if ($method === 'diagnose') { $lp = $meta['lp'] ?? null; unset($meta['lp']); return ['metadata' => $meta, 'gradients' => $fit->parsed()[0]['gradients'], 'lp' => $lp]; }
        $format ??= CmdStan::option('cmdstanr_draws_format') ?? (in_array($method, ['sample', 'generate_quantities'], true) ? 'draws_array' : 'draws_matrix');
        $pick = function (array|string|null $wanted, array $available): array {
            if ($wanted === null) { return $available; }
            $out = [];
            foreach (array_filter((array)$wanted, fn($v) => $v !== '') as $v) {
                $match = array_values(array_filter($available, fn($name) => Csv::matches($name, $v)));
                if ($match === []) { throw new \InvalidArgumentException("Can't find the following variable(s) in the output: ".$v); }
                array_push($out, ...$match);
            }
            return array_values(array_unique($out));
        };
        $vars = $pick($variables, $meta['variables']); $diag = $pick($samplerDiagnostics, $meta['sampler_diagnostics']);
        $get = fn(array $columns, string $part) => $columns === [] || array_sum(array_map(fn($csv) => count($part === 'estimate' ? array_filter([$csv['estimate']]) : $csv[$part]), $fit->parsed())) === 0 ? null : $fit->extract($columns, $format, false, $part);
        if ($method === 'sample') {
            $ids = $meta['id']; $metric = $fit->invMetric(false);
            return ['metadata' => $meta, 'time' => ['total' => null, 'chains' => $meta['time']], 'inv_metric' => array_filter(array_combine($ids, $metric), fn($m) => $m !== []), 'step_size' => array_filter(array_combine($ids, $fit->stepSize()), fn($x) => $x !== null), 'warmup_draws' => $get($vars, 'warmup'), 'post_warmup_draws' => $get($vars, 'draws'), 'warmup_sampler_diagnostics' => $get($diag, 'warmup'), 'post_warmup_sampler_diagnostics' => $get($diag, 'draws')];
        }
        return match ($method) { 'optimize' => ['metadata' => $meta, 'point_estimates' => $get($vars, 'estimate')], 'generate_quantities' => ['metadata' => $meta, 'generated_quantities' => $get($vars, 'draws')], default => ['metadata' => $meta, 'draws' => $get($vars, 'draws')] };
    }
}
