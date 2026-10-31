#!/usr/bin/env python3
"""Write the GitHub Pages site: one redirect per docs page to the same page on phpstats.org."""
import argparse
import html
import re
from pathlib import Path

TARGET = 'https://cmdstanphp.phpstats.org/docs'

parser = argparse.ArgumentParser()
parser.add_argument('docs', type=Path)
parser.add_argument('out', type=Path)
args = parser.parse_args()


def page(url):
    escaped = html.escape(url, quote=True)
    return f'''<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>CmdStanPHP documentation has moved</title>
<link rel="canonical" href="{escaped}">
<meta http-equiv="refresh" content="0; url={escaped}">
<script>location.replace({url!r} + location.hash);</script>
</head>
<body>
<p>The CmdStanPHP documentation is now at <a href="{escaped}">{escaped}</a>.</p>
</body>
</html>
'''


def target(name):
    return TARGET if name == 'index' else f'{TARGET}/{name}'


args.out.mkdir(parents=True, exist_ok=True)
written = []
for source in sorted(args.docs.glob('*.html')):
    name = source.stem
    if (args.docs / f'{name}.qmd').exists():
        url = target(name)
    else:
        # Quarto alias pages, such as inference.html, redirect to the page that replaced them.
        match = re.search(r'var redirects = \{"":"([\w-]+)\.html"\}', source.read_text())
        if not match:
            continue
        url = target(match.group(1))
    (args.out / source.name).write_text(page(url))
    written.append(f'{source.name} -> {url}')
(args.out / '404.html').write_text(page(TARGET))
(args.out / '.nojekyll').write_text('')
print('\n'.join(written))
