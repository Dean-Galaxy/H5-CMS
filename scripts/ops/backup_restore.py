#!/usr/bin/env python3
"""06 local recovery drill. Never restores over any existing database. No external services."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('h5_dev', ROOT/'scripts/dev.py')
dev = importlib.util.module_from_spec(spec); spec.loader.exec_module(dev)
os.umask(0o077)
c = dev.config(); dev.database_start()
run = ROOT/'.runtime/06/recovery'/secrets.token_hex(4); run.mkdir(parents=True)
suffix = run.name
fixture_db = c['db']+'_recovery_'+suffix+'_test'
port = dev.select_port(6506)
pg = dev.PG

def call(args, env=None, **kwargs):
    result = subprocess.run([str(a) for a in args], env=env, text=True, capture_output=True, **kwargs)
    if result.returncode:
        (run/'last-error.txt').write_text(result.stdout + result.stderr)
        raise RuntimeError('Recovery subprocess failed; inspect private last-error.txt')
    return result.stdout

def query(sql, env):
    return call([pg/'psql','-X','-A','-t','-v','ON_ERROR_STOP=1','-c',sql],env).strip()

def fingerprint(env):
    tables = query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename",env).splitlines()
    output = {}
    for t in tables:
        if not t.replace('_','').isalnum(): raise RuntimeError('Unexpected identifier')
        data=query(f'SELECT row_to_json(t)::text FROM "{t}" t ORDER BY row_to_json(t)::text',env)
        output[t]={'rows':int(query(f'SELECT count(*) FROM "{t}"',env)), 'sha256':hashlib.sha256(data.encode()).hexdigest()}
    return output

def php_env(dbport):
    e = os.environ.copy()
    e.update(APP_ENV='testing', APP_DEBUG='false', DB_HOST='127.0.0.1', DB_PORT=str(dbport), DB_DATABASE=fixture_db,
        DB_USERNAME=c['user'] if dbport == c['db_port'] else 'h5_recovery',
        DB_PASSWORD=(dev.RUN/'pg-app-password').read_text() if dbport == c['db_port'] else password,
        H5_TEST_DATABASE=fixture_db, H5_WORKSPACE=c['workspace']+'_recovery_test', MAIL_MAILER='array',
        H5_BILLING_DRIVER='unconfigured', H5_LOCAL_MODULES='', H5_LOCAL_US_TEST_REGION='false',
        H5_MEDIA_ROOT=str(run/'media'), ACQUISITION_TRANSPORT='disabled', ACQUISITION_EXTERNAL_ENABLED='false')
    return e

start=time.monotonic()
# Copy only this checkout's own synthetic preview into a new testing database.
call([pg/'pg_dump','-Fc','--no-owner','--no-acl','-f',run/'preview.dump'],dev.pg_env())
call([pg/'createdb','-O',c['user'],fixture_db],dev.pg_env(admin=True))
source=dev.pg_env(); source['PGDATABASE']=fixture_db
call([pg/'pg_restore','--exit-on-error','--no-owner','--no-acl','-d',fixture_db,run/'preview.dump'],source)
seed=json.loads(call([dev.PHP, ROOT/'h5-platform/tests/Integration/Support/recovery_fixture.php','seed'],php_env(c['db_port'])))
assert seed['payments']==seed['entitlements']==seed['refunds']==1 and seed['access_active'] and not seed['renewal_enabled']
before=fingerprint(source)
call([pg/'pg_dump','-Fc','--no-owner','--no-acl','-f',run/'database.dump'],source)
media=ROOT/'.runtime/media'
files={str(p.relative_to(media)):hashlib.sha256(p.read_bytes()).hexdigest() for p in media.rglob('*') if p.is_file() and not p.is_symlink()}
if any(p.is_symlink() for p in media.rglob('*')): raise RuntimeError('Do not follow media symlinks')
shutil.copytree(media,run/'media')
password=secrets.token_urlsafe(32)
(run/'pg-password').write_text(password); (run/'pg-password').chmod(0o600)
call([pg/'initdb','-D',run/'postgres','-U','h5_recovery','--encoding=UTF8','--locale=C','--auth=scram-sha-256','--pwfile='+str(run/'pg-password')])
with (run/'postgres/postgresql.conf').open('a') as f: f.write(f"\nlisten_addresses='127.0.0.1'\nport={port}\nunix_socket_directories=''\ntimezone='UTC'\n")
target=os.environ.copy(); target.update(PGHOST='127.0.0.1',PGPORT=str(port),PGUSER='h5_recovery',PGPASSWORD=password,PGDATABASE=fixture_db)
started=False
try:
    call([pg/'pg_ctl','-D',run/'postgres','-l',run/'postgres.log','-w','start']); started=True
    call([pg/'createdb',fixture_db],target)
    call([pg/'pg_restore','--exit-on-error','--no-owner','--no-acl','-d',fixture_db,run/'database.dump'],target)
    restored=fingerprint(target)
    assert before==restored, 'Logical row comparison failed'
    restored_media={str(p.relative_to(run/'media')):hashlib.sha256(p.read_bytes()).hexdigest() for p in (run/'media').rglob('*') if p.is_file()}
    assert restored_media==files, 'Media restore mismatch'
    # A real restart of the independent recovery PostgreSQL process.
    call([pg/'pg_ctl','-D',run/'postgres','-m','fast','-w','stop']); started=False
    call([pg/'pg_ctl','-D',run/'postgres','-l',run/'postgres.log','-w','start']); started=True
    assert fingerprint(target)==before
    after=json.loads(call([dev.PHP,ROOT/'h5-platform/tests/Integration/Support/recovery_fixture.php','verify'],php_env(port)))
    assert isinstance(seed['contract'], str) and seed['contract']
    assert after==seed
    for _ in range(2): call([dev.PHP,dev.APP/'artisan','content:publish-due'],php_env(port),cwd=dev.APP)
    policy=json.loads(call([dev.PHP,ROOT/'h5-platform/tests/Integration/Support/recovery_fixture.php','verify'],php_env(port)))
    assert policy['public_episodes']==seed['public_episodes'] and 'missed_pending_policy' in policy['plan_states']
    report={'result':'passed','code_commit':call(['git','-C',ROOT,'rev-parse','HEAD']).strip(),
        'contract':seed['contract'],'source_port':c['db_port'],'source_database':fixture_db,'restore_port':port,'restore_database':fixture_db,
        'independent_pg_instance':True,'tables':before,'media_files':len(files),'media_sha256_match':True,
        'dump_sha256':hashlib.sha256((run/'database.dump').read_bytes()).hexdigest(),'seed':seed,'after_restart':after,'after_two_publish_runs':policy,
        'seconds':round(time.monotonic()-start,2),'payment_evidence':'testing-only FakeGateway; not official Stripe',
        'scope':'local independent recovery, no cloud reboot or offsite retrieval; no worker/web/SMTP/ad/payment network started',
        'private_artifacts':str(run),'application_key':'same checkout key loaded to verify MFA decrypt; not printed or exported'}
    (ROOT/'.runtime/06/backup-restore.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
    print(json.dumps({k:v for k,v in report.items() if k!='tables'},ensure_ascii=False,indent=2))
finally:
    if started: call([pg/'pg_ctl','-D',run/'postgres','-m','fast','-w','stop'])
# Retain the newly named source and stopped recovery cluster for review; never drop a database here.
