import importlib.util
import pathlib
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('configure', pathlib.Path(__file__).resolve().parents[1] / 'scripts/configure-lexware-dev.py')
m = importlib.util.module_from_spec(spec)

class ConfigurationTests(unittest.TestCase):
    def test_import_has_no_host_side_effects(self):
        with patch('subprocess.check_output', side_effect=AssertionError('host operation during import')):
            spec.loader.exec_module(m)

    def setUp(self):
        spec.loader.exec_module(m)

    def test_partial_services_and_duplicates(self):
        import yaml
        source = {'services': {'dolibarr': {'volumes': [m.MOUNT], 'secrets': [m.SECRET]}, 'cron': {}}}
        result = yaml.safe_load(m.prepare_compose(yaml.safe_dump(source)))
        for service in result['services'].values():
            self.assertEqual(service['volumes'].count(m.MOUNT), 1)
            self.assertEqual(service['secrets'].count(m.SECRET), 1)
        for key, value in [('volumes', m.MOUNT), ('secrets', m.SECRET)]:
            source['services']['dolibarr'][key].append(value)
            with self.assertRaises(ValueError): m.prepare_compose(yaml.safe_dump(source))
            source['services']['dolibarr'][key].pop()

    def test_validation_failure_preserves_installed_state(self):
        with tempfile.TemporaryDirectory() as directory:
            base = pathlib.Path(directory)
            (base / 'secrets').mkdir()
            compose = base / 'compose.yaml'
            compose.write_text('services: {dolibarr: {}, cron: {}}')
            secret = base / 'secrets' / m.SECRET
            secret.write_bytes(b'previous-fixture')
            before = (compose.read_bytes(), secret.read_bytes(), compose.stat().st_mode, secret.stat().st_mode)
            def reject(candidate):
                self.assertNotEqual(candidate, compose)
                self.assertEqual(secret.read_bytes(), before[1])
                raise ValueError('candidate invalid')
            with self.assertRaises(ValueError): m.install(base, b'fixture\n', reject)
            self.assertEqual(before, (compose.read_bytes(), secret.read_bytes(), compose.stat().st_mode, secret.stat().st_mode))
            self.assertEqual(sorted(p.name for p in base.iterdir()), ['compose.yaml', 'secrets'])

    def test_install_success_and_compose_failure_rollback(self):
        for fail in (False, True):
            with tempfile.TemporaryDirectory() as directory:
                base = pathlib.Path(directory)
                (base / 'secrets').mkdir()
                compose = base / 'compose.yaml'
                compose.write_text('services: {dolibarr: {}, cron: {}}')
                secret = base / 'secrets' / m.SECRET
                secret.write_bytes(b'old-fixture')
                old_mode = secret.stat().st_mode
                original = compose.read_bytes()
                replace = m.os.replace
                def exchange(source, target):
                    if fail and target == compose: raise OSError('fixture install failure')
                    replace(source, target)
                with patch.object(m.os, 'fchown'), patch.object(m.os, 'replace', side_effect=exchange):
                    if fail:
                        with self.assertRaises(OSError): m.install(base, b'new-fixture\n', lambda p: None)
                        self.assertEqual(compose.read_bytes(), original)
                        self.assertEqual(secret.read_bytes(), b'old-fixture')
                        self.assertEqual(secret.stat().st_mode, old_mode)
                    else:
                        m.install(base, b'new-fixture\n', lambda p: None)
                        self.assertEqual(secret.read_bytes(), b'new-fixture\n')
                        self.assertNotEqual(compose.read_bytes(), original)
                self.assertEqual(sorted(p.name for p in base.iterdir()), ['compose.before-lexware.yaml', 'compose.yaml', 'secrets'])

    def test_private_durable_original_backup_is_never_overwritten(self):
        with tempfile.TemporaryDirectory() as directory:
            base = pathlib.Path(directory)
            (base / 'secrets').mkdir()
            compose = base / 'compose.yaml'
            original = b'services: {dolibarr: {}, cron: {}}\n'
            compose.write_bytes(original)
            backup = base / 'compose.before-lexware.yaml'
            real_chown = m.os.fchown if m.os.geteuid() == 0 else None
            with patch.object(m.os, 'fchown', side_effect=real_chown) as chown, patch.object(m.os, 'fsync', wraps=m.os.fsync) as sync:
                m.install(base, b'fixture\n', lambda p: None)
                self.assertEqual(backup.read_bytes(), original)
                self.assertEqual(backup.stat().st_mode & 0o777, 0o600)
                if m.os.geteuid() == 0:
                    self.assertEqual((backup.stat().st_uid, backup.stat().st_gid), (0, 0))
                self.assertIn(unittest.mock.call(unittest.mock.ANY, 0, 0), chown.call_args_list)
                self.assertGreaterEqual(sync.call_count, 4)
                first = backup.stat()
                if m.os.geteuid() != 0:
                    # fchown is mocked above; emulate the validated root-owned backup.
                    original_lstat = pathlib.Path.lstat
                    def root_backup(path, *args, **kwargs):
                        info = original_lstat(path, *args, **kwargs)
                        if path == backup:
                            values = list(info); values[4] = 0
                            return m.os.stat_result(values)
                        return info
                    with patch.object(pathlib.Path, 'lstat', root_backup):
                        m.install(base, b'next-fixture\n', lambda p: None)
                else:
                    m.install(base, b'next-fixture\n', lambda p: None)
                self.assertEqual(backup.read_bytes(), original)
                self.assertEqual(backup.stat().st_ino, first.st_ino)
            self.assertEqual(sorted(p.name for p in base.iterdir()), ['compose.before-lexware.yaml', 'compose.yaml', 'secrets'])

    def test_duplicate_yaml_keys_rejected(self):
        with self.assertRaises(ValueError):
            m.prepare_compose('services: {dolibarr: {}, cron: {}}\nsecrets: {}\nsecrets: {}\n')

    def test_serialized_bytes_boundary(self):
        self.assertEqual(len(m.serialize_secret('x' * 4095)), 4096)
        for value in ['x' * 4096, 'é' * 2048]:
            with self.assertRaises(ValueError): m.serialize_secret(value)


