"""Splits the eight schools workload into phases and measures CmdStan's executable alone.

Writes site/benchmarks/interfaces-split.json. Each run is a fresh process: CmdStan alone (cmdstan_alone.py), and the
three interfaces timed by phase (phases_*.{php,R,py}), whose timed regions are those of run.py's scripts with clock
reads between the calls. One warm-up of each, then 7 rounds in rotated order, as run.py does.
"""
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


model_dir = here / 'model'
model_dir.mkdir(exist_ok=True)
shutil.copy(here.parent / 'schools.stan', model_dir / 'schools.stan')
exe = model_dir / 'schools'
if not exe.exists():
    subprocess.run(['make', str(exe)], cwd=CMDSTAN, check=True, capture_output=True)
exe_sha = hashlib.sha256(exe.read_bytes()).hexdigest()

ALONE = 'CmdStan alone'
commands = {
    ALONE: [str(python), str(here / 'cmdstan_alone.py')],
    'CmdStanPHP': ['php', str(here / 'phases_cmdstanphp.php')],
    'CmdStanR': ['Rscript', str(here / 'phases_cmdstanr.R')],
    'CmdStanPy': [str(python), str(here / 'phases_cmdstanpy.py')],
}
order = list(commands)
interfaces = order[1:]


def run(name, keep=False):
    out = tempfile.mkdtemp(prefix=f'split-{name.replace(" ", "-")}-')
    proc = subprocess.run([*commands[name], str(exe), out], capture_output=True, text=True, check=True)
    result = json.loads(proc.stdout.strip().splitlines()[-1])
    if not keep:
        shutil.rmtree(out)
    return result


def draws(files):
    by_chain = {}
    for f in files:
        text = Path(f).read_text()
        rows = [line for line in text.splitlines() if line and not line.startswith('#')]
        cid = int(re.search(r'^#\s+id = (\d+)', text, re.M).group(1))
        by_chain[cid] = [list(map(float, r)) for r in list(csv.reader(rows))[1:]]
    return by_chain


for name in order:
    run(name)  # warm-up

PHASES = ['load', 'sample', 'summary', 'ms']
times = {n: {p: [] for p in PHASES} for n in order}
child_rss = []
last = {}
for r in range(ROUNDS):
    for name in order[r % len(order):] + order[:r % len(order)]:
        result = run(name, keep=r == ROUNDS - 1)
        if name == ALONE:
            times[name]['sample'].append(round(result['ms'], 3))
            child_rss.append(round(result['child_peak_rss_mib'], 1))
        else:
            for p in PHASES:
                times[name][p].append(round(result[p], 3))
        if r == ROUNDS - 1:
            last[name] = result

d = {n: draws(last[n]['csv']) for n in order}
identical = all(d[n] == d['CmdStanPHP'] for n in order)
for n in order:
    shutil.rmtree(Path(last[n]['csv'][0]).parent)

if sys.platform == 'darwin':
    sysctl = lambda k: sh('sysctl', '-n', k)
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
commit = sh('git', 'rev-parse', '--short', 'HEAD', cwd=root)
median = lambda xs: round(statistics.median(xs), 3)
alone = median(times[ALONE]['sample'])

CASES = {
    'load': 'Load the model',
    'sample': 'Sample: write data, run 4 chains, wait for them',
    'summary': 'Summary: read the CSV files and summarize',
    'ms': 'Total',
}
rows = []
for phase, case in CASES.items():
    for n in interfaces:
        row = {'case': case, 'implementation': n, 'value': median(times[n][phase]), 'reps': times[n][phase]}
        if phase == 'sample':
            row['note'] = f'{round(row["value"] - alone, 1)} ms more than CmdStan alone'
        if phase == 'ms':
            row['note'] = f'{round(row["value"] - alone, 1)} ms outside CmdStan\'s executable'
        rows.append(row)
    if phase == 'sample':
        rows.append({'case': case, 'implementation': ALONE, 'value': alone, 'reps': times[ALONE]['sample'],
                     'child_peak_rss_mib': round(statistics.median(child_rss), 1),
                     'note': 'The 4 chain processes only, started together with CmdStanPHP\'s arguments'})

