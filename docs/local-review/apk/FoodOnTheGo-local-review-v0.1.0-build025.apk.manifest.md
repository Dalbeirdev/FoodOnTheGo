# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build025.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 25) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://192.168.1.24:8001/api/v1 |
| Git commit | ef6bf6a |
| Build timestamp | 2026-09-30 19:29 |
| Size | 195.3 MB |
| SHA-256 | 2FA1DFDD4478D1A72BD21251FF147D0356AB8429089FF5A8C7ADD5594742ADE6 |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.24:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build025.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://http://192.168.1.24:8001/api/v1/health in the phone browser first.
