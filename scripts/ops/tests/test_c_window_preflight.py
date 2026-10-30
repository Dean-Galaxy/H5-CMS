"""Offline admission tests using temporary synthetic repositories; no application/PG/HTTP."""
from copy import deepcopy
from datetime import datetime, timezone, timedelta
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('c_window_preflight', Path(__file__).resolve().parents[1] / 'c_window_preflight.py')
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)


class Admission(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name).resolve() / 'h5-c-ui-manual/H5'
        self.root.mkdir(parents=True)
        self.command('init', '-q')
        self.command('config', 'user.name', 'Synthetic 06')
        self.command('config', 'user.email', '06@example.test')
        for name in (*m.REQUIRED, 'h5-platform/public/c-test.js'):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('synthetic offline fixture\n')
        self.command('add', '.')
        self.command('commit', '-qm', 'synthetic offline candidate')
        self.sha = self.command('rev-parse', 'HEAD')
        self.now = datetime(2026, 10, 30, 0, 0, 0, tzinfo=timezone.utc)
        self.doc = m.template(self.root, self.sha)
        self.doc['00_receipt'] = {'final_combination_fixed': True, 'reference': 'synthetic unit test, not real acceptance',
                                  '04_ui_commit': self.sha, '05_compat_commit': self.sha}
        self.doc['synthetic_until'] = '2026-10-30T01:00:00Z'
        name = 'h5-platform/public/c-test.js'
        self.doc['reviewed_ui_sha256'][name] = m.hashlib.sha256((self.root / name).read_bytes()).hexdigest()

    def command(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], stderr=subprocess.DEVNULL, text=True).strip()

    def verify(self, doc=None):
        return m.validate_manifest(self.root, self.sha, doc or self.doc, self.now)

    def test_exact_committed_candidate_with_explicit_receipts(self):
        self.assertEqual(self.verify()['candidate'], self.sha)

    def test_old_workspace_and_symlink_spelling_refused(self):
        with self.assertRaises(ValueError):
            m.root_guard(self.root.parent.parent / 'h5-06-privacy-manual/H5')
        alias = self.root.parent.parent / 'alias'
        alias.symlink_to(self.root.parent)
        with self.assertRaises(ValueError):
            m.root_guard(alias / 'H5')

    def test_all_protected_ports_and_boolean_port_refused(self):
        for port in (*m.PROTECTED_PORTS, True, 1, 65536):
            for field in ('port', 'db_port'):
                with self.subTest(port=port, field=field):
                    doc = deepcopy(self.doc)
                    doc['workspace'][field] = port
                    with self.assertRaises(ValueError):
                        self.verify(doc)

    def test_copied_workspace_or_database_refused(self):
        for field in ('root', 'db', 'user', 'workspace'):
            with self.subTest(field=field):
                doc = deepcopy(self.doc)
                doc['workspace'][field] = 'old-workspace'
                with self.assertRaises(ValueError):
                    self.verify(doc)

    def test_invalid_workspace_object_refused(self):
        for value in (None, [], 'copied-config', True):
            doc = deepcopy(self.doc)
            doc['workspace'] = value
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.verify(doc)

    def test_expired_policy_can_be_inspected_without_restart_permission(self):
        self.doc['synthetic_until'] = '2026-10-29T01:00:00Z'
        with self.assertRaises(ValueError): self.verify()
        self.assertEqual(m.validate_manifest(self.root, self.sha, self.doc, self.now, future_policy=False)['candidate'], self.sha)

    def test_partial_or_wrong_candidate_refused(self):
        for sha in (self.sha[:7], 'f' * 40):
            with self.subTest(sha=sha), self.assertRaises(ValueError):
                m.source_status(self.root, sha)

    def test_missing_ui_does_not_become_admitted(self):
        path = self.root / m.REQUIRED[-1]
        path.unlink()
        self.command('add', '-u')
        self.command('commit', '-qm', 'simulate partial combination')
        self.sha = self.command('rev-parse', 'HEAD')
        self.doc['candidate'] = self.sha
        with self.assertRaisesRegex(ValueError, 'FINAL_UI_NOT_IN_CANDIDATE'):
            self.verify()

    def test_uncommitted_business_and_ops_change_refused(self):
        for name in ('h5-platform/public/new.js', 'scripts/ops/foreign.py'):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('uncommitted')
            with self.assertRaisesRegex(ValueError, 'COMMITTED_SOURCE_REQUIRED'):
                self.verify()
            path.unlink()

    def test_missing_or_nonancestor_delivery_receipt_refused(self):
        for key in ('04_ui_commit', '05_compat_commit'):
            for value in ('', 'f' * 40):
                doc = deepcopy(self.doc)
                doc['00_receipt'][key] = value
                with self.subTest(key=key, value=value), self.assertRaises((ValueError, subprocess.SubprocessError)):
                    self.verify(doc)
        doc = deepcopy(self.doc)
        doc['00_receipt']['final_combination_fixed'] = False
        with self.assertRaises(ValueError):
            self.verify(doc)

    def test_deadline_expiry_unbounded_or_timezone_ambiguity_refused(self):
        for value in ('2026-10-29T23:59:59Z', '2026-10-30T00:00:00Z', '2026-10-31T00:00:01Z',
                      '2026-10-30T01:00:00', '2026-10-30T01:00:00+00:00', None):
            with self.subTest(value=value), self.assertRaises(ValueError):
                m.deadline(value, self.now)
        self.assertEqual(m.deadline('2026-10-31T00:00:00Z', self.now), self.now + timedelta(hours=24))

    def test_flags_strictly_false_not_strings_or_payment_fixture(self):
        for key in ('purchase', 'paypal_api', 'live', 'registration_writes', 'advertising_external', 'queue', 'scheduler', 'public', 'payment_fixture'):
            doc = deepcopy(self.doc)
            doc['flags'][key] = True
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.verify(doc)
        for value in ('true', 1):
            doc = deepcopy(self.doc)
            doc['flags']['experience'] = value
            with self.assertRaises(ValueError):
                self.verify(doc)

    def test_country_not_residency_and_manifest_candidate_mismatch(self):
        for key, value in (('country_use', 'all_us_eligible'), ('candidate', 'f' * 40), ('contract', 'analytics-v1')):
            doc = deepcopy(self.doc)
            doc[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.verify(doc)

    def test_source_hash_script_requirement_traversal_and_symlink(self):
        doc = deepcopy(self.doc)
        doc['reviewed_ui_sha256']['h5-platform/public/c-test.js'] = '0' * 64
        with self.assertRaises(ValueError):
            self.verify(doc)
        del doc['reviewed_ui_sha256']['h5-platform/public/c-test.js']
        with self.assertRaises(ValueError):
            self.verify(doc)
        for name in ('../secret', '/tmp/secret', 'h5-platform/public/../secret', 'h5-platform//public/c-test.js'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                m.safe_source(self.root, name)
        link = self.root / 'h5-platform/public/link.js'
        link.symlink_to(self.root / 'h5-platform/public/c-test.js')
        with self.assertRaises(ValueError):
            m.safe_source(self.root, 'h5-platform/public/link.js')

    def test_private_manifest_cannot_be_public_symlink_or_hardlink(self):
        directory = self.root / '.runtime/06/c-window'
        directory.mkdir(parents=True, mode=0o700)
        for p in (self.root / '.runtime', self.root / '.runtime/06'):
            p.chmod(0o700)
        path = directory / 'manifest.json'
        path.write_text(json.dumps(self.doc)); path.chmod(0o600)
        self.assertEqual(m.private_document(path, self.root), self.doc)
        path.chmod(0o644)
        with self.assertRaises(ValueError):
            m.private_document(path, self.root)
        path.chmod(0o600)
        other = directory / 'other.json'
        os.link(path, other)
        with self.assertRaises(ValueError):
            m.private_document(path, self.root)
        other.unlink()
        other.symlink_to(path)
        with self.assertRaises(ValueError):
            m.private_document(other, self.root)


if __name__ == '__main__':
    unittest.main()
