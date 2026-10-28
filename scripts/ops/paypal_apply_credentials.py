#!/usr/bin/env python3
"""Offline guarded application of staged Sandbox credentials; never enables payments."""
import argparse
import fcntl
import getpass
import json
import os
from pathlib import Path
import re
import stat
import sys
import subprocess
import tempfile
from paypal_sandbox_credentials import ROOT, FIELDS, validate, private_directory

PREFIX = 'H5_BILLING_PAYPAL_SANDBOX_'
PHP = '/opt/homebrew/opt/php@8.4/bin/php'
PARSER = r'''
try {
    require $argv[1];
    $source = stream_get_contents(STDIN);
    $entries = (new \Dotenv\Parser\Parser())->parse($source);
    $names = array_map(fn($entry) => $entry->getName(), $entries);
    if (count($names) !== count(array_unique($names))) { exit(2); }
    echo json_encode(\Dotenv\Dotenv::parse($source), JSON_THROW_ON_ERROR);
} catch (Throwable $error) { exit(2); }
'''


def read_private(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_nlink != 1 or info.st_size > 131072:
            raise ValueError('PRIVATE_FILE_INVALID')
        return stream.read().decode('utf-8')


def parse_env(source, autoload):
    if not autoload.is_file():
        raise ValueError('CANDIDATE_DEPENDENCIES_NOT_INSTALLED')
    result = subprocess.run([PHP, '-r', PARSER, str(autoload)], input=source,
                            capture_output=True, text=True, timeout=10)
    if result.returncode != 0:
        raise ValueError('ENV_PARSE_FAILED')
    return json.loads(result.stdout)


def quote(value):
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'


def bound_environment(root, autoload=None, allowed_purchase_providers=('unconfigured', '')):
    runtime = root / '.runtime'
    for directory in (runtime, runtime / 'paypal-sandbox', root / 'h5-platform'):
        if directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.getuid() or directory.stat().st_mode & 0o022:
            raise ValueError('DIRECTORY_INVALID')
    c = json.loads(read_private(runtime / 'workspace.json'))
    if c.get('root') != str(root):
        raise ValueError('WORKSPACE_BINDING_INVALID')
    env_path = root / 'h5-platform/.env'
    original = read_private(env_path)
    loader = autoload or root / 'h5-platform/vendor/autoload.php'
    existing = parse_env(original, loader)
    if (root / 'h5-platform/bootstrap/cache/config.php').exists():
        raise ValueError('CONFIG_CACHE_MUST_BE_CLEARED_AFTER_STOP')
    if any((runtime / f'pids/{name}.json').exists() for name in ('web', 'queue', 'scheduler')):
        raise ValueError('STOP_OWN_APPLICATION_FIRST')
    expected = {'APP_ENV': 'local', 'APP_URL': f'http://127.0.0.1:{c["port"]}',
                'H5_WORKSPACE': c['workspace'], 'DB_HOST': '127.0.0.1', 'DB_PORT': str(c['db_port']),
                'DB_DATABASE': c['db'], 'DB_USERNAME': c['user'], 'SESSION_COOKIE': c['workspace'],
                'CACHE_PREFIX': c['workspace'], 'DB_QUEUE': c['workspace']}
    if any(existing.get(k) != value for k, value in expected.items()):
        raise ValueError('ENV_WORKSPACE_MISMATCH')
    if existing.get('H5_BILLING_NEW_PURCHASE_PROVIDER', 'unconfigured') not in allowed_purchase_providers or existing.get('H5_BILLING_DRIVER', 'unconfigured') not in ('unconfigured', ''):
        raise ValueError('ACTIVE_PAYMENT_CONFIGURATION_REFUSED')
    return env_path, original, existing, loader, runtime / 'paypal-sandbox'


def render_updates(original, existing, updates, loader):
    assignment = re.compile(r"^\s*(?:export\s+)?[\"']?([A-Za-z0-9_.]+)[\"']?\s*=")
    lines = []
    for line in original.splitlines():
        match = assignment.match(line)
        if not match or match[1] not in updates:
            lines.append(line)
    lines.extend(key + '=' + quote(value) for key, value in updates.items())
    proposed = '\n'.join(lines) + '\n'
    parsed = parse_env(proposed, loader)
    if any(parsed.get(k) != v for k, v in updates.items()) or any(parsed.get(k) != v for k, v in existing.items() if k not in updates):
        raise ValueError('ENV_ROUNDTRIP_MISMATCH')
    return proposed


def saved_document(root):
    path = root / '.runtime/paypal-sandbox/credentials.json'
    doc = json.loads(read_private(path))
    if doc.get('workspace_root') != str(root) or doc.get('environment') != 'sandbox' or doc.get('schema') != 1:
        raise ValueError('WORKSPACE_BINDING_INVALID')
    validate(doc.get('credentials', {}))
    return doc


def prepare(root, autoload=None):
    path, original, existing, loader, directory = bound_environment(root, autoload)
    values = saved_document(root)['credentials']
    if existing.get(PREFIX + 'ENABLED', 'false') not in ('false', '(false)', ''):
        raise ValueError('ACTIVE_PAYMENT_CONFIGURATION_REFUSED')
    updates = {}
    for field in FIELDS:
        key = PREFIX + field.upper()
        old, new = existing.get(key, ''), values[field]
        if field in ('merchant_scope', 'merchant_id', 'client_id') and old and new and old != new:
            raise ValueError('MERCHANT_IDENTITY_CHANGE_REFUSED')
        updates[key] = new or old
    updates[PREFIX + 'ENABLED'] = 'false'
    updates['H5_BILLING_NEW_PURCHASE_PROVIDER'] = 'unconfigured'
    return path, original, render_updates(original, existing, updates, loader), directory


def prepare_api_mode(root, enabled, autoload=None):
    path, original, existing, loader, directory = bound_environment(root, autoload)
    if enabled:
        validate({field: existing.get(PREFIX + field.upper(), '') for field in FIELDS})
    updates = {PREFIX + 'ENABLED': 'true' if enabled else 'false',
               'H5_BILLING_NEW_PURCHASE_PROVIDER': 'unconfigured'}
    return path, original, render_updates(original, existing, updates, loader), directory


def complete_ids(root, additions, autoload=None):
    # No client secret or scope prompt. Never retype them merely to fill later IDs.
    _, _, existing, _, _ = bound_environment(root, autoload)
    if existing.get(PREFIX + 'ENABLED', 'false') not in ('false', '(false)', ''):
        raise ValueError('DISABLE_API_BEFORE_COMPLETION')
    if set(additions) != {'merchant_id', 'product_id', 'webhook_id'}:
        raise ValueError('IDENTIFIER_FIELDS_INVALID')
    doc = saved_document(root)
    for field, value in additions.items():
        old = doc['credentials'][field]
        if old and value and old != value:
            raise ValueError('IDENTIFIER_REBIND_REQUIRES_REVIEW')
        if value:
            doc['credentials'][field] = value
    validate(doc['credentials'])
    atomic_private(root / '.runtime/paypal-sandbox/credentials.json', json.dumps(doc) + '\n')


def atomic_private(path, contents):
    fd, name = tempfile.mkstemp(prefix='.apply-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(contents)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def apply(root, autoload=None, api_mode=None):
    directory = root / '.runtime/paypal-sandbox'
    # Never traverse an unverified parent before acquiring our local lock.
    for parent in (root / '.runtime', directory):
        if parent.is_symlink() or not parent.is_dir() or parent.stat().st_uid != os.getuid() or parent.stat().st_mode & 0o077:
            raise ValueError('DIRECTORY_INVALID')
    fd = os.open(directory / '.apply.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        path, old, proposed, directory = prepare(root, autoload) if api_mode is None else prepare_api_mode(root, api_mode, autoload)
        private_directory(directory)
        backup_fd, backup_name = tempfile.mkstemp(prefix='env-before-', dir=directory)
        with os.fdopen(backup_fd, 'w') as backup:
            os.fchmod(backup.fileno(), 0o600)
            backup.write(old)
            backup.flush()
            os.fsync(backup.fileno())
        if read_private(path) != old:
            raise ValueError('ENV_CHANGED_DURING_APPLY')
        atomic_private(path, proposed)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--apply', action='store_true', help='Apply staged credentials; payments remain disabled')
    modes.add_argument('--api-only', action='store_true', help='Explicit Sandbox API enablement; purchase remains unconfigured; no network call by this tool')
    modes.add_argument('--off', action='store_true', help='Disable Sandbox API before completion; purchase remains unconfigured')
    modes.add_argument('--complete-ids', action='store_true', help='Private interactive entry of missing IDs; retain Client ID/Secret and scope')
    parser.add_argument('--candidate', required=True, help='Full current accepted checkout SHA, not a branch name')
    args = parser.parse_args()
    try:
        actual = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
        if not re.fullmatch('[0-9a-f]{40}', args.candidate) or actual != args.candidate:
            raise ValueError('CANDIDATE_MISMATCH')
        dirty = subprocess.run(['git', '-C', str(ROOT), 'diff', '--quiet', 'HEAD', '--', 'h5-platform']).returncode
        untracked = subprocess.check_output(['git', '-C', str(ROOT), 'ls-files', '--others', '--exclude-standard', '--', 'h5-platform'], text=True)
        if dirty != 0 or untracked:
            raise ValueError('APPLICATION_SOURCE_DIRTY')
        if args.complete_ids:
            if not sys.stdin.isatty():
                raise ValueError('PRIVATE_TTY_REQUIRED')
            additions = {field: getpass.getpass(label) for field, label in (('merchant_id', 'Sandbox Merchant ID（留空保留，不回显）：'), ('product_id', 'Sandbox Product ID（留空保留，不回显）：'), ('webhook_id', '本App Webhook ID（留空保留，不回显）：'))}
            complete_ids(ROOT, additions)
        elif args.api_only or args.off:
            apply(ROOT, api_mode=args.api_only)
        elif args.apply:
            apply(ROOT)
        else:
            prepare(ROOT)
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        # Parser errors can contain source excerpts. Do not print exception strings.
        raise SystemExit('未应用：请核对固定候选、工作区/权限、依赖、停机/缓存、商户绑定和输入格式；未启用付款。')
    if args.api_only:
        print('Sandbox API已显式开启；新购仍unconfigured。此工具未联系PayPal；官方准备命令须由00安排。')
    elif args.off:
        print('Sandbox API已关闭，新购仍unconfigured，私密值和本机备份保留。')
    elif args.complete_ids:
        print('已补齐指定ID并保留原Client ID/Secret/scope；尚未应用env。')
    else:
        print('已私密应用，原env已在本机备份；付款仍关闭，未联系PayPal。' if args.apply else '离线检查通过；尚未应用，未启用付款，未联系PayPal。')


if __name__ == '__main__':
    main()
