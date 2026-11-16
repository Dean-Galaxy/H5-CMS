"""Send bounded, private Resend feedback summaries to the operations mailbox."""
from collections import Counter
from contextlib import closing
from email.message import EmailMessage
import argparse
import json
import os
from pathlib import Path
import smtplib
import sqlite3
import ssl
import time

from operations_alerts import read_config
from resend_feedback import EVENTS

BATCH_SIZE = 100
SUBJECT = '[H5 operations] Resend feedback'


def pending(connection):
    return connection.execute("""
        SELECT f.event_id, f.type FROM feedback AS f
        LEFT JOIN feedback_notices AS n USING (event_id)
        WHERE n.event_id IS NULL AND f.type IN (?, ?)
        ORDER BY f.received_at, f.event_id LIMIT ?
    """, (*EVENTS, BATCH_SIZE)).fetchall()


def prepare(connection):
    if not connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='feedback'").fetchone():
        return False
    connection.execute('PRAGMA synchronous=FULL')
    connection.execute("""
        CREATE TABLE IF NOT EXISTS feedback_notices (
            event_id TEXT PRIMARY KEY REFERENCES feedback(event_id),
            state TEXT NOT NULL CHECK (state IN ('reserved', 'accepted')),
            updated_at INTEGER NOT NULL
        )
    """)
    return bool(pending(connection))


def notice_email(config, rows):
    counts = Counter(kind for _, kind in rows)
    mail = EmailMessage()
    mail['From'] = config['from']
    mail['To'] = config['to']
    mail['Subject'] = SUBJECT
    mail.set_content(
        "Sparkling Short\n"
        f"退信 {counts['email.bounced']} 条，投诉 {counts['email.complained']} 条。\n"
        "请到 Resend 的 Emails 页面核对详情。\n"
    )
    return mail


def notify(database, config, now=time.time, smtp_factory=smtplib.SMTP_SSL):
    if not database.is_file():
        return 'idle', 0
    with closing(sqlite3.connect(database, timeout=2)) as connection:
        if not prepare(connection):
            return 'idle', 0
        client = None
        try:
            client = smtp_factory('smtp.resend.com', 465, timeout=6, context=ssl.create_default_context())
            client.login('resend', config['password'])
        except (OSError, smtplib.SMTPException):
            if client is not None:
                client.close()
            return 'smtp_pre_data_failed', 0
        try:
            with connection:
                connection.execute('BEGIN IMMEDIATE')
                rows = pending(connection)
                if not rows:
                    return 'idle', 0
                connection.executemany("""
                    INSERT INTO feedback_notices (event_id,state,updated_at) VALUES (?,'reserved',?)
                """, ((event_id, int(now())) for event_id, _ in rows))
            # Once reserved, an ambiguous reply cannot cause a duplicate summary.
            mail = notice_email(config, rows)
            try:
                refused = client.send_message(mail, from_addr=config['from'], to_addrs=[config['to']])
            except (OSError, smtplib.SMTPException):
                return 'smtp_unconfirmed', len(rows)
            if refused:
                return 'smtp_unconfirmed', len(rows)
            with connection:
                connection.executemany("""
                    UPDATE feedback_notices SET state='accepted',updated_at=? WHERE event_id=?
                """, ((int(now()), event_id) for event_id, _ in rows))
            return 'smtp_accepted', len(rows)
        finally:
            client.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args()
    try:
        database = Path(os.environ['STATE_DIRECTORY']) / 'resend-feedback.sqlite'
        status, notifications = notify(database, read_config())
    except (KeyError, OSError, ValueError, sqlite3.Error):
        status, notifications = 'unavailable', 0
    print(json.dumps({'status': status, 'events': notifications}))
    return int(status not in ('idle', 'smtp_accepted'))


if __name__ == '__main__':
    raise SystemExit(main())
