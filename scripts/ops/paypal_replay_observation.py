#!/usr/bin/env python3
"""Opt-in, bounded, unverified transport observations for one private event/window.

No PayPal requests, credentials, application imports or automatic server startup.
"""
import argparse
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
MAX_RECORDS = 64
MAX_LOG_BYTES = 65536
MAX_POLICY_BYTES = 4096
HASH = re.compile(r'[0-9a-f]{64}')
FIELDS = {'event_id_sha256', 'transmission_id_sha256', 'body_sha256', 'body_bytes',
          'upstream_status', 'observed_at', 'count', 'signature_verified'}


def digest(value):
    return hashlib.sha256(value.encode() if isinstance(value, str) else value).hexdigest()


def private_stat(info, mode):
    if info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) != mode:
        raise ValueError('PRIVATE_OWNER_OR_MODE')


def private_read(path, limit):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        private_stat(info, 0o600)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > limit:
            raise ValueError('PRIVATE_FILE_INVALID')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            data = stream.read(limit + 1)
        if len(data) > limit:
            raise ValueError('PRIVATE_FILE_TOO_LARGE')
        return data
    finally:
        os.close(fd)


def strict_json(data):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('DUPLICATE_JSON_KEY')
            result[key] = value
        return result
    return json.loads(data, object_pairs_hook=unique)


