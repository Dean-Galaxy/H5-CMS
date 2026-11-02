"""Offline unit contracts and illustrative event fixtures, NOT a systemd emulator.
No subprocess, Laravel, filesystem markers, services, DB or cloud are started.
"""
from collections import defaultdict
from pathlib import Path
import shlex
import unittest

DEPLOY = Path(__file__).resolve().parents[3]/'deploy'
DIR = '/srv/h5/ops/media-process'
ENABLED, RUNNING = DIR+'/ENABLED', DIR+'/RUNNING'
SERVICE = 'h5-media-process.service'
TIMER = 'h5-media-process.timer'
FAILURE = 'h5-media-stop-on-failure.service'


def read(name):
    data = defaultdict(list)
    section = None
    for line in (DEPLOY/name).read_text().splitlines():
        line = line.strip()
        if not line or line.startswith(('#', ';')):
            continue
        if line.startswith('['):
            section = line[1:-1]
        else:
            key, value = line.split('=', 1)
            data[section, key].append(value)
    return data


def single(unit, section, key):
    values = unit.get((section, key), [])
    if len(values) != 1:
        raise AssertionError('Expected one '+key)
    return values[0]


def conditions(unit, markers):
    for condition in unit['Unit', 'ConditionPathExists']:
        negative = condition.startswith('!')
        present = condition.lstrip('!') in markers
        if present == negative:
            return False
    return True


class EventFixture:
    """Documented systemd event assumptions only; Linux must verify them separately."""
    def __init__(self):
        self.unit = read(SERVICE)
        self.markers = {ENABLED}
        self.durable = {ENABLED}
        self.active = False
        self.timer = True
        self.claims = 0

    def start(self, db_exit=0, sync_ok=True):
        if self.active:
            return 'already-activating'  # Same unit start job is not another process.
        if not conditions(self.unit, self.markers):
            return 'condition-skipped'
        if 1 <= db_exit <= 254:
            return 'db-skipped'  # ExecCondition skip is not failure.
        if db_exit != 0:
            return self.failure()
        self.markers.add(RUNNING)
        if not sync_ok:
            return self.failure()  # CLI never invoked after failed ExecStartPre.
        self.durable = set(self.markers)  # Assumes file+directory fsync succeeded.
        self.active = True
        self.claims += 1
        return 'cli-entered'

    def failure(self):
        self.active = False
        self.markers.discard(ENABLED)
        # Failure unit fsyncs the ENABLED deletion, not an unsynced RUNNING file.
        self.durable.discard(ENABLED)
        self.timer = False
        return 'failure-held'

    def finish(self, event):
        self.active = False
        if event == 'success':
            self.markers.remove(RUNNING)
            self.durable = set(self.markers)
            return 'complete'
        if event == 'operator-stop':
            return 'stopped-with-running-marker'
        return self.failure()

    def power_loss(self):
        self.active = False
        self.markers = set(self.durable)


