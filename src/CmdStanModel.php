<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanModel
{
    private ?string $stanFile = null;
    private string $exeFile;
    private ?string $code = null;
    private ?array $info = null;
    private array $configuration = ['cpp_options' => [], 'stanc_options' => [], 'stanc_options_from_make' => [], 'include_paths' => []];
    private array $features = [];
    private ?array $cmdstan = null;
    private ?string $userHeader = null;
    private string $exeHash;
    private ?array $stamps = null;
    private ?string $hppFile = null;
    private ?StanFunctions $functions = null;
    /**
     * CmdStanR 1.0's cmdstan_model(): the model is compiled, or an up-to-date executable is reused, before the constructor
     * returns. Give stanFile (the executable goes beside it, or in dir) or exeFile alone (nothing is built, and build
     * arguments are refused). A rebuild happens when the build record shows that the Stan program, its #includes, the
     * user header, cppOptions, stancOptions, make/local or the selected CmdStan changed; forceRecompile (default: the
     * cmdstanr_force_recompile option) rebuilds anyway. quiet: false reports whether and why it compiles.
     */
    public function __construct(?string $stanFile = null, ?string $exeFile = null, bool $quiet = true, ?string $dir = null, bool $pedantic = false, ?array $includePaths = null, ?string $userHeader = null, ?array $cppOptions = null, ?array $stancOptions = null, ?bool $forceRecompile = null, ?float $timeout = null)
    {
        if ($stanFile === null && $exeFile === null) { throw new \InvalidArgumentException('Unable to create a CmdStanModel object. Both stanFile and exeFile are undefined.'); }
        if ($stanFile !== null && $exeFile !== null) { throw new \InvalidArgumentException('stanFile and exeFile cannot both be supplied. Use dir to choose where the executable for a Stan program is built.'); }
        if ($exeFile !== null) {
            $refuse = fn(string $name, string $why = 'With no Stan file there is nothing to build, so the executable is used as it is.') => throw new \InvalidArgumentException($name.' cannot be supplied for a model created from an executable alone. '.$why);
            foreach (['cppOptions' => $cppOptions, 'stancOptions' => $stancOptions, 'userHeader' => $userHeader, 'forceRecompile' => $forceRecompile, 'dir' => $dir] as $name => $value) { if ($value !== null) { $refuse($name); } }
            if ($includePaths !== null) { $refuse('includePaths', 'Include paths resolve #include lines in a Stan file, and there is none.'); }
            if ($pedantic) { $refuse('pedantic', 'Pedantic mode checks a Stan program, and there is none.'); }
            $real = realpath($exeFile);
            if ($real === false || !is_file($real)) { throw new \InvalidArgumentException('Executable not found: '.$exeFile); }
            $this->exeFile = $real; $lock = Build::lock($real, LOCK_SH);
            try {
                $read = Build::readRecord($real);
                if ($read['status'] === 'available') { $this->adopt($read['record']); $this->exeHash = $read['record']['executable_hash']; }
                else { $this->features = Build::reportedFeatures($real); $this->exeHash = Build::hash($real); }
            } finally { Build::unlock($lock); }
            return;
        }
        $real = realpath((string)$stanFile);
        if ($real === false || !is_file($real) || pathinfo($real, PATHINFO_EXTENSION) !== 'stan') { throw new \InvalidArgumentException('Expected an existing .stan file.'); }
        $this->stanFile = $real; $this->code = (string)file_get_contents($real);
        $target = $dir === null ? dirname($real) : (realpath($dir) ?: throw new \InvalidArgumentException('dir does not exist: '.$dir));
        if (!is_dir($target) || !is_writable($target)) { throw new \InvalidArgumentException('dir must be a writable directory: '.$target); }
        $this->exeFile = $target.'/'.pathinfo($real, PATHINFO_FILENAME).CmdStan::suffix();
        if (is_dir($this->exeFile)) { throw new \RuntimeException('There is a subfolder matching the model name in the same folder as the model! Please remove or rename the subfolder and try again.'); }
        $cpp = Build::cppOptions($cppOptions ?? []); $stanc = Build::stancOptions($stancOptions ?? []);
        if ($userHeader !== null) { $userHeader = realpath($userHeader) ?: throw new \InvalidArgumentException("User header file '".$userHeader."' does not exist."); }
        $includes = Build::includePaths($real, $includePaths);
        $added = Build::addedStancOptions($real, $pedantic, $cpp, $userHeader, $stanc);
        $configuration = ['cpp_options' => $cpp, 'stanc_options' => $stanc, 'stanc_options_added' => $added, 'stanc_options_from_make' => [], 'stanc_name' => Build::modelName($real).'_model', 'include_paths' => $includes];
        // The check, the build and the fingerprint of the result happen under one exclusive lock: processes that start
        // together wait for the first build and then find it current, instead of each installing its own.
        $lock = Build::lock($this->exeFile, LOCK_EX);
        try { $this->build($real, $configuration, $cpp, $stanc, $added, $includes, $userHeader, $pedantic, $quiet, $forceRecompile, $timeout); }
        finally { Build::unlock($lock); }
    }
    private function build(string $real, array $configuration, array $cpp, array $stanc, array $added, array $includes, ?string $userHeader, bool $pedantic, bool $quiet, ?bool $forceRecompile, ?float $timeout): void
    {
        $read = is_file($this->exeFile) ? Build::readRecord($this->exeFile) : ['status' => 'no_executable', 'record' => null];
        $current = Build::current(); $dependencies = null;
        if ($read['status'] === 'available' && $read['record']['cmdstan'] == $current) { $this->info = Build::info($real, $includes, $timeout); $dependencies = Build::dependencies($real, $this->info, $userHeader); }
        $reasons = $read['status'] === 'no_executable' ? ["there is no executable at '".$this->exeFile."'"] : ($read['status'] === 'available' ? Build::differences($read['record'], ['configuration' => $configuration, 'dependencies' => $dependencies, 'cmdstan' => $current]) : [Build::recordReason($read)]);
        if (($force = Build::forceReason($forceRecompile)) !== null) { $reasons[] = $force; }
        if (!$quiet || CmdStan::option('cmdstanr_verbose', false)) { echo Build::message($reasons, $read['status'] === 'no_executable')."\n"; }
        if ($reasons === []) {
            $record = $read['record'];
            if ($pedantic) { $this->adopt($record); $this->showPedanticWarnings($added); }
        } else {
            $this->info ??= Build::info($real, $includes, $timeout); $dependencies ??= Build::dependencies($real, $this->info, $userHeader);
            $makeVars = array_merge(array_map(fn($name, $value) => $name.'='.$value, array_keys($cpp), $cpp), $userHeader === null ? [] : ['USER_HEADER='.$userHeader]);
            $settings = Build::makeSettings($makeVars, $timeout);
            $configuration['stanc_options_from_make'] = Build::dropOverridden($settings['stancflags'], array_merge($stanc, $added));
            $configuration['tbb_dir'] = $settings['tbb_dir'];
            $record = Build::compile($real, $this->exeFile, $configuration, $dependencies, $userHeader, $makeVars, $added, $timeout);
            if ($record['untracked_dependencies'] !== []) {
                $what = array_map(fn($u) => $u['kind'] === 'make_local_include' ? 'make/local includes another makefile' : 'the user header includes other headers', $record['untracked_dependencies']);
                fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), 'Note: this model has dependencies CmdStanPHP does not track: '.implode(', ', array_unique($what)).". If those files change, rebuild with forceRecompile: true.\n");
            }
        }
        $this->adopt($record);
        $this->exeHash = $record['executable_hash'];
        $this->stamps = $this->stamps();
    }
    public function __destruct() { if ($this->hppFile !== null && is_file($this->hppFile)) { @unlink($this->hppFile); } }
    public function __serialize(): array { $data = get_object_vars($this); $data['hppFile'] = null; $data['functions'] = null; return $data; }
    public function __unserialize(array $data): void { foreach ($data as $key => $value) { $this->$key = $value; } }
    private function adopt(array $record): void
    {
        $this->configuration = $record['configuration'] + $this->configuration; $this->features = $record['reported_features'] ?? []; $this->cmdstan = $record['cmdstan'];
        $this->userHeader = $record['dependencies']['user_header']['built_from'] ?? null;
    }
    private function showPedanticWarnings(array $added): void
    {
        $out = sys_get_temp_dir().'/cmdstanphp-'.bin2hex(random_bytes(8)).'.hpp';
        try { $result = Process::run(array_merge([CmdStan::path().'/bin/stanc'.CmdStan::suffix(), '--o='.$out], $this->stancIncludeArgs(), $this->configuration['stanc_options'], $added, [(string)$this->stanFile])); }
        finally { if (is_file($out)) { unlink($out); } }
        if (trim($result['stderr']) !== '') { fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), $result['stderr']); }
    }
    private function stancIncludeArgs(?array $paths = null): array
    {
        $paths ??= $this->configuration['include_paths'];
        return $paths === [] ? [] : ['--include-paths='.implode(',', $paths)];
    }
    /**
     * A cheap fingerprint of everything the build depends on, so an unchanged build is confirmed without running stanc:
     * content hashes of the program, its includes, the user header and make/local, and the executable's file status.
     */
    private function stamps(): array
    {
        clearstatcache();
        $sources = [(string)$this->stanFile, CmdStan::path().'/make/local', CmdStan::path().'/makefile', ...($this->info['included_files'] ?? [])];
        if ($this->userHeader !== null) { $sources[] = $this->userHeader; }
        $out = ['cmdstan' => CmdStan::path()];
        foreach ($sources as $file) { $out[$file] = is_file($file) ? hash_file('xxh128', $file) : null; }
        $stat = @stat($this->exeFile);
        $out[$this->exeFile] = $stat === false ? null : [$stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']];
        return $out;
    }
    /** CmdStanR's check before every run: the executable must still be what this model built, from the current program. */
    private function assertCurrent(): void
    {
        if ($this->stanFile === null) {
            if (!is_file($this->exeFile)) { throw new StaleExecutableException("The executable at '".$this->exeFile."' no longer exists."); }
            if (Build::hash($this->exeFile) !== $this->exeHash) { throw new StaleExecutableException("The executable at '".$this->exeFile."' changed after this model was created."); }
            return;
        }
        if (!is_file($this->stanFile)) { throw new StaleExecutableException("The Stan file '".$this->stanFile."' this model was created from no longer exists. To run the executable without its program, create the model with cmdstan_model(exeFile: ...)."); }
        if ($this->stamps !== null && $this->stamps === $this->stamps()) { return; }
        $lock = Build::lock($this->exeFile, LOCK_SH);
        try { $this->checkRecord(); } finally { Build::unlock($lock); }
    }
    /** assertCurrent()'s comparison of the executable and its record with this model, run under a shared lock. */
    private function checkRecord(): void
    {
        $read = is_file($this->exeFile) ? Build::readRecord($this->exeFile) : ['status' => 'no_executable', 'record' => null];
        if ($read['status'] === 'no_executable') { $reasons = ["there is no executable at '".$this->exeFile."'"]; }
        elseif ($read['status'] !== 'available') { $reasons = [Build::recordReason($read)]; }
        else {
            $current = Build::current(); $info = null; $dependencies = null;
            if ($read['record']['cmdstan'] == $current) { $info = Build::info($this->stanFile, $this->configuration['include_paths']); $dependencies = Build::dependencies($this->stanFile, $info, $this->userHeader); }
            $reasons = Build::differences($read['record'], ['configuration' => $this->configuration, 'dependencies' => $dependencies, 'executable_hash' => $this->exeHash, 'cmdstan' => $current]);
            if ($reasons === [] && $info !== null) { $this->info = $info + ($this->info ?? []); }
        }
        if ($reasons !== []) { throw new StaleExecutableException("The executable is out of date:\n".implode("\n", array_map(fn($r) => '  - '.$r, $reasons))."\nRun cmdstan_model() to rebuild it."); }
        $this->stamps = $this->stamps();
    }
    /** False when the executable is out of date (see assertCurrent's reasons); other failures, such as a stanc error, throw. */
    public function isCurrent(): bool
    {
        try { $this->assertCurrent(); return true; } catch (StaleExecutableException) { return false; }
    }
    /** stan_build_info() for this model's executable. */
    public function buildInfo(): array { return self::stanBuildInfo($this->exeFile); }
    /** CmdStanR's stan_build_info(): how an executable was built, from its record, and what it reports about itself. */
    public static function stanBuildInfo(string $exeFile): array
    {
        $exe = realpath($exeFile) ?: throw new \InvalidArgumentException('Executable not found: '.$exeFile);
        $lock = Build::lock($exe, LOCK_SH);
        try { $read = Build::readRecord($exe); } finally { Build::unlock($lock); }
        $record = $read['status'] === 'available' ? $read['record'] : null;
        $features = $record['reported_features'] ?? Build::reportedFeatures($exe);
        if (!isset($features['stan_version'])) { throw new \RuntimeException("Running '".$exe."' with the argument `info` did not report a Stan version, so it is either not a CmdStan executable or cannot be run."); }
        $out = ['record' => ['status' => $read['status'], 'reason' => $record === null ? Build::recordReason($read) : null], 'reported_features' => Build::featureSummary($features)];
        if ($read['status'] === 'unsupported_format') { $out['format_version'] = $read['format_version']; }
        if ($record === null) { return $out; }
        $exists = fn(?array $dep) => $dep === null ? null : ['built_from' => $dep['built_from'], 'exists' => is_file($dep['built_from'])];
        $deps = $record['dependencies'];
        $out['configuration'] = array_intersect_key($record['configuration'], array_flip(['cpp_options', 'stanc_options', 'stanc_options_from_make', 'include_paths']));
        $out['dependencies'] = ['stan_file' => $exists($deps['stan_file']), 'included_files' => array_map($exists, $deps['included_files'] ?? []), 'user_header' => $exists($deps['user_header'] ?? null), 'make_local' => $exists($deps['make_local'] ?? null)];
        $out['cmdstan'] = $record['cmdstan'] + ['exists' => is_dir($record['cmdstan']['path'])];
        $untracked = $record['untracked_dependencies'] ?? [];
        usort($untracked, fn($a, $b) => [$a['kind'], $a['detected_in']] <=> [$b['kind'], $b['detected_in']]);
        $out['untracked_dependencies'] = array_values(array_unique($untracked, SORT_REGULAR));
        return $out;
    }
    public function stanFile(): ?string { return $this->stanFile; }
    public function exeFile(): string { return $this->exeFile; }
    /** The generated C++ (written to a temporary file on first use); null for a model created from an executable alone. */
    public function hppFile(): ?string
    {
        if ($this->stanFile === null) { return null; }
        if ($this->hppFile !== null && is_file($this->hppFile)) { return $this->hppFile; }
        $file = sys_get_temp_dir().'/model-'.bin2hex(random_bytes(6)).'.hpp';
        Process::run(array_merge([CmdStan::path().'/bin/stanc'.CmdStan::suffix(), '--o='.$file], $this->stancIncludeArgs(), $this->configuration['stanc_options'], $this->configuration['stanc_options_added'] ?? [], [$this->stanFile]));
        return $this->hppFile = $file;
    }
    /** The program as it was when the model was created; null, with a warning, for a model created from an executable alone. */
    public function code(): ?string
    {
        if ($this->stanFile === null) { trigger_error('code() will return null because the CmdStanModel was not created with a Stan file.', E_USER_WARNING); return null; }
        return $this->code;
    }
    public function hasStanFile(): bool { return $this->stanFile !== null && is_file($this->stanFile); }
    public function modelName(): string { return pathinfo($this->stanFile ?? $this->exeFile, PATHINFO_FILENAME); }
    /** The cppOptions the executable was built with, as make variables: ['stan_threads' => true] comes back as ['STAN_THREADS' => 'true']. */
    public function cppOptions(): array { return $this->configuration['cpp_options']; }
    /** The stanc arguments from stancOptions the executable was built with. */
    public function stancOptions(): array { return $this->configuration['stanc_options']; }
    public function includePaths(): array { return $this->configuration['include_paths']; }
    public function userHeader(): ?string { return $this->userHeader; }
    /** The version of CmdStan that built the executable (from its build record, else the Stan version it reports). */
    public function cmdstanVersion(): ?string { return $this->cmdstan['version'] ?? $this->features['stan_version'] ?? null; }
    /** What the executable reports about its build: stan_threads, stan_mpi, stan_opencl, stan_no_range_checks, stan_version. */
    public function reportedFeatures(): array { return Build::featureSummary($this->features); }
    /** Stan options for the in-process builds (exposed functions and model methods), in NativeBuild's form. */
    public function nativeStancOptions(): array
    {
        $out = [];
        foreach ($this->configuration['stanc_options'] as $arg) { $parts = explode('=', substr($arg, 2), 2); $out[$parts[0]] = $parts[1] ?? true; }
        if ($this->configuration['include_paths'] !== []) { $out['include-paths'] = $this->configuration['include_paths']; }
        if ($this->userHeader !== null) { $out['allow-undefined'] = true; }
        return $out;
    }
    private function requireStanFile(string $method): string
    {
        if ($this->stanFile === null) { throw new \RuntimeException($method.' cannot be used because the CmdStanModel was not created with a Stan file.'); }
        if (!is_file($this->stanFile)) { throw new \RuntimeException("The Stan file '".$this->stanFile."' this model was created from no longer exists."); }
        return $this->stanFile;
    }
    /** CmdStanR's expose_functions(): compiles user-defined Stan functions into a library called through PHP FFI. */
    public function exposeFunctions(?float $timeout = null, bool $quiet = false): StanFunctions
    {
        $this->assertCurrent();
        return $this->functions ??= StanFunctions::compile($this->requireStanFile('exposeFunctions()'), $this->nativeStancOptions(), timeout: $timeout);
    }
    public function functions(): StanFunctions { return $this->functions ?? throw new \RuntimeException('Functions have not been exposed; call exposeFunctions() first.'); }
    /** Prints the program; see print_stan_file(). */
    public function print(?bool $lineNumbers = null): void { Stanc::print((string)$this->code(), $lineNumbers); }
    /** check_syntax_stan_file() for this model's program; include paths default to the model's. */
    public function checkSyntax(bool $pedantic = false, ?array $includePaths = null, array $stancOptions = [], bool $quiet = false): bool
    {
        return Stanc::checkSyntax($this->requireStanFile('checkSyntax()'), $includePaths ?? $this->configuration['include_paths'], $pedantic, $stancOptions, $quiet);
    }
    /** format_stan_file() for this model's program. After overwriteFile, create the model again to pick up the rewritten file. */
    public function format(bool $overwriteFile = false, bool|array $canonicalize = false, bool $backup = true, ?int $maxLineLength = null, bool $quiet = false): string
    {
        return Stanc::format($this->requireStanFile('format()'), $this->configuration['include_paths'], $overwriteFile, $canonicalize, $backup, $maxLineLength, $quiet);
    }
    /** The program's variables as stanc --info declares them: data, parameters, transformed_parameters, generated_quantities. */
    public function variables(): array
    {
        if ($this->stanFile === null) { throw new \RuntimeException('variables() cannot be used because the CmdStanModel was not created with a Stan file.'); }
        $this->info ??= Build::info($this->stanFile, $this->configuration['include_paths']);
        return Build::variables($this->info);
    }
    private const DEFAULTS = [
        'sample' => ['iter_sampling' => 'sample.num_samples', 'iter_warmup' => 'sample.num_warmup', 'save_warmup' => 'sample.save_warmup', 'thin' => 'sample.thin', 'adapt_engaged' => 'sample.adapt.engaged', 'adapt_delta' => 'sample.adapt.delta', 'init_buffer' => 'sample.adapt.init_buffer', 'term_buffer' => 'sample.adapt.term_buffer', 'window' => 'sample.adapt.window', 'save_metric' => 'sample.adapt.save_metric', 'max_treedepth' => 'sample.hmc.nuts.max_depth', 'metric' => 'sample.hmc.metric', 'metric_file' => 'sample.hmc.metric_file', 'step_size' => 'sample.hmc.stepsize'],
        'optimize' => ['algorithm' => 'optimize.algorithm', 'jacobian' => 'optimize.jacobian', 'iter' => 'optimize.iter', 'init_alpha' => 'optimize.lbfgs.init_alpha', 'tol_obj' => 'optimize.lbfgs.tol_obj', 'tol_rel_obj' => 'optimize.lbfgs.tol_rel_obj', 'tol_grad' => 'optimize.lbfgs.tol_grad', 'tol_rel_grad' => 'optimize.lbfgs.tol_rel_grad', 'tol_param' => 'optimize.lbfgs.tol_param', 'history_size' => 'optimize.lbfgs.history_size'],
        'variational' => ['algorithm' => 'variational.algorithm', 'iter' => 'variational.iter', 'grad_samples' => 'variational.grad_samples', 'elbo_samples' => 'variational.elbo_samples', 'eta' => 'variational.eta', 'adapt_engaged' => 'variational.adapt.engaged', 'adapt_iter' => 'variational.adapt.iter', 'tol_rel_obj' => 'variational.tol_rel_obj', 'eval_elbo' => 'variational.eval_elbo', 'draws' => 'variational.output_samples'],
        'pathfinder' => ['init_alpha' => 'pathfinder.init_alpha', 'tol_obj' => 'pathfinder.tol_obj', 'tol_rel_obj' => 'pathfinder.tol_rel_obj', 'tol_grad' => 'pathfinder.tol_grad', 'tol_rel_grad' => 'pathfinder.tol_rel_grad', 'tol_param' => 'pathfinder.tol_param', 'history_size' => 'pathfinder.history_size', 'single_path_draws' => 'pathfinder.num_draws', 'draws' => 'pathfinder.num_psis_draws', 'num_paths' => 'pathfinder.num_paths', 'save_single_paths' => 'pathfinder.save_single_paths', 'psis_resample' => 'pathfinder.psis_resample', 'calculate_lp' => 'pathfinder.calculate_lp', 'max_lbfgs_iters' => 'pathfinder.max_lbfgs_iters', 'num_elbo_draws' => 'pathfinder.num_elbo_draws'],
        'laplace' => ['jacobian' => 'laplace.jacobian', 'draws' => 'laplace.draws'],
    ];
    /**
     * CmdStanR's cmdstan_defaults(): CmdStan's default argument values for a method, read from `<exe> <method> help-all`
     * and named as this package's options (iter_sampling, adapt_delta, ...), in help order.
     */
    public function cmdstanDefaults(string $method = 'sample'): array
    {
        $map = self::DEFAULTS[$method] ?? throw new \InvalidArgumentException("method must be one of 'sample', 'optimize', 'variational', 'pathfinder', 'laplace'.");
        $this->assertCurrent();
        try { $result = Process::run([$this->exeFile, $method, 'help-all'], timeout: 60); }
        catch (ProcessException $e) { $result = ['stdout' => $e->stdout, 'stderr' => $e->stderr]; }
        catch (\RuntimeException $e) { throw $this->cannotRun($e->getMessage()); }
        $lines = preg_split('/\r?\n/', $result['stdout']."\n".$result['stderr']) ?: []; $byKey = array_flip($map);
        $stack = []; $out = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') { continue; }
            $indent = strlen($line) - strlen(ltrim($line)); $text = trim($line);
            while ($stack !== [] && end($stack)[1] >= $indent) { array_pop($stack); }
            if (preg_match('/^([a-z_][a-z0-9_]*)$/D', $text, $m)) { $stack[] = [$m[1], $indent]; continue; }
            if (!preg_match('/^([a-z_][a-z0-9_]*)=/', $text, $m)) { continue; }
            $key = implode('.', array_merge(array_column($stack, 0), [$m[1]]));
            if (!isset($byKey[$key])) { continue; }
            for ($j = $i + 1; $j <= min($i + 5, count($lines) - 1); ++$j) {
                $next = trim($lines[$j]);
                if (preg_match('/^[a-z_][a-z0-9_]*=/', $next)) { break; }
                if (preg_match('/^Defaults to\s?(.*)$/D', $next, $d)) {
                    $value = trim($d[1]);
                    $out[$byKey[$key]] = match (true) { $value === 'true' => true, $value === 'false' => false, (bool)preg_match('/^-?[0-9]+$/D', $value) => (int)$value, (bool)preg_match('/^-?[0-9]*\.?[0-9]+([eE][+-]?[0-9]+)?$/D', $value) => (float)$value, default => $value };
                    break;
                }
            }
        }
        return $out;
    }
    /** CmdStanR's error for an executable that cannot be started. */
    private function cannotRun(string $reason): \RuntimeException
    {
        $remedy = $this->stanFile === null ? 'There is no Stan file to rebuild it from.' : 'Run cmdstan_model() with forceRecompile: true to rebuild it.';
        return new \RuntimeException("The executable at '".$this->exeFile."' could not be run: ".$reason."\n".$remedy);
    }
    public function saveHppFile(string $dir): string
    {
        $hpp = $this->hppFile() ?? throw new \RuntimeException('There is no generated C++ for a model created from an executable alone.');
        if (!is_dir($dir)) { throw new \InvalidArgumentException('Destination directory must exist.'); }
        $target = rtrim($dir, '/\\').'/'.$this->modelName().'.hpp';
        if (!copy($hpp, $target)) { throw new \RuntimeException('Cannot copy header.'); }
        return $target;
    }
    /**
     * Use named arguments (sample(chains: 4)) or a single options array. Every method's data may be an array, a JSON
     * file, a StanData, or a table such as a phibble DataFrame (any object with toModelData() or toColumns(); see StanData).
     */
    public function sample(mixed ...$options): CmdStanMCMC { return $this->run('sample', self::options($options)); }
    public function sampleMpi(string $mpiCmd = 'mpiexec', array $mpiArgs = [], mixed ...$options): CmdStanMCMC
    {
        $o = self::options($options); $o['chains'] ??= 1; $o['parallel_chains'] = 1;
        if ($mpiCmd === '') { throw new \InvalidArgumentException('mpiCmd must identify a launcher.'); }
        $launcher = [$mpiCmd];
        foreach ($mpiArgs as $name => $value) {
            if (is_int($name)) { $launcher[] = Options::scalar($value); }
            else {
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name)) { throw new \InvalidArgumentException('Invalid MPI argument name.'); }
                $launcher[] = '-'.$name;
                if ($value !== true) { $launcher[] = Options::scalar($value); }
            }
        }
        return $this->run('sample', $o, $launcher);
    }
    public function optimize(mixed ...$options): CmdStanMLE { return $this->run('optimize', self::options($options)); }
    public function variational(mixed ...$options): CmdStanVB { return $this->run('variational', self::options($options)); }
    public function pathfinder(mixed ...$options): CmdStanPathfinder
    {
        $o = self::options($options);
        if (array_key_exists('num_threads', $o)) {
            if (isset($o['threads'])) { throw new \InvalidArgumentException('Cannot specify both `threads` and deprecated `num_threads`.'); }
            trigger_error('`num_threads` is deprecated as of CmdStanR 1.0.0 and will be removed in a future release. Please use `threads` instead.', E_USER_DEPRECATED);
            $o['threads'] = $o['num_threads']; unset($o['num_threads']);
        }
        return $this->run('pathfinder', $o);
    }
    public function laplace(mixed ...$options): CmdStanLaplace
    {
        $o = self::options($options); Options::validate('laplace', $o);
        if (!isset($o['mode'])) {
            $o['data'] = StanData::normalize($o['data'] ?? null); $opt = $o['opt_args'] ?? [];
            $opt['data'] = $o['data'] ?? null; $opt['jacobian'] = $o['jacobian'] ?? true;
            foreach (['seed', 'init', 'show_messages', 'show_exceptions', 'sig_figs', 'timeout', 'output_dir', 'threads'] as $name) { if (isset($o[$name])) { $opt[$name] = $o[$name]; } }
            if (isset($o['output_basename'])) { $opt['output_basename'] = $o['output_basename'].'-mode'; }
            $o['mode'] = $this->optimize($opt);
        }
        if ($o['mode'] instanceof CmdStanMLE) {
            $meta = $o['mode']->metadata();
            if (isset($meta['jacobian']) && (bool)$meta['jacobian'] !== ($o['jacobian'] ?? true)) { throw new \InvalidArgumentException('Laplace and optimizer jacobian settings must match.'); }
            $o['mode'] = $o['mode']->outputFiles()[0];
        }
        unset($o['opt_args']);
        return $this->run('laplace', $o);
    }
    /**
     * Standalone generated quantities. fittedParams is an MCMC fit, an optimization, Laplace, variational or Pathfinder
     * fit (its draws are written as one sample-style CSV, as in CmdStanR 1.0), or a list of CmdStan CSV files.
     */
    public function generateQuantities(CmdStanFit|array|string $fittedParams, mixed ...$options): CmdStanGQ
    {
        $o = self::options($options); $dir = (is_string($o['output_dir'] ?? null) ? $o['output_dir'] : sys_get_temp_dir()).'/fittedParams-'.bin2hex(random_bytes(6));
        if ($fittedParams instanceof CmdStanMCMC || !$fittedParams instanceof CmdStanFit) { $files = $fittedParams instanceof CmdStanFit ? $fittedParams->outputFiles() : (array)$fittedParams; }
        elseif ($fittedParams instanceof CmdStanMLE || $fittedParams instanceof CmdStanLaplace || $fittedParams instanceof CmdStanVB || $fittedParams instanceof CmdStanPathfinder) {
            $rows = $fittedParams instanceof CmdStanMLE ? [$fittedParams->mle() + ['lp__' => $fittedParams->lp()]] : $fittedParams->draws(null, 'draws_matrix');
            if (!is_dir($dir)) { mkdir($dir, 0700, true); }
            $files = [Csv::writeFittedParams($rows, $dir.'/fittedParams-01.csv')];
        } else { throw new \InvalidArgumentException('fittedParams must be CmdStan CSV files or an MCMC, optimization, Laplace, variational or Pathfinder fit.'); }
        $o['fitted_params'] = array_map(function ($file) use ($dir) {
            if (!is_string($file) || !Csv::isCompressed($file)) { return $file; }
            if (!is_dir($dir)) { mkdir($dir, 0700, true); }
            $copy = Csv::decompress($file); $target = $dir.'/'.preg_replace('/\.(gz|bz2)$/i', '', basename($file)); rename($copy, $target); return $target;
        }, $files);
        return $this->run('generate_quantities', $o);
    }
    public function diagnose(mixed ...$options): CmdStanDiagnose { return $this->run('diagnose', self::options($options)); }
    public function logProb(mixed ...$options): CmdStanFit
    {
        $o = self::options($options);
        if (isset($o['unconstrained_params']) === isset($o['constrained_params'])) { throw new \InvalidArgumentException('Specify exactly one parameter file.'); }
        return $this->run('log_prob', $o);
    }
    private static function options(array $options): array
    {
        if (array_is_list($options) && count($options) === 1 && is_array($options[0])) { return $options[0]; }
        if ($options !== [] && array_is_list($options)) { throw new \InvalidArgumentException('Use named arguments or one options array.'); }
        return $options;
    }
    /** CmdStanR's partial-initialization message, written to stderr like R's message(). */
    private function warnPartialInits(array $inits): void
    {
        try { $parameters = array_keys($this->variables()['parameters'] ?? []); } catch (\Throwable) { return; }
        $lines = [];
        foreach ($inits as $chain => $init) {
            $missing = array_diff($parameters, array_keys($init));
            if ($missing !== []) { $lines[] = (count($inits) > 1 ? ' - chain '.$chain.': ' : '').implode(', ', $missing); }
        }
        if ($lines !== []) { fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), "Init values were only set for a subset of parameters. \nMissing init values for the following parameters:\n".implode("\n", $lines)."\n\nTo disable this message use CmdStan\\options(['cmdstanr_warn_inits' => false]).\n"); }
    }
    /** CmdStanR's process_init() for fit objects: per-chain parameter arrays drawn from the fit, matched to declared dimensions. */
    private function initsFromFit(CmdStanFit $fit, int $chains, ?int $seed): array
    {
        $result = Inits::fromFit($fit, $chains, $this->stanFile === null ? null : ($this->variables()['parameters'] ?? null), $seed, false);
        if (CmdStan::option('cmdstanr_warn_inits', true)) { foreach ($result['warnings'] as $warning) { fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), $warning['message']."\n"); } }
        return $result['inits'];
    }
    /**
     * Initial values as one CLI value per chain (or per Pathfinder path): a radius, init files, or JSON written from
     * arrays, a callable (called exactly once per chain or path, with its id), or a fit. Null when init is unset.
     */
    private function initFiles(mixed $init, int $count, array $ids, string $dir, \Closure $name, ?array $parameters, ?int $seed): ?array
    {
        if ($init === null) { return null; }
        if ($init instanceof CmdStanFit) { $init = $this->initsFromFit($init, $count, $seed); }
        if (is_int($init) || is_float($init)) {
            if (!is_finite((float)$init) || $init < 0) { throw new \InvalidArgumentException('init must be nonnegative, an input file, an array or callable.'); }
            return [Options::scalar($init)];
        }
        if (is_string($init)) { return [$this->inputFile($init, $dir, 'init')]; }
        if (is_callable($init)) {
            $values = [];
            foreach ($ids as $id) { $value = $init($id); if (!is_array($value) || array_is_list($value) && $value !== []) { throw new \InvalidArgumentException('If init is a function it must return a single array of initial values.'); } $values[] = $value; }
            $init = $values;
        }
        if (!is_array($init)) { throw new \InvalidArgumentException('init must be nonnegative, an input file, an array or callable.'); }
        if (!array_is_list($init)) { $init = array_fill(0, $count, $init); }
        if ($init !== [] && count(array_filter($init, 'is_string')) === count($init)) {
            if (count($init) !== 1 && count($init) !== $count) { throw new \InvalidArgumentException('If init is a list of files, its length must be 1 or equal to the number of chains or Pathfinder paths.'); }
            return array_map(fn($file) => $this->inputFile($file, $dir, 'init'), $init);
        }
        if (count($init) !== $count) { throw new \InvalidArgumentException('init has the wrong length: give one set of initial values per '.($count === count($ids) && $ids === range(1, $count) ? 'chain or Pathfinder path' : 'chain').'.'); }
        foreach ($init as $values) { if (!is_array($values) || $values === []) { throw new \InvalidArgumentException('init contains empty lists.'); } }
        if (CmdStan::option('cmdstanr_warn_inits', true)) { $this->warnPartialInits(array_combine(range(1, $count), $init)); }
        return array_map(fn($values, $id) => $this->inputFile($values, $dir, $name($id), $parameters), $init, $ids);
    }
    private static function temporaryFile(string $suffix): string { return sys_get_temp_dir().'/cmdstanphp-'.bin2hex(random_bytes(8)).$suffix; }
    private function inputFile(mixed $input, string $dir, string $name, ?array $variables = null, bool $alwaysDecimal = false): ?string
    {
        if ($input === null) { return null; }
        if (is_array($input)) {
            $file = $dir.'/'.$name.'.json';
            if (file_exists($file)) { throw new \RuntimeException('Input artifact already exists: '.$file); }
            return StanJson::write($input, $file, $alwaysDecimal, $variables);
        }
        if (!is_string($input) || !is_file($input)) { throw new \InvalidArgumentException('Expected a data array, StanData, a table with toColumns(), or an existing input file.'); }
        return realpath($input);
    }
    private function run(string $method, array $o, array $launcher = []): CmdStanFit
    {
        Options::validate($method, $o);
        $this->assertCurrent();
        $threads = $o['threads_per_chain'] ?? $o['threads'] ?? null;
        if ($threads !== null && $threads > 1 && ($this->features['stan_threads'] ?? null) !== true) { throw new \InvalidArgumentException('`'.(in_array($method, ['sample', 'generate_quantities'], true) ? 'threads_per_chain' : 'threads')."` is set but the executable does not report threading as enabled.\nRecompile the model with `cppOptions: ['stan_threads' => true]`."); }
        if (isset($o['opencl_ids']) && ($this->features['stan_opencl'] ?? null) !== true) { throw new \InvalidArgumentException("`opencl_ids` is set but the executable does not report OpenCL as enabled.\nRecompile the model with `cppOptions: ['stan_opencl' => true]`."); }
        $chains = $method === 'sample' ? ($o['chains'] ?? 4) : ($method === 'generate_quantities' ? count($o['fitted_params']) : 1);
        if ($o['fixed_param'] ?? false) { $chains = 1; $o['iter_warmup'] = 0; }
        if ($chains < 1) { throw new \InvalidArgumentException('At least one input chain is required.'); }
        $ids = $o['chain_ids'] ?? range(1, $chains);
        if (!is_array($ids) || !array_is_list($ids) || count($ids) !== $chains || count(array_unique($ids)) !== $chains || count(array_filter($ids, fn($x) => is_int($x) && $x > 0)) !== $chains) { throw new \InvalidArgumentException('chain_ids must be unique positive integers, one per chain.'); }
        if (is_array($o['seed'] ?? null) && count($o['seed']) !== $chains) { throw new \InvalidArgumentException('Provide one seed per chain.'); }
        $dir = $o['output_dir'] ?? CmdStan::option('cmdstanr_output_dir') ?? self::temporaryFile('');
        if (!is_string($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true))) { throw new \RuntimeException('Cannot create output directory.'); }
        $dir = realpath($dir);
        $named = isset($o['output_basename']); $basename = $o['output_basename'] ?? (string)preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->modelName());
        if (!is_string($basename) || in_array($basename, ['.', '..'], true) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $basename)) { throw new \InvalidArgumentException('output_basename must be a simple filename containing letters, digits, dots, underscores and hyphens.'); }
        // CmdStanR 1.0's names: <model>[-<kind>]-<YYYYmmddHHMM>-<id>-<random>, or <basename>[-<kind>]-<id>; ids have at least two digits.
        $stamp = date('YmdHi'); $random = bin2hex(random_bytes(3)); $width = max(2, strlen((string)max($ids)));
        $name = fn(string $kind = '', ?int $id = null) => $basename.($kind === '' ? '' : '-'.$kind).($named ? '' : '-'.$stamp).($id === null ? '' : '-'.str_pad((string)$id, $width, '0', STR_PAD_LEFT)).($named ? '' : '-'.$random);
        foreach ($ids as $id) {
            if (file_exists($dir.'/'.$name('', $id).'.csv')) { throw new \RuntimeException('Output already exists: '.$dir.'/'.$name('', $id).'.csv'); }
        }
        $compress = $o['compress'] ?? CmdStan::option('cmdstanr_compress', 'none');
        if ($compress !== 'none') { Csv::assertCompress($compress); }
        if ($method === 'sample' && !array_key_exists('save_metric', $o) && CmdStan::option('cmdstanr_save_metric') !== null) { $o['save_metric'] = CmdStan::option('cmdstanr_save_metric'); }
        if (in_array($method, ['sample', 'optimize', 'laplace', 'variational', 'pathfinder'], true) && !array_key_exists('save_cmdstan_config', $o) && CmdStan::option('cmdstanr_save_config') !== null) { $o['save_cmdstan_config'] = CmdStan::option('cmdstanr_save_config'); }
        $declarations = $this->stanFile === null ? null : $this->variables();
        $o['data'] = StanData::normalize($o['data'] ?? null);
        if (is_array($o['data']) && $declarations !== null) {
            $missing = array_diff(array_keys($declarations['data']), array_keys($o['data']));
            if ($missing !== []) { throw new \InvalidArgumentException('Missing input data for the following data variables: '.implode(', ', $missing).'.'); }
        }
        $data = $this->inputFile($o['data'], $dir, $name('data'), $declarations['data'] ?? null, $declarations !== null);
        // Pathfinder takes one initialization per path, the other methods one per chain.
        $numInits = $method === 'pathfinder' ? ($o['num_paths'] ?? 4) : $chains;
        $initIds = $method === 'pathfinder' ? range(1, $numInits) : $ids;
        $initFiles = $this->initFiles($o['init'] ?? null, $numInits, $initIds, $dir, fn(int $id) => $name('init', $id), $declarations['parameters'] ?? null, is_array($o['seed'] ?? null) ? $o['seed'][0] : ($o['seed'] ?? null));
        $jobs = []; $files = []; $diagnosticFiles = []; $profileFiles = []; $commands = [];
        foreach ($ids as $i => $id) {
            $local = $o; $file = $dir.'/'.$name('', $id).'.csv';
            $files[] = $file;
            if ($method === 'generate_quantities') { $local['fitted_params'] = $this->inputFile($o['fitted_params'][$i], $dir, $name('fitted', $id)); }
            if ($method === 'laplace') { $local['mode'] = $this->inputFile($o['mode'], $dir, $name('mode')); }
            foreach (['constrained_params', 'unconstrained_params'] as $key) { if (isset($o[$key])) { $local[$key] = $this->inputFile($o[$key], $dir, $name($key)); } }
            if (isset($o['inv_metric'])) { $local['metric_file'] = StanJson::write(['inv_metric' => $o['inv_metric']], $dir.'/'.$name('inv_metric', $id).'.json'); }
            elseif (isset($o['metric_file'])) { $local['metric_file'] = $this->inputFile(is_array($o['metric_file']) ? ($o['metric_file'][$i] ?? throw new \InvalidArgumentException('One metric file required per chain.')) : $o['metric_file'], $dir, 'metric'); }
            $cmd = array_merge([$this->exeFile], Options::methodArgs($method, $local), ['id='.$id]);
            if ($data !== null) { array_push($cmd, 'data', 'file='.$data); }
            if (isset($o['seed'])) { array_push($cmd, 'random', 'seed='.(is_array($o['seed']) ? $o['seed'][$i] : $o['seed'])); }
            if ($initFiles !== null) { $cmd[] = 'init='.($method === 'pathfinder' ? implode(',', $initFiles) : ($initFiles[$i] ?? $initFiles[0])); }
            if ($threads !== null) { $cmd[] = 'num_threads='.$threads; }
            if (isset($o['opencl_ids'])) { $cmd[] = 'opencl_platform_id='.$o['opencl_ids'][0]; $cmd[] = 'opencl_device_id='.$o['opencl_ids'][1]; }
            array_push($cmd, 'output', 'file='.$file);
            foreach (['refresh', 'sig_figs', 'save_cmdstan_config'] as $key) { if (isset($o[$key])) { $cmd[] = $key.'='.Options::scalar($o[$key]); } }
            if ($o['save_latent_dynamics'] ?? false) { $diagnosticFiles[] = $dir.'/'.$name('diagnostic', $id).'.csv'; $cmd[] = 'diagnostic_file='.end($diagnosticFiles); }
            $profileFiles[] = $dir.'/'.$name('profile', $id).'.csv'; $cmd[] = 'profile_file='.end($profileFiles);
            $commands[] = $cmd;
            $jobs[] = ['command' => array_merge($launcher, $cmd), 'env' => $threads === null ? [] : ['STAN_NUM_THREADS' => (string)$threads]];
        }
        if (CmdStan::option('cmdstanr_verbose', false)) { foreach ($jobs as $job) { echo implode(' ', $job['command'])."\n"; } }
        $bar = $method === 'sample' && ($o['show_progress_bar'] ?? CmdStan::option('cmdstanr_progress_bar', false)) ? new ProgressBar(count($jobs)) : null;
        try {
            if (!is_executable($this->exeFile)) { throw $this->cannotRun('Permission denied'); }
            $results = Process::pool($jobs, min($chains, $o['parallel_chains'] ?? CmdStan::option('mc.cores', 1)), $o['timeout'] ?? null, $bar === null ? null : $bar->update(...));
        } finally { $bar?->finish(); }
        foreach ($results as $r) {
            if ($o['show_messages'] ?? true) { echo $bar === null ? $r['stdout'] : ProgressBar::withoutIterations($r['stdout']); }
            if ($o['show_exceptions'] ?? ($o['show_messages'] ?? true)) { echo $r['stderr']; }
        }
        if ($compress !== 'none') { $files = Csv::compress($files, $compress); $diagnosticFiles = Csv::compress($diagnosticFiles, $compress); }
        $class = CmdStanFit::classForMethod($method);
        $fit = new $class($files, $method, $results, $diagnosticFiles, $profileFiles, ['commands' => $commands, 'init_files' => $initFiles === null || is_numeric($initFiles[0]) ? null : $initFiles, 'exe_file' => $this->exeFile, 'code' => $this->code, 'data_file' => $data, 'fitted_params_files' => $o['fitted_params'] ?? [], 'mode' => $o['mode'] ?? null, 'model_name' => $this->modelName(), 'stan_file' => $this->stanFile, 'stanc_options' => $this->nativeStancOptions(), 'variables' => $declarations, 'ids' => $ids, 'owned' => true, 'data_owned' => is_array($o['data'] ?? null)]);
        $checks = array_key_exists('diagnostics', $o) ? ($o['diagnostics'] ?? []) : ['divergences', 'treedepth', 'ebfmi'];
        if ($method === 'sample' && !($o['fixed_param'] ?? false) && $checks !== []) { $fit->checkDiagnostics($checks); }
        return $fit;
    }
}
