#!/usr/bin/env python3
"""Explicit synthetic R1 choice-only HTTP acceptance on this checkout's dedicated test DB. No shared runtime."""
import argparse
import datetime
from html.parser import HTMLParser
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
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--fresh', action='store_true', help="Explicitly rebuild ONLY this checkout's marked synthetic HTTP DB")
args = parser.parse_args()
config = dev.config()  # Rejects a runtime copied from a different checkout.
if (dev.APP / 'bootstrap/cache/config.php').exists():
    raise SystemExit('Refuse cached Laravel config: it could override the selected synthetic DB.')
subprocess.run([os.sys.executable, str(Path(__file__).with_name('r1a_test.py')), 'http', 'prepare'], check=True, capture_output=True)
DB = config['db'] + '_r1a_http_test'
owned = json.loads((dev.RUN / 'r1-a-owned.json').read_text())
assert owned['root'] == str(ROOT) and DB in owned['databases'], 'HTTP requires this checkout’s marked synthetic DB'

env = os.environ.copy()
env.update(APP_ENV='local', APP_DEBUG='false', H5_LOCAL_MODULES='acquisition',
           H5_WORKSPACE=config['workspace'] + '_r1a_http', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1',
           DB_PORT=str(config['db_port']), DB_DATABASE=DB, DB_USERNAME=config['user'],
           DB_PASSWORD=(dev.RUN / 'pg-app-password').read_text(), DB_URL='', SESSION_DRIVER='database',
           CACHE_STORE='database', QUEUE_CONNECTION='sync', ACQUISITION_ANALYTICS_ENABLED='true',
           H5_C_REGISTRATION_ENABLED='true', ACQUISITION_R1_SYNTHETIC_POLICY='true', ACQUISITION_R1_OBSERVATIONS_ENABLED='false',
           ACQUISITION_R1_SYNTHETIC_FROM=datetime.datetime.now(datetime.timezone.utc).isoformat(),
           ACQUISITION_R1_SYNTHETIC_UNTIL=(datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=23)).isoformat(),
           MAIL_MAILER='array', ACQUISITION_TRANSPORT='disabled', ACQUISITION_EXTERNAL_ENABLED='false',
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


V3 = '/api/v3/measurement/purposes'
NOTICE = 'c-purpose-choice-only-r1-v1'

def token(opener):
    status, body, _ = request(opener, 'GET', '/api/v1/auth/csrf')
    assert status == 200
    return body['csrf_token']

def command(purpose='account_link', choice='deny', revision=0):
    return {'purpose': purpose, 'choice': choice, 'notice_version': NOTICE, 'expected_revision': revision, 'operation_id': str(uuid.uuid4())}

def patch(opener, csrf, body, origin=None, extra=None):
    return request(opener, 'PATCH', V3 + '/preferences', body, csrf, origin or base, extra)

def snapshot():
    return sql("SELECT coalesce(jsonb_agg(to_jsonb(p) ORDER BY proof_id,purpose),'[]'::jsonb) FROM acquisition_r1_choices p")

def race(inputs):
    worker_env = env.copy()
    worker_env.update(APP_ENV='testing', H5_TEST_DATABASE=DB)
    processes = []
    paths = []
    try:
        for item in inputs:
            path = dev.RUN / ('r1-worker-' + str(uuid.uuid4()) + '.json')
            dev.write_private(path, json.dumps(item)); paths.append(path)
            processes.append(subprocess.Popen([dev.PHP, str(Path(__file__).with_name('r1a-worker.php')), str(path)],
                             cwd=dev.APP, env=worker_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True))
        responses = []
        for child in processes:
            output, error = child.communicate(timeout=20)
            assert child.returncode == 0, 'Synthetic concurrent worker failed; no raw payload output'
            responses.append(json.loads(output))
        return responses
    finally:
        for child in processes:
            if child.poll() is None: child.kill(); child.wait()
        for path in paths: path.unlink(missing_ok=True)

