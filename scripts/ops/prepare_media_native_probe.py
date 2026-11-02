#!/usr/bin/env python3
"""Generate/review ONE fixed synthetic native probe package. No execution option."""
import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile

REPO = Path(__file__).resolve().parents[2]
BASE = '/srv/h5-native-probe-20261102'
CONTROL = BASE+'/control'
NAME = 'h5-media-native-probe-20261102'
FAILURE = NAME+'-failure.service'
SOURCE_COMMIT = '9bd8ad8da43083985ed1c907508d2d46f3bb741f'
SOURCES = {
    'h5-media-process.service': '32bd40d40c63d4cb56538ad316a6e9ba57c6a2b0abf0ec49877e928dbd6b0ab8',
    'h5-media-process.timer': '2ff5f436317a8c39ed2bb46faf01967f447f4c587e8cd7cddcbd5c5a0b5fc86f',
    'h5-media-stop-on-failure.service': 'fb9beca09f5473a346255111561a2fecb0a3ebd907c9128e216230cb7f84331a',
}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def prepare(source=None):
    if source is None:
        source = {name: (REPO/'deploy'/name).read_bytes() for name in SOURCES}
    if set(source) != set(SOURCES) or any(sha(source[n]) != h for n, h in SOURCES.items()):
        raise ValueError('REVIEWED_SOURCE_SHA_MISMATCH')
    transformations, rendered = [], {}
    for filename, raw in source.items():
        text = '\n'.join(line for line in raw.decode().splitlines() if not line.lstrip().startswith('#'))+'\n'
        rules = []

        def replace(old, new, count=1):
            nonlocal text
            actual = text.count(old)
            if actual != count:
                raise ValueError('FIXED_TRANSFORM_COUNT_MISMATCH')
            text = text.replace(old, new)
            rules.append({'old': old, 'new': new, 'count': actual})

        count = text.count('/srv/h5/ops/media-process')
        replace('/srv/h5/ops/media-process', CONTROL, count)
        if filename == 'h5-media-process.service':
            replace('Description=H5 single-item media processing (explicit long-term permission required)',
                    'Description=Synthetic media native probe (no application)')
            replace('After=postgresql@17-main.service\n', '')
            replace('OnFailure=h5-media-stop-on-failure.service', 'OnFailure='+FAILURE)
            replace('WorkingDirectory=/srv/h5/current/h5-platform', 'WorkingDirectory='+BASE+'/work')
            replace('ExecCondition=/usr/bin/systemctl --quiet is-active postgresql@17-main.service',
                    'ExecCondition=/usr/bin/python3 -I '+BASE+'/probe.py condition')
            replace('ExecStartPre=/usr/bin/php8.4 -d memory_limit=128M /srv/h5/current/scripts/ops/check_runtime.php --expect-env=staging --require-cache',
                    'ExecStartPre=!/usr/bin/python3 -I '+BASE+'/probe.py root-check\n'
                    'ExecStartPre=/usr/bin/python3 -I '+BASE+'/probe.py bootstrap')
            replace('ExecStart=/usr/bin/php8.4 -d memory_limit=128M artisan content:process-media --limit=1',
                    'ExecStart=/usr/bin/python3 -I '+BASE+'/probe.py cli')
            replace('ReadWritePaths=/srv/h5/shared/media /srv/h5/shared/storage/logs '+CONTROL,
                    'ReadWritePaths='+BASE+'/media '+BASE+'/logs '+CONTROL)
            replace('TimeoutStartSec=15min', 'TimeoutStartSec=8s')
            replace('TimeoutStopSec=5s', 'TimeoutStopSec=2s')
            replace('MemoryMax=384M', 'MemoryMax=96M\nMemorySwapMax=0')
            replace('CPUQuota=50%', 'CPUQuota=25%')
            replace('RestrictAddressFamilies=AF_UNIX AF_INET', 'RestrictAddressFamilies=AF_UNIX')
            replace('IPAddressAllow=127.0.0.1\n', '')
            replace('SyslogIdentifier=h5-media-process', 'SyslogIdentifier='+NAME)
            target = NAME+'.service'
        elif filename.endswith('.timer'):
            replace('Description=H5 media-only polling (disabled by default)', 'Description=One-shot synthetic native probe timer')
            replace('OnCalendar=*-*-* *:*:00 UTC', 'OnActiveSec=3s')
            replace('Unit=h5-media-process.service', 'Unit='+NAME+'.service')
            replace('[Install]\nWantedBy=timers.target\n', '')
            target = NAME+'.timer'
        else:
            replace('Description=Disarm only the H5 media timer on failure', 'Description=Disarm only the synthetic probe timer')
            replace('ExecStart=/usr/bin/rm -f -- '+CONTROL+'/ENABLED',
                    'ExecStart=/usr/bin/python3 -I '+BASE+'/probe.py failure-check\n'
                    'ExecStart=/usr/bin/rm -f -- '+CONTROL+'/ENABLED')
            replace('stop h5-media-process.timer', 'stop '+NAME+'.timer')
            replace('TimeoutStartSec=15s', 'TimeoutStartSec=5s\nMemoryMax=64M\nMemorySwapMax=0\nCPUQuota=25%')
            replace('TimeoutStopSec=5s', 'TimeoutStopSec=2s')
            replace('SyslogIdentifier=h5-media-stop-on-failure', 'SyslogIdentifier='+NAME+'-failure')
            target = FAILURE
        for forbidden in ('/srv/h5/current', '/srv/h5/shared', '/srv/h5/ops/media-process',
                          'postgresql@', 'php8.4', 'artisan', '[Install]', 'WantedBy='):
            if forbidden in text:
                raise ValueError('PRODUCTION_REFERENCE_IN_PROBE')
        rendered[target] = text
        transformations.append({'source': filename, 'target': target, 'comments_removed': True, 'rules': rules})
    # Exactly one negative: deterministic missing fsync operand, not an ambiguous permission error.
    service = rendered[NAME+'.service']
    pre = [line for line in service.splitlines() if line.startswith('ExecStartPre=')]
    old = '!/usr/bin/sync -- '+CONTROL+'/RUNNING '+CONTROL
    bad = '!/usr/bin/sync -- '+CONTROL+'/ABSENT '+CONTROL
    if sum(old in line for line in pre) != 1:
        raise ValueError('SYNC_NEGATIVE_TRANSFORM_INVALID')
    rendered['sync-negative.conf'] = '[Service]\nExecStartPre=\n'+'\n'.join(line.replace(old, bad) for line in pre)+'\n'
    payload = (REPO/'scripts/ops/media_native_probe_payload.py').read_text()
    ast.parse(payload)
    rendered['probe.py'] = payload
    rendered['README.md'] = (REPO/'docs/handoffs/06-媒体原生自检包四组操作单-20261102.md').read_text()
    manifest = {'source_commit': SOURCE_COMMIT, 'source_sha256': SOURCES, 'namespace': NAME, 'host_root': BASE,
                'mode': 'generate-only; no installation or execution authority', 'groups': 4,
                'planned_cli_entries': 5, 'enforced_cli_limit': 12, 'one_shot_timer_delay_s': 3,
                'payload_window_s': 900, 'operator_total_window_max_s': 1200,
                'service_start_timeout_s': 8, 'service_stop_timeout_s': 2,
                'memory_limits_mib': {'main_cgroup': 96, 'failure_cgroup': 64}, 'oom_injection': False,
                'hardware_power_loss_or_host_restart': False, 'transformations': transformations,
                'negative_variant': {'file': 'sync-negative.conf', 'only_changed_operand': [old, bad]},
                'files_sha256': {n: sha(t.encode()) for n, t in rendered.items()}}
    rendered['manifest.json'] = json.dumps(manifest, indent=2)+'\n'
    rendered['SHA256SUMS'] = ''.join(sha(text.encode())+'  '+name+'\n' for name, text in rendered.items())
    return rendered


