import io
import json
import pathlib
import runpy
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[1]

class HelperTests(unittest.TestCase):
    def test_helper_removed_even_if_permission_restore_fails(self):
        calls = []
        def run(argv, **kwargs):
            calls.append(argv)
            if 'php' in argv:
                raise RuntimeError('fixture creation failed')
            if 'chmod' in argv and '750' in argv:
                raise RuntimeError('restore failed')
            return subprocess.CompletedProcess(argv, 0)
        with patch.object(sys, 'argv', ['lexware-ui-dev.py']), patch('subprocess.check_output', side_effect=['dolibarr-dev|dolibarr\n', '750\n', '/tmp/hwos-ui-private-test\n']), patch('subprocess.run', side_effect=run), patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(RuntimeError): runpy.run_path(str(ROOT / 'scripts/lexware-ui-dev.py'), run_name='__main__')
        self.assertTrue(any('cp' in call and call[-1].endswith('/tmp/hwos-ui-private-test/fixture.php') for call in calls))
        self.assertTrue(any('rm' in call and '/tmp/hwos-ui-private-test' in call for call in calls))

    def test_php_tests_use_invocation_private_container_directory(self):
        import os
        import tempfile
        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            log = directory / 'calls.jsonl'
            sudo = directory / 'sudo'
            sudo.write_text('''#!/usr/bin/python3
import json, os, sys
args=sys.argv[1:]
with open(os.environ['HELPER_LOG'], 'a') as output: output.write(json.dumps(args)+'\\n')
if 'inspect' in args: print('dolibarr-dev|dolibarr')
if 'mktemp' in args: print('/tmp/hwos-tests-fixture-private')
''')
            sudo.chmod(0o700)
            python = directory / 'python3'
            python.write_text('#!/bin/sh\nexit 0\n'); python.chmod(0o700)
            result = subprocess.run(['bash', str(ROOT / 'scripts/test-dev.sh')], env={**os.environ, 'PATH': str(directory)+':'+os.environ['PATH'], 'HELPER_LOG': str(log)}, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            calls = [json.loads(line) for line in log.read_text().splitlines()]
            copies = [call[-1].split(':', 1)[1] for call in calls if 'cp' in call and '/tests/php/' in call[-2]]
            self.assertTrue(copies)
            self.assertTrue(all(p.startswith('/tmp/hwos-tests-fixture-private/') for p in copies), copies)
            self.assertTrue(any('mktemp' in call and '-d' in call for call in calls))

    def test_invocation_lock_serializes_and_releases(self):
        import importlib.util
        import tempfile
        import os
        spec = importlib.util.spec_from_file_location('ui_lock', ROOT / 'scripts/lexware-ui-lock.py')
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'lock'
            with module.invocation_lock(path):
                self.assertEqual(path.stat().st_mode & 0o777, 0o600)
                code = "import runpy,sys; m=runpy.run_path(sys.argv[1]);\nwith m['invocation_lock'](sys.argv[2]): print('acquired',flush=True)"
                child = subprocess.Popen([sys.executable, '-c', code, str(ROOT / 'scripts/lexware-ui-lock.py'), str(path)], stdout=subprocess.PIPE, text=True)
                try:
                    with self.assertRaises(subprocess.TimeoutExpired): child.communicate(timeout=0.2)
                finally:
                    pass
            output, _ = child.communicate(timeout=5)
            self.assertEqual(child.returncode, 0); self.assertEqual(output.strip(), 'acquired')
            with self.assertRaises(RuntimeError):
                with module.invocation_lock(path): raise RuntimeError('synthetic')
            with module.invocation_lock(path): pass
            path.unlink(); path.symlink_to(pathlib.Path(directory) / 'missing')
            with self.assertRaises(OSError):
                with module.invocation_lock(path): pass
