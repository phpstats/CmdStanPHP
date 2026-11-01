"""Compares the three interfaces' draws and summaries and writes site/verifications/interfaces.json."""
import csv
import datetime
import itertools
import json
import platform
import re
import subprocess
import sys
from pathlib import Path

here = Path(__file__).resolve().parent
root = here.parents[1]
names = {'cmdstanphp': 'CmdStanPHP', 'cmdstanr': 'cmdstanr', 'cmdstanpy': 'CmdStanPy'}
results = {k: json.loads((here / 'output' / k / 'summary.json').read_text()) for k in names}


def read_csv(path):
    """Returns the header comments that describe the run, and the draws as {column: [values]}."""
    config, rows = [], []
    for line in Path(path).read_text().splitlines():
        if line.startswith('#'):
            config.append(line)
        elif line:
            rows.append(line)
    reader = csv.reader(rows)
    header = next(reader)
    draws = {h: [] for h in header}
    for row in reader:
        for h, v in zip(header, row):
            draws[h].append(float(v))
    return config, draws


def chain_id(config):
    return int(next(re.search(r'id = (\d+)', c).group(1) for c in config if re.search(r'^#\s+id = ', c)))


def run_arguments(config):
    """The method and argument lines of CmdStan's header, without file paths, versions, and timing."""
    lines = []
    for c in config:
        if 'Adaptation terminated' in c or 'Step size' in c or 'Diagonal elements' in c:
            break
        text = c.lstrip('#').rstrip()
        if re.search(r'file =|stan_version|model =|stanc|start_datetime|opencl|platform|device|num_threads|sig_figs', text):
            continue
        lines.append(text)
    return lines


chains, arguments = {}, {}
for k in names:
    chains[k] = {}
    for f in results[k]['csv']:
        config, draws = read_csv(f)
        chains[k][chain_id(config)] = draws
        arguments.setdefault(k, {})[chain_id(config)] = run_arguments(config)

differences = []
for a, b in itertools.combinations(names, 2):
    worst, column = 0.0, None
    for cid in chains[a]:
        for col, values in chains[a][cid].items():
            d = max(abs(x - y) for x, y in zip(values, chains[b][cid][col]))
            if d > worst:
                worst, column = d, col
    differences.append({'interfaces': [names[a], names[b]], 'max_abs_draw_difference': worst,
                        'draws_compared': sum(len(next(iter(c.values()))) for c in chains[a].values()),
                        'columns_compared': len(next(iter(chains[a].values())))})

measures = ['mean', 'sd', 'q5', 'q95', 'rhat', 'ess_bulk']


def summary_difference(x, y):
    return {m: max(abs(a[m] - b[m]) for a, b in zip(x, y)) for m in measures}


summary_differences = [
    {'interfaces': [names[a], names[b]], 'max_abs_difference': summary_difference(results[a]['summary'], results[b]['summary'])}
    for a, b in itertools.combinations(names, 2)
]
precise_cmdstanpy = {
    names[k]: summary_difference(results[k]['summary'], results['cmdstanpy']['summary_sig_figs_12'])
    for k in ('cmdstanphp', 'cmdstanr')
}

# One case per variable and measure: CmdStanPHP's value against each peer's own summary, with CmdStanPy's at
# 12 significant figures. A case passes within ABS_TOL + REL_TOL * |cmdstanr's value|.
ABS_TOL, REL_TOL = 1e-9, 1e-9
peers = {'cmdstanr': results['cmdstanr']['summary'], 'CmdStanPy': results['cmdstanpy']['summary_sig_figs_12']}
cases = []
for i, row in enumerate(results['cmdstanphp']['summary']):
    for m in measures:
        errors = {name: abs(row[m] - rows[i][m]) for name, rows in peers.items()}
        worst = max(errors.values())
        cases.append({'name': row['variable'], 'quantity': m, 'status': 'pass' if worst <= ABS_TOL + REL_TOL * abs(peers['cmdstanr'][i][m]) else 'fail',
                      'rule': 'summary', 'n_compared': len(peers), 'max_abs_error': worst, 'peers': errors})
draw_errors = {names[b]: next(d['max_abs_draw_difference'] for d in differences if d['interfaces'] == ['CmdStanPHP', names[b]])
               for b in ('cmdstanr', 'cmdstanpy')}
draws_compared = next(d['draws_compared'] * d['columns_compared'] for d in differences)
cases.append({'name': 'draws', 'quantity': 'all CSV columns, 4 chains × 1,000 draws', 'status': 'pass' if max(draw_errors.values()) == 0 else 'fail',
              'rule': 'draws', 'n_compared': draws_compared, 'max_abs_error': max(draw_errors.values()), 'peers': draw_errors})

same_arguments = all(arguments[k] == arguments['cmdstanphp'] for k in names)
# The release tag when src/ matches it, otherwise the git description of the checkout.
tag = subprocess.run(['git', 'describe', '--tags', '--abbrev=0'], cwd=root, capture_output=True, text=True).stdout.strip()
unchanged = subprocess.run(['git', 'diff', '--quiet', tag, '--', 'src', 'composer.json'], cwd=root).returncode == 0
cmdstanphp_version = tag.lstrip('v') if unchanged else subprocess.run(
    ['git', 'describe', '--tags', '--dirty'], cwd=root, capture_output=True, text=True).stdout.strip()
