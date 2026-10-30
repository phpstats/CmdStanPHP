<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanPathfinder extends CmdStanFit
{
    /** CmdStanR 1.0's lp_approx(): the approximation's log density at each draw, as one list in draw order. */
    public function lpApprox(): array { return $this->column('lp_approx__'); }
    /** Per-path draws CmdStan writes as <output>_path_<n>.csv when save_single_paths is true, in path order. */
    public function singlePathFiles(): array { return $this->context['single_path_files'] ??= $this->pathFiles('csv'); }
    /** Per-path L-BFGS/ELBO diagnostics CmdStan writes as <output>_path_<n>.json when save_single_paths is true. */
    public function singlePathJsonFiles(): array { return $this->context['single_path_json_files'] ??= $this->pathFiles('json'); }
    /** One single-path CmdStanPathfinder fit per path CSV (CmdStanR does not expose these). */
    public function singlePathFits(): array
    {
        return array_map(fn($file) => new self([$file], 'pathfinder', context: array_intersect_key($this->context, array_flip(['code', 'model_name', 'stan_file', 'stanc_options', 'data_file']))),$this->singlePathFiles());
    }
    /** Decoded per-path JSON diagnostics, one array per path. */
    public function singlePathDiagnostics(): array
    {
        return array_map(fn($file) => json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $this->singlePathJsonFiles());
    }
    protected function pinFiles(): void { $this->singlePathFiles(); $this->singlePathJsonFiles(); }
    /** With num_paths = 1 CmdStan writes no _path_ files: the output CSV is the single path and its diagnostics are <output>.json. */
    private function pathFiles(string $extension): array
    {
        $stem = (string)preg_replace('/\.csv(\.gz|\.bz2)?$/i', '', basename($this->files[0])); $dir = dirname($this->files[0]); $found = [];
        $meta = $this->chainMetadata()[0];
        if (($meta['num_paths'] ?? null) === 1 && ($meta['save_single_paths'] ?? false) === true) { return array_values(array_filter([$dir.'/'.$stem.'.'.$extension], 'is_file')); }
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match('/^'.preg_quote($stem, '/').'_path_(\d+)\.'.$extension.'$/D', $entry, $m)) { $found[(int)$m[1]] = $dir.'/'.$entry; }
        }
        ksort($found);
        return array_values($found);
    }
}
