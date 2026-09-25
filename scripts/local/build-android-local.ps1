# FoodOnTheGo — build the LOCAL-review APK and write its manifest + SHA-256 into docs/local-review/apk.
# Builds inside WSL Ubuntu because the Windows JVM on this PC cannot run Gradle (BUG-009).
param(
  [string]$ApiBaseUrl = 'http://192.168.1.221:8001/api/v1',
  [string]$Version = '0.1.0',
  [int]$Build = 1
)
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$out = Join-Path $root 'docs\local-review\apk'; New-Item -ItemType Directory -Force $out | Out-Null
$name = "FoodOnTheGo-local-review-v$Version-build$($Build.ToString('000')).apk"
$commit = try { (git -C $root rev-parse --short HEAD 2>$null) } catch { $null }; if (-not $commit) { $commit = 'no-git' }
$stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'

Write-Host "Syncing mobile/ into WSL and building $name (API $ApiBaseUrl)…"
$cmd = "export PATH=/root/fotg-tools/flutter/bin:`$PATH ANDROID_HOME=/root/fotg-tools/android-sdk; rsync -a --delete --exclude build --exclude .dart_tool --exclude .gradle /mnt/e/TechPio-Data/FoodOnTheGo/mobile/ /root/fotg/mobile/ && cd /root/fotg/mobile && flutter pub get >/dev/null && flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=$ApiBaseUrl --dart-define=BUILD_LABEL=local-review-build$($Build.ToString('000')) --dart-define=GIT_COMMIT=$commit 2>&1 | tee /root/fotg/apk-build.log | tail -5 && cp build/app/outputs/flutter-apk/app-local-debug.apk /mnt/e/TechPio-Data/FoodOnTheGo/docs/local-review/apk/$name"
wsl.exe -d Ubuntu -u root -e bash -lc $cmd
$apk = Join-Path $out $name
if (-not (Test-Path $apk)) { Write-Host 'APK not produced' -ForegroundColor Red; exit 1 }
$sha = (Get-FileHash $apk -Algorithm SHA256).Hash
$size = [math]::Round((Get-Item $apk).Length / 1MB, 1)
$manifest = @"
# APK BUILD MANIFEST — $name

| Field | Value |
|---|---|
| App version / build | $Version (build $Build) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | $ApiBaseUrl |
| Git commit | $commit |
| Build timestamp | $stamp |
| Size | $size MB |
| SHA-256 | $sha |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=$ApiBaseUrl |
| Install (USB) | adb install -r $name |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://$($ApiBaseUrl -replace '/api/v1','')/api/health in the phone browser first.
"@
Set-Content -Path (Join-Path $out "$name.manifest.md") -Value $manifest
Set-Content -Path (Join-Path $out "$name.sha256") -Value "$sha  $name"
Write-Host "APK:    $apk ($size MB)"
Write-Host "SHA256: $sha"
