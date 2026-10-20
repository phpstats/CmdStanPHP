<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * User-defined Stan functions compiled with stanc --standalone-functions and called through PHP FFI,
 * mirroring CmdStanR's expose_functions(). Arguments and results use PHP numbers and zero-indexed lists:
 * vectors and arrays are lists, matrices are lists of rows, complex numbers are [re, im] and tuples are lists.
 * _rng functions take an optional named seed argument (random when omitted) and reseed one shared RNG per call;
 * _lpdf/_lpmf functions return full densities (propto = false), as in CmdStanR.
 * Serialization keeps only the library path and signatures; the library is reloaded on first call.
 */
final class StanFunctions
{
    private const HEADER = <<<'C'
        void cmdstanphp_free(void* p);
        int cmdstanphp_call(int id, const double* in, int in_len, unsigned int seed, double** out, int* out_len, char** printed, char** err);
        C;
    private const TYPES = ['double' => 'real', 'int' => 'int', 'std::complex<double>' => 'complex'];
    private ?\FFI $ffi = null;
    /** @param array<string, array> $functions As returned by functions(). */
    private function __construct(private ?string $library, private array $functions) {}
    /** Compile (or reuse) the functions library for a Stan file; libraries are cached next to the file. */
    public static function compile(string $stanFile, array $stancOptions = [], ?string $outputDir = null, ?float $timeout = null): self
    {
        $hpp = NativeBuild::stanc($stanFile, array_merge(['--standalone-functions'], NativeBuild::stancArgs($stancOptions)), $timeout);
        $functions = self::parse($hpp);
        if ($functions === []) { throw new \RuntimeException('No standalone functions found to compile and expose.'); }
        $cases = '';
        foreach (array_values(array_filter($functions, fn($f) => $f['supported'])) as $f) {
            $cases .= '    case '.$f['id'].': return cmdstanphp::call(static_cast<'.$f['pointer'].'>(&::'.$f['name'].'), in, in_len, seed, out, out_len, printed, err);'."\n";
        }
        $cpp = "#include \"model.hpp\"\n#include \"".NativeBuild::resources()."/functions.cpp\"\n"
            ."extern \"C\" int cmdstanphp_call(int id, const double* in, int in_len, unsigned int seed, double** out, int* out_len, char** printed, char** err) {\n"
            ."  switch (id) {\n".$cases."  }\n  *err = cmdstanphp::copy_string(\"Unknown function.\");\n  return 1;\n}\n";
        $library = NativeBuild::library($stanFile, 'functions', $hpp, $cpp, ['functions.cpp'], $outputDir, $timeout);
        foreach ($functions as &$f) { unset($f['pointer']); }
        unset($f);
        return new self($library, $functions);
    }
    public function library(): ?string { return $this->library; }
    /**
     * @return array<string, array{return_type: string, arguments: array<string, string>, rng: bool, supported: bool, reason: ?string}>
     * Types use Stan names (real, vector, array[] matrix, tuple(real, int), ...); data qualifiers are not tracked.
     */
    public function functions(): array
    {
        return array_map(fn($f) => array_diff_key($f, ['id' => true, 'name' => true]), $this->functions);
    }
    public function __call(string $name, array $arguments): mixed { return $this->invoke($name, $arguments); }
    /** Call a function with positional or named arguments; _rng functions also accept seed: int. */
    public function call(string $function, mixed ...$arguments): mixed { return $this->invoke($function, $arguments); }
    private function invoke(string $name, array $arguments): mixed
    {
        $f = $this->functions[$name] ?? throw new \BadMethodCallException('Unknown Stan function: '.$name);
        if (!$f['supported']) { throw new \BadMethodCallException('Stan function '.$name.' cannot be called from PHP: '.$f['reason']); }
        $seed = null;
        if ($f['rng'] && array_key_exists('seed', $arguments)) { $seed = $arguments['seed']; unset($arguments['seed']); }
        $seed ??= random_int(1, 2147483647);
        if (!is_int($seed) || $seed < 0 || $seed > 4294967295) { throw new \InvalidArgumentException('seed must be an unsigned 32-bit integer.'); }
        $names = array_keys($f['arguments']); $values = [];
        foreach ($arguments as $key => $value) {
            if (is_int($key)) { $values[$key] = $value; continue; }
            $index = array_search($key, $names, true);
            if ($index === false) { throw new \InvalidArgumentException('Stan function '.$name.' has no argument named '.$key.'.'); }
            if (array_key_exists($index, $values)) { throw new \InvalidArgumentException('Argument '.$key.' of '.$name.' given twice.'); }
            $values[$index] = $value;
        }
        if (count($values) !== count($names) || ($values !== [] && max(array_keys($values)) !== count($names) - 1)) {
            throw new \InvalidArgumentException('Stan function '.$name.' expects '.count($names).' argument(s): '.$name.'('.implode(', ', array_map(fn($n, $t) => $t.' '.$n, $names, $f['arguments'])).').');
        }
        ksort($values);
        $buffer = [count($values)];
        foreach ($values as $i => $value) {
            try { self::encode($value, $buffer); }
            catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('Argument '.$names[$i].' of '.$name.': '.$e->getMessage(), 0, $e); }
        }
        $ffi = $this->ffi ??= NativeBuild::ffi(self::HEADER, $this->library ?? throw new \RuntimeException('No library.'));
        $out = $ffi->new('double*'); $length = $ffi->new('int'); $printed = $ffi->new('char*'); $err = $ffi->new('char*');
        $status = $ffi->cmdstanphp_call($f['id'], NativeBuild::doubles($ffi, $buffer), count($buffer), $seed, \FFI::addr($out), \FFI::addr($length), \FFI::addr($printed), \FFI::addr($err));
        $message = NativeBuild::takeString($ffi, $err); $text = NativeBuild::takeString($ffi, $printed);
        if ($text !== null) { echo $text; }
        if ($status === 2) { throw new \InvalidArgumentException('Invalid arguments for Stan function '.$name.': '.($message ?? 'unknown error')); }
        if ($status !== 0) { throw new \RuntimeException('Error in Stan function '.$name.': '.($message ?? 'unknown error')); }
        if (\FFI::isNull($out)) { return null; }
        try {
            $flat = [];
            for ($i = 0, $n = $length->cdata; $i < $n; ++$i) { $flat[] = $out[$i]; }
        } finally { $ffi->cmdstanphp_free($out); }
        $position = 0; return self::decode($flat, $position);
    }
    public function __serialize(): array { return ['library' => $this->library, 'functions' => $this->functions]; }
    public function __unserialize(array $data): void { $this->library = $data['library']; $this->functions = $data['functions']; }
    private static function encode(mixed $value, array &$out): void
    {
        if (is_bool($value)) { $value = (int)$value; }
        if (is_int($value)) { array_push($out, 1, (float)$value); }
        elseif (is_float($value)) { array_push($out, 0, $value); }
        elseif (is_array($value) && array_is_list($value)) {
            array_push($out, 2, count($value));
            foreach ($value as $item) { self::encode($item, $out); }
        } else { throw new \InvalidArgumentException('expected a number or a zero-indexed list.'); }
    }
    private static function decode(array $flat, int &$position): mixed
    {
        $tag = $flat[$position++];
        if ($tag === 0.0) { return $flat[$position++]; }
        if ($tag === 1.0) { return (int)$flat[$position++]; }
        $out = [];
        for ($i = 0, $n = (int)$flat[$position++]; $i < $n; ++$i) { $out[] = self::decode($flat, $position); }
        return $out;
    }
    /** Parse the global "// [[stan::function]]" wrappers that stanc emits for --standalone-functions. */
    private static function parse(string $hpp): array
    {
        $parts = explode('// [[stan::function]]', $hpp); array_shift($parts);
        $found = [];
        foreach ($parts as $part) {
            $open = strpos($part, '(');
            if ($open === false || !preg_match('/^\s*(.*?)\s*\b([A-Za-z_][A-Za-z0-9_]*)\s*$/s', substr($part, 0, $open), $m)) { throw new \RuntimeException('Cannot parse stanc function wrapper.'); }
            [$return, $name] = [preg_replace('/\s+/', '', $m[1]), $m[2]];
            $depth = 0; $close = null;
            for ($i = $open; $i < strlen($part); ++$i) {
                if ($part[$i] === '(') { ++$depth; } elseif ($part[$i] === ')' && --$depth === 0) { $close = $i; break; }
            }
            if ($close === null) { throw new \RuntimeException('Cannot parse stanc function wrapper '.$name.'.'); }
            $arguments = []; $cppTypes = []; $rng = false; $reason = null;
            foreach (self::split(substr($part, $open + 1, $close - $open - 1)) as $param) {
                $param = trim((string)preg_replace('/=\s*nullptr\s*$/', '', $param));
                if (!preg_match('/^(.*?)\s*\b([A-Za-z_][A-Za-z0-9_]*)$/s', $param, $p)) { throw new \RuntimeException('Cannot parse argument of '.$name.'.'); }
                $cppTypes[] = preg_replace('/\s+/', ' ', $p[1]);
                if ($p[2] === 'pstream__') { continue; }
                if ($p[2] === 'base_rng__') { $rng = true; continue; }
                if (in_array($p[2], ['lp__', 'lp_accum__'], true)) { $reason ??= '_lp functions need the log density accumulator and are not supported.'; continue; }
                $type = self::stanType(preg_replace('/\s+|const|&/', '', $p[1]));
                if ($type === null) { $reason ??= 'argument '.$p[2].' has unsupported C++ type '.trim($p[1]).'.'; }
                $arguments[$p[2]] = $type ?? trim($p[1]);
            }
            $returnType = $return === 'void' ? 'void' : self::stanType($return);
            if ($returnType === null) { $reason ??= 'unsupported return type '.$return.'.'; }
            $found[] = ['name' => $name, 'return_type' => $returnType ?? $return, 'arguments' => $arguments, 'rng' => $rng, 'supported' => $reason === null, 'reason' => $reason, 'pointer' => $return.'(*)('.implode(', ', $cppTypes).')'];
        }
        $counts = array_count_values(array_column($found, 'name')); $functions = [];
        foreach ($found as $f) {
            if ($counts[$f['name']] > 1) { $f['supported'] = false; $f['reason'] = 'overloaded functions cannot be exposed.'; }
            $functions[$f['name']] ??= $f;
        }
        $id = 0;
        foreach ($functions as &$f) { $f['id'] = $f['supported'] ? $id++ : -1; }
        unset($f);
        return $functions;
    }
    private static function split(string $list): array
    {
        $out = []; $depth = 0; $current = '';
        foreach (str_split($list) as $char) {
            if ($char === '<') { ++$depth; } elseif ($char === '>') { --$depth; }
            if ($char === ',' && $depth === 0) { $out[] = $current; $current = ''; } else { $current .= $char; }
        }
        if (trim($current) !== '') { $out[] = $current; }
        return $out;
    }
    /** Map a whitespace-free C++ type to its Stan spelling, or null if unsupported. */
    private static function stanType(string $type): ?string
    {
        if (isset(self::TYPES[$type])) { return self::TYPES[$type]; }
        if (preg_match('/^Eigen::Matrix<(double|std::complex<double>),(-1|1),(-1|1)>$/D', $type, $m)) {
            $base = match ($m[2].','.$m[3]) { '-1,1' => 'vector', '1,-1' => 'row_vector', '-1,-1' => 'matrix', default => null };
            return $base === null ? null : ($m[1] === 'double' ? $base : 'complex_'.$base);
        }
        if (preg_match('/^std::vector<(.*)>$/Ds', $type, $m)) {
            $inner = self::stanType($m[1]);
            if ($inner === null) { return null; }
            return preg_match('/^array\[(,*)\] (.*)$/Ds', $inner, $a) ? 'array[,'.$a[1].'] '.$a[2] : 'array[] '.$inner;
        }
        if (preg_match('/^std::tuple<(.*)>$/Ds', $type, $m)) {
            $items = array_map(self::stanType(...), self::split($m[1]));
            return in_array(null, $items, true) ? null : 'tuple('.implode(', ', $items).')';
        }
        return null;
    }
}
