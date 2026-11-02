"""Only preparation tests; never import/execute the host payload or any unit command."""
import ast
import contextlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

OPS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(OPS))
import prepare_media_native_probe as p


class MediaProbePreparationTests(unittest.TestCase):
    def test_review_default_and_source_drift_rejection(self):
        with patch.object(p.Path, 'read_bytes', side_effect=AssertionError('Default reads no source')), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(0, p.main([]))
        self.assertFalse(json.loads(output.getvalue())['executed'])
        source = {n: (p.REPO/'deploy'/n).read_bytes() for n in p.SOURCES}
        source['h5-media-process.service'] += b'\n'
        with self.assertRaisesRegex(ValueError, 'SOURCE_SHA_MISMATCH'):
            p.prepare(source)

    def test_generated_scope_hashes_and_retained_sandbox(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)/'review'
            files = p.generate(directory)
            manifest = json.loads(files['manifest.json'])
            self.assertEqual(3, len(manifest['source_sha256']))
            self.assertEqual(4, manifest['groups'])
            self.assertEqual(5, manifest['planned_cli_entries'])
            self.assertEqual(12, manifest['enforced_cli_limit'])
            for name, digest in manifest['files_sha256'].items():
                self.assertEqual(digest, p.sha((directory/name).read_bytes()))
            for line in files['SHA256SUMS'].splitlines():
                digest, name = line.split('  ', 1)
                self.assertEqual(digest, p.sha((directory/name).read_bytes()))
            for name in [p.NAME+'.service', p.NAME+'.timer', p.FAILURE]:
                text = files[name]
                for forbidden in ['/srv/h5/current', '/srv/h5/shared', '/srv/h5/ops/media-process',
                                  'postgresql@', 'php8.4', 'artisan', '[Install]', 'WantedBy=']:
                    self.assertNotIn(forbidden, text)
            service = files[p.NAME+'.service']
            for retained in ['User=h5', 'ProtectSystem=strict', 'NoNewPrivileges=yes', 'CapabilityBoundingSet=\n',
                             'KillMode=control-group', 'ExecStartPre=!/usr/bin/touch',
                             'ExecStartPost=!/usr/bin/rm', 'TimeoutStartSec=8s', 'TimeoutStopSec=2s']:
                self.assertIn(retained, service)
            self.assertIn('MemoryMax=96M', service)
            self.assertIn('MemoryMax=64M', files[p.FAILURE])
            self.assertIn('OnActiveSec=3s', files[p.NAME+'.timer'])
            self.assertIn('stop '+p.NAME+'.timer', files[p.FAILURE])
            ast.parse(files['probe.py'])
            self.assertEqual(0o700, directory.stat().st_mode & 0o777)
            with self.assertRaises(FileExistsError):
                p.generate(directory)

    def test_only_one_deterministic_sync_negative_and_no_execute_option(self):
        files = p.prepare()
        service = files[p.NAME+'.service']
        baseline = [l for l in service.splitlines() if l.startswith('ExecStartPre=')]
        negative = files['sync-negative.conf'].splitlines()[2:]
        self.assertEqual(len(baseline), len(negative))
        changes = [(a, b) for a, b in zip(baseline, negative) if a != b]
        self.assertEqual([('ExecStartPre=!/usr/bin/sync -- '+p.CONTROL+'/RUNNING '+p.CONTROL,
                           'ExecStartPre=!/usr/bin/sync -- '+p.CONTROL+'/ABSENT '+p.CONTROL)], changes)
        source = (OPS/'prepare_media_native_probe.py').read_text()
        self.assertNotIn('import subprocess', source)
        self.assertNotIn('os.system(', source)
        self.assertNotIn("add_argument('--run'", source)
        self.assertNotIn("add_argument('--execute'", source)


if __name__ == '__main__':
    unittest.main()
