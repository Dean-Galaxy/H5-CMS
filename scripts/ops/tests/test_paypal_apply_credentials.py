import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest

OPS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(OPS))
spec = importlib.util.spec_from_file_location('apply_credentials', OPS / 'paypal_apply_credentials.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
AUTOLOAD = Path(os.environ.get('H5_TEST_DOTENV_AUTOLOAD', str(OPS.parents[1] / 'h5-platform/vendor/autoload.php')))


@unittest.skipUnless(AUTOLOAD.is_file(), 'Candidate vlucas/phpdotenv must be installed; no substitute parser')
class ApplyBoundaryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.runtime = self.root / '.runtime'
        self.runtime.mkdir(mode=0o700)
        self.directory = self.runtime / 'paypal-sandbox'
        self.directory.mkdir(mode=0o700)
        (self.root / 'h5-platform').mkdir(mode=0o700)
        self.env = self.root / 'h5-platform/.env'
        self.config = {'root': str(self.root), 'workspace': 'h5_synthetic', 'port': 8406,
                       'db_port': 5906, 'db': 'h5_synthetic', 'user': 'h5_app_synthetic'}
        self.values = dict(client_id='SYNTHETIC_CLIENT', client_secret='synthetic"$${APP_KEY}\\#\'!=',
                           merchant_scope='h5_us_sandbox', merchant_id='', product_id='', webhook_id='')
        self.private(self.runtime / 'workspace.json', json.dumps(self.config))
        self.save_credentials()
        source = {'APP_ENV': 'local', 'APP_URL': 'http://127.0.0.1:8406', 'H5_WORKSPACE': 'h5_synthetic',
                  'DB_HOST': '127.0.0.1', 'DB_PORT': '5906', 'DB_DATABASE': 'h5_synthetic',
                  'DB_USERNAME': 'h5_app_synthetic', 'SESSION_COOKIE': 'h5_synthetic',
                  'CACHE_PREFIX': 'h5_synthetic', 'DB_QUEUE': 'h5_synthetic', 'APP_KEY': 'UNCHANGED_KEY',
                  'H5_BILLING_DRIVER': 'unconfigured'}
        self.private(self.env, '# Keep unrelated values\n' + ''.join(k + '=' + module.quote(v) + '\n' for k, v in source.items()))
        self.original = self.env.read_bytes()

    def tearDown(self):
        self.temp.cleanup()

    def private(self, path, contents):
        path.write_text(contents)
        path.chmod(0o600)

    def save_credentials(self, **changes):
        doc = {'schema': 1, 'environment': 'sandbox', 'workspace_root': str(self.root),
               'credentials': self.values}
        doc.update(changes)
        self.private(self.directory / 'credentials.json', json.dumps(doc))

    def prepare(self):
        return module.prepare(self.root, AUTOLOAD)

    def apply(self):
        module.apply(self.root, AUTOLOAD)

    def append(self, source):
        with self.env.open('a') as stream:
            stream.write(source + '\n')

    def test_01_check_does_not_write(self):
        self.prepare()
        self.assertEqual(self.original, self.env.read_bytes())
        self.assertEqual([], list(self.directory.glob('env-before-*')))

    def test_02_real_dotenv_roundtrip_special_characters_and_disabled_defaults(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
            self.apply()
        parsed = module.parse_env(self.env.read_text(), AUTOLOAD)
        self.assertEqual(self.values['client_secret'], parsed[module.PREFIX + 'CLIENT_SECRET'])
        self.assertEqual('false', parsed[module.PREFIX + 'ENABLED'])
        self.assertEqual('unconfigured', parsed['H5_BILLING_NEW_PURCHASE_PROVIDER'])
        self.assertEqual('UNCHANGED_KEY', parsed['APP_KEY'])
        self.assertEqual('', output.getvalue())
        self.assertEqual(0o600, stat.S_IMODE(self.env.stat().st_mode))
        backup = list(self.directory.glob('env-before-*'))
        self.assertEqual(1, len(backup))
        self.assertEqual(self.original, backup[0].read_bytes())
        self.assertEqual(0o600, stat.S_IMODE(backup[0].stat().st_mode))

    def test_03_workspace_file_binding_is_enforced(self):
        self.save_credentials(workspace_root='/some/other/worktree')
        with self.assertRaises(ValueError): self.apply()
        self.assertEqual(self.original, self.env.read_bytes())

    def test_04_database_and_url_binding_are_enforced(self):
        for old, new in (('5906', '5877'), ('8406', '8377'), ('h5_synthetic', 'h5_someone_else')):
            self.private(self.env, self.original.decode().replace(old, new))
            before = self.env.read_bytes()
            with self.assertRaises(ValueError): self.apply()
            self.assertEqual(before, self.env.read_bytes())

    def test_05_scope_client_and_merchant_cannot_change(self):
        for field in ('merchant_scope', 'client_id', 'merchant_id'):
            self.private(self.env, self.original.decode())
            self.append(module.PREFIX + field.upper() + '="already_bound"')
            self.values[field] = 'new_binding'
            self.save_credentials()
            before = self.env.read_bytes()
            with self.assertRaises(ValueError): self.apply()
            self.assertEqual(before, self.env.read_bytes())

    def test_06_blank_optional_fields_preserve_existing_ids(self):
        self.append(module.PREFIX + 'PRODUCT_ID="PROD-EXISTING"')
        self.append(module.PREFIX + 'WEBHOOK_ID="WH-EXISTING"')
        self.apply()
        parsed = module.parse_env(self.env.read_text(), AUTOLOAD)
        self.assertEqual('PROD-EXISTING', parsed[module.PREFIX + 'PRODUCT_ID'])
        self.assertEqual('WH-EXISTING', parsed[module.PREFIX + 'WEBHOOK_ID'])

    def test_07_active_payment_configuration_is_not_overwritten(self):
        for line in (module.PREFIX + 'ENABLED=true', 'H5_BILLING_NEW_PURCHASE_PROVIDER=paypal',
                     'H5_BILLING_NEW_PURCHASE_PROVIDER=stripe'):
            self.private(self.env, self.original.decode())
            self.append(line)
            before = self.env.read_bytes()
            with self.assertRaises(ValueError): self.apply()
            self.assertEqual(before, self.env.read_bytes())

    def test_08_malformed_or_duplicate_env_is_not_rewritten_or_logged(self):
        for extra in ('APP_ENV=local', 'BAD="unterminated_SYNTHETIC_SECRET'):
            self.private(self.env, self.original.decode())
            self.append(extra)
            before = self.env.read_bytes()
            output = io.StringIO()
            with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
                with self.assertRaisesRegex(ValueError, 'ENV_PARSE_FAILED'): self.apply()
            self.assertEqual('', output.getvalue())
            self.assertEqual(before, self.env.read_bytes())

    def test_09_symlink_env_is_not_followed(self):
        target = self.root / 'victim'
        self.private(target, self.env.read_text())
        self.env.unlink()
        self.env.symlink_to(target)
        with self.assertRaises(OSError): self.apply()
        self.assertEqual(self.original, target.read_bytes())

    def test_10_publicly_readable_credentials_are_rejected(self):
        (self.directory / 'credentials.json').chmod(0o644)
        with self.assertRaises(ValueError): self.apply()
        self.assertEqual(self.original, self.env.read_bytes())

    def test_11_running_process_or_config_cache_requires_stop(self):
        pids = self.runtime / 'pids'
        pids.mkdir()
        marker = pids / 'queue.json'
        marker.write_text('{}')
        with self.assertRaises(ValueError): self.apply()
        marker.unlink()
        cache = self.root / 'h5-platform/bootstrap/cache'
        cache.mkdir(parents=True)
        (cache / 'config.php').write_text('<?php return [];')
        with self.assertRaises(ValueError): self.apply()
        self.assertEqual(self.original, self.env.read_bytes())

    def test_12_optional_field_completion_retains_merchant_binding(self):
        self.apply()
        self.values['product_id'] = 'PROD-SYNTHETIC'
        self.values['webhook_id'] = 'WH-SYNTHETIC'
        self.save_credentials()
        self.apply()
        parsed = module.parse_env(self.env.read_text(), AUTOLOAD)
        for field in self.values:
            self.assertEqual(self.values[field], parsed[module.PREFIX + field.upper()])
        self.assertEqual('false', parsed[module.PREFIX + 'ENABLED'])

    def test_13_quote_roundtrip_all_printable_special_characters(self):
        value = ''.join(chr(i) for i in range(33, 127)) + '${APP_KEY}'
        self.assertEqual(value, module.parse_env('VALUE=' + module.quote(value), AUTOLOAD)['VALUE'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