def generate(output):
    files = prepare()  # All source validation before creating an output directory.
    output = Path(output).absolute().resolve()
    if output.is_relative_to(REPO) and not output.is_relative_to(REPO/'.runtime'):
        raise ValueError('LOCAL_REVIEW_OUTPUT_REQUIRED')
    allowed = [(REPO/'.runtime').resolve(), Path(tempfile.gettempdir()).resolve()]
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,100}', output.name) or not any(output.is_relative_to(p) for p in allowed):
        raise ValueError('LOCAL_REVIEW_OUTPUT_REQUIRED')
    if not output.parent.is_dir():
        raise ValueError('REVIEW_PARENT_REQUIRED')
    output.mkdir(mode=0o700)  # Exclusive: no overwrite/refresh/re-run mode.
    for name, text in files.items():
        fd = os.open(output/name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write(text)
    return files


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args(argv)
    if args.output is None:
        print(json.dumps({'mode': 'review-only', 'generated': False, 'executed': False}))
        return 0
    try:
        files = generate(args.output)
        print(json.dumps({'mode': 'generated-only', 'files': len(files), 'executed': False}))
        return 0
    except (ValueError, OSError):
        print(json.dumps({'generated': False, 'executed': False, 'reason': 'PROBE_PREPARATION_REFUSED'}))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
