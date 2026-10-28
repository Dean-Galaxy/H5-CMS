import contextlib
import fcntl
import io
import json
from pathlib import Path
import stat
import subprocess
import sys
import unittest
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import test_paypal_complete_webhook_url as fixtures
import paypal_purchase_switch as module
import paypal_apply_credentials as legacy
AUTOLOAD=fixtures.AUTOLOAD


@unittest.skipUnless(AUTOLOAD.is_file(),'Real dotenv dependency required')
class PurchaseSwitchTests(unittest.TestCase):
    def setUp(self):
        self.fixture=fixtures.URLCompletionTests();self.fixture.setUp()
        self.root=self.fixture.root;self.env=self.fixture.env;self.directory=self.fixture.directory;self.sha=self.fixture.sha
        legacy.apply(self.root,AUTOLOAD,api_mode=True)
        self.fixture.fixture.append(legacy.PREFIX+'WEBHOOK_URL="'+self.fixture.url+'/webhooks/paypal"')
        self.before=self.env.read_bytes()
        self.ready_patch=patch.object(module,'official_readiness',return_value=None);self.ready=self.ready_patch.start()

    def tearDown(self):self.ready_patch.stop();self.fixture.tearDown()

    def run_switch(self,enable):module.switch(self.root,self.sha,enable,AUTOLOAD)

    def parsed(self):return legacy.parse_env(self.env.read_text(),AUTOLOAD)

    def update(self,updates):
        old=self.env.read_text();values=self.parsed()
        self.fixture.fixture.private(self.env,legacy.render_updates(old,values,updates,AUTOLOAD))

    def denied(self,enable=True):
        before=self.env.read_bytes()
        with self.assertRaises((ValueError,OSError,subprocess.SubprocessError)):self.run_switch(enable)
        self.assertEqual(before,self.env.read_bytes())

    def test_enable_changes_only_provider_and_has_private_backup(self):
        before=self.parsed();out=io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(out):self.run_switch(True)
        self.assertEqual({**before,module.PROVIDER:'paypal'},self.parsed());self.assertEqual('',out.getvalue())
        self.ready.assert_called_once()
        backup=list(self.directory.glob('env-before-purchase-switch-*'));self.assertEqual(1,len(backup))
        self.assertEqual(self.before,backup[0].read_bytes());self.assertEqual(0o600,stat.S_IMODE(backup[0].stat().st_mode));self.assertEqual(0o600,stat.S_IMODE(self.env.stat().st_mode))

    def test_disable_recovers_enabled_paypal_without_network_or_window(self):
        self.run_switch(True);before=self.parsed();self.ready.reset_mock()
        for name in ('external-url.json','external-processes.json','external-probe-marker.json','credentials.json'):(self.directory/name).unlink()
        with patch.object(module,'active_window_url',side_effect=AssertionError('Must not read window')):self.run_switch(False)
        self.ready.assert_not_called()
        self.assertEqual({**before,module.PROVIDER:'unconfigured',legacy.PREFIX+'ENABLED':'false'},self.parsed())

    def test_disable_is_idempotent_and_offline(self):
        self.run_switch(False);before=self.env.read_bytes();self.run_switch(False)
        self.assertEqual(before,self.env.read_bytes());self.ready.assert_not_called()

    def test_stripe_is_refused_for_both_modes(self):
        for field in (module.PROVIDER,'H5_BILLING_DRIVER'):
            self.fixture.fixture.private(self.env,self.before.decode());self.update({field:'stripe' if field==module.PROVIDER else 'stripe_test'})
            self.denied(True);self.denied(False)
        self.ready.assert_not_called()

    def test_api_off_cannot_enable(self):
        self.update({legacy.PREFIX+'ENABLED':'false'});self.denied();self.ready.assert_not_called()

    def test_mismatching_current_url_cannot_enable(self):
        self.update({legacy.PREFIX+'WEBHOOK_URL':'https://old.trycloudflare.com/webhooks/paypal'});self.denied();self.ready.assert_not_called()

    def test_dead_or_recycled_window_cannot_enable(self):
        self.fixture.match.return_value=False;self.denied();self.ready.assert_not_called()

    def test_official_failure_does_not_write_or_backup(self):
        self.ready.side_effect=ValueError('synthetic secret must not be printed');out=io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(out):self.denied()
        self.assertEqual('',out.getvalue());self.assertEqual([],list(self.directory.glob('env-before-purchase-switch-*')))

    def test_official_timeout_does_not_write(self):
        self.ready.side_effect=subprocess.TimeoutExpired('synthetic',1);self.denied()

    def test_env_changes_during_official_check_not_overwritten(self):
        def change(*_):self.update({legacy.PREFIX+'PRODUCT_ID':'PROD-CONCURRENT'})
        self.ready.side_effect=change
        with self.assertRaises(ValueError):self.run_switch(True)
        self.assertEqual('PROD-CONCURRENT',self.parsed()[legacy.PREFIX+'PRODUCT_ID']);self.assertEqual('unconfigured',self.parsed()[module.PROVIDER])

    def test_window_changes_during_official_check_not_applied(self):
        def change(*_):self.fixture.write('external-url.json',{'url':'https://new.trycloudflare.com'})
        self.ready.side_effect=change;self.denied()

    def test_source_changes_during_official_check_not_applied(self):
        self.ready.side_effect=lambda *_:(self.root/'h5-platform/config/billing.php').write_text('<?php return [];')
        self.denied()

    def test_stopped_application_and_no_cached_config_required(self):
        p=self.root/'.runtime/pids';p.mkdir();self.fixture.fixture.private(p/'web.json','{}')
        self.denied(True);self.denied(False);(p/'web.json').unlink()
        p=self.root/'h5-platform/bootstrap/cache';p.mkdir(parents=True);(p/'config.php').write_text('<?php return [];')
        self.denied(True);self.denied(False)

    def test_other_workspace_database_and_live_env_refused(self):
        for changes in ({'DB_DATABASE':'other'},{'APP_ENV':'production'},{'APP_URL':'http://127.0.0.1:8377'}):
            self.fixture.fixture.private(self.env,self.before.decode());self.update(changes);self.denied(True);self.denied(False)

    def test_candidate_mismatch_refused(self):
        self.sha='0'*40;self.denied(True);self.denied(False)

    def test_shared_lock_refuses_parallel_change(self):
        with (self.directory/'.apply.lock').open('w') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);self.denied()
        self.ready.assert_not_called()

    def test_legacy_tools_still_refuse_enabled_purchase(self):
        self.run_switch(True);before=self.env.read_bytes()
        with self.assertRaises(ValueError):legacy.apply(self.root,AUTOLOAD,api_mode=False)
        self.assertEqual(before,self.env.read_bytes());self.run_switch(False)

    def test_post_write_failure_is_explicit_and_disable_can_recover(self):
        original=module.atomic_private
        def after(path,contents):original(path,contents);self.fixture.match.return_value=False
        with patch.object(module,'atomic_private',side_effect=after):
            with self.assertRaises(module.WrittenButUnverified):self.run_switch(True)
        self.assertEqual('paypal',self.parsed()[module.PROVIDER]);self.run_switch(False)
        self.assertEqual('false',self.parsed()[legacy.PREFIX+'ENABLED'])

    def test_official_cli_requires_all_ready_flags_and_no_error(self):
        self.ready_patch.stop()
        valid={k:True for k in ('configuration_valid','network_requested','merchant_binding_valid','app_authenticated','webhook_registration_valid','product_valid','purchase_readiness_valid')}
        valid.update(binding_source='operator_dashboard',diagnostic_code=None,missing=[],api_merchant_identity_verified=False,merchant_verified=False,official_payment_verification='not_performed')
        with patch.object(module.subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout=json.dumps(valid))) as run:
            module.official_readiness(self.root,{})
            self.assertEqual('--check-readiness',run.call_args.args[0][-1])
        for key in ('configuration_valid','network_requested','merchant_binding_valid','app_authenticated','webhook_registration_valid','product_valid','purchase_readiness_valid'):
            for value in (False,'true',1):
                with patch.object(module.subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout=json.dumps({**valid,key:value}))):
                    with self.assertRaises(ValueError):module.official_readiness(self.root,{})
        for update in ({'diagnostic_code':'FAILED'},{'missing':['webhook_url']},{'binding_source':'guessed'},{'api_merchant_identity_verified':True}):
            with patch.object(module.subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout=json.dumps({**valid,**update}))):
                with self.assertRaises(ValueError):module.official_readiness(self.root,{})
        self.ready_patch.start()
 
    def test_unreviewed_operation_source_refused(self):
        directory=self.root/'scripts/ops';directory.mkdir(parents=True)
        (directory/'changed.py').write_text('unreviewed')
        self.denied(True);self.denied(False)


if __name__=='__main__':unittest.main(verbosity=2)
