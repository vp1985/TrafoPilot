#!/usr/bin/env python3
"""Root-only DEV configuration; never print or put the API key into argv/env."""
import os
import posixpath
from pathlib import Path
import stat
import subprocess
import tempfile

BASE = Path('/opt/dolibarr/dev')
SOURCE = Path('/home/vadmin/.config/trafopilot/lexware-dev.env')
CONTAINER = 'dolibarr-dev-dolibarr-1'
SECRET = 'lexware_test_api_key_read_only'
MOUNT = '/home/vadmin/projects/hwos-dolibarr/modules/hwoslexware:/var/www/html/custom/hwoslexware:ro'


def serialize_secret(value):
    data = (value + '\n').encode('utf-8')
    if not value or value == 'DEIN_READ_ONLY_KEY' or len(data) > 4096 or any(ord(c) <= 32 or ord(c) == 127 for c in value):
        raise ValueError('READ_ONLY_SECRET_INVALID')
    return data


def read_source(source, owner, trusted_root=Path('/')):
    """Walk pinned directory descriptors; never follow links or reopen by name."""
    source = Path(source)
    relative = source.relative_to(trusted_root)
    if not relative.parts or '..' in relative.parts:
        raise ValueError('SECRET_PATH_INVALID')
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC
    directory = os.open(trusted_root, flags | os.O_DIRECTORY)
    ancestry = Path(trusted_root)
    def check_ancestry(info):
        if info.st_uid not in (0, owner) or info.st_mode & 0o022:
            raise ValueError(f"SECRET_ANCESTRY_INVALID: {ancestry} mode {stat.S_IMODE(info.st_mode):04o}; require root/source-owner ancestry without group/other write, no symlinks; private source directories 0700, source owner mode 0600; installed target root:www-data 0440")
    try:
        for part in relative.parts[:-1]:
            info = os.fstat(directory)
            check_ancestry(info)
            child = os.open(part, flags | os.O_DIRECTORY, dir_fd=directory)
            os.close(directory)
            directory = child
            ancestry = ancestry / part
        info = os.fstat(directory)
        check_ancestry(info)
        fd = os.open(relative.parts[-1], flags | os.O_NONBLOCK, dir_fd=directory)
        try:
            info = os.fstat(fd)
            identity = os.stat(relative.parts[-1], dir_fd=directory, follow_symlinks=False)
            if (info.st_dev, info.st_ino) != (identity.st_dev, identity.st_ino) or not stat.S_ISREG(info.st_mode) or info.st_uid != owner or info.st_mode & 0o077 or info.st_size > 8192:
                raise ValueError('SECRET_FILE_PERMISSIONS_INVALID')
            data = b''
            while len(data) <= 8192:
                chunk = os.read(fd, 8193 - len(data))
                if not chunk: break
                data += chunk
            if len(data) > 8192:
                raise ValueError('READ_ONLY_SECRET_INVALID')
        finally:
            os.close(fd)
    finally:
        os.close(directory)
    values = [line.split('=', 1)[1].strip().strip('"\'') for line in data.decode().splitlines() if line.startswith('LEXWARE_TEST_API_KEY_READ_ONLY=')]
    if len(values) != 1:
        raise ValueError('READ_ONLY_SECRET_INVALID')
    return serialize_secret(values[0])


def prepare_compose(original):
    import yaml
    class UniqueLoader(yaml.SafeLoader):
        pass

    def mapping(loader, node):
        result = {}
        for key_node, value_node in node.value:
            key = loader.construct_object(key_node)
            if key in result:
                raise ValueError('DUPLICATE_COMPOSE_KEY')
            result[key] = loader.construct_object(value_node)
        return result

    UniqueLoader.add_constructor(yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, mapping)
    doc = yaml.load(original, Loader=UniqueLoader)
    for name in ('dolibarr', 'cron'):
        service = doc['services'][name]
        mounts = service.setdefault('volumes', [])
        intended = [v for v in mounts if (isinstance(v, str) and '/var/www/html/custom/hwoslexware' in v) or (isinstance(v, dict) and v.get('target') == '/var/www/html/custom/hwoslexware')]
        if not intended:
            mounts.append(MOUNT)
        elif intended != [MOUNT]:
            raise ValueError('UNEXPECTED_DEV_MOUNTS')
        secrets = service.setdefault('secrets', [])
        def competes(value):
            source = value.get('source') if isinstance(value, dict) else value
            target = value.get('target', source) if isinstance(value, dict) else source
            if not isinstance(target, str):
                raise ValueError('UNEXPECTED_DEV_SECRETS')
            effective = posixpath.normpath('/' + posixpath.join('/run/secrets', target).lstrip('/'))
            return source == SECRET or effective == '/run/secrets/' + SECRET
        intended = [v for v in secrets if competes(v)]
        if not intended:
            secrets.append(SECRET)
        elif intended != [SECRET]:
            raise ValueError('UNEXPECTED_DEV_SECRETS')
    definition = {'file': './secrets/' + SECRET}
    definitions = doc.setdefault('secrets', {})
    if SECRET in definitions and definitions[SECRET] != definition:
        raise ValueError('UNEXPECTED_DEV_SECRET_DEFINITION')
    definitions[SECRET] = definition
    return yaml.safe_dump(doc, sort_keys=False)


