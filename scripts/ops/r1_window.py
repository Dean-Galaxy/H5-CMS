#!/usr/bin/env python3
"""06 R1 synthetic runtime. New root/PG only; no auto policy extension or implicit resets."""
import argparse
from datetime import datetime, timezone, timedelta
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import shutil
import signal
import socket
import subprocess
import sys
import time
import urllib.request
from analytics_acceptance import private_json, testing_environment

ROOT = Path(__file__).resolve().parents[2]
BASE = 'c29d3a41419712c0e725d22830c9aea59d631dca'
OUT = ROOT / '.runtime/06/r1'
PHP = '/opt/homebrew/opt/php@8.4/bin/php'
PG = Path('/opt/homebrew/opt/postgresql@17/bin')
ROLES = ('manual', 'regression', 'upgrade', 'lifecycle', 'lifecycle_retry1', 'restore')
PRODUCT = ['h5-platform/'+name for name in ('app','bootstrap','config','database','public','resources','routes','composer.json','composer.lock')]+['scripts/dev.py','scripts/browser-assets.lock.json','scripts/install_browser_assets.py']
ROUTER = ROOT / 'scripts/ops/r1_window_router.php'


def git(*args):
    return subprocess.check_output(['git','-C',str(ROOT),*args],text=True,stderr=subprocess.DEVNULL).strip()


