---
layout: page
title: API reference
---

All classes and functions are in the `CmdStan` namespace. Function and method names use CmdStanR's snake_case spelling. Inference methods accept PHP named arguments or one associative options array; they reject unknown options instead of silently ignoring them. `null` generally leaves the installed CmdStan default in effect. Check the [compatibility contract]({{ '/compatibility/' | relative_url }}) for exceptions.

## Functions

| Function | Purpose |
| --- | --- |
| `cmdstan_model(stan_file, compile: true, exe_file: null, stanc_options: [], cpp_options: [], force_recompile: null)` | Construct and optionally compile a model |
| `set_cmdstan_path(path)` / `cmdstan_path()` | Select/read CmdStan installation |
| `cmdstan_version()` | Read installed release version |
| `check_cmdstan_toolchain()` | Return paths and versions of make, CmdStan's C++ compiler, and stanc; throws when make or the compiler is missing. It does not prove a compilation will succeed |
| `install_cmdstan(version: null, dir: null, cores: null, timeout: null, overwrite: false, release_url: null, release_file: null, cpp_options: [], check_toolchain: true)` | Download or unpack and build a release (see the installation guide) |
| `cmdstan_make_local(dir: null, cpp_options: [], append: true)` | Read or write CmdStan's `make/local` |
| `rebuild_cmdstan(cores: null, timeout: null)` | Clean and rebuild selected CmdStan |
| `options(set: [])` / `cmdstan_option(name, default: null)` | Set global options, returning previous values, or read one option |
| `write_stan_file(code, file: null, dir: null, basename: null, force_overwrite: false, hash_salt: '')` | Write Stan source to `model_<hash>.stan`, reusing identical files |
| `write_stan_json(data, file)` | Write numeric data/initialization/metric JSON; `data` may also be a `StanData` or a table |
| `read_cmdstan_csv(files)` / `as_cmdstan_fit(files)` | Import one or more CmdStan CSV files into a typed fit |
| `read_cmdstan_csv_list(files, variables: null, sampler_diagnostics: null, format: null)` | Return CmdStanR's `read_cmdstan_csv()` list shape |
| `draws_to_csv(chains, dir)` | Export `[chain][iteration][variable]` associative draw rows to separate CSV files |
| `mcmc_trace`, `mcmc_hist`, `mcmc_dens`, `mcmc_dens_overlay`, `mcmc_intervals`, `mcmc_rank_hist`, `mcmc_scatter`, `mcmc_acf`, `pareto_k_plot` | Return a `Figure` named after its bayesplot or loo counterpart; see the inference guide |
| `cmdstanr_example(example: 'logistic', method: 'sample', ...options)` / `print_example_program(example)` | Run or print the `logistic`, `schools`, or `schools_ncp` example |

`read_sample_csv()`, `write_stan_tempfile()`, and `num_threads()` remain as deprecated CmdStanR names.

### Global options

`options()` accepts `cmdstanr_output_dir`, `cmdstanr_write_stan_file_dir`, `cmdstanr_force_recompile`, `cmdstanr_draws_format`, `cmdstanr_warn_inits`, `cmdstanr_verbose`, `cmdstanr_no_ver_check`, `cmdstanr_max_rows`, `cmdstanr_print_line_numbers`, `mc.cores`, and `cmdstanphp_example_dir`. A `null` value unsets an option. Unknown names and values of the wrong type throw. `mc.cores` sets the default `parallel_chains` and installer core count.

Data arrays use named PHP arrays at the top level and zero-indexed lists for Stan arrays, vectors, and matrices. Boolean values become 0/1. Matrices are rows of rows, with no R column-major conversion. Empty top-level data becomes `{}`. Missing values, strings, objects, nonfinite numbers, sparse indices, invalid variable names, and ragged arrays are rejected.

## Model methods

