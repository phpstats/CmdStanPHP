<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * CmdStanR 1.0's sampling progress bar: one bar for all chains, advanced from CmdStan's "Iteration: i / n [ p%]
 * (Warmup|Sampling)" lines and drawn on stderr. With the bar on, those lines are left out of the printed output.
 */
final class ProgressBar
{
    private const ITERATION = '/Iteration:\s+(\d+)\s*\/\s*(\d+)\s*\[\s*\d+%\]\s+\((?:Warmup|Sampling)\)/';
    private array $buffers = [];
    private array $last = [];
    private ?int $total = null;
    private int $done = 0;
    private bool $drawn = false;
    public function __construct(private int $processes) {}
    /** Takes a process's stdout as it arrives (the Process::pool() callback). */
    public function update(int $index, string $chunk): void
    {
        $this->buffers[$index] = ($this->buffers[$index] ?? '').$chunk;
        while (($end = strpos($this->buffers[$index], "\n")) !== false) {
            $line = substr($this->buffers[$index], 0, $end); $this->buffers[$index] = substr($this->buffers[$index], $end + 1);
            if (!preg_match(self::ITERATION, $line, $m)) { continue; }
            $this->total ??= (int)$m[2] * $this->processes;
            $this->done += max(0, (int)$m[1] - ($this->last[$index] ?? 0)); $this->last[$index] = (int)$m[1];
            $this->draw();
        }
    }
    public function finish(): void { if ($this->drawn) { fwrite(self::stderr(), "\n"); $this->drawn = false; } }
    public static function withoutIterations(string $stdout): string { return (string)preg_replace('/^.*Iteration:.*\R?/m', '', $stdout); }
    private function draw(): void
    {
        if (!$this->total) { return; }
        $fraction = min(1.0, $this->done / $this->total); $filled = (int)round(40 * $fraction);
        fwrite(self::stderr(), sprintf("\r[%s%s] %3d%% (%d chain%s)", str_repeat('=', $filled), str_repeat(' ', 40 - $filled), (int)floor(100 * $fraction), $this->processes, $this->processes === 1 ? '' : 's'));
        $this->drawn = true;
    }
    /** @return resource */
    private static function stderr() { return defined('STDERR') ? STDERR : fopen('php://stderr', 'w'); }
}