class MediaNativeUnitContracts(unittest.TestCase):
    def setUp(self):
        self.s, self.t, self.f = read(SERVICE), read(TIMER), read(FAILURE)

    def test_default_absent_enable_and_residual_running_block_both_units(self):
        for unit in (self.s, self.t):
            self.assertEqual([ENABLED, '!'+RUNNING], unit['Unit', 'ConditionPathExists'])
            self.assertFalse(conditions(unit, set()))
            self.assertFalse(conditions(unit, {ENABLED, RUNNING}))
            self.assertTrue(conditions(unit, {ENABLED}))
        self.assertFalse((Path('/srv/h5/ops/media-process/ENABLED')).is_relative_to(DEPLOY))

    def test_single_cli_guard_cwd_and_no_automatic_database_or_fpm_dependency(self):
        self.assertEqual('h5', single(self.s, 'Service', 'User'))
        self.assertEqual('h5', single(self.s, 'Service', 'Group'))
        self.assertEqual('/srv/h5/current/h5-platform', single(self.s, 'Service', 'WorkingDirectory'))
        self.assertEqual('APP_ENV=staging', single(self.s, 'Service', 'Environment'))
        self.assertEqual('/usr/bin/systemctl --quiet is-active postgresql@17-main.service',
                         single(self.s, 'Service', 'ExecCondition'))
        self.assertEqual(['/usr/bin/php8.4', '-d', 'memory_limit=128M', 'artisan',
                          'content:process-media', '--limit=1'],
                         shlex.split(single(self.s, 'Service', 'ExecStart')))
        for unit in (self.s, self.t, self.f):
            self.assertNotIn(('Unit', 'Requires'), unit)
            self.assertNotIn(('Unit', 'Wants'), unit)
        self.assertTrue(any('--expect-env=staging --require-cache' in command for command in self.s['Service', 'ExecStartPre']))

    def test_root_marker_creation_and_both_fsync_operands_precede_cli(self):
        self.assertEqual([
            '/usr/bin/test ! -w '+DIR,
            '!/usr/bin/test -O '+DIR,
            '/usr/bin/php8.4 -d memory_limit=128M /srv/h5/current/scripts/ops/check_runtime.php --expect-env=staging --require-cache',
            '!/usr/bin/touch -- '+RUNNING,
            '!/usr/bin/sync -- '+RUNNING+' '+DIR,
        ], self.s['Service', 'ExecStartPre'])
        self.assertTrue(single(self.s, 'Service', 'ExecStart').endswith('--limit=1'))
        # No '-' error-ignore prefix: a failed sync must prevent ExecStart.
        self.assertTrue(all(not x.startswith('-') for x in self.s['Service', 'ExecStartPre']))

    def test_only_success_post_clears_running_and_syncs_parent(self):
        self.assertEqual(['!/usr/bin/rm -- '+RUNNING, '!/usr/bin/sync -- '+DIR],
                         self.s['Service', 'ExecStartPost'])
        self.assertNotIn(('Service', 'ExecStopPost'), self.s)
        self.assertNotIn(('Service', 'ExecStop'), self.s)
        self.assertEqual('no', single(self.s, 'Service', 'Restart'))
        self.assertEqual('no', single(self.s, 'Service', 'RemainAfterExit'))

    def test_bang_root_steps_keep_sandbox_no_plus_sudo_or_shell(self):
        for unit in (self.s, self.f):
            self.assertEqual('strict', single(unit, 'Service', 'ProtectSystem'))
            self.assertEqual('yes', single(unit, 'Service', 'NoNewPrivileges'))
            self.assertEqual('', single(unit, 'Service', 'CapabilityBoundingSet'))
            for (section, key), values in unit.items():
                if section != 'Service' or not key.startswith('Exec'):
                    continue
                for command in values:
                    self.assertFalse(command.startswith(('+', '-', '|')))
                    for word in shlex.split(command):
                        self.assertNotIn(word, ['sudo', '/usr/bin/sudo', 'sh', '/bin/sh', 'bash', '/bin/bash'])
                        self.assertNotIn('$', word)
                        self.assertNotIn('%', word)

    def test_persistent_write_mounts_are_exact_and_marker_dac_is_root_only(self):
        self.assertEqual({'/srv/h5/shared/media', '/srv/h5/shared/storage/logs', DIR},
                         set(single(self.s, 'Service', 'ReadWritePaths').split()))
        self.assertEqual(DIR, single(self.f, 'Service', 'ReadWritePaths'))
        # POSIX mode fixture only; not a claim that Linux mounts/UIDs were exercised.
        mode, owner, group = 0o755, 0, 0
        for uid, groups, expected in [(999, {989}, False), (0, {0}, True)]:
            bits = (mode >> 6) if uid == owner else ((mode >> 3) if group in groups else mode)
            self.assertEqual(expected, bool(bits & 0o2))
        self.assertEqual(0, 0o600 & 0o022)  # Existing root marker must not be group/other writable.
        for forbidden in ['/etc/h5', '/srv/h5/shared', '/srv/h5/shared/storage',
                          '/srv/h5/current/h5-platform/bootstrap/cache']:
            self.assertNotIn(forbidden, single(self.s, 'Service', 'ReadWritePaths').split())

    def test_timer_exact_single_unit_and_no_persistent_catchup(self):
        self.assertEqual(SERVICE, single(self.t, 'Timer', 'Unit'))
        self.assertEqual('*-*-* *:*:00 UTC', single(self.t, 'Timer', 'OnCalendar'))
        self.assertEqual('false', single(self.t, 'Timer', 'Persistent'))
        self.assertEqual('1s', single(self.t, 'Timer', 'AccuracySec'))
        self.assertEqual('timers.target', single(self.t, 'Install', 'WantedBy'))
        self.assertNotIn(('Install', 'WantedBy'), self.s)
        self.assertNotIn(('Install', 'WantedBy'), self.f)
        self.assertNotIn('@', single(self.t, 'Timer', 'Unit'))

    def test_timeout_and_oom_have_cgroup_cleanup_not_a_restart_loop(self):
        for key, value in [('Type', 'oneshot'), ('TimeoutStartSec', '15min'), ('TimeoutStopSec', '5s'),
                           ('KillMode', 'control-group'), ('SendSIGKILL', 'yes'), ('OOMPolicy', 'kill'),
                           ('CPUQuota', '50%'), ('MemoryMax', '384M')]:
            self.assertEqual(value, single(self.s, 'Service', key))
        self.assertNotIn(('Service', 'RuntimeMaxSec'), self.s)
        self.assertEqual(FAILURE, single(self.s, 'Unit', 'OnFailure'))

    def test_failure_actions_are_fixed_never_clear_running_or_start_services(self):
        self.assertEqual('root', single(self.f, 'Service', 'User'))
        self.assertEqual([
            '/usr/bin/rm -f -- '+ENABLED,
            '/usr/bin/sync -- '+DIR,
            '/usr/bin/systemctl --no-block stop '+TIMER,
        ], self.f['Service', 'ExecStart'])
        self.assertEqual('AF_UNIX', single(self.f, 'Service', 'RestrictAddressFamilies'))
        self.assertEqual('no', single(self.f, 'Service', 'Restart'))
        self.assertFalse(any(RUNNING in x for x in self.f['Service', 'ExecStart']))

    def test_no_generic_queue_schedule_publication_or_acceptance_supervisor(self):
        commands = '\n'.join(command for unit in (self.s, self.f)
                             for (section, key), values in unit.items()
                             if section == 'Service' and key.startswith('Exec') for command in values)
        for forbidden in ['queue:', 'schedule:', 'publish', 'billing:', 'acquisition:',
                          'media_acceptance', 'python', 'migrate', 'seed', 'attempt', 'ENABLED\nExecStart=']:
            self.assertNotIn(forbidden, commands)


