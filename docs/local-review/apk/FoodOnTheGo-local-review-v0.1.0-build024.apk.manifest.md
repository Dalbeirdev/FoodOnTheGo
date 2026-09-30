# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build024.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 24) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://192.168.1.24:8001/api/v1 |
| Git commit | b6d2173 |
| Build timestamp | 2026-09-30 18:38 |
| Size | 195.3 MB |
| SHA-256 | 46859184BF19FAD828F4C7C44DDC27BF9B116F5F9C24427CEA45685430BC2AC9 |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.24:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build024.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://http://192.168.1.24:8001/api/v1/health in the phone browser first.