def private_write(path, data):
    directory = path.parent
    for ancestor in [ROOT/'.runtime', ROOT/'.runtime/06', OUT]:
        if ancestor.exists() and (ancestor.is_symlink() or not ancestor.is_dir() or ancestor.stat().st_uid != os.getuid() or ancestor.stat().st_mode & 0o077):
            raise ValueError('PRIVATE_RUNTIME_DIRECTORY_REQUIRED')
    directory.mkdir(mode=0o700,parents=True,exist_ok=True)
    if path.is_symlink() or (path.exists() and path.stat().st_nlink != 1): raise ValueError('NO_PRIVATE_LINKS')
    fd=os.open(path,os.O_CREAT|os.O_TRUNC|os.O_WRONLY|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as stream: stream.write(data if isinstance(data,str) else json.dumps(data,indent=2))
    path.chmod(0o600)


def root_guard(root):
    if not root.is_absolute() or root.name != 'H5' or root.parent.name != 'h5-r1-registration-manual' or root != root.resolve():
        raise ValueError('NEW_R1_ROOT_REQUIRED')


def binding(root, cfg):
    root_guard(root)
    suffix=hashlib.sha256(str(root).encode()).hexdigest()[:10]
    expected={'root':str(root),'workspace':'h5_'+suffix,'db':'h5_'+suffix,'user':'h5_app_'+suffix,'port':8412,'db_port':5912}
    if cfg != expected: raise ValueError('EXACT_R1_WORKSPACE_REQUIRED')
    return cfg


def private_text(path):
    info=path.lstat()
    if not path.is_file() or path.is_symlink() or info.st_uid!=os.getuid() or info.st_nlink!=1 or info.st_mode&0o077:raise ValueError('OWN_PRIVATE_TEXT_REQUIRED')
    return path.read_text()


def config():
    root_guard(ROOT)
    cfg=binding(ROOT,private_json(ROOT/'.runtime/workspace.json'))
    for path in (ROOT/'h5-platform/.env',ROOT/'.runtime/pg-app-password',ROOT/'.runtime/pg-admin-password'):private_text(path)
    return cfg


def migrations():
    names=git('ls-tree','-r','--name-only','HEAD','--','h5-platform/database/migrations').splitlines()
    return {Path(p).stem:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in names if p.endswith('.php')}


def source(candidate):
    if not re.fullmatch('[a-f0-9]{40}',candidate) or git('rev-parse','HEAD') != candidate: raise ValueError('FULL_FIXED_HEAD_REQUIRED')
    subprocess.run(['git','-C',str(ROOT),'diff','--exit-code',BASE,candidate,'--',*PRODUCT],check=True,stdout=subprocess.DEVNULL)
    if git('status','--porcelain','--',*PRODUCT): raise ValueError('PRODUCT_MUST_BE_COMMITTED')
    runtime_tools(candidate)
    if (ROOT/'h5-platform/bootstrap/cache/config.php').exists(): raise ValueError('NO_CACHED_CONFIG')


def committed_files(candidate, names):
    result={}
    for relative in names:
        path=ROOT/relative
        if path.is_symlink() or not path.is_file():raise ValueError('REGULAR_COMMITTED_TOOL_REQUIRED')
        committed=subprocess.check_output(['git','-C',str(ROOT),'show',candidate+':'+relative],stderr=subprocess.DEVNULL)
        actual=path.read_bytes()
        if committed!=actual:raise ValueError('UNCOMMITTED_TOOL_REFUSED')
        result[relative]=hashlib.sha256(committed).hexdigest()
    return result


def runtime_tools(candidate=None):
    candidate=candidate or git('rev-parse','HEAD')
    names=git('ls-tree','-r','--name-only',candidate,'--','scripts/ops/analytics_acceptance.py','scripts/ops/r1_window.py','scripts/ops/r1_window_runtime.php','scripts/ops/r1_window_boundary.php','scripts/ops/r1_window_router.php','scripts/ops/r1_window_control.php','scripts/ops/r1_browser.cjs','scripts/ops/r1_rehearsal.py','scripts/ops/r1_probe.php','scripts/ops/tests/test_r1_window.py').splitlines()
    expected={'scripts/ops/'+name for name in ['analytics_acceptance.py','r1_window.py','r1_window_runtime.php','r1_window_boundary.php','r1_window_router.php','r1_window_control.php','r1_browser.cjs','r1_rehearsal.py','r1_probe.php','tests/test_r1_window.py']}
    if set(names)!=expected:raise ValueError('COMPLETE_COMMITTED_TOOL_SET_REQUIRED')
    if git('status','--porcelain','--untracked-files=all','--','scripts/ops/r1*','scripts/ops/tests/test_r1*'):raise ValueError('DIRTY_OR_NEW_R1_TOOL_REFUSED')
    return committed_files(candidate,names)


def compare_restore_tree(source, destination, index, scope):
    expected={name[len(scope)+1:]:digest for name,digest in index.items() if name.startswith(scope+'/')}
    if source.is_symlink() or destination.is_symlink() or not destination.is_dir():raise ValueError('REGULAR_RESTORE_TREE_REQUIRED')
    actual={}
    for path in destination.rglob('*'):
        if path.is_symlink() or path.resolve()!=path or path.stat().st_uid!=os.getuid() or path.stat().st_mode&0o077:raise ValueError('PRIVATE_REGULAR_RESTORE_TARGET_REQUIRED')
        if path.is_file():
            if path.stat().st_nlink!=1:raise ValueError('NO_RESTORE_HARDLINKS')
            actual[str(path.relative_to(destination))]=hashlib.sha256(path.read_bytes()).hexdigest()
    if actual!=expected:raise ValueError('RESTORE_TARGET_SET_OR_BYTES_DIFFER')
    return {'scope':scope,'files':len(actual),'sha256_verified':True}


def dependencies():
    lock=json.loads((ROOT/'h5-platform/composer.lock').read_text());installed=json.loads((ROOT/'h5-platform/vendor/composer/installed.json').read_text())
    items=installed.get('packages',[]) if isinstance(installed,dict) else installed
    actual={p['name']:p for p in items};expected=lock['packages']+lock.get('packages-dev',[])
    for package in expected:
        p=actual.get(package['name'])
        if not p or p['version'].lstrip('v')!=package['version'].lstrip('v') or p.get('source',{}).get('reference')!=package.get('source',{}).get('reference'):raise ValueError('INSTALL_EXACT_LOCKED_DEPENDENCIES')
    subprocess.run([sys.executable,'-B',str(ROOT/'scripts/install_browser_assets.py'),'--check'],check=True,capture_output=True)
    return {'composer_packages':len(expected),'browser_assets_locked':True}


def manifest(candidate, future=False):
    cfg=config();source(candidate)
    doc=private_json(OUT/'manifest.json')
    if doc.get('candidate') != candidate or doc.get('workspace') != cfg or doc.get('product_base') != BASE or doc.get('migrations') != migrations() or doc.get('tools_sha256') != runtime_tools() or doc.get('environment_sha256') != hashlib.sha256(private_text(ROOT/'h5-platform/.env').encode()).hexdigest():
        raise ValueError('EXACT_MANIFEST_REQUIRED')
    if doc.get('approved') is not True or not doc.get('00_reference'): raise ValueError('00_APPROVAL_REQUIRED')
    policy(doc['policy_from'],doc['policy_until'],future=future)
    return cfg,doc


def policy(start_text,end_text,now=None,future=True):
    pattern=r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})'
    if any(not isinstance(value,str) or not re.fullmatch(pattern,value) for value in (start_text,end_text)):raise ValueError('ABSOLUTE_POLICY_REQUIRED')
    start=datetime.fromisoformat(start_text.replace('Z','+00:00'));end=datetime.fromisoformat(end_text.replace('Z','+00:00'))
    if not timedelta(0)<end-start<=timedelta(hours=24):raise ValueError('BOUNDED_ABSOLUTE_POLICY_REQUIRED')
    if future and not start<=(now or datetime.now(timezone.utc))<end:raise ValueError('POLICY_NOT_CURRENT_NO_AUTO_EXTENSION')
    return start,end


