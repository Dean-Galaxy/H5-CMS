"""Offline guards only: no Web/PG process, no real backup or migration."""
from datetime import datetime,timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
OPS=Path(__file__).resolve().parents[1];sys.path.insert(0,str(OPS))
import r1_window as r
import r1_rehearsal as rehearsal


class R1Boundary(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name).resolve()/'h5-r1-registration-manual/H5';self.root.mkdir(parents=True)
        suffix=hashlib.sha256(str(self.root).encode()).hexdigest()[:10]
        self.cfg={'root':str(self.root),'workspace':'h5_'+suffix,'db':'h5_'+suffix,'user':'h5_app_'+suffix,'port':8412,'db_port':5912}

    def test_exact_new_root_ports_and_names(self):
        self.assertEqual(r.binding(self.root,self.cfg),self.cfg)
        for key,value in [('root','/foreign'),('db','h5_c2'),('workspace','copied'),('user','admin'),('port',8411),('db_port',5906),('port',True)]:
            with self.subTest(key=key,value=value),self.assertRaises(ValueError):r.binding(self.root,{**self.cfg,key:value})

    def test_old_root_refuses_before_private_reads(self):
        with patch.object(r,'ROOT',Path('/Volumes/KESU/H5')),patch.object(r,'private_json') as read:
            with self.assertRaises(ValueError):r.config()
            read.assert_not_called()

    def test_only_named_own_role_database(self):
        for role in r.ROLES:self.assertTrue(r.db_name(self.cfg,role).endswith('_'+role+'_test'))
        for role in ['test','manual_test; DROP DATABASE x','paypal','']:
            with self.assertRaises(ValueError):r.db_name(self.cfg,role)
        with self.assertRaises(ValueError):r.db_name({'db':"bad'"},'manual')

    def test_fixed_absolute_policy_and_expiry_without_extension(self):
        now=datetime(2026,10,31,10,tzinfo=timezone.utc)
        r.policy('2026-10-31T09:00:00Z','2026-11-01T09:00:00Z',now)
        for start,end in [('now','+23 hours'),('2026-10-31T10:00:00','2026-11-01T10:00:00'),('2026-10-31T09:00:00Z','2026-11-01T10:00:00Z'),('2026-02-31T09:00:00Z','2026-03-01T09:00:00Z'),('2026-10-30T09:00:00Z','2026-10-31T09:00:00Z')]:
            with self.subTest(start=start,end=end),self.assertRaises(ValueError):r.policy(start,end,now)
        r.policy('2026-10-30T09:00:00Z','2026-10-31T09:00:00Z',now,future=False)

    def test_private_text_rejects_public_links_and_hardlinks(self):
        file=self.root/'secret';file.write_text('synthetic non-secret');file.chmod(0o600)
        self.assertEqual(r.private_text(file),'synthetic non-secret')
        file.chmod(0o644)
        with self.assertRaises(ValueError):r.private_text(file)
        file.chmod(0o600);link=self.root/'link';link.symlink_to(file)
        with self.assertRaises(ValueError):r.private_text(link)
        link.unlink();os.link(file,link)
        with self.assertRaises(ValueError):r.private_text(file)

    def test_pg_same_prefix_or_copied_record_refused(self):
        data=self.root/'.runtime/postgres';data.mkdir(parents=True)
        pid=data/'postmaster.pid';pid.write_text('12345\n'+str(data)+'\n0\n5912\n')
        for args in ['-D '+str(data)+'-foreign','-D '+str(data)+' -D /foreign','-D']:
            p=subprocess.CompletedProcess([],0,'postgres '+args,'')
            with patch.object(r,'ROOT',self.root),patch.object(r.subprocess,'run',return_value=p),self.assertRaises(ValueError):r.pg_guard(self.cfg)
        pid.write_text('12345\n/foreign\n0\n5912\n')
        with patch.object(r,'ROOT',self.root),patch.object(r.subprocess,'run') as run,self.assertRaises(ValueError):r.pg_guard(self.cfg)
        run.assert_not_called()

    def test_pid_ownership_rejects_other_root_reused_start_and_boolean_pid(self):
        with patch.object(r,'ROOT',self.root),patch.object(r.subprocess,'run') as run:
            self.assertFalse(r.matching({'root':'/foreign','pid':3},self.cfg));run.assert_not_called()
            record={'root':str(self.root),'command':r.command(self.cfg),'pid':True}
            self.assertFalse(r.matching(record,self.cfg));run.assert_not_called()
        record={'root':str(self.root),'command':None,'pid':444,'started_at':'original'}
        with patch.object(r,'ROOT',self.root):
            record['command']=r.command(self.cfg)
            values=[subprocess.CompletedProcess([],0,' '.join(record['command']),''),subprocess.CompletedProcess([],0,'reused-start','')]
            with patch.object(r.subprocess,'run',side_effect=values):self.assertFalse(r.matching(record,self.cfg))

    def test_backup_digest_coverage_corruption_and_foreign_source(self):
        out=self.root/'.runtime/06/r1';directory=out/'backups/synthetic';directory.mkdir(mode=0o700,parents=True)
        for name in ['database.dump','environment-private','workspace-private.json','manifest-private.json','fingerprint.json','state.json','fixtures/content.json','media/video.bin']:
            file=directory/name;file.parent.mkdir(mode=0o700,parents=True,exist_ok=True);file.write_bytes(b'synthetic, not a real backup');file.chmod(0o600)
        index={str(p.relative_to(directory)):hashlib.sha256(p.read_bytes()).hexdigest() for p in directory.rglob('*') if p.is_file()}
        file=directory/'sha256.json';file.write_text(json.dumps(index));file.chmod(0o600)
        with patch.object(r,'OUT',out):
            self.assertEqual(r.verify_backup(directory),8)
            with self.assertRaises(ValueError):r.verify_backup(self.root)
            (directory/'database.dump').write_bytes(b'changed')
            with self.assertRaises(ValueError):r.verify_backup(directory)

    def test_modified_untracked_or_missing_candidate_tools_refused(self):
        file=self.root/'tool.py';file.write_text('committed')
        with patch.object(r,'ROOT',self.root),patch.object(r.subprocess,'check_output',return_value=b'committed'):
            self.assertEqual(len(r.committed_files('a'*40,['tool.py'])),1)
            file.write_text('uncommitted')
            with self.assertRaises(ValueError):r.committed_files('a'*40,['tool.py'])
        with patch.object(r,'git',side_effect=['\n'.join(['scripts/ops/'+p for p in ['analytics_acceptance.py','r1_window.py','r1_window_runtime.php','r1_window_boundary.php','r1_window_router.php','r1_window_control.php','r1_browser.cjs','r1_rehearsal.py','r1_probe.php','tests/test_r1_window.py']]),'?? scripts/ops/r1_extra.py']):
            with self.assertRaises(ValueError):r.runtime_tools('a'*40)
        with patch.object(r,'git',return_value='scripts/ops/r1_window.py'):
            with self.assertRaises(ValueError):r.runtime_tools('a'*40)

    def test_restored_target_missing_extra_corrupt_symlink_refused(self):
        src=self.root/'source';dst=self.root/'target';src.mkdir();dst.mkdir(mode=0o700)
        file=dst/'video.bin';file.write_bytes(b'own media');file.chmod(0o600)
        index={'media/video.bin':hashlib.sha256(b'own media').hexdigest()}
        self.assertTrue(r.compare_restore_tree(src,dst,index,'media')['sha256_verified'])
        file.write_bytes(b'corrupt')
        with self.assertRaises(ValueError):r.compare_restore_tree(src,dst,index,'media')
        file.unlink()
        with self.assertRaises(ValueError):r.compare_restore_tree(src,dst,index,'media')
        file.write_bytes(b'own media');file.chmod(0o600);extra=dst/'extra';extra.write_text('extra');extra.chmod(0o600)
        with self.assertRaises(ValueError):r.compare_restore_tree(src,dst,index,'media')
        extra.unlink();file.unlink();file.symlink_to(src/'foreign')
        with self.assertRaises(ValueError):r.compare_restore_tree(src,dst,index,'media')

    def test_suite_roots_nested_files_and_escape_or_links(self):
        app=self.root/'h5-platform'
        for name in ['Feature','Integration','Security']:(app/'tests'/name).mkdir(parents=True)
        nested=app/'tests/Feature/Acquisition';nested.mkdir();(nested/'PurposeTest.php').write_text('<?php')
        (app/'tests/Feature/Linked.php').symlink_to(nested/'PurposeTest.php')
        with patch.object(r,'ROOT',self.root):
            for path in ['tests/Feature','tests/Integration','tests/Security','tests/Feature/Acquisition','tests/Feature/Acquisition/PurposeTest.php']:self.assertTrue(r.safe_suite(path),path)
            for path in ['tests/Feature.php','tests/Feature/Missing.php','tests/Feature/Linked.php','tests/../Feature','tests/Feature/../../private','tests/Feature/%2e%2e','tests/Feature;echo','/tests/Feature','tests/Other','tests/Feature/Acquisition/']:self.assertFalse(r.safe_suite(path),path)

    def test_regression_uses_default_provider_scope_retry1_is_explicit_only(self):
        out=self.root/'isolated';out.mkdir();(out/'manifest.json').write_text('synthetic')
        registry={'root':str(r.ROOT),'pg_port':5912,'databases':{role:r.db_name(self.cfg,role) for role in ['manual','regression','lifecycle_retry1']}}
        doc={'candidate':'a'*40,'policy_from':'2026-10-31T00:00:00Z','policy_until':'2026-10-31T23:00:00Z'}
        with patch.object(r,'OUT',out),patch.object(r,'private_json',return_value=registry),patch.object(r,'testing_environment',side_effect=lambda *args:{'H5_LOCAL_MODULES':'acquisition','ACQUISITION_ANALYTICS_ENABLED':'true'}):
            regression=r.environment(self.cfg,doc,'regression');manual=r.environment(self.cfg,doc,'manual');retry=r.environment(self.cfg,doc,'lifecycle_retry1')
            self.assertEqual(regression['H5_LOCAL_MODULES'],'');self.assertEqual(regression['ACQUISITION_ANALYTICS_ENABLED'],'false')
            self.assertEqual(manual['H5_LOCAL_MODULES'],'acquisition');self.assertEqual(retry['H5_LOCAL_MODULES'],'acquisition')
            self.assertEqual(retry['DB_DATABASE'],r.db_name(self.cfg,'lifecycle_retry1'))
        with self.assertRaises(ValueError):r.db_name(self.cfg,'lifecycle_retry2')

    def test_probe_stdout_stderr_exit_preserved_before_json_and_failures_unique(self):
        out=self.root/'.runtime/06/r1';out.mkdir(mode=0o700,parents=True)
        # Ensure the complete private hierarchy expected by the actual writer.
        for parent in [self.root/'.runtime',self.root/'.runtime/06',out]:parent.chmod(0o700)
        doc={'candidate':'a'*40}
        with patch.object(r,'ROOT',self.root),patch.object(r,'OUT',out):
            with self.assertRaisesRegex(ValueError,'JSON_FAILED'):rehearsal.capture_result(doc,'lifecycle_retry1','lifecycle',subprocess.CompletedProcess([],0,'bad JSON original','original stderr'))
            with self.assertRaisesRegex(ValueError,'PROBE_FAILED'):rehearsal.capture_result(doc,'lifecycle_retry1','lifecycle',subprocess.CompletedProcess([],7,'partial output','private stack'))
            result=rehearsal.capture_result(doc,'lifecycle_retry1','lifecycle',subprocess.CompletedProcess([],0,'{"passed":true}',''))
            self.assertTrue(result['passed'])
        directories=list((out/'probe-output').iterdir());self.assertEqual(len(directories),3)
        self.assertCountEqual([json.loads((p/'process.json').read_text())['exit_code'] for p in directories],[0,7,0])
        self.assertIn('bad JSON original',[(p/'stdout-private.txt').read_text() for p in directories]);self.assertIn('private stack',[(p/'stderr-private.txt').read_text() for p in directories])
        for directory in directories:
            for file in directory.iterdir():self.assertEqual(file.stat().st_mode&0o077,0)

    def test_php_probe_closed_bootstrap_is_safe_json_nonzero_no_database(self):
        env=os.environ.copy();env['H5_R1_WINDOW_ONLY']='0'
        run=subprocess.run([r.PHP,str(OPS/'r1_probe.php'),'lifecycle'],env=env,capture_output=True,text=True)
        self.assertEqual(run.returncode,1,run.stderr);value=json.loads(run.stdout)
        self.assertEqual(value['phase'],'bootstrap');self.assertFalse(value['passed']);self.assertEqual(value['exception_type'],'LogicException')
        self.assertNotIn('stack_private',value)

    def test_php_exact_allowed_http_and_static_and_front_controller(self):
        public=self.root/'public';public.mkdir();(public/'index.php').write_text('synthetic index');(public/'app.css').write_text('body{}')
        (public/'admin').mkdir();(public/'admin/admin.js').write_text('void 0;');(public/'open').mkdir()
        boundary=OPS/'r1_window_boundary.php';vendor=OPS.parents[1]/'h5-platform/vendor/autoload.php'
        code=r'''require $argv[1]; require $argv[2];$public=$argv[3];$u='00000000-0000-4000-8000-000000000006';
        foreach([['GET','/',true],['GET','/health',true],['GET','/api/v1/auth/csrf',true],['GET','/api/v1/auth/me',true],['POST','/api/v1/auth/register',true],['POST','/api/v1/auth/login',true],['POST','/api/v1/auth/logout',true],['GET','/identity/test-terms',true],['GET','/identity/test-privacy',true],['GET','/api/v3/measurement/purposes/context',true],['PATCH','/api/v3/measurement/purposes/preferences',true],['PATCH','/api/v2/measurement/preferences',true],['POST','/audience/dramas/'.$u.'/episodes/'.$u.'/playback',true],['GET','/admin',false],['POST','/api/v1/admin/staff',false],['GET','/membership',false],['POST','/membership/checkout',false],['POST','/api/v1/billing/refunds',false],['POST','/webhooks/paypal',false],['POST','/webhooks/stripe',false],['POST','/unknown',false],['POST','/api/v1/tracking/consent',false]] as $r){if(H5R1Boundary::allows($r[0],$r[1])!==$r[2]){exit(1);}}
        if(H5R1Boundary::staticTarget($public,'/app.css?v=1')!==$public.'/app.css'||H5R1Boundary::staticTarget($public,'/admin/admin.js')!==null||H5R1Boundary::staticTarget($public,'/../outside.css')!==null){exit(2);}
        foreach(['/open/../admin/admin.js','/open/%2e%2e/admin/admin.js','/%61dmin/admin.js','/open/../../outside.js'] as $uri){if(H5R1Boundary::staticTarget($public,$uri)!==null){exit(4);}}
        $server=['REQUEST_METHOD'=>'PATCH','REQUEST_URI'=>'/api/v3/measurement/purposes/preferences?x=1','QUERY_STRING'=>'x=1','SCRIPT_NAME'=>'/api/v3/measurement/purposes/preferences','PHP_SELF'=>'/api/v3/measurement/purposes/preferences','SCRIPT_FILENAME'=>$public.'/wrong','SERVER_NAME'=>'127.0.0.1','HTTP_HOST'=>'127.0.0.1:8412','HTTP_X_CSRF_TOKEN'=>'synthetic-csrf'];
        $s=H5R1Boundary::normalize($server,$public);$request=new Symfony\Component\HttpFoundation\Request(['x'=>'1'],[],[],['synthetic'=>'cookie'],[],$s,'{"choice":"deny"}');
        if($request->getPathInfo()!=='/api/v3/measurement/purposes/preferences'||$request->getContent()!=='{"choice":"deny"}'||$request->cookies->get('synthetic')!=='cookie'||$request->headers->get('X-CSRF-TOKEN')!=='synthetic-csrf'||$request->query->get('x')!=='1'){exit(3);} echo '22 HTTP classifications; actual request normalization and safe static targets';'''
        run=subprocess.run(['/opt/homebrew/opt/php@8.4/bin/php','-r',code,str(vendor),str(boundary),str(public)],capture_output=True,text=True)
        self.assertEqual(run.returncode,0,run.stderr)


if __name__=='__main__':unittest.main()
