"""Offline checks for monitoring, notification reservation, and recovery."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('operations_alerts', Path(__file__).resolve().parents[1] / 'operations_alerts.py')
alerts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(alerts)
CONFIG = {'from': 'ops@example.invalid', 'to': 'owner@example.invalid', 'password': 'synthetic'}


class OperationsAlertsTest(unittest.TestCase):
    def setUp(self):
        area = tempfile.TemporaryDirectory()
        self.addCleanup(area.cleanup)
        self.path = Path(area.name) / 'alerts.json'
        self.state = alerts.read_state(self.path)
        self.smtp = Mock()
        self.smtp.send_message.return_value = {}
        self.factory = Mock(return_value=self.smtp)

    def persist(self, state):
        alerts.save_state(self.path, state)

    def notify(self, state, transitions):
        return alerts.send(state, transitions, CONFIG, self.persist, self.factory)

    def test_site_database_and_billing_debounce_resume_and_new_incident(self):
        failed = dict.fromkeys(alerts.COMPONENTS, False)
        self.assertEqual(alerts.evaluate(self.state, failed), {})
        self.persist(self.state)
        reopened = alerts.read_state(self.path)
        transitions = alerts.evaluate(reopened, failed)
        self.assertEqual(transitions, dict.fromkeys(alerts.COMPONENTS, 'failed'))
        self.assertEqual(self.notify(reopened, transitions), 'smtp_accepted')
        self.assertEqual(self.smtp.send_message.call_args.kwargs['to_addrs'], [CONFIG['to']])
        reopened = alerts.read_state(self.path)
        self.assertEqual(alerts.evaluate(reopened, failed), {})
        partial = {'site': True, 'database': False, 'billing': False}
        self.assertEqual(self.notify(reopened, alerts.evaluate(reopened, partial)), 'smtp_accepted')
        self.assertEqual(alerts.evaluate(alerts.read_state(self.path), partial), {})
        reopened = alerts.read_state(self.path)
        self.assertEqual(alerts.evaluate(reopened, failed), {})
        self.assertEqual(alerts.evaluate(reopened, failed), {'site': 'failed'})
        self.assertEqual(self.smtp.send_message.call_count, 2)

    def test_unknown_data_does_not_resend_after_process_restart(self):
        self.smtp.send_message.side_effect = TimeoutError
        self.assertEqual(self.notify(self.state, {'billing': 'failed'}), 'smtp_not_confirmed')
        restarted = alerts.read_state(self.path)
        self.assertEqual(restarted['billing']['notified'], 'reserved')
        self.assertEqual(alerts.evaluate(restarted, {'billing': False}), {})
        self.assertEqual(alerts.evaluate(restarted, {'billing': True}), {'billing': 'recovered'})

    def test_connect_failure_leaves_incident_eligible(self):
        self.factory.side_effect = TimeoutError
        self.state['site']['failures'] = 2
        self.persist(self.state)
        self.assertEqual(self.notify(self.state, {'site': 'failed'}), 'smtp_pre_data_failed')
        self.assertEqual(alerts.evaluate(alerts.read_state(self.path), {'site': False}), {'site': 'failed'})
        self.smtp.send_message.assert_not_called()

    def test_billing_requires_fresh_successful_exited_main_process(self):
        properties = ('LoadState=loaded\nResult=success\nExecMainCode=1\n'
                      'ExecMainStatus=0\nExecMainExitTimestampMonotonic=1000000\n')
        cases = [(properties, 2, True), (properties, 241, False),
                 (properties.replace('ExecMainCode=1', 'ExecMainCode=0'), 2, False),
                 (properties.replace('ExecMainStatus=0', 'ExecMainStatus=1').replace('Result=success', 'Result=exit-code'), 2, False),
                 (properties.replace('LoadState=loaded', 'LoadState=not-found'), 2, False)]
        for value, now, expected in cases:
            with self.subTest(value=value, now=now), patch.object(alerts.subprocess, 'run',
                    return_value=subprocess.CompletedProcess([], 0, stdout=value)) as systemctl, patch.object(
                    alerts.time, 'monotonic_ns', return_value=now * 10**9):
                observed = {}
                self.assertEqual(alerts.billing_probe(observed), expected)
                self.assertEqual(systemctl.call_args.args[0][2], 'h5-billing-reconcile.service')
                if 'ExecMainStatus=1' in value:
                    self.assertEqual(observed, {'result': 'exit-code', 'main_code': 1,
                                                'main_status': 1, 'fresh': True})

    def test_site_requires_real_homepage_marker(self):
        response = Mock(status=200)
        response.getheader.return_value = 'text/html; charset=utf-8'
        connection = Mock()
        connection.getresponse.return_value = response
        with patch.object(alerts, 'LocalHTTPS', return_value=connection):
            response.read.return_value = b'<html>default Nginx page</html>'
            self.assertFalse(alerts.site_probe())
            response.read.return_value = b'<a href="/catalog">Catalog</a>'
            self.assertTrue(alerts.site_probe())
            response.status = 503
            self.assertFalse(alerts.site_probe())
        response.read.assert_called_with(32_768)

    def test_probe_io_failure_is_unhealthy(self):
        self.assertFalse(alerts.probe(Mock(side_effect=subprocess.TimeoutExpired('probe', 4))))
        with patch.object(alerts.subprocess, 'run', return_value=subprocess.CompletedProcess([], 2)):
            self.assertFalse(alerts.database_probe())

    def test_drill_cannot_use_live_state_directory(self):
        with patch.dict(alerts.os.environ, STATE_DIRECTORY=str(self.path.parent)), patch.object(
                alerts.argparse.ArgumentParser, 'parse_args', return_value=Mock(drill='failure')), patch('builtins.print'):
            self.assertEqual(alerts.main(), 1)
            self.assertFalse(self.path.exists())


if __name__ == '__main__':
    unittest.main()
