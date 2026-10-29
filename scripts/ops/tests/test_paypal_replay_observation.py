"""Synthetic-only private fixtures plus real loopback bridge/upstream; no DB/provider/tunnel."""
from concurrent.futures import ThreadPoolExecutor
import contextlib
import hashlib
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest.mock import patch

OPS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(OPS))
import paypal_replay_observation as module
import paypal_complete_webhook_url as guard
import paypal_external_observer as observer
spec = importlib.util.spec_from_file_location('replay_bridge_fixture', OPS / 'paypal_callback_bridge.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)
REAL_CONNECTION = http.client.HTTPConnection


class Upstream(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        self.server.received.append((self.path, dict(self.headers.items()), self.rfile.read(int(self.headers['Content-Length']))))
        self.send_response(self.server.status)
        self.send_header('Set-Cookie', 'SECRET_COOKIE')
        self.send_header('Location', 'https://private.invalid/')
        self.end_headers()
        self.wfile.write(b'PRIVATE_RESPONSE')


class ReplayTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.upstream = ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
        cls.upstream.status = 200
        cls.upstream.received = []
        cls.server = bridge.Bridge(0, cls.upstream.server_port)
        cls.threads = []
        for server in (cls.upstream, cls.server):
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start(); cls.threads.append(thread)

    @classmethod
    def tearDownClass(cls):
        for server, thread in zip((cls.upstream, cls.server), cls.threads):
            server.shutdown(); server.server_close(); thread.join()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='06-replay-synthetic-')
        self.root = Path(self.temp.name).resolve(); self.root.chmod(0o700)
        self.directory = self.root / '.runtime/paypal-sandbox'
        self.directory.mkdir(parents=True, mode=0o700); self.directory.parent.chmod(0o700)
        self.marker = 'SYNTHETIC-06-' + 'a' * 32
        self.origin = 'https://synthetic-only.trycloudflare.com'
        self.command = [str(self.root/'.runtime/tools/cloudflared-2026.10.4'),'tunnel','--config','/dev/null','--no-autoupdate','--metrics','127.0.0.1:8409','--management-diagnostics=false','--loglevel','info','--url','http://127.0.0.1:8408','--http-host-header','127.0.0.1:8408','--no-chunked-encoding']
        self.processes = {'root':str(self.root),'upstream_port':8406,'bridge_port':8408,'metrics_port':8409,
                          'bridge_pid':os.getpid(),'tunnel_pid':91234,'tunnel_command':self.command,
                          'process_started_at':{'bridge_pid':'synthetic start','tunnel_pid':'synthetic start'}}
        self.write('external-processes.json', self.processes)
        self.write('external-url.json', {'url':self.origin})
        self.write('external-probe-marker.json', {'transmission_id':self.marker})
        self.write('../workspace.json', {'root':str(self.root),'port':8406})
        self.policy = {'schema':1,'workspace_root':str(self.root),'event_id':'WH-SYNTHETIC-EVENT',
                       'window':{'marker_sha256':module.digest(self.marker),'origin_sha256':module.digest(self.origin),
                                 'tunnel_pid':91234,'tunnel_started_at':'synthetic start'},'expires_at':int(time.time())+1800}
        self.write('replay-observation.json', self.policy)
        self.match_patch = patch.object(guard, 'matching', return_value=True); self.match = self.match_patch.start()
        self.command_patch = patch.object(guard, 'process_command', return_value=' '.join(self.command)); self.command_mock = self.command_patch.start()
        self.replay = module.ReplayObservation(self.root)
        self.connection_patch = patch.object(bridge.http.client, 'HTTPConnection', observer.observed_connection(REAL_CONNECTION, self.marker, self.directory/'external-upstream.json', self.replay))
        self.connection_patch.start()
        self.upstream.status = 200; self.upstream.received.clear(); self.server.forwarded_at.clear()
        self.body = b' {\n "id":"WH-SYNTHETIC-EVENT", "resource":{"private":"DO_NOT_SAVE", "text":"\xe6\xb5\x8b"}\n}\n'
        self.headers = {key:'SYNTHETIC_SIGNATURE_ONLY' for key in bridge.PAYPAL_HEADERS}
        self.headers.update({'paypal-transmission-id':'TRANSMISSION-SYNTHETIC-1','Content-Type':'application/json; charset=utf-8','Host':self.server.expected_host})

    def tearDown(self):
        self.connection_patch.stop(); self.command_patch.stop(); self.match_patch.stop(); self.temp.cleanup()

    def write(self, name, value):
        path=self.directory/name; path.write_text(json.dumps(value)); path.chmod(0o600)

    def request(self, body=None, headers=None, path='/webhooks/paypal'):
        client=REAL_CONNECTION('127.0.0.1',self.server.server_port,timeout=5)
        client.request('POST',path,self.body if body is None else body,self.headers if headers is None else headers)
        response=client.getresponse(); result=(response.status,dict(response.getheaders()),response.read());client.close();return result

    def rows(self):
        paths=list(self.directory.glob('replay-observations-*.jsonl'))
        return [json.loads(line) for p in paths for line in p.read_text().splitlines()]

    def test_01_two_transmissions_same_event_counted_without_private_content(self):
        self.assertEqual(200,self.request()[0])
        self.assertEqual(200,self.request(headers={**self.headers,'paypal-transmission-id':'TRANSMISSION-SYNTHETIC-2'})[0])
        rows=self.rows();self.assertEqual([1,2],[r['count'] for r in rows])
        self.assertEqual(rows[0]['event_id_sha256'],rows[1]['event_id_sha256'])
        self.assertNotEqual(rows[0]['transmission_id_sha256'],rows[1]['transmission_id_sha256'])
        for r in rows:
            self.assertEqual(module.FIELDS,set(r));self.assertFalse(r['signature_verified'])
            self.assertEqual(module.digest(self.body),r['body_sha256']);self.assertEqual(len(self.body),r['body_bytes'])
        data=''.join(p.read_text() for p in self.directory.glob('replay-observations-*.jsonl'))
        for private in ['WH-SYNTHETIC-EVENT','TRANSMISSION-SYNTHETIC','DO_NOT_SAVE','SYNTHETIC_SIGNATURE_ONLY','127.0.0.1','PRIVATE_RESPONSE']:
            self.assertNotIn(private,data)

    def test_02_exact_raw_body_five_headers_and_allowlist_unchanged(self):
        status,headers,body=self.request(headers={**self.headers,'Origin':'https://fake.invalid','CF-IPCountry':'US','X-Forwarded-For':'192.0.2.1'})
        self.assertEqual(200,status);self.assertEqual(b'',body)
        self.assertNotIn('Set-Cookie',headers);self.assertNotIn('Location',headers)
        path,hs,raw=self.upstream.received[-1];self.assertEqual('/webhooks/paypal',path);self.assertEqual(self.body,raw)
        self.assertEqual(set(bridge.PAYPAL_HEADERS)|{'host','content-type','content-length'},{k.lower() for k in hs})
        for k in bridge.PAYPAL_HEADERS:self.assertEqual(self.headers[k],hs[k])

    def test_03_default_disabled_records_nothing_even_with_policy(self):
        self.connection_patch.stop()
        self.connection_patch=patch.object(bridge.http.client,'HTTPConnection',observer.observed_connection(REAL_CONNECTION,self.marker,self.directory/'external-upstream.json'))
        self.connection_patch.start();self.assertEqual(200,self.request()[0]);self.assertEqual([],self.rows())

    def test_04_unrelated_event_ignored(self):
        self.assertEqual(200,self.request(body=b'{"id":"WH-OTHER"}')[0]);self.assertEqual([],self.rows())

    def test_05_invalid_json_duplicate_id_encoding_shape_ignored(self):
        for raw in [b'{',b'[]',b'null',b'\xff',b'{"id":"WH-SYNTHETIC-EVENT","id":"WH-SYNTHETIC-EVENT"}',b'{"id":123}',b'{"id":'+b'['*1200+b'0'+b']'*1200+b'}']:
            with self.subTest(raw_length=len(raw)):self.assertEqual(200,self.request(body=raw)[0])
        self.assertEqual([],self.rows())

    def test_06_private_paths_missing_headers_and_credentials_still_rejected(self):
        for path in ['/','/admin','/.env','/metrics','/credentials','/api/admin/v1/staff','/media/test.m3u8','/webhooks/paypal?extra=1']:
            self.assertEqual(404,self.request(path=path)[0])
        for name in ['Cookie','Authorization']:
            self.assertEqual(403,self.request(headers={**self.headers,name:'SECRET'})[0])
        hs=dict(self.headers);del hs['paypal-transmission-sig'];self.assertEqual(400,self.request(headers=hs)[0])
        self.assertEqual([],self.rows());self.assertEqual([],self.upstream.received)

    def test_07_observation_status_is_actual_response_not_signature_success(self):
        for status in [200,400,503,302]:
            self.upstream.status=status;self.assertEqual(502 if status==302 else status,self.request()[0])
        self.assertEqual([200,400,503,302],[r['upstream_status'] for r in self.rows()])
        self.assertTrue(all(r['signature_verified'] is False for r in self.rows()))

    def test_08_wrong_workspace_or_window_ignored(self):
        mutations=[('workspace_root','/other-workspace'),('expires_at',int(time.time())-1)]
        for key,value in mutations:
            self.write('replay-observation.json',{**self.policy,key:value});self.assertEqual(200,self.request()[0])
        for key,value in [('marker_sha256','b'*64),('origin_sha256','c'*64),('tunnel_pid',91235),('tunnel_started_at','different start')]:
            self.write('replay-observation.json',{**self.policy,'window':{**self.policy['window'],key:value}});self.assertEqual(200,self.request()[0])
        self.assertEqual([],self.rows())

    def test_09_closed_or_recycled_process_ignored(self):
        self.match.return_value=False;self.assertEqual(200,self.request()[0]);self.assertEqual([],self.rows())

    def test_10_wider_tunnel_command_ignored(self):
        r={**self.processes,'tunnel_command':self.command+['--extra']};self.write('external-processes.json',r)
        self.assertEqual(200,self.request()[0]);self.assertEqual([],self.rows())

    def test_11_other_bridge_pid_ignored(self):
        self.write('external-processes.json',{**self.processes,'bridge_pid':os.getpid()+1})
        self.assertEqual(200,self.request()[0]);self.assertEqual([],self.rows())

    def test_12_policy_symlink_mode_hardlink_and_owner_denied(self):
        p=self.directory/'replay-observation.json';p.chmod(0o644)
        with self.assertRaises(ValueError):module.ReplayObservation(self.root)
        p.chmod(0o600);os.link(p,self.directory/'hardlink')
        with self.assertRaises(ValueError):module.ReplayObservation(self.root)
        (self.directory/'hardlink').unlink();p.rename(self.directory/'target');p.symlink_to(self.directory/'target')
        with self.assertRaises(OSError):module.ReplayObservation(self.root)
        p.unlink();(self.directory/'target').rename(p)
        with patch.object(module.os,'getuid',return_value=os.getuid()+1):
            with self.assertRaises(ValueError):module.ReplayObservation(self.root)

    def test_13_private_directories_symlink_or_wrong_mode_denied(self):
        self.directory.chmod(0o755)
        with self.assertRaises(ValueError):module.ReplayObservation(self.root)
        self.directory.chmod(0o700);self.directory.rename(self.directory.with_name('target'));self.directory.symlink_to(self.directory.with_name('target'))
        with self.assertRaises(ValueError):module.ReplayObservation(self.root)

    def test_14_malformed_policy_startup_denied_without_recording(self):
        for p in [{},[],{**self.policy,'event_id':'bad\nvalue'},{**self.policy,'event_id':3},{**self.policy,'expires_at':int(time.time())+7200},{**self.policy,'schema':True},{**self.policy,'extra':'private'}]:
            self.write('replay-observation.json',p)
            with self.subTest(policy_type=type(p).__name__),self.assertRaises(ValueError):module.ReplayObservation(self.root)
        self.assertEqual([],self.rows())

    def test_15_invalid_transmission_not_observed(self):
        self.assertEqual(200,self.request(headers={**self.headers,'paypal-transmission-id':'bad transmission! /'})[0]);self.assertEqual([],self.rows())

    def test_16_64_record_cap_no_unbounded_growth(self):
        selected=self.replay.select(self.body,self.headers)
        for _ in range(module.MAX_RECORDS):self.assertTrue(self.replay.record(selected,200))
        path=next(self.directory.glob('replay-observations-*.jsonl'));before=path.read_bytes()
        self.assertFalse(self.replay.record(selected,200));self.assertEqual(before,path.read_bytes())
        self.assertEqual(64,len(self.rows()));self.assertLessEqual(len(before),module.MAX_LOG_BYTES)
        self.assertEqual(0o600,stat.S_IMODE(path.stat().st_mode))

    def test_17_concurrent_counts_are_unique_or_safely_skipped(self):
        selected=self.replay.select(self.body,self.headers)
        with ThreadPoolExecutor(max_workers=8) as pool:out=list(pool.map(lambda _:self.replay.record(selected,200),range(32)))
        rows=self.rows();self.assertGreater(len(rows),0);self.assertEqual(sum(out),len(rows));self.assertEqual(list(range(1,len(rows)+1)),[r['count'] for r in rows])

    def test_18_output_symlink_or_corruption_not_followed_or_replaced(self):
        path=self.replay.window(self.policy);target=self.directory/'untouched';target.write_text('PRIVATE');target.chmod(0o600);path.symlink_to(target)
        self.assertFalse(self.replay.record(self.replay.select(self.body,self.headers),200));self.assertEqual('PRIVATE',target.read_text())
        path.unlink();path.write_bytes(b'incomplete');path.chmod(0o600)
        self.assertFalse(self.replay.record(self.replay.select(self.body,self.headers),200));self.assertEqual(b'incomplete',path.read_bytes())

    def test_19_policy_rotation_between_selection_and_response_ignored(self):
        selected=self.replay.select(self.body,self.headers);self.write('replay-observation.json',{**self.policy,'event_id':'WH-OTHER'})
        self.assertFalse(self.replay.record(selected,200));self.assertEqual([],self.rows())

    def test_20_expiry_mid_request_ignored_without_affecting_application(self):
        selected=self.replay.select(self.body,self.headers);self.write('replay-observation.json',{**self.policy,'expires_at':int(time.time())-1})
        self.assertFalse(self.replay.record(selected,200));self.assertEqual(200,self.request()[0]);self.assertEqual([],self.rows())

    def test_21_synthetic_transport_receipt_remains_compatible(self):
        self.assertEqual(200,self.request(headers={**self.headers,'paypal-transmission-id':self.marker})[0])
        proof=json.loads((self.directory/'external-upstream.json').read_text())
        self.assertEqual(module.digest(self.body),proof['body_sha256']);self.assertEqual(200,proof['upstream']['status'])
        self.assertEqual(sorted(['host','content-type','content-length']+list(bridge.PAYPAL_HEADERS)),proof['header_names'])

    def test_22_readonly_window_guard_accepts_appended_optin_and_stop_matching(self):
        # Accepted guard checks bridge token as substring; appended flag does not weaken exact tunnel check.
        from paypal_stop_callback_window import matching
        command=f'/usr/bin/python3 scripts/ops/tests/paypal_external_observer.py --synthetic-id {self.marker} --observe-event'
        def ps(args, **kwargs):return subprocess.CompletedProcess(args,0,command+'\n' if 'command=' in args else 'synthetic start\n','')
        with patch('paypal_stop_callback_window.subprocess.run',side_effect=ps):
            self.assertTrue(matching(os.getpid(),'scripts/ops/tests/paypal_external_observer.py --synthetic-id '+self.marker,'synthetic start'))
        self.replay.window(self.policy,own_process=False)

    def test_23_observation_error_does_not_log_request_or_change_response(self):
        out=io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(out):
            (self.directory/'replay-observation.json').unlink();self.assertEqual(200,self.request()[0])
        self.assertEqual('',out.getvalue());self.assertEqual([],self.rows())

    def test_24_policy_oversize_and_duplicate_keys_denied(self):
        p=self.directory/'replay-observation.json'
        for raw in [b'x'*(module.MAX_POLICY_BYTES+1),b'{"schema":1,"schema":1}']:
            p.write_bytes(raw)
            with self.assertRaises(ValueError):module.ReplayObservation(self.root)

    def test_25_log_mode_owner_size_and_hardlink_safely_skipped(self):
        selected=self.replay.select(self.body,self.headers);path=self.replay.window(self.policy)
        path.write_bytes(b'x'*(module.MAX_LOG_BYTES+1));path.chmod(0o600)
        self.assertFalse(self.replay.record(selected,200));path.write_bytes(b'');path.chmod(0o644)
        self.assertFalse(self.replay.record(selected,200));path.chmod(0o600)
        os.link(path,self.directory/'log-hardlink');self.assertFalse(self.replay.record(selected,200))
        (self.directory/'log-hardlink').unlink()
        with patch.object(module.os,'getuid',return_value=os.getuid()+1):self.assertFalse(self.replay.record(selected,200))
        self.assertEqual(b'',path.read_bytes())

    def test_26_lock_contention_does_not_block_or_duplicate(self):
        import fcntl
        selected=self.replay.select(self.body,self.headers);path=self.replay.window(self.policy)
        fd=os.open(path,os.O_CREAT|os.O_RDWR,0o600)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            self.assertFalse(self.replay.record(selected,200))
        finally:os.close(fd)
        self.assertTrue(self.replay.record(selected,200));self.assertEqual([1],[r['count'] for r in self.rows()])


if __name__ == '__main__':
    unittest.main(verbosity=2)
