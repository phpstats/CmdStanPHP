<?php
declare(strict_types=1);
namespace CmdStan;
/** Strict reader for CmdStan CSV, including adaptation comments and special float values. */
final class Csv
{
    /**
     * CmdStan's column name in CmdStanR's spelling: in each ':'-separated tuple piece the first '.' opens the index and
     * the others separate indices, so x.1.2 is x[1,2], z.real is z[real], and t:2.1 is t:2[1].
     */
    public static function variableName(string $name): string
    {
        return implode(':', array_map(function (string $piece): string {
            $at = strpos($piece, '.');
            return $at === false ? $piece : substr($piece, 0, $at).'['.str_replace('.', ',', substr($piece, $at + 1)).']';
        }, explode(':', $name)));
    }
    /** True when $name is $variable or one of its elements: an indexed element (x[1]) or a tuple component (t:1). */
    public static function matches(string $name, string $variable): bool { return $name === $variable || str_starts_with($name, $variable.'[') || str_starts_with($name, $variable.':'); }
    /**
     * Stan variables and their dimensions from column names, as CmdStanR reports them: complex numbers add a trailing 2,
     * and a tuple reports only the array dimensions around it. Relies on CmdStan writing each variable's last element last.
     */
    public static function variableSizes(array $columns): array
    {
        $last = [];
        foreach ($columns as $column) { $last[(string)preg_replace('/(\[|:).*$/s', '', $column)] = $column; }
        $sizes = [];
        foreach ($last as $base => $column) {
            $outer = explode(':', $column, 2)[0];
            if (!preg_match('/\[(.*)\]$/', $outer, $m)) { $sizes[$base] = [1]; continue; }
            $sizes[$base] = array_map(fn($i) => $i === 'imag' ? 2 : (int)$i, explode(',', $m[1]));
        }
        return $sizes;
    }
    /**
     * One draw's flat values ([name => value], CmdStanR spelling) as structured variables, in first-appearance order:
     * scalars stay numbers, indexed elements become zero-based nested lists (x[i][j]), complex values become [re, im]
     * pairs, and tuples become lists of their elements (t[0] is t:1). Zero-size variables, which have no columns, are absent.
     */
    public static function unflatten(array $values): array
    {
        $groups = [];
        foreach ($values as $name => $value) {
            $base = (string)preg_replace('/(\[|:).*$/s', '', (string)$name);
            $groups[$base][substr((string)$name, strlen($base))] = $value;
        }
        return array_map(self::leaves(...), $groups);
    }
    private static function leaves(array $bySuffix): mixed
    {
        if (array_keys($bySuffix) === ['']) { return $bySuffix['']; }
        if (count(array_diff(array_keys($bySuffix), ['[real]', '[imag]'])) === 0) { return [$bySuffix['[real]'] ?? 0.0, $bySuffix['[imag]'] ?? 0.0]; }
        $elements = []; $cells = [];
        foreach ($bySuffix as $suffix => $value) {
            $suffix = (string)$suffix;
            if (str_starts_with($suffix, ':')) {
                if (!preg_match('/^:(\d+)(.*)$/s', $suffix, $m)) { throw new \UnexpectedValueException('Cannot read tuple column suffix '.$suffix); }
                $elements[(int)$m[1]][$m[2]] = $value; continue;
            }
            if (!preg_match('/^\[([^\]]*)\](.*)$/s', $suffix, $m)) { throw new \UnexpectedValueException('Cannot read column suffix '.$suffix); }
            $indices = explode(',', $m[1]); $rest = $m[2];
            $part = end($indices);
            if ($part === 'real' || $part === 'imag') { array_pop($indices); $rest = '['.$part.']'.$rest; }
            $cells[implode(',', $indices)][$rest] = $value;
        }
        if ($elements !== []) {
            $out = [];
            for ($k = 1; $k <= max(array_keys($elements)); ++$k) { $out[] = isset($elements[$k]) ? self::leaves($elements[$k]) : []; }
            return $out;
        }
        $out = [];
        foreach ($cells as $key => $rest) {
            $ref = &$out;
            foreach (explode(',', (string)$key) as $index) { $ref = &$ref[(int)$index - 1]; }
            $ref = self::leaves($rest); unset($ref);
        }
        return self::lists($out);
    }
    /** Index-keyed arrays built by unflatten() as zero-based lists, recursively. */
    private static function lists(array $value): array
    {
        $out = [];
        for ($i = 0; $value !== [] && $i <= max(array_keys($value)); ++$i) { $out[] = is_array($value[$i] ?? null) ? self::lists($value[$i]) : ($value[$i] ?? null); }
        return $out;
    }
    /** Throws when a compress method ('gzip' or 'bzip2') has neither its PHP extension nor its program available. */
    public static function assertCompress(string $method): void
    {
        $ok = match ($method) { 'gzip' => function_exists('gzencode') || CmdStan::which('gzip') !== null, 'bzip2' => function_exists('bzcompress') || CmdStan::which('bzip2') !== null, 'none' => true, default => throw new \InvalidArgumentException("compress must be 'none', 'gzip' or 'bzip2'.") };
        if (!$ok) { throw new \RuntimeException("compress: '".$method."' needs PHP's ".($method === 'gzip' ? 'zlib' : 'bz2').' extension or the '.$method.' program, and neither was found.'); }
    }
    /** Compresses CSV files in place, as gzip -f and bzip2 -f do, and returns the new paths; missing files are left out. */
    public static function compress(array $files, string $method): array
    {
        if ($method === 'none') { return $files; }
        self::assertCompress($method); $out = [];
        foreach ($files as $file) {
            if (!is_file($file)) { continue; }
            $target = $file.($method === 'gzip' ? '.gz' : '.bz2');
            if ($method === 'gzip' && function_exists('gzencode')) { $data = gzencode((string)file_get_contents($file), 6); }
            elseif ($method === 'bzip2' && function_exists('bzcompress')) { $data = bzcompress((string)file_get_contents($file), 9); $data = is_string($data) ? $data : false; }
            else { Process::run([$method, '-f', $file]); $out[] = $target; continue; }
            if ($data === false || file_put_contents($target, $data, LOCK_EX) === false) { throw new \RuntimeException('Cannot compress '.$file); }
            unlink($file); $out[] = $target;
        }
        return $out;
    }
    /** A CmdStanR column name in CmdStan's spelling: x[1,2] is x.1.2, z[real] is z.real, and t:2[1] is t:2.1. */
    public static function cmdstanName(string $name): string { return str_replace(['[', ',', ']'], ['.', '.', ''], $name); }
    /**
     * Draws rows written as a sample-style CSV that standalone generated quantities accepts: lp__ and zeroed sampler
     * diagnostics first, then the other variables (lp_approx__ is left out), as CmdStanR's draws_to_csv().
     */
    public static function writeFittedParams(array $rows, string $file): string
    {
        $rows = array_values($rows);
        if ($rows === []) { throw new \InvalidArgumentException('There are no draws to write.'); }
        $sampler = ['lp__', 'accept_stat__', 'stepsize__', 'treedepth__', 'n_leapfrog__', 'divergent__', 'energy__'];
        $variables = array_values(array_filter(array_keys($rows[0]), fn($name) => !in_array($name, $sampler, true) && $name !== 'lp_approx__' && !str_starts_with((string)$name, '.')));
        $handle = fopen($file, 'xb') ?: throw new \RuntimeException('Cannot create '.$file);
        try {
            fwrite($handle, "# method = sample\n# num_samples = ".count($rows)."\n# num_warmup = 0\n# save_warmup = 0\n# thin = 1\n");
            fputcsv($handle, array_merge($sampler, array_map(self::cmdstanName(...), $variables)), ',', '"', '');
            foreach ($rows as $row) { fputcsv($handle, array_map(fn($name) => sprintf('%.17g', (float)($row[$name] ?? 0.0)), array_merge($sampler, $variables)), ',', '"', ''); }
        } finally { fclose($handle); }
        return $file;
    }
    /** True for CmdStan CSV files compressed by the compress option: .csv.gz and .csv.bz2. */
    public static function isCompressed(string $file): bool { return (bool)preg_match('/\.csv\.(gz|bz2)$/i', $file); }
    /** A compressed CSV decompressed to a temporary .csv file, which the caller removes. */
    public static function decompress(string $file): string
    {
        $raw = @file_get_contents($file);
        if ($raw === false) { throw new \InvalidArgumentException('Cannot read CSV: '.$file); }
        $gzip = (bool)preg_match('/\.gz$/i', $file);
        if ($gzip) { $csv = @gzdecode($raw); }
        elseif (function_exists('bzdecompress')) { $csv = @bzdecompress($raw); $csv = is_string($csv) ? $csv : false; }
        else {
            try { $csv = Process::run(['bzip2', '-dc', $file])['stdout']; } catch (\RuntimeException) { $csv = false; }
        }
        if ($csv === false) { throw new \RuntimeException("Compressed CSV '".basename($file)."' is truncated or corrupt."); }
        $out = sys_get_temp_dir().'/cmdstanphp-'.bin2hex(random_bytes(8)).'.csv';
        if (file_put_contents($out, $csv) === false) { throw new \RuntimeException('Cannot write a decompressed copy of '.$file); }
        return $out;
    }
    /**
     * Reads one CmdStan CSV file (.csv, .csv.gz or .csv.bz2). With $variables, only those variables (names, elements,
     * or tuples, as Csv::matches()) and the sampler diagnostics (unless $diagnostics is false) are kept, which saves
     * memory for large fits; metadata still lists every Stan variable's size.
     */
    public static function read(string $file, ?array $variables = null, bool $diagnostics = true): array
    {
        if (self::isCompressed($file)) {
            $copy = self::decompress($file);
            try { return self::read($copy, $variables, $diagnostics); } finally { @unlink($copy); }
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) { throw new \InvalidArgumentException('Cannot read CSV: '.$file); }
        $keep = null; $allColumns = null;
        $metadata = []; $header = null; $rows = []; $comments = []; $warmupRows = null; $stepSize = null; $metric = []; $readingMetric = false; $lineNumber = 0; $lp = null; $gradients = []; $time = ['warmup' => 0.0, 'sampling' => 0.0, 'total' => 0.0];
        try {
            while (($line = fgets($handle)) !== false) {
                ++$lineNumber; $line = trim($line);
                if ($line === '') { continue; }
                if (str_starts_with($line, '#')) {
                    $comment = trim(substr($line, 1)); $comments[] = $comment;
                    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $comment, $m)) {
                        $value = trim(preg_replace('/\s+\(Default\)$/', '', $m[2]));
                        $metadata[$m[1]] = match (strtolower($value)) {
                            'true' => true,
                            'false' => false,
                            default => is_numeric($value) ? (str_contains($value, '.') || stripos($value, 'e') !== false ? (float)$value : (int)$value) : $value,
                        };
                    }
                    if (str_starts_with($comment, 'Adaptation terminated')) { $warmupRows = count($rows); $readingMetric = false; }
                    if (preg_match('/([0-9.eE+-]+) seconds \((Warm-up|Sampling|Total)\)/', $comment, $m)) { $time[match ($m[2]) { 'Warm-up' => 'warmup', 'Sampling' => 'sampling', default => 'total' }] = (float)$m[1]; }
                    if (preg_match('/^Log probability\s*=\s*(\S+)/', $comment, $m)) { $lp = (float)$m[1]; }
                    elseif ($lp !== null && preg_match('/^\s*-?\d+(\s+\S+){4}\s*$/D', $comment) && count($g = preg_split('/\s+/', trim($comment))) === 5 && count(array_filter($g, 'is_numeric')) === 5) { $gradients[] = array_combine(['param_idx', 'value', 'model', 'finite_diff', 'error'], array_map('floatval', $g)); }
                    if (preg_match('/^Step size = (.*)$/', $comment, $m)) { $stepSize = (float)$m[1]; }
                    elseif (str_contains($comment, 'inverse mass matrix:')) { $readingMetric = true; }
                    elseif ($readingMetric && preg_match('/^[-+0-9.eE, ]+$/D', $comment)) { $metric[] = array_map('floatval', explode(',', $comment)); }
                    elseif ($readingMetric) { $readingMetric = false; }
                    continue;
                }
                $fields = str_getcsv($line, ',', '"', '');
                if ($header === null) {
                    if ($fields === [] || count(array_unique($fields)) !== count($fields)) { throw new \RuntimeException('Invalid or duplicate CSV columns.'); }
                    foreach ($fields as $field) { if ($field === '' || is_numeric($field)) { throw new \RuntimeException('Invalid CSV header.'); } }
                    $header = array_map(self::variableName(...), $fields); $allColumns = $header;
                    if ($variables !== null) {
                        $missing = array_values(array_filter($variables, fn($v) => array_filter($header, fn($name) => self::matches($name, (string)$v)) === []));
                        if ($missing !== []) { throw new \InvalidArgumentException("Can't find the following variable(s) in the output: ".implode(', ', $missing)); }
                        $keep = array_keys(array_filter($header, fn($name) => $name === 'lp__' || ($diagnostics && str_ends_with($name, '__')) || array_filter($variables, fn($v) => self::matches($name, (string)$v)) !== []));
                        $header = array_values(array_intersect_key($header, array_flip($keep)));
                    }
                    continue;
                }
                if (count($fields) !== count($allColumns ?? $header)) { throw new \RuntimeException('CSV row width mismatch at '.$file.':'.$lineNumber); }
                if ($keep !== null) { $fields = array_values(array_intersect_key($fields, array_flip($keep))); }
                $values = [];
                foreach ($fields as $value) {
                    $value = trim($value);
                    $values[] = match (strtolower($value)) {
                        'nan', '+nan', '-nan' => NAN,
                        'inf', '+inf', 'infinity', '+infinity' => INF,
                        '-inf', '-infinity' => -INF,
                        default => is_numeric($value) ? (float)$value : throw new \RuntimeException('Invalid CSV number at '.$file.':'.$lineNumber),
                    };
                }
                $rows[] = array_combine($header, $values);
            }
            if (!feof($handle)) { throw new \RuntimeException('CSV read failed.'); }
        } finally { fclose($handle); }
        if ($header === null && ($metadata['method'] ?? null) !== 'diagnose') { throw new \RuntimeException('CSV has no header: '.$file); }
        $header ??= [];
        $method = $metadata['method'] ?? 'sample';
        $warmupCount = 0;
        if ($method === 'sample' && ($metadata['save_warmup'] ?? 0)) {
            $warmupCount = $warmupRows ?? (int)ceil(($metadata['num_warmup'] ?? 0) / max(1, $metadata['thin'] ?? 1));
        }
        $warmup = array_slice($rows, 0, $warmupCount); $draws = array_slice($rows, $warmupCount);
        $estimate = null;
        if ($method === 'variational' && $draws !== []) { $estimate = array_shift($draws); }
        $columns = $header;
        if (in_array($method, ['variational', 'laplace'], true) && in_array('log_p__', $header, true)) {
            // CmdStanR exposes log_p__ as lp__ and log_g__/log_q__ as lp_approx__; ADVI's original lp__ is a placeholder.
            $keep = array_values(array_filter($header, fn($name) => $name !== 'lp__'));
            $header = array_map(fn($name) => ['log_p__' => 'lp__', 'log_g__' => 'lp_approx__', 'log_q__' => 'lp_approx__'][$name] ?? $name, $keep);
            $map = fn(?array $row) => $row === null ? null : array_combine($header, array_map(fn($name) => $row[$name], $keep));
            $draws = array_map($map, $draws); $estimate = $map($estimate);
        }
        if ($method === 'optimize' && $draws !== []) { $estimate = end($draws); }
        $metadata += self::cmdstanrMetadata($metadata, $columns, $stepSize, $lp, $time);
        if ($keep !== null) {
            $full = self::cmdstanrMetadata($metadata, $allColumns ?? [], $stepSize, $lp, $time);
            $metadata['stan_variables'] = $full['stan_variables']; $metadata['stan_variable_sizes'] = $full['stan_variable_sizes'];
        }
        return ['variables' => $header, 'draws' => $draws, 'warmup' => $warmup, 'metadata' => $metadata, 'comments' => $comments, 'step_size' => $stepSize, 'inv_metric' => count($metric) === 1 ? $metric[0] : $metric, 'estimate' => $estimate, 'gradients' => $gradients];
    }
    /** Keys added by CmdStanR's read_csv_metadata()/read_cmdstan_csv(); CmdStan's raw argument keys are retained alongside them. */
    private static function cmdstanrMetadata(array $raw, array $columns, ?float $stepSize, ?float $lp, array $time): array
    {
        $method = $raw['method'] ?? 'sample'; $out = [];
        foreach (['model' => 'model_name', 'engaged' => 'adapt_engaged', 'delta' => 'adapt_delta', 'max_depth' => 'max_treedepth', 'stepsize' => 'step_size', 'num_warmup' => 'iter_warmup', 'num_samples' => 'iter_sampling'] as $from => $to) {
            if (array_key_exists($from, $raw)) { $out[$to] = $raw[$from]; }
        }
        if (array_key_exists('num_threads', $raw)) { $out[in_array($method, ['variational', 'optimize', 'laplace', 'pathfinder'], true) ? 'threads' : 'threads_per_chain'] = $raw['num_threads']; }
        if ($stepSize !== null) { $out['step_size_adaptation'] = $stepSize; }
        if ($lp !== null) { $out['lp'] = $lp; }
        if (($raw['algorithm'] ?? null) === 'fixed_param') { $diagnostics = []; $variables = array_values(array_filter($columns, fn($v) => !str_ends_with($v, '__'))); }
        else {
            $diagnostics = array_values(array_filter($columns, fn($v) => str_ends_with($v, '__') && !in_array($v, ['lp__', 'log_p__', 'log_g__', 'log_q__'], true)));
            $variables = array_values(array_diff($columns, $diagnostics));
        }
        if ($method === 'variational') { $variables = array_values(array_filter($variables, fn($v) => $v !== 'lp__')); }
        $variables = array_map(fn($v) => ['log_p__' => 'lp__', 'log_g__' => 'lp_approx__', 'log_q__' => 'lp_approx__'][$v] ?? $v, $variables);
        if ($method === 'pathfinder') {
            // CmdStanR 1.0: lp_approx__ and path__ are variables, listed after lp__, not sampler diagnostics.
            $all = array_values(array_unique(array_merge($diagnostics, $variables)));
            $first = array_values(array_intersect(['lp__', 'lp_approx__', 'path__'], $all));
            $variables = array_merge($first, array_values(array_diff($all, $first)));
            $diagnostics = array_values(array_diff($diagnostics, ['lp_approx__', 'path__']));
        }
        $sizes = self::variableSizes($variables);
        $out += ['sampler_diagnostics' => $diagnostics, 'variables' => $variables, 'stan_variables' => array_keys($sizes), 'stan_variable_sizes' => $sizes, 'model_params' => $variables];
        if ($method === 'sample') { $out['time'] = ['chain_id' => $raw['id'] ?? 1] + $time; }
        return $out;
    }
}
