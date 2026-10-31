"""Targeted static-only checks; no Laravel, database, nginx or external account required."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import socket
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

OPS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(OPS))
import aws_preflight as preflight
import render_nginx as renderer


class OfflineDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name).resolve()
        self.example = preflight.EXAMPLE.read_text()

    def report(self, *args):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = preflight.main(list(args))
        return code, json.loads(output.getvalue())

    def test_default_is_offline_blocked_and_never_reads_live_credentials(self):
        read = preflight.read_example
        seen = []

        def only_example(path):
            seen.append(path)
            self.assertEqual(preflight.EXAMPLE, path)
            return read(path)

        with patch.object(socket, 'socket', side_effect=AssertionError('No sockets')), \
             patch.object(subprocess, 'run', side_effect=AssertionError('No commands')), \
             patch.object(subprocess, 'Popen', side_effect=AssertionError('No processes')), \
             patch.object(preflight, 'read_example', side_effect=only_example):
            code, report = self.report()
        self.assertEqual([preflight.EXAMPLE], seen)
        self.assertEqual(2, code)
        self.assertTrue(report['template_valid'])
        self.assertEqual([], report['errors'])
        self.assertFalse(report['deployment_ready'])
        self.assertEqual('static-only', report['mode'])
        self.assertEqual('not-run', report['host_checks'])
        self.assertFalse(report['network_actions'])
        self.assertFalse(report['rendered'])
        self.assertIn('REGION_REQUIRED', {b['code'] for b in report['blockers']})
        self.assertIn('BUDGET_NOT_APPROVED', {b['code'] for b in report['blockers']})
        self.assertIn('ORIGIN_REQUIRED', {b['code'] for b in report['blockers']})
        self.assertIn('SECRETS_NOT_PROVISIONED', {b['code'] for b in report['blockers']})

    def test_direct_test_flag_default_and_rejection_are_static_only(self):
        values = preflight.parse_example(self.example)
        self.assertEqual('false', values['H5_ACQUISITION_STAGING_TEST'])
        errors, blockers, _ = preflight.inspect(values)
        self.assertEqual([], errors)
        actions = {item['code']: item['action'] for item in blockers}
        self.assertIn('ACQUISITION_CONTROLLED_TEST_PENDING', actions)
        self.assertIn('production未放行', actions['ACQUISITION_CONTROLLED_TEST_PENDING'])
        self.assertNotIn('ACQUISITION_PUBLIC_NOT_RELEASED', actions)
        for flag in ['true', '1', 'false-but-invalid']:
            with self.subTest(flag=flag):
                example = self.directory / ('staging-' + flag + '.env.example')
                example.write_text(self.example.replace('H5_ACQUISITION_STAGING_TEST=false',
                                                       'H5_ACQUISITION_STAGING_TEST=' + flag))
                output = self.directory / ('staging-' + flag + '.conf')
                code, report = self.report('--env-file', str(example), '--domain',
                                           'staging.example.com', '--output', str(output))
                self.assertEqual(2, code)
                self.assertIn('DEFAULT_GATES_OR_ISOLATION_UNSAFE', report['errors'])
                self.assertFalse(report['rendered'])
                self.assertFalse(report['deployment_ready'])
                self.assertFalse(output.exists())

    def test_geoip_defaults_and_activation_refusal_do_not_read_database(self):
        values = preflight.parse_example(self.example)
        self.assertEqual('disabled', values['GEOIP_DRIVER'])
        self.assertEqual('', values['GEOIP_DATABASE'])
        self.assertEqual('45', values['GEOIP_MAX_AGE_DAYS'])
        errors, blockers, _ = preflight.inspect(values)
        self.assertEqual([], errors)
        self.assertIn('GEOIP_DATA_AND_HOST_UNVERIFIED', {b['code'] for b in blockers})
        # geoip-ready-1 runtime accepts 1..45; this secretless preparation stage
        # intentionally pins 45 and refuses any attempt to configure/activate a real file.
        for index, (key, value) in enumerate([
            ('GEOIP_DRIVER', 'dbip_lite'), ('GEOIP_DRIVER', 'true'),
            ('GEOIP_DATABASE', '/synthetic/country.mmdb'),
            ('GEOIP_MAX_AGE_DAYS', '0'), ('GEOIP_MAX_AGE_DAYS', '46'),
            ('GEOIP_MAX_AGE_DAYS', '44'), ('GEOIP_MAX_AGE_DAYS', 'junk'),
        ]):
            with self.subTest(key=key, value=value):
                example = self.directory / ('geoip-' + str(index) + '.env.example')
                example.write_text(self.example.replace(key + '=' + values[key] + '\n',
                                                       key + '=' + value + '\n'))
                output = self.directory / ('geoip-' + str(index) + '.conf')
                with patch.object(socket, 'socket', side_effect=AssertionError('No network')), \
                     patch.object(subprocess, 'run', side_effect=AssertionError('No processes')):
                    code, report = self.report('--env-file', str(example), '--domain',
                                               'staging.example.com', '--output', str(output))
                self.assertEqual(2, code)
                self.assertIn('DEFAULT_GATES_OR_ISOLATION_UNSAFE', report['errors'])
                self.assertFalse(report['deployment_ready'])
                self.assertFalse(report['rendered'])
                self.assertFalse(output.exists())

    def test_each_disabled_gate_and_isolation_rejects_changes_without_values(self):
        for key in preflight.LOCKED:
            with self.subTest(key=key):
                values = preflight.parse_example(self.example)
                values[key] = 'SYNTHETIC_PRIVATE_VALUE'
                errors, _, _ = preflight.inspect(values)
                self.assertIn('DEFAULT_GATES_OR_ISOLATION_UNSAFE', errors)
                self.assertNotIn('SYNTHETIC_PRIVATE_VALUE', json.dumps(errors))

    def test_secrets_and_unreviewed_keys_are_refused_not_echoed(self):
        values = preflight.parse_example(self.example)
        for key in preflight.SECRETS:
            with self.subTest(key=key):
                candidate = dict(values, **{key: 'SYNTHETIC_SECRET_DO_NOT_PRINT'})
                errors, _, _ = preflight.inspect(candidate)
                self.assertIn('SECRET_VALUES_FORBIDDEN_IN_EXAMPLE', errors)
                self.assertNotIn('SYNTHETIC_SECRET_DO_NOT_PRINT', json.dumps(errors))
        for extra in ['AWS_SECRET_ACCESS_KEY=SYNTHETIC_SECRET', 'MAIL_URL=smtps://bad',
                      'APP_ENV=local', 'TRUSTED_PROXIES=*']:
            with self.subTest(extra=extra):
                with self.assertRaises(renderer.InputRefused):
                    preflight.parse_example(self.example + '\n' + extra)

    def test_parser_rejects_shell_quotes_expansion_duplicates_and_truncated_schema(self):
        for text in [self.example.replace('APP_NAME=H5', 'APP_NAME=$(touch /tmp/no)'),
                     self.example.replace('APP_NAME=H5', 'APP_NAME=`id`'),
                     self.example.replace('APP_NAME=H5', 'APP_NAME="H5"'),
                     self.example.replace('APP_NAME=H5\n', ''),
                     self.example + '\nAPP_ENV=staging\n']:
            with self.subTest(kind=text[:12]):
                with self.assertRaises(renderer.InputRefused):
                    preflight.parse_example(text)

    def test_region_origin_and_smtp_pair_must_match_but_never_prove_host_readiness(self):
        values = preflight.parse_example(self.example)
        values.update(AWS_REGION='us-east-1', APP_URL='https://staging.example.com',
                      MAIL_HOST='email-smtp.us-east-1.amazonaws.com',
                      MAIL_FROM_ADDRESS='no-reply@example.com')
        errors, blockers, domain = preflight.inspect(values)
        self.assertEqual([], errors)
        self.assertEqual('staging.example.com', domain)
        self.assertIn('SES_ACCOUNT_AND_ENDPOINT_UNVERIFIED', {b['code'] for b in blockers})
        for key, value, error in [
            ('APP_URL', 'http://staging.example.com', 'ORIGIN_INVALID'),
            ('APP_URL', 'https://staging.example.com/path', 'ORIGIN_INVALID'),
            ('MAIL_HOST', 'email.us-east-1.amazonaws.com', 'SES_REGION_ENDPOINT_MISMATCH'),
            ('MAIL_HOST', 'email-smtp.us-west-2.amazonaws.com', 'SES_REGION_ENDPOINT_MISMATCH'),
            ('MAIL_PORT', '25', 'SMTP_TLS_PORT_INVALID'),
            ('MAIL_SCHEME', 'smtp', 'SMTP_TLS_PORT_INVALID'),
        ]:
            with self.subTest(key=key, value=value):
                self.assertIn(error, preflight.inspect(dict(values, **{key: value}))[0])
        values.update(MAIL_SCHEME='smtp', MAIL_PORT='587')
        self.assertEqual([], preflight.inspect(values)[0])
        self.assertIn('ORIGIN_DOMAIN_MISMATCH', preflight.inspect(values, 'other.example.com')[0])

    def test_bad_domains_do_not_create_files_or_echo_input(self):
        for domain in ['127.0.0.1', 'LOCALHOST', 'foo.localhost', '*.example.com',
                       'https://example.com', 'example.com;include /etc/passwd;',
                       'example.com\nserver{}', 'example.com/../x', 'example.com:443',
                       'a'*64 + '.com', 'a.'*125 + 'example.com']:
            with self.subTest(domain=domain):
                target = self.directory / 'refused.conf'
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    code = renderer.main(['--domain', domain, '--output', str(target)])
                self.assertEqual(2, code)
                self.assertFalse(target.exists())
                self.assertEqual('DOMAIN_INVALID', json.loads(output.getvalue())['error'])

    def test_tls_preserves_exact_paypal_allowlist_and_direct_peer_and_private_media(self):
        text = renderer.render('staging.example.com')
        names = ['AUTH_ALGO', 'CERT_URL', 'TRANSMISSION_ID', 'TRANSMISSION_SIG', 'TRANSMISSION_TIME']
        for name in names:
            line = f'fastcgi_param HTTP_PAYPAL_{name} $http_paypal_{name.lower()};'
            self.assertEqual(1, text.count(line))
        self.assertEqual(5, text.count('fastcgi_param HTTP_PAYPAL_'))
        self.assertIn('fastcgi_pass_request_headers off;', text)
        self.assertIn('fastcgi_param REMOTE_ADDR $remote_addr;', text)
        self.assertIn('fastcgi_param HTTPS on;', text)
        self.assertIn('fastcgi_param HTTP_STRIPE_SIGNATURE $http_stripe_signature;', text)
        for forbidden in ['HTTP_FORWARDED ', 'HTTP_X_FORWARDED_', 'HTTP_CF_IPCOUNTRY',
                          'real_ip_header', 'set_real_ip_from', 'alias /srv/h5/shared']:
            self.assertNotIn(forbidden, text)
        self.assertNotIn('__DOMAIN__', text)

    def test_first_certificate_phase_never_points_to_certificate_php_or_app(self):
        text = renderer.render('staging.example.com', 'acme-bootstrap')
        self.assertIn('location ^~ /.well-known/acme-challenge/', text)
        self.assertIn('try_files $uri =404;', text)
        self.assertIn('location / { return 503; }', text)
        for forbidden in ['listen 443', 'ssl_certificate', 'fastcgi_pass', '/srv/h5', 'proxy_pass']:
            self.assertNotIn(forbidden, text)

    def test_exclusive_private_artifact_and_path_refusals(self):
        target = self.directory / 'candidate.conf'
        renderer.write_candidate(target, 'synthetic')
        self.assertEqual(0o600, stat.S_IMODE(target.stat().st_mode))
        with self.assertRaises(FileExistsError):
            renderer.write_candidate(target, 'overwrite')
        self.assertEqual('synthetic', target.read_text())
        link = self.directory / 'link.conf'
        link.symlink_to(target)
        parent_link = self.directory / 'parent-link'
        parent_link.symlink_to(self.directory, target_is_directory=True)
        for path in [link, parent_link/'new.conf', self.directory/'..'/'escaped.conf',
                     self.directory/'bad;name.conf', Path('/etc/h5-unapproved.conf'),
                     renderer.ROOT/'h5-platform/public/h5-unapproved.conf']:
            with self.subTest(path=str(path)), self.assertRaises((renderer.InputRefused, OSError)):
                renderer.write_candidate(path, 'no')
        self.assertEqual('synthetic', target.read_text())
        self.assertFalse((self.directory/'new.conf').exists())
        # A review checkout may itself live under /private/tmp; temp allowance must not
        # turn that checkout's public/source files into permitted output locations.
        with patch.object(renderer, 'ROOT', self.directory), self.assertRaises(renderer.InputRefused):
            renderer.write_candidate(self.directory/'public.conf', 'no')

    def test_example_reader_refuses_live_env_links_fifo_and_oversized_files(self):
        example = self.directory / 'runtime.env.example'
        example.write_text(self.example)
        self.assertEqual(self.example, preflight.read_example(example))
        link = self.directory / 'linked.env.example'
        link.symlink_to(example)
        parent = self.directory / 'linked-parent'
        parent.symlink_to(self.directory, target_is_directory=True)
        fifo = self.directory / 'pipe.env.example'
        os.mkfifo(fifo)
        huge = self.directory / 'huge.env.example'
        huge.write_bytes(b'x'*32769)
        live = self.directory / '.env'
        live.write_text('SYNTHETIC_SECRET_DO_NOT_PRINT')
        for path in [link, parent/'runtime.env.example', fifo, huge, live]:
            with self.subTest(path=path.name), self.assertRaises((renderer.InputRefused, OSError)):
                preflight.read_example(path)

    def test_preflight_render_remains_blocked_and_bad_config_never_writes(self):
        target = self.directory / 'offline.conf'
        code, report = self.report('--domain', 'staging.example.com', '--output', str(target))
        self.assertEqual(2, code)
        self.assertTrue(report['rendered'])
        self.assertFalse(report['deployment_ready'])
        self.assertEqual(renderer.render('staging.example.com'), target.read_text())
        bad = self.directory / 'unsafe.env.example'
        bad.write_text(self.example.replace('ACQUISITION_EXTERNAL_ENABLED=false',
                                            'ACQUISITION_EXTERNAL_ENABLED=true'))
        target = self.directory / 'never.conf'
        code, report = self.report('--env-file', str(bad), '--domain', 'staging.example.com',
                                   '--output', str(target))
        self.assertFalse(report['rendered'])
        self.assertFalse(target.exists())

    def test_invalid_cli_arguments_are_redacted_and_import_has_no_actions(self):
        error = io.StringIO()
        with contextlib.redirect_stderr(error), self.assertRaises(SystemExit):
            preflight.main(['--phase', 'SYNTHETIC_SECRET_DO_NOT_PRINT'])
        self.assertNotIn('SYNTHETIC_SECRET_DO_NOT_PRINT', error.getvalue())
        spec = importlib.util.spec_from_file_location('offline_import_check', OPS/'render_nginx.py')
        module = importlib.util.module_from_spec(spec)
        with patch.object(sys, 'argv', ['unrelated-process']), \
             patch.object(subprocess, 'run', side_effect=AssertionError('No subprocess')), \
             patch.object(socket, 'socket', side_effect=AssertionError('No socket')):
            spec.loader.exec_module(module)


if __name__ == '__main__':
    unittest.main()