def stage(path, data, mode, uid, gid):
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.' + path.name + '-')
    try:
        with os.fdopen(fd, 'wb') as handle:
            os.fchmod(handle.fileno(), mode)
            os.fchown(handle.fileno(), uid, gid)
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        return Path(temporary)
    except BaseException:
        os.unlink(temporary)
        raise


def retain_original(base, original):
    """Publish a complete root-private backup without replacing an existing path."""
    backup = base / 'compose.before-lexware.yaml'
    def check_backup():
        info = backup.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600:
            raise ValueError('BACKUP_UNSAFE')
    if backup.exists() or backup.is_symlink():
        check_backup()
        return
    temporary = stage(backup, original, 0o600, 0, 0)
    try:
        try:
            os.link(temporary, backup)  # Atomic no-clobber publication, including symlinks.
        except FileExistsError:
            check_backup()
        directory = os.open(base, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)  # Persist the backup name before changing installed files.
        finally:
            os.close(directory)
    finally:
        temporary.unlink()


def install(base, data, validate):
    compose = base / 'compose.yaml'
    secret = base / 'secrets' / SECRET
    info = compose.stat()
    original = compose.read_bytes()
    candidate = stage(compose, prepare_compose(original.decode()).encode(), stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
    staged = rollback = None
    try:
        validate(candidate)  # No installed state has changed yet.
        retain_original(base, original)
        staged = stage(secret, data, 0o440, 0, 33)
        if secret.exists():
            old = secret.stat()
            rollback = stage(secret, secret.read_bytes(), 0o600, 0, 0)
        os.replace(staged, secret)
        try:
            os.replace(candidate, compose)
        except BaseException:
            try:
                if rollback:
                    restore = stage(secret, rollback.read_bytes(), stat.S_IMODE(old.st_mode), old.st_uid, old.st_gid)
                    try:
                        os.replace(restore, secret)
                    finally:
                        if restore.exists(): restore.unlink()
                else:
                    secret.unlink()
            except BaseException:
                if rollback:
                    retained = rollback
                    rollback = None
                    raise RuntimeError("INCOMPLETE_RECOVERY: rollback retained at " + str(retained)) from None
                raise RuntimeError("INCOMPLETE_RECOVERY: new secret could not be removed") from None
            raise
    finally:
        for path in (candidate, staged, rollback):
            if path and path.exists():
                path.unlink()


def main(argv=None):
    import argparse
    import pwd
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check-only', action='store_true', help='Validate source ancestry, permissions and key without Docker or writes')
    args = parser.parse_args(argv)
    if args.check_only:
        read_source(SOURCE, pwd.getpwnam('vadmin').pw_uid)
        print('SOURCE_PREFLIGHT_OK: trusted source; expected installed target root:www-data 0440; no changes made')
        return
    if os.geteuid() != 0:
        raise SystemExit('ROOT_REQUIRED')
    labels = subprocess.check_output(['docker', 'inspect', '--format', '{{ index .Config.Labels "com.docker.compose.project" }}|{{ index .Config.Labels "com.docker.compose.service" }}', CONTAINER], text=True).strip()
    if labels != 'dolibarr-dev|dolibarr':
        raise SystemExit('REFUSING_NON_DEV_TARGET')
    import pwd
    data = read_source(SOURCE, pwd.getpwnam('vadmin').pw_uid)
    install(BASE, data, lambda candidate: subprocess.run(['docker', 'compose', '--project-directory', str(BASE), '-f', str(candidate), 'config', '--quiet'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
    print('DEV_CONFIG_READY: read-only module and secret; recreate containers separately; no job enabled.')


if __name__ == '__main__':
    main()
