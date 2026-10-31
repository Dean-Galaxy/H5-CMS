#!/usr/bin/env python3
"""Offline candidate renderer. Never installs, starts, reloads, or contacts a service."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import tempfile
import sys

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = {'tls': 'nginx-staging.conf.template',
             'acme-bootstrap': 'nginx-acme-bootstrap.conf.template'}


class InputRefused(ValueError):
    """Fixed diagnostic only; do not interpolate input values."""


class OfflineArgumentParser(argparse.ArgumentParser):
    def error(self, message):
        # argparse's default diagnostic echoes arbitrary CLI values.
        print(json.dumps({'mode': 'static-only', 'error': 'ARGUMENTS_INVALID',
                          'deployment_ready': False}), file=sys.stderr)
        self.exit(2)


def domain_name(value):
    if not isinstance(value, str) or len(value) > 253 or not re.fullmatch(
        r'(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}', value
    ) or value.endswith('.localhost'):
        raise InputRefused('DOMAIN_INVALID')
    try:
        ipaddress.ip_address(value)
    except ValueError:
        return value
    raise InputRefused('DOMAIN_INVALID')


def safe_parent(path):
    """Open every parent with no-follow; keep a dir fd for the final operation."""
    path = Path(path)
    if '..' in path.parts or any(ord(c) < 32 for c in str(path)):
        raise InputRefused('PATH_INVALID')
    path = path.absolute()
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in path.parent.parts[1:]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
        return path, fd
    except BaseException:
        os.close(fd)
        raise


def render(domain, phase='tls'):
    domain = domain_name(domain)
    if phase not in TEMPLATES:
        raise InputRefused('PHASE_INVALID')
    return (ROOT / 'deploy' / TEMPLATES[phase]).read_text().replace('__DOMAIN__', domain)


def write_candidate(output, text):
    path = Path(output)
    if '..' in path.parts or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,100}\.conf', path.name):
        raise InputRefused('OUTPUT_PATH_INVALID')
    absolute = path.absolute()
    # Artifacts only: do not permit rendering into host installation or public/source paths.
    if absolute.is_relative_to(ROOT) and not absolute.is_relative_to(ROOT / '.runtime'):
        raise InputRefused('OUTPUT_OUTSIDE_ARTIFACT_AREA')
    allowed = (ROOT / '.runtime', Path('/private/tmp'), Path(tempfile.gettempdir()).resolve())
    if not any(absolute.is_relative_to(root) for root in allowed):
        raise InputRefused('OUTPUT_OUTSIDE_ARTIFACT_AREA')
    path, parent = safe_parent(path)
    try:
        fd = os.open(path.name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                     0o600, dir_fd=parent)
        with os.fdopen(fd, 'w') as stream:
            stream.write(text)
    finally:
        os.close(parent)


def main(argv=None):
    parser = OfflineArgumentParser(description=__doc__)
    parser.add_argument('--domain', required=True)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--phase', choices=TEMPLATES, default='tls')
    args = parser.parse_args(argv)
    try:
        write_candidate(args.output, render(args.domain, args.phase))
    except InputRefused as error:
        print(json.dumps({'mode': 'static-only', 'rendered': False, 'error': str(error)}))
        return 2
    except (OSError, UnicodeError):
        print(json.dumps({'mode': 'static-only', 'rendered': False, 'error': 'CANDIDATE_FILE_REFUSED'}))
        return 2
    print(json.dumps({'mode': 'static-only', 'rendered': True, 'phase': args.phase,
                      'host_checks': 'not-run', 'deployment_ready': False}))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
