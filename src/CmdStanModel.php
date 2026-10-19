<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanModel
{
    private string $stanFile;
    private string $exeFile;
    private ?StanFunctions $functions = null;
    public function __construct(string $stan_file, ?string $exe_file = null)
    {
        $real = realpath($stan_file);
        if ($real === false || !is_file($real) || pathinfo($real, PATHINFO_EXTENSION) !== 'stan') { throw new \InvalidArgumentException('Expected an existing .stan file.'); }
        $this->stanFile = $real;
        $this->exeFile = $exe_file === null ? substr($real, 0, -5).CmdStan::suffix() : (realpath($exe_file) ?: $exe_file);
    }
    public function stan_file(): string { return $this->stanFile; }
    public function exe_file(): string { return $this->exeFile; }
    public function hpp_file(): string { return substr($this->stanFile, 0, -5).'.hpp'; }
    public function code(): string { return (string)file_get_contents($this->stanFile); }
    public function has_stan_file(): bool { return is_file($this->stanFile); }
    public function model_name(): string { return pathinfo($this->stanFile, PATHINFO_FILENAME); }
    public function cpp_options(): array
    {
        $receipt = $this->exeFile.'.cmdstanphp.json';
        return is_file($receipt) ? (json_decode(file_get_contents($receipt), true)['cpp_options'] ?? []) : [];
    }
    public function include_paths(): array { return (array)($this->stancOptions()['include-paths'] ?? []); }
    private function stancOptions(): array
    {
        $receipt = $this->exeFile.'.cmdstanphp.json';
        return is_file($receipt) ? (json_decode(file_get_contents($receipt), true)['stanc_options'] ?? []) : [];
    }
    /** CmdStanR's expose_functions(): compiles user-defined Stan functions into a library called through PHP FFI. */
    public function expose_functions(?float $timeout = null): StanFunctions { return $this->functions ??= StanFunctions::compile($this->stanFile, $this->stancOptions(), timeout: $timeout); }
    public function functions(): StanFunctions { return $this->functions ?? throw new \RuntimeException('Functions have not been exposed; call expose_functions() first.'); }
    /** Line numbers default to the cmdstanr_print_line_numbers option and are right-aligned like CmdStanR's. */
    public function print(?bool $line_numbers = null): void
    {
        $lines = explode("\n", rtrim($this->code())); $width = strlen((string)count($lines));
        foreach ($lines as $i => $line) { echo (($line_numbers ?? CmdStan::option('cmdstanr_print_line_numbers', false)) ? str_pad((string)($i + 1), $width, ' ', STR_PAD_LEFT).': ' : '').$line."\n"; }
    }
    /** force_recompile defaults to the cmdstanr_force_recompile option; cmdstanr_verbose echoes the make command and output. Deprecated: threads, compile_hessian_method. */
    public function compile(?bool $force_recompile = null, array $stanc_options = [], array $cpp_options = [], int $cores = 1, ?float $timeout = null, ?bool $threads = null, ?bool $compile_hessian_method = null): self
    {
        if ($cores < 1) { throw new \InvalidArgumentException('cores must be positive.'); }
        $force_recompile ??= CmdStan::option('cmdstanr_force_recompile', false);
        if ($threads) { trigger_error("'threads' is deprecated. Please use 'cpp_options: ['STAN_THREADS' => true]' instead.", E_USER_DEPRECATED); $cpp_options['STAN_THREADS'] = true; }
        if ($compile_hessian_method) { trigger_error("'compile_hessian_method' is deprecated. The hessian method is compiled with all models.", E_USER_DEPRECATED); }
        $stancArgs = $this->stancArgs($stanc_options);
        $makeOptions = [];
        foreach ($cpp_options as $name => $value) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', (string)$name)) { throw new \InvalidArgumentException('Invalid make option.'); }
            $value = $value === false ? '' : Options::scalar($value);
            if (preg_match('/[\r\n$`#;&|<>()"\'\\\\]/', $value)) { throw new \InvalidArgumentException('Unsafe make option value.'); }
            $makeOptions[] = strtoupper($name).'='.$value;
        }
        $fingerprint = hash('sha256', json_encode([2, CmdStan::path(), CmdStan::version(), $stanc_options, $cpp_options], JSON_THROW_ON_ERROR));
        $receipt = $this->exeFile.'.cmdstanphp.json';
        $previous = is_file($receipt) ? json_decode(file_get_contents($receipt), true) : null;
        $changedOptions = ($previous['fingerprint'] ?? null) !== $fingerprint;
        if (!$force_recompile && !$changedOptions && is_file($this->exeFile) && filemtime($this->exeFile) >= filemtime($this->stanFile)) { return $this; }
        if ($this->exeFile !== substr($this->stanFile, 0, -5).CmdStan::suffix()) { throw new \LogicException('Compilation requires the executable to have the source basename.'); }
        $cmd = ['make', '-C', CmdStan::path(), '-j'.$cores];
        if ($force_recompile || $changedOptions) {
            foreach ([$this->exeFile, $this->hpp_file(), substr($this->stanFile, 0, -5).'.o'] as $artifact) {
                if (is_file($artifact) && !unlink($artifact)) { throw new \RuntimeException('Cannot remove stale artifact: '.$artifact); }
            }
        }
        $cmd[] = $this->exeFile;
        $cmd[] = $this->hpp_file();
        if ($stanc_options !== []) { $cmd[] = 'STANCFLAGS='.implode(' ', $stancArgs); }
        array_push($cmd, ...$makeOptions);
        $verbose = CmdStan::option('cmdstanr_verbose', false);
        if ($verbose) { echo implode(' ', $cmd)."\n"; }
        $result = Process::run($cmd, timeout: $timeout);
        if ($verbose) { echo $result['stdout'].$result['stderr']; }
        if (!is_file($this->exeFile)) { throw new \RuntimeException('Compilation did not produce an executable.'); }
        if (file_put_contents($receipt, json_encode(['fingerprint' => $fingerprint, 'cpp_options' => $cpp_options, 'stanc_options' => $stanc_options], JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new \RuntimeException('Cannot save compilation receipt.'); }
        return $this;
    }
    private function stancArgs(array $options): array
    {
        $args = [];
        foreach ($options as $name => $value) {
            if (!preg_match('/^[a-z][a-z0-9_-]*$/D', (string)$name)) { throw new \InvalidArgumentException('Invalid stanc option.'); }
            if ($value === false || $value === null) { continue; }
            if ($value === true) { $args[] = '--'.$name; }
            else {
                $value = is_array($value) ? implode(',', $value) : Options::scalar($value);
                if (preg_match('/[\s$`#;&|<>()"\'\\\\]/', $value)) { throw new \InvalidArgumentException('Unsafe stanc option value; use a path without whitespace.'); }
                $args[] = '--'.$name.'='.$value;
            }
        }
        return $args;
    }
    public function check_syntax(array $stanc_options = []): bool
    {
        $hpp = self::temporaryFile('.hpp');
        try { Process::run(array_merge([CmdStan::path().'/bin/stanc'.CmdStan::suffix(), '--o='.$hpp], $this->stancArgs($stanc_options), [$this->stanFile])); }
        finally { if (is_file($hpp)) { unlink($hpp); } }
        return true;
    }
    public function format(bool $overwrite_file = false, array $stanc_options = []): string
    {
        $result = Process::run(array_merge([CmdStan::path().'/bin/stanc'.CmdStan::suffix(), '--auto-format'], $this->stancArgs($stanc_options), [$this->stanFile]));
        if ($overwrite_file && file_put_contents($this->stanFile, $result['stdout'], LOCK_EX) === false) { throw new \RuntimeException('Cannot overwrite source.'); }
        return $result['stdout'];
    }
    public function variables(): array
    {
        $result = Process::run([CmdStan::path().'/bin/stanc'.CmdStan::suffix(), '--info', $this->stanFile]);
        return json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    }
    public function save_hpp_file(string $dir): string
    {
        if (!is_dir($dir) || !is_file($this->hpp_file())) { throw new \InvalidArgumentException('Header and destination directory must exist.'); }
        $target = rtrim($dir, '/\\').'/'.basename($this->hpp_file());
        if (!copy($this->hpp_file(), $target)) { throw new \RuntimeException('Cannot copy header.'); }
        return $target;
    }
    /**
     * Use named arguments (sample(chains: 4)) or a single options array. Every method's data may be an array, a JSON
     * file, a StanData, or a table such as a phibble Frame (any object with toModelData() or toColumns(); see StanData).
     */
    public function sample(mixed ...$options): CmdStanMCMC { return $this->run('sample', self::deprecated(self::options($options), ['cores', 'num_cores', 'num_chains', 'num_warmup', 'num_samples', 'max_depth', 'stepsize', 'save_extra_diagnostics', 'validate_csv'])); }
    public function sample_mpi(string $mpi_cmd = 'mpiexec', array $mpi_args = [], mixed ...$options): CmdStanMCMC
    {
        $o = self::deprecated(self::options($options), ['validate_csv']); $o['chains'] ??= 1; $o['parallel_chains'] = 1;
        if ($mpi_cmd === '') { throw new \InvalidArgumentException('mpi_cmd must identify a launcher.'); }
        $launcher = [$mpi_cmd];
        foreach ($mpi_args as $name => $value) {
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
            $o['mode'] = $o['mode']->output_files()[0];
        }
        unset($o['opt_args']);
        return $this->run('laplace', $o);
    }
    public function generate_quantities(CmdStanFit|array|string $fitted_params, mixed ...$options): CmdStanGQ
    {
        $o = self::options($options); $o['fitted_params'] = $fitted_params instanceof CmdStanFit ? $fitted_params->output_files() : (array)$fitted_params;
        return $this->run('generate_quantities', $o);
    }
    public function diagnose(mixed ...$options): CmdStanDiagnose { return $this->run('diagnose', self::options($options)); }
    public function log_prob(mixed ...$options): CmdStanFit
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
        $result = Inits::fromFit($fit, $chains, $this->variables()['parameters'] ?? null, $seed, false);
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
        if (!is_executable($this->exeFile)) { throw new \RuntimeException('Model is not compiled: '.$this->exeFile); }
        $chains = $method === 'sample' ? ($o['chains'] ?? 4) : ($method === 'generate_quantities' ? count($o['fitted_params']) : 1);
        if ($o['fixed_param'] ?? false) { $chains = 1; $o['iter_warmup'] = 0; }
        if ($chains < 1) { throw new \InvalidArgumentException('At least one input chain is required.'); }
        $ids = $o['chain_ids'] ?? range(1, $chains);
        if (!is_array($ids) || !array_is_list($ids) || count($ids) !== $chains || count(array_unique($ids)) !== $chains || count(array_filter($ids, fn($x) => is_int($x) && $x > 0)) !== $chains) { throw new \InvalidArgumentException('chain_ids must be unique positive integers, one per chain.'); }
        if (is_array($o['seed'] ?? null) && count($o['seed']) !== $chains) { throw new \InvalidArgumentException('Provide one seed per chain.'); }
        $dir = $o['output_dir'] ?? CmdStan::option('cmdstanr_output_dir') ?? self::temporaryFile('');
        if (!is_string($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true))) { throw new \RuntimeException('Cannot create output directory.'); }
        $dir = realpath($dir);
        $basename = $o['output_basename'] ?? pathinfo($this->stanFile, PATHINFO_FILENAME).'-'.bin2hex(random_bytes(6));
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
            $threads = $o['threads_per_chain'] ?? $o['threads'] ?? $o['num_threads'] ?? null;
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
        $fit = new $class($files, $method, $results, $diagnosticFiles, $profileFiles, ['code' => $this->code(), 'data_file' => $data, 'fitted_params_files' => $o['fitted_params'] ?? [], 'mode' => $o['mode'] ?? null, 'model_name' => $this->model_name(), 'stan_file' => $this->stanFile, 'stanc_options' => $this->stancOptions(), 'ids' => $ids, 'owned' => true, 'data_owned' => is_array($o['data'] ?? null)]);
        $checks = array_key_exists('diagnostics', $o) ? ($o['diagnostics'] ?? []) : ['divergences', 'treedepth', 'ebfmi'];
        if ($method === 'sample' && !($o['fixed_param'] ?? false) && $checks !== []) { $fit->check_diagnostics($checks); }
        return $fit;
    }
}