def verify_backup(directory):
    if directory.resolve()!=directory or not directory.is_relative_to(OUT/'backups') or directory==OUT/'backups':raise ValueError('OWN_BACKUP_ONLY')
    for parent in [directory,*(p for p in directory.rglob('*') if p.is_dir())]:
        if parent.is_symlink() or parent.stat().st_uid!=os.getuid() or parent.stat().st_mode&0o077:raise ValueError('PRIVATE_BACKUP_DIRECTORIES_REQUIRED')
    index=private_json(directory/'sha256.json')
    actual={str(p.relative_to(directory)) for p in directory.rglob('*') if p.is_file() and p!=directory/'sha256.json'}
    if set(index)!=actual or not {'database.dump','environment-private','workspace-private.json','manifest-private.json','fingerprint.json','state.json','fixtures/content.json'}.issubset(actual) or not any(v.startswith('media/') for v in actual):raise ValueError('COMPLETE_BACKUP_REQUIRED')
    for name,value in index.items():
        p=directory/name
        if p.resolve()!=p or not p.is_relative_to(directory) or not p.is_file() or p.stat().st_nlink!=1 or p.stat().st_uid!=os.getuid() or p.stat().st_mode&0o077 or hashlib.sha256(p.read_bytes()).hexdigest()!=value:raise ValueError('BACKUP_DIGEST_MISMATCH')
    return len(index)


def dev_module():
    spec=importlib.util.spec_from_file_location('own_r1_dev',ROOT/'scripts/dev.py');dev=importlib.util.module_from_spec(spec);spec.loader.exec_module(dev);return dev


def db_name(cfg,role):
    if role not in ROLES or not re.fullmatch('h5_[a-f0-9]{10}',cfg.get('db','')): raise ValueError('R1_ROLE_REQUIRED')
    return cfg['db']+'_r1_'+role+'_test'


def ensure_db(cfg,role):
    name=db_name(cfg,role);dev=dev_module();pg_guard(cfg);dev.database_start()
    marker=OUT/'owned-databases.json'
    registry=private_json(marker) if marker.exists() else {'root':str(ROOT),'pg_port':cfg['db_port'],'databases':{}}
    if registry.get('root')!=str(ROOT) or registry.get('pg_port')!=cfg['db_port']: raise ValueError('OWN_DB_REGISTRY_REQUIRED')
    exists=subprocess.check_output([str(PG/'psql'),'-XAtc',"SELECT 1 FROM pg_database WHERE datname='"+name+"'"],env=dev.pg_env(admin=True),text=True).strip()
    if exists and registry['databases'].get(role)!=name: raise ValueError('EXISTING_DATABASE_NOT_MARKED')
    if not exists:
        subprocess.run([str(PG/'createdb'),'-O',cfg['user'],name],env=dev.pg_env(admin=True),check=True,capture_output=True)
        registry['databases'][role]=name;private_write(marker,registry)
    return name


