#!/usr/bin/env python3
"""Temporary external acceptance observer; forwards to the unchanged real application.

Only a caller-selected synthetic transmission ID is observed. No request body,
header values, credentials, or unrelated request metadata are persisted.
"""
import argparse
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import signal

ROOT = Path(__file__).resolve().parents[3]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--synthetic-id', required=True)
    args = parser.parse_args()
    if not args.synthetic_id.startswith('SYNTHETIC-06-'):
        raise SystemExit('Synthetic marker required')
    spec = importlib.util.spec_from_file_location('callback_bridge', ROOT / 'scripts/ops/paypal_callback_bridge.py')
    bridge = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(bridge)
    config = json.loads((ROOT / '.runtime/workspace.json').read_text())
    if config['root'] != str(ROOT) or config['port'] != 8406:
        raise SystemExit('Unexpected workspace binding')
    destination = ROOT / '.runtime/paypal-sandbox/external-upstream.json'
    real_connection = http.client.HTTPConnection

    class ObservedConnection(real_connection):
        def putrequest(self, method, url, *a, **kw):
            self.observed_headers = {}
            self.observed_method, self.observed_path = method, url
            return super().putrequest(method, url, *a, **kw)

        def putheader(self, name, *values):
            self.observed_headers[name.lower()] = '\t'.join(str(v) for v in values)
            return super().putheader(name, *values)

        def endheaders(self, message_body=None, *a, **kw):
            self.synthetic_match = self.observed_headers.get('paypal-transmission-id') == args.synthetic_id
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
            return response

    bridge.http.client.HTTPConnection = ObservedConnection
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
