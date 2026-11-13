#!/usr/bin/env python3
"""Read-only ExecCondition for the small shared host; 1 means skip this tick."""
import json
from pathlib import Path
import subprocess

MIN_AVAILABLE_KIB = 384 * 1024
PRODUCTION_UNIT = 'h5-media-process.service'


def decision(state, available_kib):
    if state not in ('inactive', 'failed'):
        return 'production_busy_or_unknown'
    if available_kib < MIN_AVAILABLE_KIB:
        return 'memory_low'
    return 'eligible'


def main():
    try:
        status = subprocess.run(
            ['/usr/bin/systemctl', 'show', '--property=LoadState', '--property=ActiveState', PRODUCTION_UNIT],
            capture_output=True, text=True, timeout=3, check=True)
        fields = dict(line.split(':', 1) for line in Path('/proc/meminfo').read_text().splitlines())
        number, unit = fields['MemAvailable'].split()
        available = int(number)
        if unit != 'kB' or available < 0:
            raise ValueError
        properties = dict(line.split('=', 1) for line in status.stdout.splitlines() if '=' in line)
        state = properties.get('ActiveState', '') if properties.get('LoadState') == 'loaded' else ''
        reason = decision(state, available)
        print(json.dumps({'staging_media_capacity': reason, 'available_kib': available,
                          'minimum_kib': MIN_AVAILABLE_KIB}), flush=True)
        return 0 if reason == 'eligible' else 1
    except (OSError, KeyError, ValueError, subprocess.SubprocessError):
        print('{"staging_media_capacity":"unavailable_skip"}', flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
