"""Capacity decision and failure paths, synthetic inputs only; not a Linux resource test."""
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('capacity', Path(__file__).resolve().parents[1]/'staging_media_capacity.py')
capacity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capacity)


class CapacityTests(unittest.TestCase):
    def test_busy_unknown_and_threshold(self):
        for state in ('active', 'activating', 'deactivating', 'reloading', '', 'unknown'):
            with self.subTest(state=state):
                self.assertEqual(capacity.decision(state, 999999), 'production_busy_or_unknown')
        for state in ('inactive', 'failed'):
            self.assertEqual(capacity.decision(state, capacity.MIN_AVAILABLE_KIB - 1), 'memory_low')
            self.assertEqual(capacity.decision(state, capacity.MIN_AVAILABLE_KIB), 'eligible')

    def test_inspection_failure_skips(self):
        with patch.object(capacity.subprocess, 'run', side_effect=subprocess.TimeoutExpired('systemctl', 3)):
            self.assertEqual(capacity.main(), 1)
        with patch.object(capacity.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, 'LoadState=loaded\nActiveState=inactive\n')):
            with patch.object(capacity.Path, 'read_text', return_value='MemAvailable: invalid kB\n'):
                self.assertEqual(capacity.main(), 1)

    def test_missing_production_unit_skips(self):
        with patch.object(capacity.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, 'LoadState=not-found\nActiveState=inactive\n')):
            with patch.object(capacity.Path, 'read_text', return_value='MemAvailable: 500000 kB\n'):
                self.assertEqual(capacity.main(), 1)

    def test_actual_entry_with_synthetic_readouts(self):
        with patch.object(capacity.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, 'LoadState=loaded\nActiveState=inactive\n')) as run:
            with patch.object(capacity.Path, 'read_text', return_value='MemAvailable: 500000 kB\n'):
                self.assertEqual(capacity.main(), 0)
            self.assertEqual(run.call_args.args[0], ['/usr/bin/systemctl', 'show', '--property=LoadState', '--property=ActiveState', 'h5-media-process.service'])


if __name__ == '__main__':
    unittest.main()