class ReplayObservation:
    def __init__(self, root):
        self.root = Path(root)
        self.directory = self.root / '.runtime/paypal-sandbox'
        self.policy_path = self.directory / 'replay-observation.json'
        self.policy()  # Explicit opt-in with invalid private inputs fails startup, without printing inputs.

    def policy(self):
        for directory in (self.root, self.root / '.runtime', self.directory):
            info = directory.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid():
                raise ValueError('DIRECTORY_INVALID')
            # Checkout may be readable; runtime and observer records must be private.
            if directory != self.root:
                private_stat(info, 0o700)
        p = strict_json(private_read(self.policy_path, MAX_POLICY_BYTES))
        if (not isinstance(p, dict) or set(p) != {'schema', 'workspace_root', 'event_id', 'window', 'expires_at'}
                or type(p['schema']) is not int or p['schema'] != 1 or p['workspace_root'] != str(self.root)
                or not isinstance(p['event_id'], str)
                or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_-]{0,127}', p['event_id'])
                or type(p['expires_at']) is not int or not time.time() < p['expires_at'] <= time.time() + 3600):
            raise ValueError('POLICY_INVALID_OR_EXPIRED')
        w = p['window']
        if (not isinstance(w, dict) or set(w) != {'marker_sha256', 'origin_sha256', 'tunnel_pid', 'tunnel_started_at'}
                or any(not isinstance(w[k], str) or not HASH.fullmatch(w[k]) for k in ('marker_sha256', 'origin_sha256'))
                or type(w['tunnel_pid']) is not int or w['tunnel_pid'] <= 1
                or not isinstance(w['tunnel_started_at'], str) or not 1 <= len(w['tunnel_started_at']) <= 100):
            raise ValueError('WINDOW_POLICY_INVALID')
        return p

    def window(self, policy, own_process=True):
        # Reuse accepted complete command, start-time, PID, exact callback URL and port checks.
        from paypal_complete_webhook_url import active_window_url
        origin = active_window_url(self.root).removesuffix('/webhooks/paypal')
        record = strict_json(private_read(self.directory / 'external-processes.json', 16384))
        marker = strict_json(private_read(self.directory / 'external-probe-marker.json', 4096))['transmission_id']
        expected = {'marker_sha256': digest(marker), 'origin_sha256': digest(origin),
                    'tunnel_pid': record['tunnel_pid'],
                    'tunnel_started_at': record['process_started_at']['tunnel_pid']}
        if expected != policy['window'] or (own_process and record['bridge_pid'] != os.getpid()):
            raise ValueError('WINDOW_CHANGED_OR_OTHER_OBSERVER')
        return self.directory / ('replay-observations-' + digest(json.dumps(expected, sort_keys=True)) + '.jsonl')

    def select(self, body, headers):
        try:
            policy = self.policy()
            # Size/encoding/shape checks affect observation only, never rewrite the forwarded request.
            if not isinstance(body, bytes) or not 1 <= len(body) <= 256 * 1024:
                return None
            event = strict_json(body.decode('utf-8'))
            transmission = headers.get('paypal-transmission-id')
            if (not isinstance(event, dict) or event.get('id') != policy['event_id']
                    or not isinstance(transmission, str)
                    or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_-]{0,255}', transmission)):
                return None
            self.window(policy)
            return {'event_id_sha256': digest(policy['event_id']),
                    'transmission_id_sha256': digest(transmission), 'body_sha256': digest(body),
                    'body_bytes': len(body), 'policy': policy}
        except (OSError, ValueError, TypeError, KeyError, RecursionError, subprocess.SubprocessError):
            return None

    def record(self, selected, status):
        try:
            if selected is None or type(status) is not int or not 200 <= status <= 599:
                return False
            policy = self.policy()
            if policy != selected['policy']:
                return False
            path = self.window(policy)
            fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
            try:
                info = os.fstat(fd)
                private_stat(info, 0o600)
                if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > MAX_LOG_BYTES:
                    return False
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                raw = os.read(fd, MAX_LOG_BYTES + 1)
                if len(raw) > MAX_LOG_BYTES or (raw and not raw.endswith(b'\n')):
                    return False
                rows = [strict_json(line) for line in raw.splitlines()]
                for index, row in enumerate(rows, 1):
                    if (not isinstance(row, dict) or set(row) != FIELDS or type(row['count']) is not int or row['count'] != index
                            or row['event_id_sha256'] != selected['event_id_sha256']
                            or row['signature_verified'] is not False
                            or any(not isinstance(row[k], str) or not HASH.fullmatch(row[k])
                                   for k in ('event_id_sha256', 'transmission_id_sha256', 'body_sha256'))
                            or type(row['body_bytes']) is not int or not 1 <= row['body_bytes'] <= 256 * 1024
                            or type(row['upstream_status']) is not int or not 200 <= row['upstream_status'] <= 599
                            or not isinstance(row['observed_at'], str)
                            or not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00', row['observed_at'])):
                        return False
                if len(rows) >= MAX_RECORDS:
                    return False
                row = {k: selected[k] for k in ('event_id_sha256', 'transmission_id_sha256', 'body_sha256', 'body_bytes')}
                row.update(upstream_status=status, observed_at=datetime.now(timezone.utc).isoformat(timespec='microseconds'),
                           count=len(rows) + 1, signature_verified=False)
                encoded = (json.dumps(row, sort_keys=True) + '\n').encode()
                if len(raw) + len(encoded) > MAX_LOG_BYTES:
                    return False
                # An interrupted/partial write remains invalid and is never extended as valid evidence.
                os.lseek(fd, 0, os.SEEK_END)
                if os.write(fd, encoded) != len(encoded):
                    return False
                os.fsync(fd)
                return True
            finally:
                os.close(fd)
        except (OSError, ValueError, TypeError, KeyError, RecursionError, subprocess.SubprocessError):
            return False  # Never log raw requests/errors or interfere with the application's response.


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', required=True, help='Read-only private policy/current window check; never starts observation')
    parser.parse_args()
    try:
        observer = ReplayObservation(ROOT)
        observer.window(observer.policy(), own_process=False)
    except (OSError, ValueError, TypeError, KeyError, RecursionError, subprocess.SubprocessError):
        raise SystemExit('私密策略或当前窗口未通过检查；未启动观察、未输出输入值。') from None
    print('私密事件/工作区/当前窗口匹配；只读检查，不启用观察。上限64条/64KiB，最长1小时；不证明验签或账务。')


if __name__ == '__main__':
    main()
