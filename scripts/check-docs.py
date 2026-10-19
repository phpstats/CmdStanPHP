#!/usr/bin/env python3
"""Verify local links in a rendered documentation directory, including a project-site base path."""
import argparse
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlsplit

parser = argparse.ArgumentParser()
parser.add_argument('site', type=Path)
parser.add_argument('--baseurl', default='')
args = parser.parse_args()
root = args.site.resolve()
base = args.baseurl.rstrip('/')
errors = []


class Links(HTMLParser):
    def __init__(self, page):
        super().__init__()
        self.page = page

    def handle_starttag(self, tag, attrs):
        for key, value in attrs:
            if key not in ('href', 'src') or not value:
                continue
            url = urlsplit(value)
            if url.scheme or url.netloc or not url.path:
                continue
            if url.path.startswith('/'):
                if base and not url.path.startswith(base + '/') and url.path != base:
                    errors.append(f'{self.page}: link outside site base: {value}')
                    continue
                target = root / unquote(url.path[len(base):]).lstrip('/')
            else:
                target = self.page.parent / unquote(url.path)
            if target.is_dir():
                target = target / 'index.html'
            if not target.exists():
                errors.append(f'{self.page}: missing {value}')


pages = list(root.rglob('*.html'))
if not pages:
    raise SystemExit('No built documentation pages found.')
for page in pages:
    Links(page).feed(page.read_text())
if errors:
    raise SystemExit('\n'.join(errors))
print(f'Validated internal links in {len(pages)} HTML pages.')
