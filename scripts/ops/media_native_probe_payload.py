#!/usr/bin/env python3
"""Payload for ONE dedicated synthetic systemd window; never imports H5 or a DB."""
import fcntl
import json
import os
from pathlib import Path
import pwd
import signal
import stat
import subprocess
import sys
import time

BASE = Path('/srv/h5-native-probe-20261102')
CONTROL, MEDIA, LOGS = BASE/'control', BASE/'media', BASE/'logs'
MAX_CLI = 12
WINDOW_SECONDS = 900


class Refused(Exception):
    pass


def check(ok, code):
    if not ok:
        raise Refused(code)


def scope():
    code = (BASE/'probe.py').lstat()
    check(stat.S_ISREG(code.st_mode) and code.st_uid == 0 and code.st_gid == 0
          and stat.S_IMODE(code.st_mode) == 0o644, 'PROBE_CODE_PERMISSIONS_INVALID')
    h5 = pwd.getpwnam('h5')
    for path, uid, gid, mode in [(BASE, 0, 0, 0o755), (CONTROL, 0, 0, 0o755),
                                 (BASE/'work', 0, 0, 0o755), (BASE/'outside', 0, 0, 0o755),
                                 (MEDIA, h5.pw_uid, h5.pw_gid, 0o700),
                                 (LOGS, h5.pw_uid, h5.pw_gid, 0o700)]:
        info = path.lstat()
        check(stat.S_ISDIR(info.st_mode) and path.resolve() == path and info.st_uid == uid
              and info.st_gid == gid and stat.S_IMODE(info.st_mode) == mode, 'PROBE_DIRECTORY_BINDING_INVALID')
    for name, mode in [('root-owned.txt', 0o600), ('h5-writable.txt', 0o666)]:
        info = (BASE/'outside'/name).lstat()
        check(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_gid == 0
              and stat.S_IMODE(info.st_mode) == mode, 'PROBE_SENTINEL_INVALID')


def append(path, record, cap):
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'r+') as stream:
        fcntl.flock(stream, fcntl.LOCK_EX)
        data = stream.read(16385)
        check(len(data) <= 16384 and len(data.splitlines()) < cap, 'PROBE_LIMIT_REACHED')
        stream.write(json.dumps(record, separators=(',', ':'))+'\n')
        stream.flush()
        os.fsync(stream.fileno())


def identity(root):
    expected = 0 if root else pwd.getpwnam('h5').pw_uid
    check(os.geteuid() == expected, 'WRONG_PROBE_UID')
    status = dict(line.split(':', 1) for line in Path('/proc/self/status').read_text().splitlines() if ':' in line)
    check(status['NoNewPrivs'].strip() == '1' and int(status['CapEff'].strip(), 16) == 0, 'PROBE_SANDBOX_IDENTITY_INVALID')
    return {'uid': os.geteuid(), 'gid': os.getegid(), 'pid': os.getpid(),
            'cap_eff': status['CapEff'].strip(), 'no_new_privs': 1,
            'cgroup': Path('/proc/self/cgroup').read_text().strip()}


def window():
    path = CONTROL/'WINDOW.json'
    info = path.lstat()
    check(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o644, 'PROBE_WINDOW_PERMISSIONS')
    b = json.loads(path.read_text())
    check(set(b) == {'start', 'end'} and type(b['start']) is int and type(b['end']) is int
          and b['end']-b['start'] == WINDOW_SECONDS and b['start'] <= time.time() < b['end'], 'PROBE_WINDOW_EXPIRED')


def control(name, choices):
    path = CONTROL/name
    info = path.lstat()
    check(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o644, 'PROBE_CONTROL_PERMISSIONS')
    text = path.read_text().strip()
    check(text in choices, 'PROBE_CONTROL_VALUE_INVALID')
    return text


