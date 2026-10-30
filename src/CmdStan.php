<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStan
{
    /** The oldest CmdStan this package supports, as CmdStanR 1.0. */
    public const MIN_VERSION = '2.37.0';
    private static ?string $path = null;
    public static function setPath(string $path): void
    {
        $real = realpath($path);
        if ($real === false || !is_file($real.'/makefile') || !is_file($real.'/bin/stanc'.self::suffix())) {
            throw new \InvalidArgumentException('Not a CmdStan installation: '.$path);
        }
        $version = self::makefileVersion($real);
        if (!self::isSupported($version)) { throw new \InvalidArgumentException('CmdStan v'.$version.' is no longer supported. CmdStanPHP requires CmdStan v'.self::MIN_VERSION.' or newer.'); }
        self::$path = $real;
    }
    /** True for CmdStan versions this package supports; a release-candidate suffix is ignored. */
    public static function isSupported(string $version): bool { return version_compare((string)preg_replace('/^cmdstan-|-rc\d+$/', '', $version), self::MIN_VERSION, '>='); }
    public static function path(): string
    {
        if (self::$path !== null) { return self::$path; }
        $env = getenv('CMDSTAN');
        if ($env !== false && $env !== '') { self::setPath($env); return self::$path; }
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        $paths = glob($home.'/.cmdstan/cmdstan-*', GLOB_ONLYDIR) ?: [];
        usort($paths, fn($a, $b) => version_compare(basename($b), basename($a)));
        foreach ($paths as $path) {
            try { self::setPath($path); return self::$path; } catch (\InvalidArgumentException) {}
        }
        throw new \RuntimeException('CmdStan not found. Set CMDSTAN or call set_cmdstan_path().');
    }
    public static function suffix(): string { return PHP_OS_FAMILY === 'Windows' ? '.exe' : ''; }
    public static function version(): string { return self::makefileVersion(self::path()); }
    private static function makefileVersion(string $path): string
    {
        $makefile = (string)file_get_contents($path.'/makefile');
        if (!preg_match('/^CMDSTAN_VERSION\s*:?=\s*(\d+\.\d+\.\d+)/m', $makefile, $matches)) { throw new \RuntimeException('Cannot determine CmdStan version from makefile.'); }
        return $matches[1];
    }
    public static function requireVersion(string $minimum): void
    {
        if (version_compare(self::version(), $minimum, '<')) { throw new \RuntimeException('This method requires CmdStan >= '.$minimum); }
    }
    /**
     * Checks for make and the C++ compiler CmdStan will use (CXX from the environment or make/local, else clang++ on macOS and g++ elsewhere).
     * Throws when either is missing; otherwise returns ['make' => [path, version], 'cxx' => [name, path, version], 'stanc' => ?[path, version]].
     * CmdStanR's Windows RTools repair (fix = TRUE) has no PHP equivalent.
     */
    public static function checkToolchain(?string $dir = null): array
    {
        $make = self::which('make') ?? throw new \RuntimeException("The 'make' tool was not found. Install make (on macOS: xcode-select --install).");
        try { $dir ??= self::path(); } catch (\RuntimeException|\InvalidArgumentException) { $dir = null; }
        $cxx = getenv('CXX') ?: null;
        if ($cxx === null && $dir !== null && is_file($dir.'/make/local') && preg_match_all('/^\s*CXX\s*:?=\s*(\S+)/m', (string)file_get_contents($dir.'/make/local'), $m)) { $cxx = end($m[1]); }
        $cxx ??= PHP_OS_FAMILY === 'Darwin' ? 'clang++' : 'g++';
        $compiler = self::which((string)strtok($cxx, ' ')) ?? throw new \RuntimeException('A C++ compiler was not found (CXX='.$cxx.'). Install clang++ or g++.');
        $stanc = $dir !== null && is_file($dir.'/bin/stanc'.self::suffix()) ? $dir.'/bin/stanc'.self::suffix() : null;
        $version = fn(string $tool) => trim(strtok(Process::run([$tool, '--version'])['stdout'], "\n") ?: '');
        return ['make' => ['path' => $make, 'version' => $version($make)], 'cxx' => ['name' => $cxx, 'path' => $compiler, 'version' => $version($compiler)], 'stanc' => $stanc === null ? null : ['path' => $stanc, 'version' => $version($stanc)]];
    }
    /** Resolves an executable name against PATH (absolute and relative paths are checked directly). */
    public static function which(string $name): ?string
    {
        if (str_contains($name, '/') || str_contains($name, '\\')) { return is_file($name) && is_executable($name) ? (realpath($name) ?: $name) : null; }
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
            foreach (PHP_OS_FAMILY === 'Windows' ? ['', '.exe'] : [''] as $ext) {
                if ($dir !== '' && is_file($dir.'/'.$name.$ext) && is_executable($dir.'/'.$name.$ext)) { return $dir.'/'.$name.$ext; }
            }
        }
        return null;
    }
    private const OPTIONS = ['cmdstanr_output_dir' => 'dir', 'cmdstanr_write_stan_file_dir' => 'dir', 'cmdstanr_force_recompile' => 'bool', 'cmdstanr_draws_format' => 'format', 'cmdstanr_warn_inits' => 'bool', 'cmdstanr_verbose' => 'bool', 'cmdstanr_no_ver_check' => 'bool', 'cmdstanr_max_rows' => 'int', 'mc.cores' => 'int', 'cmdstanr_print_line_numbers' => 'bool', 'cmdstanphp_example_dir' => 'dir'];
    private static array $options = [];
    /**
     * Session options mirroring R's options(): returns the previous values of the options being set (so they can be restored),
     * or all set options when called without arguments. A null value unsets an option. Unknown names and wrong types throw.
     */
    public static function setOptions(array $set): array
    {
        $previous = [];
        foreach ($set as $name => $value) {
            $type = self::OPTIONS[$name] ?? throw new \InvalidArgumentException('Unknown option: '.$name);
            $valid = $value === null || match ($type) { 'bool' => is_bool($value), 'int' => is_int($value) && $value > 0, 'dir' => is_string($value) && $value !== '', 'format' => in_array($value, ['draws_array', 'draws_matrix', 'draws_df', 'draws_list'], true) };
            if (!$valid) { throw new \InvalidArgumentException('Invalid value for option '.$name.'.'); }
        }
        foreach ($set as $name => $value) {
            $previous[$name] = self::$options[$name] ?? null;
            if ($value === null) { unset(self::$options[$name]); } else { self::$options[$name] = $value; }
        }
        return $set === [] ? self::$options : $previous;
    }
    public static function option(string $name, mixed $default = null): mixed
    {
        if (!isset(self::OPTIONS[$name])) { throw new \InvalidArgumentException('Unknown option: '.$name); }
        return self::$options[$name] ?? $default;
    }
}
