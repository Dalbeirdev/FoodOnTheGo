# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build031.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 31) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://192.168.1.221:8001/api/v1 |
| Git commit | d83616e |
| Build timestamp | 2026-10-06 12:25 |
| Size | 196.8 MB |
| SHA-256 | 380ABD2140E27A1C2256CB66F92C6D6600E06C6C19A8A55850A2BCD68B02D678 |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.221:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=MARKET_MODE=api --dart-define=RESTAURANT_MODE=api --dart-define=JOURNEY_MODE=api --dart-define=CART_MODE=api --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Market data | api (api = market and coverage are read from the local backend at start-up) |
| Restaurant data | api (api = restaurants, hours, cuisines, pickup methods, availability and menus from the local backend; orders and reviews are still development data) |
| Journey data | api (api = place search, journey planning, the route, actual detours and arrival times from the local backend. The backend's places / routing provider on this PC is its DEVELOPMENT provider — synthetic roads, no live provider is configured; the app labels the figures accordingly) |
| Cart | api (api = the cart is the local backend's: one per guest (token in the device's secure storage) or signed-in customer, priced and validated by the server; pickup time, checkout, payment and orders after the cart are still development data) |
| Location permission | asked only when "Use my current location" is tapped while planning a journey (ACCESS_COARSE_LOCATION / ACCESS_FINE_LOCATION; no background location) |
| Account data | api (api = profile, favorites, saved journey places, payment-method references and notification preferences from the local backend — follows the sign-in mode; the notification inbox is still development data) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build031.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://192.168.1.221:8001/api/v1/health in the phone browser first.
