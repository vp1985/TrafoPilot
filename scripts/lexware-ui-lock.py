"""Host-local serialization of DEV HTTP fixture state mutations."""
from contextlib import contextmanager
import fcntl
import os
import stat

@contextmanager
def invocation_lock(path):
    fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1:
            raise RuntimeError('UI_FIXTURE_LOCK_UNSAFE')
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)
    # Keep the inode: unlinking would let later invocations bypass existing waiters.
