#!/usr/bin/env python3
"""DEV-only launcher. Send only the read-only key through stdin, never argv/logs."""
import argparse
import json
import os
from pathlib import Path
import stat
import subprocess
import sys

parser = argparse.ArgumentParser()
parser.add_argument('--mode', choices=['smoke', 'dry', 'full', 'incremental'], default='smoke')
parser.add_argument('--secret-file', default='/home/vadmin/.config/trafopilot/lexware-dev.env')
parser.add_argument('--run', type=int, default=0)
args = parser.parse_args()
secret_path = Path(args.secret_file)
try:
    info = secret_path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
        sys.exit('SECRET_FILE_PERMISSIONS_INVALID')
    value = None
    with secret_path.open() as handle:
        for line in handle:
            if line.startswith('LEXWARE_TEST_API_KEY_READ_ONLY='):
                if value is not None:
                    sys.exit('READ_ONLY_SECRET_DUPLICATE')
                value = line.split('=', 1)[1].strip().strip('\"\x27')
    if not value or value == 'DEIN_READ_ONLY_KEY':
        sys.exit('READ_ONLY_SECRET_MISSING')
except OSError:
    sys.exit('READ_ONLY_SECRET_FILE_UNAVAILABLE')
container = 'dolibarr-dev-dolibarr-1'
labels = subprocess.check_output(['sudo', '-n', 'docker', 'inspect', '--format', '{{ index .Config.Labels "com.docker.compose.project" }}|{{ index .Config.Labels "com.docker.compose.service" }}', container], text=True).strip()
if labels != 'dolibarr-dev|dolibarr':
    sys.exit('REFUSING_NON_DEV_TARGET')
root = Path(__file__).resolve().parents[1]
subprocess.run(['sudo', '-n', 'docker', 'cp', str(root / 'scripts/lexware-dev.php'), container + ':/tmp/trafopilot-lexware-dev.php'], check=True)
payload = json.dumps({'read_only_secret': value, 'mode': args.mode, 'run': args.run})
result = subprocess.run(['sudo', '-n', 'docker', 'exec', '-i', container, 'php', '/tmp/trafopilot-lexware-dev.php'], input=payload, text=True)
sys.exit(result.returncode)
