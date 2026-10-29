#!/usr/bin/env python3
"""Explicit own _test browser runtime: no queue/scheduler, no provider API, no external advertising."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import time
import urllib.request
from analytics_acceptance import candidate_guard, mime_version_guard, private_json, validate_workspace, testing_environment

ROOT = Path(__file__).resolve().parents[2]
DIRECTORY = ROOT/'.runtime/06/analytics-browser'
PHP = '/opt/homebrew/opt/php@8.4/bin/php'
ROUTER = ROOT/'h5-platform/tests/Browser/Support/analytics-router.php'


def environment(config, candidate):
    env = testing_environment(ROOT,config)
    env.pop('APP_RUNNING_IN_CONSOLE',None)
    env.update(APP_URL=f'http://127.0.0.1:{config["port"]}',APP_DEBUG='false',MAIL_MAILER='preview',
               SESSION_DRIVER='database',CACHE_STORE='database',QUEUE_CONNECTION='database',
               H5_LOCAL_US_TEST_REGION='true',H5_ANALYTICS_BROWSER_FIXTURE='1',H5_ANALYTICS_BROWSER_CANDIDATE=candidate,
               H5_MEDIA_ROOT=str(DIRECTORY/'media'),CACHE_PREFIX=config['workspace']+'_analytics_test',DB_QUEUE=config['workspace']+'_analytics_test')
    return env


def command(config):
    return [PHP,'-d','expose_php=0','-S',f'127.0.0.1:{config["port"]}','-t',str(ROOT/'h5-platform/public'),str(ROUTER)]


def matching(record, config):
    if record.get('root')!=str(ROOT) or record.get('command')!=command(config):return False
    pid=record.get('pid')
    if type(pid) is not int or pid<=1:return False
    ps=subprocess.run(['ps','-p',str(pid),'-o','command='],capture_output=True,text=True)
    started=subprocess.run(['ps','-p',str(pid),'-o','lstart='],capture_output=True,text=True)
    return ps.returncode==0 and ps.stdout.strip()==' '.join(command(config)) and started.stdout.strip()==record.get('started_at')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate',required=True)
    parser.add_argument('action',choices=['seed','start','stop','flags','settle','dispatch','state'])
    args=parser.parse_args();config=validate_workspace(ROOT,private_json(ROOT/'.runtime/workspace.json'))
    spec=importlib.util.spec_from_file_location('own_dev',ROOT/'scripts/dev.py');dev=importlib.util.module_from_spec(spec);spec.loader.exec_module(dev)
    if args.action=='stop':
        record=private_json(DIRECTORY/'process.json')
        if matching(record,config):
            os.killpg(record['pid'],signal.SIGTERM)
            for _ in range(50):
                if not matching(record,config):break
                time.sleep(.1)
        if matching(record,config):raise ValueError('OWN_FIXTURE_PROCESS_STILL_RUNNING')
        pidfile=ROOT/'.runtime/pids/web.json'
        if pidfile.exists():
            p=private_json(pidfile)
            if p.get('pid')!=record['pid'] or p.get('marker')!=str(ROUTER):raise ValueError('FOREIGN_WEB_PID_RECORD')
            pidfile.unlink()
        if any(dev.process_alive(n) for n in ['web','queue','scheduler']):raise ValueError('OTHER_OWN_APPLICATION_RUNNING')
        if (ROOT/'.runtime/postgres/postmaster.pid').exists():
            subprocess.run([str(dev.PG/'pg_ctl'),'-D',str(ROOT/'.runtime/postgres'),'-m','fast','-w','stop'],stdout=subprocess.DEVNULL,check=True)
        print(json.dumps({'fixture_stopped':True,'own_data_preserved':True}));return 0
    candidate_guard(ROOT,args.candidate);mime_version_guard(ROOT)
    env=environment(config,args.candidate);os.umask(0o077)
    DIRECTORY.mkdir(mode=0o700,parents=True,exist_ok=True)
    if args.action=='start':
        fixture=private_json(DIRECTORY/'fixture.json')
        if fixture['candidate']!=args.candidate:raise ValueError('FIXTURE_CANDIDATE_MISMATCH_REPREPARE_EXPLICITLY')
        if any((ROOT/'.runtime/pids'/f'{n}.json').exists() for n in ['web','queue','scheduler']):raise ValueError('OWN_SERVICE_ALREADY_RECORDED')
        with socket.socket() as s:s.bind(('127.0.0.1',config['port']))
        dev.database_start()
        process=subprocess.Popen(command(config),cwd=ROOT/'h5-platform/public',env=env,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        time.sleep(.5)
        if process.poll() is not None:raise ValueError('LOCAL_FIXTURE_SERVER_FAILED')
        record={'root':str(ROOT),'candidate':args.candidate,'pid':process.pid,'command':command(config),
                'started_at':subprocess.check_output(['ps','-p',str(process.pid),'-o','lstart='],text=True).strip()}
        dev.write_private(DIRECTORY/'process.json',json.dumps(record,indent=2))
        dev.write_private(ROOT/'.runtime/pids/web.json',json.dumps({'pid':process.pid,'marker':str(ROUTER)}))
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(f'http://127.0.0.1:{config["port"]}/health',timeout=20) as response:assert response.status==200
        except Exception:
            os.killpg(process.pid,signal.SIGTERM)
            (ROOT/'.runtime/pids/web.json').unlink()
            raise ValueError('LOCAL_FIXTURE_HEALTH_FAILED') from None
        ready=subprocess.run([PHP,str(ROOT/'scripts/ops/analytics_preflight.php')],cwd=ROOT,env=env,capture_output=True,text=True,check=True)
        readiness=json.loads(ready.stdout)
        dev.write_private(ROOT/'.runtime/06/analytics-browser-readiness.json',json.dumps({'candidate':args.candidate,'combined_ready':readiness['combined_ready'],'missing':readiness['missing']}))
        print(json.dumps({'fixture_started':True,'test_database':config['db']+'_test','port':config['port'],'queue_started':False,'scheduler_started':False,'external_disabled':True,'provider_stub':True}));return 0
    if args.action=='seed':
        if any((ROOT/'.runtime/pids'/f'{n}.json').exists() for n in ['web','queue','scheduler']):raise ValueError('STOP_OWN_SERVICES_BEFORE_FIXTURE')
        dev.database_start()
        subprocess.run([PHP,'artisan','migrate','--force'],cwd=ROOT/'h5-platform',env=env,stdout=subprocess.DEVNULL,check=True)
    if args.action=='flags':
        record=private_json(DIRECTORY/'process.json')
        if record.get('candidate')!=args.candidate or not matching(record,config):raise ValueError('OWN_FIXTURE_WEB_NOT_CURRENT')
    data=sys.stdin.read() if args.action=='settle' else ''
    result=subprocess.run([PHP,str(ROOT/'scripts/ops/analytics_browser_control.php'),args.action],cwd=ROOT,env=env,input=data,capture_output=True,text=True)
    if result.returncode:
        # Diagnostics stay private and must be reviewed before any export.
        (DIRECTORY/'control-error-private.txt').write_text(result.stdout+result.stderr)
        raise ValueError('OWN_FIXTURE_CONTROL_FAILED')
    print(json.dumps(json.loads(result.stdout)));return 0


if __name__=='__main__':
    try:raise SystemExit(main())
    except (OSError,ValueError,AssertionError,subprocess.SubprocessError):
        raise SystemExit('06 fixture refused/failed; inspect own private diagnostics. No raw credentials/IDs printed.') from None
