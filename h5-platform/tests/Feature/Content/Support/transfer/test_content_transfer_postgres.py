"""Opt-in integration. Services MUST point at two new empty disposable *_test databases."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

import content_transfer as t


@unittest.skipUnless(os.getenv('CONTENT_TRANSFER_SOURCE_TEST') and os.getenv('CONTENT_TRANSFER_TARGET_TEST'), 'explicit isolated services required')
class PostgresTransferTest(unittest.TestCase):
    def test_export_dry_run_apply_repeat_and_conflict_with_protected_rows(self):
        source = os.environ['CONTENT_TRANSFER_SOURCE_TEST']
        target = os.environ['CONTENT_TRANSFER_TARGET_TEST']
        for service in (source, target):
            self.assertTrue(t.query(service, 'SELECT current_database();').endswith('_test'))
            self.assertEqual('0', t.query(service, "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';"))
            statements = []
            for table in t.TABLES:
                fields = {
                    'content_settings': 'id integer PRIMARY KEY, default_free_count integer, version integer, catalog_version integer',
                    'content_assets': 'id text PRIMARY KEY, created_by uuid, state text, source_key text, output_key text, sha256 text',
                    'content_categories': 'id text PRIMARY KEY',
                    'content_dramas': 'id text PRIMARY KEY, created_by uuid, cover_asset_id text REFERENCES content_assets(id), version integer',
                    'content_drama_categories': 'drama_id text REFERENCES content_dramas(id), category_id text REFERENCES content_categories(id), PRIMARY KEY(drama_id,category_id)',
                    'content_episodes': 'id text PRIMARY KEY, drama_id text REFERENCES content_dramas(id), video_asset_id text REFERENCES content_assets(id), subtitle_asset_id text REFERENCES content_assets(id), version integer',
                    'content_plans': 'id text PRIMARY KEY, drama_id text REFERENCES content_dramas(id)',
                    'content_publications': 'id text PRIMARY KEY, drama_id text REFERENCES content_dramas(id), plan_id text REFERENCES content_plans(id)',
                    'content_banners': 'id text PRIMARY KEY, drama_id text REFERENCES content_dramas(id), image_asset_id text REFERENCES content_assets(id)',
                }[table]
                statements.append('CREATE TABLE ' + table + '(' + fields + ');')
            statements += ["CREATE TABLE identity_staff(id uuid PRIMARY KEY, role text, active boolean); INSERT INTO identity_staff VALUES('00000000-0000-4000-8000-000000000001','owner',true);", "CREATE TABLE protected_pixel(id int, token text); INSERT INTO protected_pixel VALUES(1,'SYNTHETIC_ONLY');", 'INSERT INTO content_settings VALUES(1,5,1,1);']
            t.query(service, '\n'.join(statements))
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); src = root/'source'; dst = root/'target'; src.mkdir(); dst.mkdir()
            (src/'assets/a/output/low').mkdir(parents=True)
            (src/'assets/a/source.png').write_bytes(b'source')
            (src/'assets/a/output/low/segment.ts').write_bytes(b'segment')
            sha = hashlib.sha256(b'source').hexdigest()
            t.query(source, "INSERT INTO content_assets VALUES('a','00000000-0000-4000-8000-000000000001','ready','assets/a/source.png','assets/a/output','" + sha + "'); INSERT INTO content_assets VALUES('unbound','00000000-0000-4000-8000-000000000001','pending',null,null,null); INSERT INTO content_dramas VALUES('d','00000000-0000-4000-8000-000000000001','a',7); INSERT INTO content_episodes VALUES('e','d',null,null,3); UPDATE content_settings SET catalog_version=20;")
            tool = str(Path(t.__file__))
            def run(*args, ok=True):
                result = subprocess.run([sys.executable, tool, *args], capture_output=True, text=True)
                self.assertEqual(ok, result.returncode == 0, result.stderr)
                return result
            package = str(root/'scope.json'); plan = str(root/'plan.json')
            run('export','--source-service',source,'--source-database',t.query(source,'SELECT current_database();'),'--source-media',str(src),'--drama','d','--package',package)
            p = json.loads(Path(package).read_text())
            args = ('copy','--package',package,'--source-media',str(src),'--target-media',str(dst),'--target-service',target,'--target-database',t.query(target,'SELECT current_database();'),'--target-uid',str(os.getuid()),'--target-gid',str(os.getgid()),'--plan',plan)
            t.query(target, "UPDATE identity_staff SET active=false;")
            run(*args,ok=False)
            self.assertFalse(Path(plan).exists())
            self.assertEqual([],list(dst.iterdir()))
            t.query(target, "UPDATE identity_staff SET active=true;")
            run(*args)
            self.assertEqual('0', t.query(target,'SELECT count(*) FROM content_dramas;'))
            self.assertEqual([],list(dst.iterdir()))
            run(*args,'--apply','--confirm-digest',p['digest'])
            self.assertEqual('7', t.query(target,"SELECT version FROM content_dramas WHERE id='d';"))
            self.assertEqual('1',t.query(target,'SELECT count(*) FROM content_assets;'))
            self.assertEqual('20',t.query(target,'SELECT catalog_version FROM content_settings;'))
            self.assertNotEqual((src/'assets/a/source.png').stat().st_ino,(dst/'assets/a/source.png').stat().st_ino)
            second = str(root/'second.json'); second_args = args[:-1]+(second,)
            run(*second_args); run(*second_args,'--apply','--confirm-digest',p['digest'])
            # Drift between reviewed dry-run and apply rejects before any new database write.
            third = str(root/'third.json'); third_args = args[:-1]+(third,)
            run(*third_args)
            t.query(target,"UPDATE content_dramas SET version=8 WHERE id='d';")
            run(*third_args,'--apply','--confirm-digest',p['digest'],ok=False)
            self.assertEqual('8',t.query(target,"SELECT version FROM content_dramas WHERE id='d';"))
            self.assertEqual('SYNTHETIC_ONLY',t.query(target,'SELECT token FROM protected_pixel;'))
            self.assertEqual('1',t.query(target,'SELECT count(*) FROM identity_staff;'))
            self.assertEqual('2',t.query(source,'SELECT count(*) FROM content_assets;'))


if __name__ == '__main__':
    unittest.main()
