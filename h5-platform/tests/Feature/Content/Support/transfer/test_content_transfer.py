import copy
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('transfer', Path(__file__).with_name('content_transfer.py'))
t = importlib.util.module_from_spec(spec)
spec.loader.exec_module(t)


class TransferTest(unittest.TestCase):
    def fixture(self):
        tables = {name: [] for name in t.TABLES}
        tables['content_settings'] = [dict(id=1, default_free_count=5, version=1, catalog_version=1)]
        tables['content_dramas'] = [dict(id='d', created_by='o', cover_asset_id='a', version=7)]
        tables['content_assets'] = [dict(id='a', created_by='o', state='ready', source_key='assets/a/source.png', output_key='assets/a/processed', sha256=hashlib.sha256(b'source').hexdigest())]
        tables['content_assets'].append(dict(id='candidate', state='pending'))
        tables['content_episodes'] = [dict(id='e', drama_id='d', video_asset_id=None, subtitle_asset_id=None, version=3)]
        columns = {name: list(rows[0]) if rows else ['id'] for name, rows in tables.items()}
        db = t.select_scope(dict(tables=tables, columns=columns), {'d'})
        p = dict(format=t.FORMAT, application=t.APPLICATION, drama_ids=['d'], db=db, files={})
        p['digest'] = t.digest(p)
        return p

    def test_scope_excludes_unbound_candidates_and_all_non_content_tables(self):
        p = self.fixture(); t.validate(p)
        self.assertEqual(['a'], [r['id'] for r in p['db']['tables']['content_assets']])
        self.assertNotIn('content_grants', p['db']['tables'])
        self.assertNotIn('content_operations', p['db']['tables'])
        p['db']['tables']['identity_staff'] = []
        with self.assertRaises(ValueError): t.validate(p)

    def test_existing_rows_are_idempotent_but_newer_target_is_rejected(self):
        p = self.fixture()
        self.assertTrue(all(not rows for rows in t.merge_rows(p, p['db']).values()))
        target = copy.deepcopy(p['db']); target['tables']['content_dramas'][0]['version'] = 8
        with self.assertRaisesRegex(ValueError, 'conflict'): t.merge_rows(p, target)

    def test_only_pristine_default_settings_can_initialize(self):
        p = self.fixture(); p['db']['tables']['content_settings'][0]['catalog_version'] = 20
        target = dict(columns=p['db']['columns'], tables={name: [] for name in t.TABLES})
        target['tables']['content_settings'] = [dict(id=1, default_free_count=5, version=1, catalog_version=1)]
        self.assertEqual(20, t.merge_rows(p, target)['content_settings'][0]['catalog_version'])
        target['tables']['content_settings'][0]['version'] = 2
        with self.assertRaises(ValueError): t.merge_rows(p, target)

    def test_pending_referenced_asset_is_rejected(self):
        p = self.fixture(); p['db']['tables']['content_assets'][0]['state'] = 'pending'
        with self.assertRaises(ValueError): t.validate(p)

    def test_manifest_contains_source_and_nested_renditions_only(self):
        p = self.fixture()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root/'assets/a/processed/low').mkdir(parents=True)
            (root/'assets/a/source.png').write_bytes(b'source')
            (root/'assets/a/processed/low/segment.ts').write_bytes(b'segment')
            (root/'unbound').write_bytes(b'candidate')
            files = t.manifest(root, p['db']['tables']['content_assets'])
            self.assertEqual({'assets/a/source.png', 'assets/a/processed/low/segment.ts'}, set(files))
            (root/'assets/a/source.png').write_bytes(b'changed')
            with self.assertRaises(ValueError): t.manifest(root, p['db']['tables']['content_assets'])

    def test_symlink_hardlink_and_traversal_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); (root/'source').write_bytes(b'data')
            (root/'link').symlink_to(root/'source')
            with self.assertRaises(ValueError): t.safe_path(root, 'link')
            with self.assertRaises(ValueError): t.safe_path(root, '../source')
            (root/'hard').hardlink_to(root/'source')
            with self.assertRaises(ValueError): t.file_info(root/'hard')

    def test_sql_only_whitelist_writes_and_pre_post_guards(self):
        p = self.fixture()
        target = dict(columns=p['db']['columns'], tables={name: [] for name in t.TABLES})
        sql = t.apply_sql(p, target, t.merge_rows(p, target))
        self.assertIn('TARGET_CHANGED', sql)
        self.assertIn('POST_MISMATCH', sql)
        self.assertIn('identity_staff WHERE', sql)
        self.assertNotIn('INSERT INTO identity_', sql)
        self.assertNotIn('acquisition', sql)
        self.assertNotIn('DELETE ', sql)
        self.assertTrue(sql.endswith('COMMIT;'))


if __name__ == '__main__':
    unittest.main()
