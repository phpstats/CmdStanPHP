<?php
declare(strict_types=1);
namespace CmdStan;
/** Executes argument vectors without a shell, draining both pipes and bounding concurrency. */
final class Process
{
    public static function run(array $command, ?string $cwd = null, array $env = [], ?float $timeout = null): array
    {
        return self::pool([['command' => $command, 'cwd' => $cwd, 'env' => $env]], 1, $timeout)[0];
    }

    /**
     * Runs the jobs with at most $parallel at a time. $onOutput(int $index, string $stdout) receives stdout as it
     * arrives (for progress reporting). Child processes still running when PHP shuts down, for example after exit() or
     * a fatal error, are terminated, as CmdStanR 1.0 stops CmdStan when R dies.
     */
    public static function pool(array $jobs, int $parallel = 1, ?float $timeout = null, ?callable $onOutput = null): array
    {
        if ($parallel < 1 || ($timeout !== null && $timeout <= 0)) {
            throw new \InvalidArgumentException('Concurrency and timeout must be positive.');
        }
        $running = []; $results = []; $next = 0;
        $stopped = false;
        register_shutdown_function(function () use (&$running, &$stopped): void { if (!$stopped) { self::terminate($running); } });
        try {
            while ($next < count($jobs) || $running !== []) {
                while ($next < count($jobs) && count($running) < $parallel) {
                    $job = $jobs[$next];
                    $process = proc_open($job['command'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $job['cwd'] ?? null, empty($job['env']) ? null : array_merge(getenv(), $job['env']));
                    if (!is_resource($process)) { throw new \RuntimeException('Unable to start process.'); }
                    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
                    $running[$next] = ['process' => $process, 'pipes' => $pipes, 'stdout' => '', 'stderr' => '', 'start' => microtime(true)];
                    ++$next;
                }
                foreach ($running as $index => &$state) {
                    $chunk = (string)stream_get_contents($state['pipes'][1]); $state['stdout'] .= $chunk;
                    if ($onOutput !== null && $chunk !== '') { $onOutput($index, $chunk); }
                    $state['stderr'] .= stream_get_contents($state['pipes'][2]);
                    $status = proc_get_status($state['process']);
                    if ($timeout !== null && microtime(true) - $state['start'] > $timeout && $status['running']) {
                        throw new \RuntimeException('Process timed out: '.implode(' ', $jobs[$index]['command']));
                    }
                    if (!$status['running']) {
                        foreach ([1, 2] as $pipe) {
                            $rest = (string)stream_get_contents($state['pipes'][$pipe]);
                            $state[$pipe === 1 ? 'stdout' : 'stderr'] .= $rest;
                            if ($pipe === 1 && $onOutput !== null && $rest !== '') { $onOutput($index, $rest); }
                            fclose($state['pipes'][$pipe]);
                        }
                        $exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode']; $closed = proc_close($state['process']);
                        if ($exit < 0) { $exit = $closed; }
                        $results[$index] = ['stdout' => $state['stdout'], 'stderr' => $state['stderr'], 'exit_code' => $exit, 'duration' => microtime(true) - $state['start'], 'command' => $jobs[$index]['command']];
                        unset($running[$index]);
                        if ($exit !== 0) { throw new ProcessException($jobs[$index]['command'], $exit, $state['stdout'], $state['stderr']); }
                    }
                }
                unset($state);
                if ($running !== []) { usleep(10000); }
            }
        } finally {
            self::terminate($running); $stopped = true;
        }
        ksort($results); return array_values($results);
    }
    private static function terminate(array &$running): void
    {
        foreach ($running as $state) {
            if (!is_resource($state['process'])) { continue; }
            proc_terminate($state['process']);
            usleep(10000);
            if (proc_get_status($state['process'])['running']) { proc_terminate($state['process'], 9); }
            foreach ([1, 2] as $pipe) { if (is_resource($state['pipes'][$pipe])) { fclose($state['pipes'][$pipe]); } }
            proc_close($state['process']);
        }
        $running = [];
    }
}
