from pathlib import Path
import http.cookiejar
import urllib.request
import urllib.error
import json
import hashlib

root = Path.cwd()
cfg = json.loads((root / '.runtime/workspace.json').read_text())
assert cfg['root'] == str(root)
base = f'http://127.0.0.1:{cfg["port"]}'
client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = []


def check(path, status, method='GET', body=None, csrf=None):
    headers = {'Accept': 'application/json'}
    if body is not None:
        headers['Content-Type'] = 'application/json'
    if csrf:
        headers['X-CSRF-TOKEN'] = csrf
    req = urllib.request.Request(base + path, data=None if body is None else json.dumps(body).encode(), method=method, headers=headers)
    try:
        response = client.open(req, timeout=45)
    except urllib.error.HTTPError as error:
        response = error
    value = json.loads(response.read())
    assert response.code == status, (path, response.code, value)
    assert response.headers.get('X-Request-ID')
    checks.append({'path': path, 'method': method, 'status': response.code, 'code': value.get('error', {}).get('code') or value.get('reason')})
    return value


health = check('/health', 200)
assert health['modules']['content'] == 'ready' and health['modules']['billing'] == 'not_ready'
for path in ['/api/v1/home', '/api/v1/categories', '/api/v1/catalog?region=US']:
    assert check(path, 403)['error']['code'] == 'REGION_BLOCKED'
check('/api/v1/admin/content', 401)
check('/api/v1/billing/offer', 503)
path = '/api/v1/episodes/019a2b3c-1234-7000-8000-000000000001/playback-grants'
check(path, 419, 'POST', {'play_session_id': None})
token = check('/api/v1/auth/csrf', 200)['csrf_token']
grant = check(path, 200, 'POST', {'play_session_id': None}, token)
assert grant['allowed'] is False and grant['reason'] == 'REGION_BLOCKED' and grant['short_lived_url'] is None
asset = client.open(base + '/content/admin.js', timeout=45).read()
assert asset == (root / 'h5-platform/public/content/admin.js').read_bytes()
report = {'result': 'passed', 'runtime': '00 independent reception; no Content opt-in, unresolved region preserved',
          'modules': health['modules'], 'checks': checks, 'admin_js_sha256': hashlib.sha256(asset).hexdigest(),
          'media': 'No user media copied; local 02 media evidence remains separately attributed.'}
(root / '.runtime/review-content-http.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
print(json.dumps(report, ensure_ascii=False))
