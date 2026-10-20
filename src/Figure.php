<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * A computed figure: its kind, title, data rows, and metadata, rendered on request as ASCII text, a Vega-Lite spec,
 * a standalone HTML page, or SVG/PNG through the vl-convert binary. The statistics are computed once by Plot, so
 * every rendering shows the same numbers. Echoing a figure prints its ASCII rendering.
 *
 * Data rows by kind: trace [chain, iteration, value]; hist [x0, x1, count]; dens [chain (null when pooled), x,
 * density]; intervals [variable, ll, l, m, h, hh] with m null when point_est is 'none'; rank_hist [chain, bin, x0,
 * x1, count, expected]; scatter [x, y, divergent]; acf [chain, lag, acf]; pareto_k [observation, k]. Chains,
 * iterations, bins, and observations are one-based. Non-finite statistics are null.
 */
final class Figure implements \Stringable
{
    /** Pinned for html(); vl-convert renders with the matching Vega-Lite 5 release. */
    public const VEGA_LITE_SCHEMA = 'https://vega.github.io/schema/vega-lite/v5.json';
    public function __construct(public readonly string $kind, public readonly string $title, public readonly array $data, public readonly array $meta = []) {}
    /** Text rendering; null sizes use per-kind defaults (rank histogram and autocorrelation heights are per chain). */
    public function ascii(?int $width = null, ?int $height = null): string { return AsciiChart::render($this, $width, $height); }
    /**
     * Vega-Lite spec with the data inlined. Sizes are in pixels (per facet for per-chain figures). $maxRows thins
     * trace and scatter data systematically, keeping every k-th row, to bound the size of large draws.
     */
    public function vegaLite(int $width = 480, int $height = 240, ?int $maxRows = null): array { return VegaLite::spec($this, $width, $height, $maxRows); }
    /** Spec as JSON, safe to embed in an HTML script element. */
    public function json(int $width = 480, int $height = 240, ?int $maxRows = null): string
    {
        return json_encode($this->vegaLite($width, $height, $maxRows), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
    /** Standalone HTML page that renders the spec with vega-embed loaded from the jsDelivr CDN. */
    public function html(int $width = 480, int $height = 240, ?int $maxRows = null): string
    {
        $title = htmlspecialchars($this->title, ENT_QUOTES | ENT_HTML5);
        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>".$title."</title>\n"
            ."<script src=\"https://cdn.jsdelivr.net/npm/vega@5\"></script>\n<script src=\"https://cdn.jsdelivr.net/npm/vega-lite@5\"></script>\n<script src=\"https://cdn.jsdelivr.net/npm/vega-embed@6\"></script>\n"
            ."</head>\n<body>\n<div id=\"figure\"></div>\n<script>vegaEmbed('#figure', ".$this->json($width, $height, $maxRows).");</script>\n</body>\n</html>\n";
    }
    /** SVG via vl-convert, found through the VL_CONVERT environment variable or PATH. */
    public function svg(int $width = 480, int $height = 240, ?int $maxRows = null, ?float $timeout = 60.0): string { return $this->convert('vl2svg', 'svg', [], $width, $height, $maxRows, $timeout); }
    /** PNG bytes via vl-convert at the given pixel scale. */
    public function png(int $width = 480, int $height = 240, ?int $maxRows = null, float $scale = 2.0, ?float $timeout = 60.0): string
    {
        if (!($scale > 0)) { throw new \InvalidArgumentException('scale must be positive.'); }
        return $this->convert('vl2png', 'png', ['--scale', Options::scalar($scale)], $width, $height, $maxRows, $timeout);
    }
    public function __toString(): string { return $this->ascii(); }
    private function convert(string $command, string $extension, array $extra, int $width, int $height, ?int $maxRows, ?float $timeout): string
    {
        $binary = getenv('VL_CONVERT') ?: CmdStan::which('vl-convert') ?? throw new \RuntimeException('vl-convert not found. Install it from https://github.com/vega/vl-convert/releases or set VL_CONVERT.');
        $base = sys_get_temp_dir().'/cmdstanphp-figure-'.bin2hex(random_bytes(8)); $input = $base.'.vl.json'; $output = $base.'.'.$extension;
        try {
            if (file_put_contents($input, $this->json($width, $height, $maxRows)) === false) { throw new \RuntimeException('Cannot write figure spec.'); }
            Process::run(array_merge([$binary, $command, '--input', $input, '--output', $output, '--vl-version', '5.21'], $extra), timeout: $timeout);
            return (string)file_get_contents($output);
        } finally { foreach ([$input, $output] as $file) { if (is_file($file)) { unlink($file); } } }
    }
}
