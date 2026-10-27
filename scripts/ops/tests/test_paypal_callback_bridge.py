import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
from pathlib import Path
import socket
import threading
import unittest

spec = importlib.util.spec_from_file_location('bridge', Path(__file__).resolve().parents[1] / 'paypal_callback_bridge.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class Recorder(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        data = self.rfile.read(int(self.headers['Content-Length']))
        self.server.received.append((self.path, dict(self.headers.items()), data))
        self.send_response(self.server.status)
        self.send_header('Set-Cookie', 'private=DO_NOT_FORWARD')
        self.send_header('Location', 'http://127.0.0.1:1/private')
        self.end_headers()
        try:
            self.wfile.write(b'PRIVATE_UPSTREAM_DIAGNOSTIC')
        except OSError:
            pass


class BridgeBoundaryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.upstream = ThreadingHTTPServer(('127.0.0.1', 0), Recorder)
        cls.upstream.status = 200
        cls.upstream.received = []
        cls.upstream_thread = threading.Thread(target=cls.upstream.serve_forever, daemon=True)
        cls.upstream_thread.start()
        cls.bridge = module.Bridge(0, cls.upstream.server_port)
        cls.bridge_thread = threading.Thread(target=cls.bridge.serve_forever, daemon=True)
        cls.bridge_thread.start()

    @classmethod
    def tearDownClass(cls):
        for server, thread in ((cls.bridge, cls.bridge_thread), (cls.upstream, cls.upstream_thread)):
            server.shutdown()
            server.server_close()
            thread.join()

    def setUp(self):
        self.upstream.status = 200
        self.upstream.received.clear()
        self.headers = {k: 'SYNTHETIC_ONLY' for k in module.PAYPAL_HEADERS}
        self.headers.update({'Content-Type': 'application/json', 'Host': self.bridge.expected_host})
        self.body = b'{ "id": "SYNTHETIC", "amount": 1.00, "unicode":"\xe6\xb5\x8b" }\n'

    def request(self, path=module.CALLBACK, method='POST', headers=None, body=None):
        connection = http.client.HTTPConnection('127.0.0.1', self.bridge.server_port, timeout=15)
        connection.request(method, path, self.body if body is None else body,
                           self.headers if headers is None else headers)
        response = connection.getresponse()
        result = response.status, dict(response.getheaders()), response.read()
        connection.close()
        return result

    def raw(self, data):
        with socket.create_connection(('127.0.0.1', self.bridge.server_port), timeout=3) as client:
            client.sendall(data)
            client.shutdown(socket.SHUT_WR)
            response = b''
            while True:
                part = client.recv(4096)
                if not part:
                    return response
                response += part

    def test_01_exact_path_and_byte_preservation(self):
        self.assertEqual(200, self.request()[0])
        path, headers, body = self.upstream.received[-1]
        self.assertEqual(module.CALLBACK, path)
        self.assertEqual(self.body, body)
        self.assertEqual(f'127.0.0.1:{self.upstream.server_port}', headers['Host'])
        self.assertEqual(set(module.PAYPAL_HEADERS) | {'host', 'content-type', 'content-length'},
                         {key.lower() for key in headers})

    def test_02_all_private_paths_and_variants_are_rejected(self):
        for path in ('/', '/admin', '/admin/login', '/livewire/update', '/media/a.ts',
                     '/.env', '/.runtime/paypal-sandbox/credentials.json', '/webhooks/stripe',
                     '/webhooks/paypal/', '/webhooks/paypal?target=/admin',
                     '/webhooks/%70aypal', '//webhooks/paypal', '/webhooks/../webhooks/paypal',
                     'http://127.0.0.1:8406/webhooks/paypal'):
            self.assertEqual(404, self.request(path=path)[0], path)
        self.assertEqual([], self.upstream.received)

    def test_03_every_other_method_is_rejected(self):
        for method in ('GET', 'HEAD', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE', 'CONNECT', 'PROPFIND'):
            self.assertEqual(404, self.request(method=method)[0], method)
        self.assertEqual([], self.upstream.received)

    def test_04_host_rebinding_is_rejected(self):
        self.assertEqual(403, self.request(headers={**self.headers, 'Host': 'evil.example'})[0])
        self.assertEqual([], self.upstream.received)

    def test_05_credentials_are_rejected_not_forwarded(self):
        for name in ('Cookie', 'Authorization', 'Proxy-Authorization'):
            self.assertEqual(403, self.request(headers={**self.headers, name: 'SYNTHETIC_PRIVATE'})[0])
        self.assertEqual([], self.upstream.received)

    def test_06_proxy_identity_and_arbitrary_targets_are_not_forwarded(self):
        additions = {'Forwarded': 'for=1.2.3.4;proto=https;host=evil.example',
                     'X-Forwarded-For': '1.2.3.4', 'X-Forwarded-Proto': 'https',
                     'CF-Connecting-IP': '1.2.3.4', 'CF-IPCountry': 'US',
                     'X-Original-URL': '/admin', 'X-Target-URL': 'http://evil.example/',
                     'X-HTTP-Method-Override': 'DELETE', 'Origin': 'https://evil.example'}
        self.assertEqual(200, self.request(headers={**self.headers, **additions})[0])
        headers = self.upstream.received[-1][1]
        self.assertTrue(set(additions).isdisjoint(headers))
        self.assertEqual(module.CALLBACK, self.upstream.received[-1][0])

    def test_07_body_limits_include_boundary(self):
        self.assertEqual(200, self.request(body=b'x' * module.MAX_BODY)[0])
        self.assertEqual(413, self.request(body=b'x' * (module.MAX_BODY + 1))[0])
        self.assertEqual(413, self.request(body=b'')[0])
        self.assertEqual(1, len(self.upstream.received))

    def test_08_transfer_compression_expect_upgrade_are_rejected(self):
        for name, value in (('Transfer-Encoding', 'chunked'), ('Content-Encoding', 'gzip'),
                            ('Expect', '100-continue'), ('Upgrade', 'websocket')):
            self.assertEqual(400, self.request(headers={**self.headers, name: value})[0])
        self.assertEqual([], self.upstream.received)

    def test_09_missing_signature_headers_and_bad_types_are_rejected(self):
        for name in module.PAYPAL_HEADERS:
            headers = dict(self.headers)
            del headers[name]
            self.assertEqual(400, self.request(headers=headers)[0])
        self.assertEqual(415, self.request(headers={**self.headers, 'Content-Type': 'text/plain'})[0])
        self.assertEqual([], self.upstream.received)

    def test_10_duplicate_headers_and_short_body_are_rejected(self):
        prefix = f'POST /webhooks/paypal HTTP/1.1\r\nHost: {self.bridge.expected_host}\r\n'.encode()
        headers = b''.join(f'{k}: {v}\r\n'.encode() for k, v in self.headers.items() if k != 'Host')
        for extra in (b'Content-Length: 1\r\nContent-Length: 2\r\n',
                      b'Content-Length: 2\r\npaypal-transmission-id: duplicate\r\n',
                      b'Content-Length: 10\r\n'):
            response = self.raw(prefix + headers + extra + b'\r\n{}')
            self.assertIn(b' 400 ', response.split(b'\r\n')[0])
        self.assertEqual([], self.upstream.received)

    def test_11_application_errors_are_not_confirmed_or_leaked(self):
        for status in (400, 401, 403, 419, 429, 500, 503):
            self.upstream.status = status
            actual, headers, body = self.request()
            self.assertEqual(status, actual)
            self.assertEqual(b'', body)
            self.assertNotIn('Set-Cookie', headers)
            self.assertNotIn('Location', headers)

    def test_12_redirects_are_not_followed(self):
        self.upstream.status = 302
        self.assertEqual(502, self.request()[0])
        self.assertEqual(1, len(self.upstream.received))

    def test_13_unavailable_upstream_is_retryable(self):
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            port = reservation.getsockname()[1]
            old = self.bridge.upstream_port
            self.bridge.upstream_port = port
            try:
                self.assertEqual(503, self.request()[0])
            finally:
                self.bridge.upstream_port = old

    def test_14_oversize_header_is_rejected(self):
        self.assertEqual(431, self.request(headers={**self.headers, 'X-Padding': 'x' * 17000})[0])
        self.assertEqual([], self.upstream.received)

    def test_15_upstream_loop_and_occupied_listener_are_rejected(self):
        with self.assertRaises(OSError):
            module.Bridge(self.bridge.server_port, self.upstream.server_port)
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            free = s.getsockname()[1]
        with self.assertRaises(ValueError):
            module.Bridge(free, free)

    def test_16_saturated_bridge_returns_retryable_status(self):
        for _ in range(4):
            self.assertTrue(self.bridge.slots.acquire(timeout=2))
        try:
            with socket.create_connection(('127.0.0.1', self.bridge.server_port), timeout=3) as client:
                self.assertIn(b' 503 ', client.recv(1024))
        finally:
            for _ in range(4):
                self.bridge.slots.release()
        self.assertEqual([], self.upstream.received)


if __name__ == '__main__':
    unittest.main(verbosity=2)
