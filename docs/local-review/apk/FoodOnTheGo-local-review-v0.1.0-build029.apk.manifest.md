# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build029.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 29) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://10.109.191.229:8001/api/v1 |
| Git commit | 313ccdb |
| Build timestamp | 2026-10-03 15:29 |
| Size | 195.4 MB |
| SHA-256 | DBD34D16CA58FAE4592425AEEFF67B75BEE7D6A7CF5A3BCE30B5FBD32ECA2A1C |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://10.109.191.229:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=MARKET_MODE=api --dart-define=RESTAURANT_MODE=api --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Market data | api (api = market and coverage are read from the local backend at start-up) |
| Restaurant data | api (api = restaurants, hours, cuisines, pickup methods, availability and menus from the local backend; carts, orders and reviews are still development data) |
| Account data | api (api = profile, favorites, saved journey places, payment-method references and notification preferences from the local backend — follows the sign-in mode; the notification inbox is still development data) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build029.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://10.109.191.229:8001/api/v1/health in the phone browser first.
