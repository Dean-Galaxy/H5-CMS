#!/usr/bin/env python3
"""Explicit local HTTP acceptance. Creates one local-preview viewer; never simulates payment."""
import html
import http.cookiejar
import json
import re
import secrets
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
RUN = ROOT / '.runtime'
config = json.loads((RUN / 'workspace.json').read_text())
assert config['root'] == str(ROOT)
environment = (ROOT / 'h5-platform/.env').read_text()
assert re.search(r'^APP_ENV="?local"?$', environment, re.M)
assert re.search(r'^MAIL_MAILER="?preview"?$', environment, re.M)
base = f'http://127.0.0.1:{config["port"]}'


def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


browser = client()


def request(path, method='GET', data=None, token=None, expected=200, web=False, using=None):
    headers = {'Accept': 'text/html' if web else 'application/json', 'Accept-Language': 'en'}
    if data is not None:
        data = json.dumps(data).encode()
        headers['Content-Type'] = 'application/json'
    if token:
        headers['X-CSRF-TOKEN'] = token
    try:
        response = (using or browser).open(urllib.request.Request(base + path, data=data, headers=headers, method=method), timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    raw = response.read().decode()
    assert response.code == expected, (path.split('?')[0], response.code)
    return raw if web else json.loads(raw)


checks = []
drama = request('/api/v1/catalog')['items'][0]
fifth, first = drama['episodes'][4], drama['episodes'][0]
path = f'/dramas/{drama["id"]}/episodes/{fifth["id"]}?position=17'
token = request('/api/v1/auth/csrf')['csrf_token']
email = 'audience-' + secrets.token_hex(6) + '@example.test'
password = secrets.token_urlsafe(24) + '9A'
before = set((RUN / 'mail').glob('*.html'))
identity = request('/api/v1/auth/register', 'POST', {'name': 'Audience local HTTP check', 'email': email,
    'password': password, 'password_confirmation': password, 'return_to': path}, token)
assert identity['redirect_to'] == path and not identity['identity']['viewer']['email_verified']
checks.append('real registration retains only the requested current playback path')
token = request('/api/v1/auth/csrf')['csrf_token']
progress_path = '/api/v1/me/progress/' + drama['id']
assert request(progress_path) is None
grant_path = f'/audience/dramas/{drama["id"]}/episodes/{fifth["id"]}/playback'
grant = request(grant_path, 'POST', {'play_session_id': None}, token)
assert grant['allowed'] and request(progress_path) is None
checks.append('no progress is created by merely obtaining a playback grant')
saved = request(progress_path, 'PUT', {'episode_id': fifth['id'], 'position_seconds': 17,
    'play_session_id': grant['play_session_id'], 'sequence': 1}, token)
assert saved['position_seconds'] == 17
second = client()
other_token = request('/api/v1/auth/csrf', using=second)['csrf_token']
request('/api/v1/auth/login', 'POST', {'email': email, 'password': password}, other_token, using=second)
assert request(progress_path, using=second)['position_seconds'] == 17
checks.append('same account sees its saved point from a separate HTTP cookie jar')
new_grant = request(f'/audience/dramas/{drama["id"]}/episodes/{first["id"]}/playback', 'POST', {'play_session_id': None}, token)
request(progress_path, 'PUT', {'episode_id': first['id'], 'position_seconds': 4, 'play_session_id': new_grant['play_session_id'], 'sequence': 1}, token)
late = request(progress_path, 'PUT', {'episode_id': fifth['id'], 'position_seconds': 28, 'play_session_id': grant['play_session_id'], 'sequence': 2}, token)
assert late['episode_id'] == first['id'] and late['position_seconds'] == 4
checks.append('active rewatch of an earlier episode wins over a delayed older session')
request(progress_path, 'PUT', {'episode_id': first['id'], 'position_seconds': first['duration_seconds'] + 1,
    'play_session_id': new_grant['play_session_id'], 'sequence': 2}, token, expected=422)
checks.append('out-of-duration write is rejected')
page = request('/membership?purchase=1&return_to=' + urllib.parse.quote(path, safe=''), web=True)
assert 'Verify email' in page and '/membership/checkout' not in page
checks.append('real website blocks first checkout before email verification')
preview = sorted(set((RUN / 'mail').glob('*.html')) - before)[-1]
link = html.unescape(re.search(r'href="([^"]*/api/v1/auth/email/verify/[^"]+)"', preview.read_text())[1])
assert link.startswith(base + '/')
verified = request(link[len(base):])
assert verified['redirect_to'] == '/membership?purchase=1'
page = request('/membership?purchase=1', web=True)
offer = re.search(r'name="offer_id" value="([^"]+)"', page)[1]
version = int(re.search(r'name="price_version" value="([^"]+)"', page)[1])
error = request('/membership/checkout', 'POST', {'offer_id': offer, 'price_version': version, 'confirmed': '1'}, token, expected=503, web=True)
assert 'Payment is not available' in error
assert not request('/api/v1/me/membership')['access_active']
checks.append('verified viewer sees explicit unconfigured-payment failure and gains no access')
assert request('/api/v1/auth/me')['staff'] is None
checks.append('viewer remains separate from staff')
credentials = RUN / 'audience/browser-viewer.json'
credentials.write_text(json.dumps({'email': email, 'password': password}))
credentials.chmod(0o600)
report = {'result': 'passed', 'checks': checks, 'mail': 'local preview only', 'payments': 'unconfigured, never simulated',
    'database': config['db'], 'progress': 'synthetic HTTP position inputs; actual video playback verified separately'}
(RUN / 'audience/full-http-smoke.json').write_text(json.dumps(report, indent=2))
print(json.dumps(report, indent=2))
