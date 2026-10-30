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

    def test_php_exact_allowed_http_and_static_and_front_controller(self):
        public=self.root/'public';public.mkdir();(public/'index.php').write_text('synthetic index');(public/'app.css').write_text('body{}')
        (public/'admin').mkdir();(public/'admin/admin.js').write_text('void 0;')
        boundary=OPS/'r1_window_boundary.php';vendor=OPS.parents[1]/'h5-platform/vendor/autoload.php'
        code=r'''require $argv[1]; require $argv[2];$public=$argv[3];$u='00000000-0000-4000-8000-000000000006';
        foreach([['GET','/',true],['GET','/health',true],['GET','/api/v1/auth/csrf',true],['GET','/api/v1/auth/me',true],['POST','/api/v1/auth/register',true],['POST','/api/v1/auth/login',true],['POST','/api/v1/auth/logout',true],['GET','/identity/test-terms',true],['GET','/identity/test-privacy',true],['GET','/api/v3/measurement/purposes/context',true],['PATCH','/api/v3/measurement/purposes/preferences',true],['PATCH','/api/v2/measurement/preferences',true],['POST','/audience/dramas/'.$u.'/episodes/'.$u.'/playback',true],['GET','/admin',false],['POST','/api/v1/admin/staff',false],['GET','/membership',false],['POST','/membership/checkout',false],['POST','/api/v1/billing/refunds',false],['POST','/webhooks/paypal',false],['POST','/webhooks/stripe',false],['POST','/unknown',false],['POST','/api/v1/tracking/consent',false]] as $r){if(H5R1Boundary::allows($r[0],$r[1])!==$r[2]){exit(1);}}
        if(H5R1Boundary::staticTarget($public,'/app.css?v=1')!==$public.'/app.css'||H5R1Boundary::staticTarget($public,'/admin/admin.js')!==null||H5R1Boundary::staticTarget($public,'/../outside.css')!==null){exit(2);}
        $server=['REQUEST_METHOD'=>'PATCH','REQUEST_URI'=>'/api/v3/measurement/purposes/preferences?x=1','QUERY_STRING'=>'x=1','SCRIPT_NAME'=>'/api/v3/measurement/purposes/preferences','PHP_SELF'=>'/api/v3/measurement/purposes/preferences','SCRIPT_FILENAME'=>$public.'/wrong','SERVER_NAME'=>'127.0.0.1','HTTP_HOST'=>'127.0.0.1:8412','HTTP_X_CSRF_TOKEN'=>'synthetic-csrf'];
        $s=H5R1Boundary::normalize($server,$public);$request=new Symfony\Component\HttpFoundation\Request(['x'=>'1'],[],[],['synthetic'=>'cookie'],[],$s,'{"choice":"deny"}');
        if($request->getPathInfo()!=='/api/v3/measurement/purposes/preferences'||$request->getContent()!=='{"choice":"deny"}'||$request->cookies->get('synthetic')!=='cookie'||$request->headers->get('X-CSRF-TOKEN')!=='synthetic-csrf'||$request->query->get('x')!=='1'){exit(3);} echo '22 HTTP classifications; actual request normalization and safe static targets';'''
        run=subprocess.run(['/opt/homebrew/opt/php@8.4/bin/php','-r',code,str(vendor),str(boundary),str(public)],capture_output=True,text=True)
        self.assertEqual(run.returncode,0,run.stderr)


if __name__=='__main__':unittest.main()
