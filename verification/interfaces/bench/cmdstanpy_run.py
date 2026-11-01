"""One timed run: model from the precompiled executable, 4 parallel chains, summary. Prints the elapsed milliseconds."""
import json
import logging
import sys
import time

import cmdstanpy

logging.getLogger('cmdstanpy').disabled = True
exe, out = sys.argv[1], sys.argv[2]
data = {'J': 8, 'y': [28, 8, -3, 7, -1, 1, 18, 12], 'sigma': [15, 10, 16, 11, 9, 11, 10, 18]}

start = time.perf_counter()
model = cmdstanpy.CmdStanModel(exe_file=exe)
fit = model.sample(
    data=data,
    seed=20261101,
    chains=4,
    parallel_chains=4,
    iter_warmup=1000,
    iter_sampling=1000,
    output_dir=out,
    show_progress=False,
)
summary = fit.summary(percentiles=(5, 95))
elapsed = (time.perf_counter() - start) * 1000

print(json.dumps({'ms': elapsed, 'csv': fit.runset.csv_files, 'mu': float(summary.loc['mu', 'Mean'])}))
