#!/usr/bin/env python3
"""Offline C-window admission check. Never creates env/DB, starts services, or sends HTTP."""
import argparse
from datetime import datetime, timezone, timedelta
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys

# 00 assigned this new workspace; neither old 06 nor old AN-M01 may become C by changing flags.
WORKSPACE_NAME = 'h5-c-ui-manual'
PROTECTED_PORTS = {8206, 8303, 5606, 8377, 5877, 8406, 5906, 8407, 8408, 8409, 8410, 5910}
REQUIRED = (
    'docs/contracts/first-party-measurement-c-v1.md',
    'docs/contracts/measurement-c-ui-1.md',
    'h5-platform/resources/views/audience/partials/measurement-c-notice.blade.php',
    'h5-platform/resources/views/audience/partials/measurement-c.blade.php',
)
SOURCE = ('h5-platform', 'scripts/dev.py', 'scripts/ops', 'docs/contracts')
SHA = re.compile(r'[a-f0-9]{40}\Z')
HASH = re.compile(r'[a-f0-9]{64}\Z')


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args], text=True, stderr=subprocess.DEVNULL).strip()


def root_guard(root):
    # Refuse symlink spelling too; manifest/root hashes must describe the real checkout.
    if not root.is_absolute() or root != root.resolve() or root.name != 'H5' or root.parent.name != WORKSPACE_NAME:
        raise ValueError('NEW_C_WORKSPACE_REQUIRED')
    if not (root / '.git').exists():
        raise ValueError('GIT_WORKTREE_REQUIRED')
    return root


def safe_source(root, value):
    if not isinstance(value, str):
        raise ValueError('SOURCE_PATH_REQUIRED')
    rel = PurePosixPath(value)
    if rel.is_absolute() or '..' in rel.parts or not value or '\\' in value or str(rel) != value:
        raise ValueError('UNSAFE_SOURCE_PATH')
    path = root / value
    if path.resolve() != path or not path.is_file():
        raise ValueError('REGULAR_SOURCE_REQUIRED')
    if not (value.startswith('h5-platform/resources/') or value.startswith('h5-platform/public/') or value in REQUIRED):
        raise ValueError('REVIEWED_UI_SOURCE_REQUIRED')
    git(root, 'ls-files', '--error-unmatch', '--', value)
    return path


def source_status(root, candidate):
    root_guard(root)
    if not isinstance(candidate, str) or not SHA.fullmatch(candidate):
        raise ValueError('FULL_CANDIDATE_REQUIRED')
    if git(root, 'rev-parse', 'HEAD') != candidate:
        raise ValueError('CANDIDATE_MISMATCH')
    if git(root, 'status', '--porcelain', '--', *SOURCE):
        raise ValueError('COMMITTED_SOURCE_REQUIRED')
    missing = [name for name in REQUIRED if not (root / name).is_file()]
    hashes = {}
    for name in REQUIRED:
        if name not in missing:
            hashes[name] = hashlib.sha256(safe_source(root, name).read_bytes()).hexdigest()
    return {'candidate': candidate, 'missing': missing, 'required_sha256': hashes}


def workspace_binding(root, config):
    root_guard(root)
    suffix = hashlib.sha256(str(root).encode()).hexdigest()[:10]
    expected = {'root': str(root), 'workspace': 'h5_' + suffix, 'db': 'h5_' + suffix, 'user': 'h5_app_' + suffix}
    if any(config.get(k) != v for k, v in expected.items()):
        raise ValueError('WORKSPACE_BINDING_MISMATCH')
    ports = [config.get(k) for k in ('port', 'db_port')]
    if any(type(p) is not int or not 1024 <= p <= 65535 or p in PROTECTED_PORTS for p in ports) or ports[0] == ports[1]:
        raise ValueError('ISOLATED_PORTS_REQUIRED')
    return config


def private_document(path, root):
    directory = root / '.runtime/06/c-window'
    if not path.is_relative_to(directory) or path.resolve() != path:
        raise ValueError('OWN_PRIVATE_MANIFEST_REQUIRED')
    for parent in (root / '.runtime', root / '.runtime/06', directory):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise ValueError('PRIVATE_DIRECTORY_REQUIRED')
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_nlink != 1 or info.st_size > 131072:
        raise ValueError('PRIVATE_FILE_REQUIRED')
    data = json.loads(path.read_text())
    if not isinstance(data, dict):
        raise ValueError('MANIFEST_OBJECT_REQUIRED')
    return data


def deadline(value, now=None):
    if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', value):
        raise ValueError('UTC_POLICY_DEADLINE_REQUIRED')
    end = datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
    now = now or datetime.now(timezone.utc)
    # Admission only. A running page must remain usable after expiry, with collection off.
    if not now < end <= now + timedelta(hours=24):
        raise ValueError('FUTURE_POLICY_WINDOW_MAX_24H_REQUIRED')
    return end


