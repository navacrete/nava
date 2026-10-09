#!/usr/bin/env bash
# AEGIS - εγκατάσταση μέσω ADB (Mac / Linux)
set -e
cd "$(dirname "$0")"
mkdir -p aegis-tools && cd aegis-tools

echo "============================================"
echo "  AEGIS - Εγκατάσταση μέσω ADB"
echo "============================================"
echo
echo "Πριν συνεχίσεις, στο κινητό:"
echo " 1. Ρυθμίσεις > Σχετικά με το τηλέφωνο > 7 πατήματα στον 'Αριθμό έκδοσης'"
echo " 2. Επιλογές προγραμματιστή > ενεργοποίηση 'USB debugging'"
echo " 3. Σύνδεσε το κινητό με καλώδιο USB"
echo
read -r -p "Πάτα Enter για συνέχεια..."

case "$(uname -s)" in
  Darwin) ZIP=platform-tools-latest-darwin.zip ;;
  *)      ZIP=platform-tools-latest-linux.zip ;;
esac

echo "[1/4] Λήψη Android Platform Tools..."
if [ ! -x platform-tools/adb ]; then
  curl -L -o platform-tools.zip "https://dl.google.com/android/repository/$ZIP"
  unzip -q -o platform-tools.zip
fi

echo "[2/4] Λήψη AEGIS.apk..."
curl -L -o AEGIS.apk https://github.com/navacrete/nava/releases/download/latest/AEGIS.apk

echo "[3/4] Αναμονή για το κινητό... Αποδέξου στην οθόνη του το 'Allow USB debugging'."
./platform-tools/adb start-server >/dev/null 2>&1 || true
./platform-tools/adb wait-for-device

echo "[4/4] Εγκατάσταση..."
./platform-tools/adb uninstall gr.aegis.protect >/dev/null 2>&1 || true
./platform-tools/adb install AEGIS.apk

echo
echo "============================================"
echo "  Έτοιμο! Άνοιξε το AEGIS στο κινητό και"
echo "  πάτα 'Παραχώρηση' στη γραμμή SMS."
echo "============================================"
