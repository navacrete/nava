@echo off
chcp 65001 >nul
setlocal
title AEGIS installer
set "DIR=%~dp0aegis-tools"
if not exist "%DIR%" mkdir "%DIR%"
cd /d "%DIR%"

echo ============================================
echo   AEGIS - Εγκατάσταση μέσω ADB
echo ============================================
echo.
echo Πριν συνεχίσεις, στο κινητό:
echo  1. Ρυθμίσεις ^> Σχετικά με το τηλέφωνο ^> 7 πατήματα στον "Αριθμό έκδοσης"
echo  2. Επιλογές προγραμματιστή ^> ενεργοποίηση "USB debugging"
echo  3. Απεγκατάστησε το AEGIS αν είναι ήδη εγκατεστημένο (γίνεται και αυτόματα)
echo  4. Σύνδεσε το κινητό με καλώδιο USB
echo.
pause

echo [1/4] Λήψη Android Platform Tools...
if not exist "platform-tools\adb.exe" (
  curl -L -o platform-tools.zip https://dl.google.com/android/repository/platform-tools-latest-windows.zip || goto :err
  tar -xf platform-tools.zip || goto :err
)

echo [2/4] Λήψη AEGIS.apk...
curl -L -o AEGIS.apk https://github.com/navacrete/nava/releases/download/latest/AEGIS.apk || goto :err

echo [3/4] Αναμονή για το κινητό... Αποδέξου στην οθόνη του το "Allow USB debugging".
platform-tools\adb.exe start-server >nul 2>&1
platform-tools\adb.exe wait-for-device

echo [4/4] Εγκατάσταση...
platform-tools\adb.exe uninstall gr.aegis.protect >nul 2>&1
platform-tools\adb.exe install AEGIS.apk || goto :err

echo.
echo ============================================
echo   Έτοιμο! Άνοιξε το AEGIS στο κινητό και
echo   πάτα "Παραχώρηση" στη γραμμή SMS.
echo ============================================
pause
exit /b 0

:err
echo.
echo Σφάλμα. Έλεγξε ότι το USB debugging είναι ενεργό, ότι αποδέχτηκες
echo το παράθυρο στο κινητό, και ότι υπάρχει σύνδεση στο internet. Μετά ξανατρέξε το.
pause
exit /b 1
