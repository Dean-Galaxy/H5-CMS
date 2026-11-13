#!/usr/bin/env python3
"""Operator-only content copy. No Laravel boot, credentials, network or workers."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import subprocess
import sys

TABLES = ('content_settings', 'content_assets', 'content_categories', 'content_dramas',
          'content_drama_categories', 'content_episodes', 'content_plans',
          'content_publications', 'content_banners')
KEYS = {t: ('id',) for t in TABLES}
KEYS['content_drama_categories'] = ('drama_id', 'category_id')
FORMAT = 'content-copy-1'
APPLICATION = 'a5f7691a680b71e0a03aedfa35b18a67d1b0628d'


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)


def digest(value):
    return hashlib.sha256(canonical(value).encode()).hexdigest()


def literal(value):
    # Hex text prevents content from terminating a DO dollar-quoted guard body.
    return "convert_from(decode('" + value.encode('utf8').hex() + "','hex'),'UTF8')"


def query(service, sql):
    # libpq service selected by operator. Never accept passwords/URLs or print stderr.
    if not service.replace('_', '').replace('-', '').isalnum():
        raise ValueError('invalid service name')
    result = subprocess.run(['psql', '-X', '--no-password', '-qAt', '-v', 'ON_ERROR_STOP=1',
                             'service=' + service], input=sql, text=True, capture_output=True)
    if result.returncode:
        raise ValueError('PSQL_FAILED (private server diagnostics deliberately omitted)')
    return result.stdout.strip()


def read_db(service):
    parts = []
    for table in TABLES:
        parts.extend([literal(table), f"(SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY {','.join(KEYS[table])}), '[]'::jsonb) FROM {table} r)"])
    columns = "SELECT table_name, jsonb_agg(column_name ORDER BY ordinal_position) AS names FROM information_schema.columns WHERE table_schema='public' AND table_name IN (" + ','.join(map(literal, TABLES)) + ') GROUP BY table_name'
    sql = "BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SELECT jsonb_build_object('tables',jsonb_build_object(" + ','.join(parts) + "),'columns',(SELECT jsonb_object_agg(table_name,names) FROM (" + columns + ") c)); COMMIT;"
    return json.loads(query(service, sql))


def select_scope(db, drama_ids):
    tables = db['tables']
    selected = {t: [] for t in TABLES}
    selected['content_dramas'] = [r for r in tables['content_dramas'] if r['id'] in drama_ids]
    if {r['id'] for r in selected['content_dramas']} != drama_ids:
        raise ValueError('missing selected drama')
    for t in ('content_episodes', 'content_plans', 'content_publications', 'content_banners', 'content_drama_categories'):
        selected[t] = [r for r in tables[t] if r['drama_id'] in drama_ids]
    category_ids = {r['category_id'] for r in selected['content_drama_categories']}
    selected['content_categories'] = [r for r in tables['content_categories'] if r['id'] in category_ids]
    asset_ids = set()
    for t, fields in (('content_dramas', ('cover_asset_id',)), ('content_episodes', ('video_asset_id', 'subtitle_asset_id')), ('content_banners', ('image_asset_id',))):
        for row in selected[t]:
            asset_ids.update(row[f] for f in fields if row[f])
    selected['content_assets'] = [r for r in tables['content_assets'] if r['id'] in asset_ids]
    if {r['id'] for r in selected['content_assets']} != asset_ids:
        raise ValueError('missing referenced asset')
    if any(r['state'] != 'ready' for r in selected['content_assets']):
        raise ValueError('referenced asset not ready; no queue consumption permitted')
    selected['content_settings'] = tables['content_settings']
    if len(selected['content_settings']) != 1 or selected['content_settings'][0]['id'] != 1 or selected['content_settings'][0]['default_free_count'] != 5:
        raise ValueError('expected singleton default free count 5')
    return {'tables': selected, 'columns': db['columns']}


def safe_path(root, key):
    if not key or '\\' in key or any(ord(c) < 32 for c in key):
        raise ValueError('unsafe media key')
    p = PurePosixPath(key)
    if p.is_absolute() or str(p) != key or '..' in p.parts:
        raise ValueError('unsafe media key')
    current = root
    if current.is_symlink() or not current.is_dir():
        raise ValueError('media root must be a real directory')
    for part in p.parts:
        current = current / part
        if current.is_symlink():
            raise ValueError('symlink rejected')
    return current


def file_info(path):
    with path.open('rb') as stream:
        before = os.fstat(stream.fileno())
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
            raise ValueError('only independent regular files allowed')
        h = hashlib.file_digest(stream, 'sha256').hexdigest()
        after = os.fstat(stream.fileno())
        if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
            raise ValueError('source changed during hash')
    if before.st_size == 0:
        raise ValueError('empty media file')
    return {'bytes': before.st_size, 'sha256': h}


def manifest(root, rows):
    files = {}
    for row in rows:
        prefix = 'assets/' + row['id'] + '/'
        if not row['source_key'].startswith(prefix) or not row['output_key'].startswith(prefix):
            raise ValueError('asset directory ownership mismatch')
        source = safe_path(root, row['source_key'])
        files[row['source_key']] = file_info(source)
        if files[row['source_key']]['sha256'] != row['sha256']:
            raise ValueError('asset source sha256 mismatch')
        output = safe_path(root, row['output_key'])
        if not output.is_dir():
            raise ValueError('output missing')
        output_files = []
        for base, dirs, names in os.walk(output, followlinks=False):
            for name in dirs + names:
                if (Path(base) / name).is_symlink():
                    raise ValueError('output symlink rejected')
            for name in names:
                key = (Path(base) / name).relative_to(root).as_posix()
                files[key] = file_info(safe_path(root, key))
                output_files.append(key)
        if not output_files:
            raise ValueError('empty output directory')
    return dict(sorted(files.items()))


def validate(package):
    if package['format'] != FORMAT or package['application'] != APPLICATION:
        raise ValueError('unsupported scope/application')
    selected = select_scope(package['db'], set(package['drama_ids']))
    if selected != package['db'] or set(package['db']['tables']) != set(TABLES) or set(package['db']['columns']) != set(TABLES):
        raise ValueError('package exceeds selected content scope')
    for t in TABLES:
        if any(set(r) != set(package['db']['columns'][t]) for r in package['db']['tables'][t]):
            raise ValueError('column mismatch')
    expected = package.copy(); expected.pop('digest', None)
    if digest(expected) != package['digest']:
        raise ValueError('package digest mismatch')


def merge_rows(package, target):
    if package['db']['columns'] != target['columns']:
        raise ValueError('target schema mismatch')
    additions = {t: [] for t in TABLES}
    for t in TABLES:
        old = {tuple(r[k] for k in KEYS[t]): r for r in target['tables'][t]}
        # Exclude content operations/session/grants by construction; existing business rows never updated.
        for row in package['db']['tables'][t]:
            key = tuple(row[k] for k in KEYS[t])
            if key in old and old[key] != row:
                if t == 'content_settings' and old[key] == {'id': 1, 'default_free_count': 5, 'version': 1, 'catalog_version': 1} and all(not target['tables'][other] for other in TABLES if other != t):
                    additions[t].append(row)
                else:
                    raise ValueError('target conflict in ' + t)
            elif key not in old:
                additions[t].append(row)
    return additions


def apply_sql(package, target, additions):
    sql = ['BEGIN;', 'SET LOCAL lock_timeout = \'5s\';', 'LOCK TABLE ' + ','.join(TABLES) + ' IN EXCLUSIVE MODE;', 'LOCK TABLE identity_staff IN SHARE MODE;']
    for t in TABLES:
        rows = target['tables'][t]
        sql.append('DO $guard$ BEGIN IF (SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY ' + ','.join(KEYS[t]) + "),'[]'::jsonb) FROM " + t + ' r) <> ' + literal(canonical(rows)) + "::jsonb THEN RAISE EXCEPTION 'TARGET_CHANGED'; END IF; END $guard$;")
    staff_ids = {r['created_by'] for t in ('content_assets', 'content_dramas') for r in package['db']['tables'][t]}
    for staff_id in sorted(staff_ids):
        sql.append("DO $guard$ BEGIN IF NOT EXISTS (SELECT 1 FROM identity_staff WHERE id=" + literal(staff_id) + " AND role='owner' AND active) THEN RAISE EXCEPTION 'OWNER_MISSING'; END IF; END $guard$;")
    for t in TABLES:
        if not additions[t]:
            continue
        rows = literal(canonical(additions[t]))
        if t == 'content_settings' and target['tables'][t]:
            sql.append('UPDATE content_settings SET (default_free_count,version,catalog_version) = (SELECT default_free_count,version,catalog_version FROM jsonb_populate_recordset(NULL::content_settings,' + rows + '::jsonb)) WHERE id=1;')
        else:
            sql.append('INSERT INTO ' + t + ' SELECT * FROM jsonb_populate_recordset(NULL::' + t + ',' + rows + '::jsonb);')
    for t in TABLES:
        wanted = {tuple(r[k] for k in KEYS[t]): r for r in target['tables'][t]}
        wanted.update({tuple(r[k] for k in KEYS[t]): r for r in package['db']['tables'][t]})
        ordered = sorted(wanted.values(), key=lambda r: tuple(r[k] for k in KEYS[t]))
        sql.append('DO $guard$ BEGIN IF (SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY ' + ','.join(KEYS[t]) + "),'[]'::jsonb) FROM " + t + ' r) <> ' + literal(canonical(ordered)) + "::jsonb THEN RAISE EXCEPTION 'POST_MISMATCH'; END IF; END $guard$;")
    sql.append('COMMIT;')
    return '\n'.join(sql)


def save(path, value):
    with Path(path).open('x', encoding='utf8') as f:
        os.chmod(path, 0o600)
        json.dump(value, f, ensure_ascii=False, sort_keys=True)


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='action', required=True)
    export = sub.add_parser('export')
    export.add_argument('--source-service', required=True)
    export.add_argument('--source-database', required=True)
    export.add_argument('--source-media', type=Path, required=True)
    export.add_argument('--drama', action='append', required=True)
    export.add_argument('--package', required=True)
    copy = sub.add_parser('copy')
    copy.add_argument('--package', required=True)
    copy.add_argument('--source-media', type=Path, required=True)
    copy.add_argument('--target-media', type=Path, required=True)
    copy.add_argument('--target-service', required=True)
    copy.add_argument('--target-database', required=True)
    copy.add_argument('--target-uid', type=int, required=True)
    copy.add_argument('--target-gid', type=int, required=True)
    copy.add_argument('--plan', required=True)
    copy.add_argument('--apply', action='store_true')
    copy.add_argument('--confirm-digest')
    args = parser.parse_args()
    if args.action == 'export':
        if query(args.source_service, 'SELECT current_database();') != args.source_database:
            raise ValueError('source database identity mismatch')
        db = select_scope(read_db(args.source_service), set(args.drama))
        package = {'format': FORMAT, 'application': APPLICATION, 'drama_ids': sorted(set(args.drama)), 'source_database': args.source_database, 'db': db,
                   'files': manifest(args.source_media, db['tables']['content_assets'])}
        if select_scope(read_db(args.source_service), set(args.drama)) != db:
            raise ValueError('source records changed during export')
        package['digest'] = digest(package)
        save(args.package, package)
        print(canonical({'digest': package['digest'], 'counts': {t: len(db['tables'][t]) for t in TABLES},
                         'file_count': len(package['files']), 'total_bytes': sum(f['bytes'] for f in package['files'].values())}))
        return
    package = json.loads(Path(args.package).read_text()); validate(package)
    src_root = args.source_media.resolve(); dst_root = args.target_media.resolve()
    if src_root.is_relative_to(dst_root) or dst_root.is_relative_to(src_root):
        raise ValueError('separate non-overlapping media roots required')
    if (args.target_media.stat().st_uid, args.target_media.stat().st_gid) != (args.target_uid, args.target_gid):
        raise ValueError('target root owner mismatch')
    if manifest(args.source_media, package['db']['tables']['content_assets']) != package['files']:
        raise ValueError('source manifest changed')
    if query(args.target_service, 'SELECT current_database();') != args.target_database or args.target_database == package.get('source_database'):
        raise ValueError('target database identity mismatch or source selected as target')
    target = read_db(args.target_service)
    for staff_id in sorted({r['created_by'] for t in ('content_assets', 'content_dramas') for r in package['db']['tables'][t]}):
        if query(args.target_service, "SELECT EXISTS(SELECT 1 FROM identity_staff WHERE id=" + literal(staff_id) + " AND role='owner' AND active);") != 't':
            raise ValueError('target active Owner missing; no identity import permitted')
    additions = merge_rows(package, target)
    missing_bytes = 0
    for key, info in package['files'].items():
        path = safe_path(args.target_media, key)
        if path.exists():
            if (path.stat().st_uid, path.stat().st_gid) != (args.target_uid, args.target_gid):
                raise ValueError('target file owner mismatch')
            if file_info(path) != info:
                raise ValueError('target file conflict')
        else:
            missing_bytes += info['bytes']
    if shutil.disk_usage(args.target_media).free < missing_bytes:
        raise ValueError('insufficient target disk space')
    plan = {'package_digest': package['digest'], 'target': target, 'target_media': str(args.target_media.resolve()),
            'target_service': args.target_service, 'target_database': args.target_database, 'target_uid': args.target_uid, 'target_gid': args.target_gid, 'insert_counts': {t: len(v) for t, v in additions.items()}, 'copy_bytes': missing_bytes}
    if not args.apply:
        save(args.plan, plan)
        print(canonical({'dry_run': True, 'digest': package['digest'], 'insert_counts': plan['insert_counts'], 'copy_bytes': missing_bytes}))
        return
    frozen = json.loads(Path(args.plan).read_text())
    if args.confirm_digest != package['digest'] or frozen != plan:
        raise ValueError('explicit digest/frozen target plan mismatch; create a new dry-run plan')
    for key, info in package['files'].items():
        source = safe_path(args.source_media, key); dest = safe_path(args.target_media, key)
        if dest.exists():
            continue
        current = args.target_media
        for part in PurePosixPath(key).parts[:-1]:
            current = current / part
            if not current.exists():
                current.mkdir(mode=0o700)
                os.chown(current, args.target_uid, args.target_gid)
            if current.is_symlink() or (current.stat().st_uid, current.stat().st_gid) != (args.target_uid, args.target_gid):
                raise ValueError('target directory owner/type mismatch')
        safe_path(args.target_media, key)
        try:
            with source.open('rb') as src, dest.open('xb') as out:
                shutil.copyfileobj(src, out, 1024 * 1024)
                os.fchown(out.fileno(), args.target_uid, args.target_gid)
            if file_info(dest) != info:
                raise ValueError('copied file verification failed')
        except BaseException:
            # Keep failed copy for operator diagnosis. Never overwrite/delete existing media.
            raise
    # Validate all files once more before introducing DB references.
    if manifest(args.target_media, package['db']['tables']['content_assets']) != package['files']:
        raise ValueError('target manifest mismatch')
    query(args.target_service, apply_sql(package, target, additions))
    actual = read_db(args.target_service)
    merge_rows(package, actual)  # no conflicting versions accepted after commit
    print(canonical({'applied': True, 'digest': package['digest'], 'counts': {t: len(actual['tables'][t]) for t in TABLES}}))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, TypeError) as e:
        print(type(e).__name__ + ': ' + str(e), file=sys.stderr)
        sys.exit(1)
