#!/usr/bin/env python3
"""C-only synthetic local lifecycle. Final 00 manifest required; never starts queue/scheduler/tunnel."""
import argparse
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import shlex
import signal
import socket
import subprocess
import time
import urllib.request
from analytics_acceptance import private_json, mime_version_guard
from analytics_browser_fixture import environment as browser_environment
from c_window_preflight import root_guard, workspace_binding, private_document, validate_manifest

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / '.runtime/06/c-window'
MANIFEST = OUT / 'manifest.json'
DATA = ROOT / '.runtime/06/analytics-browser'
PHP = '/opt/homebrew/opt/php@8.4/bin/php'
ROUTER = ROOT / 'h5-platform/tests/Browser/Support/c-window-router.php'


def private_dir(path):
    if path.exists() and (path.is_symlink() or not path.is_dir() or path.stat().st_uid != os.getuid() or path.stat().st_mode & 0o077):
        raise ValueError('PRIVATE_DIRECTORY_REQUIRED')
    path.mkdir(mode=0o700, parents=True, exist_ok=True)


def command(config):
    return [PHP, '-d', 'expose_php=0', '-S', f'127.0.0.1:{config["port"]}', '-t', str(ROOT / 'h5-platform/public'), str(ROUTER)]


def matching(record, config):
    if record.get('root') != str(ROOT) or record.get('command') != command(config):
        return False
    pid = record.get('pid')
    if type(pid) is not int or pid <= 1:
        return False
    ps = subprocess.run(['ps', '-p', str(pid), '-o', 'command='], capture_output=True, text=True)
    started = subprocess.run(['ps', '-p', str(pid), '-o', 'lstart='], capture_output=True, text=True)
    return ps.returncode == 0 and ps.stdout.strip() == ' '.join(command(config)) and started.stdout.strip() == record.get('started_at')


def port_available(port):
    with socket.socket() as sock:
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        sock.bind(('127.0.0.1', port))


def environment(config, candidate):
    env = browser_environment(config, candidate)
    doc = private_document(MANIFEST, ROOT)
    env.update(H5_C_WINDOW_ONLY='1', H5_C_WINDOW_MANIFEST_SHA256=hashlib.sha256(MANIFEST.read_bytes()).hexdigest(),
               H5_C_EXPERIENCE_ENABLED='true', ACQUISITION_C_ENABLED='true' if doc['measurement_enabled'] else 'false', ACQUISITION_C_SYNTHETIC_POLICY='true',
               ACQUISITION_C_SYNTHETIC_UNTIL=doc['synthetic_until'], SESSION_COOKIE=config['workspace']+'_c_test',
               CACHE_PREFIX=config['workspace']+'_c_test', DB_QUEUE=config['workspace']+'_c_test')
    return env


def no_application_records():
    if any((ROOT / '.runtime/pids' / (name + '.json')).exists() for name in ('web', 'queue', 'scheduler')):
        raise ValueError('OWN_APPLICATION_MUST_BE_STOPPED')