def environment(cfg,doc,role):
    registry=private_json(OUT/'owned-databases.json');name=db_name(cfg,role)
    if registry.get('root')!=str(ROOT) or registry.get('pg_port')!=cfg['db_port'] or registry['databases'].get(role)!=name: raise ValueError('MARKED_OWN_DB_REQUIRED')
    env=testing_environment(ROOT,cfg);env.pop('APP_RUNNING_IN_CONSOLE',None)
    scope=cfg['workspace']+('_r1' if role in ('manual','restore') else '_r1_'+role)
    env.update(APP_ENV='testing',APP_URL='http://127.0.0.1:8412',APP_DEBUG='false',DB_URL='',DB_DATABASE=name,H5_TEST_DATABASE=name,
        H5_WORKSPACE=scope,SESSION_COOKIE=scope+'_session',CACHE_PREFIX=scope+'_cache',DB_QUEUE=scope+'_queue',SESSION_DRIVER='database',CACHE_STORE='database',QUEUE_CONNECTION='database',MAIL_MAILER='preview',
        H5_R1_WINDOW_ONLY='1',H5_R1_DB_ROLE=role,H5_R1_CANDIDATE=doc['candidate'],H5_R1_MANIFEST_SHA256=hashlib.sha256((OUT/'manifest.json').read_bytes()).hexdigest(),
        H5_C_EXPERIENCE_ENABLED='true',H5_C_REGISTRATION_ENABLED='true',H5_LOCAL_US_TEST_REGION='true',H5_SIMULATION='false',
        H5_MEDIA_ROOT=str(OUT/('restore-media' if role=='restore' else 'media')),ACQUISITION_C_ENABLED='true',ACQUISITION_C_SYNTHETIC_POLICY='true',ACQUISITION_C_SYNTHETIC_UNTIL=doc['policy_until'],
        ACQUISITION_R1_SYNTHETIC_POLICY='true',ACQUISITION_R1_SYNTHETIC_FROM=doc['policy_from'],ACQUISITION_R1_SYNTHETIC_UNTIL=doc['policy_until'],ACQUISITION_R1_OBSERVATIONS_ENABLED='true')
    if role=='regression':
        env.update(H5_LOCAL_MODULES='',H5_C_EXPERIENCE_ENABLED='false',H5_C_REGISTRATION_ENABLED='false',ACQUISITION_C_ENABLED='false',ACQUISITION_C_SYNTHETIC_POLICY='false',ACQUISITION_R1_SYNTHETIC_POLICY='false',ACQUISITION_R1_OBSERVATIONS_ENABLED='false',ACQUISITION_ANALYTICS_ENABLED='false',SESSION_DRIVER='array')
    return env


def pg_guard(cfg):
    data=ROOT/'.runtime/postgres'
    if data.is_symlink() or data.resolve()!=data: raise ValueError('OWN_PG_DATA_REQUIRED')
    file=data/'postmaster.pid'
    if file.exists():
        rows=file.read_text().splitlines()
        if len(rows)<4 or rows[1]!=str(data) or rows[3]!=str(cfg['db_port']): raise ValueError('FOREIGN_PG_RECORD')
        ps=subprocess.run(['ps','-p',rows[0],'-o','command='],capture_output=True,text=True)
        if ps.returncode==0:
            argv=shlex.split(ps.stdout);positions=[i for i,v in enumerate(argv) if v=='-D']
            if len(positions)!=1 or positions[0]+1>=len(argv) or argv[positions[0]+1]!=str(data): raise ValueError('FOREIGN_PG_PROCESS')


def port_free(port):
    with socket.socket() as sock:sock.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);sock.bind(('127.0.0.1',port))


