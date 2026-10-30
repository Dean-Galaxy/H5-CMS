#!/usr/bin/env python3
"""Explicit synthetic C-only HTTP acceptance on this checkout's dedicated test DB. No shared runtime."""
import datetime
import http.cookiejar
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[5]
spec = importlib.util.spec_from_file_location('h5dev', ROOT / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
config = dev.config()  # Rejects a runtime copied from a different checkout.
if (dev.APP / 'bootstrap/cache/config.php').exists():
    raise SystemExit('Refuse cached Laravel config: it could override the selected synthetic DB.')
DB = config['db'] + '_ccore1_http_test'
owned = json.loads((dev.RUN / 'c-core-1-owned.json').read_text())
assert owned['root'] == str(ROOT) and DB in owned['databases'], 'HTTP requires this checkout’s marked synthetic DB'

env = os.environ.copy()
env.update(APP_ENV='local', APP_DEBUG='false', H5_LOCAL_MODULES='acquisition',
           H5_WORKSPACE=config['workspace'] + '_ccore1_http', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1',
           DB_PORT=str(config['db_port']), DB_DATABASE=DB, DB_USERNAME=config['user'],
           DB_PASSWORD=(dev.RUN / 'pg-app-password').read_text(), DB_URL='', SESSION_DRIVER='database',
           CACHE_STORE='database', QUEUE_CONNECTION='sync', ACQUISITION_ANALYTICS_ENABLED='false',
           ACQUISITION_TRANSPORT='disabled', ACQUISITION_EXTERNAL_ENABLED='false',
           ACQUISITION_C_SYNTHETIC_UNTIL=(datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=1)).isoformat())
pg = dev.pg_env()
pg['PGDATABASE'] = DB


def sql(query):
    return subprocess.run([str(dev.PG / 'psql'), '-At', '-v', 'ON_ERROR_STOP=1', '-c', query],
                          env=pg, check=True, capture_output=True, text=True).stdout.strip()


def counts():
    return {name: int(sql('SELECT count(*) FROM acquisition_c_' + name))
            for name in ['preferences', 'subjects', 'sessions', 'events', 'event_keys', 'plays']}


port = dev.select_port(8705)
base = 'http://127.0.0.1:' + str(port)
env['APP_URL'] = base
results = []
process = None
stopped = []


def listening():
    try:
        with socket.create_connection(('127.0.0.1', port), timeout=.2):
            return True
    except OSError:
        return False


def stop():
    global process
    if process:
        owned = process
        process = None
        try:
            os.killpg(owned.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        owned.wait(timeout=10)
        for _ in range(50):
            if not listening():
                break
            time.sleep(.1)
        stopped.append(not listening())
        assert stopped[-1], 'Owned test HTTP listener was not stopped'


def start(enabled):
    global process, port, base
    port = dev.select_port(8705)
    base = 'http://127.0.0.1:' + str(port)
    env['APP_URL'] = base
    env.update(ACQUISITION_C_ENABLED='true' if enabled else 'false',
               ACQUISITION_C_SYNTHETIC_POLICY='true' if enabled else 'false')
    process = subprocess.Popen([dev.PHP, str(dev.APP / 'artisan'), 'serve', '--host=127.0.0.1', '--port=' + str(port), '--no-reload'],
                               cwd=dev.APP, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    for _ in range(100):
        assert process.poll() is None, 'Owned HTTP process exited early'
        if listening():
            return
        time.sleep(.1)
    raise AssertionError('Owned HTTP startup timeout')


def client():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar)), jar


def request(opener, method, path, payload=None, token=None, origin=None):
    headers = {'Accept': 'application/json' if path.startswith('/api/') else 'text/html'}
    if payload is not None:
        headers['Content-Type'] = 'application/json'
    if token:
        headers['X-CSRF-TOKEN'] = token
    if origin:
        headers['Origin'] = origin
    req = urllib.request.Request(base + path, data=json.dumps(payload).encode() if payload is not None else None, method=method, headers=headers)
    try:
        r = opener.open(req, timeout=10)
    except urllib.error.HTTPError as e:
        r = e
    body = r.read().decode()
    if path.startswith('/api/'):
        try:
            return r.status, json.loads(body)
        except json.JSONDecodeError:
            dev.write_private(dev.RUN / 'c-http-unexpected-response.txt', body)
            raise AssertionError('Non-JSON API response; status=' + str(r.status) + '; private diagnostic saved')
    return r.status, body


def check(label, condition, status=None):
    assert condition, label
    results.append({'check': label, 'passed': True, **({'status': status} if status else {})})