class NativeEventFixtures(unittest.TestCase):
    def test_database_skip_creates_no_marker_and_no_task_but_next_tick_can_run(self):
        f = EventFixture()
        self.assertEqual('db-skipped', f.start(db_exit=3))
        self.assertEqual({ENABLED}, f.markers)
        self.assertEqual(0, f.claims)
        self.assertTrue(f.timer)
        self.assertEqual('cli-entered', f.start())
        self.assertEqual(1, f.claims)

    def test_abnormal_condition_or_sync_failure_holds_without_entering_cli(self):
        for kwargs in [{'db_exit': 255}, {'sync_ok': False}]:
            with self.subTest(kwargs=kwargs):
                f = EventFixture()
                self.assertEqual('failure-held', f.start(**kwargs))
                self.assertEqual(0, f.claims)
                self.assertNotIn(ENABLED, f.markers)
                self.assertFalse(f.timer)
                f.power_loss()
                self.assertNotIn(ENABLED, f.markers)
                self.assertEqual(0, f.claims)

    def test_success_allows_next_single_item_but_active_unit_does_not_reenter(self):
        f = EventFixture()
        self.assertEqual('cli-entered', f.start())
        self.assertEqual('already-activating', f.start())
        self.assertEqual(1, f.claims)
        self.assertEqual('complete', f.finish('success'))
        self.assertNotIn(RUNNING, f.markers)
        self.assertEqual('cli-entered', f.start())
        self.assertEqual(2, f.claims)

    def test_nonzero_timeout_oom_keep_running_and_disarm_without_retry(self):
        for event in ['nonzero', 'timeout', 'oom']:
            with self.subTest(event=event):
                f = EventFixture();f.start()
                self.assertEqual('failure-held', f.finish(event))
                self.assertEqual({RUNNING}, f.markers)
                self.assertEqual('condition-skipped', f.start())
                self.assertEqual(1, f.claims)

    def test_operator_stop_keeps_inflight_marker_even_without_onfailure(self):
        f = EventFixture();f.start()
        self.assertEqual('stopped-with-running-marker', f.finish('operator-stop'))
        self.assertIn(RUNNING, f.markers)
        self.assertEqual('condition-skipped', f.start())
        self.assertEqual(1, f.claims)

    def test_power_loss_only_assumes_completed_file_and_directory_sync(self):
        f = EventFixture();f.start();f.power_loss()
        self.assertIn(RUNNING, f.markers)
        self.assertEqual('condition-skipped', f.start())
        # This is the durability assumption, not an actual fsync/power-cut test.
        g = EventFixture();g.markers.add(RUNNING);g.power_loss()
        self.assertNotIn(RUNNING, g.markers)
        self.assertEqual(0, g.claims)  # No CLI may start before the pre-sync completes.


if __name__ == '__main__':
    unittest.main()
