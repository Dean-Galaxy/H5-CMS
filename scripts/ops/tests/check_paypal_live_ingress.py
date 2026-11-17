#!/usr/bin/env python3
"""Native loopback Nginx against a synthetic FastCGI receiver. No provider or application I/O."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
from hashlib import sha256
from http.client import HTTPConnection
import json
from pathlib import Path
import socket
import socketserver
import struct
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[3] / 'deploy/nginx'
PATH = '/webhooks/paypal/live'
HOST = 'fixture.invalid'
SIGNATURE_HEADERS = {
    'PayPal-Auth-Algo': 'SHA256withRSA',
    'PayPal-Cert-Url': 'https://api.paypal.com/v1/notifications/certs/CERT-FIXTURE',
    'PayPal-Transmission-Id': 'fixture-transmission',
    'PayPal-Transmission-Sig': 'Zml4dHVyZQ==',
    'PayPal-Transmission-Time': '2026-11-16T12:00:00Z',
}


def decode_params(payload):
    position = 0

    def size():
        nonlocal position
        value = payload[position]
        if value & 128:
            value = struct.unpack_from('!I', payload, position)[0] & 0x7fffffff
            position += 4
        else:
            position += 1
        return value

    result = {}
    while position < len(payload):
        key_size, value_size = size(), size()
        key = payload[position:position + key_size].decode()
        position += key_size
        result[key] = payload[position:position + value_size].decode()
        position += value_size
    return result


class Receiver(socketserver.StreamRequestHandler):
    def handle(self):
        params = bytearray()
        body = bytearray()
        while header := self.rfile.read(8):
            _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', header)
            data = self.rfile.read(size)
            self.rfile.read(padding)
            if kind == 4:
                params.extend(data)
            if kind == 5:
                body.extend(data)
                if not size:
                    break
        if body == b'hold':
            self.server.waiting.release()
            if not self.server.release.wait(5):
                return
        response = b'Status: 200 OK\r\nContent-Type: application/json\r\n\r\n'
        response += json.dumps({'body_sha256': sha256(body).hexdigest(),
                                'params': decode_params(params)}).encode()
        for kind, data in ((6, response), (6, b''), (3, b'\x00' * 8)):
            self.wfile.write(struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data)


def run(nginx):
    with tempfile.TemporaryDirectory(prefix='h5-live-ingress-') as tmp:
        area = Path(tmp)
        with socket.socket() as reserved:
            reserved.bind(('127.0.0.1', 0))
            port = reserved.getsockname()[1]
        fcgi = area / 'fixture.sock'
        names = {name: 'HTTP_' + name.upper().replace('-', '_') for name in SIGNATURE_HEADERS}
        parameters = '\n'.join(f'fastcgi_param {value} ${value.lower()};' for value in names.values())
        cfg = area / 'nginx.conf'
        cfg.write_text(f"""pid {area}/nginx.pid;
error_log {area}/error.log;
events {{}}
http {{
    access_log off;
    include "{ROOT}/production-paypal-live-zones.conf";
    server {{
        listen 127.0.0.1:{port};
        server_name {HOST};
        location = {PATH} {{
            include "{ROOT}/production-paypal-live-guard.conf";
            fastcgi_pass unix:{fcgi};
            fastcgi_pass_request_headers off;
            fastcgi_param REQUEST_METHOD $request_method;
            fastcgi_param REQUEST_URI $request_uri;
            fastcgi_param CONTENT_TYPE $content_type;
            fastcgi_param CONTENT_LENGTH $content_length;
            {parameters}
        }}
        location = /health {{ return 200; }}
        location / {{ return 503; }}
    }}
}}
""")
        command = [nginx, '-p', tmp, '-c', str(cfg)]
        subprocess.run([*command, '-t'], check=True, capture_output=True)
        with socketserver.ThreadingUnixStreamServer(str(fcgi), Receiver) as server:
            server.waiting = threading.Semaphore(0)
            server.release = threading.Event()
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            process = subprocess.Popen([*command, '-g', 'daemon off;'], stdout=subprocess.DEVNULL,
                                       stderr=subprocess.DEVNULL)

            def request(method='POST', path=PATH, body=b'{}', headers=None):
                with closing(HTTPConnection('127.0.0.1', port, timeout=6)) as connection:
                    headers = {**SIGNATURE_HEADERS, **(headers or {})}
                    connection.request(method, path, body, {'Host': HOST, 'Content-Type': 'application/json', **headers})
                    response = connection.getresponse()
                    return response.status, response.read()

            try:
                for _ in range(50):
                    try:
                        if request('GET', '/health')[0] == 200:
                            break
                    except OSError:
                        time.sleep(0.03)
                else:
                    raise AssertionError('local Nginx unavailable')
                assert request('GET')[0] == 405
                assert request(path=PATH + '?unexpected=1')[0] == 404
                assert request(path=PATH + '/')[0] == 503
                assert request(path='/webhooks/paypal')[0] == 503
                assert request(path='/webhooks/stripe')[0] == 503
                for header in SIGNATURE_HEADERS:
                    assert request(headers={header: ''})[0] == 400, header
                assert request(body=b'x' * (262144 + 1))[0] == 413
                raw = b'{ "id": "synthetic", "payload": "unchanged" }\n'
                for body in (raw, raw, b'x' * 262144):
                    status, response = request(body=body, headers={'X-Forwarded-For': '192.0.2.8'})
                    observed = json.loads(response)
                    assert status == 200 and observed['body_sha256'] == sha256(body).hexdigest()
                    assert observed['params'] == {'REQUEST_METHOD': 'POST', 'REQUEST_URI': PATH,
                        'CONTENT_TYPE': 'application/json', 'CONTENT_LENGTH': str(len(body)),
                        **{names[name]: value for name, value in SIGNATURE_HEADERS.items()}}
                with ThreadPoolExecutor(2) as pool:
                    workers = [pool.submit(request, body=b'hold') for _ in range(2)]
                    try:
                        assert server.waiting.acquire(timeout=4) and server.waiting.acquire(timeout=4)
                        assert request()[0] == 429
                        assert request('GET', '/health')[0] == 200
                    finally:
                        server.release.set()
                    assert all(worker.result()[0] == 200 for worker in workers)
                results = [request(headers={'X-Forwarded-For': f'192.0.2.{i}'})[0] for i in range(1, 17)]
                assert 429 in results and set(results) <= {200, 429}
                time.sleep((8 + 1) / 2)
                assert request(body=raw)[0] == 200
            finally:
                server.release.set()
                process.terminate()
                process.wait(timeout=5)
                server.shutdown()
                thread.join(timeout=5)
    return {'method_path_five_headers': True, 'raw_body_max_bytes': 262144,
            'concurrency_and_rate_429': True, 'retry_after_budget_refills': True,
            'other_routes_unchanged': True, 'upstream': 'synthetic FastCGI'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--nginx', required=True)
    print(json.dumps(run(parser.parse_args().nginx)))
