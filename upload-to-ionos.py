#!/usr/bin/env python3
"""
Arc Studio → IONOS Upload via SFTP (Port 22)
Ausführen:  python3 upload-to-ionos.py
Voraussetzung: pip install paramiko
"""

import sys, os
from pathlib import Path

try:
    import paramiko
except ImportError:
    print("❌ paramiko fehlt — bitte installieren: pip install paramiko")
    sys.exit(1)

# ── ZUGANGSDATEN ──────────────────────────────────────────────
HOST = "access-5020436626.webspace-host.com"
PORT = 22
USER = "su167909"
PASS = "gunnar-huvqa3-byHguj"
REMOTE_ROOT = "/public"

# ── DATEIEN ZUM HOCHLADEN ─────────────────────────────────────
SCRIPT_DIR = Path(__file__).parent

FILES = [
    "index.html",
    "voltiq.html",
    "VOLTECH-ampere.html",
    "VOLTECH-torque.html",
    "VOLTECH-ki.html",
    "VOLTECH-lern-basis.html",
    "VOLTECH-lern-extra.html",
    "VOLTECH-mechatronik-pro.html",
    "VOLTECH-sps.html",
    "VOLTECH-berichtsheft.html",
    "VOLTECH-tools2.html",
    "impressum.html",
    "datenschutz.html",
    ".htaccess",
    "404.html",
    "analytics.php",
    "stats.php",
    "feedback.php",
    "robots.txt",
    "sitemap.xml",
    "pwa/manifest.json",
    "pwa/sw.js",
]

# ── FARBEN ────────────────────────────────────────────────────
GRN = "\033[92m"; RED = "\033[91m"; YLW = "\033[93m"; RST = "\033[0m"; BLD = "\033[1m"

def upload():
    print(f"\n{BLD}══════════════════════════════════════════{RST}")
    print(f"{BLD}  Arc Studio → IONOS Upload (SFTP){RST}")
    print(f"{BLD}══════════════════════════════════════════{RST}\n")
    print(f"{YLW}📡 Verbinde zu {HOST}:{PORT}...{RST}")

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    try:
        client.connect(HOST, port=PORT, username=USER, password=PASS,
                       timeout=15, allow_agent=False, look_for_keys=False)
        sftp = client.open_sftp()
        print(f"{GRN}✅ Verbunden (SFTP){RST}\n")

        # /public/pwa Verzeichnis sicherstellen
        try:
            sftp.stat(f"{REMOTE_ROOT}/pwa")
        except FileNotFoundError:
            sftp.mkdir(f"{REMOTE_ROOT}/pwa")

        ok = 0
        fail = 0

        for rel_path in FILES:
            local_file = SCRIPT_DIR / rel_path
            remote_path = f"{REMOTE_ROOT}/{rel_path}"

            if not local_file.exists():
                print(f"  {YLW}⚠ Übersprungen (nicht gefunden): {rel_path}{RST}")
                continue

            try:
                sftp.put(str(local_file), remote_path)
                size_kb = local_file.stat().st_size / 1024
                print(f"  {GRN}✅ {rel_path:<45}{RST} {size_kb:>6.1f} KB")
                ok += 1
            except Exception as e:
                print(f"  {RED}❌ {rel_path}: {e}{RST}")
                fail += 1

        sftp.close()
        client.close()

        print(f"\n{BLD}══════════════════════════════════════════{RST}")
        if fail == 0:
            print(f"{GRN}{BLD}  ✅ Upload abgeschlossen! {ok} Dateien hochgeladen.{RST}")
            print(f"{BLD}══════════════════════════════════════════{RST}")
            print(f"\n  🌐 Webseite:  https://arc-studio.org\n")
        else:
            print(f"{YLW}{BLD}  ⚠ {ok} OK · {fail} Fehler — prüfe Ausgabe oben{RST}")
            print(f"{BLD}══════════════════════════════════════════{RST}\n")

    except Exception as e:
        print(f"\n{RED}❌ Fehler: {e}{RST}")
        try:
            client.close()
        except:
            pass
        sys.exit(1)

if __name__ == "__main__":
    upload()
