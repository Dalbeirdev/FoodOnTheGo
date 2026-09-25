# FoodOnTheGo — start the Customer Web dev server on http://localhost:5173 (LOCAL ONLY).
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$web = Join-Path $root 'customer-web'
$api = (Select-String -Path (Join-Path $web '.env.local') -Pattern '^VITE_API_BASE_URL=(.*)$' -ErrorAction SilentlyContinue).Matches[0].Groups[1].Value
if ($api -notmatch '^http://(127\.0\.0\.1|localhost|192\.168\.|10\.)') { Write-Host "STOP: VITE_API_BASE_URL is not a local address: $api" -ForegroundColor Red; exit 1 }
Write-Host "[OK]   Web will call $api" -ForegroundColor Green
if (Get-NetTCPConnection -LocalPort 5173 -State Listen -ErrorAction SilentlyContinue) { Write-Host '[OK]   Vite already listening on :5173' -ForegroundColor Green; exit 0 }
Start-Process -FilePath 'npm.cmd' -ArgumentList 'run dev' -WorkingDirectory $web -WindowStyle Minimized
Start-Sleep 4
Write-Host '[OK]   Customer Web: http://localhost:5173' -ForegroundColor Green
