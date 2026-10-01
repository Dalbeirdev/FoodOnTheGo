# FoodOnTheGo — start/verify local backend services (LOCAL ONLY).
# Starts Redis and PostgreSQL if needed, then the API on 0.0.0.0:8001 (Laravel on 127.0.0.1:8002 behind a small local proxy; 8000 is taken by another process on this PC).
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

# Laravel. PHP's built-in server handles one connection at a time and stalls behind idle or abandoned browser
# connections, so it listens on 127.0.0.1:8002 and scripts/local/api-proxy.mjs (Node) serves 0.0.0.0:8001 in front of it.
# Without Node the application is started directly on :8001 (works, but can stall for seconds under a busy browser).
$node = (Get-Command node -ErrorAction SilentlyContinue).Source
$listening = Get-NetTCPConnection -LocalPort 8001 -State Listen -ErrorAction SilentlyContinue
if ($listening) { Ok 'API already listening on :8001' }
elseif ($node) {
  if (-not (Get-NetTCPConnection -LocalPort 8002 -State Listen -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath $php -ArgumentList 'artisan serve --host=127.0.0.1 --port=8002' -WorkingDirectory (Join-Path $root 'backend') -WindowStyle Minimized
  }
  Start-Process -FilePath $node -ArgumentList 'scripts/local/api-proxy.mjs' -WorkingDirectory $root -WindowStyle Minimized
  Start-Sleep 3; Ok 'Laravel on 127.0.0.1:8002, local API proxy on http://0.0.0.0:8001'
}
else {
  Start-Process -FilePath $php -ArgumentList 'artisan serve --host=0.0.0.0 --port=8001' -WorkingDirectory (Join-Path $root 'backend') -WindowStyle Minimized
  Start-Sleep 3; Ok 'Laravel started on http://0.0.0.0:8001 (Node not found: no proxy)'
}
# The first request after a cold start compiles the framework and can take several seconds.
try { $h = Invoke-RestMethod 'http://127.0.0.1:8001/api/v1/ready' -TimeoutSec 20; Ok "ready: status=$($h.status) database=$($h.checks.database) postgis=$($h.checks.postgis) redis=$($h.checks.redis)" } catch { Warn "readiness check failed: $($_.Exception.Message)" }
Write-Host 'Queue worker (separate terminal, only needed when jobs are dispatched): php artisan queue:work redis' -ForegroundColor DarkGray
