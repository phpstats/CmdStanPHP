"""The timed region of cmdstanpy_run.py, split into phases. Prints milliseconds per phase."""
import json
import logging
import sys
import time

import cmdstanpy

logging.getLogger('cmdstanpy').disabled = True
exe, out = sys.argv[1], sys.argv[2]
data = {'J': 8, 'y': [28, 8, -3, 7, -1, 1, 18, 12], 'sigma': [15, 10, 16, 11, 9, 11, 10, 18]}

t0 = time.perf_counter()
model = cmdstanpy.CmdStanModel(exe_file=exe)
t1 = time.perf_counter()
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
t2 = time.perf_counter()
summary = fit.summary(percentiles=(5, 95))
t3 = time.perf_counter()

ms = lambda a, b: (b - a) * 1000
print(json.dumps({'load': ms(t0, t1), 'sample': ms(t1, t2), 'summary': ms(t2, t3), 'ms': ms(t0, t3),
                  'csv': fit.runset.csv_files, 'mu': float(summary.loc['mu', 'Mean'])}))
