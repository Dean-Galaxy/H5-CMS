"""Pure synthetic checks. Never bootstrap Laravel, connect DB/SSH, or start a service."""
import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

OPS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(OPS))
import media_acceptance_window as m

BRIDGE = OPS/'media_acceptance_bridge.php'
PHP = shutil.which('php')
BASE = m.stamp('2026-11-02T04:00:00Z')
OWNER = '00000000-0000-0000-0000-000000000001'
WINDOW = '00000000-0000-0000-0000-000000000002'
SYNTHETIC = b'OFFLINE synthetic content; no actual media or identity'
DIGEST = hashlib.sha256(SYNTHETIC).hexdigest()


def binding():
    return {'schema': 'h5-media-acceptance-v1', 'approved': True,
            'approval_reference': 'SYNTHETIC TEST ONLY - never a real permission',
            'owner_id': OWNER, 'window_id': WINDOW, 'not_before': '2026-11-02T04:00:00Z',
            'expires_at': '2026-11-02T04:45:00Z', 'max_assets': 6, 'source_sha': m.SOURCE,
            'release_root': str(m.RELEASE), 'workspace': 'h5_cloud_staging', 'database': 'h5_staging',
            'media_root': '/srv/h5/shared/media', 'fixtures': [
                {'kind': k, 'extension': ext,
                 'bytes': len(SYNTHETIC), 'sha256': DIGEST}
                for k, ext in [('video', 'mp4'), ('subtitle', 'srt'), ('cover', 'png')]],
            'frozen_sha256': {k: 'a'*64 for k in m.FILES}}


def asset(n=1):
    key = '10000000-0000-0000-0000-'+str(n).zfill(12)
    return {'id': key, 'created_by': OWNER, 'created_at': '2026-11-02T04:00:01Z',
            'kind': 'video', 'extension': 'mp4', 'filename': 'synthetic.mp4',
            'expected_bytes': len(SYNTHETIC), 'received_bytes': len(SYNTHETIC), 'observed_bytes': len(SYNTHETIC),
            'state': 'pending', 'attempts': 0, 'source_key': 'assets/'+key+'/source.mp4',
            'sha256': DIGEST, 'observed_sha256': DIGEST}


class FakeBridge:
    def __init__(self, rows, ledger, *, owner=True, failure=None):
        self.rows, self.ledger, self.owner, self.failure = rows, ledger, owner, failure
        self.processed = []

    def call(self, action, key=None):
        if action == 'inspect':
            return {'ok': True, 'owner_ok': self.owner, 'assets': copy.deepcopy(self.rows)}
        persisted = json.loads(self.ledger.path.read_text())
        assert persisted['in_flight'] == key and key in persisted['claimed']
        self.processed.append(key)
        if self.failure:
            raise m.Refused(self.failure)
        row = next(a for a in self.rows if a['id'] == key)
        row.update(state='ready', attempts=1)
        return {'ok': True, 'asset_id': key, 'state': 'ready', 'attempts': 1}


class MediaWindowOfflineTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name).resolve()
        self.b = binding()
        self.ledger = m.Ledger(self.root/'ledger.json', 'f'*64, WINDOW)

    def rejects(self, code, function, *args):
        with self.assertRaisesRegex(m.Refused, code):
            function(*args)

    def test_default_and_unapproved_binding_do_not_spawn_or_boot(self):
        with patch.object(m.subprocess, 'Popen', side_effect=AssertionError('No process')), \
             patch.object(m.Path, 'read_bytes', side_effect=AssertionError('No private read')), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(0, m.main([]))
        self.assertFalse(json.loads(output.getvalue())['started'])
        self.b['approved'] = False
        self.rejects('DIRECT_APPROVAL_REQUIRED', m.binding_policy, self.b)

    def test_run_refusal_reports_fixed_gate_without_claiming_no_prior_work(self):
        with patch.object(m, 'run', side_effect=m.Refused('DIRECT_APPROVAL_REQUIRED')), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(2, m.main(['--run']))
        result = json.loads(output.getvalue())
        self.assertEqual('DIRECT_APPROVAL_REQUIRED', result['reason'])
        self.assertTrue(result['review_required'])
        self.assertNotIn('started_or_completed', result)

    def test_single_staging_and_exact_positive_45_minute_budget(self):
        for key, value in [('source_sha', 'f'*40), ('workspace', 'other'), ('database', 'other'),
                           ('max_assets', 7), ('max_assets', True), ('expires_at', '2026-11-02T04:45:01Z')]:
            with self.subTest(key=key, value=value):
                b = {**self.b, key: value}
                with self.assertRaises(m.Refused):
                    m.binding_policy(b)
        self.assertEqual((BASE, BASE+2700), m.binding_policy(self.b))

    def test_non_owner_or_missing_mfa_stops_before_consuming(self):
        adapter = FakeBridge([asset()], self.ledger, owner=False)
        self.rejects('OWNER_OR_MFA_NOT_READY', m.step, self.b, self.ledger, adapter, BASE+10)
        self.assertEqual([], adapter.processed)
        self.assertEqual([], self.ledger.doc['claimed'])
        a = asset(); a['created_by'] = WINDOW
        self.rejects('OTHER_OWNER_ASSET', m.candidate_policy, self.b, a, BASE+10)

    def test_unknown_hash_type_size_and_actual_source_tamper_refuse(self):
        for key, value in [('sha256', 'b'*64), ('observed_sha256', hashlib.sha256(b'tampered').hexdigest()),
                           ('observed_bytes', 1), ('expected_bytes', 1), ('kind', 'banner'),
                           ('source_key', '../outside')]:
            with self.subTest(field=key):
                a = {**asset(), key: value}
                adapter = FakeBridge([a], self.ledger)
                with self.assertRaises(m.Refused):
                    m.step(self.b, self.ledger, adapter, BASE+10)
                self.assertEqual([], adapter.processed)
                self.assertEqual([], self.ledger.doc['claimed'])

    def test_chinese_or_renamed_content_is_not_a_filename_gate(self):
        for kind, ext, name in [('video', 'mp4', '1.mp4'), ('subtitle', 'srt', '1.srt'),
                                ('cover', 'png', '封面.png'), ('cover', 'png', '改名后的封面.png')]:
            with self.subTest(name=name):
                a = {**asset(), 'kind': kind, 'extension': ext, 'filename': name}
                a['source_key'] = 'assets/'+a['id']+'/source.'+ext
                self.assertEqual('pending', m.candidate_policy(self.b, a, BASE+10))
                a['observed_sha256'] = hashlib.sha256(b'different actual bytes').hexdigest()
                self.rejects('UNKNOWN_FILE_HASH_OR_SIZE', m.candidate_policy, self.b, a, BASE+10)

    def test_bridge_keeps_only_bounded_json_uppercase_refusal_codes(self):
        cases = [(b'{"ok":false,"reason":"MEDIA_OUTPUT_INVALID"}', 42, 'MEDIA_OUTPUT_INVALID'),
                 (b'{"ok":false,"reason":"owner abc / private identity"}', 42, 'BRIDGE_REJECTED_REVIEW_PRIVATE_STATE'),
                 (b'not JSON / private output', 42, 'BRIDGE_REJECTED_REVIEW_PRIVATE_STATE'),
                 (b'{"ok":false,"reason":123}', 42, 'BRIDGE_REJECTED_REVIEW_PRIVATE_STATE'),
                 (b'x'*32769, 42, 'BRIDGE_REJECTED_REVIEW_PRIVATE_STATE'),
                 (b'{"ok":true}', 42, 'BRIDGE_REJECTED_REVIEW_PRIVATE_STATE')]
        for body, code, reason in cases:
            with self.subTest(reason=reason, bytes=len(body)):
                process = unittest.mock.Mock()
                process.communicate.return_value = (body, b'RAW PRIVATE STDERR NEVER EMIT')
                process.returncode = code
                with patch.object(m.time, 'time', return_value=BASE+10), \
                     patch.object(m.subprocess, 'Popen', return_value=process), \
                     patch.object(m, 'terminate_group') as cleanup, \
                     contextlib.redirect_stdout(io.StringIO()) as output:
                    self.rejects('^'+reason+'$', m.Bridge(self.b, lambda: False).call, 'process', asset()['id'])
                self.assertEqual('', output.getvalue())
                cleanup.assert_called_once_with(process)

    def test_old_record_boundary_future_and_expired_do_not_process(self):
        for created, now in [('2026-11-02T03:59:59Z', BASE+10),
                             ('2026-11-02T04:00:00Z', BASE+10),
                             ('2026-11-02T04:00:11Z', BASE+10),
                             ('2026-11-02T04:00:01Z', BASE-1),
                             ('2026-11-02T04:00:01Z', BASE+2700)]:
            with self.subTest(created=created, now=now):
                a = {**asset(), 'created_at': created}
                with self.assertRaises(m.Refused):
                    m.candidate_policy(self.b, a, now)
        adapter = FakeBridge([asset()], self.ledger)
        self.rejects('WINDOW_EXPIRED', m.step, self.b, self.ledger, adapter, BASE+2700)
        self.assertEqual([], adapter.processed)

    def test_six_reuploads_each_consume_one_and_seventh_never_runs(self):
        rows = [{**asset(i), 'filename': 'renamed-'+str(i)+'.mp4'} for i in range(1, 7)]
        # Same content, including renamed copies: each independent ID consumes one.
        adapter = FakeBridge(rows, self.ledger)
        for n in range(6):
            self.assertEqual('quota' if n == 5 else 'processed',
                             m.step(self.b, self.ledger, adapter, BASE+10))
        self.rejects('QUOTA_REACHED', m.step, self.b, self.ledger, adapter, BASE+10)
        self.assertEqual(6, len(adapter.processed))
        self.assertEqual(6, len(self.ledger.doc['claimed']))
        self.assertEqual(6, len(set(self.ledger.doc['claimed'])))
        self.rejects('WINDOW_ALREADY_USED', m.Ledger, self.ledger.path, 'f'*64, WINDOW)

    def test_more_than_six_discovered_stops_before_any_call(self):
        adapter = FakeBridge([asset(i) for i in range(1, 8)], self.ledger)
        self.rejects('NEW_UPLOAD_QUOTA_EXCEEDED', m.step, self.b, self.ledger, adapter, BASE+10)
        self.assertEqual([], adapter.processed)
        self.assertEqual([], self.ledger.doc['claimed'])

    def test_timeout_failure_and_unknown_result_never_replenish_claim(self):
        for reason in ['CHILD_TIMEOUT_OR_WINDOW_EXPIRED', 'MEDIA_NOT_READY', 'UNKNOWN_RESULT', 'MEDIA_OUTPUT_INVALID']:
            with self.subTest(reason=reason):
                ledger = m.Ledger(self.root/(reason+'.json'), 'f'*64, WINDOW)
                adapter = FakeBridge([asset()], ledger, failure=reason)
                self.rejects(reason, m.step, self.b, ledger, adapter, BASE+10)
                ledger.close(reason)
                saved = json.loads(ledger.path.read_text())
                self.assertEqual([asset()['id']], saved['claimed'])
                self.assertEqual(asset()['id'], saved['in_flight'])
                self.assertEqual([], saved['completed'])
                self.rejects('WINDOW_ALREADY_USED', m.Ledger, ledger.path, 'f'*64, WINDOW)

    def test_prior_attempt_processing_and_failed_are_not_retried(self):
        for key, value in [('attempts', 1), ('state', 'processing'), ('state', 'failed'), ('state', 'ready')]:
            with self.subTest(key=key, value=value):
                with self.assertRaises(m.Refused):
                    m.candidate_policy(self.b, {**asset(), key: value}, BASE+10)

    def test_global_lock_refuses_another_process_even_for_different_window(self):
        lock = self.root/'window.lock'
        code = ("import sys;sys.path.insert(0,sys.argv[1]);import media_acceptance_window as m;"
                "\ntry:\n with m.WindowLock(m.Path(sys.argv[2])):sys.exit(9)"
                "\nexcept m.Refused:sys.exit(0)")
        with m.WindowLock(lock):
            r = subprocess.run([sys.executable, '-c', code, str(OPS), str(lock)], capture_output=True, timeout=5)
            self.assertEqual(0, r.returncode, r.stderr)

    def test_state_permissions_and_symlink_are_rejected(self):
        self.root.chmod(0o755)
        self.rejects('STATE_DIRECTORY_INVALID', m.private_directory, self.root)
        self.root.chmod(0o700)
        m.private_directory(self.root)
        link = self.root/'linked.json';link.symlink_to(self.ledger.path)
        self.rejects('STATE_SYMLINK_REFUSED', m.atomic_json, link, {'not': 'written'})
        self.rejects('BINDING_PATH_INVALID', m.secure_binding, self.root/'not-a-binding')

    def test_wrong_cwd_refuses_and_child_always_gets_explicit_app_cwd(self):
        with patch.object(m.Path, 'cwd', return_value=self.root):
            self.rejects('WORKING_DIRECTORY', m.frozen, self.b)
        process = unittest.mock.Mock()
        process.communicate.return_value = (b'{"ok":true}', b'')
        process.returncode = 0
        with patch.object(m.time, 'time', return_value=BASE+10), \
             patch.object(m.subprocess, 'Popen', return_value=process) as spawn, \
             patch.object(m, 'terminate_group') as cleanup:
            m.Bridge(self.b, lambda: False).call('inspect')
        self.assertEqual(m.APP, spawn.call_args.kwargs['cwd'])
        self.assertTrue(spawn.call_args.kwargs['start_new_session'])
        self.assertEqual('staging', spawn.call_args.kwargs['env']['APP_ENV'])
        self.assertEqual({'APP_ENV', 'PATH', 'HOME', 'LANG', 'TZ'}, set(spawn.call_args.kwargs['env']))
        cleanup.assert_called_once_with(process)

    def test_stop_or_expiry_does_not_even_spawn_child(self):
        with patch.object(m.subprocess, 'Popen', side_effect=AssertionError('No process')), \
             patch.object(m.time, 'time', return_value=BASE+10):
            self.rejects('STOP_REQUESTED', m.Bridge(self.b, lambda: True).call, 'process', asset()['id'])
        with patch.object(m.subprocess, 'Popen', side_effect=AssertionError('No process')), \
             patch.object(m.time, 'time', return_value=BASE+2700):
            self.rejects('WINDOW_EXPIRED', m.Bridge(self.b, lambda: False).call, 'process', asset()['id'])

    def test_process_group_cleanup_kills_resistant_descendant(self):
        # A real local synthetic process tree; neither PHP/Laravel nor ffmpeg is run.
        child = "import signal,time;signal.signal(signal.SIGTERM,signal.SIG_IGN);print('READY',flush=True);time.sleep(60)"
        parent = ("import subprocess,signal,time,sys;signal.signal(signal.SIGTERM,signal.SIG_IGN);"
                  "subprocess.Popen([sys.executable,'-c',sys.argv[1]]);time.sleep(60)")
        p = subprocess.Popen([sys.executable, '-c', parent, child], stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, start_new_session=True)
        try:
            self.assertEqual(b'READY\n', p.stdout.readline())
            m.terminate_group(p)
            stdout, stderr = p.communicate(timeout=2)
            self.assertLess(p.returncode, 0)
            # An escaping descendant would still hold this output pipe open.
            self.assertEqual(b'', stdout)
        finally:
            if p.poll() is None:
                m.terminate_group(p)

    def test_deadline_path_reaps_the_entire_synthetic_process_group(self):
        real_spawn = subprocess.Popen
        spawned = []
        child = "import signal,time;signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(60)"
        parent = ("import subprocess,signal,time,sys;signal.signal(signal.SIGTERM,signal.SIG_IGN);"
                  "subprocess.Popen([sys.executable,'-c',sys.argv[1]]);time.sleep(60)")

        def synthetic_spawn(args, **kwargs):
            self.assertEqual(m.APP, kwargs['cwd'])
            kwargs['cwd'] = self.root  # Synthetic process: never enter an app.
            kwargs['env'] = dict(os.environ)
            process = real_spawn([sys.executable, '-c', parent, child], **kwargs)
            spawned.append(process)
            return process

        with patch.object(m.time, 'time', return_value=BASE+10):
            bridge = m.Bridge(self.b, lambda: False)
            bridge.monotonic_end = time.monotonic()+0.15
            with patch.object(m.subprocess, 'Popen', side_effect=synthetic_spawn):
                self.rejects('CHILD_TIMEOUT_OR_WINDOW_EXPIRED', bridge.call, 'process', asset()['id'])
        self.assertEqual(1, len(spawned))
        self.assertLess(spawned[0].returncode, 0)
        spawned[0].communicate(timeout=2)  # No descendant remains holding a pipe.

    def test_binding_uses_dedicated_ops_file_without_weakening_etc_permissions(self):
        self.assertEqual(m.OPS/'window.json', m.BINDING)
        self.assertIn("const BINDING = '/srv/h5/ops/media-acceptance/window.json';", BRIDGE.read_text())
        unit = (OPS.parents[1]/'deploy/h5-media-acceptance-window.service').read_text()
        self.assertIn('--binding /srv/h5/ops/media-acceptance/window.json --run', unit)
        self.assertNotIn('/etc/h5/media-acceptance-window.json', unit)

    def test_service_is_manual_bounded_and_excludes_other_schedulers(self):
        unit = (OPS.parents[1]/'deploy/h5-media-acceptance-window.service').read_text()
        active = '\n'.join(l for l in unit.splitlines() if not l.lstrip().startswith('#'))
        for line in ['Restart=no', 'RuntimeMaxSec=45min', 'KillMode=control-group', 'TimeoutStopSec=5s',
                     'SendSIGKILL=yes', 'WorkingDirectory='+str(m.APP)]:
            self.assertIn(line, active)
        for forbidden in ['[Install]', 'WantedBy=', '.timer', 'schedule:run', 'schedule:work', 'queue:work', 'processNext']:
            self.assertNotIn(forbidden, active)
        example = json.loads((OPS.parents[1]/'deploy/media-acceptance-window.example.json').read_text())
        self.assertIs(False, example['approved'])


