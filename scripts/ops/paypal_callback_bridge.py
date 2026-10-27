#!/usr/bin/env python3
"""Review candidate: loopback-only, one-way PayPal webhook bridge. No tunnel launcher."""
import argparse
import http.client
import json
from pathlib import Path
import socket
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[2]
CALLBACK = '/webhooks/paypal'
MAX_BODY = 256 * 1024
PAYPAL_HEADERS = ('paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id',
                  'paypal-transmission-sig', 'paypal-transmission-time')


class Bridge(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, port, upstream_port):
        if not isinstance(upstream_port, int) or not 1024 <= upstream_port <= 65535:
            raise ValueError('UPSTREAM_PORT_INVALID')
        self.upstream_port = upstream_port
        self.slots = threading.BoundedSemaphore(4)
        super().__init__(('127.0.0.1', port), Handler)
        if self.server_port == upstream_port:
            self.server_close()
            raise ValueError('UPSTREAM_LOOP_FORBIDDEN')
        self.expected_host = f'127.0.0.1:{self.server_port}'

    def process_request(self, request, client_address):
        if not self.slots.acquire(blocking=False):
            try:
                request.settimeout(1)
                request.sendall(b'HTTP/1.0 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
            except OSError:
                pass
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except Exception:
            self.slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()

    def handle_error(self, request, client_address):
        pass  # Never log a raw request or upstream exception.


class Handler(BaseHTTPRequestHandler):
    server_version = 'CallbackBridge'
    sys_version = ''

    def setup(self):
        super().setup()
        self.connection.settimeout(5)
        self.deadline = threading.Timer(20, self.expire)
        self.deadline.daemon = True
        self.deadline.start()

    def expire(self):
        try:
            self.connection.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass

    def finish(self):
        self.deadline.cancel()
        super().finish()

    def log_message(self, *args):
        pass

    def reply(self, code):
        self.send_response(code)
        self.send_header('Content-Length', '0')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Connection', 'close')
        self.end_headers()
        self.close_connection = True

    def send_error(self, code, message=None, explain=None):
        # No reflected request target, method, HTML or upstream body.
        self.reply(404 if code == 501 else code)

    def do_GET(self):
        self.reply(404)

    do_HEAD = do_GET
    do_PUT = do_GET
    do_DELETE = do_GET
    do_PATCH = do_GET
    do_OPTIONS = do_GET
    do_TRACE = do_GET
    do_CONNECT = do_GET

    def do_POST(self):
        # BaseHTTPRequestHandler normalizes leading //; inspect original target too.
        if self.path != CALLBACK or self.requestline.split()[1] != CALLBACK:
            return self.reply(404)
        if self.headers.get_all('Host') != [self.server.expected_host]:
            return self.reply(403)
        if sum(len(k) + len(v) for k, v in self.headers.items()) > 16384:
            return self.reply(431)
        if any(key in self.headers for key in ('Cookie', 'Authorization', 'Proxy-Authorization')):
            return self.reply(403)
        if any(key in self.headers for key in ('Transfer-Encoding', 'Content-Encoding', 'Expect', 'Upgrade')):
            return self.reply(400)
        lengths = self.headers.get_all('Content-Length', [])
        if len(lengths) != 1 or not lengths[0].isascii() or not lengths[0].isdigit() or len(lengths[0]) > 8:
            return self.reply(400)
        length = int(lengths[0])
        if not 1 <= length <= MAX_BODY:
            return self.reply(413)
        content_types = self.headers.get_all('Content-Type', [])
        if len(content_types) != 1 or content_types[0].lower() not in ('application/json', 'application/json; charset=utf-8'):
            return self.reply(415)
        forwarded = {}
        for name in PAYPAL_HEADERS:
            values = self.headers.get_all(name, [])
            if len(values) != 1 or not values[0] or len(values[0]) > 4096 or any(ord(c) < 32 or ord(c) > 126 for c in values[0]):
                return self.reply(400)
            forwarded[name] = values[0]
        try:
            body = self.rfile.read(length)
            if len(body) != length:
                return self.reply(400)
        except OSError:
            return self.reply(408)
        connection = http.client.HTTPConnection('127.0.0.1', self.server.upstream_port, timeout=10)
        try:
            # Fixed loopback destination and path. No proxies, redirect following, cookies or caller host.
            connection.putrequest('POST', CALLBACK, skip_host=True, skip_accept_encoding=True)
            connection.putheader('Host', f'127.0.0.1:{self.server.upstream_port}')
            connection.putheader('Content-Type', content_types[0])
            connection.putheader('Content-Length', str(length))
            for name, value in forwarded.items():
                connection.putheader(name, value)
            connection.endheaders(body)
            response = connection.getresponse()
            status = response.status
            # Return status only. Never expose Set-Cookie, Location, application text or diagnostics.
            if 300 <= status < 400 or not 200 <= status <= 599:
                status = 502
        except (OSError, http.client.HTTPException):
            status = 503
        finally:
            connection.close()
        self.reply(status)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=8408)
    parser.add_argument('--local-review', action='store_true', help='Required: local review only, not an external exposure approval')
    args = parser.parse_args()
    if not args.local_review:
        raise SystemExit('尚需00接收回调专用入口约定；本地审查可显式使用 --local-review。此工具不启动外网。')
    configuration = ROOT / '.runtime/workspace.json'
    if configuration.is_symlink() or not configuration.exists():
        raise SystemExit('工作区配置缺失或不安全。')
    c = json.loads(configuration.read_text())
    if c.get('root') != str(ROOT):
        raise SystemExit('拒绝使用其他工作区配置。')
    server = Bridge(args.port, c['port'])
    print(f'仅本机审查：127.0.0.1:{server.server_port} → 本工作区固定回调。未启动隧道，未验证官方支付。', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == '__main__':
    main()
