# Figures and phiz: what could move and what stays

Status: planning note, October 2026. Nothing has moved yet. [phiz](https://github.com/erikwestlund/phiz) is a new Vega-Lite grammar of graphics in the phpstats family, and its API is not settled. This note records which parts of `CmdStan\Figure`, `CmdStan\VegaLite`, `CmdStan\Plot`, and `CmdStan\AsciiChart` are general plotting infrastructure and which are Stan diagnostics. It also lists what phiz would need before the diagnostics could be rebuilt on it.

This directory is excluded from the documentation site.

## Current design

`Plot` computes every statistic in PHP (densities, bins, quantiles, ranks, and autocorrelations) and returns a `Figure` with a kind, a title, data rows, and metadata. `Figure` renders those rows four ways: `ascii()` through `AsciiChart`, `vegaLite()`/`json()` through `VegaLite`, `html()` as a standalone vega-embed page, and `svg()`/`png()` through the vl-convert binary. The Vega-Lite specs use no density, binning, or quantile transforms, so every rendering shows the same numbers and the R comparisons (`PlotCompatibilityTest`) check data rows only.

## General pieces that could move to phiz

| Piece | Where | Notes |
| --- | --- | --- |
| vl-convert integration | `Figure::convert()`, `svg()`, `png()` | Binary discovery through `VL_CONVERT` or `PATH`, temporary spec and output files, `vl2svg`/`vl2png`, `--vl-version 5.21`, PNG `--scale`, timeout, and cleanup. Uses `Process::run()` and `CmdStan::which()`, which phiz would need its own copies of. |
| Script-safe spec JSON | `Figure::json()` | `JSON_HEX_TAG`, `JSON_HEX_AMP`, `JSON_HEX_APOS`, and `JSON_HEX_QUOT` so the spec can be placed in a `<script>` element; `JSON_PRESERVE_ZERO_FRACTION`. |
| Standalone HTML page | `Figure::html()` | vega, vega-lite, and vega-embed from jsDelivr pinned to major versions 5, 5, and 6, an escaped `<title>`, and one `vegaEmbed()` call. |
| Schema pinning | `Figure::VEGA_LITE_SCHEMA` | Vega-Lite 5 schema URL, kept in step with the vl-convert `--vl-version`. |
| Encoding helpers | `VegaLite::q()`, `VegaLite::facet()`, `VegaLite::number()` | Quantitative encodings with optional `zero: false`, one-row-per-group faceting with shared y scales and `'chain ' + datum.value` style labels, and exact number formatting for expressions. |
| Size validation | `VegaLite::spec()` | Minimum width and height and positive `maxRows`. |
| Systematic thinning | `VegaLite::thin()` | Keep every k-th row to bound inline data. The rule that keeps every divergent draw is Stan-specific and would stay as a filter supplied by CmdStanPHP. |
| ASCII canvas | `AsciiChart` grid, `put()`, `col()`, `row()`, `line()`, `bar()`, `frame()`, `xAxis()`, `ticks()`, `num()` | A character-cell renderer with axes, tick selection, captions, and legends. phiz could offer it as a text backend if it wants one. The per-kind renderers would stay. |
| Statistical helpers | `Plot::bandwidth()`, `density()`, `autocorrelation()`, `ranks()`, `range()`, `CmdStanFit::quantile()` | R's `bw.nrd0`, Gaussian kernel density on 512 points, `acf()`, `rank()` with averaged ties, padded ranges, and type 7 quantiles. These fit php-stats (`stats_*` functions) or phiz stat layers better than CmdStanPHP, and they are already checked against R here. |

## Stan-specific pieces that stay

These stay in CmdStanPHP and would later be rebuilt on phiz layers:

- The bayesplot and loo figures: `Plot::trace()`, `hist()`, `dens()` (pooled and by chain), `intervals()`, `rankHist()`, `scatter()` with divergent transitions, `acf()`, and `paretoK()`, and the `mcmc_*` and `pareto_k_plot()` functions.
- Draw access: `Plot::chains()` and `Plot::columns()`, which read fits and `draws_array` input, select variable elements such as `beta[2]`, and reject non-finite draws.
- The `Figure` data contract: kind, title, data rows by kind (documented on `Figure`), and metadata such as the bandwidths, bin counts, the k threshold, and whether divergences were flagged.
- Kind-specific Vega-Lite layers: the interval rules and point layer, the rank histogram's expected-count rule, the divergent-point layer and subtitle, and the Pareto k threshold and k = 1 rules.
- Kind-specific ASCII renderers, chain marks, and legends.

## What phiz needs before the rebuild

1. Inline, precomputed data with identity stats, so CmdStanPHP can keep computing statistics in PHP and the R comparisons stay valid.
2. Layers for lines, bars with explicit `x`/`x2` bin edges, rules at constant values, and points with conditional color.
3. Row faceting with shared y scales and custom header labels.
4. Rendering to a spec array, JSON, standalone HTML, and SVG/PNG through vl-convert, with the escaping and version pinning listed above.
5. A PHP floor compatible with CmdStanPHP. phiz targets PHP 8.5, and CmdStanPHP supports 8.2. Until CmdStanPHP raises its floor, phiz can only be optional: `Figure` would keep its current renderers and could add a `toPhiz()` method when phiz is installed, using the same class detection as `to_frame()`.

## Proposed sequence

1. When phiz can render a spec to HTML, SVG, and PNG, add an optional `Figure::toPhiz()` and test that its spec matches `vegaLite()`.
2. Move the vl-convert and HTML code to phiz, and keep thin wrappers in `Figure` for PHP 8.2 users without phiz.
3. Offer the statistical helpers to php-stats, keeping PHP fallbacks here.
4. Rebuild the kind-specific Vega-Lite layers on phiz when CmdStanPHP can require PHP 8.5, keeping the `Figure` data rows and the R comparisons unchanged.
