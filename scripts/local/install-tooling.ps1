# FoodOnTheGo — local development tooling installer (Windows, local/test only).
# Installs: PHP 8.3, Composer, Temurin JDK 17, Android Studio (SDK), Memurai (Redis-compatible).
# Flutter is installed separately by install-flutter.ps1 (not on winget).
# Never run against production. Re-runnable: skips anything already present.

$ErrorActionPreference = 'Continue'
$log = Join-Path $PSScriptRoot 'install-tooling.log'
function Log($m) { $line = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m; Write-Host $line; Add-Content -Path $log -Value $line }

function Ensure-Winget($id, $name, $extra = @()) {
  $installed = winget list --id $id --exact --accept-source-agreements 2>$null | Select-String -Quiet $id
  if ($installed) { Log "$name already installed — skipping"; return }
  Log "Installing $name ($id)…"
  $args = @('install', '--id', $id, '--exact', '--silent', '--accept-package-agreements', '--accept-source-agreements') + $extra
  & winget @args 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
  Log "$name install exit code: $LASTEXITCODE"
}

Log '=== install-tooling start ==='

Ensure-Winget 'PHP.PHP.8.3' 'PHP 8.3'
Ensure-Winget 'EclipseAdoptium.Temurin.17.JDK' 'Temurin JDK 17'
Ensure-Winget 'Memurai.MemuraiDeveloper' 'Memurai Developer (Redis-compatible)'
Ensure-Winget 'Google.AndroidStudio' 'Android Studio'

# --- Composer (official installer; not on winget) ---
$php = Get-ChildItem 'C:\Program Files\PHP', "$env:LOCALAPPDATA\Programs\PHP", 'C:\tools\php*' -Recurse -Filter php.exe -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $php) { $php = Get-Command php -ErrorAction SilentlyContinue | ForEach-Object { Get-Item $_.Source } }
if ($php) {
  $composerDir = Join-Path $env:LOCALAPPDATA 'Programs\Composer'
  New-Item -ItemType Directory -Force $composerDir | Out-Null
  if (Test-Path (Join-Path $composerDir 'composer.phar')) { Log 'Composer already installed — skipping' }
  else {
    Log "Installing Composer using $($php.FullName)…"
    $setup = Join-Path $env:TEMP 'composer-setup.php'
    Invoke-WebRequest -Uri 'https://getcomposer.org/installer' -OutFile $setup
    & $php.FullName $setup --install-dir="$composerDir" --filename=composer.phar 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
    Set-Content -Path (Join-Path $composerDir 'composer.cmd') -Value "@echo off`r`n`"$($php.FullName)`" `"%~dp0composer.phar`" %*"
    Remove-Item $setup -ErrorAction SilentlyContinue
    Log "Composer installed to $composerDir"
  }
  $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
  if ($userPath -notlike "*$composerDir*") { [Environment]::SetEnvironmentVariable('Path', "$userPath;$composerDir", 'User'); Log 'Added Composer to user PATH' }
} else { Log 'PHP not found after install — Composer skipped (re-run after PHP is on PATH)' }

Log '=== install-tooling end ==='
