#!/usr/bin/env python3
"""Stop only the two recorded processes of this checkout's temporary callback window."""
import argparse
import json
from pathlib import Path
import os
import signal
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]


def matching(pid, token, started_at):
    if not isinstance(pid, int) or pid <= 1:
        return False
    result = subprocess.run(['ps', '-p', str(pid), '-o', 'command='], capture_output=True, text=True)
    start = subprocess.run(['ps', '-p', str(pid), '-o', 'lstart='], capture_output=True, text=True)
    return result.returncode == 0 and token in result.stdout and start.returncode == 0 and start.stdout.strip() == started_at


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--stop', action='store_true', help='Without this flag, only report matching owned processes.')
    args = parser.parse_args()
    path = ROOT / '.runtime/paypal-sandbox/external-processes.json'
    if path.is_symlink():
        raise SystemExit('Unsafe process record')
    record = json.loads(path.read_text())
    if record['root'] != str(ROOT):
        raise SystemExit('Wrong workspace')
    marker = json.loads((ROOT / '.runtime/paypal-sandbox/external-probe-marker.json').read_text())['transmission_id']
    targets = [('tunnel', record['tunnel_pid'], str(ROOT / '.runtime/tools/cloudflared-2026.10.4')),
               ('bridge', record['bridge_pid'], 'scripts/ops/tests/paypal_external_observer.py --synthetic-id '+marker)]
    results = []
    for name, pid, token in targets:
        started_at = record['process_started_at'][name+'_pid']
        owned = matching(pid, token, started_at)
        if args.stop and owned:
            os.kill(pid, signal.SIGTERM)
            for _ in range(50):
                if not matching(pid, token, started_at):
                    break
                time.sleep(0.1)
        results.append({'process':name,'pid':pid,'matched_before':owned,'running_after':matching(pid,token,started_at)})
    print(json.dumps({'stop_requested':args.stop,'processes':results}))


if __name__ == '__main__':
    main()
