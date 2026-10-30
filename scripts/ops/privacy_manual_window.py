#!/usr/bin/env python3
"""AN-M01: new loopback workspace, real tracking/free media, no gateway/registration/payment/queue."""
import argparse
from datetime import datetime, timezone
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import time
import urllib.request
from analytics_acceptance import candidate_guard, mime_version_guard, private_json, validate_workspace
from analytics_browser_fixture import environment as browser_environment

ROOT = Path(__file__).resolve().parents[2]
BASE = '4add9e40e4820bbc0dfa38c3ea5796b527943c38'
DIRECTORY = ROOT/'.runtime/06/analytics-browser'
OUTPUT = ROOT/'.runtime/06/privacy-manual'
PHP = '/opt/homebrew/opt/php@8.4/bin/php'
ROUTER = ROOT/'h5-platform/tests/Browser/Support/privacy-only-router.php'
BUSINESS = ['app','bootstrap','config','database','public','resources','routes','composer.json','composer.lock']


def binding(root, config):
    validate_workspace(root, config)
    if root.parent.name != 'h5-06-privacy-manual' or any(config[k] in {8206,5606} for k in ('port','db_port')):
        raise ValueError('NEW_AN_M01_WORKSPACE_REQUIRED')
    return config


def environment(config, candidate):
    env = browser_environment(config, candidate)
    env['H5_ANALYTICS_PRIVACY_ONLY'] = '1'
    return env


def command(config):
    return [PHP,'-d','expose_php=0','-S',f'127.0.0.1:{config["port"]}','-t',str(ROOT/'h5-platform/public'),str(ROUTER)]


def matching(record, config):
    if record.get('root') != str(ROOT) or record.get('command') != command(config):
        return False
    pid = record.get('pid')
    if type(pid) is not int or pid <= 1:
        return False
    process = subprocess.run(['ps','-p',str(pid),'-o','command='],capture_output=True,text=True)
    start = subprocess.run(['ps','-p',str(pid),'-o','lstart='],capture_output=True,text=True)
    return process.returncode == 0 and process.stdout.strip() == ' '.join(command(config)) and start.stdout.strip() == record.get('started_at')


