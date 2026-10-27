#!/usr/bin/env python3
"""Real local application and bridge denial probes; no official payment calls or credentials."""
import http.cookiejar
import http.client
import base64
from unittest.mock import patch
import json
from pathlib import Path
import subprocess
import threading
import urllib.error
import urllib.request
import uuid
from paypal_callback_bridge import Bridge, PAYPAL_HEADERS

ROOT = Path(__file__).resolve().parents[2]
PHP = '/opt/homebrew/opt/php@8.4/bin/php'
c = json.loads((ROOT / '.runtime/workspace.json').read_text())
assert c['root'] == str(ROOT)
base = f'http://127.0.0.1:{c["port"]}'
private = ROOT / '.runtime/06'
private.mkdir(mode=0o700, exist_ok=True)


def snapshot():
    return json.loads(subprocess.check_output([PHP, str(ROOT / 'scripts/ops/paypal_runtime_snapshot.php')], text=True))


before = snapshot()  # Abort if real provider is enabled; never touch the staged credential document.
checks = []
client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
webhook_client = urllib.request.build_opener(urllib.request.ProxyHandler({}))
upstream_proof = []
main_thread = threading.get_ident()
real_putrequest = http.client.HTTPConnection.putrequest
real_getresponse = http.client.HTTPConnection.getresponse

def traced_putrequest(connection, method, path, *args, **kwargs):
    if threading.get_ident() != main_thread and connection.host == '127.0.0.1' and connection.port == c['port']:
        connection._qa_fixed_target = {'host': connection.host, 'port': connection.port, 'method': method, 'path': path}
    return real_putrequest(connection, method, path, *args, **kwargs)

def traced_getresponse(connection, *args, **kwargs):
    response = real_getresponse(connection, *args, **kwargs)
    if hasattr(connection, '_qa_fixed_target'):
        upstream_proof.append({**connection._qa_fixed_target, 'status': response.status})
    return response


def request(origin, path, method='GET', data=None, headers=None):
    h = {'Accept': 'application/json', **(headers or {})}
    if data is not None:
        data = json.dumps(data).encode() if isinstance(data, dict) else data
        h.setdefault('Content-Type', 'application/json')
    req = urllib.request.Request(origin + path, data=data, headers=h, method=method)
    try:
        response = (webhook_client if origin != base else client).open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read()
    try:
        document = json.loads(body)
    except ValueError:
        document = None
    return response.status, document, body


def check(label, status, expected):
    checks.append({'check': label, 'status': status, 'expected': expected, 'passed': status in expected})
    assert status in expected, label + ': ' + str(status)


bridge = Bridge(8408, c['port'])
worker = threading.Thread(target=bridge.serve_forever, daemon=True)
worker.start()
bridge_origin = f'http://127.0.0.1:{bridge.server_port}'
trace_request = patch.object(http.client.HTTPConnection, 'putrequest', traced_putrequest)
trace_response = patch.object(http.client.HTTPConnection, 'getresponse', traced_getresponse)
trace_request.start()
trace_response.start()
try:
    status, _, _ = request(base, '/webhooks/paypal', 'POST', {})
    check('application unconfigured callback never acknowledged', status, [503])
    status, _, _ = request(bridge_origin, '/webhooks/paypal', 'POST', {})
    check('bridge missing signature headers rejected before upstream', status, [400])
    assert not upstream_proof
    headers = {'paypal-auth-algo': 'SHA256withRSA',
               'paypal-cert-url': 'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-SYNTHETIC06',
               'paypal-transmission-id': 'SYNTHETIC-TRANSMISSION-06',
               'paypal-transmission-sig': base64.b64encode(bytes(256)).decode(),
               'paypal-transmission-time': '2026-10-27T09:00:00Z'}
    event = {'id': 'WH-SYNTHETIC-06', 'event_type': 'PAYMENT.SALE.COMPLETED',
             'create_time': '2026-10-27T09:00:00Z', 'resource': {'id': 'SALE-SYNTHETIC-06'}}
    status, _, _ = request(bridge_origin, '/webhooks/paypal', 'POST', event, {**headers, 'Cookie': 'synthetic=1'})
    check('bridge explicitly rejects Cookie without forwarding', status, [403])
    assert not upstream_proof
    direct, _, _ = request(base, '/webhooks/paypal', 'POST', event, headers)
    check('direct application unconfigured synthetic event', direct, [503])
    status, _, body = request(bridge_origin, '/webhooks/paypal', 'POST', event, headers)
    check('cookie-free bridge receives actual application unconfigured response', status, [direct])
    assert body == b''
    assert upstream_proof == [{'host': '127.0.0.1', 'port': c['port'], 'method': 'POST', 'path': '/webhooks/paypal', 'status': 503}]
    for path in ('/', '/admin', '/admin/login', '/livewire/update', '/api/v1/auth/csrf', '/media/private.ts',
                 '/.env', '/.runtime/paypal-sandbox/credentials.json', '/webhooks/paypal?x=1', '/webhooks/paypal/'):
        status, _, _ = request(bridge_origin, path, 'POST', {})
        check('bridge blocks private/variant path ' + path.split('?')[0], status, [404])
    for method in ('GET', 'PUT', 'OPTIONS'):
        status, _, _ = request(bridge_origin, '/webhooks/paypal', method)
        check('bridge rejects method ' + method, status, [404])
    status, _, _ = request(base, '/api/v1/billing/checkout', 'POST', {})
    check('application browser checkout still requires CSRF', status, [419])
    status, _, _ = request(base, '/webhooks/paypal/adjacent', 'POST', {})
    check('application nonexistent adjacent route is unavailable', status, [404])
    # This account was registered over real HTTP and verified with the local mail preview.
    account = json.loads((ROOT / '.runtime/http-smoke-account.json').read_text())
    status, token, _ = request(base, '/api/v1/auth/csrf')
    check('viewer csrf available', status, [200])
    status, _, _ = request(base, '/api/v1/auth/login', 'POST', {**account, 'return_to': '/membership'}, {'X-CSRF-TOKEN': token['csrf_token']})
    check('fresh synthetic viewer can log in', status, [200])
    _, token, _ = request(base, '/api/v1/auth/csrf')
    status, offer, _ = request(base, '/api/v1/billing/offer')
    check('test-only offer available', status, [200])
    status, response, _ = request(base, '/api/v1/billing/checkout', 'POST',
        {'offer_id': offer['offer_id'], 'price_version': offer['price_version'], 'return_to': '/membership'},
        {'X-CSRF-TOKEN': token['csrf_token'], 'Idempotency-Key': str(uuid.uuid4())})
    check('valid checkout does not pretend purchase-ready', status, [503])
    status, membership, _ = request(base, '/api/v1/me/membership')
    check('viewer membership query available', status, [200])
    after = snapshot()
    assert before['counts'] == after['counts']
    report = {'application_candidate': 'a82b831ba5d03064d7d1564a93a5b201298571ce',
              'checkout_sha': subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip(),
              'checks': checks, 'before': before, 'after': after,
              'billing_facts_unchanged': True, 'upstream_http_responses': upstream_proof, 'official_signature_verification': 'not_performed',
              'external_https': 'not_opened', 'limitations': 'Real local denial only; provider is off. Not valid-credential signature verification.'}
    (private / 'paypal-joint-http.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({'checks': len(checks), 'passed': all(x['passed'] for x in checks), 'billing_facts_unchanged': True}))
finally:
    trace_response.stop()
    trace_request.stop()
    bridge.shutdown()
    bridge.server_close()
    worker.join()