try:
    assert int(sql("SELECT count(*) FROM migrations")) == 24, 'Prepare only this dedicated synthetic DB with migrate first'
    start(False)
    opener, jar = client()
    before = counts()
    status, context = request(opener, 'GET', '/api/v2/measurement/context')
    sample = json.loads((ROOT / 'docs/contracts/examples/measurement-c-disabled.json').read_text())
    check('default disabled matches frozen example', status == 200 and context == sample, status)
    check('context creates no measurement records', counts() == before)
    stop()
    start(True)
    opener, jar = client()
    before = counts()
    status, context = request(opener, 'GET', '/api/v2/measurement/context')
    check('explicit synthetic policy is eligible but off before start', status == 200 and context['reason'] == 'NOTICE_NOT_READY', status)
    check('before-start context creates no measurement records', counts() == before)
    start_body = {'notice_version': 'c-notice-c-core-1', 'page_instance_id': str(uuid.uuid4())}
    status, _ = request(opener, 'POST', '/api/v2/measurement/start', start_body)
    check('actual web CSRF rejects missing token', status == 419, status)
    status, html = request(opener, 'GET', '/identity/login')
    check('existing guest login page supplies necessary CSRF session', status == 200, status)
    match = re.search(r'name="_token"\s+value="([^"]+)"', html)
    assert match, 'No CSRF token in normal login page'
    token = match.group(1)
    status, context = request(opener, 'POST', '/api/v2/measurement/start', start_body, token, base)
    check('notice start works with genuine session and CSRF', status == 200 and context['site_effective'] == 'on' and context['site_choice'] == 'unset', status)
    c_cookies = [c for c in jar if c.name.startswith(env['H5_WORKSPACE'] + '_c_')]
    check('measurement cookies are HttpOnly and SameSite=Lax', len(c_cookies) == 3 and all(c.has_nonstandard_attr('httponly') and c.get_nonstandard_attr('samesite') == 'lax' for c in c_cookies))
    e = {'event_id': str(uuid.uuid4()), 'event_name': 'page_view', 'properties': {'page_instance_id': start_body['page_instance_id'], 'page_type': 'catalog'}}
    for label in ['first event', 'identical replay']:
        status, body = request(opener, 'POST', '/api/v2/measurement/events', {'events': [e]}, token, base)
        check(label, status == 200 and body == {'accepted': 1, 'rejected': 0}, status)
    check('replay increases raw events once', counts()['events'] == before['events'] + 1)
    changed = json.loads(json.dumps(e)); changed['properties']['page_type'] = 'home'
    status, body = request(opener, 'POST', '/api/v2/measurement/events', {'events': [changed]}, token, base)
    check('changed replay rejected', status == 200 and body['rejected'] == 1, status)
    forged = json.loads(json.dumps(e)); forged['event_id'] = str(uuid.uuid4()); forged['event_name'] = 'payment_confirmed'
    status, _ = request(opener, 'POST', '/api/v2/measurement/events', {'events': [forged]}, token, base)
    check('public payment success forbidden', status == 422, status)
    pref = {'site_session': 'deny', 'notice_version': 'c-notice-c-core-1', 'expected_revision': 0}
    status, context = request(opener, 'PATCH', '/api/v2/measurement/preferences', pref, token, base)
    check('deny confirmed server-side', status == 200 and context['site_effective'] == 'off' and context['revision'] == 1, status)
    pref['site_session'] = 'allow'
    status, context = request(opener, 'PATCH', '/api/v2/measurement/preferences', pref, token, base)
    check('stale allow cannot overwrite deny', status == 409 and context['site_choice'] == 'deny', status)
    status, body = request(opener, 'POST', '/api/v2/measurement/events', {'events': [e]}, token, base)
    check('event after deny is not collected', status == 200 and body['accepted'] == 0, status)
    status, _ = request(opener, 'POST', '/api/v2/measurement/start', start_body, token, 'https://attacker.invalid')
    check('cross-origin start rejected', status == 403, status)
    status, body = request(opener, 'GET', '/api/v1/tracking/context')
    check('legacy pipeline stays separately disabled', status == 200 and body['enabled'] is False, status)
    check('no C real-data flags', int(sql('SELECT count(*) FROM acquisition_c_events WHERE is_test=false')) == 0)
    check('no legacy source or payment writes', sql('SELECT (SELECT count(*) FROM acquisition_sources)+(SELECT count(*) FROM acquisition_receipts)') == '0')
finally:
    stop()

out = ROOT / 'docs/evidence/05/c-core-1-20261030'
out.mkdir(parents=True, exist_ok=True)
result = {'scope': 'explicit local synthetic HTTP only; not real eligibility', 'http_port': port,
          'checks': results, 'passed': len(results), 'owned_http_starts': len(stopped), 'owned_http_stopped': all(stopped),
          'queue_scheduler_started': False, 'external_sending_enabled': False,
          'other_runtime_touched': False}
(out / 'http.json').write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
