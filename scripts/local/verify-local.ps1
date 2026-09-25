# FoodOnTheGo — verify the LOCAL environment and FAIL if anything points at production.
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$fail = 0
function Pass($m) { Write-Host "[PASS] $m" -ForegroundColor Green }
function Fail($m) { Write-Host "[FAIL] $m" -ForegroundColor Red; $script:fail++ }

# Ports
foreach ($p in @(@{n = 'PostgreSQL'; port = 5432 }, @{n = 'Redis'; port = 6379 }, @{n = 'Laravel API'; port = 8001 }, @{n = 'Customer Web'; port = 5173 })) {
  if (Get-NetTCPConnection -LocalPort $p.port -State Listen -ErrorAction SilentlyContinue) { Pass "$($p.n) listening on :$($p.port)" } else { Fail "$($p.n) not listening on :$($p.port)" }
}
# Health
try { $h = Invoke-RestMethod 'http://127.0.0.1:8001/api/health' -TimeoutSec 5
  if ($h.status -eq 'ok' -and $h.environment -eq 'local') { Pass "API health ok (environment=$($h.environment) db=$($h.database) redis=$($h.redis))" } else { Fail "API health: status=$($h.status) environment=$($h.environment) db=$($h.database) redis=$($h.redis)" }
  if (($h | ConvertTo-Json) -match 'password|secret|CHANGE_ME') { Fail 'health payload leaks secrets' } else { Pass 'health payload contains no secrets' }
} catch { Fail "API health unreachable: $($_.Exception.Message)" }
# Web
try { $w = Invoke-WebRequest 'http://localhost:5173/' -TimeoutSec 5 -UseBasicParsing; if ($w.StatusCode -eq 200) { Pass 'Customer Web responds 200' } } catch { Fail 'Customer Web not responding' }

# Production-reference scan of resolved runtime config
$envFile = Join-Path $root 'backend\.env'
$bad = Select-String -Path $envFile -Pattern 'amazonaws|rds\.|\.com:5432|foodonthego\.com|APP_ENV=production|rzp_live_'
if ($bad) { Fail "backend .env references production-like values: $($bad.Line -join '; ')" } else { Pass 'backend .env has no production references' }
$dbName = (Select-String -Path $envFile -Pattern '^DB_DATABASE=(.*)$').Matches[0].Groups[1].Value
if ($dbName -eq 'foodonthego_local') { Pass "DB_DATABASE=$dbName" } else { Fail "DB_DATABASE=$dbName is not the local database" }
$webEnv = Join-Path $root 'customer-web\.env.local'
$api = (Select-String -Path $webEnv -Pattern '^VITE_API_BASE_URL=(.*)$').Matches[0].Groups[1].Value
if ($api -match '^http://(127\.0\.0\.1|localhost|192\.168\.|10\.)') { Pass "Web API base is local: $api" } else { Fail "Web API base is not local: $api" }
# Android config
$cfg = Get-Content (Join-Path $root 'mobile\lib\core\app_config.dart') -Raw
if ($cfg -match "defaultValue: 'local'" -and $cfg -match '10\.0\.2\.2') { Pass 'Android AppConfig defaults to local (emulator 10.0.2.2)' } else { Fail 'Android AppConfig default is not local' }
$mainManifest = Get-Content (Join-Path $root 'mobile\android\app\src\main\AndroidManifest.xml') -Raw
if ($mainManifest -match 'usesCleartextTraffic="false"') { Pass 'Main manifest: cleartext disabled (only local flavor overrides)' } else { Fail 'Main manifest allows cleartext' }

Write-Host ''
if ($fail -eq 0) { Write-Host 'LOCAL VERIFY = PASS' -ForegroundColor Green } else { Write-Host "LOCAL VERIFY = FAIL ($fail issue(s))" -ForegroundColor Red; exit 1 }