@unittest.skipUnless(PHP, 'PHP CLI required for pure adapter policy tests; never install automatically')
class PhpAdapterPolicyTests(unittest.TestCase):
    def call_policy(self, mode, b=None, a=None, owner=None, process=False):
        code = '''require $argv[1];$d=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
try{if($d['mode']==='binding'){H5MediaAcceptance\\bindingPolicy($d['b'],$d['now']);}
elseif($d['mode']==='owner'){H5MediaAcceptance\\ownerPolicy($d['b'],$d['owner']);}
else{H5MediaAcceptance\\assetPolicy($d['b'],$d['a'],$d['now'],$d['process']);}
echo 'OK';}catch(Throwable $e){echo $e->getMessage();exit(42);}'''
        return subprocess.run([PHP, '-r', code, str(BRIDGE)], input=json.dumps(
            {'mode': mode, 'b': b or binding(), 'a': a or asset(), 'owner': owner, 'now': BASE+10, 'process': process}),
            text=True, capture_output=True, timeout=5)

    def test_false_permission_is_rejected_before_linux_or_laravel(self):
        b = binding();b['approved'] = False
        r = subprocess.run([PHP, str(BRIDGE), '--execute'], input=json.dumps({'binding': b}),
                           text=True, capture_output=True, timeout=5)
        self.assertEqual(42, r.returncode)
        self.assertEqual('DIRECT_APPROVAL_REQUIRED', json.loads(r.stdout)['reason'])
        self.assertEqual('', r.stderr)

    def test_actual_owner_gate_requires_bound_owner_active_role_and_mfa(self):
        good = {'id': OWNER, 'active': True, 'role': 'owner', 'has_mfa': True}
        self.assertEqual(0, self.call_policy('owner', owner=good).returncode)
        for key, value in [('id', WINDOW), ('active', False), ('role', 'content_operator'), ('has_mfa', False)]:
            with self.subTest(key=key):
                r = self.call_policy('owner', owner={**good, key: value})
                self.assertEqual(42, r.returncode)
                self.assertEqual('OWNER_OR_MFA_NOT_READY', r.stdout)

    def test_real_synthetic_source_bytes_are_rehashed_and_symlinks_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)/'source.mp4'
            path.write_bytes(SYNTHETIC)
            b, a = binding(), asset()
            import datetime as dt
            now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
            fmt = lambda t: t.strftime('%Y-%m-%dT%H:%M:%SZ')
            b.update(not_before=fmt(now-dt.timedelta(seconds=10)), expires_at=fmt(now+dt.timedelta(seconds=2600)))
            a['created_at'] = fmt(now-dt.timedelta(seconds=1))
            code = "require $argv[1];$d=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);\nclass SyntheticDate extends DateTimeImmutable{public function utc(){return $this->setTimezone(new DateTimeZone('UTC'));}}\n$a=(object)$d['a'];$a->created_at=new SyntheticDate($a->created_at);$a->metadata=['original_filename'=>$a->filename];\n$media=new class($d['path']){public function __construct(private string $p){}public function path(string $key){return $this->p;}};\ntry{$row=H5MediaAcceptance\\snapshot($d['b'],$a,$media);H5MediaAcceptance\\assetPolicy($d['b'],$row,time(),true);echo 'OK';}\ncatch(Throwable $e){echo $e->getMessage();exit(42);}"
            payload = {'b': b, 'a': a, 'path': str(path)}
            def check(expected):
                r = subprocess.run([PHP, '-r', code, str(BRIDGE)], input=json.dumps(payload),
                                   text=True, capture_output=True, timeout=5)
                self.assertEqual(expected, r.stdout, r.stderr)
                self.assertEqual(0 if expected == 'OK' else 42, r.returncode)
            check('OK')
            path.write_bytes(b'X'*len(SYNTHETIC))  # Same DB SHA/size; actual bytes differ.
            check('UNKNOWN_FILE_HASH_OR_SIZE')
            link = Path(directory)/'linked.mp4';link.symlink_to(path)
            payload['path'] = str(link)
            check('SOURCE_FILE_INVALID')
            payload['a']['created_by'] = WINDOW
            # Scope rejection wins before any source access.
            check('OTHER_OWNER_ASSET')

    def test_chinese_renamed_file_passes_but_changed_content_fails(self):
        for kind, ext, name in [('video', 'mp4', '1.mp4'), ('subtitle', 'srt', '1.srt'),
                                ('cover', 'png', '封面.png'), ('cover', 'png', '改名后的封面.png')]:
            with self.subTest(name=name):
                a = {**asset(), 'kind': kind, 'extension': ext, 'filename': name}
                a['source_key'] = 'assets/'+a['id']+'/source.'+ext
                self.assertEqual(0, self.call_policy('asset', a=a, process=True).returncode)
                a['observed_sha256'] = hashlib.sha256(b'different actual bytes').hexdigest()
                r = self.call_policy('asset', a=a, process=True)
                self.assertEqual(42, r.returncode)
                self.assertEqual('UNKNOWN_FILE_HASH_OR_SIZE', r.stdout)

    def output_check(self, root, kind):
        # Real existing MediaStore::path(), with only pure config/public_path stubs.
        # No vendor/autoload, Laravel kernel, DB, identity or processing is invoked.
        code = "$d=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);\n$GLOBALS['syntheticRoot']=$d['root'];\nfunction config($key){return $GLOBALS['syntheticRoot'].'/media';}\nfunction public_path($path=''){return $GLOBALS['syntheticRoot'].'/public';}\nrequire $argv[1];require $argv[2];\n$media=new App\\Modules\\Content\\Services\\MediaStore;\n$asset=(object)['kind'=>$d['kind'],'output_key'=>'assets/synthetic/processed-synthetic'];\ntry{H5MediaAcceptance\\verifyOutput($asset,$media);echo 'OK';}\ncatch(Throwable $e){$s=$e->getMessage();echo preg_match('/\\A[A-Z0-9_]+\\z/D',$s)?$s:'MEDIA_WINDOW_REJECTED';exit(42);}"
        source = OPS.parents[1]/'h5-platform/app/Modules/Content/Services/MediaStore.php'
        return subprocess.run([PHP, '-r', code, str(BRIDGE), str(source)],
                              input=json.dumps({'root': str(root), 'kind': kind}),
                              text=True, capture_output=True, timeout=5)

    def output_cases(self, kind, valid, invalid):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'public').mkdir()
            target = root/'media/assets/synthetic/processed-synthetic'
            target.mkdir(parents=True)
            file = target/('image.png' if kind == 'cover' else 'subtitles.vtt')
            def refused():
                r = self.output_check(root, kind)
                self.assertEqual(42, r.returncode, r.stderr)
                self.assertNotEqual('OK', r.stdout)
                self.assertEqual('', r.stderr)
            refused()  # Missing output despite a caller potentially claiming ready.
            for data in [b'', *invalid]:
                file.write_bytes(data)
                refused()
            file.write_bytes(valid)
            r = self.output_check(root, kind)
            self.assertEqual((0, 'OK', ''), (r.returncode, r.stdout, r.stderr))
            # A valid file behind either a leaf or ancestor symlink must still fail.
            other = root/'other';other.write_bytes(valid)
            file.unlink();file.symlink_to(other);refused()
            file.unlink();file.write_bytes(valid)
            moved = root/'moved-output';target.rename(moved)
            target.symlink_to(moved, target_is_directory=True);refused()

    def test_cover_requires_nonempty_real_png_and_rejects_links(self):
        import struct,zlib
        def chunk(tag, data):
            return struct.pack('>I', len(data))+tag+data+struct.pack('>I', zlib.crc32(tag+data))
        png = (b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR', struct.pack('>IIBBBBB',16,16,8,2,0,0,0))
               +chunk(b'IDAT', zlib.compress((b'\x00'+b'\x00'*48)*16))+chunk(b'IEND', b''))
        import base64
        gif = base64.b64decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
        self.output_cases('cover', png, [b'not a PNG', gif])

    def test_subtitle_requires_nonempty_webvtt_header_and_rejects_links(self):
        self.output_cases('subtitle', b'WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nSynthetic\n',
                          [b'NOTVTT\n\n', b'WEBVTTgarbage\n', b'WEBVTT'])

    def test_adapter_rechecks_fresh_owner_hash_size_and_attempt_before_process(self):
        self.assertEqual(0, self.call_policy('asset', process=True).returncode)
        cases = [('created_by', WINDOW), ('created_at', '2026-11-02T04:00:00Z'),
                 ('observed_sha256', 'b'*64), ('sha256', 'b'*64), ('observed_bytes', 1),
                 ('kind', 'banner'), ('state', 'processing'), ('attempts', 1)]
        for key, value in cases:
            with self.subTest(key=key):
                r = self.call_policy('asset', a={**asset(), key: value}, process=True)
                self.assertEqual(42, r.returncode, r.stdout+r.stderr)


if __name__ == '__main__':
    unittest.main()
