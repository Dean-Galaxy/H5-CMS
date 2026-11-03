"""Synthetic loopback tests in temporary roots; never start the operator entry point."""
import contextlib
import http.client
import importlib.util
import io
import json
from pathlib import Path
import socket
import stat
import sys
import tempfile
import threading
import unittest
from unittest.mock import patch
from urllib.parse import urlencode

OPS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(OPS))
spec = importlib.util.spec_from_file_location('live_credentials', OPS / 'paypal_live_credentials.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class LiveCredentialTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.root = Path(cls.temp.name)
        cls.server = module.Server(cls.root, 0)
        cls.worker = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.worker.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.worker.join(3)
        cls.temp.cleanup()

    def setUp(self):
        self.values = dict(client_id='SYNTHETIC_LIVE_CLIENT', client_secret='SYNTHETIC_LIVE_SECRET',
                           merchant_id='SYNTHETICMERCHANT', live_app_confirmed='yes')

    def request(self, method='POST', path='/save', values=None, headers=None, body=None, server=None):
        server = server or self.server
        data = {'csrf': server.csrf, **(self.values if values is None else values)}
        raw = urlencode(data) if body is None else body
        conn = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=3)
        conn.request(method, path, raw if method == 'POST' else None,
                     {'Host': server.origin[7:], 'Origin': server.origin,
                      'Content-Type': 'application/x-www-form-urlencoded', **(headers or {})})
        response = conn.getresponse()
        result = response.status, dict(response.getheaders()), response.read().decode()
        conn.close()
        return result

    def test_form_no_prefill_and_only_three_inputs_and_confirmation(self):
        module.save(self.root, self.values)
        status, headers, body = self.request('GET', '/')
        self.assertEqual(200, status)
        self.assertEqual(3, body.count('type="password"'))
        self.assertIn('name="live_app_confirmed" value="yes" required', body)
        self.assertNotIn('name="merchant_scope"', body)
        self.assertNotIn('name="product_id"', body)
        self.assertNotIn('name="webhook_id"', body)
        self.assertNotIn(self.values['client_secret'], body)
        self.assertNotIn(self.values['client_id'], body)
        self.assertIn('Live Dashboard', body)
        self.assertEqual('no-store', headers['Cache-Control'])
        self.assertIn("frame-ancestors 'none'", headers['Content-Security-Policy'])
        self.assertIn("form-action 'self'", headers['Content-Security-Policy'])

    def test_save_fixes_live_scope_and_writes_private_file_only(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            sandbox = root / '.runtime/paypal-sandbox/credentials.json'
            sandbox.parent.mkdir(parents=True, mode=0o700)
            sandbox.write_text('SYNTHETIC_SANDBOX_UNTOUCHED')
            env = root / 'h5-platform/.env'
            env.parent.mkdir()
            env.write_text('SYNTHETIC_ENV_UNTOUCHED')
            module.save(root, self.values)
            target = root / '.runtime/paypal-live/credentials.json'
            doc = json.loads(target.read_text())
            self.assertEqual('live', doc['environment'])
            self.assertEqual(str(root), doc['workspace_root'])
            self.assertEqual('offline_shape_only', doc['validation'])
            self.assertEqual(module.MERCHANT_SCOPE, doc['credentials']['merchant_scope'])
            self.assertEqual('', doc['credentials']['product_id'])
            self.assertEqual('', doc['credentials']['webhook_id'])
            self.assertTrue(doc['operator_confirmation']['same_app_and_merchant_confirmed'])
            self.assertNotIn('enabled', doc)
            self.assertEqual('SYNTHETIC_SANDBOX_UNTOUCHED', sandbox.read_text())
            self.assertEqual('SYNTHETIC_ENV_UNTOUCHED', env.read_text())
            self.assertEqual(0o600, stat.S_IMODE(target.stat().st_mode))
            self.assertEqual(0o700, stat.S_IMODE(target.parent.stat().st_mode))
            self.assertEqual({sandbox, env, target}, {p for p in root.rglob('*') if p.is_file()})

    def test_save_does_not_log_or_echo_any_credentials(self):
        logs = io.StringIO()
        with contextlib.redirect_stdout(logs), contextlib.redirect_stderr(logs):
            status, _, body = self.request()
        self.assertEqual(200, status)
        self.assertEqual('', logs.getvalue())
        for key in ('client_id', 'client_secret', 'merchant_id'):
            self.assertNotIn(self.values[key], body)
        self.assertIn('未连接 PayPal', body)

    def test_missing_confirmation_or_merchant_preserves_previous(self):
        module.save(self.root, self.values)
        target = self.root / '.runtime/paypal-live/credentials.json'
        before = target.read_bytes()
        for values in ({k: v for k, v in self.values.items() if k != 'live_app_confirmed'},
                       {**self.values, 'live_app_confirmed': 'no'},
                       {**self.values, 'merchant_id': ''}, {**self.values, 'merchant_id': 'bad@merchant'},
                       {**self.values, 'client_secret': ''}, {**self.values, 'client_secret': 'bad\nsecret'}):
            with self.subTest(fields=list(values)):
                self.assertEqual(400, self.request(values=values)[0])
                self.assertEqual(before, target.read_bytes())

    def test_environment_scope_path_and_enable_injection_rejected(self):
        for key, value in [('environment', 'sandbox'), ('merchant_scope', 'sandbox'),
                           ('product_id', 'PROD-OTHER'), ('webhook_id', 'OTHER'),
                           ('enabled', 'true'), ('destination', '../paypal-sandbox/credentials.json')]:
            self.assertEqual(400, self.request(values={**self.values, key: value})[0])

    def test_host_and_origin_checks(self):
        for host in ('evil.example', 'localhost', self.server.origin[7:] + '.evil'):
            self.assertEqual(403, self.request('GET', '/', headers={'Host': host})[0])
        for origin in ('https://evil.example', 'null', self.server.origin + '.evil'):
            self.assertEqual(403, self.request(headers={'Origin': origin})[0])

    def test_missing_duplicate_host_origin_and_content_length_rejected(self):
        for header in ('Host', 'Origin', 'Content-Length'):
            conn = http.client.HTTPConnection('127.0.0.1', self.server.server_port, timeout=3)
            conn.putrequest('POST', '/save', skip_host=True)
            hs = [('Host', self.server.origin[7:]), ('Origin', self.server.origin),
                  ('Content-Length', '0'), ('Content-Type', 'application/x-www-form-urlencoded')]
            for k, v in hs:
                conn.putheader(k, v)
                if k == header:
                    conn.putheader(k, v)
            conn.endheaders()
            res = conn.getresponse()
            self.assertIn(res.status, (400, 403))
            res.read()
            conn.close()
        conn = http.client.HTTPConnection('127.0.0.1', self.server.server_port, timeout=3)
        conn.putrequest('POST', '/save', skip_host=True)
        conn.putheader('Content-Length', '0')
        conn.endheaders()
        res = conn.getresponse()
        self.assertEqual(403, res.status)
        res.read()
        conn.close()

    def test_csrf_and_duplicate_fields_rejected(self):
        for body in (urlencode(self.values), urlencode({'csrf': 'bad', **self.values}),
                     urlencode({'csrf': '非ASCII', **self.values}),
                     urlencode({'csrf': self.server.csrf, **self.values}) + '&csrf=x',
                     urlencode({'csrf': self.server.csrf, **self.values}) + '&merchant_id=OTHER'):
            self.assertEqual(403, self.request(body=body)[0])

    def test_body_type_size_and_transfer_encoding_rejected(self):
        self.assertEqual(413, self.request(body='x' * 32769)[0])
        self.assertEqual(415, self.request(headers={'Content-Type': 'application/json'})[0])
        self.assertEqual(400, self.request(headers={'Transfer-Encoding': 'chunked'})[0])

    def test_no_file_query_or_other_path_served(self):
        for path in ('/.runtime/paypal-live/credentials.json', '/?secret=anything', '/save', '/admin', '/../'):
            self.assertEqual(404, self.request('GET', path)[0])

    def test_symlink_runtime_live_directory_and_destination_rejected(self):
        for component in ('runtime', 'live', 'file'):
            with self.subTest(component=component), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                outside = root / 'outside'
                outside.mkdir(mode=0o755)
                victim = outside / 'victim'
                victim.write_text('unchanged')
                if component == 'runtime':
                    (root / '.runtime').symlink_to(outside, target_is_directory=True)
                else:
                    (root / '.runtime').mkdir(mode=0o700)
                    if component == 'live':
                        (root / '.runtime/paypal-live').symlink_to(outside, target_is_directory=True)
                    else:
                        (root / '.runtime/paypal-live').mkdir(mode=0o700)
                        (root / '.runtime/paypal-live/credentials.json').symlink_to(victim)
                with self.assertRaises(ValueError):
                    module.save(root, self.values)
                self.assertEqual('unchanged', victim.read_text())
                self.assertEqual(0o755, stat.S_IMODE(outside.stat().st_mode))
                self.assertEqual([victim], list(outside.iterdir()))

    def test_failed_atomic_replace_preserves_previous_and_cleans_temp(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            module.save(root, self.values)
            target = root / '.runtime/paypal-live/credentials.json'
            before = target.read_bytes()
            with patch.object(module.os, 'replace', side_effect=OSError('synthetic')):
                with self.assertRaises(OSError):
                    module.save(root, {**self.values, 'client_secret': 'SYNTHETIC_CHANGED'})
            self.assertEqual(before, target.read_bytes())
            self.assertEqual([target], list(target.parent.iterdir()))

    def test_workspace_binding_rejects_missing_foreign_and_symlink(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            with self.assertRaises(ValueError):
                module.check_workspace(root)
            (root / '.runtime').mkdir()
            config = root / '.runtime/workspace.json'
            for document in ([], {}, {'root': '/another/worktree'}):
                config.write_text(json.dumps(document))
                with self.assertRaises(ValueError):
                    module.check_workspace(root)
            config.write_text(json.dumps({'root': str(root)}))
            module.check_workspace(root)
            config.rename(root / 'foreign.json')
            config.symlink_to(root / 'foreign.json')
            with self.assertRaises(ValueError):
                module.check_workspace(root)

    def test_listener_is_loopback_and_existing_port_is_not_replaced(self):
        self.assertEqual('127.0.0.1', self.server.server_address[0])
        with self.assertRaises(OSError):
            module.Server(self.root, self.server.server_port)

    def test_synthetic_post_makes_only_loopback_connections(self):
        original = socket.socket.connect
        connections = []
        def loopback_only(sock, address):
            if address[0] != '127.0.0.1':
                raise AssertionError('outbound connection forbidden')
            connections.append(address)
            return original(sock, address)
        with patch.object(socket.socket, 'connect', loopback_only):
            self.assertEqual(200, self.request()[0])
        self.assertEqual(1, len(connections))

    def test_stop_requires_csrf_and_only_stops_own_server(self):
        server = module.Server(self.root, 0)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            self.assertEqual(403, self.request('POST', '/stop',
                             body=urlencode({'csrf': self.server.csrf}), server=server)[0])
            self.assertEqual(200, self.request('POST', '/stop', values={}, server=server)[0])
            worker.join(3)
            self.assertFalse(worker.is_alive())
            self.assertEqual(200, self.request('GET', '/')[0])
        finally:
            server.shutdown()
            server.server_close()
            worker.join(3)


if __name__ == '__main__':
    unittest.main(verbosity=2)
