#!/usr/bin/env python3
"""Explicit future own-C quiesce/backup/stop/restart rehearsal. --plan never reads a runtime."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from analytics_acceptance import private_json
import c_window as window
from c_window_preflight import root_guard, workspace_binding, private_document, validate_manifest

ROOT = Path(__file__).resolve().parents[2]
STEPS = ['fingerprint-before', 'quiesce', 'backup', 'verify-private-archive', 'stop', 'start', 'fingerprint-after', 'quiesce', 'backup', 'stop']


def verify_archive(directory, root):
    base = root / '.runtime/06/c-window/backups'
    if not directory.is_relative_to(base) or directory.resolve() != directory or directory == base:
        raise ValueError('OWN_BACKUP_DIRECTORY_REQUIRED')
    for parent in [base, directory, *[p for p in directory.rglob('*') if p.is_dir()]]:
        info = parent.lstat()
        if parent.is_symlink() or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise ValueError('PRIVATE_BACKUP_DIRECTORY_REQUIRED')
    index = private_json(directory / 'sha256.json')
    if not isinstance(index, dict) or not index:
        raise ValueError('BACKUP_DIGEST_INDEX_REQUIRED')
    actual = set()
    for item in directory.rglob('*'):
        if item.is_symlink(): raise ValueError('NO_BACKUP_LINKS')
        if item.is_file() and item != directory / 'sha256.json': actual.add(str(item.relative_to(directory)))
    if set(index) != actual or not {'database.dump', 'environment-private', 'owner-private.json', 'fixture-private.json', 'manifest-private.json', 'state.json'}.issubset(actual) or not any(name.startswith('media/') for name in actual):
        raise ValueError('COMPLETE_BACKUP_INDEX_REQUIRED')
    for relative, expected in index.items():
        item = directory / relative
        if item.resolve() != item or not item.is_relative_to(directory) or item.stat().st_nlink != 1 or item.stat().st_uid != os.getuid() or item.stat().st_mode & 0o077:
            raise ValueError('OWN_PRIVATE_BACKUP_FILE_REQUIRED')
        if hashlib.sha256(item.read_bytes()).hexdigest() != expected:
            raise ValueError('BACKUP_DIGEST_MISMATCH')
    return {'files_verified': len(index), 'restoration_executed': False}


def run(candidate):
    root_guard(ROOT)
    doc = private_document(ROOT / '.runtime/06/c-window/manifest.json', ROOT)
    validate_manifest(ROOT, candidate, doc)
    config = workspace_binding(ROOT, private_json(ROOT / '.runtime/workspace.json'))
    if config != doc['workspace']: raise ValueError('WORKSPACE_MANIFEST_MISMATCH')
    record = private_json(ROOT / '.runtime/06/c-window/process.json')
    if record.get('candidate') != candidate or not window.matching(record, config): raise ValueError('OWN_LIVE_C_WINDOW_REQUIRED')
    os.umask(0o077)
    output = ROOT / '.runtime/06/c-window' / ('rehearsal-' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ'))
    output.mkdir(mode=0o700)
    result = {'candidate': candidate, 'driver_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'passed': False, 'restoration_executed': False, 'real_device': False, 'steps': []}
    def action(name):
        proc = subprocess.run(['python3', '-B', str(ROOT / 'scripts/ops/c_window.py'), name, '--candidate', candidate], cwd=ROOT, capture_output=True, text=True)
        file = output / (str(len(result['steps'])) + '-' + name + '-private.txt')
        file.write_text(proc.stdout + proc.stderr); file.chmod(0o600)
        if proc.returncode: raise ValueError('LIFECYCLE_OPERATION_FAILED')
        document = json.loads(proc.stdout)
        result['steps'].append({'action': name, 'passed': True})
        return document
    try:
        before = action('fingerprint')
        action('quiesce')
        backup = action('backup'); result['before_restart_archive'] = verify_archive(Path(backup['private_backup']), ROOT)
        action('stop')
        window.port_available(config['port']); window.port_available(config['db_port'])
        action('start')
        after = action('fingerprint')
        if before != after: raise ValueError('BUSINESS_ROWS_CHANGED_ACROSS_RESTART')
        result['business_rows_unchanged'] = True
        result['business_digest'] = before['sha256']; result['business_tables'] = len(before['tables'])
        action('quiesce')
        backup = action('backup'); result['final_archive'] = verify_archive(Path(backup['private_backup']), ROOT)
        action('stop')
        window.port_available(config['port']); window.port_available(config['db_port'])
        result['passed'] = True; result['own_ports_unbound'] = True
    except Exception as error:
        result['failure_type'] = type(error).__name__
        (output / 'failure-private.txt').write_text(str(error))
        # Exact own lifecycle guards still apply. Preserve data and failed archive for diagnosis.
        try: action('stop')
        except Exception: result['cleanup_requires_06_review'] = True
    finally:
        (output / 'result-private.json').write_text(json.dumps(result, indent=2))
        print(json.dumps({'candidate': candidate, 'passed': result['passed'], 'steps': result['steps'],
                          'business_rows_unchanged': result.get('business_rows_unchanged', False),
                          'restoration_executed': False, 'cleanup_requires_06_review': result.get('cleanup_requires_06_review', False)}))
    return 0 if result['passed'] else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan', action='store_true')
    parser.add_argument('--run', action='store_true')
    parser.add_argument('--candidate')
    args = parser.parse_args()
    if args.plan and not args.run:
        print(json.dumps({'steps': STEPS, 'runtime_started': False, 'restoration_executed': False})); return 0
    if not args.run or args.plan or not args.candidate: parser.error('Use --plan or --run --candidate FINAL_40_SHA')
    return run(args.candidate)


if __name__ == '__main__':
    try: raise SystemExit(main())
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        raise SystemExit('06 C rehearsal refused/failed; private receipts only. No other window selected.') from None