class ReviewRegressionTests(unittest.TestCase):
    def setUp(self): spec.loader.exec_module(m)

    def test_competing_effective_secret_target(self):
        import yaml
        for target in ['//run/secrets/' + m.SECRET, '///run/secrets/' + m.SECRET, m.SECRET, '/run/secrets/' + m.SECRET, './' + m.SECRET, '/run/secrets/extra/../' + m.SECRET]:
            doc = {'services': {'dolibarr': {'secrets': [{'source': 'other', 'target': target}]}, 'cron': {}}}
            encoded = yaml.safe_dump(doc)
            with patch.object(yaml, 'safe_dump', side_effect=AssertionError('serialized competing secret')):
                with self.assertRaisesRegex(ValueError, 'UNEXPECTED_DEV_SECRETS'):
                    m.prepare_compose(encoded)

    def test_check_only_reports_unsafe_ancestry_without_mutations(self):
        import os
        with tempfile.TemporaryDirectory(dir=pathlib.Path(__file__).resolve().parents[1]) as directory:
            base = pathlib.Path(directory)
            parent = base / 'config'; parent.mkdir(); parent.chmod(0o775)
            source = parent / 'key.env'
            source.write_text('LEXWARE_TEST_API_KEY_READ_ONLY=synthetic\n'); source.chmod(0o600)
            reader = m.read_source
            with patch.object(m, 'read_source', side_effect=lambda path, owner: reader(path, owner, trusted_root=base)), patch.object(m, 'SOURCE', source), patch('pwd.getpwnam', return_value=type('Owner', (), {'pw_uid': os.getuid()})()), patch.object(m, 'install', side_effect=AssertionError('install called')), patch.object(m.subprocess, 'check_output', side_effect=AssertionError('docker called')):
                with self.assertRaisesRegex(ValueError, 'SECRET_ANCESTRY_INVALID.*0775.*0700'):
                    m.main(['--check-only'])
                parent.chmod(0o700)
                with patch('builtins.print') as output:
                    m.main(['--check-only'])
                self.assertIn('SOURCE_PREFLIGHT_OK', output.call_args[0][0])

    def test_source_descriptor_and_trusted_ancestry(self):
        with tempfile.TemporaryDirectory() as directory:
            base = pathlib.Path(directory)
            source = base / 'key.env'
            source.write_text('LEXWARE_TEST_API_KEY_READ_ONLY=fixture\n'); source.chmod(0o600)
            self.assertEqual(m.read_source(source, m.os.getuid(), trusted_root=base), b'fixture\n')
            source.chmod(0o644)
            with self.assertRaises(ValueError): m.read_source(source, m.os.getuid(), trusted_root=base)
            source.chmod(0o600)
            link = base / 'link'; link.symlink_to(source)
            with self.assertRaises((ValueError, OSError)): m.read_source(link, m.os.getuid(), trusted_root=base)
            parent = base / 'unsafe'; parent.mkdir(mode=0o777); parent.chmod(0o777)
            nested = parent / 'key'; nested.write_bytes(source.read_bytes()); nested.chmod(0o600)
            with self.assertRaises(ValueError): m.read_source(nested, m.os.getuid(), trusted_root=base)
            with self.assertRaises(ValueError): m.read_source(source, m.os.getuid() + 1, trusted_root=base)
            ancestor = base / 'ancestor'; ancestor.symlink_to(parent, target_is_directory=True)
            with self.assertRaises((ValueError, OSError)): m.read_source(ancestor / 'key', m.os.getuid(), trusted_root=base)
            fifo = base / 'fifo'; m.os.mkfifo(fifo, 0o600)
            with self.assertRaises(ValueError): m.read_source(fifo, m.os.getuid(), trusted_root=base)
            real_open = m.os.open
            def swap_after_open(path, *args, **kwargs):
                fd = real_open(path, *args, **kwargs)
                if path == source.name and 'dir_fd' in kwargs:
                    source.unlink(); source.write_bytes(b'LEXWARE_TEST_API_KEY_READ_ONLY=replaced\n'); source.chmod(0o600)
                return fd
            with patch.object(m.os, 'open', side_effect=swap_after_open):
                with self.assertRaises(ValueError): m.read_source(source, m.os.getuid(), trusted_root=base)
            source.write_bytes(b'x' * 8193)
            with self.assertRaises(ValueError): m.read_source(source, m.os.getuid(), trusted_root=base)

    def test_reject_unsafe_existing_backup(self):
        for kind in ['symlink', 'directory', 'mode', 'owner']:
            with tempfile.TemporaryDirectory() as directory:
                base = pathlib.Path(directory); backup = base / 'compose.before-lexware.yaml'
                if kind == 'symlink': backup.symlink_to(base / 'missing')
                elif kind == 'directory': backup.mkdir()
                else:
                    backup.write_bytes(b'old'); backup.chmod(0o644 if kind == 'mode' else 0o600)
                    if kind == 'owner':
                        if m.os.geteuid() != 0: continue
                        m.os.chown(backup, 12345, 12345)
                with self.assertRaisesRegex(ValueError, 'BACKUP_UNSAFE'):
                    m.retain_original(base, b'original')

    def test_double_failure_retains_private_rollback(self):
        with tempfile.TemporaryDirectory() as directory:
            base = pathlib.Path(directory); (base / 'secrets').mkdir()
            compose = base / 'compose.yaml'; compose.write_text('services: {dolibarr: {}, cron: {}}')
            secret = base / 'secrets' / m.SECRET; secret.write_bytes(b'old-fixture'); secret.chmod(0o644)
            replace = m.os.replace; calls = 0
            def fail(source, target):
                nonlocal calls
                calls += 1
                if calls >= 2: raise OSError('private failure detail')
                return replace(source, target)
            with patch.object(m.os, 'fchown'), patch.object(m.os, 'replace', side_effect=fail):
                with self.assertRaisesRegex(RuntimeError, '^INCOMPLETE_RECOVERY: rollback retained at '):
                    m.install(base, b'new-fixture', lambda p: None)
            retained = [p for p in secret.parent.iterdir() if p != secret]
            self.assertEqual(len(retained), 1)
            self.assertEqual(retained[0].read_bytes(), b'old-fixture')
            self.assertEqual(retained[0].stat().st_mode & 0o777, 0o600)

if __name__ == '__main__': unittest.main()
