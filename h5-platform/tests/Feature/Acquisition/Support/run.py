#!/usr/bin/env python3
"""Use dev.py's checkout-bound PostgreSQL configuration for focused module tests."""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[5]
spec = importlib.util.spec_from_file_location('h5_workspace_dev', root / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
c = dev.config()
dev.database_start()
env = os.environ.copy()
env.update(APP_ENV='testing', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT=str(c['db_port']),
           DB_DATABASE=c['db'] + '_test', DB_USERNAME=c['user'], DB_PASSWORD=(dev.RUN / 'pg-app-password').read_text(),
           H5_TEST_DATABASE=c['db'] + '_test', H5_WORKSPACE=c['workspace'] + '_test', H5_LOCAL_MODULES='', ACQUISITION_ANALYTICS_ENABLED='false', H5_LOCAL_US_TEST_REGION='false')
args = sys.argv[1:] or ['tests/Feature/Acquisition']
subprocess.run([dev.PHP, str(dev.APP / 'artisan'), 'config:clear'], cwd=dev.APP, env=env, check=True)
raise SystemExit(subprocess.run([dev.PHP, str(dev.APP / 'artisan'), 'test', *args], cwd=dev.APP, env=env).returncode)
