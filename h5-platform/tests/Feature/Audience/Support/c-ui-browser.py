#!/usr/bin/env python3
"""Bounded real HTTP/Chrome C UI check. Own marked DB/media; no queue, scheduler, public tunnel or provider."""
import datetime
import importlib.util
import json
import os
from pathlib import Path
import signal
import subprocess
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[5]
spec = importlib.util.spec_from_file_location('h5dev', ROOT / 'scripts/dev.py')
dev = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
cfg = dev.config()
header = os.environ.get('H5_C_UI_HEADER_ONLY') == '1'
r1 = os.environ.get('H5_C_UI_R1') == '1' or header
scope = 'header-audience' if header else ('r1-audience' if r1 else 'c-ui-1-audience')
assert not (dev.APP / 'bootstrap/cache/config.php').exists(), 'Refuse cached config'
dev.database_start()
db = cfg['db'] + ('_header_audience_http_test' if header else ('_r1_audience_http_test' if r1 else '_cui1_audience_http_test'))
marker = dev.RUN / (scope + '-owned.json')
admin = dev.pg_env(admin=True)
exists = subprocess.run([str(dev.PG / 'psql'), '-Atc', "SELECT 1 FROM pg_database WHERE datname='" + db + "'"],
                        env=admin, capture_output=True, text=True, check=True).stdout.strip()
if not exists:
    subprocess.run([str(dev.PG / 'createdb'), '-O', cfg['user'], db], env=admin, check=True)
    dev.write_private(marker, json.dumps({'root': str(ROOT), 'database': db}))
assert marker.exists() and json.loads(marker.read_text()) == {'root': str(ROOT), 'database': db}, 'Refuse unowned DB'
out = dev.RUN / scope
media = out / 'media'
media.mkdir(parents=True, exist_ok=True)
port = dev.select_port(8214)
base = 'http://127.0.0.1:' + str(port)
env = os.environ.copy()
env.update(APP_ENV='testing', APP_DEBUG='false', APP_URL=base, H5_LOCAL_MODULES='acquisition',
           H5_C_REGISTRATION_ENABLED='false',
           H5_C_EXPERIENCE_ENABLED='true', H5_LOCAL_US_TEST_REGION='true', H5_WORKSPACE=cfg['workspace']+'_cui1_audience',
           DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT=str(cfg['db_port']), DB_DATABASE=db, DB_URL='',
           DB_USERNAME=cfg['user'], DB_PASSWORD=(dev.RUN/'pg-app-password').read_text(), H5_TEST_DATABASE=db,
           H5_MEDIA_ROOT=str(media), SESSION_DRIVER='database', CACHE_STORE='database', QUEUE_CONNECTION='sync',
           ACQUISITION_C_ENABLED='true', ACQUISITION_C_SYNTHETIC_POLICY='true',
           ACQUISITION_C_SYNTHETIC_UNTIL=(datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=1)).isoformat(),
           ACQUISITION_ANALYTICS_ENABLED='false', ACQUISITION_TRANSPORT='disabled', ACQUISITION_EXTERNAL_ENABLED='false',
           PAYPAL_API_ENABLED='false', H5_BILLING_DRIVER='unconfigured', H5_BILLING_CHECKOUT_PROVIDER='unconfigured', H5_C_UI_BASE=base, H5_C_UI_OUT=str(out))
if r1:
    env.update(H5_C_REGISTRATION_ENABLED='true', H5_WORKSPACE=cfg['workspace']+('_header_audience' if header else '_r1_audience'),
               ACQUISITION_R1_SYNTHETIC_POLICY='true', ACQUISITION_R1_OBSERVATIONS_ENABLED='false',
               ACQUISITION_R1_SYNTHETIC_FROM=(datetime.datetime.now(datetime.timezone.utc)-datetime.timedelta(minutes=1)).isoformat(),
               ACQUISITION_R1_SYNTHETIC_UNTIL=(datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=1)).isoformat())
subprocess.run([dev.PHP, str(dev.APP/'artisan'), 'migrate', '--force'], env=env, cwd=dev.APP, check=True)
for number in range(1, 7):
    folder = media / ('episode-'+str(number))
    folder.mkdir(exist_ok=True)
    if not (folder/'media.mp4').exists():
        subprocess.run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i',
                        'testsrc2=size=160x240:rate=24', '-t', '8', '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
                        '-g', '48', '-movflags', '+faststart', str(folder/'media.mp4')], check=True)
        subprocess.run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-i', str(folder/'media.mp4'), '-c', 'copy',
                        '-hls_time', '2', '-hls_segment_filename', str(folder/'segment-%05d.ts'), str(folder/'index.m3u8')], check=True)
cover = media/'cover'
cover.mkdir(exist_ok=True)
if not (cover/'image.png').exists():
    subprocess.run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=0x244f46:s=160x240',
                    '-frames:v', '1', str(cover/'image.png')], check=True)
subprocess.run([dev.PHP, str(Path(__file__).with_name('c-ui-fixture.php'))], env=env, cwd=dev.APP, check=True)
process = None
try:
    router = str(dev.APP/'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')
    process = subprocess.Popen([dev.PHP, '-S', '127.0.0.1:'+str(port), '-t', str(dev.APP/'public'), router],
                               cwd=dev.APP/'public', env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for _ in range(100):
        assert process.poll() is None, 'Owned HTTP exited'
        try:
            if opener.open(base+'/health', timeout=1).status == 200: break
        except Exception: time.sleep(.1)
    else: raise AssertionError('Owned HTTP startup timeout')
    env['NODE_PATH'] = os.environ.get('NODE_PATH', str(Path.home()/'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules'))
    script = 'header-font-browser.test.cjs' if header else ('purposes-r1-browser.test.cjs' if r1 else 'measurement-c-browser.test.cjs')
    if header and os.environ.get('H5_C_UI_DIALOG_ONLY') == '1':
        script = 'dialog-font-browser.test.cjs'
    subprocess.run(['node', str(Path(__file__).with_name(script))], env=env, cwd=ROOT, check=True)
finally:
    if process:
        os.killpg(process.pid, signal.SIGTERM)
        process.wait(timeout=10)
    dev.write_private(out/'runtime.json', json.dumps({'http_port': port, 'http_stopped': process is not None and process.poll() is not None,
        'database': db, 'database_port': cfg['db_port'], 'queue_scheduler_started': False, 'public_enabled': False,
        'provider_enabled': False, 'other_runtime_touched': False}))
