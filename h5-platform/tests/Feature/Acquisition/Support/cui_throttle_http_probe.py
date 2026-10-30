#!/usr/bin/env python3
"""Explicit synthetic C throttle pressure HTTP acceptance on this checkout's dedicated test DB. No shared runtime."""
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
DB = config['db'] + '_cui_throttle_http_test'
owned = json.loads((dev.RUN / 'c-ui-throttle-owned.json').read_text())
assert owned['root'] == str(ROOT) and DB in owned['databases'], 'HTTP requires this checkout’s marked synthetic DB'

env = os.environ.copy()
env.update(APP_ENV='local', APP_DEBUG='false', H5_LOCAL_MODULES='acquisition',
           H5_WORKSPACE=config['workspace'] + '_cui_throttle_http', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1',
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


def request(opener, method, path, payload=None, token=None, origin=None, extra=None):
    headers = {'Accept': 'application/json' if path.startswith('/api/') else 'text/html'}
    if payload is not None:
        headers['Content-Type'] = 'application/json'
    if token:
        headers['X-CSRF-TOKEN'] = token
    if origin:
        headers['Origin'] = origin
    headers.update(extra or {})
    req = urllib.request.Request(base + path, data=json.dumps(payload).encode() if payload is not None else None, method=method, headers=headers)
    try:
        r = opener.open(req, timeout=10)
    except urllib.error.HTTPError as e:
        r = e
    body = r.read().decode()
    if path.startswith('/api/'):
        try:
            return r.status, json.loads(body), r.headers
        except json.JSONDecodeError:
            dev.write_private(dev.RUN / 'c-http-unexpected-response.txt', body)
            raise AssertionError('Non-JSON API response; status=' + str(r.status) + '; private diagnostic saved')
    return r.status, body, r.headers


def check(label, condition, status=None):
    assert condition, label
    results.append({'check': label, 'passed': True, **({'status': status} if status else {})})


def browser():
    opener, jar = client()
    status, html, _ = request(opener, 'GET', '/identity/login')
    assert status == 200, 'Necessary guest page unavailable'
    match = re.search(r'name="_token"\s+value="([^"]+)"', html)
    assert match, 'Expected genuine CSRF session'
    return opener, match.group(1)


def preference(opener, token, revision, extra=None):
    return request(opener, 'PATCH', '/api/v2/measurement/preferences',
                   {'site_session': 'deny', 'notice_version': 'c-notice-c-core-1', 'expected_revision': revision}, token, base, extra)


def proof_snapshot():
    return sql("SELECT coalesce(jsonb_agg(to_jsonb(p) ORDER BY id),'[]'::jsonb) FROM acquisition_c_preferences p")


def limited(status, body, headers):
    return (status == 429 and body.get('error', {}).get('code') == 'RATE_LIMITED'
            and int(headers.get('Retry-After', '0')) > 0 and headers.get('X-RateLimit-Remaining') == '0'
            and 'no-store' in headers.get('Cache-Control', ''))


windows = []
try:
    assert int(sql('SELECT count(*) FROM migrations')) == 25
    assert sum(counts().values()) == 0, 'Use only a fresh marked throttle HTTP synthetic DB'
    start(True)
    a, a_token = browser()
    began = time.monotonic()
    for _ in range(120):
        status, body, _ = request(a, 'GET', '/api/v2/measurement/context')
        assert status == 200
    status, body, headers = request(a, 'GET', '/api/v2/measurement/context')
    check('120 context calls exhaust only context budget', limited(status, body, headers), status)
    for _ in range(60):
        status, body, _ = request(a, 'POST', '/api/v2/measurement/start',
                                  {'notice_version': 'c-notice-c-core-1', 'page_instance_id': str(uuid.uuid4())}, a_token, base)
        assert status == 200 and body['site_effective'] == 'on'
    status, body, headers = request(a, 'POST', '/api/v2/measurement/start',
                                  {'notice_version': 'c-notice-c-core-1', 'page_instance_id': str(uuid.uuid4())}, a_token, base)
    check('60 starts remain independent then enforce their own limit', limited(status, body, headers), status)
    event = {'event_id': str(uuid.uuid4()), 'event_name': 'page_view',
             'properties': {'page_instance_id': str(uuid.uuid4()), 'page_type': 'catalog'}}
    for _ in range(120):
        status, body, _ = request(a, 'POST', '/api/v2/measurement/events', {'events': [event]}, a_token, base)
        assert status == 200 and body['accepted'] == 1
    status, body, headers = request(a, 'POST', '/api/v2/measurement/events', {'events': [event]}, a_token, base)
    check('120 event calls enforce a separate event budget', limited(status, body, headers), status)
    check('retries do not duplicate raw events', counts()['events'] == 1)
    status, body, _ = preference(a, a_token, 0)
    check('all optional budgets exhausted but necessary denial still saves', status == 200 and body['site_choice'] == 'deny' and body['site_effective'] == 'off' and body['revision'] == 1, status)
    for revision in range(1, 30):
        status, body, _ = preference(a, a_token, revision)
        assert status == 200 and body['revision'] == revision + 1
    before = proof_snapshot()
    status, body, headers = preference(a, a_token, 30)
    check('31st preference attempt is independently limited with retry headers', limited(status, body, headers), status)
    check('preference quota rejection writes no proof or revision', proof_snapshot() == before)
    status, body, headers = preference(a, a_token, 30, {'X-Viewer-Id': str(uuid.uuid4()), 'X-Session-Id': str(uuid.uuid4()),
                                                     'X-Forwarded-For': '203.0.113.111'})
    check('forged viewer session and forwarding headers cannot replenish quota', limited(status, body, headers) and proof_snapshot() == before, status)
    status, body, _ = request(a, 'GET', '/api/v1/tracking/context')
    check('C pressure leaves original v1 endpoint budget independent', status == 200, status)
    b, b_token = browser()
    status, body, _ = preference(b, None, 0)
    check('missing actual CSRF still rejected', status == 419 and proof_snapshot() == before, status)
    status, body, _ = preference(b, a_token, 0)
    check('another session CSRF token is not authority', status == 419 and proof_snapshot() == before, status)
    payload = {'site_session': 'deny', 'notice_version': 'c-notice-c-core-1', 'expected_revision': 0}
    status, body, _ = request(b, 'PATCH', '/api/v2/measurement/preferences', payload, b_token, 'https://attacker.invalid')
    check('genuine session still cannot save cross origin', status == 403 and proof_snapshot() == before, status)
    status, body, _ = request(b, 'PATCH', '/api/v2/measurement/preferences', payload | {'viewer_id': str(uuid.uuid4())}, b_token, base)
    check('browser supplied identity remains invalid and writes nothing', status == 422 and proof_snapshot() == before, status)
    status, body, _ = preference(b, b_token, 0)
    check('another genuine session can independently close on same IP', status == 200 and body['revision'] == 1 and body['site_choice'] == 'deny', status)
    before = proof_snapshot()
    status, body, headers = preference(a, a_token, 30)
    check('other session cannot donate quota to exhausted original session', limited(status, body, headers) and proof_snapshot() == before, status)
    payload['site_session'] = 'allow'
    payload['expected_revision'] = 30
    status, body, headers = request(a, 'PATCH', '/api/v2/measurement/preferences', payload, a_token, base)
    check('rate limited allow cannot rewrite a confirmed denial', limited(status, body, headers) and proof_snapshot() == before, status)
    elapsed = time.monotonic() - began
    check('optional pressure and refusal checked within one real 60 second window', elapsed < 55)
    windows.append(round(elapsed, 2))
    stop()

    # Separate necessary sessions/cache namespace; no cache clearing or rate-limiter bypass between requests.
    env['H5_WORKSPACE'] = config['workspace'] + '_cui_throttle_ip_abuse'
    start(True)
    began = time.monotonic()
    before_count = counts()['preferences']
    for browser_index in range(6):
        opener, token = browser()
        for revision in range(30):
            status, body, _ = preference(opener, token, revision)
            assert status == 200 and body['revision'] == revision + 1
        status, body, headers = preference(opener, token, 30)
        assert limited(status, body, headers)
    check('six genuine sessions each have 30 independent preference attempts', counts()['preferences'] == before_count + 6)
    before = proof_snapshot()
    opener, token = browser()
    status, body, headers = preference(opener, token, 0, {'X-Forwarded-For': '203.0.113.222'})
    check('rotating sessions cannot bypass 180 per IP preference ceiling', limited(status, body, headers) and proof_snapshot() == before, status)
    status, body, _ = request(opener, 'GET', '/api/v2/measurement/context')
    check('preference abuse cannot consume independent context quota', status == 200, status)
    elapsed = time.monotonic() - began
    check('IP rotation ceiling verified within one real 60 second window', elapsed < 55)
    windows.append(round(elapsed, 2))
    check('no legacy analysis source payment or delivery writes', sql('SELECT (SELECT count(*) FROM acquisition_subjects)+(SELECT count(*) FROM acquisition_behaviors)+(SELECT count(*) FROM acquisition_sources)+(SELECT count(*) FROM acquisition_receipts)+(SELECT count(*) FROM acquisition_deliveries)') == '0')
    check('C pressure events are explicitly synthetic', sql('SELECT count(*) FROM acquisition_c_events WHERE is_test=false') == '0')
finally:
    stop()

out = ROOT / 'docs/evidence/05/c-ui-throttle-20261030'
out.mkdir(parents=True, exist_ok=True)
result = {'scope': '05 fresh marked local synthetic HTTP pressure only; no page/manual acceptance',
          'checks': results, 'passed': len(results), 'window_seconds': windows,
          'owned_http_starts': len(stopped), 'owned_http_stopped': all(stopped), 'cache_cleared_during_pressure': False,
          'limits_per_minute': {'context_ip': 120, 'start_ip': 60, 'events_ip': 120, 'preferences_session': 30, 'preferences_ip': 180},
          'queue_scheduler_started': False, 'external_sending_enabled': False, 'other_runtime_touched': False}
(out / 'http-pressure.json').write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
