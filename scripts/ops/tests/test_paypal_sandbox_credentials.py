import contextlib
import http.client
import importlib.util
import io
import json
from pathlib import Path
import re
import stat
import tempfile
import threading
import unittest
from urllib.parse import urlencode

SCRIPT = Path(__file__).resolve().parents[1] / 'paypal_sandbox_credentials.py'
spec = importlib.util.spec_from_file_location('credentials', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class CredentialBoundaryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.root = Path(cls.temp.name)
        cls.server = module.Server(cls.root, 0)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()
        cls.temp.cleanup()

    def setUp(self):
        self.values = dict(client_id='SYNTHETIC_CLIENT', client_secret='SYNTHETIC_SECRET_06',
                           merchant_id='', merchant_scope='h5_us_sandbox', product_id='', webhook_id='')

    def request(self, method='POST', path='/save', values=None, headers=None, body=None):
        data = {'csrf': self.server.csrf, **(self.values if values is None else values)}
        raw = urlencode(data) if body is None else body
        h = {'Host': self.server.origin.removeprefix('http://'), 'Origin': self.server.origin,
             'Content-Type': 'application/x-www-form-urlencoded'}
        h.update(headers or {})
        connection = http.client.HTTPConnection('127.0.0.1', self.server.server_port, timeout=3)
        connection.request(method, path, raw if method == 'POST' else None, h)
        response = connection.getresponse()
        result = response.status, dict(response.getheaders()), response.read().decode()
        connection.close()
        return result

    def test_01_local_form_is_private_and_does_not_reflect_saved_secret(self):
        module.save(self.root, self.values)
        status, headers, body = self.request('GET', path='/')
        self.assertEqual(200, status)
        self.assertIn('no-store', headers['Cache-Control'])
        self.assertIn("frame-ancestors 'none'", headers['Content-Security-Policy'])
        self.assertNotIn(self.values['client_secret'], body)
        self.assertNotIn(self.values['client_id'], body)
        self.assertEqual(6, body.count('type="password"'))

    def test_02_valid_submission_writes_private_file_only(self):
        logs = io.StringIO()
        with contextlib.redirect_stderr(logs), contextlib.redirect_stdout(logs):
            status, _, body = self.request()
        self.assertEqual(200, status)
        self.assertEqual('', logs.getvalue())
        self.assertNotIn(self.values['client_secret'], body)
        path = self.root / '.runtime/paypal-sandbox/credentials.json'
        doc = json.loads(path.read_text())
        self.assertEqual('offline_shape_only', doc['validation'])
        self.assertEqual(self.values, doc['credentials'])
        self.assertEqual(0o600, stat.S_IMODE(path.stat().st_mode))
        self.assertEqual(0o700, stat.S_IMODE(path.parent.stat().st_mode))
        self.assertFalse((self.root / 'h5-platform/.env').exists())

    def test_03_rebinding_host_is_rejected(self):
        self.assertEqual(403, self.request('GET', headers={'Host': 'attacker.example'})[0])

    def test_04_cross_origin_and_null_origin_are_rejected(self):
        for origin in ('https://attacker.example', 'null', self.server.origin + '.evil'):
            self.assertEqual(403, self.request(headers={'Origin': origin})[0])

    def test_05_wrong_missing_and_duplicate_csrf_are_rejected(self):
        for body in (urlencode(self.values), urlencode({'csrf': 'wrong', **self.values}),
                     urlencode({'csrf': '非ASCII', **self.values}),
                     urlencode({'csrf': self.server.csrf, **self.values}) + '&csrf=extra'):
            self.assertEqual(403, self.request(body=body)[0])

    def test_06_oversize_content_and_bad_type_are_rejected(self):
        self.assertEqual(413, self.request(body='x' * 32769)[0])
        self.assertEqual(415, self.request(headers={'Content-Type': 'application/json'})[0])

    def test_07_unknown_fields_or_empty_client_do_not_replace_saved_file(self):
        module.save(self.root, self.values)
        path = self.root / '.runtime/paypal-sandbox/credentials.json'
        before = path.read_bytes()
        for values in ({**self.values, 'api_host': 'https://evil.example'},
                       {**self.values, 'client_secret': ''},
                       {**self.values, 'merchant_scope': 'UPPERCASE'},
                       {**self.values, 'client_secret': 'line\nbreak'},
                       {**self.values, 'webhook_id': 'WEBHOOK_ID'}):
            self.assertEqual(400, self.request(values=values)[0])
            self.assertEqual(before, path.read_bytes())

    def test_08_no_file_or_path_is_served(self):
        for path in ('/.runtime/paypal-sandbox/credentials.json', '/save', '/?secret=x', '/admin'):
            self.assertEqual(404, self.request('GET', path=path)[0])

    def test_09_symlink_directory_is_refused_without_touching_target(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            outside = root / 'outside'
            outside.mkdir(mode=0o755)
            (root / '.runtime').symlink_to(outside, target_is_directory=True)
            with self.assertRaises(ValueError):
                module.save(root, self.values)
            self.assertEqual([], list(outside.iterdir()))
            self.assertEqual(0o755, stat.S_IMODE(outside.stat().st_mode))

    def test_10_symlink_destination_is_refused_without_touching_target(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            directory = root / '.runtime/paypal-sandbox'
            directory.mkdir(parents=True)
            victim = root / 'victim'
            victim.write_text('unchanged')
            (directory / 'credentials.json').symlink_to(victim)
            with self.assertRaises(ValueError):
                module.save(root, self.values)
            self.assertEqual('unchanged', victim.read_text())

    def test_11_duplicate_fields_and_transfer_encoding_are_rejected(self):
        body = urlencode({'csrf': self.server.csrf, **self.values}) + '&client_id=extra'
        self.assertEqual(403, self.request(body=body)[0])
        self.assertEqual(400, self.request(headers={'Transfer-Encoding': 'chunked'})[0])

    def test_12_stop_requires_csrf_and_shuts_listener(self):
        server = module.Server(self.root, 0)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        connection = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=3)
        connection.request('POST', '/stop', urlencode({'csrf': server.csrf}),
                           {'Origin': server.origin, 'Content-Type': 'application/x-www-form-urlencoded'})
        response = connection.getresponse()
        self.assertEqual(200, response.status)
        response.read()
        connection.close()
        worker.join(2)
        self.assertFalse(worker.is_alive())
        server.server_close()


if __name__ == '__main__':
    unittest.main(verbosity=2)
