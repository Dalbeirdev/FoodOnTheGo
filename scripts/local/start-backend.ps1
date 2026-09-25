# FoodOnTheGo — start/verify local backend services (LOCAL ONLY).
# Starts Redis and PostgreSQL if needed, then Laravel on 0.0.0.0:8001 (8000 is taken by another process on this PC).
$ErrorActionPreference = 'Continue'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$php = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$pgBin = 'C:\dev\pgsql\bin'; $pgData = 'C:\dev\pgdata'; $redis = 'C:\dev\redis'
function Ok($m) { Write-Host "[OK]   $m" -ForegroundColor Green }
function Warn($m) { Write-Host "[WARN] $m" -ForegroundColor Yellow }

# Safety: refuse to run if the backend .env points anywhere but local.
$envFile = Join-Path $root 'backend\.env'
$db = (Select-String -Path $envFile -Pattern '^DB_DATABASE=(.*)$').Matches[0].Groups[1].Value
$host_ = (Select-String -Path $envFile -Pattern '^DB_HOST=(.*)$').Matches[0].Groups[1].Value
$appEnv = (Select-String -Path $envFile -Pattern '^APP_ENV=(.*)$').Matches[0].Groups[1].Value
if ($appEnv -ne 'local' -or $db -ne 'foodonthego_local' -or $host_ -notin @('127.0.0.1', 'localhost')) {
  Write-Host "STOP: backend .env is not the local database (APP_ENV=$appEnv DB_HOST=$host_ DB_DATABASE=$db)" -ForegroundColor Red; exit 1
}
Ok "backend .env targets $host_/$db (APP_ENV=$appEnv)"

# Redis
if ((& "$redis\redis-cli.exe" -h 127.0.0.1 ping 2>$null) -eq 'PONG') { Ok 'Redis already running' }
else { Start-Process -FilePath "$redis\redis-server.exe" -ArgumentList '--port 6379 --bind 127.0.0.1' -WindowStyle Hidden; Start-Sleep 2; if ((& "$redis\redis-cli.exe" ping 2>$null) -eq 'PONG') { Ok 'Redis started' } else { Warn 'Redis did not answer' } }

# PostgreSQL
if (& "$pgBin\pg_ctl.exe" status -D $pgData 2>&1 | Select-String -Quiet 'server is running') { Ok 'PostgreSQL already running' }
else { & "$pgBin\pg_ctl.exe" start -D $pgData -l 'C:\dev\pgsql.log' -w | Out-Null; Ok 'PostgreSQL started' }

# Laravel
$listening = Get-NetTCPConnection -LocalPort 8001 -State Listen -ErrorAction SilentlyContinue
if ($listening) { Ok 'Laravel already listening on :8001' }
else {
  Start-Process -FilePath $php -ArgumentList 'artisan serve --host=0.0.0.0 --port=8001' -WorkingDirectory (Join-Path $root 'backend') -WindowStyle Minimized
  Start-Sleep 3; Ok 'Laravel started on http://0.0.0.0:8001'
}
try { $h = Invoke-RestMethod 'http://127.0.0.1:8001/api/health' -TimeoutSec 5; Ok "health: status=$($h.status) database=$($h.database) redis=$($h.redis)" } catch { Warn "health check failed: $($_.Exception.Message)" }
