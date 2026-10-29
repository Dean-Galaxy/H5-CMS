#!/usr/bin/env python3
"""06 new-test-DB process restart proof. No permanent workers or official payment calls."""
import importlib.util,json,os,secrets,subprocess,time
from datetime import datetime,timezone
from pathlib import Path
from analytics_acceptance import candidate_guard,private_json,validate_workspace,testing_environment
ROOT=Path(__file__).resolve().parents[2]
os.umask(0o077)
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();candidate_guard(ROOT,sha)
c=validate_workspace(ROOT,private_json(ROOT/'.runtime/workspace.json'))
assert not any((ROOT/'.runtime/pids'/f'{n}.json').exists() for n in ['web','queue','scheduler'])
s=importlib.util.spec_from_file_location('dev',ROOT/'scripts/dev.py');dev=importlib.util.module_from_spec(s);s.loader.exec_module(dev);dev.database_start()
db=c['db']+'_analytics_restart_'+secrets.token_hex(3)+'_test'
subprocess.run([str(dev.PG/'createdb'),'-O',c['user'],db],env=dev.pg_env(admin=True),check=True,capture_output=True)
run=ROOT/'.runtime/06/analytics-restart'/secrets.token_hex(4);run.mkdir(parents=True,mode=0o700)
env=testing_environment(ROOT,c);env.update(DB_DATABASE=db,H5_TEST_DATABASE=db,H5_WORKSPACE=c['workspace']+'_restart_test',H5_LOCAL_MODULES='',ACQUISITION_ANALYTICS_ENABLED='false',H5_ANALYTICS_RESTART_FIXTURE='1',MAIL_MAILER='array')
(run/'database.json').write_text(json.dumps({'candidate':sha,'database':db})+'\n');pids=[]
def call(args,e=env):
    p=subprocess.Popen(args,cwd=ROOT/'h5-platform',env=e,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True);pids.append(p.pid);out,_=p.communicate(timeout=120)
    (run/f'process-{len(pids)}-private.txt').write_text(out)
    assert p.returncode==0,'Inspect private restart process output'
    return out
control=[dev.PHP,str(ROOT/'scripts/ops/analytics_outbox_fixture.php')]
def inspect():return json.loads(call(control+['inspect']))
assert json.loads(call(control+['seed']))['seeded_committed_fact']
before=inspect();assert before['state']=='pending' and before['refund_behaviors']==0
# First actual standard CLI exits with the durable fact retained because its consumer is absent.
command=[dev.PHP,'artisan','billing:behavior-dispatch','--limit=100']
call(command);pending=inspect();assert pending['state']=='pending' and pending['attempts']==1 and pending['refund_behaviors']==0
wait=max(0,(datetime.fromisoformat(pending['next_attempt_at'].replace('Z','+00:00'))-datetime.now(timezone.utc)).total_seconds())+1
assert wait<=65
print(json.dumps({'candidate':sha,'pending_survived_worker_exit':True,'real_backoff_seconds':round(wait,2)}),flush=True)
time.sleep(wait)
ready=env.copy();ready.update(H5_LOCAL_MODULES='acquisition',ACQUISITION_ANALYTICS_ENABLED='true')
call(command,ready);received=inspect();assert received['state']=='delivered' and received['attempts']==2 and received['refund_behaviors']==1
call(command,ready);replayed=inspect();assert replayed==received
assert before['ledger_hashes']==received['ledger_hashes']==replayed['ledger_hashes']
assert before['fact_key_sha256']==received['fact_key_sha256'] and received['payments']==received['entitlements']==1
assert len(pids)==len(set(pids))
report={'candidate':sha,'passed':True,'original_browser_database_untouched':True,'new_testing_database_only':True,'seed_producer_exited':True,'independent_processes':len(pids),'pending_survived_worker_exit':True,'real_backoff_seconds':round(wait,2),'standard_command':'billing:behavior-dispatch','attempts_after_restart':2,'refund_behaviors':1,'payments':1,'entitlements':1,'stable_key_sha256':received['fact_key_sha256'],'five_financial_tables_identical':True,'repeat_dispatch_unchanged':True,'permanent_worker_started':False,'official_payment':False,'external_delivery_enabled':False}
(run/'safe-report.json').write_text(json.dumps(report,indent=2)+'\n');(ROOT/'.runtime/06/analytics-delta/restart-safe.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report))
