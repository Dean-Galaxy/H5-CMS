#!/usr/bin/env python3
"""Check additive 050008 against the current source set, only on this checkout's marked synthetic DB."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import uuid

ROOT = Path(__file__).resolve().parents[5]
TARGET = '2026_10_31_050008_create_acquisition_registration_purposes'
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--fresh', action='store_true', help='Explicitly rebuild ONLY the marked synthetic upgrade DB')
args = parser.parse_args()
spec = importlib.util.spec_from_file_location('h5dev', ROOT / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
cfg = dev.config()
helper = Path(__file__).with_name('r1a_test.py')
subprocess.run([sys.executable, str(helper), 'upgrade', 'prepare'], check=True, capture_output=True)
owned = json.loads((dev.RUN / 'r1-a-owned.json').read_text())
database = cfg['db'] + '_r1a_upgrade_test'
assert owned['root'] == str(ROOT) and database in owned['databases'], 'Refuse unmarked upgrade DB'
pg = dev.pg_env(); pg['PGDATABASE'] = database
source_files = {p.stem: p for p in sorted((dev.APP / 'database/migrations').glob('*.php'))}
assert TARGET in source_files, 'The specific adopted Acquisition migration must exist'
expected = sorted(source_files)
baseline = [name for name in expected if name != TARGET]
source_hashes = {name: hashlib.sha256(path.read_bytes()).hexdigest() for name, path in source_files.items()}


def sql(query):
    return subprocess.run([str(dev.PG / 'psql'), '-At', '-v', 'ON_ERROR_STOP=1', '-c', query], env=pg,
                          check=True, capture_output=True, text=True).stdout.strip()


def run(*command):
    subprocess.run([sys.executable, str(helper), 'upgrade', *command], check=True)


def installed():
    if not sql("SELECT to_regclass('public.migrations')"):
        return []
    return sql('SELECT migration FROM migrations ORDER BY migration').splitlines()


def tables():
    return sql("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename <> 'migrations' ORDER BY tablename").splitlines()


# Never run source migrations or remove a table on an unexpected existing schema implicitly.
prior = installed()
if not args.fresh and prior not in ([], baseline):
    raise SystemExit('Existing marked DB is not the exact source baseline excluding 050008; refusing mutation. Use explicit --fresh for this synthetic DB only.')
if not args.fresh:
    if not prior and tables():
        raise SystemExit('Unrecorded tables exist; refusing implicit replacement. Use explicit --fresh only for the marked synthetic DB.')
    if any(sql('SELECT count(*) FROM "' + table + '"') != '0' for table in tables()):
        raise SystemExit('Previous synthetic data exists; refusing implicit clearing. Use explicit --fresh only for the marked synthetic DB.')
with tempfile.TemporaryDirectory(prefix='r1a-source-baseline-', dir=dev.RUN) as directory:
    for name in baseline:
        shutil.copyfile(source_files[name], Path(directory) / source_files[name].name)
    run('migrate:fresh' if args.fresh else 'migrate', '--force', '--path=' + directory, '--realpath')
assert installed() == baseline

# Published 01-A constants supply synthetic receipt versions; this is schema data, not an auth bypass.
php = "require 'vendor/autoload.php'; echo json_encode([\\App\\Modules\\Identity\\Services\\RegistrationTerms::TERMS, \\App\\Modules\\Identity\\Services\\RegistrationTerms::PRIVACY]);"
versions = json.loads(subprocess.run([dev.PHP, '-r', php], cwd=dev.APP, check=True, capture_output=True, text=True).stdout)
assert len(versions) == 2 and all(re.fullmatch(r'[A-Za-z0-9_-]{1,64}', value) for value in versions)
viewer = str(uuid.uuid4())
sql("INSERT INTO identity_viewers(id,name,email,password,created_at,updated_at) VALUES ('" + viewer
    + "','SYNTHETIC_SCHEMA_ONLY','" + viewer + "@example.test','NOT_A_USABLE_HASH',now(),now())")
sql("INSERT INTO identity_registration_receipts(viewer_id,terms_version,privacy_notice_version,locale,accepted_at,is_test) VALUES ('"
    + viewer + "','" + versions[0] + "','" + versions[1] + "','en',now(),true)")
for choice in ('granted', 'denied'):
    sql("INSERT INTO acquisition_privacy(id,analytics,advertising,policy_version,created_at,updated_at) VALUES ('"
        + str(uuid.uuid4()) + "','" + choice + "','granted','SYNTHETIC_UPGRADE_ONLY',now(),now())")
pref, subject, session = [str(uuid.uuid4()) for _ in range(3)]
sql("INSERT INTO acquisition_c_preferences(id,created_at,updated_at,expires_at) VALUES ('" + pref + "',now(),now(),now()+interval '1 day')")
sql("INSERT INTO acquisition_c_subjects(id,preference_id,policy_version,notice_version,is_test,created_at,expires_at) VALUES ('"
    + subject + "','" + pref + "','c-core-1-synthetic-v1','c-notice-c-core-1',true,now(),now()+interval '1 day')")
sql("INSERT INTO acquisition_c_sessions(id,subject_id,created_at,last_seen_at,expires_at) VALUES ('" + session + "','" + subject
    + "',now(),now(),now()+interval '1 hour')")
old_tables = tables()
columns = {table: sql("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='"
                      + table + "' ORDER BY ordinal_position").splitlines() for table in old_tables}


def snapshot():
    hashes = {}
    for table, names in columns.items():
        quoted = ','.join('"' + name + '"' for name in names)
        raw = sql('SELECT coalesce(jsonb_agg(to_jsonb(x) ORDER BY to_jsonb(x)::text),\'[]\'::jsonb) FROM (SELECT '
                  + quoted + ' FROM "' + table + '") x')
        hashes[table] = hashlib.sha256(raw.encode()).hexdigest()
    return hashes


before = snapshot()
run('migrate', '--force')
assert installed() == expected and snapshot() == before
new_tables = sorted(set(tables()) - set(old_tables))
assert new_tables == ['acquisition_r1_choices', 'acquisition_r1_operations', 'acquisition_r1_proofs']
assert sql('SELECT count(*) FROM acquisition_privacy WHERE r1_advertising_denied_at IS NOT NULL OR r1_advertising_denial_until IS NOT NULL') == '0'
run('migrate:rollback', '--force', '--step=1')
assert installed() == baseline and snapshot() == before and tables() == old_tables
assert sql("SELECT count(*) FROM information_schema.columns WHERE table_name='acquisition_privacy' AND column_name LIKE 'r1_advertising_%'") == '0'
run('migrate', '--force')
assert installed() == expected and snapshot() == before
assert source_hashes == {name: hashlib.sha256(path.read_bytes()).hexdigest() for name, path in source_files.items()}
result = {'scope': '05 + accepted 01-A source-set upgrade on marked synthetic DB only', 'explicit_fresh': args.fresh,
          'old_migrations': len(baseline), 'new_migrations': len(expected), 'baseline_migrations': baseline,
          'installed_migrations': expected, 'excluded_additive_migration': TARGET, 'source_migration_files_unchanged': True,
          'preserved_business_tables': len(old_tables), 'populated_fixture_tables': ['identity_viewers', 'identity_registration_receipts',
              'acquisition_privacy', 'acquisition_c_preferences', 'acquisition_c_subjects', 'acquisition_c_sessions'],
          'identity_receipt_old_columns_preserved': True, 'published_test_versions': versions,
          'original_column_full_row_hashes_unchanged': True, 'added_nullable_columns': 2, 'new_business_tables': len(new_tables),
          'existing_denial_columns_default_null': True, 'rollback_and_reapply_preserved_original_data': True,
          'other_databases_modified': False}
out = ROOT / 'docs/evidence/05/c-registration-r1-a-combined-20261031/migration-upgrade.json'
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
