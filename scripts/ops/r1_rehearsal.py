#!/usr/bin/env python3
"""06 owned empty upgrade/lifecycle DB only. No full reset, no manual database mutation."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import time
import r1_window as r


def capture_result(doc,role,action,result,expect_json=True):
    # Capture before even examining exit status or attempting JSON. Unique paths preserve failures.
    directory=r.OUT/'probe-output'/(role+'-'+action+'-'+str(time.time_ns()))
    directory.mkdir(mode=0o700,parents=True,exist_ok=False)
    r.private_write(directory/'stdout-private.txt',result.stdout)
    r.private_write(directory/'stderr-private.txt',result.stderr)
    r.private_write(directory/'process.json',{'candidate':doc['candidate'],'role':role,'action':action,'exit_code':result.returncode,'stdout_bytes':len(result.stdout.encode()),'stderr_bytes':len(result.stderr.encode())})
    if result.returncode:raise ValueError('OWN_PROBE_FAILED_OUTPUT_PRESERVED')
    if not expect_json:return None
    try:data=json.loads(result.stdout)
    except ValueError as error:
        r.private_write(directory/'parse-failure.json',{'candidate':doc['candidate'],'exception_type':type(error).__name__,'first_output_preserved':True})
        raise ValueError('OWN_PROBE_JSON_FAILED_OUTPUT_PRESERVED') from None
    if not isinstance(data,dict) or data.get('passed') is False:raise ValueError('OWN_PROBE_RESULT_REFUSED_OUTPUT_PRESERVED')
    return data


def run(cfg,doc,role,action,*args):
    result=subprocess.run([r.PHP,str(r.ROOT/'scripts/ops/r1_probe.php'),action,*args],env=r.environment(cfg,doc,role),cwd=r.ROOT,capture_output=True,text=True)
    return capture_result(doc,role,action,result)


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('mode',choices=['upgrade','lifecycle','lifecycle_retry1']);p.add_argument('--candidate',required=True);a=p.parse_args()
    os.umask(0o077);cfg,doc=r.manifest(a.candidate,future=True);r.no_web();r.ensure_db(cfg,a.mode)
    attempt=r.OUT/(a.mode+'-attempt.json')
    if attempt.exists():raise ValueError('ONE_TIME_OWN_REHEARSAL_NO_RESET')
    env=r.environment(cfg,doc,a.mode);pg=r.dev_module().pg_env();pg['PGDATABASE']=r.db_name(cfg,a.mode)
    count=subprocess.check_output([str(r.PG/'psql'),'-XAtc',"SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'"],env=pg,text=True).strip()
    if count!='0':raise ValueError('EMPTY_OWN_DESTINATION_REQUIRED')
    r.private_write(attempt,{'candidate':a.candidate,'role':a.mode})
    def artisan(*args):
        result=subprocess.run([r.PHP,'artisan',*args],env=env,cwd=r.ROOT/'h5-platform',capture_output=True,text=True)
        capture_result(doc,a.mode,'artisan-'+args[0].replace(':','-'),result,expect_json=False)
    if a.mode=='upgrade':
        last='2026_10_31_050008_create_acquisition_registration_purposes'
        names=sorted(doc['migrations'])
        if last not in names:raise ValueError('EXPECTED_R1_DELTA_NOT_PRESENT')
        for name in names:
            if name!=last:artisan('migrate','--force','--path=database/migrations/'+name+'.php')
        seeded=run(cfg,doc,'upgrade','upgrade-seed')
        artisan('migrate','--force')
        result=run(cfg,doc,'upgrade','upgrade-check')
        # The last migration is its own batch. Rollback only that batch; compare original columns again.
        artisan('migrate:rollback','--force','--step=1')
        rollback=run(cfg,doc,'upgrade','upgrade-check-old')
        artisan('migrate','--force');reapply=run(cfg,doc,'upgrade','upgrade-check')
        result={'candidate':a.candidate,'from_migrations':len(names)-1,'to_migrations':len(names),'seed':seeded,'up':result,'rollback':rollback,'reapply':reapply}
    else:
        artisan('migrate','--force');result=run(cfg,doc,a.mode,'lifecycle')
        result['candidate']=a.candidate
    r.private_write(r.OUT/(a.mode+'-receipt.json'),result);print(json.dumps(result))


if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,subprocess.SubprocessError):raise SystemExit('Owned rehearsal refused/failed. Inspect private evidence; no reset.') from None
