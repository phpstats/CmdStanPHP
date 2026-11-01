# Interface comparison

These scripts run the non-centered eight schools model (Rubin 1981), a hierarchical Gaussian model that phpstats.org lists as "Mixed Gaussian (Bayesian)", through CmdStanPHP, cmdstanr, and CmdStanPy and compare the results. All three use the same CmdStan, data, seed, chain ids, and initialization, so CmdStan produces the same draws, and each interface then summarizes them with its own code. `compare.py` writes the results to `site/verifications/interfaces.json`, which phpstats.org shows in its verification section.

## Files

- `schools.stan` and `data.json` are the model and the data. The data are the eight schools effects and standard errors from Rubin (1981), also published as [schools.csv](https://raw.githubusercontent.com/phpstats/datasets/main/csv/schools.csv) (columns school, y, and sigma) in phpstats/datasets.
- `cmdstanphp.php`, `cmdstanr.R`, and `cmdstanpy_run.py` each copy the model into `output/<interface>/`, compile it there, sample, and save the summary and the CSV file paths to `output/<interface>/summary.json`.
- `compare.py` reads the CSV files and summaries, checks that the CmdStan arguments recorded in each CSV header match, computes the largest absolute differences in draws and in summaries, and writes the JSON file.
- `example.php` is the short script the site shows beside the results. It produces the same draws.
- `run.sh` runs everything.

## Running

Requirements:

- CmdStan 2.40.0, at `~/.cmdstan/cmdstan-2.40.0` or in `CMDSTAN`
- PHP with this repository's Composer dependencies installed
- R with cmdstanr 0.9.0 and posterior
- uv

```sh
verification/interfaces/run.sh
```

`run.sh` creates a virtual environment in `.venv` with CmdStanPy 1.3.0 the first time it runs. `output/` and `.venv/` are not committed.

## Arguments

Each interface runs 4 chains with ids 1 to 4, 1,000 warmup and 1,000 sampling iterations, seed 20261101, and `init = 2`. Everything else uses CmdStan's defaults. Each chain is a separate CmdStan process with `num_chains = 1`.

## Results

The draws are identical: the largest absolute difference between any two interfaces is 0 across all 25 CSV columns and 4,000 draws. CmdStanPHP's and cmdstanr's summaries differ by less than 1e-10. CmdStanPy's `summary()` runs CmdStan's `stansummary` and rounds its output to 6 significant figures by default, so its values differ from the others by up to that rounding (4e-5 for a 95% quantile near 16). With `summary(sig_figs=12)` the largest difference from CmdStanPHP is 5e-9, in bulk ESS.

`interfaces.json` also lists one case per variable and summary measure, in the format of the other phpstats verification files. Each case compares CmdStanPHP with cmdstanr and with CmdStanPy at 12 significant figures, and it passes within 1e-9 + 1e-9 times the absolute value of cmdstanr's result. A final case, `draws`, passes only when the draws are identical.

## Workload benchmark

`bench/run.py` times the same model end to end from each interface and writes `site/benchmarks/interfaces-workload.json`. It compiles `schools.stan` once with CmdStan's makefile into `bench/model/`, and every interface loads that executable through its `exe_file` argument, so compilation is outside the timing. Each run is a fresh process under `peakmem`, built from `bench/peakmem.c` with `cc -O2 -o peakmem peakmem.c` and placed on `PATH`, which reports the process's peak anonymous memory and peak RSS on Linux: `bench/cmdstanphp.php`, `bench/cmdstanr.R`, or `bench/cmdstanpy_run.py`. Each one times loading the model, sampling 4 chains of 1,000 warmup and 1,000 sampling iterations with `parallel_chains = 4`, and summarizing, and prints the elapsed milliseconds. The harness runs each interface once as a warm-up and then 7 rotated rounds, records the median time, the peak anonymous memory, and the peak RSS, and checks that the last round's draws are identical.

```sh
python3 verification/interfaces/bench/run.py
```

It needs Linux and the CmdStanPy virtual environment that `run.sh` creates.
