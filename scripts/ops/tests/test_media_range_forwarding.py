import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('render_nginx', ROOT / 'scripts/ops/render_nginx.py')
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


class MediaRangeForwardingTest(unittest.TestCase):
    def test_optional_range_headers_are_omitted_when_absent_in_every_php_entry(self):
        # An empty If-Range is different from a missing header. Symfony treats the
        # former as a failed validator and sends the entire file instead of 206.
        for phase in ('tls', 'staff-admin', 'viewer-preview'):
            with self.subTest(phase=phase):
                candidate = renderer.render('example.com', phase)
                entries = candidate.count('fastcgi_pass unix:/run/php/h5-staging.sock;')
                self.assertGreater(entries, 0)
                for key, variable in (('HTTP_RANGE', '$http_range'), ('HTTP_IF_RANGE', '$http_if_range')):
                    self.assertEqual(entries, candidate.count(f'fastcgi_param {key} {variable} if_not_empty;'))
                    self.assertNotIn(f'fastcgi_param {key} {variable};', candidate)


if __name__ == '__main__':
    unittest.main()
