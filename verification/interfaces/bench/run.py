"""Times the eight schools workload in CmdStanPHP, cmdstanr, and CmdStanPy and writes site/benchmarks/interfaces-workload.json."""
import csv
import datetime
import hashlib
import json
import os
import platform
import re
import shutil
import statistics
import subprocess
import sys
import tempfile
from pathlib import Path

here = Path(__file__).resolve().parent
root = here.parents[2]
ROUNDS = 7
CMDSTAN = os.environ.setdefault('CMDSTAN', str(Path.home() / '.cmdstan' / 'cmdstan-2.40.0'))
python = here.parent / '.venv' / 'bin' / 'python'


def sh(*cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, check=True, **kw).stdout.strip()


# Compile once with CmdStan's makefile. Every interface loads this executable, so compilation is outside the timing.
model_dir = here / 'model'
model_dir.mkdir(exist_ok=True)
shutil.copy(here.parent / 'schools.stan', model_dir / 'schools.stan')
exe = model_dir / 'schools'
if not exe.exists():
    subprocess.run(['make', str(exe)], cwd=CMDSTAN, check=True, capture_output=True)
exe_sha = hashlib.sha256(exe.read_bytes()).hexdigest()

commands = {
    'CmdStanPHP': ['php', str(here / 'cmdstanphp.php')],
    'CmdStanR': ['Rscript', str(here / 'cmdstanr.R')],
    'CmdStanPy': [str(python), str(here / 'cmdstanpy_run.py')],
}
order = list(commands)
DARWIN = sys.platform == 'darwin'
TIME = '/usr/bin/time' if DARWIN else 'peakmem'


def run(name, keep=False):
    out = tempfile.mkdtemp(prefix=f'bench-{name}-')
    # bench/peakmem.c reports on Linux what /usr/bin/time -l reports on macOS (cc -O2 -o peakmem peakmem.c, on the PATH).
    proc = subprocess.run([TIME, '-l', *commands[name], str(exe), out], capture_output=True, text=True, check=True)
    result = json.loads(proc.stdout.strip().splitlines()[-1])
    footprint = int(re.search(r'(\d+)\s+peak memory footprint', proc.stderr).group(1))
    rss = int(re.search(r'(\d+)\s+maximum resident set size', proc.stderr).group(1))
    if not keep:
        shutil.rmtree(out)
    return result, footprint / 2**20, rss / 2**20


def draws(files):
    by_chain = {}
    for f in files:
        rows = [line for line in Path(f).read_text().splitlines() if line and not line.startswith('#')]
        text = Path(f).read_text()
        cid = int(re.search(r'^#\s+id = (\d+)', text, re.M).group(1))
        by_chain[cid] = [list(map(float, r)) for r in list(csv.reader(rows))[1:]]
    return by_chain


for name in order:
    run(name)  # warm-up

times = {n: [] for n in order}
footprints = {n: [] for n in order}
rss = {n: [] for n in order}
last = {}
for r in range(ROUNDS):
    for name in order[r % len(order):] + order[:r % len(order)]:
        result, fp, rs = run(name, keep=r == ROUNDS - 1)
        times[name].append(round(result['ms'], 3))
        footprints[name].append(round(fp, 1))
        rss[name].append(round(rs, 1))
        if r == ROUNDS - 1:
            last[name] = result

d = {n: draws(last[n]['csv']) for n in order}
identical = all(d[n] == d['CmdStanPHP'] for n in order)
max_diff = max(abs(a - b) for n in order for c in d[n] for ra, rb in zip(d[n][c], d['CmdStanPHP'][c]) for a, b in zip(ra, rb))
for n in order:
    shutil.rmtree(Path(last[n]['csv'][0]).parent)


def dedent_block(path, start, end):
    lines = Path(path).read_text().splitlines()
    i = next(k for k, l in enumerate(lines) if start in l)
    j = next(k for k, l in enumerate(lines) if end in l and k > i)
    return '\n'.join(lines[i + 1:j]).strip()


code = {
    'CmdStanPHP': dedent_block(here / 'cmdstanphp.php', '$start = hrtime', '$elapsed ='),
    'CmdStanR': dedent_block(here / 'cmdstanr.R', 'start <- Sys.time', 'elapsed <-'),
    'CmdStanPy': dedent_block(here / 'cmdstanpy_run.py', 'start = time.perf_counter', 'elapsed ='),
}

sysctl = lambda k: sh('sysctl', '-n', k)
if DARWIN:
    machine = {
        'cpu': sysctl('machdep.cpu.brand_string'),
        'cores': int(sysctl('hw.ncpu')),
        'memory_gb': round(int(sysctl('hw.memsize')) / 2**30),
        'os': f"macOS {platform.mac_ver()[0]} ({platform.machine()})",
    }
