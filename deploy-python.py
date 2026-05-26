#!/usr/bin/env python3
# ══════════════════════════════════════════════════════════════
# Arc Studio — Deploy via SFTP (Port 22)
# Verwendung: python3 deploy-python.py
# ══════════════════════════════════════════════════════════════

import os, sys, subprocess, tempfile
from pathlib import Path

GREEN  = '\033[0;32m'
YELLOW = '\033[1;33m'
RED    = '\033[0;31m'
NC     = '\033[0m'

def ok(msg):   print(f"{GREEN}  ✅ {msg}{NC}")
def info(msg): print(f"{YELLOW}  📡 {msg}{NC}")
def err(msg):  print(f"{RED}  ❌ {msg}{NC}")

# ── .env laden ────────────────────────────────────────────────
script_dir = Path(__file__).parent
env_file = script_dir / '.env'
if env_file.exists():
    for line in env_file.read_text().splitlines():
        line = line.strip()
        if line and not line.startswith('#') and '=' in line:
            key, _, val = line.partition('=')
            os.environ.setdefault(key.strip(), val.strip())

FTP_HOST = os.environ.get('IONOS_FTP_HOST', '')
FTP_USER = os.environ.get('IONOS_FTP_USER', '')
FTP_PASS = os.environ.get('IONOS_FTP_PASS', '')
FTP_DIR  = os.environ.get('IONOS_FTP_DIR', '/').rstrip('/') or '/'

if not FTP_USER or 'BENUTZERNAME' in FTP_USER:
    err('Keine FTP-Zugangsdaten in .env!')
    sys.exit(1)

# ── Dateien ───────────────────────────────────────────────────
FILES = [
    'index.html', 'voltiq.html',
    'VOLTECH-ampere.html', 'VOLTECH-torque.html',
    'VOLTECH-ki.html', 'VOLTECH-lern-basis.html',
    'VOLTECH-lern-extra.html', 'VOLTECH-mechatronik-pro.html',
    'VOLTECH-sps.html', 'VOLTECH-berichtsheft.html',
    'VOLTECH-tools2.html', 'VOLTECH-eigene-karten.html',
    'VOLTECH-achievements.html', 'VOLTECH-analytics.html',
    'VOLTECH-ap2-sim.html', 'VOLTECH-ki-quiz.html',
    'VOLTECH-lernplan.html', 'VOLTECH-whats-new.html',
    'SHK-lern.html', 'FISI-lern.html', 'MESH-lern.html',
    'PAINT-lern.html', 'CHEF-lern.html', 'KAUF-lern.html',
    'ELEK-lern.html', 'impressum.html', 'datenschutz.html',
    '.htaccess',
    # ── Neue Dateien ───────────────────────────────────────────
    'VOLTECH-onboarding.html',
    'VOLTECH-ki-tutor.html',
    'VOLTECH-english.html',
    'FISI-core.html',
]

print()
print('══════════════════════════════════════════════')
print('  Arc Studio → IONOS Deployment (SFTP/22)    ')
print('══════════════════════════════════════════════')
print()
info(f'Host: {FTP_HOST}')
info(f'User: {FTP_USER}')
print()

# ── SFTP Batch-Datei erstellen ────────────────────────────────
batch_lines = [f'cd {FTP_DIR}', '-mkdir pwa']

for filename in FILES:
    local = script_dir / filename
    if local.exists():
        batch_lines.append(f'put "{local}" {filename}')

pwa_dir = script_dir / 'pwa'
if pwa_dir.exists():
    for f in sorted(pwa_dir.rglob('*')):
        if f.is_file():
            rel = f.relative_to(script_dir)
            remote = str(rel).replace('\\', '/')
            batch_lines.append(f'-mkdir {Path(remote).parent}')
            batch_lines.append(f'put "{f}" {remote}')

batch_lines.append('bye')
batch_content = '\n'.join(batch_lines) + '\n'

# Batch-Datei temporär speichern
with tempfile.NamedTemporaryFile(mode='w', suffix='.sftp',
                                  delete=False, prefix='arc_deploy_') as tf:
    tf.write(batch_content)
    batch_file = tf.name

info('Starte SFTP Upload (Passwort-Eingabe folgt)...')
print()

# ── sshpass prüfen (für nicht-interaktiv), sonst manuell ─────
sshpass = subprocess.run(['which', 'sshpass'], capture_output=True).returncode == 0

if sshpass:
    cmd = [
        'sshpass', '-p', FTP_PASS,
        'sftp', '-oBatchMode=no',
        '-oStrictHostKeyChecking=no',
        '-oConnectTimeout=30',
        '-b', batch_file,
        f'{FTP_USER}@{FTP_HOST}'
    ]
else:
    # Interaktiv — Passwort muss manuell eingegeben werden
    info(f'Passwort eingeben wenn gefragt: {FTP_PASS}')
    print()
    cmd = [
        'sftp',
        '-oStrictHostKeyChecking=no',
        '-oConnectTimeout=30',
        '-b', batch_file,
        f'{FTP_USER}@{FTP_HOST}'
    ]

result = subprocess.run(cmd)
os.unlink(batch_file)

print()
if result.returncode == 0:
    print('════════════════════════════════════════════')
    ok('Deployment erfolgreich!')
    print('════════════════════════════════════════════')
    print(f'\n  🌐 https://arc-studio.org\n')
else:
    err(f'SFTP fehlgeschlagen (Exit {result.returncode})')
    print()
    err('Mögliche Ursachen:')
    print('    • SFTP auf Port 22 nicht aktiv bei IONOS')
    print('    • Falsches Passwort')
    print('    • Firewall blockiert Port 22')
