#!/usr/bin/env python3
"""Offline callback URL completion from this checkout's live private window record only.

Does not read staged credentials, accept an arbitrary URL, call the URL, verify
PayPal registration, enable API/purchases, or change the legacy six-field schema.
"""
import argparse
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from paypal_apply_credentials import (ROOT, PREFIX, read_private, bound_environment,
                                      render_updates, atomic_private)
from paypal_stop_callback_window import matching

URL_KEY = PREFIX + 'WEBHOOK_URL'


class AppliedButUnverified(ValueError):
    pass


def candidate_ready(root, candidate):
    actual = subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True).strip()
    if not re.fullmatch('[0-9a-f]{40}', candidate) or actual != candidate:
        raise ValueError('CANDIDATE_MISMATCH')
    dirty = subprocess.run(['git', '-C', str(root), 'diff', '--quiet', 'HEAD', '--', 'h5-platform']).returncode
    extra = subprocess.check_output(['git', '-C', str(root), 'ls-files', '--others', '--exclude-standard', '--', 'h5-platform'], text=True)
    if dirty or extra:
        raise ValueError('APPLICATION_SOURCE_DIRTY')
    # The accepted candidate must actually contain 00's new shared field.
    config = (root / 'h5-platform/config/billing.php').read_text()
    if "'webhook_url'" not in config or URL_KEY not in config:
        raise ValueError('SHARED_CALLBACK_CONFIG_NOT_RECEIVED')


def process_command(pid):
    return subprocess.check_output(['ps', '-p', str(pid), '-o', 'command='], text=True).strip()


def active_window_url(root):
    directory = root / '.runtime/paypal-sandbox'
    origin = json.loads(read_private(directory / 'external-url.json'))['url']
    # This tool only supports the already authorized Quick Tunnel deployment.
    # A future hosting option needs its own accepted provenance, never arbitrary input.
    if not isinstance(origin, str) or not re.fullmatch(r'https://[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com', origin) or len(origin) > 2000:
        raise ValueError('WINDOW_URL_INVALID')
    record = json.loads(read_private(directory / 'external-processes.json'))
    config = json.loads(read_private(root / '.runtime/workspace.json'))
    marker = json.loads(read_private(directory / 'external-probe-marker.json'))['transmission_id']
    if (record['root'] != str(root) or config['root'] != str(root)
            or record['upstream_port'] != config['port'] or record['bridge_port'] != 8408
            or record['metrics_port'] != 8409
            or not isinstance(marker, str) or not re.fullmatch(r'SYNTHETIC-06-[a-f0-9]{32}', marker)):
        raise ValueError('WINDOW_BINDING_INVALID')
    binary = str(root / '.runtime/tools/cloudflared-2026.10.4')
    required = [binary, 'tunnel', '--config', '/dev/null', '--no-autoupdate',
                '--metrics', '127.0.0.1:8409', '--management-diagnostics=false',
                '--loglevel', 'info', '--url', 'http://127.0.0.1:8408',
                '--http-host-header', '127.0.0.1:8408', '--no-chunked-encoding']
    if record['tunnel_command'] != required:
        raise ValueError('CALLBACK_ONLY_COMMAND_MISMATCH')
    # Compare the complete tunnel command, not just its binary, plus process start time.
    expected = [('tunnel_pid', ' '.join(required)),
                ('bridge_pid', 'scripts/ops/tests/paypal_external_observer.py --synthetic-id '+marker)]
    for key, token in expected:
        if not matching(record[key], token, record['process_started_at'][key]) or (key == 'tunnel_pid' and process_command(record[key]) != token):
            raise ValueError('WINDOW_PROCESS_NOT_CURRENT')
    return origin + '/webhooks/paypal'


def prepare(root, candidate, autoload=None):
    candidate_ready(root, candidate)
    path, original, existing, loader, directory = bound_environment(root, autoload)
    if existing.get(PREFIX + 'ENABLED', 'false') not in ('false', '(false)', ''):
        raise ValueError('DISABLE_API_BEFORE_COMPLETION')
    callback = active_window_url(root)
    proposed = render_updates(original, existing, {URL_KEY: callback}, loader)
    return path, original, proposed, directory


def apply(root, candidate, autoload=None):
    directory = root / '.runtime/paypal-sandbox'
    for parent in (root / '.runtime', directory):
        if parent.is_symlink() or not parent.is_dir() or parent.stat().st_uid != os.getuid() or parent.stat().st_mode & 0o077:
            raise ValueError('DIRECTORY_INVALID')
    fd = os.open(directory / '.apply.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        path, old, proposed, directory = prepare(root, candidate, autoload)
        backup_fd, _ = tempfile.mkstemp(prefix='env-before-webhook-url-', dir=directory)
        with os.fdopen(backup_fd, 'w') as backup:
            os.fchmod(backup.fileno(), 0o600)
            backup.write(old)
            backup.flush()
            os.fsync(backup.fileno())
        if read_private(path) != old:
            raise ValueError('ENV_CHANGED_DURING_APPLY')
        # Recheck the candidate and window at the last reversible boundary.
        _, old_again, proposed_again, _ = prepare(root, candidate, autoload)
        if old_again != old or proposed_again != proposed:
            raise ValueError('INPUT_CHANGED_DURING_APPLY')
        atomic_private(path, proposed)
        try:
            if read_private(path) != proposed:
                raise ValueError('ENV_CHANGED_AFTER_APPLY')
            _, saved, rendered, _ = prepare(root, candidate, autoload)
            if saved != proposed or rendered != proposed:
                raise ValueError('POST_WRITE_GUARD_FAILED')
        except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
            # Do not silently overwrite a concurrent update with an old backup.
            raise AppliedButUnverified('URL_WRITTEN_BUT_FINAL_GUARD_FAILED') from None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', required=True, help='00-accepted exact current checkout SHA')
    parser.add_argument('--apply', action='store_true', help='Without this flag: offline check only')
    args = parser.parse_args()
    try:
        (apply if args.apply else prepare)(ROOT, args.candidate)
    except AppliedButUnverified:
        raise SystemExit('URL已写入但末次核验未完成；未启用付款，请00检查窗口/配置后继续，不自动回滚覆盖并发修改。')
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        raise SystemExit('未补齐：请核对固定候选、停机/API关闭、私密窗口记录及有效进程；未启用付款。')
    print('仅回调URL已私密补齐，原env已备份；原凭据不变，API仍关闭，官方登记尚待读回。' if args.apply
          else '离线检查通过；未写入、未请求地址、未验证官方登记或启用付款。')


if __name__ == '__main__':
    main()
