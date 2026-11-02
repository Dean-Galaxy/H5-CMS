#!/usr/bin/env python3
"""One explicitly approved media acceptance window; default is inert, never a timer."""
import argparse
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import signal
import stat
import subprocess
import sys
import time

SOURCE = '57a658ef0c8f29842047aca94572bec784bfae37'
RELEASE = Path('/srv/h5/releases') / SOURCE
APP = RELEASE / 'h5-platform'
OPS = Path('/srv/h5/ops/media-acceptance')
STATE = Path('/srv/h5/shared/ops/media-acceptance')
BINDING = Path('/srv/h5/ops/media-acceptance/window.json')
FILES = {'env': Path('/srv/h5/shared/.env'), 'config': APP/'bootstrap/cache/config.php',
         'lock': APP/'composer.lock', 'media_store': APP/'app/Modules/Content/Services/MediaStore.php',
         'runner': OPS/'media_acceptance_window.py', 'bridge': OPS/'media_acceptance_bridge.php'}
UUID = re.compile(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\Z')
SHA = re.compile(r'[0-9a-f]{64}\Z')


class Refused(Exception):
    pass


def require(ok, code):
    if not ok:
        raise Refused(code)


def stamp(value):
    require(isinstance(value, str) and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', value), 'WINDOW_TIME_INVALID')
    try:
        return int(dt.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=dt.timezone.utc).timestamp())
    except ValueError:
        raise Refused('WINDOW_TIME_INVALID') from None


def binding_policy(b):
    require(isinstance(b, dict) and b.get('schema') == 'h5-media-acceptance-v1', 'BINDING_SCHEMA_INVALID')
    require(b.get('approved') is True and isinstance(b.get('approval_reference'), str)
            and 1 <= len(b['approval_reference']) <= 200, 'DIRECT_APPROVAL_REQUIRED')
    for key in ('owner_id', 'window_id'):
        require(isinstance(b.get(key), str) and UUID.fullmatch(b[key]), 'IDENTITY_BINDING_INVALID')
    require(b.get('source_sha') == SOURCE and b.get('release_root') == str(RELEASE)
            and b.get('workspace') == 'h5_cloud_staging' and b.get('database') == 'h5_staging'
            and b.get('media_root') == '/srv/h5/shared/media', 'STAGING_BINDING_INVALID')
    start, end = stamp(b.get('not_before')), stamp(b.get('expires_at'))
    require(0 < end-start <= 2700 and type(b.get('max_assets')) is int and b['max_assets'] == 6, 'WINDOW_LIMIT_INVALID')
    fixtures = b.get('fixtures')
    require(isinstance(fixtures, list) and len(fixtures) == 3, 'FIXTURE_BINDING_INVALID')
    require({(f.get('kind'), f.get('extension')) for f in fixtures if isinstance(f, dict)}
            in ({('video', 'mp4'), ('subtitle', 'srt'), ('cover', 'png')},
                {('video', 'mp4'), ('subtitle', 'srt'), ('cover', 'jpg')},
                {('video', 'mp4'), ('subtitle', 'srt'), ('cover', 'jpeg')}), 'FIXTURE_BINDING_INVALID')
    for f in fixtures:
        require(type(f.get('bytes')) is int
                and 0 < f['bytes'] <= 16*1024*1024 and isinstance(f.get('sha256'), str)
                and SHA.fullmatch(f['sha256']), 'FIXTURE_BINDING_INVALID')
    hashes = b.get('frozen_sha256')
    require(isinstance(hashes, dict) and set(hashes) == set(FILES)
            and all(isinstance(x, str) and SHA.fullmatch(x) for x in hashes.values()), 'FROZEN_HASHES_REQUIRED')
    return start, end


def candidate_policy(b, a, now, completed=()):
    start, end = binding_policy(b)
    require(start <= now < end, 'WINDOW_EXPIRED_OR_NOT_STARTED')
    require(isinstance(a, dict) and isinstance(a.get('id'), str) and UUID.fullmatch(a['id']), 'ASSET_ID_INVALID')
    created = stamp(a.get('created_at'))
    require(start < created < end and created <= now, 'OLD_OR_OUTSIDE_WINDOW_ASSET')
    require(a.get('created_by') == b['owner_id'], 'OTHER_OWNER_ASSET')
    match = [f for f in b['fixtures'] if (f['kind'], f['extension'], f['bytes']) ==
             (a.get('kind'), a.get('extension'), a.get('expected_bytes'))]
    require(len(match) == 1, 'UNKNOWN_FILE_SCOPE')
    fixture = match[0]
    require(a.get('source_key') == 'assets/'+a['id']+'/source.'+a['extension'], 'ASSET_PATH_INVALID')
    if a['id'] in completed:
        require(a.get('state') == 'ready', 'COMPLETED_ASSET_CHANGED')
    elif a.get('state') == 'uploading':
        require(type(a.get('received_bytes')) is int and 0 <= a['received_bytes'] <= fixture['bytes']
                and a.get('attempts') == 0, 'UPLOAD_STATE_INVALID')
        return 'waiting'
    else:
        require(a.get('state') == 'pending' and a.get('attempts') == 0, 'ASSET_NOT_FRESH_PENDING')
    require(a.get('received_bytes') == fixture['bytes'] and a.get('observed_bytes') == fixture['bytes']
            and a.get('sha256') == fixture['sha256'] and a.get('observed_sha256') == fixture['sha256'], 'UNKNOWN_FILE_HASH_OR_SIZE')
    return 'complete' if a['id'] in completed else 'pending'


def private_directory(path):
    require(path.is_dir() and not path.is_symlink() and path.resolve() == path
            and path.stat().st_uid == os.getuid() and stat.S_IMODE(path.stat().st_mode) == 0o700, 'STATE_DIRECTORY_INVALID')


def atomic_json(path, document):
    require(not path.is_symlink(), 'STATE_SYMLINK_REFUSED')
    temp = path.with_suffix('.next')
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(document, stream, separators=(',', ':'))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if temp.exists():
            temp.unlink()


class Ledger:
    def __init__(self, path, digest, window):
        require(not path.exists() and not path.is_symlink(), 'WINDOW_ALREADY_USED_REVIEW_REQUIRED')
        self.path = path
        self.doc = {'window_id': window, 'binding_sha256': digest, 'status': 'running',
                    'claimed': [], 'completed': [], 'in_flight': None, 'reason': None, 'supervisor_pid': os.getpid()}
        self.save()

    def save(self):
        atomic_json(self.path, self.doc)

    def claim(self, asset):
        require(self.doc['status'] == 'running' and self.doc['in_flight'] is None
                and asset not in self.doc['claimed'] and len(self.doc['claimed']) < 6, 'LEDGER_CLAIM_REFUSED')
        self.doc['claimed'].append(asset)
        self.doc['in_flight'] = asset
        self.save()  # Consume before invoking PHP; interruption never replenishes budget.

    def complete(self, asset):
        require(self.doc['in_flight'] == asset, 'LEDGER_RESULT_MISMATCH')
        self.doc['completed'].append(asset)
        self.doc['in_flight'] = None
        self.save()

    def close(self, reason):
        self.doc.update(status='closed', reason=reason)
        self.save()  # Retain unknown in_flight for 00 reconciliation; never auto retry.


class WindowLock:
    def __init__(self, path):
        self.path = path

    def __enter__(self):
        fd = os.open(self.path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        self.stream = os.fdopen(fd, 'r+')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            self.stream.close()
            raise Refused('CONCURRENT_WINDOW_REFUSED') from None
        return self

    def __exit__(self, *_):
        self.stream.close()


def terminate_group(process):
    # Even an exited PHP leader may have left descendants; kill its dedicated group.
    for sig in (signal.SIGTERM, signal.SIGKILL):
        try:
            os.killpg(process.pid, sig)
        except ProcessLookupError:
            break
        if sig == signal.SIGTERM:
            try:
                process.wait(timeout=1)
            except subprocess.TimeoutExpired:
                pass
    process.wait()


class Bridge:
    def __init__(self, binding, stopped):
        self.binding, self.stopped = binding, stopped
        start, end = binding_policy(binding)
        self.monotonic_end = time.monotonic() + max(0, min(2700, end-time.time()))
        self.end = end

    def call(self, action, asset=None):
        require(not self.stopped(), 'STOP_REQUESTED')
        require(time.time() < self.end and time.monotonic() < self.monotonic_end, 'WINDOW_EXPIRED')
        payload = json.dumps({'binding': self.binding, 'action': action, 'asset_id': asset}).encode()
        # Never inherit credentials/proxies or the SSH caller's inaccessible cwd.
        process = subprocess.Popen(['/usr/bin/php8.4', '-d', 'memory_limit=128M',
                                    str(OPS/'media_acceptance_bridge.php'), '--execute'],
                                   cwd=APP, env={'APP_ENV': 'staging', 'PATH': '/usr/bin:/bin',
                                                 'HOME': '/srv/h5', 'LANG': 'C.UTF-8', 'TZ': 'UTC'},
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   start_new_session=True)
        deadline = min(self.monotonic_end, time.monotonic()+120)
        try:
            while True:
                require(not self.stopped(), 'STOP_REQUESTED')
                require(time.time() < self.end and time.monotonic() < deadline, 'CHILD_TIMEOUT_OR_WINDOW_EXPIRED')
                try:
                    stdout, stderr = process.communicate(payload, timeout=min(0.5, max(0.01, deadline-time.monotonic())))
                    break
                except subprocess.TimeoutExpired:
                    payload = None
            require(len(stdout) <= 32768, 'BRIDGE_REJECTED_REVIEW_PRIVATE_STATE')
            try:
                result = json.loads(stdout)
            except (ValueError, UnicodeDecodeError):
                raise Refused('BRIDGE_REJECTED_REVIEW_PRIVATE_STATE') from None
            if isinstance(result, dict) and result.get('ok') is False:
                reason = result.get('reason')
                if isinstance(reason, str) and re.fullmatch(r'[A-Z][A-Z0-9_]{1,95}', reason):
                    raise Refused(reason)
            require(process.returncode == 0 and isinstance(result, dict) and result.get('ok') is True,
                    'BRIDGE_REJECTED_REVIEW_PRIVATE_STATE')
            return result
        finally:
            terminate_group(process)


def step(b, ledger, bridge, now):
    start, end = binding_policy(b)
    current = lambda: now() if callable(now) else now
    require(start <= current() < end, 'WINDOW_EXPIRED_OR_NOT_STARTED')
    require(len(ledger.doc['claimed']) < 6, 'QUOTA_REACHED')
    result = bridge.call('inspect')
    require(result.get('owner_ok') is True, 'OWNER_OR_MFA_NOT_READY')
    rows = result.get('assets')
    require(isinstance(rows, list) and len(rows) <= 6, 'NEW_UPLOAD_QUOTA_EXCEEDED')
    ids = [a.get('id') for a in rows if isinstance(a, dict)]
    require(len(ids) == len(rows) == len(set(ids)) and set(ledger.doc['completed']).issubset(ids), 'ASSET_SET_CHANGED')
    decisions = [(a, candidate_policy(b, a, current(), ledger.doc['completed'])) for a in rows]
    for asset, decision in decisions:
        if decision != 'pending':
            continue
        ledger.claim(asset['id'])
        outcome = bridge.call('process', asset['id'])
        require(outcome.get('asset_id') == asset['id'] and outcome.get('state') == 'ready'
                and outcome.get('attempts') == 1, 'MEDIA_NOT_READY_REVIEW_REQUIRED')
        ledger.complete(asset['id'])
        return 'quota' if len(ledger.doc['claimed']) == 6 else 'processed'
    return 'waiting'


def secure_binding(path):
    require(path == BINDING and not path.is_symlink() and path.resolve() == path, 'BINDING_PATH_INVALID')
    info = path.stat()
    require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_gid == os.getgid()
            and stat.S_IMODE(info.st_mode) == 0o640 and info.st_size <= 16384, 'BINDING_PERMISSIONS_INVALID')
    raw = path.read_bytes()
    return raw, json.loads(raw)


def frozen(b):
    require(Path.cwd() == APP and APP.resolve() == APP and Path(__file__).resolve() == FILES['runner'], 'WORKING_DIRECTORY_OR_RUNNER_INVALID')
    for key, path in FILES.items():
        require(not path.is_symlink() and path.resolve() == path and path.is_file()
                and hashlib.sha256(path.read_bytes()).hexdigest() == b['frozen_sha256'][key], 'FROZEN_FILE_CHANGED')


def run(path):
    require(sys.platform == 'linux' and pwd.getpwuid(os.getuid()).pw_name == 'h5', 'H5_LINUX_USER_REQUIRED')
    raw, b = secure_binding(path)
    binding_policy(b)
    require(stamp(b['not_before']) <= time.time() < stamp(b['expires_at']), 'WINDOW_EXPIRED_OR_NOT_STARTED')
    private_directory(STATE)
    # Explicit chdir before any Laravel/FFmpeg process; never use SSH HOME.
    os.chdir(APP)
    frozen(b)
    stopped = [False]
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, lambda *_: stopped.__setitem__(0, True))
    with WindowLock(STATE/'window.lock'):
        ledger = Ledger(STATE/(b['window_id']+'.json'), hashlib.sha256(raw).hexdigest(), b['window_id'])
        bridge = Bridge(b, lambda: stopped[0] or (STATE/'STOP').exists())
        reason = 'OPERATOR_STOP'
        try:
            while not stopped[0] and not (STATE/'STOP').exists():
                require(path.read_bytes() == raw, 'BINDING_CHANGED')
                frozen(b)
                status = step(b, ledger, bridge, time.time)
                if status == 'quota':
                    reason = 'QUOTA_REACHED'
                    break
                time.sleep(1)
        except Refused as error:
            reason = str(error)
            raise
        except BaseException:
            reason = 'INTERRUPTED_OR_UNKNOWN_RESULT'
            raise
        finally:
            ledger.close(reason)
            print(json.dumps({'status': 'closed', 'reason': reason, 'consumed': len(ledger.doc['claimed']),
                              'completed': len(ledger.doc['completed']), 'unknown_result': ledger.doc['in_flight'] is not None}))


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--binding', type=Path, default=BINDING)
    parser.add_argument('--run', action='store_true')
    args = parser.parse_args(argv)
    if not args.run:
        print(json.dumps({'mode': 'offline-default', 'started': False, 'approval_required': True}))
        return 0
    try:
        run(args.binding)
        return 0
    except (Refused, OSError, ValueError, KeyError, TypeError) as error:
        # Only our fixed refusal codes are printable; never dump a framework exception.
        reason = str(error) if isinstance(error, Refused) and re.fullmatch(r'[A-Z0-9_]+', str(error)) else 'MEDIA_WINDOW_REFUSED'
        print(json.dumps({'status': 'refused_or_stopped', 'reason': reason, 'review_required': True}))
        return 2


if __name__ == '__main__':
    sys.exit(main())