`stan_file()`, `exe_file()`, `hpp_file()`, and `code()` return paths or source text. `expose_functions(timeout: null)` compiles the model's user-defined functions and returns a `StanFunctions` object; `functions()` returns it afterwards. `model_name()`, `has_stan_file()`, `cpp_options()`, and `include_paths()` expose model identity and recorded compiler settings. `print(line_numbers: false)` writes source to stdout. `save_hpp_file(dir)` copies the generated header. `variables()` returns stanc's JSON information for source-level declarations and dimensions. `format(overwrite_file: false, stanc_options: [])` returns formatted Stan and optionally overwrites the source. `check_syntax(stanc_options: [])` throws on invalid Stan and returns true on success.

`compile(force_recompile: null, stanc_options: [], cpp_options: [], cores: 1, timeout: null)` compiles via CmdStan's makefile and returns the model. Compiler options use associative arrays, e.g. `['warn-pedantic' => true]` or `['STAN_THREADS' => true]`. Make values containing command expansions or shell metacharacters are rejected. stanc option values with whitespace are not supported. Changing options removes stale model artifacts before rebuilding.

| Method | Fit type | Required CmdStan |
| --- | --- | --- |
| `sample()` / `sample_mpi()` | `CmdStanMCMC` | 2.31+ supported baseline |
| `optimize()` | `CmdStanMLE` | 2.31+ |
| `variational()` | `CmdStanVB` | 2.31+ |
| `pathfinder()` | `CmdStanPathfinder` | 2.33+ |
| `laplace()` | `CmdStanLaplace` | 2.32+ |
| `generate_quantities(fitted_params, ...)` | `CmdStanGQ` | 2.31+ |
| `diagnose()` | `CmdStanDiagnose` | 2.31+ |
| `log_prob()` | `CmdStanFit` | 2.31+ |

The CI version targets are 2.37 and 2.40. Availability of individual options remains determined by the selected CmdStan; options added in newer releases can fail on older releases with CmdStan's error message.

### Shared options

`data`, `seed`, `refresh`, `init`, `output_dir`, `output_basename`, `sig_figs`, `threads`, `threads_per_chain`, `num_threads`, `opencl_ids`, `show_messages`, `show_exceptions`, `save_latent_dynamics`, `save_cmdstan_config`, `timeout`.

`init` additionally accepts a fit object (see the inference guide). Deprecated CmdStanR names are mapped with an `E_USER_DEPRECATED` notice: on `sample()`, `cores`, `num_cores`, `num_chains`, `num_warmup`, `num_samples`, `max_depth`, `stepsize`, `save_extra_diagnostics`, and `validate_csv`; on `optimize()`, `laplace()`, and `log_prob()`, `jacobian_adjustment`.

Thread options are aliases, with precedence `threads_per_chain`, `threads`, then `num_threads`. Prefer the argument appropriate to the method. `sig_figs` is 1–18. `opencl_ids` contains platform/device IDs and requires an OpenCL build. `output_basename` contains only letters, numbers, dots, underscores, and hyphens. `save_latent_dynamics` creates auxiliary diagnostic CSVs; these may be empty for some algorithms. `save_cmdstan_config` requests CmdStan configuration JSON beside the CSVs.

### Method-specific options

- **sample:** `chains` (default 4), `parallel_chains` (default 1), `chain_ids`, `iter_warmup`, `iter_sampling`, `save_warmup`, `thin`, `max_treedepth`, `adapt_engaged`, `adapt_delta`, `step_size`, `metric`, `metric_file`, `inv_metric`, `init_buffer`, `term_buffer`, `window`, `fixed_param`, `save_metric`, `diagnostics`.
- **optimize:** `algorithm`, `jacobian`, `init_alpha`, `iter`, `tol_obj`, `tol_rel_obj`, `tol_grad`, `tol_rel_grad`, `tol_param`, `history_size`, `save_iterations`.
- **variational:** `algorithm`, `iter`, `grad_samples`, `elbo_samples`, `eta`, `adapt_engaged`, `adapt_iter`, `tol_rel_obj`, `eval_elbo`, `output_samples`, `draws` (alias for `output_samples`).
- **pathfinder:** `init_alpha`, `tol_obj`, `tol_rel_obj`, `tol_grad`, `tol_rel_grad`, `tol_param`, `history_size`, `single_path_draws`, `draws`, `num_paths`, `max_lbfgs_iters`, `num_elbo_draws`, `save_single_paths`, `psis_resample`, `calculate_lp`.
- **laplace:** `mode`, `opt_args`, `jacobian` (default true), `draws`, `calculate_lp`.
- **generate_quantities:** required `fitted_params` is a fit, filename, or filename list; `parallel_chains` controls concurrency.
- **diagnose:** `epsilon`, `error` for finite-difference gradient checks.
- **log_prob:** exactly one of `unconstrained_params`, `constrained_params`, and optional `jacobian`.

