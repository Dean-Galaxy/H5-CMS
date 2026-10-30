#!/usr/bin/env python3
"""Run artisan checks ONLY on a marked, disposable synthetic C-ui database owned by this checkout."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[5]
spec = importlib.util.spec_from_file_location('h5dev', ROOT / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
if len(sys.argv) < 3 or sys.argv[1] not in ('clean', 'upgrade', 'http'):
    raise SystemExit('Usage: cui_test.py clean|upgrade|http ARTISAN_ARGUMENTS...')
cfg = dev.config()
if (dev.APP / 'bootstrap/cache/config.php').exists():
    raise SystemExit('Refuse cached Laravel config: it could override the selected synthetic DB.')
dev.database_start()  # Own instance only; dev.py rejects mismatched checkout roots.
database = cfg['db'] + '_cui1_' + sys.argv[1] + '_test'
marker = dev.RUN / 'c-ui-1-owned.json'
owned = json.loads(marker.read_text()) if marker.exists() else {'root': str(ROOT), 'databases': []}
assert owned['root'] == str(ROOT), 'Refuse copied C-ui ownership marker'
admin = dev.pg_env(admin=True)
exists = subprocess.run([str(dev.PG / 'psql'), '-Atc', "SELECT 1 FROM pg_database WHERE datname='" + database + "'"],
                        env=admin, capture_output=True, text=True, check=True).stdout.strip()
if not exists:
    subprocess.run([str(dev.PG / 'createdb'), '-O', cfg['user'], database], env=admin, check=True)
    owned['databases'].append(database)
    dev.write_private(marker, json.dumps(owned))
if database not in owned['databases']:
    raise SystemExit('Existing database has no C-ui synthetic ownership marker; refusing to run tests or clear it.')
env = os.environ.copy()
env.update(APP_ENV='testing', APP_DEBUG='false', H5_LOCAL_MODULES='acquisition',
           H5_WORKSPACE=cfg['workspace'] + '_cui1_' + sys.argv[1], DB_CONNECTION='pgsql',
           DB_HOST='127.0.0.1', DB_PORT=str(cfg['db_port']), DB_DATABASE=database, DB_USERNAME=cfg['user'],
           DB_PASSWORD=(dev.RUN / 'pg-app-password').read_text(), DB_URL='', H5_TEST_DATABASE=database,
           H5_C_EXPERIENCE_ENABLED='false', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', ACQUISITION_ANALYTICS_ENABLED='false',
           ACQUISITION_C_ENABLED='false', ACQUISITION_C_SYNTHETIC_POLICY='false',
           ACQUISITION_TRANSPORT='disabled', ACQUISITION_EXTERNAL_ENABLED='false')
raise SystemExit(subprocess.run([dev.PHP, str(dev.APP / 'artisan'), *sys.argv[2:]], cwd=dev.APP, env=env).returncode)
