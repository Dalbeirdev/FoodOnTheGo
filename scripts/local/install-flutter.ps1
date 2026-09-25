# FoodOnTheGo — Flutter SDK installer (Windows, stable channel). Local/test only.
$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'install-flutter.log'
function Log($m) { $line = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m; Write-Host $line; Add-Content -Path $log -Value $line }

$root = 'C:\dev'
$sdk = Join-Path $root 'flutter'
if (Test-Path (Join-Path $sdk 'bin\flutter.bat')) { Log "Flutter already present at $sdk — skipping"; exit 0 }

Log '=== install-flutter start ==='
New-Item -ItemType Directory -Force $root | Out-Null

# Resolve the current stable release from the official release index.
$index = Invoke-RestMethod 'https://storage.googleapis.com/flutter_infra_release/releases/releases_windows.json'
$stableHash = $index.current_release.stable
$release = $index.releases | Where-Object { $_.hash -eq $stableHash } | Select-Object -First 1
$url = $index.base_url + '/' + $release.archive
Log "Stable Flutter $($release.version) → $url"

$zip = Join-Path $env:TEMP 'flutter_stable.zip'
Log 'Downloading (~1 GB)…'
Invoke-WebRequest -Uri $url -OutFile $zip
Log 'Extracting…'
Expand-Archive -Path $zip -DestinationPath $root -Force
Remove-Item $zip -ErrorAction SilentlyContinue

$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
$bin = Join-Path $sdk 'bin'
if ($userPath -notlike "*$bin*") { [Environment]::SetEnvironmentVariable('Path', "$userPath;$bin", 'User'); Log 'Added flutter\bin to user PATH' }
Log "Flutter $($release.version) installed at $sdk"
Log '=== install-flutter end ==='
