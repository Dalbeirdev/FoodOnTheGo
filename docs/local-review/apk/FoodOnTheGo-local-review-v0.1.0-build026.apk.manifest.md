# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build026.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 26) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://192.168.1.24:8001/api/v1 |
| Git commit | a3df93d |
| Build timestamp | 2026-10-01 13:31 |
| Size | 195.3 MB |
| SHA-256 | 5D8268CF9C617DB7AB907CD10B9836F7965BB668457830F914558ABFCE28AB10 |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.24:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=MARKET_MODE=api --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Market data | api (api = market and coverage are read from the local backend at start-up) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build026.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://http://192.168.1.24:8001/api/v1/health in the phone browser first.
