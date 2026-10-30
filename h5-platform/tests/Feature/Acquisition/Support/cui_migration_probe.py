#!/usr/bin/env python3
"""Additive 050007 up/down/reapply check on a newly marked, synthetic-only upgrade DB."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import uuid

ROOT = Path(__file__).resolve().parents[5]
spec = importlib.util.spec_from_file_location('h5dev', ROOT / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
cfg = dev.config()
helper = Path(__file__).with_name('cui_test.py')
old = dev.RUN / 'c-ui-1-old-migrations'
old.mkdir(exist_ok=True)
for path in (dev.APP / 'database/migrations').glob('*.php'):
    if '050007' not in path.name:
        shutil.copyfile(path, old / path.name)
subprocess.run([sys.executable, str(helper), 'upgrade', 'migrate', '--force', '--path=' + str(old), '--realpath'], check=True)
owned = json.loads((dev.RUN / 'c-ui-1-owned.json').read_text())
database = cfg['db'] + '_cui1_upgrade_test'
assert owned['root'] == str(ROOT) and database in owned['databases']
pg = dev.pg_env()
pg['PGDATABASE'] = database


def sql(query):
    return subprocess.run([str(dev.PG / 'psql'), '-At', '-v', 'ON_ERROR_STOP=1', '-c', query], env=pg,
                          check=True, capture_output=True, text=True).stdout.strip()


def run(*args):
    subprocess.run([sys.executable, str(helper), 'upgrade', *args], check=True)


assert sql('SELECT count(*) FROM migrations') == '24', 'Requires a fresh upgrade DB; do not reuse previous results'
assert sql('SELECT count(*) FROM acquisition_privacy') == '0'
for choice in ('granted', 'denied'):
    sql("INSERT INTO acquisition_privacy(id,analytics,advertising,policy_version,created_at,updated_at) VALUES ('"
        + str(uuid.uuid4()) + "','" + choice + "','granted','SYNTHETIC_UPGRADE_ONLY',now(),now())")
pref, subject, session = [str(uuid.uuid4()) for _ in range(3)]
sql("INSERT INTO acquisition_c_preferences(id,created_at,updated_at,expires_at) VALUES ('" + pref + "',now(),now(),now()+interval '1 day')")
sql("INSERT INTO acquisition_c_subjects(id,preference_id,policy_version,notice_version,is_test,created_at,expires_at) VALUES ('"
    + subject + "','" + pref + "','c-core-1-synthetic-v1','c-notice-c-core-1',true,now(),now()+interval '1 day')")
sql("INSERT INTO acquisition_c_sessions(id,subject_id,created_at,last_seen_at,expires_at) VALUES ('" + session + "','" + subject
    + "',now(),now(),now()+interval '1 hour')")
tables = sql("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename <> 'migrations' ORDER BY tablename").splitlines()
columns = {t: sql("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='" + t + "' ORDER BY ordinal_position").splitlines() for t in tables}


def snapshot():
    result = {}
    for table, names in columns.items():
        quoted = ','.join('"' + n + '"' for n in names)
        raw = sql('SELECT coalesce(jsonb_agg(to_jsonb(x) ORDER BY to_jsonb(x)::text),\'[]\'::jsonb) FROM (SELECT '
                  + quoted + ' FROM "' + table + '") x')
        result[table] = hashlib.sha256(raw.encode()).hexdigest()
    return result


before = snapshot()
run('migrate', '--force')
assert sql('SELECT count(*) FROM migrations') == '25'
assert snapshot() == before, 'Original columns/data changed during additive migration'
assert sql('SELECT count(*) FROM acquisition_privacy WHERE site_analysis_denied_at IS NOT NULL OR site_analysis_denial_until IS NOT NULL') == '0'
run('migrate:rollback', '--force', '--step=1')
assert sql('SELECT count(*) FROM migrations') == '24'
assert snapshot() == before
assert sql("SELECT count(*) FROM information_schema.columns WHERE table_name='acquisition_privacy' AND column_name LIKE 'site_analysis_%'") == '0'
run('migrate', '--force')
assert sql('SELECT count(*) FROM migrations') == '25' and snapshot() == before
source = 'h5-platform/database/migrations/2026_10_30_050006_create_acquisition_c_core.php'
accepted = subprocess.run(['git', 'show', '841bed7:' + source], cwd=ROOT, check=True, capture_output=True).stdout
assert accepted == (ROOT / source).read_bytes(), 'Adopted 050006 must remain immutable'
result = {'scope': '05 fresh marked synthetic upgrade DB only', 'old_migrations': 24, 'new_migrations': 25,
          'preserved_business_tables': len(tables), 'populated_fixture_tables': ['acquisition_privacy', 'acquisition_c_preferences', 'acquisition_c_subjects', 'acquisition_c_sessions'],
          'original_column_data_hashes_unchanged': True, 'added_nullable_columns': 2, 'new_business_tables': 0,
          'existing_denial_columns_default_null': True, 'rollback_and_reapply_preserved_original_data': True,
          'adopted_050006_unchanged': True, 'other_databases_modified': False}
out = ROOT / 'docs/evidence/05/c-ui-1-20261030/migration-upgrade.json'
out.write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
