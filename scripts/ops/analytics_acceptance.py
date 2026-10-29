#!/usr/bin/env python3
"""06 analytics preparation/combination gate. Own test DB only; never starts web/queues/tunnels."""
import argparse
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
PROTECTED_PORTS = {8377, 5877, 8406, 5906, 8407, 8408, 8409, 8303}
SOURCE_PATHS = ['h5-platform', 'scripts/ops/analytics_acceptance.py', 'scripts/ops/analytics_preflight.php', 'scripts/ops/analytics_browser_runtime.php', 'scripts/ops/analytics_browser_fixture.py', 'scripts/ops/analytics_browser_control.php']


def private_json(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_nlink != 1:
        raise ValueError('PRIVATE_WORKSPACE_FILE_REQUIRED')
    return json.loads(path.read_text())


def validate_workspace(root, config):
    if ('paypal-sandbox' in str(root) or root == Path('/Volumes/KESU/H5')
            or config.get('root') != str(root) or config.get('port') in PROTECTED_PORTS
            or config.get('db_port') in PROTECTED_PORTS or config.get('port') == config.get('db_port')
            or any(type(config.get(k)) is not int or not 1024 <= config[k] <= 65535 for k in ('port', 'db_port'))):
        raise ValueError('ISOLATED_06_WORKSPACE_REQUIRED')
    suffix = hashlib.sha256(str(root).encode()).hexdigest()[:10]
    if any(config.get(k) != v for k, v in {'workspace':'h5_'+suffix, 'db':'h5_'+suffix, 'user':'h5_app_'+suffix}.items()):
        raise ValueError('WORKSPACE_DATABASE_IDENTITY_MISMATCH')
    return config


def testing_environment(root, config):
    env = os.environ.copy()
    for key in ('HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','http_proxy','https_proxy','all_proxy'):
        env.pop(key,None)
    env.update(NO_PROXY='127.0.0.1,localhost,::1', no_proxy='127.0.0.1,localhost,::1')
    env.update(APP_ENV='testing', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT=str(config['db_port']),
               DB_DATABASE=config['db']+'_test', DB_USERNAME=config['user'],
               DB_PASSWORD=(root/'.runtime/pg-app-password').read_text(), H5_TEST_DATABASE=config['db']+'_test',
               H5_WORKSPACE=config['workspace']+'_test', H5_LOCAL_MODULES='acquisition',
               H5_BILLING_DRIVER='unconfigured', H5_BILLING_NEW_PURCHASE_PROVIDER='unconfigured',
               H5_BILLING_PAYPAL_SANDBOX_ENABLED='false', H5_SIMULATION='false',
               ACQUISITION_TRANSPORT='disabled', ACQUISITION_EXTERNAL_ENABLED='false', ACQUISITION_ANALYTICS_ENABLED='true',
               ACQUISITION_CREDENTIALS_FILE='', ACQUISITION_PLATFORM_REVIEWED='false')
    # No staged secrets from shell or an old own local .env reach the provider adapters.
    for key in ('CLIENT_ID','CLIENT_SECRET','MERCHANT_ID','MERCHANT_SCOPE','WEBHOOK_ID','PRODUCT_ID','WEBHOOK_URL'):
        env['H5_BILLING_PAYPAL_SANDBOX_'+key] = '(null)'
    for key in ('H5_STRIPE_SECRET_KEY','H5_STRIPE_WEBHOOK_SECRET','STRIPE_SECRET','STRIPE_KEY','STRIPE_WEBHOOK_SECRET'):
        env[key] = ''
    return env


def mime_version_guard(root):
    locked = json.loads((root/'h5-platform/composer.lock').read_text())
    installed = json.loads((root/'h5-platform/vendor/composer/installed.json').read_text())
    packages = installed.get('packages',[]) if isinstance(installed,dict) else installed
    lock_rows = [p for p in locked.get('packages',[]) if p.get('name')=='symfony/mime']
    installed_rows = [p for p in packages if p.get('name')=='symfony/mime']
    if len(lock_rows)!=1 or len(installed_rows)!=1:
        raise ValueError('MIME_INSTALLATION_NOT_READY')
    expected = lock_rows[0]['version'].lstrip('v')
    actual = installed_rows[0]['version'].lstrip('v')
    if expected!='8.1.10' or actual!=expected:
        raise ValueError('MIME_INSTALLED_LOCK_MISMATCH_INSTALL_OWN_DEPENDENCIES_FIRST')
    return {'package':'symfony/mime','lock_version':expected,'installed_version':actual,'matched':True}


def candidate_guard(root, candidate):
    if not re.fullmatch('[0-9a-f]{40}', candidate):
        raise ValueError('FULL_CANDIDATE_REQUIRED')
    actual = subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
    if actual != candidate:
        raise ValueError('CANDIDATE_MISMATCH')
    dirty = subprocess.check_output(['git','status','--porcelain','--',*SOURCE_PATHS],cwd=root,text=True)
    if dirty:
        raise ValueError('SOURCE_MUST_BE_COMMITTED')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--stage', choices=['prepare','combined','full'], default='prepare')
    args = parser.parse_args()
    candidate_guard(ROOT,args.candidate)
    config = validate_workspace(ROOT,private_json(ROOT/'.runtime/workspace.json'))
    dependency = mime_version_guard(ROOT)
    # Refuse a live own web/worker runtime too: test-only evidence must not race browser fixtures.
    if any((ROOT/'.runtime/pids'/f'{name}.json').exists() for name in ('web','queue','scheduler')):
        raise ValueError('STOP_OWN_APPLICATION_BEFORE_TEST_DATABASE_RUN')
    if (ROOT/'h5-platform/bootstrap/cache/config.php').exists():
        raise ValueError('CACHED_CONFIG_NOT_ALLOWED')
    spec = importlib.util.spec_from_file_location('analytics_own_dev', ROOT/'scripts/dev.py')
    dev = importlib.util.module_from_spec(spec);spec.loader.exec_module(dev)
    dev.database_start()
    env = testing_environment(ROOT,config)
    output = ROOT/'.runtime/06/analytics'/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    output.mkdir(parents=True,mode=0o700);os.umask(0o077)
    preflight = subprocess.run([dev.PHP,str(ROOT/'scripts/ops/analytics_preflight.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if preflight.returncode:
        (output/'preflight-private.txt').write_text(preflight.stdout+preflight.stderr)
        raise ValueError('PREFLIGHT_FAILED_SEE_PRIVATE_RUNTIME')
    ready = json.loads(preflight.stdout)
    (output/'preflight.json').write_text(json.dumps(ready,indent=2)+'\n')
    browser_ready = {'candidate':args.candidate,'combined_ready':ready['combined_ready'],'missing':ready['missing']}
    target = ROOT/'.runtime/06/analytics-browser-readiness.json'
    fd = os.open(target,os.O_WRONLY|os.O_CREAT|os.O_TRUNC|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as stream: json.dump(browser_ready,stream);stream.write('\n')
    target.chmod(0o600)
    report = {'candidate':args.candidate,'contract':'analytics-v1','stage':args.stage,'database':config['db']+'_test',
              'preparation_passed':None,'combined_acceptance':'not_executed','missing':ready['missing'],
              'official_platform_verified':False,'external_delivery_enabled':False,'mime_dependency':dependency,
              'loopback_proxy_bypassed':True}
    if args.stage == 'combined' and not ready['combined_ready']:
        report['combined_acceptance'] = 'blocked_missing_consumers'
        (output/'result.json').write_text(json.dumps(report,indent=2)+'\n')
        print(json.dumps(report));return 77
    suites = ['tests/Feature/Contracts/AnalyticsContractsTest.php', 'tests/Integration/Analytics', 'tests/Security/Analytics']
    if args.stage == 'combined':
        suites.append('tests/Integration/AnalyticsCombined')
    command=[dev.PHP,str(dev.APP/'artisan'),'test',*suites]
    if args.stage == 'full':
        # Default-off regression and explicit per-test consumers must coexist; do not globally turn analysis on.
        env.update(H5_LOCAL_MODULES='', ACQUISITION_ANALYTICS_ENABLED='false')
        command=[dev.PHP,'-d','memory_limit=512M',str(dev.APP/'vendor/bin/phpunit'),'--colors=never',
                 'tests/Feature','tests/Integration','tests/Security']
        report['test_defaults']={'modules':'off','analytics':'off','memory_limit':'512M'}
    with (output/'tests-private.txt').open('w') as stream:
        run=subprocess.run(command,cwd=dev.APP,env=env,stdout=stream,stderr=subprocess.STDOUT)
    report['preparation_passed']=run.returncode==0
    if args.stage == 'combined':
        report['integration_passed']=run.returncode==0
        report['combined_acceptance']='integration_only_browser_and_matrix_pending' if run.returncode==0 else 'integration_failed'
        report['browser_executed']=False
    report['tests_exit_code']=run.returncode
    if args.stage == 'full':
        report['full_php_passed']=run.returncode==0
        report['summary']=[line for line in (output/'tests-private.txt').read_text().splitlines()
                           if re.search(r'OK |Tests:|Assertions:|Time:|ERRORS|FAILURES',line)]
    (output/'result.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({**report,'evidence_directory':str(output)}));return run.returncode


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, subprocess.SubprocessError):
        raise SystemExit('06 analytics refused: inspect own private preparation report; no protected runtime was selected.') from None
