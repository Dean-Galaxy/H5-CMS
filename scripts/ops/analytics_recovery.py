import hashlib,importlib.util,json,os,re,secrets,shutil,subprocess,time
from pathlib import Path
ROOT=Path(os.environ['H5_ANALYTICS_ACCEPTANCE_ROOT']).resolve();os.umask(0o077)
import sys;sys.path.insert(0,str(ROOT/'scripts/ops'));import analytics_browser_fixture as fixture
from analytics_acceptance import candidate_guard,private_json,validate_workspace,testing_environment
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();candidate_guard(ROOT,sha);c=validate_workspace(ROOT,private_json(ROOT/'.runtime/workspace.json'))
assert not any((ROOT/'.runtime/pids'/f'{n}.json').exists() for n in ['web','queue','scheduler'])
s=importlib.util.spec_from_file_location('dev',ROOT/'scripts/dev.py');dev=importlib.util.module_from_spec(s);s.loader.exec_module(dev);dev.database_start();pg=dev.PG
run=ROOT/'.runtime/06/analytics-recovery'/secrets.token_hex(4);run.mkdir(mode=0o700,parents=True)
def call(args,env=None,**kw):
 p=subprocess.run([str(a) for a in args],env=env,text=True,capture_output=True,**kw)
 if p.returncode:(run/'error-private.txt').write_text(p.stdout+p.stderr);raise RuntimeError('private recovery subprocess failed')
 return p.stdout.strip()
def query(sql,env):return call([pg/'psql','-XAt','-v','ON_ERROR_STOP=1','-c',sql],env)
def fingerprints(env):
 out={}
 for table in query("select tablename from pg_tables where schemaname='public' order by tablename",env).splitlines():
  assert re.fullmatch('[a-z0-9_]+',table)
  rows=query(f'SELECT row_to_json(t)::text FROM "{table}" t ORDER BY row_to_json(t)::text',env)
  out[table]={'rows':int(query(f'SELECT count(*) FROM "{table}"',env)),'sha256':hashlib.sha256(rows.encode()).hexdigest()}
 return out
source=dev.pg_env(testing=True);before=fingerprints(source);assert before['billing_payments']['rows']==1 and before['billing_entitlements']['rows']==1
call([pg/'pg_dump','-Fc','--no-owner','--no-acl','-f',run/'database.dump'],source)
media=fixture.DIRECTORY/'media';assert media.is_dir() and not any(x.is_symlink() for x in media.rglob('*'))
media_before={str(x.relative_to(media)):hashlib.sha256(x.read_bytes()).hexdigest() for x in media.rglob('*') if x.is_file()};shutil.copytree(media,run/'media')
port=dev.select_port(6506);assert port not in [8206,5606,8377,5877,8406,5906,8407,8408,8409,8303];password=secrets.token_urlsafe(32);(run/'password').write_text(password)
call([pg/'initdb','-D',run/'postgres','-U','h5_recovery','--encoding=UTF8','--locale=C','--auth=scram-sha-256','--pwfile='+str(run/'password')])
with (run/'postgres/postgresql.conf').open('a') as f:f.write(f"\nlisten_addresses='127.0.0.1'\nport={port}\nunix_socket_directories=''\ntimezone='UTC'\n")
target=os.environ.copy();target.update(PGHOST='127.0.0.1',PGPORT=str(port),PGUSER='h5_recovery',PGPASSWORD=password,PGDATABASE=c['db']+'_test');started=False
try:
 call([pg/'pg_ctl','-D',run/'postgres','-l',run/'postgres.log','-w','start']);started=True
 call([pg/'createdb',c['db']+'_test'],target);call([pg/'pg_restore','--exit-on-error','--no-owner','--no-acl','-d',c['db']+'_test',run/'database.dump'],target)
 assert fingerprints(target)==before
 call([pg/'pg_ctl','-D',run/'postgres','-m','fast','-w','stop']);started=False
 call([pg/'pg_ctl','-D',run/'postgres','-l',run/'postgres.log','-w','start']);started=True;assert fingerprints(target)==before
 env=testing_environment(ROOT,c);env.update(DB_PORT=str(port),DB_USERNAME='h5_recovery',DB_PASSWORD=password,H5_MEDIA_ROOT=str(run/'media'),H5_LOCAL_US_TEST_REGION='true',QUEUE_CONNECTION='database',MAIL_MAILER='preview')
 business=['billing_orders','billing_payments','billing_entitlements','billing_subscriptions','content_dramas','content_episodes','content_publications','content_release_plans']
 business=[t for t in business if t in before]
 for _ in range(2):call([fixture.PHP,'artisan','content:publish-due'],env,cwd=ROOT/'h5-platform')
 after=fingerprints(target);assert all(after[t]==before[t] for t in business)
 assert fingerprints(source)==before
 media_after={str(x.relative_to(run/'media')):hashlib.sha256(x.read_bytes()).hexdigest() for x in (run/'media').rglob('*') if x.is_file()};assert media_before==media_after
 report={'candidate':sha,'result':'passed','script_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'independent_pg_instance':True,'source_test_database_only':True,'source_unchanged':True,'matched_tables':len(before),'migration_rows':before['migrations']['rows'],'all_rows_match_after_restore_and_restart':True,'payment_count':before['billing_payments']['rows'],'entitlement_count':before['billing_entitlements']['rows'],'publish_due_runs':2,'business_tables_unchanged_after_two_runs':business,'media_files':len(media_before),'media_hashes_match':True,'dump_sha256':hashlib.sha256((run/'database.dump').read_bytes()).hexdigest(),'official_calls':False,'cloud_reboot_verified':False}
 (run/'safe-report.json').write_text(json.dumps(report,indent=2)+'\n');(ROOT/'.runtime/06/analytics-final/recovery-safe.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report))
finally:
 if started:call([pg/'pg_ctl','-D',run/'postgres','-m','fast','-w','stop'])
