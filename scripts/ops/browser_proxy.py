#!/usr/bin/env python3
"""Loopback-only 06 observation/fault proxy; does not manufacture successful app responses."""
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
import http.client
import json
from pathlib import Path
import socket
from urllib.parse import urlsplit
ROOT=Path(__file__).resolve().parents[2]
c=json.loads((ROOT/'.runtime/workspace.json').read_text())
assert c['root']==str(ROOT)
PORT=8207
BASE=f'http://127.0.0.1:{c["port"]}'
PROXY=f'http://127.0.0.1:{PORT}'
mode=ROOT/'.runtime/06/network-mode'
observer=(ROOT/'h5-platform/tests/Browser/observer.js').read_bytes()
force_mse=(ROOT/'h5-platform/tests/Browser/force-mse.js').read_bytes()
monitor=(ROOT/'h5-platform/tests/Browser/hls-monitor.js').read_bytes()
class Handler(BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args): pass  # Never log signatures/cookies/URLs.
    def do_GET(self): self.forward()
    def do_HEAD(self): self.forward()
    def do_POST(self): self.forward()
    def do_PUT(self): self.forward()
    def forward(self):
        path=urlsplit(self.path).path
        if path in ('/__qa06_observer.js','/__qa06_force_mse.js','/__qa06_hls_monitor.js'):
            script = observer if path=='/__qa06_observer.js' else force_mse if path=='/__qa06_force_mse.js' else monitor
            self.send_response(200);self.send_header('Content-Type','text/javascript');self.send_header('Content-Length',str(len(script)));self.end_headers()
            if self.command!='HEAD': self.wfile.write(script)
            return
        if mode.exists() and mode.read_text().strip()=='reset' and ('/api/v1/content/playback/' in path or path.endswith(('/playback-grants', '/playback'))):
            with (ROOT/'.runtime/06/proxy-requests.jsonl').open('a') as log:
                log.write(json.dumps({'kind':'network_reset','extension':Path(path).suffix,'status':None})+'\n')
            self.close_connection=True
            try:self.connection.shutdown(socket.SHUT_RDWR)
            except OSError:pass
            self.connection.close();return
        body=self.rfile.read(int(self.headers.get('Content-Length','0'))) if self.command in ('POST','PUT') else None
        headers={k:v for k,v in self.headers.items() if k.lower() not in ('host','connection','accept-encoding','content-length')}
        headers['Host']=f'127.0.0.1:{c["port"]}'
        conn=http.client.HTTPConnection('127.0.0.1',c['port'],timeout=25)
        try:
            conn.request(self.command,self.path,body,headers)
            res=conn.getresponse(); data=res.read(); ctype=res.getheader('Content-Type','')
            with (ROOT/'.runtime/06/proxy-requests.jsonl').open('a') as log:
                log.write(json.dumps({'kind':'media' if '/api/v1/content/playback/' in path else 'playback' if path.endswith(('/playback-grants', '/playback')) else 'other','extension':Path(path).suffix,'status':res.status,'bytes':len(data)})+'\n')
            if self.command!='HEAD' and any(x in ctype for x in ('text/','json','mpegurl')):
                data=data.replace(BASE.encode(),PROXY.encode())
                if 'json' in ctype:
                    def rewrite(value):
                        if isinstance(value,str): return value.replace(BASE,PROXY)
                        if isinstance(value,dict): return {k:rewrite(v) for k,v in value.items()}
                        if isinstance(value,list): return [rewrite(v) for v in value]
                        return value
                    data=json.dumps(rewrite(json.loads(data))).encode()
                if 'text/html' in ctype:
                    data=data.replace(b'<script src="/audience/player.js"',b'<script src="/__qa06_hls_monitor.js" defer></script><script src="/audience/player.js"')
                    data=data.replace(b'</body>',b'<script src="/__qa06_observer.js"></script></body>')
                    if (ROOT/'.runtime/06/force-mse').exists(): data=data.replace(b'<head>',b'<head><script src="/__qa06_force_mse.js"></script>')
            self.send_response(res.status)
            for k,v in res.getheaders():
                if k.lower() not in ('connection','transfer-encoding','content-length','content-encoding'):
                    self.send_header(k,v.replace(BASE,PROXY) if k.lower()=='location' else v)
            self.send_header('Content-Length',str(len(data)));self.end_headers()
            if self.command!='HEAD': self.wfile.write(data)
        except (OSError,http.client.HTTPException):
            self.close_connection=True
        finally: conn.close()
if __name__=='__main__':
    print(f'06 observation proxy on {PROXY}; mode file {mode}',flush=True)
    ThreadingHTTPServer(('127.0.0.1',PORT),Handler).serve_forever()
