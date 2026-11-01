"""Fits the model with CmdStanPy and writes its summary to output/cmdstanpy/summary.json."""
import json
import os
import platform
import shutil
from pathlib import Path

import cmdstanpy

here = Path(__file__).resolve().parent
out = here / 'output' / 'cmdstanpy'
out.mkdir(parents=True, exist_ok=True)
shutil.copy(here / 'schools.stan', out / 'schools.stan')

model = cmdstanpy.CmdStanModel(stan_file=str(out / 'schools.stan'))
fit = model.sample(
    data=str(here / 'data.json'),
    seed=20261101,
    chains=4,
    parallel_chains=4,
    chain_ids=[1, 2, 3, 4],
    iter_warmup=1000,
    iter_sampling=1000,
    inits=2,
    output_dir=str(out),
    show_progress=False,
)

variables = ['mu', 'tau'] + [f'theta[{j}]' for j in range(1, 9)]


def rows(s):
    return [{'variable': v, 'mean': float(s.loc[v]['Mean']), 'sd': float(s.loc[v]['StdDev']),
             'q5': float(s.loc[v]['5%']), 'q95': float(s.loc[v]['95%']), 'rhat': float(s.loc[v]['R_hat']),
             'ess_bulk': float(s.loc[v]['ESS_bulk'])} for v in variables]


# summary() rounds stansummary's output to 6 significant figures by default. The 12-figure run checks that the
# differences from the other interfaces are only that rounding.
default = rows(fit.summary(percentiles=(5, 95)))
precise = rows(fit.summary(percentiles=(5, 95), sig_figs=12))
(out / 'summary.json').write_text(json.dumps({
    'versions': {'cmdstanpy': cmdstanpy.__version__, 'CmdStan': cmdstanpy.cmdstan_version(),
                 'Python': platform.python_version()},
    'csv': fit.runset.csv_files,
    'summary': default,
    'summary_sig_figs_12': precise,
}, indent=2, default=str))
