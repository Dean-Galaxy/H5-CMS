#!/usr/bin/env python3
"""06 owned empty upgrade/lifecycle DB only. No full reset, no manual database mutation."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import r1_window as r


def run(cfg,doc,role,action,*args):
    result=subprocess.run([r.PHP,str(r.ROOT/'scripts/ops/r1_probe.php'),action,*args],env=r.environment(cfg,doc,role),cwd=r.ROOT,capture_output=True,text=True)
    if result.returncode:
        r.private_write(r.OUT/(role+'-probe-error-private.txt'),result.stdout+result.stderr)
        raise ValueError('OWN_PROBE_FAILED')
    return json.loads(result.stdout)


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('mode',choices=['upgrade','lifecycle']);p.add_argument('--candidate',required=True);a=p.parse_args()
    os.umask(0o077);cfg,doc=r.manifest(a.candidate,future=True);r.no_web();r.ensure_db(cfg,a.mode)
    attempt=r.OUT/(a.mode+'-attempt.json')
    if attempt.exists():raise ValueError('ONE_TIME_OWN_REHEARSAL_NO_RESET')
    env=r.environment(cfg,doc,a.mode);pg=r.dev_module().pg_env();pg['PGDATABASE']=r.db_name(cfg,a.mode)
    count=subprocess.check_output([str(r.PG/'psql'),'-XAtc',"SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'"],env=pg,text=True).strip()
    if count!='0':raise ValueError('EMPTY_OWN_DESTINATION_REQUIRED')
    r.private_write(attempt,{'candidate':a.candidate,'role':a.mode})
    def artisan(*args):
        result=subprocess.run([r.PHP,'artisan',*args],env=env,cwd=r.ROOT/'h5-platform',capture_output=True,text=True)
        if result.returncode:
            r.private_write(r.OUT/(a.mode+'-artisan-error-private.txt'),result.stdout+result.stderr);raise ValueError('REHEARSAL_MIGRATION_FAILED')
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
        artisan('migrate','--force');result=run(cfg,doc,'lifecycle','lifecycle')
        result['candidate']=a.candidate
    r.private_write(r.OUT/(a.mode+'-receipt.json'),result);print(json.dumps(result))


if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,subprocess.SubprocessError):raise SystemExit('Owned rehearsal refused/failed. Inspect private evidence; no reset.') from None
