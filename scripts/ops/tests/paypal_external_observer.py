#!/usr/bin/env python3
"""Temporary external acceptance observer; forwards to the unchanged real application.

A synthetic transmission marker retains the existing transport proof. Real event
observation requires --observe-event plus a private, expiring window policy.
Neither mode verifies a signature; no request body or header values are saved.
"""
import argparse
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import signal
import re
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from paypal_replay_observation import ReplayObservation

ROOT = Path(__file__).resolve().parents[3]


def observed_connection(real_connection, synthetic_id, destination, replay=None):
    class ObservedConnection(real_connection):
        def putrequest(self, method, url, *a, **kw):
            self.observed_headers = {}
            self.observed_method, self.observed_path = method, url
            return super().putrequest(method, url, *a, **kw)

        def putheader(self, name, *values):
            self.observed_headers[name.lower()] = '\t'.join(str(v) for v in values)
            return super().putheader(name, *values)

        def endheaders(self, message_body=None, *a, **kw):
            self.synthetic_match = self.observed_headers.get('paypal-transmission-id') == synthetic_id
            self.replay_match = (replay.select(message_body, self.observed_headers)
                                 if replay is not None else None)
            if self.synthetic_match:
                self.body_digest = hashlib.sha256(message_body).hexdigest()
                self.body_length = len(message_body)
            return super().endheaders(message_body, *a, **kw)

        def getresponse(self):
            response = super().getresponse()
            if self.synthetic_match:
                result = {
                    'scope': 'synthetic transport only; real application; not official PayPal verification',
                    'upstream': {'host': self.host, 'port': self.port, 'method': self.observed_method,
                                 'path': self.observed_path, 'status': response.status},
                    'body_sha256': self.body_digest, 'body_bytes': self.body_length,
                    'header_names': sorted(self.observed_headers),
                    'header_sha256': {key: hashlib.sha256(value.encode()).hexdigest()
                                      for key, value in self.observed_headers.items()},
                }
                temporary = destination.with_suffix('.tmp')
                fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
                with os.fdopen(fd, 'w') as stream:
                    json.dump(result, stream, indent=2)
                    stream.write('\n')
                os.replace(temporary, destination)
            if replay is not None:
                replay.record(self.replay_match, response.status)
            return response

    return ObservedConnection


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--synthetic-id', required=True)
    parser.add_argument('--observe-event', action='store_true',
                        help='Opt in using fixed private replay-observation.json; no event ID argument')
    args = parser.parse_args()
    if not re.fullmatch(r'SYNTHETIC-06-[a-f0-9]{32}', args.synthetic_id):
        raise SystemExit('Synthetic marker required')
    try:
        replay = ReplayObservation(ROOT) if args.observe_event else None
    except (OSError, ValueError, TypeError, KeyError, RecursionError):
        raise SystemExit('私密观察策略无效；未启动桥，未输出输入值。') from None
    spec = importlib.util.spec_from_file_location('callback_bridge', ROOT / 'scripts/ops/paypal_callback_bridge.py')
    bridge = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(bridge)
    config = json.loads((ROOT / '.runtime/workspace.json').read_text())
    if config['root'] != str(ROOT) or config['port'] != 8406:
        raise SystemExit('Unexpected workspace binding')
    destination = ROOT / '.runtime/paypal-sandbox/external-upstream.json'
    bridge.http.client.HTTPConnection = observed_connection(http.client.HTTPConnection, args.synthetic_id, destination, replay)
    server = bridge.Bridge(8408, config['port'])
    def stop(*_):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, stop)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == '__main__':
    main()
