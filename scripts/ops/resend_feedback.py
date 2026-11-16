"""WSGI intake for signed Resend feedback. A successful response follows a durable insert."""
import base64
import binascii
from contextlib import closing
from datetime import datetime, timezone
from email import policy
from email.parser import HeaderParser
import hmac
import json
import os
from pathlib import Path
import re
import sqlite3
import time
from uuid import UUID

PATH = '/webhooks/resend'
MAX_BODY = 65_536
WINDOW_SECONDS = 300
EVENTS = ('email.bounced', 'email.complained')
ALERT_SUBJECTS = ('[H5 operations] ', '[H5 operations drill] ')


def decode_secret(value):
    if not isinstance(value, str) or not value.startswith('whsec_') or len(value) > 256:
        raise ValueError('INVALID_SIGNING_KEY')
    key = base64.b64decode(value[6:], validate=True)
    if len(key) < 16:
        raise ValueError('INVALID_SIGNING_KEY')
    return key


def verified(key, event_id, timestamp, signatures, body, now):
    if (not re.fullmatch(r'[A-Za-z0-9_-]{1,128}', event_id)
            or not re.fullmatch(r'[0-9]{1,12}', timestamp)
            or abs(now - int(timestamp)) > WINDOW_SECONDS or len(signatures) > 2048):
        return False
    signed = f'{event_id}.{timestamp}.'.encode('ascii') + body
    expected = base64.b64encode(hmac.digest(key, signed, 'sha256'))
    for signature in signatures.split():
        version, _, digest = signature.partition(',')
        if version == 'v1' and digest.isascii() and hmac.compare_digest(expected, digest.encode('ascii')):
            return True
    return False


def mailbox(value):
    if not isinstance(value, str) or len(value) > 512 or '\r' in value or '\n' in value:
        raise ValueError('INVALID_SENDER')
    header = HeaderParser(policy=policy.default).parsestr('From: ' + value)['From']
    if header.defects or len(header.addresses) != 1:
        raise ValueError('INVALID_SENDER')
    address = header.addresses[0]
    if not address.username or not address.domain:
        raise ValueError('INVALID_SENDER')
    return address.addr_spec.casefold()


def summarize(body, approved_sender, received_at):
    event = json.loads(body)
    if not isinstance(event, dict) or not isinstance(event.get('type'), str):
        raise ValueError('INVALID_EVENT')
    kind = event['type']
    if kind not in EVENTS:
        return None
    data = event.get('data')
    if not isinstance(data, dict) or not isinstance(data.get('subject'), str):
        raise ValueError('INVALID_EVENT')
    if mailbox(data.get('from')) != approved_sender or data['subject'].startswith(ALERT_SUBJECTS):
        return None
    timestamp = datetime.fromisoformat(event['created_at'].replace('Z', '+00:00'))
    if timestamp.tzinfo is None:
        raise ValueError('INVALID_EVENT_TIME')
    return (kind, str(UUID(data['email_id'])), timestamp.astimezone(timezone.utc).isoformat(), received_at)


def store_event(database, event_id, summary):
    with closing(sqlite3.connect(database, timeout=2)) as connection:
        connection.execute('PRAGMA synchronous=FULL')
        with connection:
            connection.execute("""
                CREATE TABLE IF NOT EXISTS feedback (
                    event_id TEXT PRIMARY KEY NOT NULL,
                    type TEXT NOT NULL,
                    email_id TEXT NOT NULL,
                    occurred_at TEXT NOT NULL,
                    received_at INTEGER NOT NULL
                )
            """)
            connection.execute("""
                INSERT INTO feedback (event_id, type, email_id, occurred_at, received_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(event_id) DO NOTHING
            """, (event_id, *summary))


def respond(start, status, result, headers=()):
    start(status, [('Content-Type', 'application/json'), ('Cache-Control', 'no-store'), *headers])
    return [json.dumps({'result': result}).encode('ascii')]


def intake(environ, start, key, approved_sender, database, now):
    if environ.get('PATH_INFO') != PATH:
        return respond(start, '404 Not Found', 'not_found')
    if environ.get('REQUEST_METHOD') != 'POST':
        return respond(start, '405 Method Not Allowed', 'method', [('Allow', 'POST')])
    if environ.get('CONTENT_TYPE', '').split(';', 1)[0].strip().lower() != 'application/json':
        return respond(start, '415 Unsupported Media Type', 'type')
    length = environ.get('CONTENT_LENGTH', '')
    if not re.fullmatch(r'[0-9]{1,10}', length) or int(length) == 0:
        return respond(start, '400 Bad Request', 'invalid_length')
    if int(length) > MAX_BODY:
        return respond(start, '413 Payload Too Large', 'size')
    try:
        body = environ['wsgi.input'].read(int(length))
    except OSError:
        return respond(start, '400 Bad Request', 'invalid_body')
    if len(body) != int(length):
        return respond(start, '400 Bad Request', 'invalid_body')
    event_id = environ.get('HTTP_SVIX_ID', '')
    if not verified(key, event_id, environ.get('HTTP_SVIX_TIMESTAMP', ''),
                    environ.get('HTTP_SVIX_SIGNATURE', ''), body, now):
        return respond(start, '403 Forbidden', 'signature')
    try:
        summary = summarize(body, approved_sender, now)
    except (AttributeError, KeyError, TypeError, ValueError, RecursionError, OverflowError):
        return respond(start, '400 Bad Request', 'invalid_event')
    if summary is not None:
        try:
            store_event(database, event_id, summary)
        except sqlite3.Error:
            return respond(start, '503 Service Unavailable', 'storage_unavailable')
    return respond(start, '200 OK', 'accepted')


def application(environ, start_response):
    """Mount at PATH using a WSGI runner with private systemd credentials and state."""
    try:
        credential = Path(os.environ['CREDENTIALS_DIRECTORY']) / 'resend-feedback'
        settings = json.loads(credential.read_text())
        key = decode_secret(settings['signing_secret'])
        sender = mailbox(settings['from'])
        database = Path(os.environ['STATE_DIRECTORY']) / 'resend-feedback.sqlite'
    except (OSError, KeyError, TypeError, ValueError, binascii.Error):
        return respond(start_response, '503 Service Unavailable', 'configuration_unavailable')
    return intake(environ, start_response, key, sender, database, int(time.time()))