else:
    machine = {
        'cpu': re.search(r'^model name\s*:\s*(.+)$', Path('/proc/cpuinfo').read_text(), re.M).group(1).strip(),
        'cores': os.cpu_count(),
        'memory_gb': round(int(re.search(r'^MemTotal:\s*(\d+)', Path('/proc/meminfo').read_text(), re.M).group(1)) / 2**20),
        'os': 'Linux, ' + re.search(r'^PRETTY_NAME="(.+)"', Path('/etc/os-release').read_text(), re.M).group(1) + f' ({platform.machine()})',
    }
tag = sh('git', 'describe', '--tags', '--abbrev=0', cwd=root)
unchanged = subprocess.run(['git', 'diff', '--quiet', tag, '--', 'src', 'composer.json'], cwd=root).returncode == 0
case = 'Eight schools, 4 chains × 2,000 iterations'
record = {
    'title': 'Mixed Gaussian (Bayesian) workload: CmdStanPHP, CmdStanR, and CmdStanPy',
    'label': 'Eight schools',
    'description': "Sampling of the non-centered eight schools model with 4 parallel chains of 1,000 warmup and 1,000 sampling iterations, followed by a summary of the draws, in CmdStanPHP, CmdStanR, and CmdStanPy. All three run the same compiled CmdStan executable with the same arguments and produce identical draws.",
    'measure': 'Elapsed time',
    'unit': 'ms',
    'statistic': 'median',
    'workload': case,
    'memory_measure': 'peak memory footprint' if DARWIN else 'peak anonymous memory',
    'environment': {
        **machine,
        'php': sh('php', '-r', 'echo PHP_VERSION;'),
        'versions': {
            'CmdStan': Path(CMDSTAN).name.removeprefix('cmdstan-'),
            'CmdStanPHP': tag.lstrip('v') if unchanged else sh('git', 'describe', '--tags', '--dirty', cwd=root),
            'cmdstanr': sh('Rscript', '-e', 'cat(as.character(packageVersion("cmdstanr")))'),
            'posterior': sh('Rscript', '-e', 'cat(as.character(packageVersion("posterior")))'),
            'cmdstanpy': sh(str(python), '-c', 'import cmdstanpy; print(cmdstanpy.__version__)'),
            'R': sh('Rscript', '-e', 'cat(paste(R.version$major, R.version$minor, sep = "."))'),
            'Python': sh(str(python), '-c', 'import platform; print(platform.python_version())'),
        },
        'parallel_chains': 4,
        'compilation': 'Outside the timing. The model is compiled once with CmdStan\'s makefile before any run, and every interface loads that executable (sha256 ' + exe_sha[:12] + ') with its exe_file argument.',
        'repeats': ROUNDS,
        'date': datetime.date.today().isoformat(),
        'commit': sh('git', 'rev-parse', '--short', 'HEAD', cwd=root),
    },
    'method': f'Each run is a fresh process. Each interface runs once as a warm-up, then the three alternate for {ROUNDS} rounds in a rotated order. The time runs from the interface call that loads the precompiled model to holding the summary: it covers loading the model, writing the data (held in memory in the runtime\'s usual structure) to JSON, running 4 chains in parallel as 4 CmdStan processes (parallel_chains 4 everywhere) with seed 20261101, reading the CSV output, and the summary (CmdStanPHP summary() and cmdstanr $summary() with mean, sd, 5% and 95% quantiles, R-hat, and bulk ESS for mu, tau, and theta; CmdStanPy summary(), which runs CmdStan\'s stansummary for every column). Starting the runtime and loading the interface\'s libraries are outside the timing. ' + ('Every process runs under /usr/bin/time -l. Memory is the interface process\'s peak memory footprint as macOS reports it: its own allocations, without the clean file pages it maps and without the CmdStan chain processes.' if DARWIN else 'Every process runs under bench/peakmem.c. Memory is the interface process\'s peak anonymous memory (RssAnon in /proc, sampled every millisecond): its own allocations, without the file pages it maps and without the CmdStan chain processes.') + ' Peak resident set size, which counts those pages, is reported separately.',
    'code_commit': sh('git', 'rev-parse', '--short', 'HEAD', cwd=root),
    'check': {'draws_identical': identical, 'max_abs_draw_difference': max_diff,
              'posterior_mean_mu': {n: last[n]['mu'] for n in order}},
    'code': code,
    'languages': {'CmdStanPHP': 'php', 'CmdStanR': 'r', 'CmdStanPy': 'python'},
    'rows': [{
        'case': case,
        'implementation': n,
        'value': round(statistics.median(times[n]), 3),
        'reps': times[n],
        'peak_memory_mib': round(statistics.median(footprints[n]), 1),
        'reps_peak_memory_mib': footprints[n],
        'peak_rss_mib': round(statistics.median(rss[n]), 1),
        'reps_peak_rss_mib': rss[n],
    } for n in order],
}
(root / 'site' / 'benchmarks').mkdir(parents=True, exist_ok=True)
(root / 'site' / 'benchmarks' / 'interfaces-workload.json').write_text(json.dumps(record, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({'check': record['check'], 'rows': record['rows']}, indent=1))
