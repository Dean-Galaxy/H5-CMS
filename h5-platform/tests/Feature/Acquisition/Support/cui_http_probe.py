#!/usr/bin/env python3
"""Explicit synthetic C/v1 compatibility HTTP acceptance on this checkout's dedicated test DB. No shared runtime."""
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
DB = config['db'] + '_cui1_http_test'
owned = json.loads((dev.RUN / 'c-ui-1-owned.json').read_text())
assert owned['root'] == str(ROOT) and DB in owned['databases'], 'HTTP requires this checkout’s marked synthetic DB'

env = os.environ.copy()
env.update(APP_ENV='local', APP_DEBUG='false', H5_LOCAL_MODULES='acquisition',
           H5_WORKSPACE=config['workspace'] + '_cui1_http', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1',
           DB_PORT=str(config['db_port']), DB_DATABASE=DB, DB_USERNAME=config['user'],
           DB_PASSWORD=(dev.RUN / 'pg-app-password').read_text(), DB_URL='', SESSION_DRIVER='database',
           CACHE_STORE='database', QUEUE_CONNECTION='sync', ACQUISITION_ANALYTICS_ENABLED='true',
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


def start(experience, enabled=True):
    global process, port, base
    port = dev.select_port(8705)
    base = 'http://127.0.0.1:' + str(port)
    env['APP_URL'] = base
    env.update(H5_C_EXPERIENCE_ENABLED='true' if experience else 'false', ACQUISITION_C_ENABLED='true' if enabled else 'false',
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
    assert int(sql('SELECT count(*) FROM migrations')) == 25, 'Migrate only this marked synthetic DB first'
    assert counts() == dict.fromkeys(counts(), 0), 'HTTP DB must contain no previous C measurements'
    start(False)
    opener, jar = client()
    status, html = request(opener, 'GET', '/identity/login')
    token = re.search(r'name="_token"\s+value="([^"]+)"', html).group(1)
    check('necessary guest page and genuine session available', status == 200, status)
    grant = {'analytics': True, 'advertising': True, 'policy_version': 'analytics-v1'}
    status, body = request(opener, 'POST', '/api/v1/tracking/consent', grant, token, base)
    check('normal mode old explicit grant works', status == 200 and body['consent'] == {'analytics': 'granted', 'advertising': 'granted'}, status)
    check('grant alone creates no analysis graph', sql('SELECT count(*) FROM acquisition_subjects') == '0')
    stop()
    start(True)
    event = {'event_id': str(uuid.uuid4()), 'event_name': 'page_view', 'properties': {'page_instance_id': str(uuid.uuid4()), 'path': '/'}}
    status, body = request(opener, 'POST', '/api/v1/tracking/events', {'events': [event]}, token, base)
    check('C experience suppresses stale granted v1 HTTP event', status == 200 and body['accepted'] == 0, status)
    status, body = request(opener, 'GET', '/api/v2/measurement/context')
    check('C context remains off before visible notice start', status == 200 and body['site_effective'] == 'off', status)
    start_body = {'notice_version': 'c-notice-c-core-1', 'page_instance_id': str(uuid.uuid4())}
    status, _ = request(opener, 'POST', '/api/v2/measurement/start', start_body)
    check('actual web CSRF rejects missing token', status == 419, status)
    status, body = request(opener, 'POST', '/api/v2/measurement/start', start_body, token, base)
    check('explicit synthetic C starts without granting old analysis', status == 200 and body['site_effective'] == 'on', status)
    cookies = [c for c in jar if c.name.startswith(env['H5_WORKSPACE'] + '_c_')]
    check('C cookies remain HttpOnly SameSite Lax', len(cookies) == 3 and all(c.has_nonstandard_attr('httponly') and c.get_nonstandard_attr('samesite') == 'lax' for c in cookies))
    c_event = {'event_id': str(uuid.uuid4()), 'event_name': 'page_view', 'properties': {'page_instance_id': start_body['page_instance_id'], 'page_type': 'catalog'}}
    for label in ['C event accepted', 'C identical replay accepted']:
        status, body = request(opener, 'POST', '/api/v2/measurement/events', {'events': [c_event]}, token, base)
        check(label, status == 200 and body['accepted'] == 1, status)
    check('C replay and v1 attempt give exactly one C and zero v1 events', counts()['events'] == 1 and sql('SELECT count(*) FROM acquisition_behaviors') == '0')
    pref = {'site_session': 'deny', 'notice_version': 'c-notice-c-core-1', 'expected_revision': 0}
    status, body = request(opener, 'PATCH', '/api/v2/measurement/preferences', pref, token, base)
    check('C denial confirmed server side', status == 200 and body['site_choice'] == 'deny' and body['site_effective'] == 'off', status)
    check('bridge denies analysis only on existing privacy proof', sql("SELECT count(*) FROM acquisition_privacy WHERE analytics='denied' AND advertising='granted' AND site_analysis_denied_at IS NOT NULL") == '1')
    status, body = request(opener, 'GET', '/api/v2/measurement/context')
    check('refresh preserves denial', status == 200 and body['site_choice'] == 'deny', status)
    status, _ = request(opener, 'PATCH', '/api/v2/measurement/preferences', pref, token, 'https://attacker.invalid')
    check('cross origin cannot alter refusal', status == 403, status)
    sql('ALTER TABLE acquisition_c_preferences RENAME TO acquisition_c_preferences_http_fault')
    try:
        status, body = request(opener, 'GET', '/api/v2/measurement/context')
        check('C read failure reports unavailable and off', status == 503 and body['reason'] == 'MEASUREMENT_UNAVAILABLE' and body['site_effective'] == 'off', status)
        status, body = request(opener, 'POST', '/api/v1/tracking/events', {'events': [event]}, token, base)
        check('C storage failure does not fall back to v1 HTTP', status == 200 and body['accepted'] == 0, status)
    finally:
        sql('ALTER TABLE acquisition_c_preferences_http_fault RENAME TO acquisition_c_preferences')
    stop()
    start(True, False)
    status, body = request(opener, 'GET', '/api/v2/measurement/context')
    check('disabled C with experience selected stays off', status == 200 and body['enabled'] is False and body['site_choice'] == 'deny', status)
    status, body = request(opener, 'POST', '/api/v1/tracking/events', {'events': [event]}, token, base)
    check('C total feature off still suppresses old event', status == 200 and body['accepted'] == 0, status)
    stop()
    start(False, False)
    status, body = request(opener, 'POST', '/api/v1/tracking/consent', grant, token, base)
    check('fallback old positive API cannot override C denial', status == 200 and body['consent'] == {'analytics': 'denied', 'advertising': 'granted'}, status)
    status, body = request(opener, 'POST', '/api/v1/tracking/events', {'events': [event]}, token, base)
    check('normal mode after refusal records no old behavior', status == 200 and body['accepted'] == 0, status)
    status, body = request(opener, 'POST', '/api/v1/tracking/consent', {'analytics': False, 'advertising': False, 'policy_version': 'analytics-v1'}, token, base)
    check('separate advertising withdrawal requires old consent endpoint', status == 200 and body['consent']['advertising'] == 'denied', status)
    check('no analysis account or order graph created', sql('SELECT (SELECT count(*) FROM acquisition_subjects)+(SELECT count(*) FROM acquisition_analytics_orders)') == '0')
    check('no source payment or delivery writes from compatibility HTTP', sql('SELECT (SELECT count(*) FROM acquisition_sources)+(SELECT count(*) FROM acquisition_receipts)+(SELECT count(*) FROM acquisition_deliveries)') == '0')
    check('all C events explicitly synthetic', sql('SELECT count(*) FROM acquisition_c_events WHERE is_test=false') == '0')
finally:
    stop()

out = ROOT / 'docs/evidence/05/c-ui-1-20261030'
out.mkdir(parents=True, exist_ok=True)
result = {'scope': 'explicit local synthetic compatibility HTTP only; no browser/manual qualification',
          'checks': results, 'passed': len(results), 'owned_http_starts': len(stopped), 'owned_http_stopped': all(stopped),
          'queue_scheduler_started': False, 'external_sending_enabled': False, 'other_runtime_touched': False}
(out / 'http.json').write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