record = {
    'title': 'Where the time goes: eight schools in CmdStanPHP, CmdStanR, and CmdStanPy',
    'label': 'Time by phase',
    'description': 'The eight schools workload of the interface benchmark, timed in three phases inside each interface, beside the same 4 chains run by CmdStan\'s executable with no interface. The sample phase includes the chains, so the difference between an interface\'s sample phase and CmdStan alone is the time the interface spends writing the data, starting the processes, and waiting for them.',
    'workload': 'Eight schools, 4 chains × 2,000 iterations',
    'implementations': {
        ALONE: 'CmdStan\'s compiled executable, 4 processes started together from Python with the arguments CmdStanPHP passes',
        'CmdStanPHP': 'CmdStanPHP, plain PHP (no phit)',
        'CmdStanR': 'cmdstanr with posterior',
        'CmdStanPy': 'CmdStanPy',
    },
    'measure': 'Elapsed time',
    'unit': 'ms',
    'statistic': 'median',
    'time_spent_in': 'Load and Summary: the interface process. Sample: CmdStan\'s executable, launched by the interface, plus the interface\'s own work. CmdStan alone: CmdStan\'s executable only.',
    'method': f'Each run is a fresh process. Each runs once as a warm-up, then the four alternate for {ROUNDS} rounds in a rotated order. Inside each interface the clock is read before loading the precompiled model, after it, after sample() returns, and after the summary; the phases are the differences, and Total runs from the first read to the last, as in the interface benchmark. Starting the runtime and loading libraries are outside the timing. CmdStan alone writes the data file before its clock starts and stops the clock when the last of the 4 chains exits. The summaries differ by interface as in the interface benchmark: CmdStanPy\'s runs CmdStan\'s stansummary.',
    'memory_method': 'child_peak_rss_mib, for CmdStan alone only: the largest peak resident set size among the 4 chain processes, from getrusage(RUSAGE_CHILDREN).',
    'check': {'draws_identical': identical},
    'environment': {
        **machine,
        'php': sh('php', '-r', 'echo PHP_VERSION;'),
        'versions': {
            'CmdStan': Path(CMDSTAN).name.removeprefix('cmdstan-'),
            'CmdStanPHP': sh('git', 'describe', '--tags', '--dirty', cwd=root),
            'cmdstanr': sh('Rscript', '-e', 'cat(as.character(packageVersion("cmdstanr")))'),
            'posterior': sh('Rscript', '-e', 'cat(as.character(packageVersion("posterior")))'),
            'cmdstanpy': sh(str(python), '-c', 'import cmdstanpy; print(cmdstanpy.__version__)'),
            'R': sh('Rscript', '-e', 'cat(paste(R.version$major, R.version$minor, sep = "."))'),
            'Python': sh(str(python), '-c', 'import platform; print(platform.python_version())'),
        },
        'threads': {'chains': 4},
        'cache': 'warm',
        'compilation': 'Outside the timing. The model is compiled once with CmdStan\'s makefile, and every run loads that executable (sha256 ' + exe_sha[:12] + ').',
        'repeats': ROUNDS,
        'warmups': 1,
        'date': datetime.date.today().isoformat(),
        'commit': commit,
    },
    'rows': rows,
    'reproduce': 'python3 verification/interfaces/bench/split.py',
    'source': 'verification/interfaces/bench/split.py',
}
(root / 'site' / 'benchmarks' / 'interfaces-split.json').write_text(json.dumps(record, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({'check': record['check'], 'rows': [(r['case'], r['implementation'], r['value']) for r in rows]}, indent=1, ensure_ascii=False))
