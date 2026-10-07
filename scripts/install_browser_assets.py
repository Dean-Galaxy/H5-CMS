#!/usr/bin/env python3
"""Install the locked browser bundle without Node, build scripts or runtime CDN."""
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import tempfile
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
MAX_ARCHIVE_BYTES = 20_000_000


def load_lock(root):
    lock = json.loads((root / 'scripts/browser-assets.lock.json').read_text())
    version = lock['version']
    if lock['schema_version'] != 1 or lock['name'] != 'hls.js' or not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise ValueError('不支持的浏览器依赖锁文件')
    if lock['tarball'] != f'https://registry.npmjs.org/hls.js/-/hls.js-{version}.tgz':
        raise ValueError('依赖地址必须是锁定版本的官方 npm 包')
    if lock['output_directory'] != f'h5-platform/public/vendor/hls.js/{version}':
        raise ValueError('浏览器依赖输出目录不符')
    expected = {'package/dist/hls.min.js': 'hls.min.js', 'package/LICENSE': 'LICENSE'}
    if len(lock['files']) != 2 or {f['archive_path']: f['output'] for f in lock['files']} != expected:
        raise ValueError('浏览器依赖文件清单不符')
    for entry in lock['files']:
        if not re.fullmatch(r'[a-f0-9]{64}', entry['sha256']) or not 0 < entry['bytes'] < 2_000_000:
            raise ValueError('浏览器依赖摘要或文件大小不符')
    return lock


def local_directory(root, relative, create=True):
    """Never follow a link into another worktree's cache or static assets."""
    part = root
    for name in PurePosixPath(relative).parts:
        if name in ('..', '/'):
            raise ValueError('不允许越过当前工作区')
        part = part / name
        if part.is_symlink():
            raise ValueError('浏览器依赖目录不能链接到其他位置')
    if create:
        part.mkdir(parents=True, exist_ok=True)
    return part


def valid_file(path, entry):
    return (not path.is_symlink() and path.is_file()
            and path.stat().st_size == entry['bytes']
            and hashlib.sha256(path.read_bytes()).hexdigest() == entry['sha256'])


def verified_contents(data, lock):
    if len(data) > MAX_ARCHIVE_BYTES:
        raise ValueError('依赖包超过大小限制')
    integrity = 'sha512-' + base64.b64encode(hashlib.sha512(data).digest()).decode()
    if integrity != lock['integrity']:
        raise ValueError('依赖包摘要不符，未安装')
    contents = {}
    with tarfile.open(fileobj=io.BytesIO(data), mode='r:gz') as archive:
        for entry in lock['files']:
            matches = [m for m in archive.getmembers() if m.name == entry['archive_path']]
            if len(matches) != 1 or not matches[0].isfile() or matches[0].size != entry['bytes']:
                raise ValueError('依赖文件重复、类型或大小不符')
            # Read only the named regular files into memory; never extract archive paths.
            content = archive.extractfile(matches[0]).read(entry['bytes'] + 1)
            if len(content) != entry['bytes'] or hashlib.sha256(content).hexdigest() != entry['sha256']:
                raise ValueError('依赖文件摘要不符，未安装')
            contents[entry['output']] = content
    return contents


def atomic_write(path, content):
    fd, temporary = tempfile.mkstemp(prefix='.browser-asset-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
        os.chmod(temporary, 0o644)
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def download(url, target):
    env = os.environ.copy()
    for scheme, proxy in urllib.request.getproxies().items():
        if scheme in ('http', 'https'):
            env[scheme + '_proxy'] = proxy
    subprocess.run(['curl', '--fail', '--silent', '--show-error', '--proto', '=https',
                    '--connect-timeout', '15', '--max-time', '60', '--max-filesize', str(MAX_ARCHIVE_BYTES),
                    url, '-o', str(target)], env=env, check=True)


def install(root=ROOT, check=False, downloader=download):
    root = root.resolve()
    lock = load_lock(root)
    output = local_directory(root, lock['output_directory'], create=not check)
    ready = all(valid_file(output / entry['output'], entry) for entry in lock['files'])
    if ready:
        return {'name': lock['name'], 'version': lock['version'], 'status': 'verified', 'network_used': False}
    if check:
        raise ValueError('浏览器依赖缺失或摘要不符，请先运行 python3 scripts/install_browser_assets.py')
    cache = local_directory(root, '.runtime/browser-assets')
    archive = cache / f"hls.js-{lock['version']}.tgz"
    network_used = not archive.is_file()
    if archive.is_symlink():
        raise ValueError('依赖缓存不能使用符号链接')
    if network_used:
        fd, temporary = tempfile.mkstemp(prefix='download-', suffix='.tgz', dir=cache)
        os.close(fd)
        try:
            downloader(lock['tarball'], Path(temporary))
            contents = verified_contents(Path(temporary).read_bytes(), lock)
            os.replace(temporary, archive)
        finally:
            Path(temporary).unlink(missing_ok=True)
    else:
        contents = verified_contents(archive.read_bytes(), lock)
    # Verify all files before replacing any output. A failed download preserves old files.
    for name, content in contents.items():
        atomic_write(output / name, content)
    return {'name': lock['name'], 'version': lock['version'], 'status': 'installed', 'network_used': network_used}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='只检查已安装文件，不联网下载')
    args = parser.parse_args()
    try:
        print(json.dumps(install(check=args.check), ensure_ascii=False))
    except (OSError, ValueError, KeyError, tarfile.TarError, subprocess.CalledProcessError) as exc:
        raise SystemExit(str(exc)) from exc


if __name__ == '__main__':
    main()
