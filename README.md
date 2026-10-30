# CmdStanPHP

A PHP 8.2+ interface to [CmdStan](https://mc-stan.org/users/interfaces/cmdstan), with the model and fit API patterned after [CmdStanR](https://mc-stan.org/cmdstanr/) 1.0. Runs real Stan programs through CmdStan; no R installation or PHP framework is needed.

Supports compilation with build records that rebuild a model only when its program, includes, or build settings change, parallel MCMC, optimization, ADVI, Pathfinder, Laplace, standalone generated quantities, gradient diagnostics, log density, Stan JSON with tuples and complex numbers, CSV import/export (including gzip and bzip2), posterior summaries, and fit persistence. In-process log densities, gradients, Hessians, constrain/unconstrain methods, and `exposeFunctions()` use PHP FFI. Fits can initialize new runs, and MCMC fits compute PSIS-LOO, with optional moment matching through the `phit` extension, and WAIC. Trace, density, interval, rank, scatter, autocorrelation, and Pareto k figures render as ASCII text, Vega-Lite charts, HTML, or SVG/PNG. MPI launching is implemented but requires an MPI toolchain and is not covered by the local integration suite. **This is not a complete replacement for every CmdStanR feature.** See the [compatibility contract](https://phpstats.github.io/CmdStanPHP/compatibility.html) for exact differences and unimplemented features.

```sh
composer install
export CMDSTAN=/absolute/path/to/cmdstan-2.40.0
php examples/bernoulli.php
```

```php
require 'vendor/autoload.php';

$model = CmdStan\cmdstan_model('examples/bernoulli.stan');
$fit = $model->sample(
    data: ['N' => 10, 'y' => [0,1,0,0,0,0,0,0,0,1]],
    chains: 4, parallel_chains: 2, seed: 123,
    iter_warmup: 500, iter_sampling: 500,
    show_messages: false,
);
$summary = $fit->summary('theta');
$draws = $fit->draws('theta', format: 'draws_df');
$diagnostics = $fit->diagnosticSummary();
```

CmdStan 2.37 or newer is required. An existing CmdStan installation is discovered from `CMDSTAN` or `~/.cmdstan/cmdstan-*`. You can also call `CmdStan\set_cmdstan_path()`. Installing a specified official release is supported with `CmdStan\install_cmdstan('2.37.0', cores: 2)`; this requires curl, tar, make, a supported C++ compiler, internet access, and enough memory for compilation. `releaseUrl` and `releaseFile` install from another tarball.

## Data frames and the phpstats family

Model data can be a plain array, a [phibble](https://github.com/phpstats/phibble) data frame, or any object with `toModelData()` or `toColumns()`. Numeric columns become Stan arrays, factors become 1-based indices with `J_<name>` level counts, and `N` is added. String columns are rejected, as in CmdStanR 1.0. `CmdStan\StanData::fromColumns()` builds the hierarchical regression block (`N`, `K`, `X`, `y`, `J`, and `<group>_id`), and `StanData::fromFormula('y ~ x1 + f + (1 + x1 | g)', $df)` does the same through `Phit\model_matrix()` from the `phit` extension, adding factor contrasts, factor responses, and random-slope matrices (`Z_<group>`). Fits return draws and summaries as columns, or as phibble DataFrames when phibble is installed.

```php
$data = CmdStan\StanData::fromColumns($df, 'score', ['hours'], 'school');
$fit = $model->sample(data: $data, seed: 1);
$draws = $fit->drawsColumns('beta');   // ['beta[1]' => [...], '.chain' => [...], '.iteration' => [...], '.draw' => [...]]
$table = $fit->drawsTable('beta');     // Phibble\DataFrame; needs phpstats/phibble
```

The [API reference](https://phpstats.github.io/CmdStanPHP/api.html#data-frames-and-the-phpstats-family) documents the conventions. None of these packages is a Composer dependency.

### Part of the phpstats family

CmdStanPHP belongs to a family of PHP statistics packages: [phit](https://github.com/phpstats/phit) (the `phit` native extension), [phibble](https://github.com/phpstats/phibble) (data frames), [phplyr](https://github.com/phpstats/phplyr) (dplyr-style verbs), and [phiz](https://github.com/phpstats/phiz) (a Vega-Lite grammar of graphics). The family targets PHP 8.5. CmdStanPHP keeps PHP 8.2 support.

## Documentation and tests

Documentation lives in [docs/](docs/) and is published at <https://phpstats.github.io/CmdStanPHP/>, including installation, inference, API reference, compatibility, and testing. GitHub Actions builds and deploys it to GitHub Pages; select **Settings → Pages → Source → GitHub Actions** after the repository is published. No site has been published from this workspace.

```sh
composer test                 # unit suite, no CmdStan needed
composer test:integration     # real CmdStan, discovered as above
composer test:compatibility   # direct R/PHP comparisons; needs R packages
composer lint
```

The integration suite exercises all eight CmdStan methods, posterior accuracy, reproducibility, parallel chains, generated quantities, FFI model methods and functions, and errors. CI runs PHP 8.2–8.4 and CmdStan 2.37/2.40 on Linux, and compares results with CmdStanR 1.0. See [testing](https://phpstats.github.io/CmdStanPHP/testing.html) for coverage boundaries.

MIT licensed. The example models in `resources/examples/` come from CmdStanR under its BSD 3-Clause license (see the `NOTICE` there). CmdStan and CmdStanR are separate Stan projects; this package is an independent implementation, not an official Stan interface.