try:
    expected_migrations = sorted(p.stem for p in (dev.APP / 'database/migrations').glob('*.php'))
    if args.fresh:
        subprocess.run([os.sys.executable, str(Path(__file__).with_name('r1a_test.py')), 'http', 'migrate:fresh', '--force'], check=True, capture_output=True)
    assert sql("SELECT to_regclass('public.migrations')") == 'migrations', 'Install current migrations on the marked synthetic DB, or explicitly use --fresh'
    installed_migrations = sql('SELECT migration FROM migrations ORDER BY migration').splitlines()
    assert installed_migrations == expected_migrations, 'Installed migration set differs from source; use --fresh ONLY for this marked synthetic DB'
    assert any(n.endswith('_create_identity_registration_receipts') for n in expected_migrations), 'This combined probe requires accepted 01-A'
    for table in ['acquisition_r1_proofs', 'identity_viewers', 'identity_registration_receipts', 'sessions', 'cache', 'cache_locks']:
        assert sql('SELECT count(*) FROM ' + table) == '0', 'Synthetic HTTP DB has previous data; refuse implicit clearing, use explicit --fresh'
    assert sum(counts().values()) == 0, 'Synthetic C data already present; use explicit --fresh'

    start(True)
    opener, jar = client(); csrf = token(opener)
    status, body, headers = request(opener, 'GET', V3 + '/context')
    check('R1 eligible choice-only context is read-only', status == 200 and body['schema_version'] == 3 and body['eligibility'] == 'eligible' and body['purposes']['account_link']['capability'] == 'choice_only' and sql('SELECT count(*) FROM acquisition_r1_proofs') == '0', status)
    status, body, _ = patch(opener, None, command())
    check('actual CSRF rejects preference without token', status == 419, status)
    status, body, _ = patch(opener, csrf, command(), 'https://attacker.invalid')
    check('cross-origin preference rejected', status == 403, status)
    status, body, _ = patch(opener, csrf, command(choice='allow'))
    check('anonymous allow requires web identity', status == 401, status)
    status, body, _ = patch(opener, csrf, command() | {'viewer_id': str(uuid.uuid4())})
    check('browser cannot supply identity', status == 422, status)
    status, body, _ = patch(opener, csrf, command() | {'extra': 'x' * 4100})
    check('4KiB request ceiling enforced', status == 413, status)
    status, body, headers = patch(opener, csrf, command())
    check('anonymous necessary deny saves without C start', status == 200 and body['status'] == 'saved' and body['state']['revision'] == 1 and body['state']['choice'] == 'deny', status)
    cookies = [c for c in jar if c.name == env['H5_WORKSPACE'] + '_r1_purpose']
    check('necessary proof cookie is HttpOnly SameSite Lax', len(cookies) == 1 and cookies[0].has_nonstandard_attr('httponly') and cookies[0].get_nonstandard_attr('samesite') == 'lax')
    proof_until = sql('SELECT expires_at FROM acquisition_r1_proofs')
    status, body, headers = request(opener, 'GET', V3 + '/context')
    check('GET neither queues R1 proof cookie nor extends proof', status == 200 and not any('_r1_purpose=' in h for h in headers.get_all('Set-Cookie', [])) and sql('SELECT expires_at FROM acquisition_r1_proofs') == proof_until, status)
    # Read accepted 01-A versions from its actually rendered form; keep R1 true throughout.
    class PublishedInputs(HTMLParser):
        def __init__(self):
            super().__init__(); self.values = {}
        def handle_starttag(self, tag, attrs):
            attrs = dict(attrs)
            if tag == 'input' and attrs.get('type') == 'hidden' and attrs.get('name') in ('terms_version', 'privacy_notice_version'):
                self.values[attrs['name']] = attrs.get('value', '')
    status, form, _ = request(opener, 'GET', '/identity/register')
    inputs = PublishedInputs(); inputs.feed(form)
    versions = inputs.values
    assert status == 200 and set(versions) == {'terms_version', 'privacy_notice_version'} and all(versions.values()), 'No published R1 test versions; never fall back to old registration'
    for route, key in [('test-terms', 'terms_version'), ('test-privacy', 'privacy_notice_version')]:
        doc_status, document, _ = request(opener, 'GET', '/identity/' + route)
        assert doc_status == 200 and versions[key] in document
    check('01-A form and both readable test documents publish real current versions', True)
    registration = {'name': 'Synthetic R1 HTTP', 'email': str(uuid.uuid4()) + '@example.test', 'password': 'SyntheticPass1234', 'password_confirmation': 'SyntheticPass1234'}
    status, body, _ = request(opener, 'POST', '/api/v1/auth/register', registration, csrf, base)
    check('R1 remains enabled and missing necessary acceptance really rejects creation', status == 422 and sql('SELECT count(*) FROM identity_viewers') == '0' and sql('SELECT count(*) FROM identity_registration_receipts') == '0', status)
    registration.update(versions, terms_accepted=True)
    status, body, _ = request(opener, 'POST', '/api/v1/auth/register', registration, csrf, base)
    receipt = json.loads(sql("SELECT to_jsonb(r) - 'viewer_id' - 'accepted_at' FROM identity_registration_receipts r")) if status in (200, 201) else {}
    check('01-A explicit acceptance creates account and correct necessary test receipt', status in (200, 201) and sql('SELECT count(*) FROM identity_viewers') == '1' and receipt.get('terms_version') == versions['terms_version'] and receipt.get('privacy_notice_version') == versions['privacy_notice_version'] and receipt.get('is_test') is True, status)
    csrf = token(opener)
    first_allow = command(choice='allow', revision=1)
    status, body, _ = patch(opener, csrf, first_allow)
    check('web allow saves intent but never runs account association', status == 200 and body['state']['choice'] == 'allow' and body['state']['effective'] is False and body['state']['reason'] == 'ASSOCIATION_NOT_READY', status)
    ad_allow = command('advertising', 'allow')
    status, body, _ = patch(opener, csrf, ad_allow)
    check('advertising intent does not create legacy grant', status == 200 and sql('SELECT count(*) FROM acquisition_privacy') == '0', status)
    status, body, _ = request(opener, 'POST', '/api/v1/tracking/consent', {'analytics': True, 'advertising': True, 'policy_version': 'analytics-v1'}, csrf, base)
    check('ordinary legacy grant before R1 refusal remains compatible', status == 200 and body['consent']['advertising'] == 'granted', status)
    ad_deny = command('advertising', 'deny', 1)
    status, body, _ = patch(opener, csrf, ad_deny)
    check('R1 ad denial really stops recognizable legacy proof', status == 200 and sql("SELECT count(*) FROM acquisition_privacy WHERE advertising='denied' AND r1_advertising_denied_at IS NOT NULL") == '1', status)
    status, body, _ = patch(opener, csrf, ad_allow)
    check('old allow replay confirms operation with CURRENT denial', status == 200 and body['state']['choice'] == 'deny' and body['state']['revision'] == 2, status)
    status, body, _ = patch(opener, csrf, command('advertising', 'allow', 2))
    check('new choice-only allow cannot erase legacy suppression', status == 200 and body['state']['choice'] == 'allow' and sql("SELECT count(*) FROM acquisition_privacy WHERE advertising='denied'") == '1', status)
    before = snapshot()
    sql('ALTER TABLE acquisition_r1_operations RENAME TO acquisition_r1_operations_http_fault')
    try:
        status, body, _ = patch(opener, csrf, command(revision=2))
        check('storage fault returns 503 without false saved result', status == 503 and body['error']['code'] == 'PURPOSE_UNAVAILABLE' and snapshot() == before, status)
        status, body, _ = request(opener, 'GET', '/api/v1/auth/me')
        check('preference failure preserves real current account', status == 200 and sql('SELECT count(*) FROM identity_viewers') == '1' and sql('SELECT count(*) FROM identity_registration_receipts') == '1', status)
    finally:
        sql('ALTER TABLE acquisition_r1_operations_http_fault RENAME TO acquisition_r1_operations')
    for _ in range(125):
        status, body, headers = request(opener, 'GET', V3 + '/context')
        if status == 429: break
        assert status == 200
    check('R1 context reaches its independent budget', status == 429 and 'no-store' in headers.get('Cache-Control', ''), status)
    status, body, _ = patch(opener, csrf, command('advertising', 'deny', 3))
    check('exhausted R1 reads do not block necessary preference denial', status == 200 and body['state']['choice'] == 'deny', status)
    for _ in range(40):
        status, body, headers = patch(opener, csrf, command('advertising', 'deny', 4))
        if status == 429: break
        assert status == 200
    check('R1 preference abuse is bounded with Retry-After', status == 429 and int(headers.get('Retry-After', '0')) > 0, status)
    status, body, _ = request(opener, 'PATCH', '/api/v2/measurement/preferences', {'site_session': 'deny', 'notice_version': 'c-notice-c-core-1', 'expected_revision': 0}, csrf, base)
    check('R1 pressure does not consume v2 necessary exit budget', status == 200 and body['site_choice'] == 'deny', status)
    stop()
    env['H5_C_REGISTRATION_ENABLED'] = 'false'
    start(False, False)
    csrf = token(opener)
    status, body, _ = request(opener, 'POST', '/api/v1/tracking/consent', {'analytics': False, 'advertising': True, 'policy_version': 'analytics-v1'}, csrf, base)
    check('R1 disabled and old frontend cannot revive ad grant', status == 200 and body['consent']['advertising'] == 'denied', status)
    stop()
    # Real independent processes exercise the exact service locks/CAS, on committed synthetic data.
    env.update(H5_C_REGISTRATION_ENABLED='true', H5_C_EXPERIENCE_ENABLED='true')
    actor = sql('SELECT id FROM identity_viewers LIMIT 1')
    proof = str(uuid.uuid4())
    sql("INSERT INTO acquisition_r1_proofs(id,created_at,expires_at,is_test) VALUES ('" + proof + "',now(),now()+interval '24 months',true)")
    spec = {'proof': proof, 'actor': actor, 'purpose': 'account_link', 'choice': 'allow', 'revision': 0, 'operation': str(uuid.uuid4())}
    responses = race([spec, spec])
    check('two processes replay same operation exactly once', [r['status'] for r in responses] == [200, 200] and sql("SELECT revision FROM acquisition_r1_choices WHERE proof_id='" + proof + "'") == '1' and sql("SELECT count(*) FROM acquisition_r1_operations WHERE proof_id='" + proof + "'") == '1')
    proof2 = str(uuid.uuid4())
    sql("INSERT INTO acquisition_r1_proofs(id,created_at,expires_at,is_test) VALUES ('" + proof2 + "',now(),now()+interval '24 months',true)")
    specs = [spec | {'proof': proof2, 'choice': choice, 'operation': str(uuid.uuid4())} for choice in ('allow', 'deny')]
    responses = race(specs)
    check('competing process CAS has one winner and truthful conflict', sorted(r['status'] for r in responses) == [200, 409] and sql("SELECT revision FROM acquisition_r1_choices WHERE proof_id='" + proof2 + "'") == '1')
    check('no C identity conversion or delivery graph created', sql('SELECT (SELECT count(*) FROM acquisition_c_events)+(SELECT count(*) FROM acquisition_c_subjects)+(SELECT count(*) FROM acquisition_subjects)+(SELECT count(*) FROM acquisition_analytics_orders)+(SELECT count(*) FROM acquisition_deliveries)') == '0')
finally:
    stop()

out = ROOT / 'docs/evidence/05/c-registration-r1-a-combined-20261031'; out.mkdir(parents=True, exist_ok=True)
result = {'scope': '05 + accepted 01-A combined synthetic HTTP/service checks; not 01-B facade or manual acceptance', 'checks': results, 'passed': len(results), 'source_migrations': expected_migrations, 'installed_migrations': installed_migrations, 'migration_count': len(installed_migrations), 'published_test_versions': versions, 'explicit_fresh': args.fresh,
          'owned_http_starts': len(stopped), 'owned_http_stopped': all(stopped), 'concurrent_worker_processes': 4,
          'queue_scheduler_started': False, 'external_sending_enabled': False, 'other_runtime_touched': False}
(out / 'http.json').write_text(json.dumps(result, indent=2) + '\n'); print(json.dumps(result))
