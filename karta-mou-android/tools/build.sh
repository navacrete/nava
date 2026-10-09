#!/usr/bin/env bash
# Builds kartamou.apk WITHOUT the Android SDK, using:
#   $TC/android-all.jar  (Robolectric framework jar, for javac)
#   $TC/dx.jar           (repackaged dalvik dx, for classes.dex)
#   $TC/apktool.jar      (Apktool, bundles aapt2 + framework resources)
#   $TC/signer.jar       (uber-apk-signer)
# Usage: TC=/path/to/toolchain tools/build.sh
set -euo pipefail
cd "$(dirname "$0")/.."
TC="${TC:-../toolchain}"
OUT=build
rm -rf "$OUT" && mkdir -p "$OUT/classes" "$OUT/apk"

echo "== javac"
find src -name '*.java' > "$OUT/sources.txt"
javac --release 8 -encoding UTF-8 -Xlint:-options -cp "$TC/android-all.jar" -d "$OUT/classes" @"$OUT/sources.txt"

echo "== dx"
java -cp "$TC/dx.jar" com.android.dx.command.Main --dex --min-sdk-version=24 --output="$OUT/classes.dex" "$OUT/classes"

echo "== apktool project"
cp -r res AndroidManifest.xml apktool.yml "$OUT/apk/"
cp "$OUT/classes.dex" "$OUT/apk/classes.dex"
mkdir -p "$OUT/apk/original/META-INF"
java -jar "$TC/apktool.jar" b "$OUT/apk" -o "$OUT/kartamou-unsigned.apk" --use-aapt2 -q

echo "== sign"
KS="${KS:-tools/kartamou.keystore}"
if [ ! -f "$KS" ]; then
  keytool -genkeypair -v -keystore "$KS" -storepass kartamou -keypass kartamou -alias kartamou -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=Karta Mou, O=Le Grand Travel, C=GR" >/dev/null 2>&1
fi
java -jar "$TC/signer.jar" --apks "$OUT/kartamou-unsigned.apk" --ks "$KS" --ksAlias kartamou --ksPass kartamou --ksKeyPass kartamou --out "$OUT/signed" --skipZipAlign >/dev/null
SIGNED=$(ls "$OUT"/signed/*.apk | head -1)
cp "$SIGNED" "$OUT/kartamou.apk"
ls -la "$OUT/kartamou.apk"
