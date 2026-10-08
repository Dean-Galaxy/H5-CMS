#!/usr/bin/env python3
"""Run explicitly selected 06 suites using the unchanged shared dev.py isolation."""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('h5_dev', ROOT / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
c = dev.config()
dev.database_start()
env = os.environ.copy()
env.update(APP_ENV='testing', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT=str(c['db_port']), DB_DATABASE=c['db']+'_test',
           DB_USERNAME=c['user'], DB_PASSWORD=(dev.RUN/'pg-app-password').read_text(), H5_TEST_DATABASE=c['db']+'_test', H5_WORKSPACE=c['workspace']+'_test', H5_LOCAL_MODULES='')
subprocess.run([dev.PHP, str(dev.APP/'artisan'), 'config:clear'], env=env, cwd=dev.APP, check=True)
args = sys.argv[1:] or ['tests/Integration', 'tests/Security']
raise SystemExit(subprocess.run([dev.PHP, str(dev.APP/'artisan'), 'test', *args], env=env, cwd=dev.APP).returncode)
