<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * In-process log density, gradient, Hessian and constrain/unconstrain methods, mirroring CmdStanR's
 * init_model_methods(). Densities drop constants (propto), as in CmdStanR. Named variables are nested
 * zero-indexed lists ([i][j], matrices as rows, complex as [re, im]); unconstrained vectors are flat lists.
 * Serialization keeps the library path, data and seed and reconnects lazily, which resets the RNG.
 */
final class ModelMethods
{
    private const HEADER = <<<'C'
        typedef struct cmdstanphp_model cmdstanphp_model;
        void cmdstanphp_free(void* p);
        cmdstanphp_model* cmdstanphp_model_new(const char* data_file, unsigned int seed, char** err);
        void cmdstanphp_model_free(cmdstanphp_model* m);
        char* cmdstanphp_model_info(cmdstanphp_model* m, char** err);
        int cmdstanphp_log_density(cmdstanphp_model* m, const double* theta, int jacobian, double* lp, char** err);
        int cmdstanphp_log_density_gradient(cmdstanphp_model* m, const double* theta, int jacobian, double* lp, double* grad, char** err);
        int cmdstanphp_log_density_hessian(cmdstanphp_model* m, const double* theta, int jacobian, double* lp, double* grad, double* hess, char** err);
        int cmdstanphp_param_constrain(cmdstanphp_model* m, const double* theta, int tparams, int gqs, double* out, int capacity, char** err);
        int cmdstanphp_param_unconstrain(cmdstanphp_model* m, const double* constrained, int size, double* out, char** err);
        void cmdstanphp_seed(cmdstanphp_model* m, unsigned int seed);
        C;
    private ?\FFI $ffi = null;
    private ?\FFI\CData $handle = null;
    private array $info = [];
    private array|string|null $data;
    /** @param array|object|string|null $data Stan data array, StanData, table (see StanData), or JSON data file. */
    public function __construct(private string $library, array|object|string|null $data = null, private int $seed = 1)
    {
        $this->data = StanData::normalize($data);
        if ($seed < 0 || $seed > 4294967295) { throw new \InvalidArgumentException('seed must be an unsigned 32-bit integer.'); }
        if (is_string($data) && !is_file($data)) { throw new \InvalidArgumentException('Data file not found: '.$data); }
        $this->library = realpath($library) ?: throw new \InvalidArgumentException('Native library not found: '.$library);
        $this->connect();
    }
    /** Build (or reuse) the model-methods library for a Stan file and return its path. */
    public static function build(string $stanFile, array $stancOptions = [], ?string $outputDir = null, ?float $timeout = null): string
    {
        $hpp = NativeBuild::stanc($stanFile, NativeBuild::stancArgs($stancOptions), $timeout);
        return NativeBuild::library($stanFile, 'model_methods', $hpp, "#include \"model.hpp\"\n#include \"".NativeBuild::resources()."/model_methods.cpp\"\n", ['model_methods.cpp'], $outputDir, $timeout);
    }
    public static function compile(string $stanFile, array|object|string|null $data = null, int $seed = 1, array $stancOptions = [], ?string $outputDir = null, ?float $timeout = null): self
    {
        return new self(self::build($stanFile, $stancOptions, $outputDir, $timeout), $data, $seed);
    }
    public function library(): string { return $this->library; }
    public function seed(): int { return $this->seed; }
    public function numUnconstrained(): int { $this->model(); return $this->info['num_unconstrained']; }
    /** Unconstrained parameter names in draws notation (e.g. "s[1]", "S[3]"). */
    public function unconstrainedParamNames(): array { $this->model(); return array_map(Csv::variableName(...), $this->info['unconstrained']); }
    /** Flat constrained names in draws notation, in write order (column-major, matching CmdStan CSV columns). */
    public function constrainedParamNames(bool $transformedParameters = true, bool $generatedQuantities = true): array
    {
        return array_map(Csv::variableName(...), $this->select('constrained', $transformedParameters, $generatedQuantities));
    }
    /** Variable name => dimensions ([] for scalars; complex values have a trailing 2; tuple elements appear as name.k). */
    public function variableSkeleton(bool $transformedParameters = true, bool $generatedQuantities = true): array
    {
        $info = $this->info(); $count = $info['blocks'][0] + ($transformedParameters ? $info['blocks'][1] : 0);
        $out = [];
        foreach ($info['names'] as $i => $name) {
            if ($i < $count || ($generatedQuantities && $i >= $info['blocks'][0] + $info['blocks'][1])) { $out[$name] = $info['dims'][$i]; }
        }
        return $out;
    }
    public function logProb(array $unconstrainedVariables, bool $jacobian = true): float
    {
        $theta = $this->unconstrained($unconstrainedVariables); $lp = $this->ffi->new('double'); $err = $this->ffi->new('char*');
        $this->check($this->ffi->cmdstanphp_log_density($this->handle, $theta, (int)$jacobian, \FFI::addr($lp), \FFI::addr($err)), $err);
        return $lp->cdata;
    }
    /** @return array{log_prob: float, gradient: list<float>} */
    public function gradLogProb(array $unconstrainedVariables, bool $jacobian = true): array
    {
        $theta = $this->unconstrained($unconstrainedVariables); $n = $this->info['num_unconstrained'];
        $lp = $this->ffi->new('double'); $grad = $this->ffi->new('double['.max(1, $n).']'); $err = $this->ffi->new('char*');
        $this->check($this->ffi->cmdstanphp_log_density_gradient($this->handle, $theta, (int)$jacobian, \FFI::addr($lp), $grad, \FFI::addr($err)), $err);
        return ['log_prob' => $lp->cdata, 'gradient' => self::read($grad, $n)];
    }
    /** Finite differences of autodiff gradients, as in CmdStanR. @return array{log_prob: float, grad_log_prob: list<float>, hessian: list<list<float>>} */
    public function hessian(array $unconstrainedVariables, bool $jacobian = true): array
    {
        $theta = $this->unconstrained($unconstrainedVariables); $n = $this->info['num_unconstrained'];
        $lp = $this->ffi->new('double'); $grad = $this->ffi->new('double['.max(1, $n).']'); $hess = $this->ffi->new('double['.max(1, $n * $n).']'); $err = $this->ffi->new('char*');
        $this->check($this->ffi->cmdstanphp_log_density_hessian($this->handle, $theta, (int)$jacobian, \FFI::addr($lp), $grad, $hess, \FFI::addr($err)), $err);
        $flat = self::read($hess, $n * $n); $rows = [];
        for ($i = 0; $i < $n; ++$i) { for ($j = 0; $j < $n; ++$j) { $rows[$i][$j] = $flat[$i + $j * $n]; } }
        return ['log_prob' => $lp->cdata, 'grad_log_prob' => self::read($grad, $n), 'hessian' => $rows];
    }
    /**
     * @param array<string, mixed> $variables Parameter name => value, structured as constrainVariables() returns it
     *     (tuples as lists of their elements, complex values as [re, im]); tuple elements may also be given as name.k
     *     entries. Extra entries are ignored.
     */
    public function unconstrainVariables(array $variables): array
    {
        $skeleton = array_filter($this->variableSkeleton(false, false), fn($dims) => array_product($dims) !== 0);
        $legacy = array_diff_key($skeleton, $variables) === [];
        $missing = [];
        foreach (array_keys($skeleton) as $name) { $base = explode('.', $name, 2)[0]; if (!array_key_exists($legacy ? $name : $base, $variables)) { $missing[$base] = $base; } }
        if ($missing !== []) { throw new \InvalidArgumentException('Model parameter(s): '.implode(',', $missing).' not provided!'); }
        if ($legacy) { foreach ($skeleton as $name => $dims) { if (self::shape($variables[$name], $name) !== $dims) { throw new \InvalidArgumentException('Parameter '.$name.' must have dimensions ['.implode(',', $dims).'].'); } } }
        $flat = [];
        foreach ($this->select($legacy ? 'paths' : 'constrained', false, false) as $entry) {
            if ($legacy) { [$name, $indices] = $entry; $value = $variables[$name]; foreach ($indices as $i) { $value = $value[$i]; } }
            else { $value = self::walk($variables, (string)$entry); }
            $flat[] = $value;
        }
        return $this->unconstrain($flat);
    }
    /** The value a CmdStan flat name ("at.1:2.3", "z.imag") points to in structured variables: at[0][1][2], z[1]. */
    private static function walk(array $variables, string $name): float|int
    {
        $length = strcspn($name, '.:'); $value = $variables[substr($name, 0, $length)];
        preg_match_all('/([.:])([^.:]+)/', substr($name, $length), $matches, PREG_SET_ORDER);
        foreach ($matches as [, , $token]) {
            $i = match ($token) { 'real' => 0, 'imag' => 1, default => (int)$token - 1 };
            if (!is_array($value) || !array_key_exists($i, $value)) { throw new \InvalidArgumentException('Parameter '.substr($name, 0, $length).' is missing elements.'); }
            $value = $value[$i];
        }
        if (!is_int($value) && !is_float($value)) { throw new \InvalidArgumentException('Parameter '.substr($name, 0, $length).' has a non-numeric value.'); }
        return $value;
    }
    /** @param list<array<string, float>> $draws Rows keyed by draws names such as "theta" or "x[1,2]". @return list<list<float>> */
    public function unconstrainDraws(array $draws): array
    {
        $names = $this->constrainedParamNames(false, false); $out = [];
        foreach ($draws as $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('Each draw must be an associative row.'); }
            $missing = array_diff($names, array_keys($row));
            if ($missing !== []) { throw new \InvalidArgumentException('Draws are missing parameter column(s): '.implode(',', $missing)); }
            $out[] = $this->unconstrain(array_map(fn($name) => $row[$name], $names));
        }
        return $out;
    }
    /** @return array<string, mixed> Variable name => number or nested list, structured as Csv::unflatten() describes. */
    public function constrainVariables(array $unconstrainedVariables, bool $transformedParameters = true, bool $generatedQuantities = true): array
    {
        $theta = $this->unconstrained($unconstrainedVariables); $size = $this->size($transformedParameters, $generatedQuantities);
        $values = $this->ffi->new('double['.max(1, $size).']'); $err = $this->ffi->new('char*');
        $this->check($this->ffi->cmdstanphp_param_constrain($this->handle, $theta, (int)$transformedParameters, (int)$generatedQuantities, $values, $size, \FFI::addr($err)), $err);
        $flat = [];
        foreach ($this->constrainedParamNames($transformedParameters, $generatedQuantities) as $k => $name) { $flat[$name] = $values[$k]; }
        $values = Csv::unflatten($flat); $out = [];
        // Zero-size variables have no columns; they come back as empty lists, in declaration order.
        foreach (array_keys($this->variableSkeleton($transformedParameters, $generatedQuantities)) as $key) { $base = explode('.', (string)$key, 2)[0]; $out[$base] = $values[$base] ?? []; }
        return $out;
    }
    /** Reseed the RNG used for generated quantities by constrainVariables(). */
    public function seedRng(int $seed): void
    {
        if ($seed < 0 || $seed > 4294967295) { throw new \InvalidArgumentException('seed must be an unsigned 32-bit integer.'); }
        $this->ffi->cmdstanphp_seed($this->model(), $seed);
    }
    public function __serialize(): array { return ['library' => $this->library, 'data' => $this->data, 'seed' => $this->seed]; }
    public function __unserialize(array $data): void { $this->library = $data['library']; $this->data = $data['data']; $this->seed = $data['seed']; }
    public function __clone() { $this->handle = null; $this->ffi = null; }
    public function __destruct() { if ($this->handle !== null && $this->ffi !== null) { $this->ffi->cmdstanphp_model_free($this->handle); } }
    private function connect(): void
    {
        $this->ffi = NativeBuild::ffi(self::HEADER, $this->library);
        $file = is_array($this->data) ? StanJson::write($this->data, sys_get_temp_dir().'/cmdstanphp-data-'.bin2hex(random_bytes(8)).'.json') : $this->data;
        try {
            $err = $this->ffi->new('char*');
            $handle = $this->ffi->cmdstanphp_model_new($file ?? '', $this->seed, \FFI::addr($err));
            $message = NativeBuild::takeString($this->ffi, $err);
            if ($handle === null) { throw new \RuntimeException('Cannot construct model: '.($message ?? 'unknown error')); }
            $this->handle = $handle;
        } finally { if (is_array($this->data) && is_file($file)) { unlink($file); } }
        if ($this->info === []) {
            $err = $this->ffi->new('char*');
            $json = NativeBuild::takeString($this->ffi, $this->ffi->cmdstanphp_model_info($this->handle, \FFI::addr($err)));
            $this->check($json === null ? 1 : 0, $err);
            $info = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $info['paths'] = array_map(self::path(...), $info['constrained']);
            $this->info = $info;
        }
    }
    private function model(): \FFI\CData { if ($this->handle === null) { $this->connect(); } return $this->handle; }
    private function info(): array { $this->model(); return $this->info; }
    private function size(bool $transformedParameters, bool $generatedQuantities): int { return count($this->select('constrained', $transformedParameters, $generatedQuantities)); }
    /** Entries of a flat per-value list ('constrained' or 'paths') for the requested blocks, in write_array order. */
    private function select(string $key, bool $transformedParameters, bool $generatedQuantities): array
    {
        $info = $this->info(); $sizes = [0, 0, 0];
        foreach ($info['dims'] as $i => $dims) { $sizes[$i < $info['blocks'][0] ? 0 : ($i < $info['blocks'][0] + $info['blocks'][1] ? 1 : 2)] += array_product($dims); }
        return array_merge(array_slice($info[$key], 0, $sizes[0]), $transformedParameters ? array_slice($info[$key], $sizes[0], $sizes[1]) : [], $generatedQuantities ? array_slice($info[$key], $sizes[0] + $sizes[1], $sizes[2]) : []);
    }
    private function unconstrained(array $values): \FFI\CData
    {
        $n = $this->numUnconstrained();
        if (!array_is_list($values)) { throw new \InvalidArgumentException('Unconstrained variables must be a list of numbers.'); }
        if (count($values) !== $n) { throw new \InvalidArgumentException('Model has '.$n.' unconstrained parameter(s), but '.count($values).' were provided!'); }
        foreach ($values as $value) { if (!is_int($value) && !is_float($value)) { throw new \InvalidArgumentException('Unconstrained variables must be numbers.'); } }
        return NativeBuild::doubles($this->ffi, $values);
    }
    private function unconstrain(array $flat): array
    {
        foreach ($flat as $value) { if (!is_int($value) && !is_float($value)) { throw new \InvalidArgumentException('Parameter values must be numbers.'); } }
        $n = $this->numUnconstrained(); $out = $this->ffi->new('double['.max(1, $n).']'); $err = $this->ffi->new('char*');
        $this->check($this->ffi->cmdstanphp_param_unconstrain($this->handle, NativeBuild::doubles($this->ffi, $flat), count($flat), $out, \FFI::addr($err)), $err);
        return self::read($out, $n);
    }
    private function check(int $status, \FFI\CData $err): void
    {
        $message = NativeBuild::takeString($this->ffi, $err);
        if ($status !== 0) { throw new \RuntimeException($message ?? 'Stan model call failed.'); }
    }
    private static function read(\FFI\CData $buffer, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) { $out[] = $buffer[$i]; }
        return $out;
    }
    /** Map a CmdStan flat name ("a.2.1", "z.imag", "t:2.1", "at.1:2.3") to [variableSkeleton key, zero-based indices]. */
    private static function path(string $name): array
    {
        $length = strcspn($name, '.:'); $key = substr($name, 0, $length); $indices = [];
        preg_match_all('/([.:])([^.:]+)/', substr($name, $length), $matches, PREG_SET_ORDER);
        foreach ($matches as [, $separator, $token]) {
            if ($separator === ':') { $key .= '.'.$token; }
            else { $indices[] = match ($token) { 'real' => 0, 'imag' => 1, default => (int)$token - 1 }; }
        }
        return [$key, $indices];
    }
    private static function shape(mixed $value, string $name): array
    {
        if (is_int($value) || is_float($value)) { return []; }
        if (!is_array($value) || !array_is_list($value)) { throw new \InvalidArgumentException('Parameter '.$name.' must be a number or a zero-indexed list.'); }
        $shapes = array_map(fn($v) => self::shape($v, $name), $value);
        if (count(array_unique(array_map('serialize', $shapes))) > 1) { throw new \InvalidArgumentException('Parameter '.$name.' must be rectangular.'); }
        return array_merge([count($value)], $shapes[0] ?? []);
    }
}
