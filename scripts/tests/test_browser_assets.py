"""Installer boundary tests use tiny synthetic packages, not playback claims."""
import base64
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'install_browser_assets.py'
spec = importlib.util.spec_from_file_location('browser_assets', SCRIPT)
assets = importlib.util.module_from_spec(spec)
spec.loader.exec_module(assets)


class BrowserAssetsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.files = {'package/dist/hls.min.js': b'/* synthetic test bundle */',
                      'package/LICENSE': b'synthetic test license'}
        self.data = self.package()
        self.lock = {
            'schema_version': 1, 'name': 'hls.js', 'version': '1.7.3',
            'tarball': 'https://registry.npmjs.org/hls.js/-/hls.js-1.7.3.tgz',
            'integrity': self.integrity(self.data),
            'output_directory': 'h5-platform/public/vendor/hls.js/1.7.3',
            'files': [{'archive_path': name, 'output': name.split('/')[-1],
                       'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
                      for name, data in self.files.items()],
        }
        (self.root / 'scripts').mkdir()
        self.save_lock()
        self.output = self.root / self.lock['output_directory']
        self.cache = self.root / '.runtime/browser-assets/hls.js-1.7.3.tgz'
        self.downloads = 0

    @staticmethod
    def integrity(data):
        return 'sha512-' + base64.b64encode(hashlib.sha512(data).digest()).decode()

    def save_lock(self):
        (self.root / 'scripts/browser-assets.lock.json').write_text(json.dumps(self.lock))

    def package(self, symlink=False):
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w:gz') as archive:
            for name, data in self.files.items():
                entry = tarfile.TarInfo(name)
                entry.size = len(data)
                if symlink and name.endswith('hls.min.js'):
                    entry.type = tarfile.SYMTYPE
                    entry.linkname = '/outside/workspace'
                    entry.size = 0
                    archive.addfile(entry)
                else:
                    archive.addfile(entry, io.BytesIO(data))
        return stream.getvalue()

    def download(self, url, destination):
        self.assertEqual(url, self.lock['tarball'])
        self.downloads += 1
        destination.write_bytes(self.data)

    def test_install_and_offline_repeat_keep_bundle_and_license(self):
        result = assets.install(self.root, downloader=self.download)
        self.assertTrue(result['network_used'])
        for entry in self.lock['files']:
            self.assertEqual((self.output / entry['output']).read_bytes(), self.files[entry['archive_path']])
        self.assertEqual(assets.install(self.root, downloader=self.download)['status'], 'verified')
        self.assertFalse(assets.install(self.root, check=True)['network_used'])
        self.assertEqual(self.downloads, 1)

    def test_check_missing_is_read_only_and_never_downloads(self):
        with self.assertRaisesRegex(ValueError, '缺失'):
            assets.install(self.root, check=True, downloader=self.download)
        self.assertFalse((self.root / 'h5-platform').exists())
        self.assertEqual(self.downloads, 0)

    def test_bad_download_keeps_previous_output_and_does_not_cache(self):
        self.output.mkdir(parents=True)
        previous = self.output / 'hls.min.js'
        previous.write_bytes(b'previous bundle')
        self.data = b'tampered archive'
        with self.assertRaisesRegex(ValueError, '包摘要'):
            assets.install(self.root, downloader=self.download)
        self.assertEqual(previous.read_bytes(), b'previous bundle')
        self.assertFalse(self.cache.exists())
        self.assertFalse((self.output / 'LICENSE').exists())

    def test_corrupt_cache_stops_without_download_or_overwrite(self):
        self.cache.parent.mkdir(parents=True)
        self.cache.write_bytes(b'corrupt cached archive')
        with self.assertRaisesRegex(ValueError, '包摘要'):
            assets.install(self.root, downloader=self.download)
        self.assertEqual(self.downloads, 0)
        self.assertFalse((self.output / 'hls.min.js').exists())

    def test_file_digest_rejects_even_if_archive_digest_matches(self):
        self.lock['files'][0]['sha256'] = '0' * 64
        self.save_lock()
        with self.assertRaisesRegex(ValueError, '文件摘要'):
            assets.install(self.root, downloader=self.download)
        self.assertFalse((self.output / 'hls.min.js').exists())
        self.assertFalse((self.output / 'LICENSE').exists())

    def test_symlink_archive_member_is_not_extracted(self):
        self.data = self.package(symlink=True)
        self.lock['integrity'] = self.integrity(self.data)
        self.save_lock()
        with self.assertRaisesRegex(ValueError, '类型或大小'):
            assets.install(self.root, downloader=self.download)
        self.assertFalse((self.output / 'hls.min.js').exists())

    def test_other_workspace_asset_directory_is_not_followed(self):
        other = self.root / 'other-workspace'
        other.mkdir()
        public = self.root / 'h5-platform/public'
        public.mkdir(parents=True)
        (public / 'vendor').symlink_to(other, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, '不能链接'):
            assets.install(self.root, downloader=self.download)
        self.assertEqual(list(other.iterdir()), [])

    def test_check_detects_tampering_then_explicit_install_repairs_from_cache(self):
        assets.install(self.root, downloader=self.download)
        (self.output / 'hls.min.js').write_bytes(b'tampered output')
        with self.assertRaisesRegex(ValueError, '缺失或摘要'):
            assets.install(self.root, check=True, downloader=self.download)
        self.assertFalse(assets.install(self.root, downloader=self.download)['network_used'])
        self.assertEqual(self.downloads, 1)
        self.assertEqual((self.output / 'hls.min.js').read_bytes(), self.files['package/dist/hls.min.js'])

    def test_unapproved_download_host_rejected_before_network(self):
        self.lock['tarball'] = 'https://example.invalid/hls.js-1.7.3.tgz'
        self.save_lock()
        with self.assertRaisesRegex(ValueError, '官方 npm'):
            assets.install(self.root, downloader=self.download)
        self.assertEqual(self.downloads, 0)


if __name__ == '__main__':
    unittest.main()
