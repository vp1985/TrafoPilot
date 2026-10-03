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
        self.assertTrue(any('rm' in call and any(x.startswith('/tmp/hwos-ui-private-test') for x in call) for call in calls))

    def test_initial_create_failure_never_invents_recovery_metadata(self):
        calls = []
        def run(argv, **kwargs):
            calls.append((argv, kwargs))
            if 'php' in argv:
                data = json.loads(kwargs['input'])
                if data['action'] == 'create':
                    return subprocess.CompletedProcess(argv, 1, '', 'UI_FIXTURE_RECOVERY_WRITE_FAILED')
                self.assertEqual(data, {'action': 'cleanup', 'login': data['login']})
                return subprocess.CompletedProcess(argv, 1, json.dumps({'cleaned': False, 'attempted': [], 'failures': ['baseline:UNVERIFIED_BASELINE']}), 'UI_FIXTURE_INCOMPLETE_RECOVERY: UNVERIFIED_BASELINE')
            return subprocess.CompletedProcess(argv, 0)
        with patch.object(sys, 'argv', ['lexware-ui-dev.py']), patch('subprocess.check_output', side_effect=['dolibarr-dev|dolibarr\n', '750\n', '/tmp/hwos-ui-private-test\n']), patch('subprocess.run', side_effect=run), patch('sys.stdout', new_callable=io.StringIO) as output:
            with self.assertRaisesRegex(RuntimeError, 'UI_FIXTURE_INCOMPLETE_RECOVERY'):
                runpy.run_path(str(ROOT / 'scripts/lexware-ui-dev.py'), run_name='__main__')
        self.assertIn('DEV_RECOVERY_FAILED_PHASES=baseline', output.getvalue())
        self.assertNotIn('DEV_RECOVERY_METADATA=', output.getvalue(), 'must not claim a journal exists when none was obtained')
        self.assertTrue(any('rm' in a and '-f' in a and a[-1].endswith('/fixture.php') for a, k in calls))

    def test_populate_failure_recovers_from_private_journal(self):
        calls = []
        def run(argv, **kwargs):
            calls.append((argv, kwargs))
            if 'php' in argv:
                data = json.loads(kwargs['input'])
                if data['action'] == 'create':
                    return subprocess.CompletedProcess(argv, 0, json.dumps({'id': 42, 'active': {}, 'permission_read': True, 'module_enabled': True}), '')
                if data['action'] == 'populate':
                    return subprocess.CompletedProcess(argv, 1, '', 'UI_FIXTURE_INJECTED')
                return subprocess.CompletedProcess(argv, 0, '{"cleaned":true}', '')
            return subprocess.CompletedProcess(argv, 0)
        with patch.object(sys, 'argv', ['lexware-ui-dev.py']), patch('subprocess.check_output', side_effect=['dolibarr-dev|dolibarr\n', '750\n', '/tmp/hwos-ui-private-test\n']), patch('subprocess.run', side_effect=run), patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaises(SystemExit): runpy.run_path(str(ROOT / 'scripts/lexware-ui-dev.py'), run_name='__main__')
        php = [(a,k) for a,k in calls if 'php' in a]
        self.assertEqual([json.loads(k['input'])['action'] for a,k in php], ['create', 'populate', 'cleanup'])
        self.assertTrue(all('HWOS_FIXTURE_RECOVERY=/tmp/hwos-ui-private-test/recovery.json' in a for a,k in php), 'populate recovery metadata must be available before normal JSON')

    def test_cleanup_reports_all_independent_python_failures(self):
        calls = []
        def run(argv, **kwargs):
            calls.append(argv)
            if 'php' in argv:
                action = json.loads(kwargs['input'])['action']
                if action == 'create':
                    return subprocess.CompletedProcess(argv, 0, json.dumps({'id':42, 'active':{}, 'permission_read':True, 'module_enabled':True}), '')
                return subprocess.CompletedProcess(argv, 1, '', 'UI_FIXTURE_CLEANUP_FAILED')
            if 'chmod' in argv and '750' in argv: raise RuntimeError('MODE_FAILED')
            if 'rm' in argv: raise RuntimeError('HELPER_FAILED')
            return subprocess.CompletedProcess(argv, 0)
        with patch.object(sys, 'argv', ['lexware-ui-dev.py']), patch('subprocess.check_output', side_effect=['dolibarr-dev|dolibarr\n', '750\n', '/tmp/hwos-ui-private-test\n']), patch('subprocess.run', side_effect=run), patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaisesRegex(RuntimeError, 'fixture; custom-mode; helper'):
                runpy.run_path(str(ROOT / 'scripts/lexware-ui-dev.py'), run_name='__main__')
        self.assertTrue(any('rm' in a for a in calls))

    def test_python_reports_each_php_cleanup_phase(self):
        phases = ['csrf', 'history', 'user', 'lexware', 'core', 'helper']
        def run(argv, **kwargs):
            if 'php' in argv:
                action = json.loads(kwargs['input'])['action']
                if action == 'create':
                    return subprocess.CompletedProcess(argv, 0, json.dumps({'id':42, 'active':{}, 'permission_read':True, 'module_enabled':True}), '')
                if action == 'cleanup':
                    return subprocess.CompletedProcess(argv, 1, json.dumps({'cleaned':False, 'attempted':phases, 'failures':['history:UI_FIXTURE_INJECTED','core:UI_FIXTURE_RESTORE_FAILED']}), 'UI_FIXTURE_INCOMPLETE_RECOVERY')
                return subprocess.CompletedProcess(argv, 1, '', 'UI_FIXTURE_INJECTED')
            return subprocess.CompletedProcess(argv, 0)
        with patch.object(sys, 'argv', ['lexware-ui-dev.py']), patch('subprocess.check_output', side_effect=['dolibarr-dev|dolibarr\n', '750\n', '/tmp/hwos-ui-private-test\n']), patch('subprocess.run', side_effect=run), patch('sys.stdout', new_callable=io.StringIO) as output:
            with self.assertRaises(RuntimeError): runpy.run_path(str(ROOT / 'scripts/lexware-ui-dev.py'), run_name='__main__')
        self.assertIn('DEV_RECOVERY_PHASES=csrf,history,user,lexware,core,helper', output.getvalue(), 'PHP recovery phase report must survive subprocess failure')
        self.assertIn('DEV_RECOVERY_FAILED_PHASES=history,core', output.getvalue())

    def test_http_200_csrf_requires_native_rejection_body(self):
        source = (ROOT / 'scripts/lexware-ui-dev.py').read_text()
        block = source.split("stage='csrf-global-disabled'", 1)[1].split("stage='permission-rejection'", 1)[0]
        self.assertIn('assert_csrf_rejection', block, 'HTTP 200 forged-token response must explicitly prove native rejection')

    def test_native_csrf_response_evidence(self):
        check = runpy.run_path(str(ROOT / 'scripts/lexware-http-assertions.py'))['assert_csrf_rejection']
        for token in (None, 'forgedtoken'):
            for status in (200, 403):
                with self.assertRaisesRegex(RuntimeError, 'NATIVE_CSRF_REJECTION_NOT_CONFIRMED'):
                    check(status, '<html>state unchanged; generic CSRF form token</html>', token)
        check(403, 'refused by CSRF protection in main.inc.php. Token not provided.', None)
        check(200, 'Security token has expired, so action has been canceled. Please try again.', 'forgedtoken')
        check(200, 'Die Seite war zu lange inaktiv (Sicherheitstoken abgelaufen). Bitte führen Sie die Aktion erneut aus.', 'forgedtoken')

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
