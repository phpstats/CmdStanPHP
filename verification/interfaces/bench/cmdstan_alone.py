"""Runs the 4 chains of the workload as CmdStanPHP launches them, with no interface: the same executable and
arguments, started together. The data file is written before the clock starts. Prints the milliseconds from starting
the first process to the exit of the last, and the largest chain's peak resident set size."""
import json
import os
import resource
import subprocess
import sys
import time

exe, out = sys.argv[1], sys.argv[2]
data = os.path.join(out, 'data.json')
with open(data, 'w') as f:
    json.dump({'J': 8, 'y': [28, 8, -3, 7, -1, 1, 18, 12], 'sigma': [15, 10, 16, 11, 9, 11, 10, 18]}, f)
commands = [[exe, 'sample', 'num_samples=1000', 'num_warmup=1000', 'algorithm=hmc', 'engine=nuts', 'adapt',
             f'id={c}', 'data', f'file={data}', 'random', 'seed=20261101', 'output',
             f'file={os.path.join(out, f"schools-{c}.csv")}', f'profile_file={os.path.join(out, f"profile-{c}.csv")}']
            for c in (1, 2, 3, 4)]

start = time.perf_counter()
processes = [subprocess.Popen(c, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) for c in commands]
codes = [p.wait() for p in processes]
elapsed = (time.perf_counter() - start) * 1000
if any(codes):
    sys.exit(f'a chain exited with {codes}')
# ru_maxrss of RUSAGE_CHILDREN is the largest peak among the waited-for children: KiB on Linux, bytes on macOS.
peak = resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss
peak_mib = peak / 2**20 if sys.platform == 'darwin' else peak / 2**10
print(json.dumps({'ms': elapsed, 'child_peak_rss_mib': peak_mib,
                  'csv': [os.path.join(out, f'schools-{c}.csv') for c in (1, 2, 3, 4)]}))
