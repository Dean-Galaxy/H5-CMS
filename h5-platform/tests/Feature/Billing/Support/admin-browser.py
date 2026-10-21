#!/usr/bin/env python3
"""03 isolated browser test runtime. No production/local DB fixture writes or credentials output."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[5]
SPEC = importlib.util.spec_from_file_location('billing_workspace', ROOT / 'scripts/dev.py')
DEV = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEV)
CONFIG = DEV.config()
RUN = ROOT / '.runtime'
RECORD = RUN / '03-admin-browser-server.json'
FIXTURE = Path(__file__).with_name('admin-browser-fixture.php')
BLOCKED = 'curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen'


def environment(port, for_http=False):
    env = os.environ.copy()
    env.update(APP_ENV='testing', APP_DEBUG='false', APP_URL=f'http://127.0.0.1:{port}',
               APP_RUNNING_IN_CONSOLE='false' if for_http else 'true',
               APP_CONFIG_CACHE=str(RUN / '03-admin-browser-config-must-not-exist.php'),
               DB_CONNECTION='pgsql', DB_URL='', DB_HOST='127.0.0.1', DB_PORT=str(CONFIG['db_port']),
               DB_DATABASE=CONFIG['db'] + '_test', DB_USERNAME=CONFIG['user'],
               DB_PASSWORD=(RUN / 'pg-app-password').read_text(),
               H5_TEST_DATABASE=CONFIG['db'] + '_test', H5_WORKSPACE=CONFIG['workspace'] + '_test',
               H5_LOCAL_MODULES='', H5_CONTROLLED_STAGING='false', H5_SIMULATION='false',
               H5_LOCAL_US_TEST_REGION='false', H5_BILLING_DRIVER='unconfigured',
               H5_BILLING_STRIPE_TEST_SECRET='', H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET='',
               H5_BILLING_TEST_CLOCK_ID='null', MAIL_MAILER='array',
               SESSION_DRIVER='database', CACHE_STORE='database', SESSION_SECURE_COOKIE='false',
               SESSION_COOKIE=CONFIG['workspace'] + '_test', CACHE_PREFIX=CONFIG['workspace'] + '_test',
               DB_QUEUE=CONFIG['workspace'] + '_test')
    if Path(env['APP_CONFIG_CACHE']).exists():
        raise SystemExit('Refuse an existing browser configuration cache.')
    return env


def alive(record):
    result = subprocess.run(['ps', '-p', str(record['pid']), '-o', 'command='], capture_output=True, text=True)
    return result.returncode == 0 and record['marker'] in result.stdout


def run_php(args, port):
    return subprocess.run([DEV.PHP, '-d', 'disable_functions=' + BLOCKED, *map(str, args)],
                          cwd=DEV.APP, env=environment(port), check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['seed', 'start', 'stop', 'code', 'verify-ui'])
    parser.add_argument('role', nargs='?', choices=['owner', 'support', 'supportOther', 'content', 'acquisition'])
    parser.add_argument('--port', type=int, default=8303)
    args = parser.parse_args()
    if args.port in (CONFIG['port'], CONFIG['db_port']) or not 1024 <= args.port <= 65535:
        raise SystemExit('Use a separate unprivileged browser port.')
    if args.action == 'stop':
        if RECORD.exists():
            record = json.loads(RECORD.read_text())
            if record.get('root') != str(ROOT):
                raise SystemExit('Refuse a foreign runtime record.')
            if alive(record):
                os.kill(record['pid'], signal.SIGTERM)
                for _ in range(50):
                    if not alive(record):
                        break
                    time.sleep(.1)
                else:
                    raise SystemExit('Test server has not stopped; runtime record retained.')
            RECORD.unlink()
        print(json.dumps({'test_server': 'stopped', 'local_environment_untouched': True}))
        return
    if args.action == 'seed':
        if RECORD.exists() and alive(json.loads(RECORD.read_text())):
            raise SystemExit('Stop the test server before provisioning; do not run DB tests concurrently.')
        DEV.database_start()
        # Check the resolved connection before any migrations or fixture writes.
        run_php([FIXTURE, 'check'], args.port)
        run_php([DEV.APP / 'artisan', 'migrate', '--force', '--no-interaction'], args.port)
        run_php([FIXTURE, 'seed'], args.port)
        return
    if args.action == 'verify-ui':
        run_php([FIXTURE, 'verify-ui'], args.port)
        return
    if args.action == 'code':
        if not args.role:
            raise SystemExit('Choose the disposable fixture staff role.')
        run_php([FIXTURE, 'code', args.role], args.port)
        return
    if RECORD.exists() and alive(json.loads(RECORD.read_text())):
        raise SystemExit('This browser test server is already running.')
    if not DEV.available(args.port):
        raise SystemExit('Browser test port is in use; no other process was stopped.')
    if not (RUN / '03-admin-browser-credentials.json').is_file():
        raise SystemExit('Provision the guarded fixture after DB tests first.')
    marker = f'127.0.0.1:{args.port} -t {DEV.APP / "public"}'
    command = [DEV.PHP, '-d', 'disable_functions=' + BLOCKED, '-S', f'127.0.0.1:{args.port}',
               '-t', str(DEV.APP / 'public'), str(DEV.APP / 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')]
    with open(os.devnull, 'wb') as output:
        process = subprocess.Popen(command, cwd=DEV.APP / 'public', env=environment(args.port, for_http=True),
                                   stdout=output, stderr=output, stdin=subprocess.DEVNULL, start_new_session=True)
    DEV.write_private(RECORD, json.dumps({'root': str(ROOT), 'pid': process.pid, 'marker': marker,
                                         'port': args.port, 'database': CONFIG['db'] + '_test'}))
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for _ in range(40):
        try:
            with opener.open(f'http://127.0.0.1:{args.port}/admin/login', timeout=1) as response:
                if response.status == 200:
                    print(json.dumps({'test_server': 'ready', 'url': f'http://127.0.0.1:{args.port}/admin/login',
                                      'database': CONFIG['db'] + '_test', 'official_payment_verified': False}))
                    return
        except Exception:
            time.sleep(.2)
    raise SystemExit('Test server did not become ready; use stop after inspection.')


if __name__ == '__main__':
    main()
