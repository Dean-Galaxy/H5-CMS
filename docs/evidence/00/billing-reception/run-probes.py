#!/usr/bin/env python3
"""Run 00 reception probes against one explicitly selected isolated worktree."""
import argparse
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--workspace', required=True, type=Path)
args = parser.parse_args()
workspace = args.workspace.resolve()
fixture = Path(__file__).resolve().with_name('BillingReceptionTest.php.txt')
os.chdir(workspace)
spec = importlib.util.spec_from_file_location('workspace_dev', workspace / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
cfg = dev.config()  # Reject copied configuration; ROOT must match this worktree.
dev.database_start()
env = os.environ.copy()
env.update(APP_ENV='testing', H5_LOCAL_MODULES='', DB_CONNECTION='pgsql',
           DB_HOST='127.0.0.1', DB_PORT=str(cfg['db_port']),
           DB_DATABASE=cfg['db'] + '_test', DB_USERNAME=cfg['user'],
           DB_PASSWORD=(dev.RUN / 'pg-app-password').read_text(),
           H5_TEST_DATABASE=cfg['db'] + '_test', H5_WORKSPACE=cfg['workspace'] + '_test')
subprocess.run([dev.PHP, str(dev.APP / 'artisan'), 'config:clear'],
               cwd=dev.APP, env=env, check=True)
with tempfile.TemporaryDirectory(prefix='00-billing-probes-', dir=dev.RUN) as directory:
    probe = Path(directory) / 'BillingReceptionTest.php'
    probe.write_text(fixture.read_text())
    result = subprocess.run([dev.PHP, str(dev.APP / 'vendor/bin/phpunit'), str(probe)],
                            cwd=dev.APP, env=env)
raise SystemExit(result.returncode)