def cleanup_failed_start(process, pidfile):
    """Only the just-created Popen child is terminated; foreign PID records are retained."""
    if process.poll() is None:
        os.killpg(process.pid, signal.SIGTERM)
        process.wait(timeout=5)
    if pidfile.exists():
        stored = private_json(pidfile)
        if stored.get('pid') == process.pid and stored.get('marker') == str(ROUTER):
            pidfile.unlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('action', choices=['seed','start','state','checkpoint','backup','stop'])
    parser.add_argument('--label', default='snapshot')
    args = parser.parse_args()
    config = binding(ROOT, private_json(ROOT/'.runtime/workspace.json'))
    spec = importlib.util.spec_from_file_location('own_dev', ROOT/'scripts/dev.py')
    dev = importlib.util.module_from_spec(spec); spec.loader.exec_module(dev)
    os.umask(0o077)
    OUTPUT.mkdir(mode=0o700, parents=True, exist_ok=True)
    (ROOT/'.runtime/06').chmod(0o700)
    if args.action == 'stop':
        record = private_json(OUTPUT/'process.json')
        if matching(record, config):
            os.killpg(record['pid'], signal.SIGTERM)
            for _ in range(50):
                if not matching(record, config): break
                time.sleep(.1)
        if matching(record, config): raise ValueError('OWN_WEB_STILL_RUNNING')
        pidfile = ROOT/'.runtime/pids/web.json'
        if pidfile.exists():
            stored = private_json(pidfile)
            if stored.get('pid') != record['pid'] or stored.get('marker') != str(ROUTER): raise ValueError('FOREIGN_PID_RECORD')
            pidfile.unlink()
        if any(dev.process_alive(n) for n in ['web','queue','scheduler']): raise ValueError('OTHER_OWN_PROCESS_RUNNING')
        if (ROOT/'.runtime/postgres/postmaster.pid').exists():
            subprocess.run([str(dev.PG/'pg_ctl'),'-D',str(ROOT/'.runtime/postgres'),'-m','fast','-w','stop'],check=True,stdout=subprocess.DEVNULL)
        print(json.dumps({'stopped':True,'data_preserved':True})); return
    candidate_guard(ROOT,args.candidate)
    dirty = subprocess.check_output(['git','status','--porcelain','--','scripts/ops/privacy_manual_window.py','scripts/ops/privacy_manual_control.php'],cwd=ROOT,text=True)
    if dirty: raise ValueError('COMMIT_WINDOW_TOOLS_FIRST')
    subprocess.run(['git','diff','--exit-code',BASE,args.candidate,'--',*[f'h5-platform/{p}' for p in BUSINESS]],cwd=ROOT,check=True,stdout=subprocess.DEVNULL)
    mime_version_guard(ROOT)
    env = environment(config,args.candidate)
    def php(script, action):
        result = subprocess.run([PHP,str(ROOT/script),action],cwd=ROOT,env=env,capture_output=True,text=True)
        try: document = json.loads(result.stdout)
        except ValueError: document = None
        if result.returncode or not isinstance(document,dict):
            dev.write_private(OUTPUT/'control-error-private.txt',result.stdout+result.stderr)
            raise ValueError('PRIVATE_CONTROL_ERROR')
        return document
    if args.action == 'seed':
        if any((ROOT/'.runtime/pids'/f'{n}.json').exists() for n in ['web','queue','scheduler']): raise ValueError('OWN_SERVICES_MUST_BE_STOPPED')
        if (DIRECTORY/'fixture.json').exists(): raise ValueError('FRESH_PRIVACY_WORKSPACE_REQUIRED_NO_RESEED')
        dev.database_start()
        subprocess.run([PHP,'artisan','migrate','--force'],cwd=ROOT/'h5-platform',env=env,check=True,stdout=subprocess.DEVNULL)
        media = ROOT/'.runtime/06/analytics-media'; media.mkdir(parents=True,mode=0o700,exist_ok=True)
        subprocess.run(['/opt/homebrew/bin/ffmpeg','-nostdin','-v','error','-n','-f','lavfi','-i','testsrc2=s=180x320:r=25',
                        '-t','8','-c:v','libx264','-pix_fmt','yuv420p','-movflags','+faststart',str(media/'synthetic.mp4')],check=True)
        php('scripts/ops/analytics_browser_control.php','seed')
        php('scripts/ops/privacy_manual_control.php','seed_cases')
        baseline = php('scripts/ops/privacy_manual_control.php','state')
        if any(baseline['counts'][t] for t in ['identity_viewers','billing_orders','billing_payments','billing_entitlements']): raise ValueError('NO_USER_OR_PAYMENT_FACTS_EXPECTED')
        dev.write_private(OUTPUT/'seed-baseline.json',json.dumps(baseline,indent=2))
        print(json.dumps({'seeded':True,'migrations':baseline['migrations'],'synthetic_only':True,'viewers':0,'orders':0})); return
    private_json(DIRECTORY/'privacy-cases.json')
    if args.action == 'start':
        if any((ROOT/'.runtime/pids'/f'{n}.json').exists() for n in ['web','queue','scheduler']): raise ValueError('OWN_SERVICE_ALREADY_RECORDED')
        with socket.socket() as sock: sock.bind(('127.0.0.1',config['port']))
        dev.database_start()
        process = subprocess.Popen(command(config),cwd=ROOT/'h5-platform/public',env=env,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        pidfile = ROOT/'.runtime/pids/web.json'
        success = False
        try:
            time.sleep(.5)
            if process.poll() is not None: raise ValueError('PRIVACY_SERVER_FAILED')
            record = {'root':str(ROOT),'candidate':args.candidate,'application_base':BASE,'pid':process.pid,'command':command(config),
                      'started_at':subprocess.check_output(['ps','-p',str(process.pid),'-o','lstart='],text=True).strip()}
            dev.write_private(OUTPUT/'process.json',json.dumps(record,indent=2))
            dev.write_private(pidfile,json.dumps({'pid':process.pid,'marker':str(ROUTER)}))
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            with opener.open(f'http://127.0.0.1:{config["port"]}/health',timeout=20) as response:
                if response.status != 200 or response.headers.get('X-AN-M01-Gateway') != 'UnconfiguredGateway': raise ValueError('HEALTH_FAILED')
            state = php('scripts/ops/privacy_manual_control.php','state')
            dev.write_private(OUTPUT/'live-receipt.json',json.dumps({**record,'state':state,'queue_started':False,'scheduler_started':False},indent=2))
            success = True
            print(json.dumps({'started':True,'candidate':args.candidate,'base':BASE,'port':config['port'],'db_port':config['db_port'],
                              'purchase_provider':state['new_purchase_provider'],'gateway_class':state['gateway_class']})); return
        finally:
            if not success:
                cleanup_failed_start(process,pidfile)
                (OUTPUT/'live-receipt.json').unlink(missing_ok=True)
                dev.write_private(OUTPUT/'startup-failed.json',json.dumps({'candidate':args.candidate,'own_web_cleaned':True}))
    if args.action in ['state','checkpoint']:
        state = php('scripts/ops/privacy_manual_control.php','state')
        if args.action == 'checkpoint':
            if not re.fullmatch('[a-z0-9-]{1,48}',args.label): raise ValueError('SAFE_SNAPSHOT_LABEL_REQUIRED')
            dev.write_private(OUTPUT/(args.label+'.json'),json.dumps(state,indent=2))
        print(json.dumps(state)); return
    if args.action == 'backup':
        directory = OUTPUT/'backups'/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
        directory.mkdir(mode=0o700,parents=True)
        subprocess.run([str(dev.PG/'pg_dump'),'-Fc','--no-owner','--no-acl','-f',str(directory/'database.dump')],env=dev.pg_env(testing=True),check=True,capture_output=True)
        source = DIRECTORY/'media'
        if any(p.is_symlink() for p in source.rglob('*')): raise ValueError('NO_MEDIA_SYMLINKS')
        shutil.copytree(source,directory/'media')
        for source,name in [(ROOT/'h5-platform/.env','own-environment-private'),(DIRECTORY/'owner.json','owner-private.json'),
                            (DIRECTORY/'fixture.json','fixture-private.json'),(DIRECTORY/'privacy-cases.json','cases-private.json')]:
            shutil.copyfile(source,directory/name); (directory/name).chmod(0o600)
        dev.write_private(directory/'state.json',json.dumps(php('scripts/ops/privacy_manual_control.php','state'),indent=2))
        print(json.dumps({'private_backup':str(directory),'restoration_not_claimed':True})); return


if __name__ == '__main__':
    try: main()
    except (OSError,ValueError,subprocess.SubprocessError):
        raise SystemExit('AN-M01 refused/failed; inspect own private window diagnostics. No credentials printed.') from None
