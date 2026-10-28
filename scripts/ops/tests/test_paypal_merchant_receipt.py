import contextlib
import hashlib
import io
import json
from pathlib import Path
import stat
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import test_paypal_complete_webhook_url as fixture_module
import paypal_merchant_receipt as module
import paypal_apply_credentials as credentials
AUTOLOAD=fixture_module.AUTOLOAD


@unittest.skipUnless(AUTOLOAD.is_file(),'Real dotenv dependency required')
class MerchantReceiptTests(unittest.TestCase):
    def setUp(self):
        self.fixture=fixture_module.URLCompletionTests();self.fixture.setUp()
        self.root=self.fixture.root;self.directory=self.fixture.directory;self.env=self.fixture.env
        self.sha=self.fixture.sha;self.before=self.env.read_bytes();self.staged=(self.directory/'credentials.json').read_bytes()
        self.context={'environment':'sandbox','merchant_scope':self.fixture.fixture.values['merchant_scope'],
                      'merchant_id':'','client_id_sha256':hashlib.sha256(self.fixture.fixture.values['client_id'].encode()).hexdigest()}
        self.patch=patch.object(module,'offline_context',return_value=self.context);self.offline=self.patch.start()
        self.answers={key:True for key,_ in module.CONFIRMATIONS}

    def tearDown(self):self.patch.stop();self.fixture.tearDown()

    def state(self):return module.checked_state(self.root,self.sha,AUTOLOAD)

    def complete(self,answers=None,merchant='SYNTHETICMERCHANT'):
        _,_,context,before=self.state()
        return module.complete(self.root,self.sha,merchant,self.answers if answers is None else answers,before,context,AUTOLOAD)

    def receipts(self):return list(self.directory.glob('merchant-dashboard-*.json'))

    def test_complete_preserves_secrets_env_and_exact_receipt_schema(self):
        out=io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(out):path=self.complete()
        self.assertEqual('',out.getvalue());self.assertEqual(self.before,self.env.read_bytes())
        doc=json.loads((self.directory/'credentials.json').read_text());old=json.loads(self.staged)
        old['credentials']['merchant_id']='SYNTHETICMERCHANT';self.assertEqual(old,doc)
        receipt=json.loads(path.read_text())
        self.assertEqual(set(self.context)|{'binding_source','verified_at','evidence_ref'}|set(self.answers),set(receipt))
        self.assertEqual('operator_dashboard',receipt['binding_source']);self.assertEqual('SYNTHETICMERCHANT',receipt['merchant_id'])
        self.assertEqual(self.context['client_id_sha256'],receipt['client_id_sha256'])
        self.assertEqual(0o600,stat.S_IMODE(path.stat().st_mode))
        self.assertEqual(self.answers,{key:receipt[key] for key in self.answers})

    def test_every_confirmation_requires_explicit_yes(self):
        for position in range(3):
            for denial in ('','no','true','y','是'):
                answers=iter(['yes']*position+[denial]);self.assertIsNone(module.prompt_confirmations(lambda _:next(answers)))
        self.assertEqual(self.answers,module.prompt_confirmations(lambda _:'yes'))
        self.assertEqual([],self.receipts());self.assertEqual(self.staged,(self.directory/'credentials.json').read_bytes())

    def test_false_missing_or_string_true_cannot_write(self):
        for key in self.answers:
            for value in (False,'true',1):
                bad={**self.answers,key:value}
                with self.assertRaises(ValueError):self.complete(bad)
        with self.assertRaises(ValueError):self.complete({})
        self.assertEqual([],self.receipts());self.assertEqual(self.staged,(self.directory/'credentials.json').read_bytes())

    def test_cancel_main_does_not_write_or_complete(self):
        state=self.state()
        with patch.object(module,'ROOT',self.root),patch.object(sys,'argv',['tool','--candidate',self.sha]),patch.object(sys.stdin,'isatty',return_value=True),patch.object(module,'checked_state',return_value=state),patch.object(module.getpass,'getpass',return_value='SYNTHETICMERCHANT'),patch.object(module,'prompt_confirmations',return_value=None),patch.object(module,'complete') as complete,contextlib.redirect_stdout(io.StringIO()):
            module.main();complete.assert_not_called()
        self.assertEqual([],self.receipts());self.assertEqual(self.staged,(self.directory/'credentials.json').read_bytes())

    def test_non_tty_refuses_before_reading_state(self):
        with patch.object(sys,'argv',['tool','--candidate',self.sha]),patch.object(sys.stdin,'isatty',return_value=False),patch.object(module,'checked_state') as state:
            with self.assertRaises(SystemExit):module.main()
            state.assert_not_called()

    def test_env_staged_identity_drift_refused(self):
        for field in ('client_id','client_secret','merchant_scope'):
            doc=json.loads(self.staged);doc['credentials'][field]='CHANGED'
            self.fixture.write('credentials.json',doc)
            with self.assertRaises(ValueError):self.state()
        self.assertEqual([],self.receipts())

    def test_context_fingerprint_or_scope_drift_refused(self):
        for key,value in (('client_id_sha256','0'*64),('merchant_scope','another'),('environment','live'),('merchant_id','DIFFERENT')):
            self.offline.return_value={**self.context,key:value}
            with self.assertRaises(ValueError):self.state()

    def test_api_enabled_refused(self):
        credentials.apply(self.root,AUTOLOAD,api_mode=True)
        with self.assertRaises(ValueError):self.state()
        self.assertEqual([],self.receipts())

    def test_nonempty_merchant_cannot_be_rebound(self):
        doc=json.loads(self.staged);doc['credentials']['merchant_id']='EXISTING';self.fixture.write('credentials.json',doc)
        with self.assertRaises(ValueError):self.complete()
        self.assertEqual([],self.receipts())

    def test_changed_identity_after_user_confirmation_refused(self):
        _,_,context,before=self.state()
        doc=json.loads(self.staged);doc['credentials']['product_id']='PROD-CHANGED';self.fixture.write('credentials.json',doc)
        with self.assertRaises(ValueError):module.complete(self.root,self.sha,'SYNTHETICMERCHANT',self.answers,before,context,AUTOLOAD)
        self.assertEqual([],self.receipts())

    def test_failure_after_stage_leaves_no_receipt_and_same_id_retry_allowed(self):
        original=module.checked_state;calls=0
        def failing(*a,**kw):
            nonlocal calls
            calls+=1
            if calls==3:raise ValueError('synthetic failure after stage')
            return original(*a,**kw)
        with patch.object(module,'checked_state',side_effect=failing):
            with self.assertRaises(module.StagedButUnconfirmed):self.complete()
        self.assertEqual([],self.receipts())
        self.assertEqual('SYNTHETICMERCHANT',json.loads((self.directory/'credentials.json').read_text())['credentials']['merchant_id'])
        with self.assertRaises(ValueError):self.complete(merchant='OTHER')
        self.assertTrue(self.complete().exists())

    def test_receipt_removed_when_post_write_identity_changes(self):
        original=module.checked_state;calls=0
        def failing(*a,**kw):
            nonlocal calls
            calls+=1
            if calls==4:raise ValueError('synthetic final guard failure')
            return original(*a,**kw)
        with patch.object(module,'checked_state',side_effect=failing):
            with self.assertRaises(module.StagedButUnconfirmed):self.complete()
        self.assertEqual([],self.receipts())

    def test_offline_cli_response_must_be_successful_and_no_network(self):
        from types import SimpleNamespace
        self.patch.stop()
        valid={'configuration_valid':True,'network_requested':False,'diagnostic_code':None,'binding_context':self.context}
        with patch.object(module.subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout=json.dumps(valid))):
            self.assertEqual(self.context,module.offline_context(self.root))
        for changes in ({'network_requested':True},{'configuration_valid':False},{'diagnostic_code':'ERROR'}):
            with patch.object(module.subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout=json.dumps({**valid,**changes}))):
                with self.assertRaises(ValueError):module.offline_context(self.root)
        self.patch.start()


if __name__=='__main__':unittest.main(verbosity=2)
