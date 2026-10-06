#!/usr/bin/env python3
"""One isolated local runtime per checkout. Never deletes a database or user data."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import secrets
import signal
import socket
import subprocess
import sys
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / 'h5-platform'
RUN = ROOT / '.runtime'
CONFIG = RUN / 'workspace.json'
PHP = os.environ.get('H5_PHP_BIN', '/opt/homebrew/opt/php@8.4/bin/php')
PG = Path(os.environ.get('H5_PG_BIN', '/opt/homebrew/opt/postgresql@17/bin'))


def call(args, **kw):
    return subprocess.run([str(x) for x in args], check=True, **kw)


def write_private(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as f:
        f.write(value)
    path.chmod(0o600)


def config():
    if not CONFIG.exists():
        raise SystemExit('请先运行：python3 scripts/dev.py setup')
    c = json.loads(CONFIG.read_text())
    if c['root'] != str(ROOT):
        raise SystemExit('拒绝使用从其他工作区复制的配置。请由本任务重新建立本工作区 .runtime。')
    return c


def available(port):
    with socket.socket() as s:
        try:
            s.bind(('127.0.0.1', port))
            return True
        except OSError:
            return False


def select_port(base):
    for p in range(base, base + 100):
        if available(p):
            return p
    raise SystemExit('没有可用端口，请指定 --port 和 --db-port。')


def setup(args):
    if CONFIG.exists():
        config()
        print('已有本工作区配置，保留密钥、端口和数据。')
        return
    if (APP / '.env').exists():
        raise SystemExit('发现未登记的 .env，拒绝覆盖。先由开发任务核对来源。')
    RUN.mkdir(mode=0o700, exist_ok=True)
    RUN.chmod(0o700)
    suffix = hashlib.sha256(str(ROOT).encode()).hexdigest()[:10]
    c = dict(root=str(ROOT), workspace='h5_' + suffix,
             port=args.port or select_port(8101 if str(ROOT) == '/Volumes/KESU/H5' else 8200 + int(suffix[:3], 16) % 700),
             db_port=args.db_port or select_port(5541 if str(ROOT) == '/Volumes/KESU/H5' else 5700 + int(suffix[:3], 16) % 700),
             db='h5_' + suffix, user='h5_app_' + suffix)
    if c['port'] == c['db_port'] or not all(available(c[k]) for k in ('port', 'db_port')):
        raise SystemExit('端口不可用，不会停止占用端口的其他程序。')
    write_private(CONFIG, json.dumps(c, indent=2))
    db_password = secrets.token_urlsafe(32)
    admin_password = secrets.token_urlsafe(32)
    write_private(RUN / 'pg-admin-password', admin_password)
    write_private(RUN / 'pg-app-password', db_password)
    env = (APP / '.env.example').read_text()
    values = {
        'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'APP_URL': f'http://127.0.0.1:{c["port"]}', 'APP_ENV': 'local', 'APP_DEBUG': 'false',
        'H5_WORKSPACE': c['workspace'], 'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1',
        'DB_PORT': str(c['db_port']), 'DB_DATABASE': c['db'], 'DB_USERNAME': c['user'],
        'DB_PASSWORD': db_password, 'SESSION_COOKIE': c['workspace'],
        'CACHE_PREFIX': c['workspace'], 'DB_QUEUE': c['workspace'],
        'H5_MEDIA_ROOT': str(RUN / 'media'), 'MAIL_MAILER': 'preview',
    }
    lines = env.splitlines()
    for k, v in values.items():
        lines = [x for x in lines if not x.startswith(k + '=')]
        lines.append(k + '=' + json.dumps(v))
    write_private(APP / '.env', '\n'.join(lines) + '\n')
    for name in ('media', 'logs', 'pids', 'mail'):
        (RUN / name).mkdir(mode=0o700, exist_ok=True)
    print(f'本工作区：{c["workspace"]}，网站 {c["port"]}，数据库 {c["db_port"]}。配置已独立生成。')


def pg_env(admin=False, testing=False):
    c = config()
    env = os.environ.copy()
    env.update(PGHOST='127.0.0.1', PGPORT=str(c['db_port']),
               PGUSER='h5_local_admin' if admin else c['user'],
               PGPASSWORD=(RUN / ('pg-admin-password' if admin else 'pg-app-password')).read_text(),
               PGDATABASE='postgres' if admin else c['db'] + ('_test' if testing else ''))
    return env


def database_start():
    c = config()
    data = RUN / 'postgres'
    if not data.exists():
        call([PG / 'initdb', '-D', data, '-U', 'h5_local_admin', '--encoding=UTF8',
              '--locale=C', '--auth=scram-sha-256', '--pwfile=' + str(RUN / 'pg-admin-password')], stdout=subprocess.DEVNULL)
        with (data / 'postgresql.conf').open('a') as f:
            f.write(f"\nlisten_addresses = '127.0.0.1'\nport = {c['db_port']}\nunix_socket_directories = ''\ntimezone = 'UTC'\n")
    running = subprocess.run([str(PG / 'pg_ctl'), '-D', str(data), 'status'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
    if not running:
        if not available(c['db_port']):
            raise SystemExit('数据库端口被其他实例占用；已拒绝连接或停止它。')
        call([PG / 'pg_ctl', '-D', data, '-l', RUN / 'logs/postgres.log', '-w', 'start'], stdout=subprocess.DEVNULL)
    env = pg_env(admin=True)
    role_exists = call([PG / 'psql', '-Atc', f"SELECT 1 FROM pg_roles WHERE rolname='{c['user']}'"], env=env, capture_output=True, text=True).stdout.strip()
    if not role_exists:
        password = (RUN / 'pg-app-password').read_text()
        call([PG / 'psql', '-v', 'ON_ERROR_STOP=1'], env=env, input=f"CREATE ROLE {c['user']} LOGIN PASSWORD '{password}';\n", text=True, stdout=subprocess.DEVNULL)
    for db in (c['db'], c['db'] + '_test'):
        exists = call([PG / 'psql', '-Atc', f"SELECT 1 FROM pg_database WHERE datname='{db}'"], env=env, capture_output=True, text=True).stdout.strip()
        if not exists:
            call([PG / 'createdb', '-O', c['user'], db], env=env)


def process_alive(name):
    path = RUN / 'pids' / (name + '.json')
    if not path.exists():
        return False
    p = json.loads(path.read_text())
    result = subprocess.run(['ps', '-p', str(p['pid']), '-o', 'command='], capture_output=True, text=True)
    return result.returncode == 0 and p['marker'] in result.stdout


def spawn(name, command, marker):
    if process_alive(name):
        return
    # PHP's development server logs full signed URLs; do not persist access logs.
    out = open(os.devnull, 'wb') if name == 'web' else open(RUN / 'logs' / (name + '.log'), 'ab')
    with out:
        p = subprocess.Popen(command, cwd=APP / 'public' if name == 'web' else APP, stdout=out, stderr=out, stdin=subprocess.DEVNULL, start_new_session=True)
    write_private(RUN / 'pids' / (name + '.json'), json.dumps(dict(pid=p.pid, marker=marker)))


def start():
    c = config()
    database_start()
    if not (APP / 'public/js/filament').exists():
        call([PHP, APP / 'artisan', 'filament:assets'], cwd=APP)
    call([PHP, APP / 'artisan', 'migrate', '--force'], cwd=APP)
    if not process_alive('web') and not available(c['port']):
        raise SystemExit('网站端口已被占用，保留其他进程。')
    router = str(APP / 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')
    spawn('web', [PHP, '-d', 'opcache.enable_cli=1', '-d', 'expose_php=0', '-S', f'127.0.0.1:{c["port"]}', '-t', str(APP / 'public'), router], router)
    spawn('queue', [PHP, str(APP / 'artisan'), 'queue:work', '--queue=' + c['workspace'], '--sleep=1', '--tries=3', '--timeout=60'], str(APP / 'artisan') + ' queue:work')
    spawn('scheduler', [PHP, str(APP / 'artisan'), 'schedule:work'], str(APP / 'artisan') + ' schedule:work')
    time.sleep(1)
    if not status():
        raise SystemExit('有服务未就绪，请查看状态和本工作区日志；数据仍保留。')


def status():
    c = config()
    checks = {name: process_alive(name) for name in ('web', 'queue', 'scheduler')}
    db = subprocess.run([str(PG / 'psql'), '-Atc', 'select current_database()'], env=pg_env(), capture_output=True, text=True)
    checks['database'] = db.returncode == 0 and db.stdout.strip() == c['db']
    try:
        # Local probes must bypass macOS/system proxy settings.
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        with opener.open(f'http://127.0.0.1:{c["port"]}/health', timeout=30) as r:
            checks['http'] = r.status == 200
    except Exception:
        checks['http'] = False
    print(json.dumps(dict(workspace=c['workspace'], checks=checks, website=f'http://127.0.0.1:{c["port"]}',
                          admin=f'http://127.0.0.1:{c["port"]}/admin', database=c['db'], db_port=c['db_port'],
                          mail='仅本地预览，未验证真实送达', media=str(RUN / 'media')), ensure_ascii=False, indent=2))
    return all(checks.values())


def stop():
    config()
    for name in ('scheduler', 'queue', 'web'):
        p = RUN / 'pids' / (name + '.json')
        if process_alive(name):
            os.killpg(json.loads(p.read_text())['pid'], signal.SIGTERM)
        if p.exists():
            p.unlink()
    if (RUN / 'postgres/postmaster.pid').exists():
        call([PG / 'pg_ctl', '-D', RUN / 'postgres', '-m', 'fast', '-w', 'stop'], stdout=subprocess.DEVNULL)
    print('本工作区的网站、队列、定时进程与数据库已停止；保留全部数据。')


def test():
    c = config()
    database_start()
    env = os.environ.copy()
    env.update(APP_ENV='testing', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT=str(c['db_port']),
               DB_DATABASE=c['db'] + '_test', DB_USERNAME=c['user'], DB_PASSWORD=(RUN / 'pg-app-password').read_text(),
               H5_TEST_DATABASE=c['db'] + '_test', H5_WORKSPACE=c['workspace'] + '_test')
    call([PHP, APP / 'artisan', 'config:clear'], cwd=APP, env=env)
    call([PHP, APP / 'artisan', 'test'], cwd=APP, env=env)


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['setup', 'start', 'status', 'stop', 'test', 'owner', 'mail', 'open'])
    parser.add_argument('--port', type=int)
    parser.add_argument('--db-port', type=int)
    a = parser.parse_args()
    if a.action == 'setup':
        setup(a)
    elif a.action == 'start':
        start()
    elif a.action == 'status':
        sys.exit(0 if status() else 1)
    elif a.action == 'stop':
        stop()
    elif a.action == 'test':
        test()
    elif a.action == 'owner':
        database_start()
        call([PHP, APP / 'artisan', 'identity:owner'], cwd=APP)
    elif a.action == 'mail':
        config()
        call(['open', RUN / 'mail'])
        print('已打开本工作区邮件预览目录；这些邮件未发往真实收件箱。')
    elif a.action == 'open':
        c = config()
        call(['open', f'http://127.0.0.1:{c["port"]}'])


if __name__ == '__main__':
    main()
