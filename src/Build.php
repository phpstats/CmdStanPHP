<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * CmdStanR 1.0's build system: what an executable was built from is recorded beside it in a hidden JSON build record
 * (.<executable>.cmdstanphp.json, CmdStanR's format version 1 with xxh128 file hashes), so a model is rebuilt exactly
 * when the Stan program, its #includes, the user header, cppOptions, stancOptions, make/local, or the selected CmdStan
 * changed. Builds run on a temporary copy of the program and are installed by rename, so a failed build leaves the
 * previous executable and its record in place.
 */
final class Build
{
    public const RECORD_VERSION = 1;
    private const REJECTED_STANC = [
        'include-paths' => 'Pass the directories with the includePaths argument.',
        'warn-pedantic' => 'Use pedantic: true.',
        'allow-undefined' => 'It is on whenever a userHeader is supplied, and checkSyntax(), format() and variables() always use it.',
        'use-opencl' => "Use cppOptions: ['stan_opencl' => true], which turns it on.",
        'name' => 'The model name comes from the name of the Stan file.',
    ];
    private const REASONS = [
        'missing' => 'the executable has no build record, so what it was built with cannot be determined',
        'unreadable' => 'the build record beside the executable could not be read, so what it was built with cannot be verified',
        'executable_mismatch' => 'the executable does not match its build record, so it was replaced or altered after it was built',
        'executable' => 'the executable changed after this model was created',
        'cpp_options' => '`cppOptions` changed',
        'stanc_options' => '`stancOptions` changed',
        'stanc_name' => 'the model name changed',
        'stan_file' => 'the Stan program changed',
        'force_recompile' => '`forceRecompile: true` was supplied',
        'force_recompile_option' => 'the `cmdstanr_force_recompile` option is set',
    ];
    public static function recordFile(string $exe): string { return dirname($exe).'/.'.basename($exe).'.cmdstanphp.json'; }
    /**
     * The lock file beside an executable. Building and installing hold it exclusively; checking the executable against
     * its record holds it shared, so no process sees a pair that another is midway through replacing, and processes
     * that start together build once. The file is never removed: removing a lock file lets two holders lock different
     * files.
     */
    public static function lockFile(string $exe): string { return dirname($exe).'/.'.basename($exe).'.cmdstanphp.lock'; }
    /**
     * Locks an executable's lock file (LOCK_SH or LOCK_EX), waiting for other holders. Only LOCK_EX creates the file, so
     * checking an executable that was never built here leaves its directory alone. Null when there is no lock to take:
     * no lock file for LOCK_SH, or a directory that cannot be written for LOCK_EX.
     */
    public static function lock(string $exe, int $operation): mixed
    {
        $handle = @fopen(self::lockFile($exe), $operation === LOCK_EX ? 'c' : 'r');
        if ($handle === false) { return null; }
        if (!flock($handle, $operation)) { fclose($handle); return null; }
        return $handle;
    }
    public static function unlock(mixed $handle): void { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }
    public static function hash(string $file): string { return hash_file('xxh128', $file) ?: throw new \RuntimeException('Cannot read '.$file); }
    /** The model name stanc and CmdStan see: the Stan file's basename without its extension, spaces replaced by underscores. */
    public static function modelName(string $stanFile): string { return str_replace(' ', '_', (string)preg_replace('/\.(stan|stanfunctions)$/', '', basename($stanFile))); }
    /**
     * Validates cppOptions and returns them as make assignments, NAME => value, sorted by name: names are uppercased,
     * true becomes 'true', and false becomes '' so the option is turned off even when make/local turns it on.
     *
     * @return array<string, string>
     */
    public static function cppOptions(array $options): array
    {
        $out = [];
        foreach ($options as $name => $value) {
            if (is_int($name)) { throw new \InvalidArgumentException(self::unnamedCppOption(is_scalar($value) ? (string)$value : '')); }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) { throw new \InvalidArgumentException('cppOptions names must be make variable names, made of letters, digits and underscores and not starting with a digit. `'.$name.'` is not one.'); }
            $upper = strtoupper($name);
            if ($upper === 'USER_HEADER') { throw new \InvalidArgumentException('The user header cannot be set through cppOptions. Pass it with the userHeader argument.'); }
            if ($upper === 'STANCFLAGS') { throw new \InvalidArgumentException('STANCFLAGS cannot be set through cppOptions. Pass stanc flags with the stancOptions argument.'); }
            if ($value === null) { continue; }
            $value = $value === true ? 'true' : ($value === false ? '' : Options::scalar($value));
            if (preg_match('/[\r\n$`#;&|<>()"\'\\\\]/', $value)) { throw new \InvalidArgumentException('Unsafe make option value for '.$upper.': '.$value); }
            $out[$upper] = $value;
        }
        ksort($out, SORT_STRING);
        return $out;
    }
    private static function unnamedCppOption(string $entry): string
    {
        if (preg_match('/(\+=|\?=|::=|:=|!=)/', $entry)) { return '`'.$entry.'` is makefile syntax and cannot be passed through cppOptions. To set it in make/local use cmdstan_make_local(cppOptions: [\''.$entry.'\']).'; }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/D', $entry, $m)) { return "cppOptions entries must be named. Write ['".$m[1]."' => '".$m[2]."'] instead of '".$entry."'."; }
        if (in_array($entry, ['-B', '--always-make'], true)) { return 'cppOptions cannot pass flags to make, and `'.$entry.'` rebuilds everything; pass forceRecompile: true instead.'; }
        if (str_starts_with($entry, '-')) { return 'cppOptions cannot pass flags to make. Set them in make/local with cmdstan_make_local(), for example `MAKEFLAGS += -j4`.'; }
        return "cppOptions entries must be named: ['NAME' => value].";
    }
    /**
     * Validates stancOptions (['O1', 'warn-uninitialized', 'canonicalize' => 'deprecations', 'max-line-length' => 78]) and
     * returns them as stanc arguments. Values may contain spaces and quotes; flags that have their own argument are rejected.
     *
     * @return list<string>
     */
    public static function stancOptions(array $options): array
    {
        $args = [];
        foreach ($options as $key => $value) {
            $name = is_int($key) ? (is_scalar($value) ? (string)$value : '') : $key;
            if (str_starts_with($name, '-')) { throw new \InvalidArgumentException('No leading hyphens allowed in stanc options ('.$name."). Use options without leading hyphens, for example stancOptions: ['warn-uninitialized']."); }
            if (!is_int($key) && str_contains($key, '=')) { throw new \InvalidArgumentException("stancOptions names cannot contain `=`. Write the value after the name: ['".explode('=', $key, 2)[0]."' => '".explode('=', $key, 2)[1]."'] instead of ['".$key."' => ...]."); }
            $flag = explode('=', $name, 2)[0];
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $flag)) { throw new \InvalidArgumentException('Invalid stanc option: '.$name); }
            if (isset(self::REJECTED_STANC[$flag])) { throw new \InvalidArgumentException('`'.$flag.'` cannot be set through stancOptions. '.self::REJECTED_STANC[$flag]); }
            if (is_int($key)) { $args[] = '--'.$name; continue; }
            if ($value === false || $value === null) { continue; }
            $value = $value === true ? null : (is_array($value) ? implode(',', array_map(Options::scalar(...), $value)) : Options::scalar($value));
            if ($value !== null && preg_match('/[\r\n\0]/', $value)) { throw new \InvalidArgumentException('stanc option values cannot contain newlines.'); }
            $args[] = $value === null ? '--'.$key : '--'.$key.'='.$value;
        }
        return $args;
    }
    /** Include paths as absolute directories; without any, the Stan file's directory when the program has an #include. */
    public static function includePaths(string $stanFile, ?array $paths): array
    {
        if ($paths === null || $paths === []) { return str_contains((string)file_get_contents($stanFile), '#include') ? [dirname($stanFile)] : []; }
        return array_values(array_map(fn($path) => is_dir((string)$path) ? (string)realpath((string)$path) : throw new \InvalidArgumentException('Include path does not exist: '.(str_starts_with((string)$path, '/') ? $path : getcwd().'/'.$path)), $paths));
    }
    /** stanc arguments added for a build, in CmdStanR's order: pedantic, OpenCL, undefined functions for a user header, the model name, and the file name in messages. */
    public static function addedStancOptions(string $stanFile, bool $pedantic, array $cpp, ?string $userHeader, array $user): array
    {
        $added = [];
        if ($pedantic) { $added[] = '--warn-pedantic'; }
        if (($cpp['STAN_OPENCL'] ?? '') === 'true') { $added[] = '--use-opencl'; }
        if ($userHeader !== null) { $added[] = '--allow-undefined'; }
        $added[] = '--name='.self::modelName($stanFile).'_model';
        if (!array_filter($user, fn($arg) => str_starts_with($arg, '--filename-in-msg'))) { $added[] = '--filename-in-msg='.$stanFile; }
        return $added;
    }
    /** stanc --info for a Stan file, with its include paths and undefined functions allowed. */
    public static function info(string $stanFile, array $includePaths = [], ?float $timeout = null): array
    {
        $args = [CmdStan::path().'/bin/stanc'.CmdStan::suffix(), '--info', '--allow-undefined'];
        if ($includePaths !== []) { $args[] = '--include-paths='.implode(',', $includePaths); }
        $args[] = $stanFile;
        return json_decode(Process::run($args, timeout: $timeout)['stdout'], true, flags: JSON_THROW_ON_ERROR);
    }
    /** stanc --info remapped as CmdStanR's variables(): data, parameters, transformed_parameters, generated_quantities. */
    public static function variables(array $info): array
    {
        return ['data' => $info['inputs'] ?? [], 'parameters' => $info['parameters'] ?? [], 'transformed_parameters' => $info['transformed parameters'] ?? [], 'generated_quantities' => $info['generated quantities'] ?? []];
    }
    /** What the build depends on: the program, its includes (from stanc --info), the user header, and make/local. */
    public static function dependencies(string $stanFile, array $info, ?string $userHeader): array
    {
        $dependency = fn(string $file) => ['hash' => self::hash($file), 'built_from' => realpath($file) ?: $file];
        $deps = ['stan_file' => $dependency($stanFile), 'included_files' => array_map($dependency, $info['included_files'] ?? [])];
        if ($userHeader !== null) { $deps['user_header'] = $dependency($userHeader); }
        $local = CmdStan::path().'/make/local';
        if (is_file($local)) { $deps['make_local'] = $dependency($local); }
        return $deps;
    }
    /** Runs `<exe> info`: the threading, MPI, OpenCL and range-check flags plus the Stan version. Failures give []. */
    public static function reportedFeatures(string $exe): array
    {
        try { $output = Process::run([$exe, 'info'], timeout: 30)['stdout']; } catch (\Throwable) { return []; }
        $raw = []; $features = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(\S*)\s*$/D', $line, $m)) { $raw[strtolower($m[1])] = $m[2]; }
        }
        foreach ($raw as $key => $value) { if ($value === 'true' || $value === 'false') { $features[$key] = $value === 'true'; } }
        if (isset($raw['stan_version_major'], $raw['stan_version_minor'], $raw['stan_version_patch'])) { $features['stan_version'] = $raw['stan_version_major'].'.'.$raw['stan_version_minor'].'.'.$raw['stan_version_patch']; }
        return $features;
    }
    /** The five reported features stan_build_info() always lists; unknown flags are null. */
    public static function featureSummary(array $features): array
    {
        $out = [];
        foreach (['stan_threads', 'stan_mpi', 'stan_opencl', 'stan_no_range_checks'] as $flag) { $out[$flag] = $features[$flag] ?? null; }
        return $out + ['stan_version' => $features['stan_version'] ?? null];
    }
    /**
     * Reads the build record beside an executable: ['status' => available|missing|unreadable|unsupported_format|executable_mismatch, 'record' => ?array].
     */
    public static function readRecord(string $exe): array
    {
        $file = self::recordFile($exe);
        if (!is_file($file)) { return ['status' => 'missing', 'record' => null]; }
        try { $record = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR); } catch (\JsonException) { return ['status' => 'unreadable', 'record' => null]; }
        if (!is_array($record) || !is_int($record['format_version'] ?? null)) { return ['status' => 'unreadable', 'record' => null]; }
        if ($record['format_version'] !== self::RECORD_VERSION) { return ['status' => 'unsupported_format', 'record' => null, 'format_version' => $record['format_version']]; }
        if (!is_array($record['configuration'] ?? null) || !is_array($record['dependencies']['stan_file'] ?? null) || !is_string($record['executable_hash'] ?? null) || !is_array($record['cmdstan'] ?? null)) { return ['status' => 'unreadable', 'record' => null]; }
        if (!is_file($exe) || self::hash($exe) !== $record['executable_hash']) { return ['status' => 'executable_mismatch', 'record' => $record]; }
        return ['status' => 'available', 'record' => $record];
    }
    /** The rebuild reason for a record that cannot be used. */
    public static function recordReason(array $read): string
    {
        if ($read['status'] === 'unsupported_format') { return 'the build record was written by '.($read['format_version'] > self::RECORD_VERSION ? 'a newer' : 'an older').' version of CmdStanPHP (format '.$read['format_version'].'; this version understands '.self::RECORD_VERSION.'), so how the executable was built cannot be verified'; }
        return self::REASONS[$read['status']];
    }
    /**
     * Compares a recorded build with the current one and returns the reasons they differ, in CmdStanR's order. Dependencies
     * are compared only when both are known (they are not resolved for a different CmdStan).
     *
     * @return list<string>
     */
    public static function differences(array $record, array $current): array
    {
        $reasons = []; $was = $record['configuration']; $now = $current['configuration'];
        $sorted = function (array $options): array { ksort($options, SORT_STRING); return $options; };
        if ($sorted($was['cpp_options'] ?? []) != $sorted($now['cpp_options'])) { $reasons[] = self::REASONS['cpp_options']; }
        if (($was['stanc_options'] ?? []) !== $now['stanc_options']) { $reasons[] = self::REASONS['stanc_options']; }
        if (($was['stanc_name'] ?? null) !== $now['stanc_name']) { $reasons[] = self::REASONS['stanc_name']; }
        if (isset($current['dependencies'])) {
            $old = $record['dependencies']; $new = $current['dependencies'];
            if ($old['stan_file']['hash'] !== $new['stan_file']['hash']) { $reasons[] = self::REASONS['stan_file']; }
            $oldIncludes = $old['included_files'] ?? []; $newIncludes = $new['included_files'];
            if (count($oldIncludes) !== count($newIncludes)) { $reasons[] = 'the set of included files changed'; }
            else {
                $changed = [];
                foreach ($newIncludes as $i => $include) { if ($include['hash'] !== $oldIncludes[$i]['hash'] || $include['built_from'] !== $oldIncludes[$i]['built_from']) { $changed[] = $include['built_from']; } }
                if ($changed !== []) { $reasons[] = 'included files changed ('.implode(', ', $changed).')'; }
            }
            if (($old['user_header'] ?? null) != ($new['user_header'] ?? null)) { $reasons[] = 'the user header changed ('.($new['user_header']['built_from'] ?? $old['user_header']['built_from'] ?? '').')'; }
            if (($old['make_local']['hash'] ?? null) !== ($new['make_local']['hash'] ?? null)) { $reasons[] = 'make/local changed ('.CmdStan::path().'/make/local)'; }
        }
        if (isset($current['executable_hash']) && $record['executable_hash'] !== $current['executable_hash']) { $reasons[] = self::REASONS['executable']; }
        if ($record['cmdstan'] != $current['cmdstan']) { $reasons[] = sprintf('the selected CmdStan changed (built with %s at \'%s\'; %s at \'%s\' is selected now)', $record['cmdstan']['version'] ?? '?', $record['cmdstan']['path'] ?? '?', $current['cmdstan']['version'], $current['cmdstan']['path']); }
        return $reasons;
    }
    public static function forceReason(?bool $forceRecompile): ?string
    {
        if ($forceRecompile === true) { return self::REASONS['force_recompile']; }
        if ($forceRecompile === null && CmdStan::option('cmdstanr_force_recompile', false)) { return self::REASONS['force_recompile_option']; }
        return null;
    }
    public static function message(array $reasons, bool $noExecutable): string
    {
        if ($reasons === []) { return 'Model executable is up to date!'; }
        if ($noExecutable) { return 'Compiling Stan program...'; }
        return "Recompiling:\n".implode("\n", array_map(fn($reason) => '  - '.$reason, $reasons));
    }
    public static function current(): array { return ['path' => CmdStan::path(), 'version' => CmdStan::version()]; }
    /** STANCFLAGS and the TBB directories as make/local sets them for these make variables (one make call). */
    public static function makeSettings(array $makeVars, ?float $timeout = null): array
    {
        $file = tempnam(sys_get_temp_dir(), 'cmdstanphp-make-') ?: throw new \RuntimeException('Cannot create a temporary makefile.');
        try {
            file_put_contents($file, "MAKEFILE_LIST := \$(filter-out \$(lastword \$(MAKEFILE_LIST)),\$(MAKEFILE_LIST))\n.PHONY: cmdstanphp-print\ncmdstanphp-print: ; @printf 'cmdstanphp-stancflag=%s\\n' \$(STANCFLAGS) && printf 'cmdstanphp-tbb-lib=%s\\n' '\$(TBB_LIB)' && printf 'cmdstanphp-tbb-bin=%s\\n' '\$(TBB_BIN)'\n");
            $out = Process::run(array_merge(['make', '-s', '-C', CmdStan::path()], $makeVars, ['-f', 'makefile', '-f', $file, 'cmdstanphp-print']), timeout: $timeout)['stdout'];
        } finally { @unlink($file); }
        $flags = []; $lib = ''; $bin = '';
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            if (str_starts_with($line, 'cmdstanphp-stancflag=') && strlen($line) > 21) { $flags[] = substr($line, 21); }
            elseif (str_starts_with($line, 'cmdstanphp-tbb-lib=')) { $lib = trim(substr($line, 19)); }
            elseif (str_starts_with($line, 'cmdstanphp-tbb-bin=')) { $bin = trim(substr($line, 19)); }
        }
        foreach ($flags as $flag) {
            if (str_contains($flag, '--include-paths') || str_starts_with($flag, '-I')) { throw new \InvalidArgumentException('make/local sets an include path in STANCFLAGS (`'.$flag.'`). Include paths cannot be set there. Remove it from make/local and pass the directories with the includePaths argument.'); }
        }
        $tbb = $lib !== '' ? $lib : ($bin !== '' ? $bin : 'stan/lib/stan_math/lib/tbb');
        return ['stancflags' => $flags, 'tbb_dir' => str_starts_with($tbb, '/') ? $tbb : CmdStan::path().'/'.$tbb];
    }
    /** Removes make/local STANCFLAGS words that set a flag this build already passes (and a bare flag's separate value). */
    public static function dropOverridden(array $fromMake, array $emitted): array
    {
        $flags = array_map(fn($arg) => explode('=', $arg, 2)[0], $emitted); $out = [];
        for ($i = 0; $i < count($fromMake); ++$i) {
            $word = $fromMake[$i];
            if (in_array(explode('=', $word, 2)[0], $flags, true)) {
                if (!str_contains($word, '=') && isset($fromMake[$i + 1]) && !str_starts_with($fromMake[$i + 1], '-')) { ++$i; }
                continue;
            }
            $out[] = $word;
        }
        return $out;
    }
    /** One word for a make recipe line: single-quoted for sh when needed, with $ doubled for make. */
    public static function makeQuote(string $word): string
    {
        $quoted = preg_match('/^[A-Za-z0-9_.\/:=+@%,-]+$/D', $word) ? $word : "'".str_replace("'", "'\\''", $word)."'";
        return str_replace('$', '$$', $quoted);
    }
    /** Lines that pull in files the record cannot track: includes in make/local and quoted includes in the user header. */
    public static function untracked(?string $userHeader): array
    {
        $out = []; $local = CmdStan::path().'/make/local';
        if (is_file($local) && preg_match('/^\s*(?:-?include|sinclude)\b/m', (string)file_get_contents($local))) { $out[] = ['kind' => 'make_local_include', 'detected_in' => $local]; }
        if ($userHeader !== null && preg_match('/^\s*#\s*include\s*"/m', (string)file_get_contents($userHeader))) { $out[] = ['kind' => 'user_header_include', 'detected_in' => $userHeader]; }
        return $out;
    }
    /**
     * Compiles $stanFile into $exe on a temporary copy and installs the executable and its record by rename. A failed
     * build throws and leaves the previous executable and record untouched. Returns the record written.
     */
    public static function compile(string $stanFile, string $exe, array $configuration, array $dependencies, ?string $userHeader, array $makeVars, array $stancAdded, ?float $timeout = null): array
    {
        $dir = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/cmdstanphp-build-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) { throw new \RuntimeException('Cannot create build directory.'); }
        try {
            $source = $dir.'/model-'.bin2hex(random_bytes(4)).'.stan';
            if (!copy($stanFile, $source)) { throw new \RuntimeException('Cannot copy the Stan program for compilation.'); }
            $target = substr($source, 0, -5).CmdStan::suffix();
            $stanc = array_merge($configuration['stanc_options'], $stancAdded);
            $include = $configuration['include_paths'] === [] ? [] : ['--include-paths='.implode(',', array_map(self::makeQuote(...), $configuration['include_paths']))];
            $words = array_merge($include, array_map(self::makeQuote(...), $stanc), array_map(self::makeQuote(...), $configuration['stanc_options_from_make']));
            $cmd = array_merge(['make', '-C', CmdStan::path(), $target], $makeVars, ['STANCFLAGS += '.implode(' ', $words)]);
            if (CmdStan::option('cmdstanr_verbose', false)) { echo implode(' ', $cmd)."\n"; }
            try { $result = Process::run($cmd, timeout: $timeout); }
            catch (ProcessException $e) { throw new \RuntimeException("An error occurred during compilation! See the message below for more information.\n".$e->stdout.$e->stderr, 0, $e); }
            if (CmdStan::option('cmdstanr_verbose', false)) { echo $result['stdout'].$result['stderr']; }
            if (!is_file($target)) { throw new \RuntimeException('Compilation did not produce an executable.'); }
            $features = self::reportedFeatures($target);
            $settings = $configuration['tbb_dir'];
            unset($configuration['tbb_dir']);
            $record = ['format_version' => self::RECORD_VERSION, 'configuration' => $configuration, 'reported_features' => $features, 'dependencies' => $dependencies, 'executable_hash' => self::hash($target), 'cmdstan' => self::current(), 'tbb_dir' => $settings, 'untracked_dependencies' => self::untracked($userHeader)];
            self::install($target, $exe, $record);
            return $record;
        } finally { self::remove($dir); }
    }
    /** Stages the executable and record beside the destination, then swaps them in; on failure the previous pair is restored. */
    private static function install(string $built, string $exe, array $record): void
    {
        $destDir = dirname($exe); $recordFile = self::recordFile($exe);
        $stagedExe = $destDir.'/.cmdstanphp-new-'.bin2hex(random_bytes(6)).CmdStan::suffix(); $stagedRecord = $stagedExe.'.json';
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
        if (!copy($built, $stagedExe) || !chmod($stagedExe, 0755) || file_put_contents($stagedRecord, $json, LOCK_EX) === false) {
            @unlink($stagedExe); @unlink($stagedRecord);
            throw new \RuntimeException('Cannot stage the compiled executable. The model executable at \''.$exe.'\' was not modified.');
        }
        $moves = [];
        $backup = fn(string $file) => $destDir.'/.cmdstanphp-old-'.bin2hex(random_bytes(6)).'-'.basename($file);
        try {
            foreach ([[$exe, $backup($exe)], [$recordFile, $backup($recordFile)]] as [$from, $to]) {
                if (file_exists($from)) { if (!rename($from, $to)) { throw new \RuntimeException('Cannot move '.$from.' aside.'); } $moves[] = [$from, $to]; }
            }
            if (!rename($stagedExe, $exe)) { throw new \RuntimeException('Cannot move the new executable into place.'); }
            $moves[] = [$stagedExe, $exe];
            if (!rename($stagedRecord, $recordFile)) { throw new \RuntimeException('Cannot move the new build record into place.'); }
        } catch (\Throwable $e) {
            foreach (array_reverse($moves) as [$from, $to]) { @rename($to, $from); }
            @unlink($stagedExe); @unlink($stagedRecord);
            throw new \RuntimeException('Could not install the compiled executable at \''.$exe.'\': '.$e->getMessage().' The executable and build record there are as they were.', 0, $e);
        }
        foreach ($moves as [$from, $to]) { if ($to !== $exe) { @unlink($to); } }
        $legacy = $exe.'.cmdstanphp.json';
        if (is_file($legacy)) { @unlink($legacy); }
    }
    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) { $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
        @rmdir($dir);
    }
}
