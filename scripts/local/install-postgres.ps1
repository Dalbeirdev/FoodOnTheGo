# FoodOnTheGo — portable PostgreSQL 18 + PostGIS 3.6 for LOCAL development (no admin, no service).
#   Server  -> C:\dev\pgsql      Data -> C:\dev\pgdata      Port 5432
#   Creates role fotg_local + database foodonthego_local with the postgis extension.
#   Credentials are written to C:\dev\pg-local-credentials.txt (local machine only).
$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'install-postgres.log'
function Log($m) { $line = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m; Write-Host $line; Add-Content -Path $log -Value $line }

$root = 'C:\dev'; $pg = Join-Path $root 'pgsql'; $data = Join-Path $root 'pgdata'; $credFile = Join-Path $root 'pg-local-credentials.txt'
New-Item -ItemType Directory -Force $root | Out-Null
Log '=== install-postgres start ==='

# ---- PostgreSQL 18 binaries (newest 18.x that EDB publishes) ----
if (-not (Test-Path (Join-Path $pg 'bin\pg_ctl.exe'))) {
  $ver = $null
  foreach ($v in 18..0 | ForEach-Object { "18.$_-1" }) {
    try { $r = Invoke-WebRequest -Method Head -Uri "https://get.enterprisedb.com/postgresql/postgresql-$v-windows-x64-binaries.zip" -UseBasicParsing -ErrorAction Stop; if ($r.StatusCode -eq 200) { $ver = $v; break } } catch {}
  }
  if (-not $ver) { throw 'No PostgreSQL 18 binaries found' }
  Log "Downloading PostgreSQL $ver binaries…"
  $zip = Join-Path $env:TEMP 'pg18.zip'
  Invoke-WebRequest -Uri "https://get.enterprisedb.com/postgresql/postgresql-$ver-windows-x64-binaries.zip" -OutFile $zip
  $tmp = Join-Path $env:TEMP 'pg18-extract'; if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
  Expand-Archive -Path $zip -DestinationPath $tmp -Force
  if (Test-Path $pg) { Remove-Item $pg -Recurse -Force }
  Move-Item (Join-Path $tmp 'pgsql') $pg
  Remove-Item $zip, $tmp -Recurse -Force -ErrorAction SilentlyContinue
  Log "PostgreSQL installed at $pg"
} else { Log 'PostgreSQL binaries already present' }

# ---- PostGIS 3.6 bundle (copied over the server tree) ----
if (-not (Test-Path (Join-Path $pg 'share\extension\postgis.control'))) {
  $listing = Invoke-WebRequest -Uri 'https://download.osgeo.org/postgis/windows/pg18/' -UseBasicParsing
  $bundle = ($listing.Links | Where-Object { $_.href -match '^postgis-bundle-pg18-3\.6\.\d+x64\.zip$' } | Select-Object -Last 1).href
  if (-not $bundle) { throw 'No PostGIS 3.6 bundle for PG18 found' }
  Log "Downloading $bundle…"
  $zip = Join-Path $env:TEMP $bundle
  Invoke-WebRequest -Uri "https://download.osgeo.org/postgis/windows/pg18/$bundle" -OutFile $zip
  $tmp = Join-Path $env:TEMP 'postgis-extract'; if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
  Expand-Archive -Path $zip -DestinationPath $tmp -Force
  $inner = Get-ChildItem $tmp -Directory | Select-Object -First 1
  foreach ($d in 'bin', 'lib', 'share') { if (Test-Path (Join-Path $inner.FullName $d)) { Copy-Item (Join-Path $inner.FullName "$d\*") (Join-Path $pg $d) -Recurse -Force } }
  Remove-Item $zip, $tmp -Recurse -Force -ErrorAction SilentlyContinue
  Log 'PostGIS bundle merged into the server tree'
} else { Log 'PostGIS already present' }

# ---- Cluster ----
$bin = Join-Path $pg 'bin'
if (-not (Test-Path (Join-Path $data 'PG_VERSION'))) {
  $superPw = -join ((48..57 + 65..90 + 97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
  $pwFile = Join-Path $env:TEMP 'pgpw.txt'; Set-Content -Path $pwFile -Value $superPw -NoNewline
  Log 'Initialising cluster…'
  & (Join-Path $bin 'initdb.exe') -D $data -U postgres --auth=scram-sha-256 --pwfile=$pwFile -E UTF8 --locale=C 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
  Remove-Item $pwFile -Force
  Add-Content -Path (Join-Path $data 'postgresql.conf') -Value "`nlisten_addresses = '127.0.0.1'`nport = 5432`n"
  Set-Content -Path $credFile -Value "superuser=postgres`npassword=$superPw`n"
  Log "Cluster created at $data (superuser password saved to $credFile)"
}

# ---- Start ----
$running = & (Join-Path $bin 'pg_ctl.exe') status -D $data 2>&1 | Select-String -Quiet 'server is running'
if (-not $running) {
  & (Join-Path $bin 'pg_ctl.exe') start -D $data -l (Join-Path $root 'pgsql.log') -w 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
  Log 'Server started'
} else { Log 'Server already running' }

# ---- App role + database + PostGIS ----
$creds = Get-Content $credFile | ConvertFrom-StringData
$env:PGPASSWORD = $creds.password
$psql = Join-Path $bin 'psql.exe'
$appPw = ($creds.app_password)
if (-not $appPw) {
  $appPw = -join ((48..57 + 65..90 + 97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
  Add-Content -Path $credFile -Value "app_user=fotg_local`napp_password=$appPw`napp_database=foodonthego_local"
}
& $psql -h 127.0.0.1 -U postgres -d postgres -v ON_ERROR_STOP=1 -c "DO `$`$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'fotg_local') THEN CREATE ROLE fotg_local LOGIN PASSWORD '$appPw'; END IF; END `$`$;" 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
$dbExists = & $psql -h 127.0.0.1 -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='foodonthego_local'"
if ($dbExists -ne '1') { & $psql -h 127.0.0.1 -U postgres -d postgres -c "CREATE DATABASE foodonthego_local OWNER fotg_local ENCODING 'UTF8'" 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ } }
& $psql -h 127.0.0.1 -U postgres -d foodonthego_local -c "CREATE EXTENSION IF NOT EXISTS postgis;" 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
& $psql -h 127.0.0.1 -U postgres -d foodonthego_local -tAc "SELECT version(); SELECT postgis_full_version();" 2>&1 | ForEach-Object { Log $_ }
Remove-Item Env:PGPASSWORD

$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
if ($userPath -notlike "*$bin*") { [Environment]::SetEnvironmentVariable('Path', "$userPath;$bin", 'User'); Log 'Added pgsql\bin to user PATH' }
Log '=== install-postgres end ==='
