# FoodOnTheGo — Android app (Flutter)

The customer app for Android (iOS is deferred). It talks **only to the FoodOnTheGo API** (`/api/v1`) and the realtime
server (Reverb); never to the database or to a provider with a server secret.

- Application id: `com.foodonthego.app` (flavors: `local` → `.local`, `staging` → `.staging`, `production`).
- Version: `pubspec.yaml` `version` (Module 41: `0.1.0+45`); the app reads `BUILD_NAME` / `BUILD_NUMBER` defines for the
  value it reports (keep them equal to `--build-name` / `--build-number`).
- minSdk 24, targetSdk / compileSdk 36. Permissions: internet, network state, foreground location (asked only when
  "Use my current location" is tapped), notifications (Android 13+, asked after sign-in). No camera, contacts, storage
  or background location.

## Prerequisites

Flutter (stable, Dart 3), Android SDK (platform 36, build-tools), JDK 17. On the development PC the APK is built in WSL
Ubuntu (`scripts/local/build-android-local.ps1`; the Windows JVM loopback issue is BUG-009 / CF-020).

## Configuration: flavors and `--dart-define`

The flavor decides the environment (a production flavor is production whatever `APP_ENV` says). Everything else comes
from defines — no secret ever goes into a define (they are compiled into the app).

| Define | Local | Production |
|---|---|---|
| `APP_ENV` | `local` | (flavor) |
| `API_BASE_URL` | `http://<PC LAN address>:8001/api/v1` | `https://<api host>/api/v1` |
| `AUTH_MODE`, `MARKET_MODE`, `RESTAURANT_MODE`, `JOURNEY_MODE`, `CART_MODE`, `PICKUP_MODE`, `CHECKOUT_MODE`, `PAYMENT_MODE`, `ORDER_MODE`, `REVIEW_MODE` | `api` (or `mock` for development data) | `api` |
| `REALTIME_MODE` | `api` | `api` |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` / `REVERB_APP_KEY` | PC address / `8085` / `http` / local key | realtime host / `443` / `https` / the public Reverb key |
| `PUSH_MODE` | `fake` (development push provider) | `fcm` |
| `FIREBASE_API_KEY` / `FIREBASE_APP_ID` / `FIREBASE_SENDER_ID` / `FIREBASE_PROJECT_ID` | empty | the production Firebase Android app (client values) |
| `DEV_OTP` | the backend's local test code (shown on the OTP screen in local builds only) | **empty** |
| `BUILD_LABEL`, `GIT_COMMIT`, `BUILD_NAME`, `BUILD_NUMBER` | build metadata | build metadata |

**Production gate** (`lib/core/app_config.dart`, `BuildEnvironment.configurationProblems`): a production build that has
no https API, any domain not on `api`, an unencrypted realtime socket, a local realtime host, push not on FCM, missing
Firebase options or a `DEV_OTP` refuses to start and lists what is wrong (tested in `test/build_environment_test.dart`).

## Run and test

```bash
flutter pub get
flutter analyze
flutter test
```
The web preview used by the browser suites: `flutter run -d web-server --web-port 5174 …` with the defines of
`.claude/launch.json` (`mobile-web`). Some widget tests read the wall clock (checkout after 08:30 IST).

## Build a review APK (local flavor)

```powershell
pwsh -File scripts/local/build-android-local.ps1 -Build 45
```
It builds `flutter build apk --flavor local --debug` with the local defines in WSL, copies
`docs/local-review/apk/FoodOnTheGo-local-review-v0.1.0-build045.apk` and writes its `.manifest.md` and `.sha256`.
The APK file itself is git-ignored.

Install on a phone on the same network as the PC (the backend must listen on the LAN — `scripts/local/start-backend.ps1`):

```bash
adb install -r docs/local-review/apk/FoodOnTheGo-local-review-v0.1.0-build045.apk
```
Device checklist: [docs/production/android-device-test-checklist.md](../docs/production/android-device-test-checklist.md).

## Production build (Module 42)

```bash
flutter build appbundle --flavor production --release --obfuscate --split-debug-info=build/symbols --build-name=<x.y.z> --build-number=<n> --dart-define=BUILD_NAME=<x.y.z> --dart-define=BUILD_NUMBER=<n> --dart-define=API_BASE_URL=https://<api>/api/v1 …
```
- **Signing:** release builds must be signed with an upload key kept outside git (`android/key.properties` + keystore,
  or the CI secret store) with Play App Signing. `build.gradle.kts` reads `storeFile`, `storePassword`, `keyAlias`, `keyPassword`
  from `android/key.properties` (or `FOTG_UPLOAD_STORE_FILE`, `FOTG_UPLOAD_STORE_PASSWORD`, `FOTG_UPLOAD_KEY_ALIAS`,
  `FOTG_UPLOAD_KEY_PASSWORD`); a **production** release without them stops the build (Module 41). Local / staging release
  builds may fall back to the debug key for internal review only. The upload key itself is the owner's (CF-548).
  Never commit a keystore or its passwords.
- R8 / ProGuard keep rules for Razorpay and Firebase must be verified on the release build (CF-416).
- Firebase: register `com.foodonthego.app` with the signing certificate's SHA-256 in the production Firebase project.

## Security notes

Tokens are kept in secure storage; no token, OTP, payment signature, pickup credential or FCM token is logged
(Module 39 / 41 audit); backup and device transfer are excluded; cleartext HTTP is allowed in the `local` flavor only.
More: [docs/security/mobile-security.md](../docs/security/mobile-security.md).
