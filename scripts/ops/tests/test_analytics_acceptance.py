import hashlib
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

spec=importlib.util.spec_from_file_location('analytics_runner',Path(__file__).resolve().parents[1]/'analytics_acceptance.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class AnalyticsIsolationTests(unittest.TestCase):
    def setUp(self):
        self.root=Path('/private/tmp/synthetic-06-analytics')
        suffix=hashlib.sha256(str(self.root).encode()).hexdigest()[:10]
        self.config={'root':str(self.root),'workspace':'h5_'+suffix,'db':'h5_'+suffix,'user':'h5_app_'+suffix,'port':8726,'db_port':6126}

    def test_own_binding_accepted(self):
        self.assertEqual(self.config,m.validate_workspace(self.root,self.config))

    def test_protected_runtime_ports_refused(self):
        for port in m.PROTECTED_PORTS:
            for key in ['port','db_port']:
                with self.subTest(port=port,key=key),self.assertRaises(ValueError):m.validate_workspace(self.root,{**self.config,key:port})

    def test_foreign_database_or_root_refused(self):
        for key,value in [('root','/other'),('db','shared_test'),('workspace','shared'),('user','shared')]:
            with self.subTest(key=key),self.assertRaises(ValueError):m.validate_workspace(self.root,{**self.config,key:value})

    def test_bad_port_types_or_equal_refused(self):
        for config in [{**self.config,'port':'8726'},{**self.config,'db_port':True},{**self.config,'port':1023},{**self.config,'db_port':8726}]:
            with self.assertRaises(ValueError):m.validate_workspace(self.root,config)

    def test_private_json_rejects_symlink_permissions_and_hardlink(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'fixture';p.write_text('{}');p.chmod(0o600);self.assertEqual({},m.private_json(p));p.chmod(0o644)
            with self.assertRaises(ValueError):m.private_json(p)
            p.chmod(0o600);q=Path(d)/'link';q.symlink_to(p)
            with self.assertRaises(ValueError):m.private_json(q)
            q.unlink();os.link(p,q)
            with self.assertRaises(ValueError):m.private_json(p)

    def test_env_forces_own_test_db_and_disables_transports(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'.runtime').mkdir();(root/'.runtime/pg-app-password').write_text('SYNTHETIC')
            env=m.testing_environment(root,self.config)
            self.assertEqual(self.config['db']+'_test',env['DB_DATABASE'])
            self.assertEqual(env['DB_DATABASE'],env['H5_TEST_DATABASE'])
            self.assertEqual('false',env['ACQUISITION_EXTERNAL_ENABLED']);self.assertEqual('disabled',env['ACQUISITION_TRANSPORT'])
            self.assertEqual('false',env['H5_BILLING_PAYPAL_SANDBOX_ENABLED']);self.assertEqual('',env['H5_BILLING_PAYPAL_SANDBOX_CLIENT_SECRET'])

if __name__=='__main__':unittest.main(verbosity=2)
