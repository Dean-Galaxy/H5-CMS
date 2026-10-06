#!/usr/bin/env python3
"""Install locked project dependencies and generate an isolated local configuration."""
import hashlib
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / 'h5-platform'
RUN = ROOT / '.runtime'
PHP = '/opt/homebrew/opt/php@8.4/bin/php'
PG = '/opt/homebrew/opt/postgresql@17/bin/postgres'
COMPOSER_VERSION = '2.10.3'
COMPOSER_SHA256 = '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'


def call(args, **kwargs):
    return subprocess.run([str(x) for x in args], check=True, **kwargs)


def main():
    os.umask(0o077)
    RUN.mkdir(mode=0o700, exist_ok=True)
    if not all(Path(p).exists() for p in (PHP, PG)):
        brew = Path('/opt/homebrew/bin/brew')
        if not brew.exists():
            raise SystemExit('此脚本适用于本机 Apple Silicon + Homebrew。其他机器由 01/06 核对系统环境，不会自动执行未知安装器。')
        env = os.environ.copy()
        env.update(HOMEBREW_API_DOMAIN='https://formulae.brew.sh/api', HOMEBREW_BOTTLE_DOMAIN='https://ghcr.io/v2/homebrew/core',
                   HOMEBREW_CACHE=str(RUN / 'brew-cache'), HOMEBREW_NO_AUTO_UPDATE='1', HOMEBREW_NO_INSTALL_CLEANUP='1')
        call([brew, 'install', 'php@8.4', 'postgresql@17'], env=env)
    call([PHP, '-r', 'exit(PHP_VERSION_ID >= 80426 && PHP_VERSION_ID < 80500 ? 0 : 1);'])
    version = call([PG, '--version'], capture_output=True, text=True).stdout
    if not version.strip().startswith('postgres (PostgreSQL) 17.'):
        raise SystemExit('本机基础锁定 PostgreSQL 17；请由 00 核对版本。')
    tools = RUN / 'tools'
    tools.mkdir(exist_ok=True)
    composer = tools / 'composer.phar'
    if not composer.exists():
        call(['curl', '--fail', '--location', '--silent', '--show-error', '--proto', '=https',
              f'https://getcomposer.org/download/{COMPOSER_VERSION}/composer.phar', '-o', composer])
    if hashlib.sha256(composer.read_bytes()).hexdigest() != COMPOSER_SHA256:
        raise SystemExit('Composer 官方文件校验不符，已停止，未执行下载文件。')
    env = os.environ.copy()
    env.update(COMPOSER_HOME=str(RUN / 'composer-home'), COMPOSER_CACHE_DIR=str(RUN / 'composer-cache'))
    env['PATH'] = str(Path(PHP).parent) + os.pathsep + env.get('PATH', '')
    call([PHP, composer, 'install', '--prefer-dist', '--no-interaction', '--no-scripts'], cwd=APP, env=env)
    call([sys.executable, ROOT / 'scripts/dev.py', 'setup'])
    call([PHP, APP / 'artisan', 'package:discover'], cwd=APP)
    call([PHP, APP / 'artisan', 'filament:assets'], cwd=APP)
    venv = RUN / 'contracts'
    if not (venv / 'bin/python').exists():
        call(['/usr/bin/python3', '-m', 'venv', venv])
    call([venv / 'bin/python', '-m', 'pip', 'install', '--index-url', 'https://pypi.org/simple', '-r', ROOT / 'scripts/requirements-contracts.lock'])
    call([venv / 'bin/python', ROOT / 'scripts/validate_contracts.py'])
    print('依赖与独立配置就绪。启动：python3 scripts/dev.py start')


if __name__ == '__main__':
    main()
