#!/usr/bin/env python3
"""Exercise real local HTTP + CSRF + session + mail preview; no external sends."""
import http.cookiejar
import html
import json
from pathlib import Path
import re
import secrets
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[5]
RUN = ROOT / '.runtime'
cfg = json.loads((RUN / 'workspace.json').read_text())
assert cfg['root'] == str(ROOT), 'Refuse copied runtime configuration'
env = (ROOT / 'h5-platform/.env').read_text()
assert re.search(r'^MAIL_MAILER="?preview"?$', env, re.M), 'Smoke requires local preview mail'
assert re.search(r'^APP_ENV="?local"?$', env, re.M), 'Smoke requires APP_ENV=local'
base = f'http://127.0.0.1:{cfg["port"]}'
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar))
passed = []


def request(path, method='GET', data=None, csrf=None, expect=200):
    headers = {'Accept': 'application/json'}
    if path == '/api/v1/billing/checkout': headers['Idempotency-Key']='http-check-'+secrets.token_hex(12)
    if data is not None:
        data = json.dumps(data).encode()
        headers['Content-Type'] = 'application/json'
    if csrf:
        headers['X-CSRF-TOKEN'] = csrf
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        response = client.open(req, timeout=45)
    except urllib.error.HTTPError as e:
        response = e
    body = json.loads(response.read())
    assert response.code == expect, (path.split('?')[0], response.code, body.get('error', {}).get('code'))
    assert response.headers.get('X-Request-ID')
    # Real response must consume the shared schema, not just a test-only shape.
    from jsonschema import Draft202012Validator, FormatChecker
    spec = json.loads((ROOT / 'docs/contracts/openapi.yaml').read_text())
    route = path.split('?')[0]
    if route.startswith('/api/v1/billing/orders/'):
        route = '/api/v1/billing/orders/{id}'
    if '/email/verify/' in route:
        route = '/api/v1/auth/email/verify/{id}/{hash}'
    schema = spec['paths'][route][method.lower()]['responses'][str(expect)]['content']['application/json']['schema']
    Draft202012Validator({**schema, 'components': spec['components']}, format_checker=FormatChecker()).validate(body)
    return body


assert request('/health')['modules']['billing']=='ready'
passed.append('health + OpenAPI response')
token = request('/api/v1/auth/csrf')['csrf_token']
request('/api/v1/auth/login', 'POST', {'email': 'nobody@example.test', 'password': 'dummy'}, expect=419)
passed.append('real CSRF mismatch blocked')
email = 'smoke-' + secrets.token_hex(6) + '@example.test'
password = secrets.token_urlsafe(24) + '9A'
files = set((RUN / 'mail').glob('*.html'))
result = request('/api/v1/auth/register', 'POST', {'name': '本地验收观众', 'email': email, 'password': password,
                 'password_confirmation': password, 'return_to': '/identity/account'}, token)
assert not result['identity']['viewer']['email_verified']
assert request('/api/v1/auth/me')['staff'] is None
request('/api/v1/admin/staff', expect=401)
passed.append('registration + independent viewer/staff cookies')
token = request('/api/v1/auth/csrf')['csrf_token']
request('/api/v1/billing/checkout', 'POST', {}, token, expect=403)
preview = sorted(set((RUN / 'mail').glob('*.html')) - files)[-1]
link = html.unescape(re.search(r'href="([^"]*/api/v1/auth/email/verify/[^"]+)"', preview.read_text())[1])
assert link.startswith(base + '/')
path = link[len(base):]
assert request(path)['identity']['viewer']['email_verified']
request(path, expect=409)
request('/api/v1/billing/checkout', 'POST', {'offer_id':'019a2b3c-1234-7000-8000-000000000099','price_version':1,'return_to':'/membership'}, token, expect=503)
member=request('/api/v1/me/membership');assert member['access_active'] is False and member['subscription_state']=='none'
request('/api/v1/billing/subscription/cancel','POST',{},expect=419)
request('/api/v1/billing/subscription/cancel','POST',{},token)
request('/api/v1/billing/subscription/resume-quote',expect=409)
request('/api/v1/billing/orders/019a2b3c-1234-7000-8000-000000000099',expect=404)
request('/webhooks/stripe','POST',{},expect=503)
passed.append('Billing runtime binding + membership + cancellation CSRF + stale resume + order scope + unconfigured Stripe')
passed.append('unverified gate + signed email preview + replay refusal + honest unconfigured Stripe')
request('/api/v1/auth/logout', 'POST', {}, token)
assert request('/api/v1/auth/me')['viewer'] is None
token = request('/api/v1/auth/csrf')['csrf_token']
result = request('/api/v1/auth/login', 'POST', {'email': email, 'password': password, 'return_to': '//example.test'}, token)
assert result['redirect_to'] == '/identity/account'
passed.append('logout + login + controlled return')
(RUN / 'billing-http-account.json').write_text(json.dumps({'email': email, 'password': password}, indent=2))
(RUN / 'billing-http-account.json').chmod(0o600)
report = {'result': 'passed', 'checks': passed, 'mail': 'local preview only', 'database': cfg['db']}
(RUN / 'billing-http-result.json').write_text(json.dumps(report, ensure_ascii=False, indent=2))
print(json.dumps(report, ensure_ascii=False, indent=2))
