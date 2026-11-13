#!/usr/bin/env python3
"""Native Nginx/TLS routing fixture. The upstream is synthetic, not application authorization."""
import argparse
from contextlib import closing
import http.client
import json
from pathlib import Path
import socket
import socketserver
import ssl
import struct
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[3]
HOST = 'staging.sparklingshort.com'


def parameters(data):
    values = {}
    offset = 0
    def length():
        nonlocal offset
        n = data[offset]
        if n & 128:
            n = struct.unpack('!I', data[offset:offset+4])[0] & 0x7fffffff
            offset += 4
        else:
            offset += 1
        return n
    while offset < len(data):
        key_size, value_size = length(), length()
        key = data[offset:offset+key_size].decode()
        offset += key_size
        values[key] = data[offset:offset+value_size].decode()
        offset += value_size
    return values


class Fixture(socketserver.StreamRequestHandler):
    def handle(self):
        params, body = b'', b''
        while header := self.rfile.read(8):
            _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', header)
            data = self.rfile.read(size)
            self.rfile.read(padding)
            if kind == 4:
                params += data
            if kind == 5:
                body += data
                if not size:
                    break
        payload = json.dumps({'params': parameters(params), 'body_bytes': len(body)}).encode()
        payload = b'Status: 418 Routing fixture\r\nContent-Type: application/json\r\n\r\n' + payload
        for kind, data in ((6, payload), (6, b''), (3, b'\x00'*8)):
            self.wfile.write(struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--nginx', type=Path, required=True)
    parser.add_argument('--openssl', type=Path, required=True)
    args = parser.parse_args()
    area_template = ROOT/'deploy/page-test-staging'
    with tempfile.TemporaryDirectory(prefix='h5-stage-route-', dir='/private/tmp') as temp:
        area = Path(temp)
        (area/'logs').mkdir()
        subprocess.run([str(args.openssl), 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(area/'key'), '-out', str(area/'cert'), '-days', '1',
                        '-subj', '/CN='+HOST, '-addext', 'subjectAltName=DNS:'+HOST],
                       check=True, capture_output=True)
        with socket.socket() as reserved:
            reserved.bind(('127.0.0.1', 0))
            port = reserved.getsockname()[1]
        fcgi = (area_template/'fastcgi.conf.template').read_text().replace(
            'unix:/run/php/h5-page-test.sock', 'unix:'+str(area/'fcgi.sock'))
        (area/'fcgi.conf').write_text(fcgi)
        config = (area_template/'nginx.conf.template').read_text()
        config = config.replace('listen 443 ssl;', f'listen 127.0.0.1:{port} ssl;')
        config = config.replace('/etc/letsencrypt/live/'+HOST+'/fullchain.pem', str(area/'cert'))
        config = config.replace('/etc/letsencrypt/live/'+HOST+'/privkey.pem', str(area/'key'))
        config = config.replace('__FASTCGI_INCLUDE__', str(area/'fcgi.conf')).replace('__LIVEWIRE__', 'livewire-fcc22f7f')
        default = f'server {{ listen 127.0.0.1:{port} ssl default_server; ssl_reject_handshake on; return 444; }}'
        conf = area/'nginx.conf'
        conf.write_text(f'pid {area}/nginx.pid;\nerror_log {area}/error.log;\nevents {{}}\nhttp {{\n'+default+config+'\n}\n')
        subprocess.run([str(args.nginx), '-t', '-p', temp, '-c', str(conf)], check=True, capture_output=True)
        ctx = ssl.create_default_context(cafile=str(area/'cert'))
        with socketserver.ThreadingUnixStreamServer(str(area/'fcgi.sock'), Fixture) as backend:
            thread = threading.Thread(target=backend.serve_forever, daemon=True)
            thread.start()
            nginx = subprocess.Popen([str(args.nginx), '-p', temp, '-c', str(conf), '-g', 'daemon off;'],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            def request(method, path, body=None, headers=None):
                with closing(http.client.HTTPConnection(HOST, port, timeout=10)) as connection:
                    connection.sock = ctx.wrap_socket(socket.create_connection(('127.0.0.1', port), timeout=10),
                                                      server_hostname=HOST)
                    connection.request(method, path, body, {'Host': HOST, **(headers or {})})
                    response = connection.getresponse()
                    data = response.read()
                    return response.status, data
            try:
                for _ in range(30):
                    try:
                        if request('GET', '/admin/login')[0] == 418:
                            break
                    except OSError:
                        time.sleep(.05)
                forwarded = [
                    ('GET','/admin/content-library'), ('GET','/admin/billing-desk'), ('GET','/admin/staff-accounts'),
                    ('GET','/admin/profile'), ('GET','/admin/content-settings'), ('GET','/admin/acquisition-desk'),
                    ('POST','/admin/livewire/update'), ('POST','/livewire-fcc22f7f/update'),
                    ('POST','/api/v1/admin/content/uploads'), ('POST','/api/v1/admin/content/dramas/x/publish'),
                    ('GET','/admin/content/episodes/x/preview'), ('GET','/api/v1/admin/content/preview/x/index.m3u8'),
                    ('PATCH','/api/v1/admin/staff/x'), ('GET','/'), ('GET','/catalog'),
                    ('GET','/dramas/x'), ('GET','/dramas/x/episodes/y'), ('GET','/r/test'),
                    ('GET','/api/v1/tracking/context'), ('POST','/api/v1/tracking/landing'),
                    ('POST','/api/v1/tracking/consent'), ('POST','/api/v1/tracking/events'),
                    ('POST','/api/v1/episodes/x/playback-grants'),
                ]
                for method, path in forwarded:
                    status, raw = request(method, path, b'{}' if method not in ('GET','HEAD') else None)
                    assert status == 418, (method,path,status)
                    value = json.loads(raw)['params']
                    assert value['REQUEST_METHOD'] == method and value['REQUEST_URI'] == path
                    assert all(key not in value for key in ('HTTP_RANGE', 'HTTP_IF_RANGE', 'HTTP_UPLOAD_OFFSET'))
                status, raw = request('POST','/api/v1/admin/content/uploads/x/chunks',b'x'*1048576,
                                      {'Upload-Offset':'1048576','Content-Type':'application/octet-stream'})
                assert status == 418
                value = json.loads(raw)
                assert value['body_bytes'] == 1048576 and value['params']['HTTP_UPLOAD_OFFSET'] == '1048576'
                status, raw = request('GET','/api/v1/content/playback/x/media.mp4',headers={
                    'Range':'bytes=0-1023','If-Range':'"fixture"','X-Forwarded-For':'203.0.113.1'})
                value = json.loads(raw)['params']
                assert status == 418 and value['HTTP_RANGE']=='bytes=0-1023' and value['HTTP_IF_RANGE']=='"fixture"'
                assert 'HTTP_X_FORWARDED_FOR' not in value
                for path in ('/.env','/nested/.git/config','/index.php','/a.PHP/path'):
                    assert request('GET',path)[0] == 404, path
                for path in ('/webhooks/paypal/live','/webhooks/stripe','/membership/checkout','/api/v1/billing/checkout','/membership/checkout/','/billing/return/recheck/'):
                    assert request('POST',path,b'{}')[0] == 503, path
                try:
                    request('GET','/',headers={'Host':'wrong.invalid'})
                except http.client.RemoteDisconnected:
                    pass
                else:
                    raise AssertionError('Wrong Host was accepted')
                unknown_ctx = ssl.create_default_context(cafile=str(area/'cert'))
                unknown_ctx.check_hostname = False  # Verify server rejection, not a client hostname error.
                with socket.create_connection(('127.0.0.1',port),timeout=5) as sock:
                    try:
                        unknown_ctx.wrap_socket(sock,server_hostname='unknown.invalid')
                    except ssl.SSLError:
                        pass
                    else:
                        raise AssertionError('Unknown SNI was accepted')
                result = {'application_routes_forwarded':len(forwarded),'chunk_bytes':1048576,
                          'range_offset_headers':True,'sni_host_dotfiles_php_boundaries':True,
                          'provider_ingress_closed':True,'scope':'native TLS Nginx to synthetic FastCGI',
                          'authenticated_login_playback_calendar_tested':False}
            finally:
                nginx.terminate()
                nginx.wait(timeout=5)
                backend.shutdown()
                thread.join(timeout=5)
        print(json.dumps(result))


if __name__ == '__main__':
    main()
