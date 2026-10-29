#!/usr/bin/env python3
"""06: private, read-only state comparison for the scheduled 8377 MFA rollout.

Does not stop/start services, change code, restore a database, or alter an account.
The before phase requires web/queue/scheduler already stopped by the operator.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('phase', choices=['before', 'after'])
parser.add_argument('--run', required=True)
args = parser.parse_args()
assert re.fullmatch(r'[a-z0-9-]{1,64}', args.run)
os.umask(0o077)
spec = importlib.util.spec_from_file_location('rollout_dev', ROOT/'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
c = dev.config()
assert ROOT == Path('/Users/hejunxiang/.codex/worktrees/h5-06-f09-final/H5')
assert c['port'] == 8377 and c['db_port'] == 5877 and c['db'] == 'h5_e5d3fdcfd4'
assert not (dev.APP/'bootstrap/cache/config.php').exists(), 'Review cached configuration first'
run = dev.RUN/'06-rollout'/args.run
if args.phase == 'before':
    assert not run.exists(), 'Never overwrite a prior snapshot'
    assert not any(dev.process_alive(n) for n in ['web','queue','scheduler']), 'Stop writers before snapshot'
    run.mkdir(parents=True, mode=0o700)
else:
    assert run.is_dir() and not run.is_symlink()

def query(sql):
    p = subprocess.run([str(dev.PG/'psql'), '-XAt', '-v', 'ON_ERROR_STOP=1', '-c', sql],
                       env=dev.pg_env(), capture_output=True, text=True)
    if p.returncode:
        raise RuntimeError('Read-only database query failed; no raw output exported')
    return p.stdout.strip()

def digest(data):
    return hashlib.sha256(data).hexdigest()

def file_hash(path):
    assert path.is_file() and not path.is_symlink()
    return digest(path.read_bytes())

tables = query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename").splitlines()
assert all(re.fullmatch('[a-z_]+', t) for t in tables)
# One read-only repeatable-read transaction; raw rows are hashed, never exported.
statements = ['BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;']
for table in tables:
    statements.append(f"SELECT json_build_object('table','{table}','rows',count(*),'content',coalesce(json_agg(row_to_json(t) ORDER BY row_to_json(t)::text),'[]'::json))::text FROM \"{table}\" t;")
statements.append('COMMIT;')
rows = query('\n'.join(statements)).splitlines()
state = {}
for line in rows:
    if not line.startswith('{'):
        continue
    value = json.loads(line)
    state[value['table']] = {'rows': value['rows'], 'sha256': digest(json.dumps(value['content'],sort_keys=True,separators=(',',':')).encode())}
assert set(state) == set(tables)
media = dev.RUN/'media'
assert not media.is_symlink()
files = sorted(p for p in media.rglob('*') if p.is_file() or p.is_symlink())
assert not any(p.is_symlink() for p in files), 'Media symlinks require separate review'
private = {
    'head': subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),
    'database': c['db'], 'port': c['port'], 'dbPort': c['db_port'],
    'tables': state, 'media': {str(p.relative_to(media)):file_hash(p) for p in files},
    'configuration': {str(p.relative_to(ROOT)):file_hash(p) for p in [dev.APP/'.env',dev.CONFIG,dev.RUN/'pg-app-password',dev.RUN/'pg-admin-password']},
    'processes': {n:dev.process_alive(n) for n in ['web','queue','scheduler']},
    'postgresPid': int((dev.RUN/'postgres/postmaster.pid').read_text().splitlines()[0]),
}
(run/(args.phase+'.private.json')).write_text(json.dumps(private,indent=2)+'\n')
summary = {'phase':args.phase,'checkout':private['head'],'webPort':c['port'],'postgresPort':c['db_port'],'tables':len(state),'mediaFiles':len(files)}
if args.phase == 'before':
    backup = run/'database.dump'
    p = subprocess.run([str(dev.PG/'pg_dump'),'-Fc','--no-owner','--no-acl','-f',str(backup)],env=dev.pg_env(),capture_output=True)
    assert p.returncode == 0, 'Database backup failed'
    backup.chmod(0o600)
    p = subprocess.run([str(dev.PG/'pg_restore'),'--list',str(backup)],capture_output=True)
    assert p.returncode == 0, 'Database archive cannot be listed'
    shutil.copy2(dev.APP/'.env',run/'environment.private');(run/'environment.private').chmod(0o600)
    summary.update(backupCreated=True,archiveListReadable=True,backupSha256=file_hash(backup),backupBytes=backup.stat().st_size,restoreExecuted=False)
else:
    before = json.loads((run/'before.private.json').read_text())
    volatile = {'cache','cache_locks','sessions','jobs','job_batches','failed_jobs'}
    changed = [t for t in sorted(set(state)|set(before['tables'])) if state.get(t)!=before['tables'].get(t)]
    summary.update(businessTablesUnchanged=not any(t not in volatile for t in changed),
        changedTables=changed,operationalTablesExcluded=sorted(volatile),
        mediaUnchanged=private['media']==before['media'],configurationAndKeysUnchanged=private['configuration']==before['configuration'],
        postgresRestarted=private['postgresPid']!=before['postgresPid'],allApplicationProcessesAlive=all(private['processes'].values()))
    summary['passed'] = all(summary[k] for k in ['businessTablesUnchanged','mediaUnchanged','configurationAndKeysUnchanged','postgresRestarted','allApplicationProcessesAlive'])
(run/(args.phase+'.json')).write_text(json.dumps(summary,indent=2)+'\n')
print(json.dumps(summary,indent=2))
if args.phase == 'after' and not summary['passed']:
    raise SystemExit(1)