`sample_mpi(mpi_cmd: 'mpiexec', mpi_args: [], ...options)` accepts sampling options, defaults to one chain, and executes chains sequentially. An associative MPI argument such as `['n' => 4]` becomes `-n 4`; a list is passed as a literal argument vector. Compile with `['STAN_MPI' => true, 'CXX' => 'mpicxx', 'TBB_CXX_TYPE' => 'gcc']` and install an MPI runtime. MPI launch construction is unit-tested; a real MPI cluster run is not validated here.

## Fit methods

- `draws(variables: null, format: null, inc_warmup: false)`: select posterior variables. Formats are `draws_array`, `draws_matrix`, `draws_df`, `draws_list`, and `draws_rvars`; `null` uses the `cmdstanr_draws_format` option or the method default.
- `sampler_diagnostics(format: null, inc_warmup: false)`: diagnostic columns ending in `__`, excluding `lp__`.
- `summary(variables: null, probs: [0.05, 0.95], measures: [])`: descriptive and MCMC convergence summaries, or the requested summary measures.
- `diagnostic_summary()`: per-chain `num_divergent`, `num_max_treedepth`, and `ebfmi` arrays.
- `check_diagnostics(checks: ['divergences', 'treedepth', 'ebfmi'])`, `diagnostic_warnings()`: check and read messages.
- `cmdstan_diagnose()`: invoke CmdStan's diagnostic utility and return text.
- `metadata()`, `chain_metadata()`, `variables()`, `num_chains()`, `num_draws_sampling()`, `num_draws_warmup()`: inspect parsed CSV.
- `step_size()`, `inv_metric(matrix: true)`: adaptation results, one entry per chain.
- `time()`: per-chain warmup, sampling, and total time when available.
- `output()`, `return_codes()`: captured console output and process status. Imported fits have no process logs or status codes.
- `output_files()`, `latent_dynamics_files()`, `profile_files()`, `profiles()`: output paths and parsed profiling data. The file accessors accept `include_failed` for CmdStanR compatibility; fits only contain successful runs. Latent-dynamics and profile accessors throw when no such files exist.
- `data_file()`, `config_files()`, `metric_files()`, `cmdstan_config()`, `metric()`: data and auxiliary JSON paths and their decoded contents. Imported fits use the data file recorded in the CSV when it still exists.
- `code()`, `num_procs()`, `lp()`, `cmdstan_summary()`: stored source, number of processes, log-density matrix, and native text summary. Imported CSV fits do not include source code.
- `save_output_files()`, `save_latent_dynamics_files()`, `save_profile_files()`, `save_data_file()`, `save_config_files()`, `save_metric_files()`: each takes `dir: '.', basename: null, timestamp: true, random: true` and names files `basename-YYYYmmddHHMM-id-xxxxxx.ext` as CmdStanR does. Files created by the run are moved; imported and user-supplied files are copied. Existing destinations are never overwritten.
- `save_object(file)`: serialize parsed fit data (and native MCMC summary statistics) to a PHP object file.
- `print(variables: null, max_rows: null)`: emit summary JSON to stdout, limited to `cmdstanr_max_rows` (default 10) variables.
- `draws_columns()`, `summary_columns()`, `draws_table()`, `summary_table()`: column-oriented draws and summaries for data frames; see [data frames](#data-frames-and-the-phpstats-family).

### Model methods

These methods need PHP FFI. The first call compiles a shared library for the fit's model and data; `init_model_methods(seed: 1, verbose: false, hessian: false, model: null)` does this explicitly. Imported CSV fits must pass `model:`.

- `log_prob(unconstrained_variables, jacobian: true)`: log density with constants dropped.
- `grad_log_prob(...)`: `['log_prob' => float, 'gradient' => list]`.
- `hessian(...)`: `['log_prob' => float, 'grad_log_prob' => list, 'hessian' => rows]`.
- `unconstrain_variables(variables)`: a flat unconstrained list from parameter values keyed by name.
- `unconstrain_draws(files: null, draws: null, format: null, inc_warmup: false)`: the fit's draws, another set of CSV files, or `draws_df` rows, in unconstrained space.
- `constrain_variables(unconstrained_variables, transformed_parameters: true, generated_quantities: true)`: variables keyed by name as nested arrays.
- `variable_skeleton(transformed_parameters: true, generated_quantities: true)`: variable name to dimension list.
- `expose_functions(timeout: null)` / `functions()`: the model's user-defined functions.

`jacobian_adjustment` is accepted as a deprecated alias of `jacobian`. `CmdStan\ModelMethods` and `CmdStan\StanFunctions` can also be used directly with a Stan file.

### Method-specific fit methods

`CmdStanMCMC` adds `loo(variables: 'log_lik', r_eff: true, moment_match: false, max_iters: 30, k_threshold: null, split: true, cov: true)` and `waic(variables: 'log_lik')`. Both return `estimates` (each with `Estimate` and `SE`), `pointwise` rows, and flat keys such as `elpd_loo` and `se_elpd_loo`; LOO also returns `diagnostics` with Pareto k values. `moment_match: true` needs model methods and the `phit` posterior-draw kernels, and adds a `moment_match` key; see the inference guide.

`CmdStanMLE` adds `mle()`/`optimized_params()` (final optimizer row) and `lp()`; its `summary()` returns `variable`/`estimate` rows, matching CmdStanR's optimization summary. `CmdStanVB` adds `variational_mean()` and `lp_approx()`. Pathfinder and Laplace also expose `lp_approx()`; Laplace exposes `mode()`, and generated quantities fits expose `fitted_params_files()`. `CmdStanPathfinder` adds `single_path_files()`, `single_path_json_files()`, `single_path_fits()`, and `single_path_diagnostics()` for runs with `save_single_paths: true`. `CmdStanDiagnose` adds `gradients()` (gradient-check console output).

### Statistics helpers

`CmdStan\Figure` renders computed figures with `ascii()`, `vegaLite()`, `json()`, `html()`, `svg()`, and `png()`; casting a figure to a string gives its ASCII form. `CmdStan\Plot` computes the figures, plus `bandwidth()`, `density()`, `autocorrelation()`, and `ranks()` for the values they draw. `CmdStan\Psis` (`pareto_smooth()`, `pareto_khat()`, `psis()`, `gpdfit()`), `CmdStan\Loo` (`loo()`, `waic()`, `relative_eff()`), `CmdStan\MomentMatch` (`loo()` on unconstrained draws with log-density and log-likelihood callbacks, `fromFit()`), `CmdStan\DrawKernels` (detection of and wrappers for the `phit` draw kernels), `CmdStan\Summary` (`summarise()` and each posterior measure), `CmdStan\Rvars`, and `CmdStan\Inits` (`fromFit()`, `fromDraws()`, `nest()`, `validate()`) operate on plain draws arrays. Their docblocks describe the input and output shapes.

## Data frames and the phpstats family

CmdStanPHP works with the [phpstats family](#part-of-the-phpstats-family) without depending on it. phibble is detected by method and class names. The `phit` extension is detected by function name.

### Data in

Every `data` argument (`sample()`, `sample_mpi()`, `optimize()`, `variational()`, `pathfinder()`, `laplace()`, `generate_quantities()`, `diagnose()`, and `log_prob()`) and `write_stan_json()` accept a plain array, a JSON file, a `CmdStan\StanData`, or a table. A table is any object with `toModelData()` or `toColumns()`, such as a [phibble](https://github.com/phpstats/phibble) `Phibble\DataFrame`. `toModelData()` is used when both exist. `StanData::fromTable($table, add_n: true)` performs the same conversion and keeps the factor labels.

| Column | Stan data |
| --- | --- |
| int or float | The list of values, unchanged |
| bool | 0/1 integers |
| list cells | Nested arrays, which must be rectangular |
| factor | 1-based integer indices in level order, plus `J_<name>`, the number of levels |
| string | A factor with sorted unique levels (`strcmp` order, as phibble's and R's `factor()` defaults) |
| null, dates, other objects | Rejected with the column name and row, because Stan data has no missing values |

Factor levels come from a `toModelData()` descriptor (`['levels' => list<string>, 'codes' => list<?int>, 'ordered' => bool]`, with 0-based codes, as phibble's `DataFrame::toModelData()` returns them since phibble [93858fa](https://github.com/phpstats/phibble/commit/93858fa); add `'base' => 1` for 1-based codes) or, for a `toColumns()` table, from `levels($name)` when `types()` reports a factor. The `ordered` flag is ignored. Labels cannot be written to Stan JSON, so they are returned in `StanData::$levels`, keyed by data variable, where label `i` has index `i + 1`. The conversion adds `N`, the row count, unless the table has a column named `N`, and adds `J_<name>` unless a column already has that name. CmdStan ignores data variables that the program does not declare. Column names must be valid Stan identifiers.

Fit methods use CmdStanR-style snake_case names. `StanData` and `Tables` constructors use camelCase `from...()` names, like the package's other static helpers such as `CmdStanFit::fromCsv()` and `Inits::fromFit()`. `StanData` is a value object with readonly `data`, `levels`, `columns` (the names of the columns of `X`), and `meta`. `toArray()` returns `data`, and `with(['prior_scale' => 2.5])` adds or replaces variables.

### Regression data

`StanData::fromColumns($columns, y: 'y', x: ['x1', 'x2'], groups: 'school', intercept: false)` builds the usual hierarchical regression data block from named columns or a table:

| Variable | Contents |
| --- | --- |
| `N` | Number of rows |
| `K` | Number of predictors (columns of `X`) |
| `X` | N rows of K floats, in the order of `x` |
| `y` | The outcome, keeping integers as integers |
| `<g>_id` | For each grouping column `g`, the 1-based group index of each row |
| `J_<g>` | For each grouping column, the number of groups |
| `J` | The number of groups, when there is exactly one grouping column |

Groups are numbered over sorted unique values (numbers numerically, otherwise in `strcmp` order) or over a factor's levels, and the labels are in `levels['<g>_id']`. Predictors must be numeric. `intercept: true` prepends a column of ones named `(Intercept)`. It is off by default because Stan programs usually give the intercept its own parameter.

```php
$data = CmdStan\StanData::fromColumns($frame, 'score', ['hours'], 'school');
$fit = $model->sample(data: $data, seed: 1);
```

```stan
data {
  int<lower=0> N;
  int<lower=0> K;
  matrix[N, K] X;
  vector[N] y;
  int<lower=1> J;
  array[N] int<lower=1, upper=J> school_id;
}
```

`StanData::fromFormula('y ~ x1 + f + (1 + x1 | g)', $data, drop_intercept: false, missing: 'drop')` builds regression data from an R-style formula through `Phit\model_matrix()` in the [`phit`](https://github.com/phpstats/phit) extension (phit `741d38f` and later). The function handles factor predictors (treatment contrasts), interactions, transforms, and offsets, and keeps the `(Intercept)` column in `X` unless `drop_intercept: true`. `$data` can be columns, factor descriptors, or a table, whose `toModelData()` is passed unchanged. Without the function, `fromFormula()` throws a `RuntimeException` that names the requirement. `StanData::fromModelMatrix($result)` maps an existing `Phit\model_matrix()` result and is the only code that depends on its shape.

| Formula part | Stan data |
| --- | --- |
| Design matrix | `N` (retained rows), `K`, and `X`; the column names are in `columns` |
| Numeric response | `y`, as integers when every value is integral, so it suits `int` and `real` declarations |
| Factor response | `y` as 0/1 for two levels (Bernoulli), or 1..`C` with `C` levels (categorical); labels in `levels['y']` |
| `cbind(s, f)` response | `y` (successes) and `trials` (successes plus failures) |
| `offset()` | `offset` |
| `(... \| g)` | `<g>_id` (1-based) and `J_<g>` per grouping factor, and `J` when there is one grouping factor |
| Random slopes, such as `(1 + x \| g)` | `Z_<g>` (N rows of the group's columns, including the intercept column) and `Q_<g>`, the number of columns; omitted when every term on the group is intercept-only |

Group names that are not Stan identifiers have other characters replaced by `_`, so the nested group `g2:g1` gives `g2_g1_id`. Groups are numbered in factor level order when the data has descriptors or table factors, and otherwise over sorted labels (numbers numerically, otherwise byte order, as `Phit\model_matrix()` sorts levels). Terms on the same group are concatenated into one `Z_<g>`, intercept-only terms included, so `(1 | g) + (0 + x | g)` gives the same `Z_g` as `(1 + x | g)`. Two groups whose names map to the same `<g>`, such as `a.b` and `a_b`, are rejected. Factors reach `Phit\model_matrix()` as descriptors in the data's level order, so the first level is the reference level and a factor response is coded in that order, while plain string columns use sorted levels. `missing: 'drop'` removes rows with missing values in any variable the formula uses, with one `E_WARNING` from `Phit\model_matrix()`, and `missing: 'raise'` rejects them. The remaining result keys, including `retained_rows`, `dropped_rows`, `n_input`, `n_dropped`, `terms`, and `assign`, are kept in `meta`, and `meta['random'][<g>]` lists each group's columns and terms.

```stan
data {
  int<lower=0> N;
  int<lower=1> K;
  matrix[N, K] X;          // includes the intercept column
  vector[N] y;
  int<lower=1> J;
  array[N] int<lower=1, upper=J> g_id;
  int<lower=1> Q_g;
  matrix[N, Q_g] Z_g;      // (1 + x1 | g)
}
```

### Draws and summaries out

- `draws_columns(variables: null, inc_warmup: false)` returns draws as columns, like posterior's `as_draws_df()`: one list per variable element (such as `beta[2]`), then one-based `.chain`, `.iteration`, and `.draw` columns.
- `summary_columns(variables: null, probs: [0.05, 0.95], measures: [])` returns `summary()` as columns: `variable` and one column per measure.
- `draws_table()` and `summary_table()` take the same arguments and return a `Phibble\DataFrame` built with `DataFrame::fromColumns()`. They need `phpstats/phibble`, which requires PHP 8.5, and throw a `RuntimeException` explaining this when it is not installed.
- `CmdStan\Tables::fromRows($rows)` converts any list of associative rows, such as `loo()['pointwise']`, to columns.

The column arrays need no other package and suit any data frame library that accepts name-to-values columns.

### Part of the phpstats family

CmdStanPHP is part of the phpstats family of PHP statistics packages:

- [phit](https://github.com/phpstats/phit): the `phit` native extension (`Phit\` functions), which provides the moment-matching draw kernels and, when available, `Phit\model_matrix()`.
- [phibble](https://github.com/phpstats/phibble): data frames (`Phibble\DataFrame`).
- [phplyr](https://github.com/phpstats/phplyr): dplyr-style verbs for phibble tables.
- [phiz](https://github.com/phpstats/phiz): a Vega-Lite grammar of graphics.

The family packages target PHP 8.5. CmdStanPHP keeps PHP 8.2 support and requires none of them.

## Errors

Invalid PHP data or options throw `InvalidArgumentException`. Missing files/toolchain, timeouts, and malformed CSV throw runtime errors. A nonzero command exit throws `ProcessException`, whose public readonly properties are `command`, `exitCode`, `stdout`, and `stderr`. A failing chain terminates other active chains; partial output files remain available for investigation. Partial outputs are not returned as a successful fit.
