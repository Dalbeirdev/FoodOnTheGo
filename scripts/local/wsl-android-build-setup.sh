#!/usr/bin/env bash
# FoodOnTheGo — Linux (WSL Ubuntu) Android build host. Local/test only.
# Installs OpenJDK 17, Flutter (stable), Android cmdline-tools + SDK into ~/fotg-tools,
# because the Windows JVM on this PC cannot open NIO loopback pipes (Gradle cannot run).
set -euo pipefail
ROOT="$HOME/fotg-tools"; LOG="$ROOT/setup.log"; mkdir -p "$ROOT"
log(){ echo "[$(date +%H:%M:%S)] $*" | tee -a "$LOG"; }
log "=== wsl android setup start ==="

if ! command -v java >/dev/null; then
  log "Installing OpenJDK 17 + unzip via apt (sudo)…"
  sudo DEBIAN_FRONTEND=noninteractive apt-get update -qq >>"$LOG" 2>&1
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq openjdk-17-jdk-headless unzip zip curl xz-utils git >>"$LOG" 2>&1
fi
log "java: $(java -version 2>&1 | head -1)"

FLUTTER="$ROOT/flutter"
if [ ! -x "$FLUTTER/bin/flutter" ]; then
  VER=$(curl -s https://storage.googleapis.com/flutter_infra_release/releases/releases_linux.json | python3 -c 'import json,sys; d=json.load(sys.stdin); h=d["current_release"]["stable"]; print(next(r["archive"] for r in d["releases"] if r["hash"]==h))')
  log "Downloading Flutter $VER…"
  curl -sL "https://storage.googleapis.com/flutter_infra_release/releases/$VER" -o "$ROOT/flutter.tar.xz"
  tar -xf "$ROOT/flutter.tar.xz" -C "$ROOT" && rm "$ROOT/flutter.tar.xz"
  git config --global --add safe.directory "$FLUTTER" || true
fi
export PATH="$FLUTTER/bin:$PATH"
log "flutter: $(flutter --version 2>/dev/null | head -1)"

SDK="$ROOT/android-sdk"; TOOLS="$SDK/cmdline-tools/latest"
if [ ! -x "$TOOLS/bin/sdkmanager" ]; then
  URL=$(curl -s https://developer.android.com/studio | grep -oE 'https://dl.google.com/android/repository/commandlinetools-linux-[0-9]+_latest.zip' | head -1)
  log "Downloading $URL…"
  mkdir -p "$SDK/cmdline-tools"; curl -sL "$URL" -o "$ROOT/cmdtools.zip"
  unzip -q "$ROOT/cmdtools.zip" -d "$ROOT/cmdtmp" && mv "$ROOT/cmdtmp/cmdline-tools" "$TOOLS" && rm -rf "$ROOT/cmdtmp" "$ROOT/cmdtools.zip"
fi
export ANDROID_HOME="$SDK" ANDROID_SDK_ROOT="$SDK"
yes | "$TOOLS/bin/sdkmanager" --sdk_root="$SDK" --licenses >>"$LOG" 2>&1 || true
log "Installing platform-tools, platforms;android-36, build-tools;36.0.0…"
"$TOOLS/bin/sdkmanager" --sdk_root="$SDK" "platform-tools" "platforms;android-36" "build-tools;36.0.0" >>"$LOG" 2>&1
flutter config --android-sdk "$SDK" >>"$LOG" 2>&1
yes | flutter doctor --android-licenses >>"$LOG" 2>&1 || true
flutter doctor 2>&1 | tee -a "$LOG"
log "=== wsl android setup end ==="