def command(cfg):return [PHP,'-d','opcache.enable_cli=1','-d','expose_php=0','-S',f'127.0.0.1:{cfg["port"]}','-t',str(ROOT/'h5-platform/public'),str(ROUTER)]


def matching(record,cfg):
    if record.get('root')!=str(ROOT) or record.get('command')!=command(cfg) or type(record.get('pid')) is not int or record['pid']<=1:return False
    pid=str(record['pid']);p=subprocess.run(['ps','-p',pid,'-o','command='],capture_output=True,text=True);s=subprocess.run(['ps','-p',pid,'-o','lstart='],capture_output=True,text=True)
    return p.returncode==0 and p.stdout.strip()==' '.join(command(cfg)) and s.stdout.strip()==record.get('started_at')


def no_web():
    if any((ROOT/'.runtime/pids'/(name+'.json')).exists() for name in ('web','queue','scheduler')):raise ValueError('OWN_APPLICATION_MUST_BE_STOPPED')


def control(cfg,doc,role,action,*args):
    result=subprocess.run([PHP,str(ROOT/'scripts/ops/r1_window_control.php'),action,*args],cwd=ROOT,env=environment(cfg,doc,role),capture_output=True,text=True)
    try:data=json.loads(result.stdout)
    except ValueError:data=None
    if result.returncode or not isinstance(data,dict):
        private_write(OUT/'control-error-private.txt',result.stdout+result.stderr);raise ValueError('PRIVATE_CONTROL_FAILURE')
    return data


