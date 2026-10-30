import hashlib
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest

OPS=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(OPS))
spec=importlib.util.spec_from_file_location('privacy_window',OPS/'privacy_manual_window.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class PrivacyWindowIsolation(unittest.TestCase):
    def configuration(self,root):
        suffix=hashlib.sha256(str(root).encode()).hexdigest()[:10]
        return dict(root=str(root),workspace='h5_'+suffix,db='h5_'+suffix,user='h5_app_'+suffix,port=8410,db_port=5910)

    def test_new_workspace_binds_to_own_database(self):
        root=Path('/private/tmp/h5-06-privacy-manual/H5');c=self.configuration(root)
        self.assertEqual(c,m.binding(root,c))
        for key in ['db','user','workspace']:
            with self.assertRaises(ValueError):m.binding(root,{**c,key:'copied_other_workspace'})

    def test_old_acceptance_and_payment_and_main_roots_are_refused(self):
        for root in [Path('/Volumes/KESU/H5'),Path('/private/tmp/h5-06-acceptance/H5'),Path('/private/tmp/h5-06-paypal-sandbox/H5')]:
            with self.assertRaises(ValueError):m.binding(root,self.configuration(root))

    def test_protected_previous_ports_are_refused(self):
        root=Path('/private/tmp/h5-06-privacy-manual/H5');c=self.configuration(root)
        for port in [8206,5606,8406,5906,8377,5877,8407,8408,8409,8303]:
            for key in ['port','db_port']:
                with self.assertRaises(ValueError):m.binding(root,{**c,key:port})

    def test_environment_does_not_turn_on_gateway_or_external_delivery(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);(root/'.runtime').mkdir();(root/'.runtime/pg-app-password').write_text('SYNTHETIC')
            # browser_environment shares a ROOT-free constructor; only this known password file is read.
            original=m.browser_environment
            m.browser_environment=lambda c,sha: __import__('analytics_acceptance').testing_environment(root,c)
            try:env=m.environment(self.configuration(root),'a'*40)
            finally:m.browser_environment=original
            self.assertEqual('1',env['H5_ANALYTICS_PRIVACY_ONLY'])
            self.assertEqual('unconfigured',env['H5_BILLING_NEW_PURCHASE_PROVIDER'])
            self.assertEqual('false',env['H5_BILLING_PAYPAL_SANDBOX_ENABLED'])
            self.assertEqual('disabled',env['ACQUISITION_TRANSPORT'])
            self.assertEqual('false',env['ACQUISITION_EXTERNAL_ENABLED'])

if __name__=='__main__':unittest.main()
