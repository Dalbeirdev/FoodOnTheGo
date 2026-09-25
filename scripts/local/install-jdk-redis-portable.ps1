# FoodOnTheGo — portable (no-admin) installs for local dev only.
#   JDK 17  -> C:\dev\jdk-17   (Eclipse Temurin zip, via the Adoptium API)
#   Redis   -> C:\dev\redis    (Redis for Windows 5.x zip build, tporadowski/redis; dev/test only)
$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'install-jdk-redis-portable.log'
function Log($m) { $line = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m; Write-Host $line; Add-Content -Path $log -Value $line }
$root = 'C:\dev'; New-Item -ItemType Directory -Force $root | Out-Null
$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')

# ---------------- JDK 17 ----------------
$jdk = Join-Path $root 'jdk-17'
if (Test-Path (Join-Path $jdk 'bin\java.exe')) { Log "JDK already at $jdk" }
else {
  Log 'Downloading Temurin JDK 17 (zip)…'
  $zip = Join-Path $env:TEMP 'temurin17.zip'
  Invoke-WebRequest -Uri 'https://api.adoptium.net/v3/binary/latest/17/ga/windows/x64/jdk/hotspot/normal/eclipse?project=jdk' -OutFile $zip
  $tmp = Join-Path $env:TEMP 'temurin17'
  if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
  Expand-Archive -Path $zip -DestinationPath $tmp -Force
  $inner = Get-ChildItem $tmp -Directory | Select-Object -First 1
  if (Test-Path $jdk) { Remove-Item $jdk -Recurse -Force }
  Move-Item $inner.FullName $jdk
  Remove-Item $zip, $tmp -Recurse -Force -ErrorAction SilentlyContinue
  Log "JDK installed at $jdk"
}
[Environment]::SetEnvironmentVariable('JAVA_HOME', $jdk, 'User')
if ($userPath -notlike "*$jdk\bin*") { $userPath = "$userPath;$jdk\bin" }

# ---------------- Redis ----------------
$redis = Join-Path $root 'redis'
if (Test-Path (Join-Path $redis 'redis-server.exe')) { Log "Redis already at $redis" }
else {
  Log 'Downloading Redis for Windows (zip)…'
  $rel = Invoke-RestMethod 'https://api.github.com/repos/tporadowski/redis/releases/latest' -Headers @{ 'User-Agent' = 'FoodOnTheGo-local' }
  $asset = $rel.assets | Where-Object { $_.name -like '*.zip' -and $_.name -notlike '*src*' } | Select-Object -First 1
  Log "Release $($rel.tag_name) → $($asset.name)"
  $zip = Join-Path $env:TEMP 'redis-win.zip'
  Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $zip
  New-Item -ItemType Directory -Force $redis | Out-Null
  Expand-Archive -Path $zip -DestinationPath $redis -Force
  Remove-Item $zip -ErrorAction SilentlyContinue
  Log "Redis installed at $redis"
}
if ($userPath -notlike "*$redis*") { $userPath = "$userPath;$redis" }
[Environment]::SetEnvironmentVariable('Path', $userPath, 'User')
Log 'PATH/JAVA_HOME updated (user scope)'

# ---------------- Verify ----------------
& (Join-Path $jdk 'bin\java.exe') -version 2>&1 | ForEach-Object { Log "java: $_" }
& (Join-Path $redis 'redis-server.exe') --version 2>&1 | ForEach-Object { Log "redis: $_" }
Log 'done'