code = (here / 'example.php').read_text()
if sys.platform == 'darwin':
    os_name = f"macOS {platform.mac_ver()[0]} ({platform.machine()})"
else:
    os_name = 'Linux, ' + re.search(r'^PRETTY_NAME="(.+)"', Path('/etc/os-release').read_text(), re.M).group(1) + f' ({platform.machine()})'
v = results['cmdstanr']['versions'] | results['cmdstanpy']['versions'] | results['cmdstanphp']['versions']
r_version = re.search(r'\d+\.\d+\.\d+', v['R']).group(0)

out = {
    'title': 'Mixed Gaussian (Bayesian): CmdStanPHP, cmdstanr, and CmdStanPy',
    'description': 'The non-centered eight schools model (Rubin 1981) run through three CmdStan interfaces with the same CmdStan, data, seed, chain ids, and initialization. The draws are identical, and each interface summarizes them with its own code.',
    'label': 'Other interfaces',
    'kind': 'reference',
    'group': 'Comparisons with other interfaces',
    'checked': "Draws from identical CmdStan settings, and each interface's own summary: mean, standard deviation, 5% and 95% quantiles, R-hat, and bulk ESS for mu, tau, and theta.",
    'reference': f"cmdstanr {v['cmdstanr']} with posterior {v['posterior']}, and CmdStanPy {v['cmdstanpy']} (summary at sig_figs 12), on CmdStan {v['CmdStan']}, R {r_version}, Python {v['Python']}, and PHP {v['PHP']}",
    'tolerance': 'summaries: 1e-9 + 1e-9*|cmdstanr value|; draws: exact',
    'comparison': [
        {'id': 'summary', 'applies_to': 'Summary values; larger error of cmdstanr and CmdStanPy', 'kind': 'tolerance', 'atol': ABS_TOL, 'rtol': REL_TOL},
        {'id': 'draws', 'applies_to': 'Draws; every CSV column', 'kind': 'exact'},
    ],
    'cases': cases,
    'known_differences': ["CmdStanPy's summary() rounds stansummary's output to 6 significant figures by default, so its summary is compared at sig_figs 12."],
    'reproduce': 'verification/interfaces/run.sh',
    'environment': {
        'date': datetime.date.today().isoformat(),
        'commit': subprocess.run(['git', 'rev-parse', '--short=7', 'HEAD'], cwd=root, capture_output=True, text=True).stdout.strip(),
        'php': v['PHP'],
        'os': os_name,
    },
    'versions': {
        'CmdStan': results['cmdstanphp']['versions']['CmdStan'],
        'CmdStanPHP': cmdstanphp_version,
        'cmdstanr': results['cmdstanr']['versions']['cmdstanr'],
        'posterior': results['cmdstanr']['versions']['posterior'],
        'cmdstanpy': results['cmdstanpy']['versions']['cmdstanpy'],
        'R': results['cmdstanr']['versions']['R'],
        'Python': results['cmdstanpy']['versions']['Python'],
        'PHP': results['cmdstanphp']['versions']['PHP'],
    },
    'model': (here / 'schools.stan').read_text(),
    'data': json.loads((here / 'data.json').read_text()),
    'arguments': {'chains': 4, 'parallel_chains': 4, 'chain_ids': [1, 2, 3, 4], 'iter_warmup': 1000,
                  'iter_sampling': 1000, 'seed': 20261101, 'init': 2,
                  'other': "CmdStan's defaults for everything else, including NUTS with the diag_e metric, adapt_delta 0.8, max_treedepth 10, and the CSV output precision"},
    'cmdstan_arguments_identical': same_arguments,
    'cmdstan_arguments_chain_1': arguments['cmdstanphp'][1],
    'summary_source': {
        'CmdStanPHP': "summary() with measures mean, sd, quantile2, rhat, and ess_bulk",
        'cmdstanr': 'fit$summary() (posterior package): mean, sd, quantile 0.05 and 0.95, rhat, ess_bulk',
        'CmdStanPy': "fit.summary(percentiles=(5, 95)), which runs CmdStan's stansummary: Mean, StdDev, 5%, 95%, R_hat, ESS_bulk",
    },
    'summaries': {names[k]: results[k]['summary'] for k in names},
    'draw_differences': differences,
    'summary_differences': summary_differences,
    'summary_differences_note': "CmdStanPHP's and cmdstanr's summaries agree to rounding error in double precision. CmdStanPy's summary() rounds stansummary's output to 6 significant figures by default, which accounts for its differences. With summary(sig_figs=12), its largest differences are those in summary_differences_cmdstanpy_sig_figs_12.",
    'summary_differences_cmdstanpy_sig_figs_12': precise_cmdstanpy,
    'code': code,
    'source': 'verification/interfaces',
}
(root / 'site' / 'verifications').mkdir(parents=True, exist_ok=True)
(root / 'site' / 'verifications' / 'interfaces.json').write_text(json.dumps(out, indent=2) + '\n')
print(json.dumps({'arguments_identical': same_arguments, 'draws': differences, 'summaries': summary_differences, 'cmdstanpy_sig_figs_12': precise_cmdstanpy}, indent=2))
for k in names:
    print(names[k])
    for r in results[k]['summary']:
        print(f"  {r['variable']:<9} {r['mean']:9.4f} {r['sd']:8.4f} {r['q5']:9.4f} {r['q95']:9.4f} {r['rhat']:8.5f} {r['ess_bulk']:8.1f}")
