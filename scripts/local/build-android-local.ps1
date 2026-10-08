# FoodOnTheGo — build the LOCAL-review APK and write its manifest + SHA-256 into docs/local-review/apk.
# Builds inside WSL Ubuntu because the Windows JVM on this PC cannot run Gradle (BUG-009).
param(
  # Default: this PC's current Wi-Fi / LAN address (it is assigned by DHCP and can change — rebuild if it does).
  [string]$ApiBaseUrl = '',
  [string]$Version = '0.1.0',
  [int]$Build = 1,
  # api = sign in against the local backend (it must be running and reachable from the device); mock = in-app development mock
  [ValidateSet('api', 'mock')][string]$AuthMode = 'api',
  # api = market, currency, units and coverage come from the local backend (Module 22); mock = bundled fixtures
  [ValidateSet('api', 'mock')][string]$MarketMode = 'api',
  # api = restaurants, hours, cuisines, pickup methods and availability come from the local backend (Module 23); mock = bundled fixtures
  [ValidateSet('api', 'mock')][string]$RestaurantMode = 'api',
  # api = places, journey planning, the route and the restaurants along it come from the local backend (Module 26); mock = the in-app development providers
  [ValidateSet('api', 'mock')][string]$JourneyMode = 'api',
  [ValidateSet('api', 'mock')][string]$CartMode = 'api',
  # api = pickup times come from the local backend (Module 28: ASAP, slots, capacity state, arrival-aware recommendation; needs CartMode api); mock = the in-app development slot engine
  [ValidateSet('api', 'mock')][string]$PickupMode = 'api',
  [ValidateSet('api', 'mock')][string]$CheckoutMode = 'api',
  # api = payments are the local backend's (Module 30: payment attempts, the provider's own checkout screen, server-side verification; needs CheckoutMode api); mock = the in-app development payment.
  # Which provider the backend uses (Razorpay in test mode, or the development provider) is the backend's configuration — not the app's.
  [ValidateSet('api', 'mock')][string]$PaymentMode = 'api',
  # api = orders are the local backend's (Module 31: created by the server from the verified payment; needs PaymentMode api); mock = the in-app development orders.
  [ValidateSet('api', 'mock')][string]$OrderMode = 'api',
  # Local test code shown on the OTP screen of LOCAL builds. Default: OTP_DEV_CODE from the git-ignored backend/.env.
  [string]$DevOtp = ''
)
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if (-not $ApiBaseUrl) { $ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.PrefixOrigin -eq 'Dhcp' -and $_.IPAddress -notlike '169.*' } | Select-Object -First 1).IPAddress; if (-not $ip) { Write-Host 'Could not detect the LAN address; pass -ApiBaseUrl' -ForegroundColor Red; exit 1 }; $ApiBaseUrl = "http://${ip}:8001/api/v1" }
if ($AuthMode -eq 'api' -and -not $DevOtp) { $m = Select-String -Path (Join-Path $root 'backend\.env') -Pattern '^OTP_DEV_CODE=(.*)$'; if ($m) { $DevOtp = $m.Matches[0].Groups[1].Value.Trim() } }
$out = Join-Path $root 'docs\local-review\apk'; New-Item -ItemType Directory -Force $out | Out-Null
$name = "FoodOnTheGo-local-review-v$Version-build$($Build.ToString('000')).apk"
$commit = try { (git -C $root rev-parse --short HEAD 2>$null) } catch { $null }; if (-not $commit) { $commit = 'no-git' }
$stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'