def pg_guard():
    data = ROOT / '.runtime/postgres'
    if data.is_symlink() or data.resolve() != data:
        raise ValueError('OWN_PG_DIRECTORY_REQUIRED')
    pidfile = data / 'postmaster.pid'
    if pidfile.exists():
        rows = pidfile.read_text().splitlines()
        config = private_json(ROOT / '.runtime/workspace.json')
        if len(rows) < 4 or rows[1] != str(data) or rows[3] != str(config['db_port']):
            raise ValueError('FOREIGN_POSTGRES_PID_FILE')
        ps = subprocess.run(['ps', '-p', rows[0], '-o', 'command='], capture_output=True, text=True)
        if ps.returncode == 0:
            try:
                argv = shlex.split(ps.stdout.strip())
            except ValueError:
                raise ValueError('FOREIGN_POSTGRES_PROCESS') from None
            positions = [i for i, token in enumerate(argv) if token == '-D']
            if len(positions) != 1 or positions[0] + 1 >= len(argv) or argv[positions[0] + 1] != str(data):
                raise ValueError('FOREIGN_POSTGRES_PROCESS')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['setup', 'seed', 'start', 'state', 'checkpoint', 'backup', 'quiesce', 'stop'])
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--label', default='snapshot')
    args = parser.parse_args()
    root_guard(ROOT)
    doc = private_document(MANIFEST, ROOT)
    config = workspace_binding(ROOT, doc['workspace'])
    if args.candidate != doc.get('candidate'):
        raise ValueError('MANIFEST_CANDIDATE_REQUIRED')
    spec = importlib.util.spec_from_file_location('c_own_dev', ROOT / 'scripts/dev.py')
    dev = importlib.util.module_from_spec(spec); spec.loader.exec_module(dev)
    os.umask(0o077)
    if args.action in ('quiesce', 'stop'):
        actual = workspace_binding(ROOT, private_json(ROOT / '.runtime/workspace.json'))
        if actual != config:
            raise ValueError('OWN_SETUP_MISMATCH')
        record_path = OUT / 'process.json'
        if not record_path.exists():
            no_application_records()  # setup/seed may have started only this workspace's PG.
        else:
            record = private_json(record_path)
            if record.get('candidate') != args.candidate or record.get('root') != str(ROOT) or record.get('command') != command(config):
                raise ValueError('OWN_PROCESS_CANDIDATE_REQUIRED')
            pidfile = ROOT / '.runtime/pids/web.json'
            if pidfile.exists():
                stored = private_json(pidfile)
                if stored.get('pid') != record.get('pid') or stored.get('marker') != str(ROUTER):
                    raise ValueError('FOREIGN_PID_RECORD')
            # A reused live PID must never be reported/stopped as our own process.
            pid = record.get('pid')
            if type(pid) is not int or pid <= 1:
                raise ValueError('OWN_PID_REQUIRED')
            exists = subprocess.run(['ps', '-p', str(pid), '-o', 'pid='], capture_output=True).returncode == 0
            if exists and not matching(record, config):
                raise ValueError('PID_REUSED_OR_FOREIGN_PROCESS')
            if matching(record, config):
                if os.getpgid(pid) != pid:
                    raise ValueError('OWN_PROCESS_GROUP_REQUIRED')
                os.killpg(pid, signal.SIGTERM)
                for _ in range(50):
                    if not matching(record, config): break
                    time.sleep(.1)
            if matching(record, config):
                raise ValueError('OWN_WEB_STILL_RUNNING')
            pidfile.unlink(missing_ok=True)
            if any(dev.process_alive(name) for name in ('web', 'queue', 'scheduler')):
                raise ValueError('OTHER_OWN_APPLICATION_REMAINS')
        if args.action == 'quiesce':
            port_available(config['port'])
            dev.write_private(OUT / 'quiesced.json', json.dumps({'candidate': args.candidate, 'own_web_stopped': True, 'own_pg_retained': True, 'web_port_unbound': True}))
            print(json.dumps({'web_stopped': True, 'pg_retained_for_backup': True})); return
        pg_guard()
        if (ROOT / '.runtime/postgres/postmaster.pid').exists():
            subprocess.run([str(dev.PG / 'pg_ctl'), '-D', str(ROOT / '.runtime/postgres'), '-m', 'fast', '-w', 'stop'], check=True, capture_output=True)
        port_available(config['port'])
        port_available(config['db_port'])
        dev.write_private(OUT / 'stopped.json', json.dumps({'candidate': args.candidate, 'data_preserved': True, 'own_web_and_pg_stopped': True, 'web_port_unbound': True, 'db_port_unbound': True}))
        print(json.dumps({'stopped': True, 'data_preserved': True, 'other_windows_touched': False})); return
    validate_manifest(ROOT, args.candidate, doc, future_policy=args.action in ('setup', 'seed', 'start'))
    if (ROOT / 'h5-platform/bootstrap/cache/config.php').exists():
        raise ValueError('CACHED_CONFIG_NOT_ALLOWED')
    pg_guard()
    if args.action == 'setup':
        no_application_records()
        dev.setup(argparse.Namespace(port=config['port'], db_port=config['db_port']))
        if private_json(ROOT / '.runtime/workspace.json') != config:
            raise ValueError('OWN_SETUP_MISMATCH')
        print(json.dumps({'setup': True, 'database_started': False, 'web_started': False})); return
    if private_json(ROOT / '.runtime/workspace.json') != config:
        raise ValueError('OWN_SETUP_MISMATCH')
    mime_version_guard(ROOT)
    env = environment(config, args.candidate)
    for directory in (OUT, DATA, ROOT / '.runtime/logs', ROOT / '.runtime/pids'):
        private_dir(directory)
    def control(action):
        run = subprocess.run([PHP, str(ROOT / 'scripts/ops/c_window_control.php'), action], cwd=ROOT, env=env, capture_output=True, text=True)
        try: result = json.loads(run.stdout)
        except ValueError: result = None
        if run.returncode or not isinstance(result, dict):
            dev.write_private(OUT / 'control-error-private.txt', run.stdout + run.stderr)
            raise ValueError('CONTROL_FAILED_SEE_PRIVATE_DIAGNOSTICS')
        return result
    if args.action == 'seed':
        no_application_records()
        if (DATA / 'fixture.json').exists() or (OUT / 'seed-attempt.json').exists():
            raise ValueError('NO_RESEED_OR_RETRY_PARTIAL_SEED')
        dev.write_private(OUT / 'seed-attempt.json', json.dumps({'candidate': args.candidate, 'status': 'started_no_automatic_retry'}))
        dev.database_start()
        subprocess.run([PHP, 'artisan', 'migrate', '--force'], cwd=ROOT / 'h5-platform', env=env, check=True, capture_output=True)
        media = ROOT / '.runtime/06/analytics-media'; private_dir(media)
        subprocess.run(['/opt/homebrew/bin/ffmpeg', '-nostdin', '-v', 'error', '-n', '-f', 'lavfi', '-i', 'testsrc2=s=180x320:r=25',
                        '-t', '8', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', str(media / 'synthetic.mp4')], check=True, capture_output=True)
        seeded = control('seed'); state = control('state')
        if any(state['counts'][name] for name in ('identity_viewers', 'billing_prices', 'billing_orders', 'billing_payments', 'billing_entitlements',
                                                  'acquisition_subjects', 'acquisition_behaviors', 'acquisition_c_subjects', 'acquisition_c_events')):
            raise ValueError('SEED_MUST_NOT_CREATE_VIEWERS_PRICES_PAYMENTS_OR_MEASUREMENT')
        dev.write_private(OUT / 'seed-baseline.json', json.dumps(state, indent=2))
        print(json.dumps(seeded)); return
    fixture = private_json(DATA / 'fixture.json')
    if fixture.get('candidate') != args.candidate or fixture.get('synthetic_only') is not True:
        raise ValueError('FIXTURE_CANDIDATE_MISMATCH')
    if args.action == 'start':
        no_application_records(); port_available(config['port']); dev.database_start()
        process = subprocess.Popen(command(config), cwd=ROOT / 'h5-platform/public', env=env, stdin=subprocess.DEVNULL,
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        pidfile = ROOT / '.runtime/pids/web.json'; success = False
        try:
            time.sleep(.5)
            if process.poll() is not None: raise ValueError('C_WEB_FAILED_TO_START')
            record = {'root': str(ROOT), 'candidate': args.candidate, 'pid': process.pid, 'command': command(config),
                      'started_at': subprocess.check_output(['ps', '-p', str(process.pid), '-o', 'lstart='], text=True).strip(),
                      'manifest_sha256': hashlib.sha256(MANIFEST.read_bytes()).hexdigest()}
            dev.write_private(OUT / 'process.json', json.dumps(record, indent=2))
            dev.write_private(pidfile, json.dumps({'pid': process.pid, 'marker': str(ROUTER)}))
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            with opener.open(f'http://127.0.0.1:{config["port"]}/health', timeout=20) as response:
                if response.status != 200 or response.headers.get('X-06-C-Gateway') != 'UnconfiguredGateway':
                    raise ValueError('C_HEALTH_FAILED')
            state = control('state')
            dev.write_private(OUT / 'live-receipt.json', json.dumps({**record, 'state': state, 'queue_started': False, 'scheduler_started': False}, indent=2))
            success = True
            print(json.dumps({'started': True, 'candidate': args.candidate, 'port': config['port'], 'db_port': config['db_port'],
                              'payment_fixture_installed': False, 'queue_started': False, 'scheduler_started': False})); return
        finally:
            if not success:
                if process.poll() is None:
                    os.killpg(process.pid, signal.SIGTERM); process.wait(timeout=5)
                if pidfile.exists() and private_json(pidfile).get('pid') == process.pid: pidfile.unlink()
                (OUT / 'live-receipt.json').unlink(missing_ok=True)
                dev.write_private(OUT / 'startup-failed.json', json.dumps({'candidate': args.candidate, 'own_web_cleaned': True, 'own_pg_may_remain': True}))
    if args.action in ('state', 'checkpoint'):
        state = control('state')
        if args.action == 'checkpoint':
            if not re.fullmatch('[a-z0-9-]{1,48}', args.label): raise ValueError('SAFE_CHECKPOINT_LABEL_REQUIRED')
            path = OUT / (args.label + '.json')
            if path.exists(): raise ValueError('CHECKPOINT_EXISTS_NO_OVERWRITE')
            dev.write_private(path, json.dumps(state, indent=2))
        print(json.dumps(state)); return
    if args.action == 'backup':
        # No writes to application state or restore over another database. Quiesce own web first for a stable dump/state boundary.
        record_path = OUT / 'process.json'
        if record_path.exists() and matching(private_json(record_path), config):
            raise ValueError('STOP_WEB_FOR_CONSISTENT_BACKUP_USE_QUIESCE_FIRST')
        no_application_records()
        directory = OUT / 'backups' / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ'); private_dir(directory)
        subprocess.run([str(dev.PG / 'pg_dump'), '-Fc', '--no-owner', '--no-acl', '-f', str(directory / 'database.dump')], env=dev.pg_env(testing=True), check=True, capture_output=True)
        subprocess.run([str(dev.PG / 'pg_restore'), '--list', str(directory / 'database.dump')], check=True, capture_output=True)
        media = DATA / 'media'
        if media.is_symlink() or any(path.is_symlink() for path in media.rglob('*')): raise ValueError('OWN_REGULAR_MEDIA_REQUIRED')
        shutil.copytree(media, directory / 'media')
        for source, name in ((ROOT / 'h5-platform/.env', 'environment-private'), (DATA / 'owner.json', 'owner-private.json'),
                             (DATA / 'fixture.json', 'fixture-private.json'), (MANIFEST, 'manifest-private.json')):
            private_json(source) if source.suffix == '.json' else None
            if source.is_symlink() or source.stat().st_nlink != 1: raise ValueError('OWN_REGULAR_BACKUP_SOURCE_REQUIRED')
            shutil.copyfile(source, directory / name); (directory / name).chmod(0o600)
        state = control('state'); dev.write_private(directory / 'state.json', json.dumps(state, indent=2))
        hashes = {str(path.relative_to(directory)): hashlib.sha256(path.read_bytes()).hexdigest() for path in directory.rglob('*') if path.is_file()}
        dev.write_private(directory / 'sha256.json', json.dumps(hashes, indent=2))
        print(json.dumps({'private_backup': str(directory), 'restoration_executed': False})); return


if __name__ == '__main__':
    try: main()
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        raise SystemExit('06 C window refused/failed. Inspect own private receipts; no credentials printed.') from None
