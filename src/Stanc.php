<?php
declare(strict_types=1);
namespace CmdStan;
/** stanc on a Stan file alone, without a model object: check_syntax_stan_file(), format_stan_file(), variables_stan_file(). */
final class Stanc
{
    private static function stanc(): string { return CmdStan::path().'/bin/stanc'.CmdStan::suffix(); }
    private static function file(string $stanFile): string
    {
        $real = realpath($stanFile);
        if ($real === false || !is_file($real)) { throw new \InvalidArgumentException('Stan file not found: '.$stanFile); }
        return $real;
    }
    private static function includeArgs(array $paths): array { return $paths === [] ? [] : ['--include-paths='.implode(',', $paths)]; }
    private static function stderr(string $text): void { if (trim($text) !== '') { fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), $text); } }
    /** Throws "Syntax error found!" with stanc's message; prints "Stan program is syntactically correct" unless quiet. */
    public static function checkSyntax(string $stanFile, ?array $includePaths = null, bool $pedantic = false, array $stancOptions = [], bool $quiet = false): bool
    {
        $file = self::file($stanFile); $hpp = sys_get_temp_dir().'/cmdstanphp-'.bin2hex(random_bytes(8)).'.hpp';
        $args = array_merge([self::stanc(), '--allow-undefined', '--o='.$hpp], Build::stancOptions($stancOptions), $pedantic ? ['--warn-pedantic'] : [], ['--name='.Build::modelName($file).'_model'], self::includeArgs(Build::includePaths($file, $includePaths)), [$file]);
        try { $result = Process::run($args); }
        catch (ProcessException $e) { throw new \RuntimeException("Syntax error found! See the message below for more information.\n".trim($e->stderr."\n".$e->stdout), 0, $e); }
        finally { if (is_file($hpp)) { unlink($hpp); } }
        self::stderr($result['stderr']);
        if (!$quiet) { echo "Stan program is syntactically correct\n"; }
        return true;
    }
    /**
     * The formatted program. canonicalize: true prints the canonical program, or a list of canonicalizations to apply.
     * overwriteFile rewrites the file, keeping a <file>.bak-YYYYMMDDHHMMSS copy unless backup is false.
     */
    public static function format(string $stanFile, ?array $includePaths = null, bool $overwriteFile = false, bool|array $canonicalize = false, bool $backup = true, ?int $maxLineLength = null, bool $quiet = false): string
    {
        $file = self::file($stanFile);
        $args = [self::stanc(), '--allow-undefined', '--auto-format'];
        if ($maxLineLength !== null) { if ($maxLineLength < 1) { throw new \InvalidArgumentException('maxLineLength must be positive.'); } $args[] = '--max-line-length='.$maxLineLength; }
        if ($canonicalize === true) { $args[] = '--print-canonical'; }
        elseif (is_array($canonicalize) && $canonicalize !== []) { $args[] = '--canonicalize='.implode(',', array_map(Options::scalar(...), $canonicalize)); }
        $result = Process::run(array_merge($args, self::includeArgs(Build::includePaths($file, $includePaths)), [$file]));
        self::stderr($result['stderr']);
        if ($overwriteFile) {
            if ($backup) {
                $copy = $file.'.bak-'.date('YmdHis');
                if (!copy($file, $copy)) { throw new \RuntimeException('Cannot back up the Stan file.'); }
                if (!$quiet) { echo 'Old version of the model stored to '.$copy.".\n"; }
            }
            if (file_put_contents($file, $result['stdout'], LOCK_EX) === false) { throw new \RuntimeException('Cannot overwrite the Stan file.'); }
        }
        return $result['stdout'];
    }
    /** Prints Stan code; line numbers default to the cmdstanr_print_line_numbers option and are right-aligned like CmdStanR's. */
    public static function print(string $code, ?bool $lineNumbers = null): void
    {
        $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $code))); $width = strlen((string)count($lines)); $numbers = $lineNumbers ?? CmdStan::option('cmdstanr_print_line_numbers', false);
        foreach ($lines as $i => $line) { echo ($numbers ? str_pad((string)($i + 1), $width, ' ', STR_PAD_LEFT).': ' : '').$line."\n"; }
    }
    /** The program's variables: data, parameters, transformed_parameters and generated_quantities, as stanc --info declares them. */
    public static function variables(string $stanFile, ?array $includePaths = null): array
    {
        $file = self::file($stanFile);
        return Build::variables(Build::info($file, Build::includePaths($file, $includePaths)));
    }
}