def validate_manifest(root, candidate, doc, now=None, future_policy=True):
    source = source_status(root, candidate)
    if source['missing']:
        raise ValueError('FINAL_UI_NOT_IN_CANDIDATE')
    if doc.get('schema') != '06-c-window-1' or doc.get('purpose') != 'local-synthetic-c-ui-only' or doc.get('candidate') != candidate:
        raise ValueError('MANIFEST_CANDIDATE_REQUIRED')
    workspace_binding(root, doc.get('workspace', {}))
    if doc.get('contract') != 'c-ui-1' or doc.get('core_contract') != 'c-core-1':
        raise ValueError('CONTRACT_MISMATCH')
    receipt = doc.get('00_receipt', {})
    if not isinstance(receipt, dict) or receipt.get('final_combination_fixed') is not True or not isinstance(receipt.get('reference'), str) or not receipt['reference'].strip():
        raise ValueError('00_FIXED_COMBINATION_RECEIPT_REQUIRED')
    for key in ('04_ui_commit', '05_compat_commit'):
        commit = receipt.get(key)
        if not isinstance(commit, str) or not SHA.fullmatch(commit):
            raise ValueError('04_05_DELIVERY_COMMITS_REQUIRED')
        subprocess.run(['git', '-C', str(root), 'merge-base', '--is-ancestor', commit, candidate], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    flags = {'experience': True, 'synthetic_policy': True, 'paypal_api': False, 'purchase': False,
             'live': False, 'advertising_external': False, 'queue': False, 'scheduler': False, 'public': False,
             'registration_writes': False, 'payment_fixture': False}
    if doc.get('flags') != flags or any(type(doc['flags'][k]) is not bool for k in flags):
        raise ValueError('C_WINDOW_FLAGS_REQUIRED')
    if type(doc.get('measurement_enabled')) is not bool:
        raise ValueError('MEASUREMENT_VARIANT_BOOLEAN_REQUIRED')
    if doc.get('country_use') != 'US_VIEWING_FIXTURE_ONLY_NOT_RESIDENCY':
        raise ValueError('COUNTRY_PURPOSE_REQUIRED')
    policy_now = now
    if not future_policy:
        value = doc.get('synthetic_until')
        if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', value):
            raise ValueError('UTC_POLICY_DEADLINE_REQUIRED')
        policy_now = datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc) - timedelta(seconds=1)
    deadline(doc.get('synthetic_until'), policy_now)
    reviewed = doc.get('reviewed_ui_sha256')
    if not isinstance(reviewed, dict) or not set(REQUIRED).issubset(reviewed):
        raise ValueError('REVIEWED_SOURCE_HASHES_REQUIRED')
    if not any(isinstance(name, str) and name.endswith('.js') for name in reviewed):
        raise ValueError('ACTUAL_C_UI_SCRIPT_REQUIRED')
    for name, digest in reviewed.items():
        if not isinstance(digest, str) or not HASH.fullmatch(digest) or hashlib.sha256(safe_source(root, name).read_bytes()).hexdigest() != digest:
            raise ValueError('REVIEWED_SOURCE_CHANGED')
    return source


def template(root, candidate):
    source = source_status(root, candidate)
    suffix = hashlib.sha256(str(root).encode()).hexdigest()[:10]
    return {'schema': '06-c-window-1', 'purpose': 'local-synthetic-c-ui-only', 'candidate': candidate,
            'contract': 'c-ui-1', 'core_contract': 'c-core-1',
            'workspace': {'root': str(root), 'workspace': 'h5_' + suffix, 'db': 'h5_' + suffix,
                          'user': 'h5_app_' + suffix, 'port': 8411, 'db_port': 5911},
            '00_receipt': {'final_combination_fixed': False, 'reference': '', '04_ui_commit': '', '05_compat_commit': ''},
            'synthetic_until': '', 'measurement_enabled': True, 'country_use': 'US_VIEWING_FIXTURE_ONLY_NOT_RESIDENCY',
            'flags': {'experience': True, 'synthetic_policy': True, 'paypal_api': False, 'purchase': False,
                      'live': False, 'advertising_external': False, 'queue': False, 'scheduler': False, 'public': False,
                      'registration_writes': False, 'payment_fixture': False},
            'reviewed_ui_sha256': source['required_sha256']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['inspect', 'template', 'verify'])
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--manifest', type=Path)
    args = parser.parse_args()
    source = source_status(args.root, args.candidate)
    if args.action == 'template':
        print(json.dumps(template(args.root, args.candidate), indent=2)); return 0
    if args.action == 'verify':
        if not args.manifest:
            raise ValueError('PRIVATE_MANIFEST_REQUIRED')
        doc = private_document(args.manifest, args.root)
        validate_manifest(args.root, args.candidate, doc)
        # Existing setup must match; this tool never creates or changes setup.
        path = args.root / '.runtime/workspace.json'
        if path.exists():
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_nlink != 1:
                raise ValueError('PRIVATE_WORKSPACE_FILE_REQUIRED')
            config = json.loads(path.read_text())
            workspace_binding(args.root, config)
            if config != doc['workspace']:
                raise ValueError('ACTUAL_SETUP_MANIFEST_MISMATCH')
        print(json.dumps({'candidate': args.candidate, 'manifest_sha256': hashlib.sha256(args.manifest.read_bytes()).hexdigest(),
                          'offline_admission': 'passed', 'runtime_started': False, 'browser_executed': False,
                          'deployment_authorized_by_this_tool': False})); return 0
    print(json.dumps({**source, 'status': 'not_ready' if source['missing'] else 'await_00_manifest',
                      'runtime_started': False, 'database_accessed': False, 'browser_executed': False,
                      'note': 'Source presence does not prove v1/C compatibility or runtime acceptance.'}))
    return 77 if source['missing'] else 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, TypeError, subprocess.SubprocessError) as exc:
        reason = str(exc) if isinstance(exc, ValueError) and re.fullmatch('[A-Z0-9_]+', str(exc)) else 'C_WINDOW_OFFLINE_ADMISSION_FAILED'
        print(json.dumps({'status': 'refused', 'reason': reason, 'runtime_started': False}), file=sys.stderr)
        raise SystemExit(78) from None
