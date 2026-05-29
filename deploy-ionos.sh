#!/bin/bash
# ══════════════════════════════════════════════════════════════
# Arc Studio — IONOS FTP Deployment Script
# Voraussetzung: lftp installiert (brew install lftp)
# Verwendung: ./deploy-ionos.sh
# ══════════════════════════════════════════════════════════════

set -e  # Abbruch bei Fehler

# ── KONFIGURATION ─────────────────────────────────────────────
# ⚠ Diese Werte anpassen — NIEMALS committen!
FTP_HOST="${IONOS_FTP_HOST:-ftp.arc-studio.org}"
FTP_USER="${IONOS_FTP_USER:-dein-ftp-benutzer}"
FTP_PASS="${IONOS_FTP_PASS:-dein-ftp-passwort}"
FTP_DIR="${IONOS_FTP_DIR:-/}"       # Zielverzeichnis auf Server (meist / oder /htdocs/)

# ── LOKALES VERZEICHNIS ───────────────────────────────────────
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── DATEIEN ZUM HOCHLADEN ─────────────────────────────────────
# Nur diese Dateien werden übertragen
FILES=(
  "index.html"
  "voltiq.html"
  "VOLTECH-ampere.html"
  "VOLTECH-torque.html"
  "VOLTECH-ki.html"
  "VOLTECH-lern-basis.html"
  "VOLTECH-lern-extra.html"
  "VOLTECH-mechatronik-pro.html"
  "VOLTECH-sps.html"
  "VOLTECH-berichtsheft.html"
  "VOLTECH-tools2.html"
  "VOLTECH-eigene-karten.html"
  "VOLTECH-achievements.html"
  "VOLTECH-analytics.html"
  "VOLTECH-ap2-sim.html"
  "VOLTECH-ki-quiz.html"
  "VOLTECH-lernplan.html"
  "VOLTECH-whats-new.html"
  "SHK-lern.html"
  "FISI-core.html"
  "FISI-lern.html"
  "spark-kfz.html"
  "MESH-lern.html"
  "PAINT-lern.html"
  "CHEF-lern.html"
  "KAUF-lern.html"
  "ELEK-lern.html"
  "impressum.html"
  "datenschutz.html"
  ".htaccess"
)

DIRS=(
  "pwa"
)

# ── FARBEN FÜR OUTPUT ─────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo ""
echo "══════════════════════════════════════════════"
echo "  Arc Studio → IONOS Deployment"
echo "══════════════════════════════════════════════"
echo ""

# Prüfen ob lftp installiert
if ! command -v lftp &> /dev/null; then
  echo -e "${RED}❌ lftp nicht gefunden!${NC}"
  echo "   Installieren mit: brew install lftp"
  exit 1
fi

echo -e "${YELLOW}📡 Verbinde zu: $FTP_HOST${NC}"
echo -e "${YELLOW}📁 Zielverzeichnis: $FTP_DIR${NC}"
echo ""

# ── UPLOAD via LFTP ───────────────────────────────────────────
lftp -c "
set ftp:ssl-allow no;
set ftp:passive-mode false;
set ftp:use-feat false;
set net:timeout 60;
set net:max-retries 3;
set net:reconnect-interval-base 5;

open ftp://$FTP_USER:$FTP_PASS@$FTP_HOST;

# Ordner anlegen falls nicht vorhanden
mkdir -f -p $FTP_DIR/pwa;

# HTML & Konfigurationsdateien hochladen
$(for f in "${FILES[@]}"; do
  echo "put -O $FTP_DIR '$LOCAL_DIR/$f';"
done)

# Verzeichnisse hochladen (pwa/)
$(for d in "${DIRS[@]}"; do
  echo "mirror -R --delete '$LOCAL_DIR/$d' $FTP_DIR/$d;"
done)

echo '✅ Upload abgeschlossen';
bye;
"

echo ""
echo -e "${GREEN}════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✅ Deployment erfolgreich!${NC}"
echo -e "${GREEN}════════════════════════════════════════════${NC}"
echo ""
echo "  🌐 Webseite: https://$FTP_HOST"
echo "  📋 Bitte prüfen:"
echo "     1. HTTPS aktiv?"
echo "     2. PWA-Installation möglich?"
echo "     3. Offline-Modus funktioniert?"
echo ""
