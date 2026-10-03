# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build028.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 28) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://10.109.191.229:8001/api/v1 |
| Git commit | 58120bf |
| Build timestamp | 2026-10-03 12:15 |
| Size | 195.3 MB |
| SHA-256 | 3FA7DC71480701DFC60457BF9000502DDB5D70E9C037A9D48EBDEB4D8A2C1064 |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://10.109.191.229:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=MARKET_MODE=api --dart-define=RESTAURANT_MODE=api --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Market data | api (api = market and coverage are read from the local backend at start-up) |
| Restaurant data | api (api = restaurants, hours, cuisines, pickup methods, availability and menus from the local backend; carts, orders and reviews are still development data) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build028.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://10.109.191.229:8001/api/v1/health in the phone browser first.