def denied(path):
    try:
        fd = os.open(path, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    except OSError as error:
        check(error.errno in (13, 30), 'UNEXPECTED_PROBE_WRITE_ERROR')  # EACCES/EROFS only.
        return True
    else:
        with os.fdopen(fd, 'w') as stream:
            stream.write('UNEXPECTED WRITE INSIDE PROBE ONLY\n')
        raise Refused('PROBE_WRITE_WAS_NOT_DENIED')


def init_window():
    check(os.geteuid() == 0, 'ROOT_PREPARATION_REQUIRED')
    check(CONTROL.resolve() == CONTROL and CONTROL.stat().st_uid == 0
          and stat.S_IMODE(CONTROL.stat().st_mode) == 0o755, 'PROBE_CONTROL_DIRECTORY_INVALID')
    names = ('WINDOW.json', 'CASE', 'CONDITION')
    check(not any((CONTROL/name).exists() or (CONTROL/name).is_symlink() for name in names)
          and not (CONTROL/'ENABLED').exists() and not (CONTROL/'RUNNING').exists(), 'PROBE_ALREADY_PREPARED')
    start = int(time.time())
    records = [json.dumps({'start': start, 'end': start+WINDOW_SECONDS})+'\n', 'success\n', 'skip\n']
    for name, data in zip(names, records):
        fd = os.open(CONTROL/name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
        with os.fdopen(fd, 'w') as stream:
            os.fchmod(stream.fileno(), 0o644)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
    directory = os.open(CONTROL, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)
    # Deliberately never creates ENABLED or runs systemctl.


def root_check(failure=False):
    record = identity(True)
    if not failure:
        window()
        check(Path.cwd() == BASE/'work', 'PROBE_CWD_INVALID')
    denied(BASE/'outside/root-owned.txt')  # root owns it; FS sandbox must still deny.
    record.update(kind='failure-root' if failure else 'bang-root', outside_write_denied=True)
    append(CONTROL/'root-observations.jsonl', record, 40)


def bootstrap():
    window()
    record = identity(False)
    check(Path.cwd() == BASE/'work', 'PROBE_CWD_INVALID')
    denied(CONTROL/'H5_MUST_NOT_WRITE')  # RW mount but root-only DAC.
    denied(BASE/'outside/h5-writable.txt')  # Host DAC would allow; sandbox must deny.
    check(not (LOGS/'cli-entries.jsonl').exists() or len((LOGS/'cli-entries.jsonl').read_text().splitlines()) < MAX_CLI,
          'PROBE_LIMIT_REACHED')
    for path in (MEDIA/'synthetic.txt', LOGS/'synthetic.txt'):
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write('synthetic\n')
    record.update(kind='h5-bootstrap', marker_write_denied=True, outside_write_denied=True)
    append(LOGS/'observations.jsonl', record, 40)


def cli():
    record = identity(False)
    record.update(kind='cli-entry')
    append(LOGS/'cli-entries.jsonl', record, MAX_CLI)  # Count before case/window checks; never reset.
    window()
    case = control('CASE', ('success', 'nonzero', 'hold'))
    append(LOGS/'observations.jsonl', {'kind': 'cli-case', 'pid': os.getpid(), 'case': case}, 40)

    if case == 'success':
        return 0
    if case == 'nonzero':
        return 7
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    child = subprocess.Popen(['/usr/bin/python3', '-I', str(BASE/'probe.py'), 'child'])
    try:
        append(LOGS/'children.jsonl', {'parent': os.getpid(), 'child': child.pid}, MAX_CLI)
        end = time.monotonic()+12
        while time.monotonic() < end:
            window()
            time.sleep(0.1)
        return 9  # Safety fallback if the native unit limit did not work.
    finally:
        if child.poll() is None:
            child.kill()
        child.wait()


def main(argv=None):
    args = sys.argv[1:] if argv is None else argv
    if not args:
        print(json.dumps({'mode': 'review-only', 'started': False}))
        return 0
    try:
        check(sys.platform == 'linux' and Path(__file__).resolve() == BASE/'probe.py', 'NOT_THE_DEPLOYED_PROBE')
        check(len(args) == 1 and args[0] in ('init-window', 'condition', 'bootstrap', 'root-check', 'failure-check', 'cli', 'child'), 'PROBE_MODE_INVALID')
        scope()  # Reject links/misbound writable directories before any probe write.
        action = args[0]
        if action == 'init-window':
            init_window()
        elif action == 'condition':
            window();identity(False)
            return 3 if control('CONDITION', ('allow', 'skip')) == 'skip' else 0
        elif action == 'root-check':
            root_check()
        elif action == 'failure-check':
            root_check(True)  # Disarm still works after window expiry.
        elif action == 'bootstrap':
            bootstrap()
        elif action == 'cli':
            return cli()
        else:
            identity(False)
            signal.signal(signal.SIGTERM, signal.SIG_IGN)
            time.sleep(14)  # Bounded synthetic descendant, no allocation/pressure test.
        return 0
    except (Refused, OSError, ValueError, KeyError) as error:
        code = str(error) if isinstance(error, Refused) else 'PROBE_REJECTED'
        print(json.dumps({'ok': False, 'reason': code}))
        return 255 if args and args[0] == 'condition' else 8


if __name__ == '__main__':
    sys.exit(main())
