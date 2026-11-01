#!/bin/sh
# Runs the eight schools model through CmdStanPHP, cmdstanr, and CmdStanPy, then compares them.
set -eu
cd "$(dirname "$0")"
export CMDSTAN="${CMDSTAN:-$HOME/.cmdstan/cmdstan-2.40.0}"
rm -rf output
php cmdstanphp.php
Rscript cmdstanr.R
if [ ! -x .venv/bin/python ]; then
    uv venv .venv
    uv pip install --python .venv/bin/python cmdstanpy==1.3.0
fi
.venv/bin/python cmdstanpy_run.py
python3 compare.py
