#!/usr/bin/env python3
"""Render a candidate config, never install or reload a service."""
import argparse,re
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--domain',required=True);p.add_argument('--output',required=True,type=Path);a=p.parse_args()
if not re.fullmatch(r'(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}',a.domain):p.error('Use a plain lower-case DNS hostname, no URL or wildcard')
root=Path(__file__).resolve().parents[2]
source=(root/'deploy/nginx-staging.conf.template').read_text()
with a.output.open('x') as f:f.write(source.replace('__DOMAIN__',a.domain))
print('Candidate written; nginx -t and real negative network checks are still required.')
