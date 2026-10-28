import fcntl
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import test_paypal_apply_credentials as fixture_module
AUTOLOAD = fixture_module.AUTOLOAD
import paypal_complete_webhook_url as module
import paypal_apply_credentials as credentials


@unittest.skipUnless(AUTOLOAD.is_file(), 'Real candidate dotenv dependency required')
class URLCompletionTests(unittest.TestCase):
    def setUp(self):
        self.fixture = fixture_module.ApplyBoundaryTests()
        self.fixture.setUp()
        self.root = self.fixture.root
        self.directory = self.fixture.directory
        self.env = self.fixture.env
        self.fixture.apply()  # Synthetic six-field credentials, never the real workspace.
        self.before = self.env.read_bytes()
        self.staged = (self.directory / 'credentials.json').read_bytes()
        self.url = 'https://synthetic-only.trycloudflare.com'
        self.marker = 'SYNTHETIC-06-'+'a'*32
        self.record = {'root':str(self.root),'upstream_port':8406,'bridge_port':8408,'metrics_port':8409,
                       'tunnel_pid':12341,'bridge_pid':12342,'process_started_at':{'tunnel_pid':'synthetic start','bridge_pid':'synthetic start'},
                       'tunnel_command':[str(self.root/'.runtime/tools/cloudflared-2026.10.4'),'tunnel','--config','/dev/null','--no-autoupdate','--metrics','127.0.0.1:8409','--management-diagnostics=false','--loglevel','info','--url','http://127.0.0.1:8408','--http-host-header','127.0.0.1:8408','--no-chunked-encoding']}
        self.write('external-url.json', {'url':self.url})
        self.write('external-processes.json',self.record)
        self.write('external-probe-marker.json',{'transmission_id':self.marker})
        (self.root/'h5-platform/config').mkdir()
        (self.root/'h5-platform/config/billing.php').write_text("<?php return ['webhook_url' => env('H5_BILLING_PAYPAL_SANDBOX_WEBHOOK_URL')];")
        subprocess.run(['git','init','-q',str(self.root)],check=True)
        subprocess.run(['git','-C',str(self.root),'add','h5-platform/config/billing.php'],check=True)
        subprocess.run(['git','-C',str(self.root),'-c','user.email=synthetic@example.invalid','-c','user.name=Synthetic','commit','-qm','synthetic candidate'],check=True)
        (self.root/'.git/info/exclude').write_text('h5-platform/.env\n.runtime/\n')
        self.sha=subprocess.check_output(['git','-C',str(self.root),'rev-parse','HEAD'],text=True).strip()
        self.mock=patch.object(module,'matching',return_value=True);self.match=self.mock.start()
        self.cmd_mock=patch.object(module,'process_command',return_value=' '.join(self.record['tunnel_command']));self.command=self.cmd_mock.start()

    def tearDown(self):
        self.cmd_mock.stop();self.mock.stop();self.fixture.tearDown()

    def write(self,name,value):
        self.fixture.private(self.directory/name,json.dumps(value))

    def apply(self):module.apply(self.root,self.sha,AUTOLOAD)

    def denied(self):
        before=self.env.read_bytes()
        with self.assertRaises((ValueError,OSError)):self.apply()
        self.assertEqual(before,self.env.read_bytes())

    def test_check_is_readonly(self):
        backups=list(self.directory.glob('env-before-*'))
        module.prepare(self.root,self.sha,AUTOLOAD)
        self.assertEqual(self.before,self.env.read_bytes())
        self.assertEqual(backups,list(self.directory.glob('env-before-*')))

    def test_only_url_changes_without_secret_output(self):
        out=io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(out):self.apply()
        old=credentials.parse_env(self.before.decode(),AUTOLOAD)
        new=credentials.parse_env(self.env.read_text(),AUTOLOAD)
        self.assertEqual({**old,module.URL_KEY:self.url+'/webhooks/paypal'},new)
        self.assertEqual(self.staged,(self.directory/'credentials.json').read_bytes())
        self.assertEqual('',out.getvalue())
        self.assertEqual(0o600,stat.S_IMODE(self.env.stat().st_mode))
        backup=list(self.directory.glob('env-before-webhook-url-*'))
        self.assertEqual(1,len(backup));self.assertEqual(self.before,backup[0].read_bytes())
        self.assertEqual(0o600,stat.S_IMODE(backup[0].stat().st_mode))

    def test_staged_credentials_not_needed_or_read(self):
        (self.directory/'credentials.json').unlink();self.apply()

    def test_old_url_replaced_without_identity_change(self):
        self.fixture.append(module.URL_KEY+'="https://old.trycloudflare.com/webhooks/paypal"')
        self.apply()
        self.assertEqual(self.url+'/webhooks/paypal',credentials.parse_env(self.env.read_text(),AUTOLOAD)[module.URL_KEY])

    def test_api_on_rejected(self):
        credentials.apply(self.root,AUTOLOAD,api_mode=True);self.denied()

    def test_running_application_rejected(self):
        d=self.root/'.runtime/pids';d.mkdir();self.fixture.private(d/'web.json','{}');self.denied()

    def test_closed_or_recycled_window_rejected(self):
        self.match.return_value=False;self.denied()

    def test_other_worktree_record_rejected(self):
        self.write('external-processes.json',{**self.record,'root':'/another-workspace'});self.denied()

    def test_wider_tunnel_command_rejected(self):
        r=json.loads(json.dumps(self.record));r['tunnel_command'][r['tunnel_command'].index('http://127.0.0.1:8408')]='http://127.0.0.1:8406'
        self.write('external-processes.json',r);self.denied()

    def test_url_input_cannot_escape_authorized_origin(self):
        for value in ('http://synthetic.trycloudflare.com','https://127.0.0.1','https://synthetic.trycloudflare.com@evil.test','https://synthetic.trycloudflare.com:8443','https://synthetic.trycloudflare.com/extra','https://synthetic.trycloudflare.com?x=1','https://synthetic.trycloudflare.com#x','https://synthetic.trycloudflare.com\n'):
            with self.subTest(value=value):self.write('external-url.json',{'url':value});self.denied()

    def test_private_record_mode_and_symlink_rejected(self):
        p=self.directory/'external-url.json';p.chmod(0o644);self.denied();p.chmod(0o600)
        target=self.directory/'saved-url.json';p.rename(target);p.symlink_to(target);self.denied()

    def test_candidate_mismatch_and_missing_shared_field_rejected(self):
        with self.assertRaises(ValueError):module.prepare(self.root,'0'*40,AUTOLOAD)
        (self.root/'h5-platform/config/billing.php').write_text('<?php return [];')
        self.denied()

    def test_untracked_application_source_rejected(self):
        (self.root/'h5-platform/unreviewed.php').write_text('<?php');self.denied()

    def test_candidate_without_field_even_when_clean_rejected(self):
        p=self.root/'h5-platform/config/billing.php';p.write_text('<?php return [];')
        subprocess.run(['git','-C',str(self.root),'-c','user.email=synthetic@example.invalid','-c','user.name=Synthetic','commit','-qam','older candidate'],check=True)
        self.sha=subprocess.check_output(['git','-C',str(self.root),'rev-parse','HEAD'],text=True).strip();self.denied()

    def test_change_during_backup_refused(self):
        original=module.prepare
        calls=0
        def changing(*a,**kw):
            nonlocal calls
            calls+=1
            if calls==2:self.write('external-url.json',{'url':'https://changed.trycloudflare.com'})
            return original(*a,**kw)
        with patch.object(module,'prepare',side_effect=changing):self.denied()


    def test_post_write_guard_reports_written_state_without_blind_rollback(self):
        original=module.atomic_private
        def close_after_write(path,value):
            original(path,value)
            self.match.return_value=False
        with patch.object(module,'atomic_private',side_effect=close_after_write):
            with self.assertRaises(module.AppliedButUnverified):self.apply()
        self.assertEqual(self.url+'/webhooks/paypal',credentials.parse_env(self.env.read_text(),AUTOLOAD)[module.URL_KEY])
        self.assertEqual('false',credentials.parse_env(self.env.read_text(),AUTOLOAD)[credentials.PREFIX+'ENABLED'])

    def test_actual_command_extra_origin_rejected(self):
        self.command.return_value += ' --url http://127.0.0.1:8406'
        self.denied()

    def test_shared_apply_lock_blocks_concurrent_writer(self):
        with (self.directory/'.apply.lock').open('w') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            self.denied()


if __name__=='__main__':unittest.main(verbosity=2)
