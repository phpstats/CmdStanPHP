<?php
declare(strict_types=1);
namespace CmdStan;
final class Installer
{
    /**
     * Installs CmdStan from an official release (version, or the latest release when no source is given), an https .tar.gz
     * releaseUrl, or a local releaseFile tarball. The installation directory is the archive's top-level directory.
     * With overwrite, an existing installation is moved aside and only removed after the new build succeeds.
     * cppOptions are written to make/local (append) before building. copyMakeLocal (CmdStanR 1.0) first copies the
     * make/local of the CmdStan in use into the new installation; null asks in an interactive terminal and otherwise
     * does not copy.
     */
    public static function install(?string $version = null, ?string $dir = null, ?int $cores = null, ?float $timeout = null, bool $overwrite = false, ?string $releaseUrl = null, ?string $releaseFile = null, array $cppOptions = [], bool $checkToolchain = true, ?bool $copyMakeLocal = null): string
    {
        $cores ??= CmdStan::option('mc.cores', 1);
        if ($cores < 1) { throw new \InvalidArgumentException('cores must be positive.'); }
        if (count(array_filter([$version, $releaseUrl, $releaseFile], fn($x) => $x !== null)) > 1) { throw new \InvalidArgumentException('Specify only one of version, releaseUrl and releaseFile.'); }
        if ($version !== null && !preg_match('/^2\.\d+\.\d+$/D', $version)) { throw new \InvalidArgumentException('Provide a stable CmdStan 2.x version.'); }
        if ($version !== null) { self::assertSupported('version', $version); }
        if ($releaseUrl !== null && (!str_starts_with($releaseUrl, 'https://') || !str_ends_with($releaseUrl, '.tar.gz') || preg_match('/[\s\x00-\x1f]/', $releaseUrl))) { throw new \InvalidArgumentException('releaseUrl must be an https URL of a .tar.gz archive.'); }
        if ($releaseFile !== null && (!is_file($releaseFile) || !str_ends_with($releaseFile, '.tar.gz'))) { throw new \InvalidArgumentException('releaseFile must be an existing .tar.gz archive.'); }
        foreach ($cppOptions as $name => $value) { self::makeLocalLine($name, $value); }
        $dir ??= (getenv('HOME') ?: getenv('USERPROFILE')).'/.cmdstan';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('Cannot create installation directory.'); }
        $dir = realpath($dir);
        // Fail before downloading when the official release target is already known to exist.
        if ($version !== null && !$overwrite && file_exists($dir.'/cmdstan-'.$version)) { throw new \RuntimeException('Installation already exists: '.$dir.'/cmdstan-'.$version.'. Use overwrite: true to replace it.'); }
        if ($checkToolchain) { CmdStan::checkToolchain($dir); }
        [$previousLocal, $previousPath] = self::previousMakeLocal($copyMakeLocal);
        $staging = $dir.'/.cmdstanphp-install-'.bin2hex(random_bytes(8)); mkdir($staging, 0700);
        $target = null; $backup = null; $created = false;
        try {
            $archive = $staging.'/release.tar.gz';
            if ($releaseFile !== null) {
                if (!copy($releaseFile, $archive)) { throw new \RuntimeException('Cannot copy releaseFile.'); }
            } else {
                if ($releaseUrl === null && $version === null) { $version = self::latestVersion($timeout); self::assertSupported('latest release', $version); }
                $url = $releaseUrl ?? 'https://github.com/stan-dev/cmdstan/releases/download/v'.$version.'/cmdstan-'.$version.'.tar.gz';
                Process::run(['curl', '--fail', '--location', '--retry', '3', '--proto', '=https', '--proto-redir', '=https', '--output', $archive, $url], timeout: $timeout);
            }
            $top = self::archiveRoot($archive, $timeout);
            if (preg_match('/^cmdstan-(\d+\.\d+\.\d+)/', $top, $m)) { self::assertSupported($releaseFile !== null || $releaseUrl !== null ? 'releaseUrl/releaseFile' : 'archive', $m[1]); }
            if ($version !== null && $releaseUrl === null && $top !== 'cmdstan-'.$version) { throw new \RuntimeException('Release archive does not contain cmdstan-'.$version.'.'); }
            $target = $dir.'/'.$top;
            if (file_exists($target) && !$overwrite) { throw new \RuntimeException('Installation already exists: '.$target.'. Use overwrite: true to replace it.'); }
            $extract = $staging.'/extract'; mkdir($extract, 0700);
            Process::run(['tar', '-xzf', $archive, '-C', $extract], timeout: $timeout);
            if (!is_file($extract.'/'.$top.'/makefile')) { throw new \RuntimeException('Release archive has no makefile.'); }
            if (file_exists($target)) {
                $backup = $dir.'/.cmdstanphp-previous-'.bin2hex(random_bytes(8));
                if (!rename($target, $backup)) { throw new \RuntimeException('Cannot move existing installation aside.'); }
            }
            if (!rename($extract.'/'.$top, $target)) { throw new \RuntimeException('Cannot move extracted installation.'); }
            $created = true;
            if ($previousLocal !== []) { self::makeLocal($target, $previousLocal, true); echo '* Copied make/local from '.$previousPath."\n"; }
            if ($cppOptions !== []) { self::makeLocal($target, $cppOptions, true); }
            // TBB runtime paths are embedded in binaries; build at the final location.
            Process::run(['make', '-C', $target, '-j'.$cores, 'build'], timeout: $timeout);
            CmdStan::setPath($target);
            $created = false; [$previous, $backup] = [$backup, null];
            if ($previous !== null) { try { self::removeDirectory($previous); } catch (\Throwable) {} }
            return $target;
        } catch (\Throwable $error) {
            if ($created) { self::removeDirectory($target); }
            if ($backup !== null && !file_exists($target)) { rename($backup, $target); }
            throw $error;
        } finally {
            self::removeDirectory($staging);
        }
    }
    private static function assertSupported(string $source, string $version): void
    {
        if (!CmdStan::isSupported($version)) { throw new \InvalidArgumentException('Requested CmdStan '.$source.' ('.$version.') is unsupported. CmdStanPHP requires CmdStan v'.CmdStan::MIN_VERSION.' or newer.'); }
    }
    /** Lists the archive and returns its single top-level directory, rejecting absolute paths, '..' and links escaping it. */
    public static function archiveRoot(string $archive, ?float $timeout = null): string
    {
        $entries = array_values(array_filter(explode("\n", Process::run(['tar', '-tzf', $archive], timeout: $timeout)['stdout']), fn($e) => $e !== ''));
        $verbose = array_values(array_filter(explode("\n", Process::run(['tar', '-tvzf', $archive], timeout: $timeout)['stdout']), fn($e) => $e !== ''));
        if ($entries === [] || count($verbose) !== count($entries)) { throw new \RuntimeException('Cannot list release archive.'); }
        $top = explode('/', preg_replace('~^\./~', '', $entries[0]))[0];
        if (!preg_match('/^cmdstan-[A-Za-z0-9._-]+$/D', $top)) { throw new \RuntimeException('Release archive must contain a single cmdstan-* directory.'); }
        foreach ($entries as $i => $raw) {
            $entry = preg_replace('~^\./~', '', $raw);
            if (($entry !== $top && !str_starts_with($entry, $top.'/')) || preg_match('~(^|/)\.\.(/|$)~', $entry)) { throw new \RuntimeException('Unsafe path in release archive.'); }
            $line = $verbose[$i];
            if ($line[0] !== 'l' && $line[0] !== 'h') { continue; }
            $at = strpos($line, ' '.rtrim($raw, '/').($line[0] === 'l' ? ' -> ' : ' link to '));
            if ($at === false) { throw new \RuntimeException('Cannot verify link in release archive.'); }
            $target = substr($line, $at + strlen(' '.rtrim($raw, '/')) + ($line[0] === 'l' ? 4 : 9));
            $base = $line[0] === 'l' ? explode('/', dirname($entry)) : [];
            if (str_starts_with($target, '/') || !self::contained($base, $target, $top)) { throw new \RuntimeException('Unsafe link in release archive.'); }
        }
        return $top;
    }
    private static function contained(array $parts, string $target, string $top): bool
    {
        foreach (explode('/', preg_replace('~^\./~', '', $target)) as $part) {
            if ($part === '..') { if (array_pop($parts) === null) { return false; } }
            elseif ($part !== '.' && $part !== '') { $parts[] = $part; }
        }
        return ($parts[0] ?? null) === $top;
    }
    private static function latestVersion(?float $timeout): string
    {
        $json = Process::run(['curl', '--fail', '--location', '--silent', '--proto', '=https', '--proto-redir', '=https', 'https://api.github.com/repos/stan-dev/cmdstan/releases/latest'], timeout: $timeout)['stdout'];
        $tag = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['tag_name'] ?? '';
        if (!preg_match('/^v(2\.\d+\.\d+)$/D', $tag, $m)) { throw new \RuntimeException('Cannot determine the latest CmdStan release.'); }
        return $m[1];
    }
    /** The make/local lines to copy into a new installation, and where they come from (CmdStanR's resolve_copy_make_local). */
    private static function previousMakeLocal(?bool $copy): array
    {
        if ($copy === false) { return [[], null]; }
        try { $path = CmdStan::path(); $lines = self::makeLocal($path); } catch (\Throwable) { $path = null; $lines = []; }
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        if ($lines === []) {
            if ($copy === true) { echo $path === null ? "* copyMakeLocal: true, but no CmdStan installation is currently in use, so there are no makefile flags to copy.\n" : '* copyMakeLocal: true, but '.$path." has an empty or missing make/local, so there is nothing to copy.\n"; }
            return [[], null];
        }
        if ($copy === null) {
            if (!defined('STDIN') || !stream_isatty(STDIN)) { return [[], null]; }
            echo "\nThe CmdStan installation in ".$path." has a non-empty make/local:\n  ".implode("\n  ", $lines)."\nCopy these makefile flags to the new installation? [y/N] ";
            if (!in_array(strtolower(trim((string)fgets(STDIN))), ['y', 'yes'], true)) { return [[], null]; }
        }
        return [$lines, $path];
    }
    private static function makeLocalLine(int|string $name, mixed $value): string
    {
        $value = $value === true ? 'true' : ($value === false ? '' : Options::scalar($value));
        if (preg_match('/[\r\n]/', $value)) { throw new \InvalidArgumentException('make/local values cannot contain newlines.'); }
        if (is_int($name)) { return $value; }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) { throw new \InvalidArgumentException('Invalid make option name.'); }
        return strtoupper($name).'='.$value;
    }
    /**
     * CmdStanR's cmdstan_make_local(): optionally writes NAME=value (or unnamed raw) lines, then returns the current lines.
     * When appending, a line that already applies is skipped, as in CmdStanR 1.0; false writes NAME= (turning it off).
     */
    public static function makeLocal(?string $dir = null, array $cppOptions = [], bool $append = true): array
    {
        $dir ??= CmdStan::path();
        $file = $dir.'/make/local';
        if ($cppOptions !== [] || !$append) {
            $lines = array_map(self::makeLocalLine(...), array_keys($cppOptions), array_values($cppOptions));
            if ($append && is_file($file)) {
                $existing = array_map('trim', explode("\n", (string)file_get_contents($file))); $kept = [];
                foreach ($lines as $line) { if (!self::alreadyApplies(trim($line), $existing)) { $kept[] = $line; $existing[] = trim($line); } }
                if ($kept === []) { return self::makeLocal($dir); }
                $lines = $kept;
                if (($content = (string)file_get_contents($file)) !== '' && !str_ends_with($content, "\n")) { $lines[0] = "\n".$lines[0]; }
            }
            if (!is_dir($dir.'/make')) { throw new \InvalidArgumentException('Not a CmdStan directory: '.$dir); }
            if (file_put_contents($file, $lines === [] ? '' : implode("\n", $lines)."\n", ($append ? FILE_APPEND : 0) | LOCK_EX) === false) { throw new \RuntimeException('Cannot write make/local.'); }
        }
        if (!is_file($file)) { return []; }
        $content = trim((string)file_get_contents($file));
        return $content === '' ? [] : array_map('trim', explode("\n", $content));
    }
    /** CmdStanR 1.0's make_flag_already_applies: whether appending $line to make/local would change nothing. */
    private static function alreadyApplies(string $line, array $existing): bool
    {
        $assign = '/^([A-Za-z_][A-Za-z0-9_]*)\s*(\+=|:=|\?=|=)(.*)$/';
        if (!preg_match($assign, $line, $m)) { return false; }
        [, $name, $op, $value] = $m; $depth = 0; $continued = false; $last = null; $lastPlain = -1; $assignments = [];
        foreach ($existing as $i => $raw) {
            $text = trim($raw); $wasContinued = $continued; $continued = str_ends_with($text, '\\');
            if ($wasContinued) { continue; }
            if (preg_match('/^(ifeq|ifneq|ifdef|ifndef)\b/', $text)) { ++$depth; continue; }
            if ($text === 'endif') { $depth = max(0, $depth - 1); continue; }
            if (!preg_match($assign, $text, $e) || $e[1] !== $name) { continue; }
            if ($depth > 0 || $continued) { $last = null; $lastPlain = PHP_INT_MAX; continue; }
            $last = $e; $assignments[$i] = $text;
            if ($e[2] === '=' || $e[2] === ':=') { $lastPlain = $i; }
        }
        if ($last === null) { return false; }
        if ($op !== '+=') { return trim($last[0]) === $line; }
        foreach ($assignments as $i => $text) { if ($i > $lastPlain && $text === $line) { return true; } }
        $words = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $words !== [] && array_diff($words, preg_split('/\s+/', trim($last[3]), -1, PREG_SPLIT_NO_EMPTY) ?: []) === [];
    }
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) { return; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
        }
        rmdir($dir);
    }
    public static function rebuild(?int $cores = null, ?float $timeout = null): void
    {
        $cores ??= CmdStan::option('mc.cores', 1);
        if ($cores < 1) { throw new \InvalidArgumentException('cores must be positive.'); }
        Process::run(['make', '-C', CmdStan::path(), 'clean-all'], timeout: $timeout);
        Process::run(['make', '-C', CmdStan::path(), '-j'.$cores, 'build'], timeout: $timeout);
    }
}
