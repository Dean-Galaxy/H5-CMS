"""Offline archive/rehearsal checks. Synthetic bytes are not a real PG backup."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

OPS=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(OPS))
import c_window_rehearsal as r


class Rehearsal(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name).resolve()
        self.directory=self.root/'.runtime/06/c-window/backups/synthetic'
        self.directory.mkdir(parents=True,mode=0o700);self.directory.parent.chmod(0o700)
        (self.directory/'media').mkdir(mode=0o700)
        for name in ('database.dump','environment-private','owner-private.json','fixture-private.json','manifest-private.json','state.json','media/synthetic.bin'):
            file=self.directory/name;file.write_bytes(b'synthetic bytes, not an actual backup');file.chmod(0o600)
        self.index={str(p.relative_to(self.directory)):hashlib.sha256(p.read_bytes()).hexdigest() for p in self.directory.rglob('*') if p.is_file()}
        self.write_index()

    def write_index(self):
        file=self.directory/'sha256.json';file.write_text(json.dumps(self.index));file.chmod(0o600)

    def test_exact_private_archive_index(self):
        self.assertEqual(r.verify_archive(self.directory,self.root),{'files_verified':7,'restoration_executed':False})

    def test_corruption_extra_missing_and_incomplete_media_rejected(self):
        file=self.directory/'database.dump';file.write_bytes(b'corrupt')
        with self.assertRaisesRegex(ValueError,'BACKUP_DIGEST_MISMATCH'):r.verify_archive(self.directory,self.root)
        file.write_bytes(b'synthetic bytes, not an actual backup')
        extra=self.directory/'unexpected';extra.write_bytes(b'extra')
        with self.assertRaises(ValueError):r.verify_archive(self.directory,self.root)
        extra.unlink();(self.directory/'media/synthetic.bin').unlink();del self.index['media/synthetic.bin'];self.write_index()
        with self.assertRaises(ValueError):r.verify_archive(self.directory,self.root)

    def test_foreign_directory_symlink_hardlink_and_public_file_rejected(self):
        with self.assertRaises(ValueError):r.verify_archive(self.root,self.root)
        file=self.directory/'database.dump';file.chmod(0o644)
        with self.assertRaises(ValueError):r.verify_archive(self.directory,self.root)
        file.chmod(0o600);os.link(file,self.root/'other-link')
        with self.assertRaises(ValueError):r.verify_archive(self.directory,self.root)
        (self.root/'other-link').unlink();file.unlink();file.symlink_to(self.directory/'state.json')
        with self.assertRaises(ValueError):r.verify_archive(self.directory,self.root)

    def test_storage_fault_requires_exact_candidate_token_and_short_expiry(self):
        php=OPS.parents[1]/'h5-platform/tests/Browser/Support/CWindowStorageFault.php'
        code='require $argv[1]; $base=["candidate"=>str_repeat("a",40),"nonce"=>str_repeat("b",64),"expires_at"=>1180]; foreach([[[],true],[["candidate"=>str_repeat("c",40)],false],[["nonce"=>str_repeat("c",64)],false],[["expires_at"=>1000],false],[["expires_at"=>1181],false],[["expires_at"=>"1100"],false]] as $case){if(Tests\\Browser\\Support\\CWindowStorageFault::matches(array_replace($base,$case[0]),str_repeat("a",40),str_repeat("b",64),1000)!==$case[1]){exit(1);}} echo "6 token boundaries";'
        run=subprocess.run(['/opt/homebrew/opt/php@8.4/bin/php','-r',code,str(php)],capture_output=True,text=True)
        self.assertEqual(run.returncode,0,run.stderr);self.assertEqual(run.stdout,'6 token boundaries')

    def test_plan_is_inert_and_never_claims_restore(self):
        run=subprocess.run([sys.executable,'-B',str(OPS/'c_window_rehearsal.py'),'--plan'],cwd=self.root,capture_output=True,text=True)
        self.assertEqual(run.returncode,0,run.stderr)
        plan=json.loads(run.stdout);self.assertFalse(plan['runtime_started']);self.assertFalse(plan['restoration_executed'])
        self.assertIn('fingerprint-after',plan['steps'])


if __name__=='__main__':unittest.main()