Write-Host "Syncing mobile/ into WSL and building $name (API $ApiBaseUrl)…"
$cmd = "export PATH=/root/fotg-tools/flutter/bin:`$PATH ANDROID_HOME=/root/fotg-tools/android-sdk; rsync -a --delete --exclude build --exclude .dart_tool --exclude .gradle /mnt/e/TechPio-Data/FoodOnTheGo/mobile/ /root/fotg/mobile/ && cd /root/fotg/mobile && flutter pub get >/dev/null && flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=$ApiBaseUrl --dart-define=AUTH_MODE=$AuthMode --dart-define=MARKET_MODE=$MarketMode --dart-define=RESTAURANT_MODE=$RestaurantMode --dart-define=JOURNEY_MODE=$JourneyMode --dart-define=CART_MODE=$CartMode --dart-define=PICKUP_MODE=$PickupMode --dart-define=CHECKOUT_MODE=$CheckoutMode --dart-define=PAYMENT_MODE=$PaymentMode --dart-define=ORDER_MODE=$OrderMode --dart-define=DEV_OTP=$DevOtp --dart-define=BUILD_LABEL=local-review-build$($Build.ToString('000')) --dart-define=GIT_COMMIT=$commit 2>&1 | tee /root/fotg/apk-build.log | tail -5 && cp build/app/outputs/flutter-apk/app-local-debug.apk /mnt/e/TechPio-Data/FoodOnTheGo/docs/local-review/apk/$name"
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
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=$ApiBaseUrl --dart-define=AUTH_MODE=$AuthMode --dart-define=MARKET_MODE=$MarketMode --dart-define=RESTAURANT_MODE=$RestaurantMode --dart-define=JOURNEY_MODE=$JourneyMode --dart-define=CART_MODE=$CartMode --dart-define=PICKUP_MODE=$PickupMode --dart-define=CHECKOUT_MODE=$CheckoutMode --dart-define=PAYMENT_MODE=$PaymentMode --dart-define=ORDER_MODE=$OrderMode --dart-define=DEV_OTP=<local test code> |
| Authentication | $AuthMode (api = the PC's local backend must be running and reachable from the device) |
| Market data | $MarketMode (api = market and coverage are read from the local backend at start-up) |
| Restaurant data | $RestaurantMode (api = restaurants, hours, cuisines, pickup methods, availability and menus from the local backend; reviews are still development data) |
| Journey data | $JourneyMode (api = place search, journey planning, the route, actual detours and arrival times from the local backend. The backend's places / routing provider on this PC is its DEVELOPMENT provider — synthetic roads, no live provider is configured; the app labels the figures accordingly) |
| Cart | $CartMode (api = the cart is the local backend's: one per guest (token in the device's secure storage) or signed-in customer, priced and validated by the server) |
| Pickup times | $PickupMode (api = ASAP, pickup slots, capacity state and the arrival-aware recommendation come from the local backend on its clock, in the restaurant's time zone; the choice is stored with the cart and checked again on every read. A chosen time is SELECTED, NOT RESERVED — the slot hold belongs to checkout / order creation) |
| Checkout | $CheckoutMode (api = the checkout is the local backend's: final validation of the cart and the pickup time, one promotion, the configured tax and fee rules, the total, the legal documents to accept, the pickup-time hold and the payment preparation; mock = the in-app development checkout) |
| Payments | $PaymentMode (api = the payment step is the local backend's: a payment attempt per try, the payment provider's own screen, verification on the server, recovery after a restart. The PROVIDER AND ITS MODE ARE THE BACKEND'S CONFIGURATION: Razorpay in TEST mode when test keys are configured there, otherwise the development provider, whose place on the phone is a development sheet — no real money moves in either. The verified payment becomes an ORDER on the server (Module 31); mock = the in-app development payment) |
| Orders | $OrderMode (api = orders are the local backend's: created by the server from the verified payment (exactly once), read by order number, the restaurant's steps (accepted, preparing, ready) followed by polling until realtime tracking arrives (Module 32). NOT built yet: realtime updates, pickup code verification / picked-up, refunds, notifications; mock = the in-app development orders) |
| Location permission | asked only when "Use my current location" is tapped while planning a journey (ACCESS_COARSE_LOCATION / ACCESS_FINE_LOCATION; no background location) |
| Account data | $AuthMode (api = profile, favorites, saved journey places, payment-method references and notification preferences from the local backend — follows the sign-in mode; the notification inbox is still development data) |
| Install (USB) | adb install -r $name |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check $($ApiBaseUrl -replace '/api/v1','')/api/v1/health in the phone browser first.
"@
Set-Content -Path (Join-Path $out "$name.manifest.md") -Value $manifest
Set-Content -Path (Join-Path $out "$name.sha256") -Value "$sha  $name"
Write-Host "APK:    $apk ($size MB)"
Write-Host "SHA256: $sha"
