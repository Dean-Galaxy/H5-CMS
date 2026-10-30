"""Test lifecycle boundaries offline; never launch Laravel, PG, or a listener."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import sys
import unittest
from unittest.mock import patch

OPS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(OPS))
import c_window as w
import c_window_preflight as p


class Lifecycle(unittest.TestCase):
    def test_foreign_pid_record_never_gets_process_lookup(self):
        with patch.object(w.subprocess, 'run') as run:
            self.assertFalse(w.matching({'root': '/foreign', 'pid': 123}, {'port': 8411}))
            run.assert_not_called()

    def test_matching_needs_exact_command_and_starttime(self):
        config = {'port': 8411}
        record = {'root': str(w.ROOT), 'command': w.command(config), 'pid': 12345, 'started_at': 'test-start'}
        correct = subprocess.CompletedProcess([], 0, ' '.join(w.command(config)) + '\n', '')
        start = subprocess.CompletedProcess([], 0, 'test-start\n', '')
        with patch.object(w.subprocess, 'run', side_effect=[correct, start]):
            self.assertTrue(w.matching(record, config))
        with patch.object(w.subprocess, 'run', side_effect=[correct, subprocess.CompletedProcess([], 0, 'reused-pid\n', '')]):
            self.assertFalse(w.matching(record, config))
        for pid in (True, -1, 1, None, '12345'):
            with patch.object(w.subprocess, 'run') as run:
                self.assertFalse(w.matching({**record, 'pid': pid}, config)); run.assert_not_called()

    def test_busy_port_is_not_stopped_or_retried(self):
        with patch.object(w.socket, 'socket') as sock:
            sock.return_value.__enter__.return_value.bind.side_effect = OSError('in use')
            with self.assertRaises(OSError): w.port_available(8411)

    def test_old_development_root_refuses_before_manifest_or_environment(self):
        with patch.object(w, 'ROOT', Path('/Volumes/KESU/H5')), patch.object(w, 'private_document') as private:
            with patch.object(sys, 'argv', ['c_window.py', 'setup', '--candidate', 'a' * 40]), self.assertRaises(ValueError):
                w.main()
            private.assert_not_called()

    def stop_fixture(self, extra):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp).resolve() / 'h5-c-ui-manual/H5'; root.mkdir(parents=True)
            (root / '.git').write_text('synthetic git marker')
            out = root / '.runtime/06/c-window'; out.mkdir(parents=True, mode=0o700)
            for path in (root / '.runtime', root / '.runtime/06'): path.chmod(0o700)
            suffix = p.hashlib.sha256(str(root).encode()).hexdigest()[:10]
            config = dict(root=str(root), workspace='h5_'+suffix, db='h5_'+suffix, user='h5_app_'+suffix, port=8411, db_port=5911)
            manifest = out / 'manifest.json'
            manifest.write_text(json.dumps({'workspace':config,'candidate':'a'*40})); manifest.chmod(0o600)
            workspace = root / '.runtime/workspace.json'; workspace.write_text(json.dumps(config)); workspace.chmod(0o600)
            fake_dev = SimpleNamespace(PG=Path('/synthetic/pg'), process_alive=lambda name: False,
                                       write_private=lambda path, value: path.write_text(value))
            with patch.multiple(w, ROOT=root, OUT=out, MANIFEST=manifest, ROUTER=root/'router.php'), \
                 patch.object(w.importlib.util, 'spec_from_file_location', return_value=SimpleNamespace(loader=SimpleNamespace(exec_module=lambda module: None))), \
                 patch.object(w.importlib.util, 'module_from_spec', return_value=fake_dev), \
                 patch.object(sys, 'argv', ['c_window.py','stop','--candidate','a'*40]), patch('builtins.print'):
                extra(root, out, config)

    def test_stop_before_web_was_created_does_not_need_process_record(self):
        def check(root, out, config):
            with patch.object(w.os, 'killpg') as kill, patch.object(w.subprocess, 'run') as run:
                w.main(); kill.assert_not_called(); run.assert_not_called()
            self.assertTrue((out/'stopped.json').exists())
        self.stop_fixture(check)

    def test_copied_pg_pid_cannot_signal_foreign_cluster(self):
        def check(root, out, config):
            directory = root/'.runtime/postgres'; directory.mkdir()
            (directory/'postmaster.pid').write_text('12345\n/foreign/postgres\n0\n5911\n')
            with patch.object(w.os, 'killpg') as kill, patch.object(w.subprocess, 'run') as run:
                with self.assertRaisesRegex(ValueError, 'FOREIGN_POSTGRES_PID_FILE'): w.main()
                kill.assert_not_called(); run.assert_not_called()
        self.stop_fixture(check)

    def test_reused_web_pid_cannot_be_signalled(self):
        def check(root, out, config):
            path = out/'process.json'
            path.write_text(json.dumps({'root':str(root),'candidate':'a'*40,'pid':12345,'command':w.command(config),'started_at':'original'})); path.chmod(0o600)
            foreign = subprocess.CompletedProcess([],0,'foreign process','')
            with patch.object(w.os, 'killpg') as kill, patch.object(w.subprocess, 'run', return_value=foreign):
                with self.assertRaisesRegex(ValueError, 'PID_REUSED_OR_FOREIGN_PROCESS'): w.main()
                kill.assert_not_called()
        self.stop_fixture(check)

    def test_http_boundary_without_application_boot(self):
        root = OPS.parents[1]
        boundary = root / 'h5-platform/tests/Browser/Support/CWindowBoundary.php'
        uuid = '00000000-0000-4000-8000-000000000006'
        rows = [('GET', '/', True), ('GET', '/catalog', True), ('GET', '/api/v2/measurement/context', True),
                ('POST', '/api/v2/measurement/start', True), ('PATCH', '/api/v2/measurement/preferences', True),
                ('POST', '/api/v1/tracking/consent', True), ('POST', '/api/v1/tracking/events', True),
                ('POST', f'/api/v1/episodes/{uuid}/playback-grants', True),
                ('POST', f'/audience/dramas/{uuid}/episodes/{uuid}/playback', True),
                ('POST', '/api/v1/episodes/not-uuid/playback-grants', False),
                ('POST', '/api/v1/auth/register', False), ('POST', '/identity/register', False),
                ('POST', '/membership/checkout', False), ('POST', '/membership/cancel', False),
                ('POST', '/api/v1/billing/refunds', False), ('GET', '/webhooks/paypal', False),
                ('POST', '/webhooks/stripe', False), ('GET', '/admin', False), ('POST', '/admin/login', False),
                ('PUT', '/api/v1/me/progress/any', False), ('POST', '/unknown', False)]
        code = 'require $argv[1]; $rows=json_decode($argv[2],true); foreach($rows as $r){if(Tests\\Browser\\Support\\CWindowBoundary::allows($r[0],$r[1]) !== $r[2]){exit(1);}} echo count($rows);'
        result = subprocess.run(['/opt/homebrew/opt/php@8.4/bin/php', '-r', code, str(boundary), json.dumps(rows)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, str(len(rows)))


if __name__ == '__main__': unittest.main()
