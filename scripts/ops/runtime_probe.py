#!/usr/bin/env python3
"""Actual isolated PHP boots; each negative changes one field from a validated synthetic baseline."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('h5_dev', ROOT / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
c = dev.config()
os.umask(0o077)
probe_root = dev.RUN / '06/runtime-probes'
probe_root.mkdir(parents=True, exist_ok=True)
helper = ROOT / 'h5-platform/tests/Security/Support/runtime-probe.php'
results = []
# Probe boots/readiness must remain offline even if a guard regresses.
php_args = [dev.PHP, '-d', 'disable_functions=curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen']


def run(env, action='boot', stdin=None, expected=None, executable=None, expected_driver='unconfigured'):
    args = [*php_args, helper, action] if executable is None else [*php_args, *executable]
    p = subprocess.run(args, env=env, cwd=dev.APP, input=stdin, text=True, capture_output=True, timeout=25)
    try:
        observed = json.loads(p.stdout)
    except ValueError:
        raise RuntimeError('Probe returned unexpected output; no raw output printed') from None
    assert 'SYNTHETIC_SECRET' not in p.stdout
    assert p.returncode == (0 if expected is None else 42), (action, observed)
    assert observed['ok'] is (expected is None), observed
    if expected:
        assert observed['reason'] == expected, (expected, observed)
    elif action != 'cache':
        assert observed['config_environment'] == observed['container_environment'] == observed['process_environment']
        assert observed['region'] == {'country_code': 'ZZ', 'source': 'unresolved'}
        assert observed['billing_driver'] == expected_driver and not observed['acquisition_ready']
        if executable is None:
            assert observed['network_functions_disabled'] is True
    return observed


with tempfile.TemporaryDirectory(prefix='run-', dir=probe_root) as tmp:
    work = Path(tmp)
    base = {**os.environ, 'APP_ENV': 'staging', 'APP_DEBUG': 'false',
            'APP_URL': 'https://staging.example.test', 'APP_KEY': 'base64:' + 'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg=',
            'APP_CONFIG_CACHE': str(work / 'uncached.php'), 'H5_06_RUNTIME_PROBE': '1',
            'H5_CONTROLLED_STAGING': 'false', 'H5_LOCAL_MODULES': '', 'H5_SIMULATION': 'false', 'H5_LOCAL_US_TEST_REGION': 'false',
            'SESSION_SECURE_COOKIE': 'true', 'SESSION_HTTP_ONLY': 'true', 'SESSION_SAME_SITE': 'lax',
            'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1', 'DB_PORT': str(c['db_port']), 'DB_URL': 'null',
            'DB_DATABASE': c['db'] + '_test', 'H5_TEST_DATABASE': c['db'] + '_test', 'DB_USERNAME': c['user'],
            'DB_PASSWORD': 'SYNTHETIC_DATABASE_NOT_CONNECTED',
            'H5_BILLING_DRIVER': 'unconfigured', 'H5_BILLING_STRIPE_TEST_SECRET': '', 'H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET': '',
            'MAIL_MAILER': 'smtp', 'MAIL_SCHEME': 'smtp', 'MAIL_URL': 'null', 'MAIL_HOST': 'smtp.example.test',
            'MAIL_PORT': '587', 'MAIL_TIMEOUT': '10', 'MAIL_USERNAME': 'SYNTHETIC_USER', 'MAIL_PASSWORD': 'SYNTHETIC_SECRET_NOT_SENT',
            'MAIL_REQUIRE_TLS': 'true', 'MAIL_EHLO_DOMAIN': 'staging.example.test', 'MAIL_FROM_ADDRESS': 'noreply@example.test'}
    safe = run(base)
    results.append({'name': 'uncached safe staging with default opt-out', 'observed': safe, 'passed': True})
    for environment in ('local', 'testing', 'production'):
        observed = run({**base, 'APP_ENV': environment})
        results.append({'name': 'safe exact environment ' + environment, 'observed': observed, 'passed': True})
    results.append({'name': 'controlled opt-in cannot enable production',
                    'observed': run({**base, 'APP_ENV': 'production', 'H5_CONTROLLED_STAGING': 'true'},
                                    expected='STAGING_ENVIRONMENT_REQUIRED'), 'passed': True})
    on = {**base, 'H5_CONTROLLED_STAGING': 'true'}
    origin = run(on, 'origin')
    assert origin['origin'] == 'https://staging.example.test'
    results.append({'name': 'controlled origin opt-in, still ZZ and unconfigured payment', 'observed': origin, 'passed': True})
    stripe_without_credentials = {**on, 'H5_BILLING_DRIVER': 'stripe_test'}
    unpaid = run(stripe_without_credentials, 'billing', expected_driver='stripe_test')
    assert unpaid['origin'] == 'https://staging.example.test'
    assert unpaid['billing_availability'] == {'ready': False, 'reason': 'DEPENDENCY_NOT_READY', 'status': 503}
    results.append({'name': 'uncached controlled Stripe test boots but empty credentials refuse payment', 'observed': unpaid, 'passed': True})
    cases = [
        ('APP_DEBUG', 'true', 'RUNTIME_DEBUG_FORBIDDEN'), ('APP_DEBUG', 'junk', 'RUNTIME_BOOLEAN_INVALID'),
        ('H5_CONTROLLED_STAGING', 'junk', 'RUNTIME_BOOLEAN_INVALID'), ('H5_SIMULATION', 'true', 'RUNTIME_LOCAL_OVERRIDE_FORBIDDEN'),
        ('H5_LOCAL_US_TEST_REGION', 'true', 'RUNTIME_LOCAL_OVERRIDE_FORBIDDEN'),
        ('APP_ENV', 'stage', 'RUNTIME_ENVIRONMENT_INVALID'), ('APP_URL', 'http://staging.example.test', 'RUNTIME_ORIGIN_INVALID'),
        ('SESSION_SECURE_COOKIE', 'false', 'RUNTIME_COOKIE_UNSAFE'), ('SESSION_HTTP_ONLY', 'false', 'RUNTIME_COOKIE_UNSAFE'),
        ('SESSION_SAME_SITE', 'none', 'RUNTIME_COOKIE_UNSAFE'), ('MAIL_REQUIRE_TLS', 'false', 'RUNTIME_MAIL_TLS_REQUIRED'),
        ('MAIL_REQUIRE_TLS', 'junk', 'RUNTIME_MAIL_TLS_REQUIRED'), ('MAIL_TIMEOUT', '121', 'RUNTIME_MAIL_ENDPOINT_INVALID'),
        ('MAIL_HOST', '127.0.0.1', 'RUNTIME_MAIL_ENDPOINT_INVALID'), ('MAIL_PASSWORD', '', 'RUNTIME_MAIL_CREDENTIALS_REQUIRED'),
        ('MAIL_MAILER', 'preview', 'RUNTIME_MAIL_TRANSPORT_FORBIDDEN'), ('MAIL_MAILER', 'failover', 'RUNTIME_MAIL_PATH_INVALID'),
        ('MAIL_FROM_ADDRESS', 'invalid', 'RUNTIME_MAIL_FROM_INVALID'),
        ('H5_LOCAL_MODULES', 'acquisition', 'RUNTIME_LOCAL_OVERRIDE_FORBIDDEN'),
        ('H5_BILLING_DRIVER', 'stripe_test', 'BILLING_STAGING_NOT_ENABLED'),
    ]
    for field, value, reason in cases:
        observed = run({**base, field: value}, expected=reason)
        results.append({'name': 'uncached ' + field, 'changed_field': field, 'expected_reason': reason, 'observed': observed, 'passed': True})
    results.append({'name': 'default opt-out origin denied', 'observed': run(base, 'origin', expected='STAGING_NOT_ENABLED'), 'passed': True})

    def cache(changes):
        path = work / 'candidate.php'
        if path.exists():
            path.unlink()  # Only our private per-run probe cache, never bootstrap/cache/config.php.
        run(base, 'cache', json.dumps(changes))
        return {**base, 'APP_CONFIG_CACHE': str(path)}

    cached = cache({})
    cached_safe = run(cached)
    assert cached_safe['cached'] is True
    results.append({'name': 'cached safe staging', 'observed': cached_safe, 'passed': True})
    for field, value, reason in [
        ('app.debug', True, 'RUNTIME_DEBUG_FORBIDDEN'), ('app.debug', 'false', 'RUNTIME_BOOLEAN_INVALID'),
        ('app.env', 'production', 'RUNTIME_ENVIRONMENT_INVALID'), ('session.secure', False, 'RUNTIME_COOKIE_UNSAFE'),
        ('trustedproxy.proxies', ['127.0.0.1'], 'RUNTIME_PROXY_FORBIDDEN'),
        ('mail.mailers.smtp.verify_peer', False, 'RUNTIME_MAIL_TLS_REQUIRED'),
        ('h5.modules.acquisition.ready', True, 'RUNTIME_ACQUISITION_NOT_RELEASED'),
        ('billing.driver', 'stripe_test', 'BILLING_STAGING_NOT_ENABLED'),
    ]:
        observed = run(cache({field: value}), expected=reason)
        results.append({'name': 'cached ' + field, 'changed_field': field, 'expected_reason': reason, 'observed': observed, 'passed': True})
    cached_unpaid = run(cache({'billing.driver': 'stripe_test', 'h5.controlled_staging': True}), 'billing', expected_driver='stripe_test')
    assert cached_unpaid['cached'] is True
    assert cached_unpaid['origin'] == 'https://staging.example.test'
    assert cached_unpaid['billing_availability'] == {'ready': False, 'reason': 'DEPENDENCY_NOT_READY', 'status': 503}
    results.append({'name': 'cached controlled Stripe test boots but empty credentials refuse payment', 'observed': cached_unpaid, 'passed': True})
    cached = cache({})
    for value in (None, 'local'):
        changed = dict(cached)
        if value is None:
            changed.pop('APP_ENV', None)
        else:
            changed['APP_ENV'] = value
        results.append({'name': 'cached process APP_ENV ' + ('missing' if value is None else value),
                        'observed': run(changed, expected='RUNTIME_ENVIRONMENT_INVALID'), 'passed': True})
    # Existing process intentionally retains loaded settings; replacing its cache does not refresh it.
    process = subprocess.Popen([*php_args, helper, 'hold'], cwd=dev.APP, env=cached, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
    try:
        first = json.loads(process.stdout.readline())
        assert first['ok'] and first['cached']
        cache({'app.debug': True})
        process.stdin.write('inspect\n'); process.stdin.flush()
        retained = json.loads(process.stdout.readline())
        assert retained == first
        process.terminate(); process.wait(timeout=5)
        assert process.poll() is not None
        refused = run(cached, expected='RUNTIME_DEBUG_FORBIDDEN')
        results.append({'name': 'old CLI probe retains config until exit; new process refuses changed cache',
                        'old_probe_exited': True, 'new_process': refused, 'passed': True,
                        'scope': 'real long-lived CLI probe, not FPM or queue worker'})
    finally:
        if process.poll() is None:
            process.kill(); process.wait(timeout=5)
    cached = cache({})
    preflight = [ROOT / 'scripts/ops/check_runtime.php', '--expect-env=staging', '--require-cache']
    results.append({'name': 'actual release preflight with cached staging', 'observed': run(cached, executable=preflight), 'passed': True})
    results.append({'name': 'release preflight requires cache', 'observed': run(base, expected='PREFLIGHT_CACHE_REQUIRED', executable=preflight), 'passed': True})
    results.append({'name': 'release preflight rejects unsafe changed cache',
                    'observed': run(cache({'app.debug': True}), expected='RUNTIME_DEBUG_FORBIDDEN', executable=preflight), 'passed': True})

# Inspect only this worktree's actual local DB identity; probe SMTP credentials are never used here.
query = 'SELECT current_database(),inet_server_port(),current_user,rolsuper,rolcreatedb FROM pg_roles WHERE rolname=current_user'
r = subprocess.run([dev.PG/'psql', '-X', '-At', '-c', query], env=dev.pg_env(), check=True, text=True, capture_output=True)
fields = dict(line.split('=', 1) for line in (dev.APP/'.env').read_text().splitlines() if '=' in line and not line.startswith('#'))
fields = {k: v.strip('"') for k, v in fields.items()}
expected = 'h5_' + hashlib.sha256(str(ROOT).encode()).hexdigest()[:10]
assert c['workspace'] == c['db'] == fields['DB_DATABASE'] == expected
assert fields['CACHE_PREFIX'] == fields['DB_QUEUE'] == fields['H5_WORKSPACE'] == expected
assert fields['H5_MEDIA_ROOT'] == str(dev.RUN/'media') and (dev.APP/'.env').stat().st_mode & 0o077 == 0
assert r.stdout.strip().split('|') == [c['db'], str(c['db_port']), c['user'], 'f', 'f']
report = {'checkout_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
          'contract': safe['contract'], 'checks': results, 'workspace_binding': True, 'database_identity': r.stdout.strip(),
          'scope': 'independent CLI boot/config cache and local DB identity only; synthetic SMTP and empty Stripe credentials; native network functions disabled in probes; no external connection or delivery; no real FPM/systemd execution'}
(dev.RUN/'06/runtime-probe.json').write_text(json.dumps(report, ensure_ascii=False, indent=2)+'\n')
print(json.dumps(report, ensure_ascii=False, indent=2))
