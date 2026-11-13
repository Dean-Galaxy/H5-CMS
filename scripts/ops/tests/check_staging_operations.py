#!/usr/bin/env python3
"""Native Nginx/TLS routing fixture. The upstream is synthetic, not application authorization."""
import argparse
from contextlib import closing
import http.client
import hashlib
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
    parser.add_argument('--hls-only', action='store_true', help='Only the locked HLS file and adjacent route boundaries')
    parser.add_argument('--hls-file', type=Path, help='Existing local locked bundle; no download is performed')
    args = parser.parse_args()
    hls_path = '/vendor/hls.js/1.7.3/hls.min.js'
    hls_payload = None
    hls_entry = None
    if args.hls_only:
        source = args.hls_file or ROOT/('h5-platform/public'+hls_path)
        lock = json.loads((ROOT/'scripts/browser-assets.lock.json').read_text())
        hls_entry = next(item for item in lock['files'] if item['output'] == 'hls.min.js')
        if source.is_symlink() or not source.is_file():
            parser.error('Existing locked HLS bundle is required; no download or synthetic replacement')
        hls_payload = source.read_bytes()
        if len(hls_payload) != hls_entry['bytes'] or hashlib.sha256(hls_payload).hexdigest() != hls_entry['sha256']:
            parser.error('HLS bundle differs from browser-assets.lock.json')
    area_template = ROOT/'deploy/page-test-staging'
    with tempfile.TemporaryDirectory(prefix='h5-stage-route-', dir='/private/tmp') as temp:
        area = Path(temp)
        (area/'logs').mkdir()
        static_root = area/'public'
        static_audience = static_root/'audience'
        static_audience.mkdir(parents=True)
        assets = sorted((ROOT/'h5-platform/public/audience').iterdir())
        assert len(assets) == 10 and all(p.is_file() and p.suffix in ('.css', '.js') for p in assets)
        for asset in assets:
            (static_audience/asset.name).write_bytes(asset.read_bytes())
        if hls_payload is not None:
            bundle = static_root/hls_path.lstrip('/')
            bundle.parent.mkdir(parents=True)
            bundle.write_bytes(hls_payload)
            (static_root/'vendor/extra.js').write_bytes(b'/* adjacent file must not be served */')
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
        config = config.replace('root /srv/h5-page-test/current/h5-platform/public;', f'root {static_root};')
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
            def request(method, path, body=None, headers=None, response_headers=None):
                with closing(http.client.HTTPConnection(HOST, port, timeout=10)) as connection:
                    connection.sock = ctx.wrap_socket(socket.create_connection(('127.0.0.1', port), timeout=10),
                                                      server_hostname=HOST)
                    connection.request(method, path, body, {'Host': HOST, **(headers or {})})
                    response = connection.getresponse()
                    data = response.read()
                    if response_headers is not None:
                        response_headers.update(response.getheaders())
                    return response.status, data
            try:
                for _ in range(30):
                    try:
                        if request('GET', '/admin/login')[0] == 418:
                            break
                    except OSError:
                        time.sleep(.05)
                if args.hls_only:
                    observed = {}
                    status, raw = request('GET', hls_path, response_headers=observed)
                    assert status == 200 and raw == hls_payload, ('HLS GET', status)
                    assert observed['Content-Type'] == 'application/javascript'
                    assert observed['X-Content-Type-Options'] == 'nosniff'
                    assert observed['Cache-Control'] == 'no-store, private'
                    status, raw = request('HEAD', hls_path)
                    assert status == 200 and raw == b'', ('HLS HEAD', status)
                    assert request('POST', hls_path, b'{}')[0] == 403
                    playback = '/audience/dramas/11111111-1111-4111-8111-111111111111/episodes/22222222-2222-4222-8222-222222222222/playback'
                    status, raw = request('POST', playback, b'{}', {'Origin':'https://'+HOST, 'X-CSRF-TOKEN':'fixture-only'})
                    assert status == 418
                    forwarded = json.loads(raw)['params']
                    assert forwarded['REQUEST_METHOD'] == 'POST' and forwarded['REQUEST_URI'] == playback
                    assert forwarded['HTTP_X_CSRF_TOKEN'] == 'fixture-only' and forwarded['HTTP_ORIGIN'] == 'https://'+HOST
                    # Adjacent files and directories never become a generic static /vendor server.
                    for path in ('/vendor/extra.js', hls_path+'.map', '/vendor/', '/vendor/hls.js/1.7.3/'):
                        assert request('GET', path)[0] == 418, path
                    for path in ('/vendor/.env', '/vendor/hls.js/1.7.3/test.php', hls_path+'/test.php'):
                        assert request('GET', path)[0] == 404, path
                    result = {'scope':'native TLS locked HLS file and adjacent routing only',
                              'hls_get_head_200':True, 'hls_bytes':len(hls_payload),
                              'hls_sha256':hls_entry['sha256'], 'hls_post_403':True,
                              'javascript_nosniff_cache_headers':True, 'audience_playback_post_forwarded':True,
                              'vendor_not_general_static':True, 'php_dotfile_rejected':True,
                              'actual_application_or_decode_tested':False}
                else:
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
                        ('POST','/audience/dramas/11111111-1111-4111-8111-111111111111/episodes/22222222-2222-4222-8222-222222222222/playback'),
                    ]
                    for method, path in forwarded:
                        status, raw = request(method, path, b'{}' if method not in ('GET','HEAD') else None)
                        assert status == 418, (method,path,status)
                        value = json.loads(raw)['params']
                        assert value['REQUEST_METHOD'] == method and value['REQUEST_URI'] == path
                        assert all(key not in value for key in ('HTTP_RANGE', 'HTTP_IF_RANGE', 'HTTP_UPLOAD_OFFSET'))
                    for asset in assets:
                        path = '/audience/' + asset.name
                        status, raw = request('GET', path)
                        assert status == 200 and raw == asset.read_bytes(), (path, status)
                        assert request('POST', path, b'{}')[0] == 403, path
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
                              'provider_ingress_closed':True,'audience_static_files_get_only':len(assets),'scope':'native TLS Nginx to synthetic FastCGI',
                              'authenticated_login_playback_calendar_tested':False}
            finally:
                nginx.terminate()
                nginx.wait(timeout=5)
                backend.shutdown()
                thread.join(timeout=5)
        print(json.dumps(result))


if __name__ == '__main__':
    main()
