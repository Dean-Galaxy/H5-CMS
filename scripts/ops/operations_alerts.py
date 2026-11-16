#!/usr/bin/env python3
"""Independent production probes and one notification per incident transition."""
import argparse
from email.message import EmailMessage
import fcntl
import http.client
import json
import os
from pathlib import Path
import re
import smtplib
import socket
import ssl
import subprocess
import tempfile
import time

HOST = 'sparklingshort.com'
BILLING = 'h5-billing-reconcile.service'
UNIT_RESULTS = ('success', 'exit-code', 'signal', 'timeout', 'watchdog', 'oom-kill',
                'resources', 'protocol', 'start-limit-hit', 'exec-condition')
COMPONENTS = ('site', 'database', 'billing')
ADDRESS = re.compile(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}")


class LocalHTTPS(http.client.HTTPSConnection):
    def connect(self):
        connection = socket.create_connection(('127.0.0.1', 443), timeout=4)
        try:
            self.sock = self._context.wrap_socket(connection, server_hostname=HOST)
        except BaseException:
            connection.close()
            raise


def site_probe():
    connection = LocalHTTPS(HOST, timeout=4, context=ssl.create_default_context())
    try:
        connection.request('GET', '/', headers={'Host': HOST})
        response = connection.getresponse()
        return (response.status == 200 and
                response.getheader('Content-Type', '').lower().startswith('text/html') and
                b'href="/catalog"' in response.read(32_768))
    except (OSError, http.client.HTTPException):
        return False
    finally:
        connection.close()


def database_probe():
    return subprocess.run(
        ['/usr/bin/pg_isready', '-h', '127.0.0.1', '-p', '5432', '-U', 'postgres', '-d', 'postgres', '-t', '3', '-q'],
        timeout=4, capture_output=True, check=False).returncode == 0


def billing_probe(observation):
    result = subprocess.run(
        ['/usr/bin/systemctl', 'show', BILLING, '-p', 'LoadState', '-p', 'Result',
         '-p', 'ExecMainCode', '-p', 'ExecMainStatus', '-p', 'ExecMainExitTimestampMonotonic'],
        timeout=4, capture_output=True, check=True, text=True)
    properties = dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)
    if properties['LoadState'] != 'loaded':
        return False
    result = properties['Result']
    code = int(properties['ExecMainCode'])
    status = int(properties['ExecMainStatus'])
    finished = int(properties['ExecMainExitTimestampMonotonic'])
    age_us = time.monotonic_ns() // 1000 - finished
    fresh = finished > 0 and 0 <= age_us < 240_000_000
    observation.update(result=result if result in UNIT_RESULTS else 'other',
                       main_code=code, main_status=status, fresh=fresh)
    return fresh and result == 'success' and code == 1 and status == 0


def evaluate(state, samples):
    """Two observations open an incident. One good observation closes it."""
    transitions = {}
    for key, healthy in samples.items():
        entry = state[key]
        if healthy:
            entry['failures'] = 0
            if entry['notified'] != 'idle':
                transitions[key] = 'recovered'
        else:
            entry['failures'] = min(2, entry['failures'] + 1)
            if entry['failures'] == 2 and entry['notified'] == 'idle':
                transitions[key] = 'failed'
    return transitions


def read_state(path):
    try:
        state = json.loads(path.read_text())
    except FileNotFoundError:
        return {key: {'failures': 0, 'notified': 'idle'} for key in COMPONENTS}
    if not isinstance(state, dict) or set(state) != set(COMPONENTS) or any(
        not isinstance(e, dict) or type(e.get('failures')) is not int or e['failures'] not in (0, 1, 2) or
        e.get('notified') not in ('idle', 'reserved', 'accepted')
        for e in state.values()
    ):
        raise ValueError('INVALID_STATE')
    return state


def save_state(path, state):
    fd, name = tempfile.mkstemp(dir=path.parent, prefix='.alerts-')
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(state, stream)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
        dir_fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    finally:
        Path(name).unlink(missing_ok=True)


def send(state, transitions, config, persist, smtp_factory=smtplib.SMTP_SSL, drill=False):
    message = EmailMessage()
    message['From'] = config['from']
    message['To'] = config['to']
    prefix = '[H5 operations drill]' if drill else '[H5 operations]'
    message['Subject'] = prefix + ' ' + ', '.join(f'{key} {value}' for key, value in transitions.items())
    message.set_content(f'{HOST}\n' + '\n'.join(f'{key}: {value}' for key, value in transitions.items()))
    client = None
    try:
        client = smtp_factory('smtp.resend.com', 465, timeout=6, context=ssl.create_default_context())
        client.login('resend', config['password'])
    except (OSError, smtplib.SMTPException):
        if client is not None:
            client.close()
        return 'smtp_pre_data_failed'

    try:
        # Reserve before the message transaction. A crash or lost SMTP reply must not resend it.
        for key, transition in transitions.items():
            state[key]['notified'] = 'reserved' if transition == 'failed' else 'idle'
        persist(state)
        try:
            refused = client.send_message(message, from_addr=config['from'], to_addrs=[config['to']])
            if refused:
                return 'smtp_not_confirmed'
        except (OSError, smtplib.SMTPException):
            return 'smtp_not_confirmed'
        for key, transition in transitions.items():
            if transition == 'failed':
                state[key]['notified'] = 'accepted'
        persist(state)
        return 'smtp_accepted'
    finally:
        client.close()


def read_config():
    config = json.loads((Path(os.environ['CREDENTIALS_DIRECTORY']) / 'alert-config').read_text())
    if not isinstance(config, dict) or set(config) != {'from', 'to', 'password'} or any(
        not isinstance(config[key], str) or not ADDRESS.fullmatch(config[key])
        or len(config[key]) > 254 for key in ('from', 'to')
    ) or not isinstance(config['password'], str) or not re.fullmatch(r'[!-~]{1,4096}', config['password']):
        raise ValueError('INVALID_CONFIG')
    return config


def probe(check):
    try:
        return check()
    except (KeyError, OSError, ValueError, subprocess.SubprocessError):
        return False


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--drill', choices=('failure', 'recovery'))
    args = parser.parse_args()
    try:
        directory = Path(os.environ['STATE_DIRECTORY'])
        if args.drill and directory.name != 'h5-operations-alerts-drill':
            raise ValueError('DRILL_REQUIRES_ISOLATED_STATE')
        path = directory / 'alerts.json'
        with (directory / 'lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            state = read_state(path)
            billing_observation = {}
            if args.drill:
                samples = {key: key != 'site' or args.drill == 'recovery' for key in COMPONENTS}
            else:
                samples = {'site': probe(site_probe), 'database': probe(database_probe),
                           'billing': probe(lambda: billing_probe(billing_observation))}
            transitions = evaluate(state, samples)
            save_state(path, state)
            status = 'no_transition'
            if transitions:
                status = send(state, transitions, read_config(), lambda value: save_state(path, value),
                              drill=bool(args.drill))
            print(json.dumps({'drill': args.drill, 'samples': samples, 'billing': billing_observation,
                              'transitions': transitions, 'notification': status}))
            return int(status in ('smtp_pre_data_failed', 'smtp_not_confirmed'))
    except (KeyError, OSError, TypeError, ValueError):
        print('H5_OPERATIONS_ALERT_CHECK_UNAVAILABLE')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
