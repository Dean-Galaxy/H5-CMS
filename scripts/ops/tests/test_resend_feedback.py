"""Exercise the WSGI entry with signed fixtures and an independent temporary inbox."""
import base64
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
import hashlib
import hmac
import importlib.util
from io import BytesIO
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
from wsgiref.util import setup_testing_defaults

spec = importlib.util.spec_from_file_location('resend_feedback', Path(__file__).resolve().parents[1] / 'resend_feedback.py')
feedback = importlib.util.module_from_spec(spec)
spec.loader.exec_module(feedback)
NOW = 1_764_000_000
KEY = bytes(range(32))
EMAIL_ID = '56761188-7520-42d8-8898-ff6fc54ce618'


def payload(kind='email.bounced', sender='H5 <mail@example.invalid>', subject='Account verification'):
    return json.dumps({'type': kind, 'created_at': '2025-11-22T23:41:12.126Z', 'data': {
        'email_id': EMAIL_ID, 'from': sender, 'to': ['private@example.invalid'],
        'subject': subject, 'bounce': {'message': 'private failure text'},
    }}, ensure_ascii=False).encode()


class ResendFeedbackTest(unittest.TestCase):
    def setUp(self):
        area = tempfile.TemporaryDirectory()
        self.addCleanup(area.cleanup)
        self.root = Path(area.name)
        self.credentials = self.root / 'credentials'
        self.credentials.mkdir()
        self.credential = self.credentials / 'resend-feedback'
        self.credential.write_text(json.dumps({
            'signing_secret': 'whsec_' + base64.b64encode(KEY).decode(),
            'from': 'mail@example.invalid',
        }))
        self.database = self.root / 'resend-feedback.sqlite'
        context = patch.dict(os.environ, CREDENTIALS_DIRECTORY=str(self.credentials), STATE_DIRECTORY=str(self.root))
        context.start()
        self.addCleanup(context.stop)
        clock = patch.object(feedback.time, 'time', return_value=NOW)
        clock.start()
        self.addCleanup(clock.stop)

    def request(self, body, event_id='msg_fixture', timestamp=NOW, signed_body=None, **overrides):
        wire = body if signed_body is None else signed_body
        signature = base64.b64encode(hmac.new(KEY, f'{event_id}.{timestamp}.'.encode() + wire, hashlib.sha256).digest())
        environ = {}
        setup_testing_defaults(environ)
        environ.update(PATH_INFO='/webhooks/resend', REQUEST_METHOD='POST',
                       CONTENT_TYPE='application/json; charset=utf-8', CONTENT_LENGTH=str(len(body)),
                       HTTP_SVIX_ID=event_id, HTTP_SVIX_TIMESTAMP=str(timestamp),
                       HTTP_SVIX_SIGNATURE='v1,old v1,' + signature.decode())
        environ.update({'wsgi.input': BytesIO(body), **overrides})
        response = []

        def start(status, headers):
            response.append((int(status.split()[0]), dict(headers)))

        raw = b''.join(feedback.application(environ, start))
        status, headers = response[0]
        self.assertEqual(headers['Cache-Control'], 'no-store')
        self.assertNotIn(b'private@example', raw)
        return status, json.loads(raw)['result']

    def rows(self):
        with closing(sqlite3.connect(self.database)) as db:
            return db.execute('SELECT * FROM feedback ORDER BY event_id').fetchall()

    def test_fixed_signature_vector_generated_with_openssl(self):
        key = feedback.decode_secret('whsec_' + base64.b64encode(b'generated-only-for-this-public-test-vector').decode())
        self.assertTrue(feedback.verified(key, 'msg_loFOjxBNrRLzqYUf', '1731705121',
                        'v1,FXJN3crtRO9R7k48t4yi+WtWH/TclPXvdRMPSJOrXhc=',
                        b'{"event_type":"ping","data":{"success":true}}', 1731705121))

    def test_bounce_and_complaint_leave_only_safe_fields(self):
        self.assertEqual(self.request(payload(subject='验证邮件')), (200, 'accepted'))
        self.assertEqual(self.request(payload('email.complained'), event_id='msg_second'), (200, 'accepted'))
        self.assertEqual(self.rows(), [
            ('msg_fixture', 'email.bounced', EMAIL_ID, '2025-11-22T23:41:12.126000+00:00', NOW),
            ('msg_second', 'email.complained', EMAIL_ID, '2025-11-22T23:41:12.126000+00:00', NOW),
        ])
        self.assertNotIn(b'private', self.database.read_bytes())

    def test_repeat_and_concurrent_delivery_commit_one_row(self):
        self.assertEqual(self.request(payload()), (200, 'accepted'))
        with ThreadPoolExecutor(max_workers=4) as pool:
            outcomes = list(pool.map(lambda _: self.request(payload(), event_id='msg_concurrent'), range(8)))
        self.assertEqual(outcomes, [(200, 'accepted')] * 8)
        self.assertEqual(self.request(payload(), timestamp=NOW + 4), (200, 'accepted'))
        self.assertEqual(len(self.rows()), 2)

    def test_modified_bytes_wrong_or_missing_signatures_and_clock_are_rejected(self):
        for overrides in (
            {'signed_body': payload().replace(b': ', b':')},
            {'HTTP_SVIX_SIGNATURE': 'v1,incorrect'},
            {'HTTP_SVIX_ID': 'msg_other'},
            {'HTTP_SVIX_SIGNATURE': ''},
            {'timestamp': NOW - 301},
            {'timestamp': NOW + 301},
        ):
            with self.subTest(overrides=overrides):
                self.assertEqual(self.request(payload(), **overrides), (403, 'signature'))
        self.assertFalse(self.database.exists())

    def test_alert_feedback_wrong_sender_and_unsubscribed_events_do_not_enter_inbox(self):
        for message in (
            payload(subject='[H5 operations] site failed'),
            payload('email.complained', subject='[H5 operations drill] site recovered'),
            payload(sender='Other <other@example.invalid>'),
            payload('email.delivered'),
        ):
            self.assertEqual(self.request(message), (200, 'accepted'))
        self.assertFalse(self.database.exists())

    def test_route_media_type_size_and_truncation(self):
        for override, expected in (
            ({'PATH_INFO': '/webhooks/paypal/live'}, 404),
            ({'REQUEST_METHOD': 'GET'}, 405),
            ({'CONTENT_TYPE': 'text/plain'}, 415),
            ({'CONTENT_LENGTH': str(feedback.MAX_BODY + 1)}, 413),
            ({'CONTENT_LENGTH': str(len(payload()) + 1)}, 400),
        ):
            self.assertEqual(self.request(payload(), **override)[0], expected)
        self.assertFalse(self.database.exists())

    def test_signed_malformed_event_is_rejected(self):
        self.assertEqual(self.request(b'{"type":"email.bounced","data":null}'), (400, 'invalid_event'))
        self.assertFalse(self.database.exists())

    def test_storage_failure_is_retriable_and_missing_secret_stays_closed(self):
        self.database.mkdir()
        self.assertEqual(self.request(payload()), (503, 'storage_unavailable'))
        self.database.rmdir()
        self.assertEqual(self.request(payload()), (200, 'accepted'))
        self.assertEqual(len(self.rows()), 1)
        self.credential.unlink()
        self.assertEqual(self.request(payload()), (503, 'configuration_unavailable'))
        self.assertEqual(len(self.rows()), 1)


if __name__ == '__main__':
    unittest.main()