def safe_suite(value):
    if not isinstance(value,str) or not re.fullmatch(r'tests/(Feature|Integration|Security)(?:/[A-Za-z0-9_]+)*(?:\.php)?',value):return False
    if len(value.split('/'))==2 and value.endswith('.php'):return False
    target=ROOT/'h5-platform'/value
    if target.resolve()!=target or target.is_symlink():return False
    return target.is_file() if value.endswith('.php') else target.is_dir()


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['configure','db','migrate','seed','start','state','fingerprint','actor','actor-status','checkpoint','quiesce','backup','stop','restore','regression'])
    parser.add_argument('--candidate',required=True);parser.add_argument('--role',choices=ROLES,default='manual');parser.add_argument('--label',default='snapshot');parser.add_argument('--backup',type=Path);parser.add_argument('--00-reference',dest='reference')
    args,extra=parser.parse_known_args()
    if extra and args.action!='regression':parser.error('Unknown arguments refused')
    os.umask(0o077);cfg=config()
    if args.action=='configure':
        source(args.candidate)
        if (OUT/'manifest.json').exists():raise ValueError('MANIFEST_EXISTS_NO_POLICY_RENEWAL')
        start=datetime.now(timezone.utc).replace(microsecond=0)-timedelta(seconds=30);end=start+timedelta(hours=23)
        doc={'schema':'06-r1-window-1','product_base':BASE,'candidate':args.candidate,'workspace':cfg,'tools_sha256':runtime_tools(),'dependencies':dependencies(),'environment_sha256':hashlib.sha256(private_text(ROOT/'h5-platform/.env').encode()).hexdigest(),'migrations':migrations(),'policy_from':start.isoformat().replace('+00:00','Z'),'policy_until':end.isoformat().replace('+00:00','Z'),'approved':bool(args.reference),'00_reference':args.reference or '', 'purposes_effective':False,'external':False,'purchase':False,'queue_scheduler':False}
        private_write(OUT/'manifest.json',doc);print(json.dumps({'manifest_created':True,'policy_from':doc['policy_from'],'policy_until':doc['policy_until'],'approved':doc['approved']}));return
    if args.action in ('quiesce','stop'):
        doc=private_json(OUT/'manifest.json')
        if doc.get('workspace')!=cfg or doc.get('candidate')!=args.candidate:raise ValueError('STOP_OWN_CANDIDATE_REQUIRED')
        recordfile=OUT/'process.json';pidfile=ROOT/'.runtime/pids/web.json'
        if recordfile.exists():
            record=private_json(recordfile)
            if record.get('candidate')!=args.candidate or record.get('root')!=str(ROOT) or record.get('command')!=command(cfg):raise ValueError('FOREIGN_WEB_RECORD')
            if pidfile.exists() and private_json(pidfile)!={'pid':record.get('pid'),'marker':str(ROUTER)}:raise ValueError('FOREIGN_PID_POINTER')
            pid=record.get('pid')
            if type(pid)is not int or pid<=1:raise ValueError('OWN_PID_REQUIRED')
            exists=subprocess.run(['ps','-p',str(pid),'-o','pid='],capture_output=True).returncode==0
            if exists and not matching(record,cfg):raise ValueError('REUSED_PID_REFUSED')
            if matching(record,cfg):
                if os.getpgid(pid)!=pid:raise ValueError('OWN_PROCESS_GROUP_REQUIRED')
                os.killpg(pid,signal.SIGTERM)
                for _ in range(50):
                    if not matching(record,cfg):break
                    time.sleep(.1)
            if matching(record,cfg):raise ValueError('OWN_WEB_REMAINS')
            pidfile.unlink(missing_ok=True)
        no_web();port_free(cfg['port'])
        if args.action=='stop':
            pg_guard(cfg)
            if (ROOT/'.runtime/postgres/postmaster.pid').exists():subprocess.run([str(PG/'pg_ctl'),'-D',str(ROOT/'.runtime/postgres'),'-m','fast','-w','stop'],check=True,capture_output=True)
            port_free(cfg['db_port'])
        private_write(OUT/(args.action+'-receipt.json'),{'candidate':args.candidate,'web_port_unbound':True,'pg_port_unbound':args.action=='stop','data_preserved':True,'at':datetime.now(timezone.utc).isoformat()})
        print(json.dumps({'web_stopped':True,'pg_stopped':args.action=='stop','data_preserved':True}));return
    cfg,doc=manifest(args.candidate,future=args.action in ('db','migrate','seed','start','regression'))
    if args.action=='db':print(json.dumps({'marked_database':ensure_db(cfg,args.role)}));return
    env=environment(cfg,doc,args.role);dev=dev_module()
    if args.action=='migrate':
        no_web();subprocess.run([PHP,'artisan','migrate','--force'],cwd=ROOT/'h5-platform',env=env,check=True,capture_output=True)
        state=control(cfg,doc,args.role,'state')
        if set(state['migrations'])!=set(doc['migrations']):raise ValueError('MIGRATION_SET_MISMATCH')
        private_write(OUT/(args.role+'-migration.json'),state);print(json.dumps({'migrations_match_actual_candidate_set':True,'count':len(state['migrations'])}));return
    if args.action=='regression':
        if args.role!='regression':raise ValueError('REGRESSION_DB_ONLY')
        suites=extra or ['tests/Feature/Acquisition','tests/Feature/Identity','tests/Feature/Audience','tests/Feature/Contracts/RegistrationMeasurementTest.php','tests/Feature/Contracts/AcceptedRegistrationMeasurementTest.php','tests/Feature/Contracts/RuntimeEnvironmentGuardTest.php','tests/Feature/Contracts/AdminPanelIntegrationTest.php']
        if any(not safe_suite(s) for s in suites):raise ValueError('SAFE_SUITE_PATH_REQUIRED')
        stamp=str(time.time_ns());target=OUT/('regression-'+args.candidate+'-'+stamp+'-private.txt')
        with target.open('w') as stream:result=subprocess.run([PHP,'-d','memory_limit=768M','artisan','test','--compact',*suites],cwd=ROOT/'h5-platform',env=env,stdout=stream,stderr=subprocess.STDOUT)
        target.chmod(0o600);private_write(OUT/('regression-receipt-'+stamp+'.json'),{'candidate':args.candidate,'role':args.role,'suites':suites,'exit_code':result.returncode,'private_log':str(target),'prior_logs_preserved':True});print(json.dumps({'candidate':args.candidate,'regression_exit_code':result.returncode,'private_log':str(target)}));raise SystemExit(result.returncode)
    if args.action=='seed':
        if args.role!='manual':raise ValueError('MANUAL_SEED_ONLY')
        no_web()
        if (OUT/'seed-attempt.json').exists():raise ValueError('SEED_ONCE_NO_RESET')
        private_write(OUT/'seed-attempt.json',{'candidate':args.candidate,'started':True})
        directory=OUT/'generated';directory.mkdir(mode=0o700,exist_ok=True)
        subprocess.run(['/opt/homebrew/bin/ffmpeg','-nostdin','-v','error','-n','-f','lavfi','-i','testsrc2=s=180x320:r=25','-t','8','-c:v','libx264','-pix_fmt','yuv420p','-movflags','+faststart',str(directory/'synthetic.mp4')],check=True,capture_output=True)
        seeded=control(cfg,doc,args.role,'seed');private_write(OUT/'seed-baseline.json',control(cfg,doc,args.role,'state'));print(json.dumps(seeded));return
    if args.action=='start':
        if args.role not in ('manual','restore'):raise ValueError('NO_WEB_FOR_REGRESSION_OR_LIFECYCLE')
        no_web();port_free(cfg['port']);pg_guard(cfg);dev.database_start()
        process=subprocess.Popen(command(cfg),cwd=ROOT/'h5-platform/public',env=env,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        success=False;pidfile=ROOT/'.runtime/pids/web.json'
        try:
            time.sleep(.5)
            if process.poll()is not None:raise ValueError('WEB_START_FAILURE')
            record={'root':str(ROOT),'candidate':args.candidate,'role':args.role,'pid':process.pid,'command':command(cfg),'started_at':subprocess.check_output(['ps','-p',str(process.pid),'-o','lstart='],text=True).strip(),'manifest_sha256':hashlib.sha256((OUT/'manifest.json').read_bytes()).hexdigest()}
            private_write(OUT/'process.json',record);private_write(pidfile,{'pid':process.pid,'marker':str(ROUTER)})
            opener=urllib.request.build_opener(urllib.request.ProxyHandler({}));start=time.monotonic()
            with opener.open('http://127.0.0.1:8412/health',timeout=45) as response:
                if response.status!=200 or response.headers.get('X-06-R1-Gateway')!='UnconfiguredGateway' or response.headers.get('X-06-R1-Role')!=args.role:raise ValueError('OWN_HEALTH_FAILED')
            private_write(OUT/'live-receipt.json',{**record,'warmup_seconds':time.monotonic()-start,'state':control(cfg,doc,args.role,'state')});success=True
            print(json.dumps({'started':True,'candidate':args.candidate,'role':args.role,'web':8412,'postgres':5912,'queue_scheduler':False}));return
        finally:
            if not success:
                if process.poll()is None:os.killpg(process.pid,signal.SIGTERM);process.wait(timeout=5)
                if pidfile.exists() and private_json(pidfile).get('pid')==process.pid:pidfile.unlink()
                private_write(OUT/'startup-failure.json',{'candidate':args.candidate,'own_web_cleaned':True,'pg_may_remain':True})
    if args.action in ('state','fingerprint','actor','actor-status','checkpoint'):
        action='state' if args.action=='checkpoint' else args.action
        if args.action in ('actor','actor-status') and not re.fullmatch('[a-z0-9-]{1,48}',args.label):raise ValueError('SAFE_ACTOR_LABEL_REQUIRED')
        data=control(cfg,doc,args.role,action,args.label)
        if args.action=='checkpoint':
            if not re.fullmatch('[a-z0-9-]{1,48}',args.label):raise ValueError('SAFE_LABEL_REQUIRED')
            target=OUT/(args.label+'.json')
            if target.exists():raise ValueError('CHECKPOINT_NO_OVERWRITE')
            private_write(target,data)
        print(json.dumps(data));return
    if args.action=='backup':
        if args.role!='manual':raise ValueError('BACKUP_SOURCE_MANUAL_ONLY')
        no_web();pg_guard(cfg)
        directory=OUT/'backups'/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ');directory.mkdir(mode=0o700,parents=True)
        pgenv=dev.pg_env();pgenv['PGDATABASE']=db_name(cfg,args.role)
        subprocess.run([str(PG/'pg_dump'),'-Fc','--no-owner','--no-acl','-f',str(directory/'database.dump')],env=pgenv,check=True,capture_output=True)
        subprocess.run([str(PG/'pg_restore'),'--list',str(directory/'database.dump')],check=True,capture_output=True)
        for src,name in [(OUT/'media','media'),(ROOT/'.runtime/mail','mail'),(OUT/'fixtures','fixtures')]:
            if src.is_symlink() or any(p.is_symlink() for p in src.rglob('*')):raise ValueError('REGULAR_OWN_BACKUP_SOURCES')
            shutil.copytree(src,directory/name)
        for src,name in [(ROOT/'h5-platform/.env','environment-private'),(ROOT/'.runtime/workspace.json','workspace-private.json'),(OUT/'manifest.json','manifest-private.json')]:
            if src.is_symlink() or src.stat().st_nlink!=1:raise ValueError('REGULAR_PRIVATE_CONFIG_REQUIRED')
            shutil.copyfile(src,directory/name)
        private_write(directory/'fingerprint.json',control(cfg,doc,args.role,'fingerprint'))
        private_write(directory/'state.json',control(cfg,doc,args.role,'state'))
        for p in directory.rglob('*'):p.chmod(0o700 if p.is_dir() else 0o600)
        private_write(directory/'sha256.json',{str(p.relative_to(directory)):hashlib.sha256(p.read_bytes()).hexdigest() for p in directory.rglob('*') if p.is_file()})
        print(json.dumps({'private_backup':str(directory),'archive_readable':True,'restoration_executed':False}));return
    if args.action=='restore':
        no_web()
        directory=args.backup
        if directory is None:raise ValueError('OWN_BACKUP_ONLY')
        verify_backup(directory)
        if args.role!='restore':raise ValueError('RESTORE_ROLE_ONLY')
        ensure_db(cfg,'restore');restoreenv=dev.pg_env();restoreenv['PGDATABASE']=db_name(cfg,'restore')
        tables=subprocess.check_output([str(PG/'psql'),'-XAtc',"SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'"],env=restoreenv,text=True).strip()
        if tables!='0' or (OUT/'restore-attempt.json').exists():raise ValueError('NEW_EMPTY_RESTORE_DATABASE_ONLY')
        private_write(OUT/'restore-attempt.json',{'candidate':args.candidate,'source_backup':str(directory),'started':True})
        subprocess.run([str(PG/'pg_restore'),'--no-owner','--no-acl','--exit-on-error','-d',db_name(cfg,'restore'),str(directory/'database.dump')],env=restoreenv,check=True,capture_output=True)
        shutil.copytree(directory/'media',OUT/'restore-media');shutil.copytree(directory/'mail',OUT/'restore-mail')
        index=private_json(directory/'sha256.json')
        media=compare_restore_tree(directory/'media',OUT/'restore-media',index,'media')
        mail=compare_restore_tree(directory/'mail',OUT/'restore-mail',index,'mail')
        actual=control(cfg,doc,'restore','fingerprint');expected=private_json(directory/'fingerprint.json')
        if actual!=expected:raise ValueError('RESTORED_ROWS_DIFFER')
        private_write(OUT/'restore-receipt.json',{'candidate':args.candidate,'independent_destination_database':db_name(cfg,'restore'),'data_fingerprint':actual,'passed':True,'media':media,'mail':mail,'external_enabled':False})
        print(json.dumps({'independent_restore':True,'all_rows_equal':True,'tables':len(actual['tables']),'source_database_untouched':True}));return


if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,subprocess.SubprocessError) as error:
        if ROOT.parent.name=='h5-r1-registration-manual' and OUT.exists():
            private_write(OUT/'operation-error-private.txt',str(error)+'\n'+str(getattr(error,'stdout',''))+'\n'+str(getattr(error,'stderr','')))
        raise SystemExit('06 R1 operation refused/failed; inspect own private evidence. No secrets printed.') from None
