from io import BytesIO
from pathlib import Path
import json
import sqlite3
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from resend_feedback import store_event, summarize
from resend_feedback_notifications import notify, SUBJECT

CONFIG = {'from': 'no-reply@example.test', 'to': 'ops@example.test', 'password': 'example'}
NOW = 1_794_805_200


class FakeSMTP:
    def __init__(self, auth_error=False, send_error=False):
        self.auth_error = auth_error
        self.send_error = send_error
        self.messages = []
        self.closed = False

    def login(self, username, password):
        if self.auth_error:
            raise OSError('no server')

    def send_message(self, mail, from_addr, to_addrs):
        self.messages.append(mail)
        if self.send_error:
            raise OSError('no reply')
        return {}

    def close(self):
        self.closed = True


class FeedbackNotificationTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.db = Path(self.folder.name) / 'inbox.sqlite'

    def log(self, event_id, kind):
        store_event(self.db, event_id, (kind, '00000000-0000-0000-0000-000000000004', '2026-11-16T00:00:00+00:00', NOW))

    def run_notify(self, transport):
        return notify(self.db, CONFIG, now=lambda: NOW, smtp_factory=lambda *args, **kwargs: transport)

    def test_combines_bounces_and_complaints_once_without_addresses_or_message_ids(self):
        self.log('test-bounce', 'email.bounced')
        self.log('test-bounce', 'email.bounced')
        self.log('test-complaint', 'email.complained')
        smtp = FakeSMTP()
        self.assertEqual(self.run_notify(smtp), ('smtp_accepted', 2))
        self.assertEqual(self.run_notify(smtp), ('idle', 0))
        self.assertEqual(len(smtp.messages), 1)
        self.assertIn('退信 1 条，投诉 1 条', smtp.messages[0].get_content())
        self.assertNotIn('test-bounce', smtp.messages[0].get_content())
        self.assertNotIn('@', smtp.messages[0].get_content())
        self.assertTrue(smtp.closed)

    def test_failure_before_smtp_submission_leaves_event_ready(self):
        self.log('retryable', 'email.bounced')
        self.assertEqual(self.run_notify(FakeSMTP(auth_error=True)), ('smtp_pre_data_failed', 0))
        self.assertEqual(self.run_notify(FakeSMTP()), ('smtp_accepted', 1))

    def test_response_loss_preserves_reserved_event_without_repeat(self):
        self.log('unconfirmed', 'email.complained')
        self.assertEqual(self.run_notify(FakeSMTP(send_error=True)), ('smtp_unconfirmed', 1))
        with sqlite3.connect(self.db) as state:
            self.assertEqual(state.execute('SELECT state FROM feedback_notices').fetchone(), ('reserved',))
        self.assertEqual(self.run_notify(FakeSMTP()), ('idle', 0))

    def test_missing_inbox_does_not_open_smtp(self):
        def unexpected(*args, **kwargs):
            self.fail('SMTP opened unexpectedly')
        self.assertEqual(notify(self.db, CONFIG, smtp_factory=unexpected), ('idle', 0))

    def test_own_alert_loop_is_excluded(self):
        event = {'type':'email.bounced','created_at':'2026-11-16T00:00:00Z',
                 'data':{'from':CONFIG['from'],'subject':SUBJECT,'email_id':'00000000-0000-0000-0000-000000000004'}}
        self.assertIsNone(summarize(json.dumps(event).encode(), CONFIG['from'], NOW))


if __name__ == '__main__':
    unittest.main()
