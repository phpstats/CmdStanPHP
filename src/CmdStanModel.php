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
            $this->exeFile = $real; $read = Build::readRecord($real);
            if ($read['status'] === 'available') { $this->adopt($read['record']); } else { $this->features = Build::reportedFeatures($real); }
            $this->exeHash = Build::hash($real);
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
        $read = is_file($this->exeFile) ? Build::readRecord($this->exeFile) : ['status' => 'no_executable', 'record' => null];
        $current = Build::current(); $dependencies = null;
        if ($read['status'] === 'available' && $read['record']['cmdstan'] == $current) { $this->info = Build::info($real, $includes, $timeout); $dependencies = Build::dependencies($real, $this->info, $userHeader); }
        $reasons = $read['status'] === 'no_executable' ? ["there is no executable at '".$this->exeFile."'"] : ($read['status'] === 'available' ? Build::differences($read['record'], ['configuration' => $configuration, 'dependencies' => $dependencies, 'cmdstan' => $current]) : [Build::recordReason($read)]);
        if (($force = Build::forceReason($forceRecompile)) !== null) { $reasons[] = $force; }
        if (!$quiet || CmdStan::option('cmdstanr_verbose', false)) { echo Build::message($reasons, $read['status'] === 'no_executable')."\n"; }
        if ($reasons === []) {
            $record = $read['record'];
            if ($pedantic) { $this->showPedanticWarnings($added); }
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
    /** File status of everything the build depends on, so an unchanged build is confirmed without hashing or running stanc. */
    private function stamps(): array
    {
        $files = [$this->exeFile, (string)$this->stanFile, CmdStan::path().'/make/local', CmdStan::path().'/makefile'];
        foreach ($this->info['included_files'] ?? [] as $file) { $files[] = $file; }
        if ($this->userHeader !== null) { $files[] = $this->userHeader; }
        clearstatcache();
        $out = [CmdStan::path()];
        foreach ($files as $file) { $stat = @stat($file); $out[$file] = $stat === false ? null : [$stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']]; }
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
        $read = Build::readRecord($exe); $record = $read['status'] === 'available' ? $read['record'] : null;
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
    private function nativeStancOptions(): array
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
    public function sample(mixed ...$options): CmdStanMCMC { return $this->run('sample', self::deprecated(self::options($options), ['cores', 'num_cores', 'num_chains', 'num_warmup', 'num_samples', 'max_depth', 'stepsize', 'save_extra_diagnostics', 'validate_csv'])); }
    public function sampleMpi(string $mpiCmd = 'mpiexec', array $mpiArgs = [], mixed ...$options): CmdStanMCMC
    {
        $o = self::deprecated(self::options($options), ['validate_csv']); $o['chains'] ??= 1; $o['parallel_chains'] = 1;
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
    public function optimize(mixed ...$options): CmdStanMLE { return $this->run('optimize', self::deprecated(self::options($options), ['jacobian_adjustment'])); }
    public function variational(mixed ...$options): CmdStanVB { return $this->run('variational', self::options($options)); }
    public function pathfinder(mixed ...$options): CmdStanPathfinder { CmdStan::requireVersion('2.33.0'); return $this->run('pathfinder', self::options($options)); }
    public function laplace(mixed ...$options): CmdStanLaplace
    {
        CmdStan::requireVersion('2.32.0'); $o = self::deprecated(self::options($options), ['jacobian_adjustment']); Options::validate('laplace', $o);
        if (!isset($o['mode'])) {
            $o['data'] = StanData::normalize($o['data'] ?? null); $opt = $o['opt_args'] ?? [];
            $opt['data'] = $o['data'] ?? null; $opt['jacobian'] = $o['jacobian'] ?? true;
            foreach (['seed', 'init', 'show_messages', 'show_exceptions', 'sig_figs', 'timeout'] as $name) { if (isset($o[$name])) { $opt[$name] = $o[$name]; } }
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
    public function generateQuantities(CmdStanFit|array|string $fittedParams, mixed ...$options): CmdStanGQ
    {
        $o = self::options($options); $o['fitted_params'] = $fittedParams instanceof CmdStanFit ? $fittedParams->outputFiles() : (array)$fittedParams;
        return $this->run('generate_quantities', $o);
    }
    public function diagnose(mixed ...$options): CmdStanDiagnose { return $this->run('diagnose', self::options($options)); }
    public function logProb(mixed ...$options): CmdStanFit
    {
        CmdStan::requireVersion('2.31.0'); $o = self::deprecated(self::options($options), ['jacobian_adjustment']);
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
    private const DEPRECATED = ['cores' => 'parallel_chains', 'num_cores' => 'parallel_chains', 'num_chains' => 'chains', 'num_warmup' => 'iter_warmup', 'num_samples' => 'iter_sampling', 'max_depth' => 'max_treedepth', 'stepsize' => 'step_size', 'save_extra_diagnostics' => 'save_latent_dynamics', 'validate_csv' => 'diagnostics', 'jacobian_adjustment' => 'jacobian'];
    /** Maps CmdStanR's deprecated argument names with an E_USER_DEPRECATED notice; conflicting old/new values throw. */
    private static function deprecated(array $o, array $names): array
    {
        foreach ($names as $old) {
            if (!array_key_exists($old, $o)) { continue; }
            $new = self::DEPRECATED[$old]; $value = $o[$old]; unset($o[$old]);
            if ($value === null) { continue; }
            trigger_error("'".$old."' is deprecated. Please use '".$new."' instead.", E_USER_DEPRECATED);
            if ($old === 'validate_csv') {
                if (!is_bool($value)) { throw new \InvalidArgumentException('validate_csv must be boolean.'); }
                $value = $value ? ['divergences', 'treedepth', 'ebfmi'] : null;
                if (array_key_exists('diagnostics', $o) && $o['diagnostics'] !== $value) { throw new \InvalidArgumentException("Specify 'diagnostics' or 'validate_csv', not both."); }
            } elseif (isset($o[$new]) && $o[$new] !== $value) { throw new \InvalidArgumentException("Specify '".$new."' or the deprecated '".$old."', not both."); }
            $o[$new] = $value;
        }
        return $o;
    }
    private static function temporaryFile(string $suffix): string { return sys_get_temp_dir().'/cmdstanphp-'.bin2hex(random_bytes(8)).$suffix; }
    private function inputFile(mixed $input, string $dir, string $name): ?string
    {
        if ($input === null) { return null; }
        if (is_array($input)) {
            $file = $dir.'/'.$name.'.json';
            if (file_exists($file)) { throw new \RuntimeException('Input artifact already exists: '.$file); }
            return StanJson::write($input, $file);
        }
        if (!is_string($input) || !is_file($input)) { throw new \InvalidArgumentException('Expected a data array, StanData, a table with toColumns(), or an existing input file.'); }
        return realpath($input);
    }
    private function run(string $method, array $o, array $launcher = []): CmdStanFit
    {
        Options::validate($method, $o);
        $this->assertCurrent();
        $threads = $o['threads_per_chain'] ?? $o['threads'] ?? $o['num_threads'] ?? null;
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
        $basename = $o['output_basename'] ?? $this->modelName().'-'.bin2hex(random_bytes(6));
        if (!is_string($basename) || in_array($basename, ['.', '..'], true) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $basename)) { throw new \InvalidArgumentException('output_basename must be a simple filename containing letters, digits, dots, underscores and hyphens.'); }
        foreach ($ids as $id) {
            if (file_exists($dir.'/'.$basename.'-'.$id.'.csv')) { throw new \RuntimeException('Output already exists: '.$dir.'/'.$basename.'-'.$id.'.csv'); }
        }
        $o['data'] = StanData::normalize($o['data'] ?? null);
        $data = $this->inputFile($o['data'], $dir, $basename.'-data');
        if (($o['init'] ?? null) instanceof CmdStanFit) { $o['init'] = $this->initsFromFit($o['init'], $chains, is_array($o['seed'] ?? null) ? $o['seed'][0] : ($o['seed'] ?? null)); }
        $jobs = []; $files = []; $diagnosticFiles = []; $profileFiles = []; $initArrays = [];
        foreach ($ids as $i => $id) {
            $local = $o; $file = $dir.'/'.$basename.'-'.$id.'.csv';
            if (file_exists($file)) { throw new \RuntimeException('Output already exists: '.$file); }
            $files[] = $file;
            if ($method === 'generate_quantities') { $local['fitted_params'] = $this->inputFile($o['fitted_params'][$i], $dir, $basename.'-fitted-'.$id); }
            if ($method === 'laplace') { $local['mode'] = $this->inputFile($o['mode'], $dir, $basename.'-mode'); }
            foreach (['constrained_params', 'unconstrained_params'] as $key) { if (isset($o[$key])) { $local[$key] = $this->inputFile($o[$key], $dir, $basename.'-'.$key); } }
            if (isset($o['inv_metric'])) { $local['metric_file'] = StanJson::write(['inv_metric' => $o['inv_metric']], $dir.'/'.$basename.'-metric-'.$id.'.json'); }
            elseif (isset($o['metric_file'])) { $local['metric_file'] = $this->inputFile(is_array($o['metric_file']) ? ($o['metric_file'][$i] ?? throw new \InvalidArgumentException('One metric file required per chain.')) : $o['metric_file'], $dir, 'metric'); }
            $cmd = array_merge([$this->exeFile], Options::methodArgs($method, $local), ['id='.$id]);
            if ($data !== null) { array_push($cmd, 'data', 'file='.$data); }
            if (isset($o['seed'])) { array_push($cmd, 'random', 'seed='.(is_array($o['seed']) ? $o['seed'][$i] : $o['seed'])); }
            if (isset($o['init'])) {
                $init = $o['init'];
                if (is_callable($init)) { $init = $init($id); }
                elseif (is_array($init) && array_is_list($init) && $init !== []) { $init = $init[$i] ?? throw new \InvalidArgumentException('One init required per chain.'); }
                if (is_array($init)) { $initArrays[$i + 1] = $init; $init = $this->inputFile($init, $dir, $basename.'-init-'.$id); }
                elseif (is_string($init)) { $init = $this->inputFile($init, $dir, 'init'); }
                elseif ((!is_int($init) && !is_float($init)) || !is_finite((float)$init) || $init < 0) { throw new \InvalidArgumentException('init must be nonnegative, an input file, an array or callable.'); }
                $cmd[] = 'init='.Options::scalar($init);
            }
            if ($threads !== null) { $cmd[] = 'num_threads='.$threads; }
            if (isset($o['opencl_ids'])) { $cmd[] = 'opencl_platform_id='.$o['opencl_ids'][0]; $cmd[] = 'opencl_device_id='.$o['opencl_ids'][1]; }
            array_push($cmd, 'output', 'file='.$file);
            foreach (['refresh', 'sig_figs', 'save_cmdstan_config'] as $key) { if (isset($o[$key])) { $cmd[] = $key.'='.Options::scalar($o[$key]); } }
            if ($o['save_latent_dynamics'] ?? false) { $diagnosticFiles[] = $dir.'/'.$basename.'-'.$id.'-diagnostic.csv'; $cmd[] = 'diagnostic_file='.end($diagnosticFiles); }
            $profileFiles[] = $dir.'/'.$basename.'-'.$id.'-profile.csv'; $cmd[] = 'profile_file='.end($profileFiles);
            $jobs[] = ['command' => array_merge($launcher, $cmd), 'env' => $threads === null ? [] : ['STAN_NUM_THREADS' => (string)$threads]];
        }
        if ($initArrays !== [] && CmdStan::option('cmdstanr_warn_inits', true)) { $this->warnPartialInits($initArrays); }
        if (CmdStan::option('cmdstanr_verbose', false)) { foreach ($jobs as $job) { echo implode(' ', $job['command'])."\n"; } }
        $results = Process::pool($jobs, min($chains, $o['parallel_chains'] ?? CmdStan::option('mc.cores', 1)), $o['timeout'] ?? null);
        foreach ($results as $r) {
            if ($o['show_messages'] ?? true) { echo $r['stdout']; }
            if ($o['show_exceptions'] ?? ($o['show_messages'] ?? true)) { echo $r['stderr']; }
        }
        $class = CmdStanFit::classForMethod($method);
        $fit = new $class($files, $method, $results, $diagnosticFiles, $profileFiles, ['code' => $this->code, 'data_file' => $data, 'fitted_params_files' => $o['fitted_params'] ?? [], 'mode' => $o['mode'] ?? null, 'model_name' => $this->modelName(), 'stan_file' => $this->stanFile, 'stanc_options' => $this->nativeStancOptions(), 'ids' => $ids, 'owned' => true, 'data_owned' => is_array($o['data'] ?? null)]);
        $checks = array_key_exists('diagnostics', $o) ? ($o['diagnostics'] ?? []) : ['divergences', 'treedepth', 'ebfmi'];
        if ($method === 'sample' && !($o['fixed_param'] ?? false) && $checks !== []) { $fit->checkDiagnostics($checks); }
        return $fit;
    }
}
