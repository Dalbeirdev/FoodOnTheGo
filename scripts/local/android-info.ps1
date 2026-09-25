# FoodOnTheGo — print how an Android device/emulator should reach the local API.
$ip = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias 'Wi-Fi' -ErrorAction SilentlyContinue | Select-Object -First 1).IPAddress
if (-not $ip) { $ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '192.168.*' } | Select-Object -First 1).IPAddress }
Write-Host "PC LAN IPv4 (Wi-Fi): $ip"
Write-Host ''
Write-Host 'Android emulator  -> API_BASE_URL=http://10.0.2.2:8001/api/v1'
Write-Host "Phone on same Wi-Fi -> API_BASE_URL=http://$ip`:8001/api/v1   (phone browser test: http://$ip`:8001/api/health)"
Write-Host 'Phone over USB     -> adb reverse tcp:8001 tcp:8001 ; API_BASE_URL=http://127.0.0.1:8001/api/v1'
Write-Host ''
$adb = "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe"
if (Test-Path $adb) { Write-Host '--- adb devices ---'; & $adb devices } else { Write-Host 'adb not found' }
Write-Host '--- firewall (port 8001, Private profile) ---'
$rule = Get-NetFirewallRule -DisplayName 'FoodOnTheGo local API 8001' -ErrorAction SilentlyContinue
if ($rule) { Write-Host "rule exists: Enabled=$($rule.Enabled) Profile=$($rule.Profile)" } else { Write-Host 'No dedicated rule. If the phone cannot reach the API, run as admin:'; Write-Host '  New-NetFirewallRule -DisplayName "FoodOnTheGo local API 8001" -Direction Inbound -Protocol TCP -LocalPort 8001 -Profile Private -Action Allow' }
