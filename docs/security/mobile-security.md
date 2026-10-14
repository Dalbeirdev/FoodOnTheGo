# Mobile security (Android app)

Module 39 — 2026-10-14 — local only; production = NO

Sources: `mobile/pubspec.yaml`, `mobile/lib/core/app_config.dart`, `mobile/lib/main.dart:67-79, 186-198`, `mobile/lib/auth/{auth_repository,api_auth_repository}.dart`, `mobile/lib/state/auth_state.dart`, `mobile/lib/data/api_client.dart`, `mobile/lib/notifications/{device_registrar,push_runtime,push_notice}.dart`, `mobile/lib/analytics/analytics_emitter.dart`, `mobile/lib/screens/{auth_screens,unconfigured_build_screen}.dart`, `mobile/android/app/build.gradle.kts`, `mobile/android/app/src/main/AndroidManifest.xml`, `mobile/android/app/src/main/res/xml/data_extraction_rules.xml`, `mobile/android/app/src/local/AndroidManifest.xml`, `mobile/android/app/src/main/kotlin/com/foodonthego/app/MainActivity.kt`, `mobile/test/build_environment_test.dart`, `backend/app/Models/CustomerDevice.php`, `backend/app/Services/Notifications/CustomerDeviceService.php`, `backend/database/migrations/2026_10_19_100000_module39_security.php:57-62`, M39 `audit_clients.md`, `client_agent_report.txt`, `refs.md` §1.5, §9.

The app is a Flutter Android client (`com.foodonthego.app`, version 0.1.0, build 42). Every review APK built so far
is the **local flavor, debug build type** (`docs/local-review/apk/*build042*.manifest.md`). No release build has been
made for distribution. The backend is the authority for every decision: the app holds no secret and cannot mark a
payment, an order or a pickup as done.

## 1. Token and local data storage

| Item | Where | Code |
|---|---|---|
| Customer session token (Sanctum bearer) | `flutter_secure_storage` 11.2.0, key `fotg.auth.token.customer`; values encrypted with an Android Keystore-backed key | `api_auth_repository.dart:27`, `auth_repository.dart:79-95` |
| Pre-profile token, OTP challenge id and phone during sign-in | memory only | `api_auth_repository.dart:40` |
| Guest cart token, journey tokens, installation id, checkout request / accepted terms, payment attempt references, discovery location, analytics session / buffer / opt-out | same secure store (`fotg.cart.token`, `fotg.journey.tokens`, `fotg.installation.id`, `fotg.checkout.*`, `fotg.payment.*`, `fotg.discovery.scope`, `fotg.analytics.*`) | all through `SecureKeyValueStore` |
| Plain files, `shared_preferences`, SQLite | none — no such package in `pubspec.yaml` | |

The token is sent only as an `Authorization: Bearer` header (`api_client.dart:103`); requests time out after 20 s
(`:123`).

**Sign-out** (`ApiAuthRepository.logout`, `api_auth_repository.dart:190-202`) calls `POST /auth/logout` with the
installation id (the backend ends the push binding in the same request) and deletes the token. Since the Module 39
follow-up (M-6, done) sign-out, `AuthState.expire()` (every client's 401) and a restore that finds the session expired
also clear the customer's private context (`lib/auth/private_context.dart`, `lib/state/private_state.dart`):
`fotg.journey.tokens`, `fotg.checkout.request`, `fotg.checkout.terms`, `fotg.payment.attempt`, `fotg.payment.current`,
`fotg.pickup.selection`, `fotg.discovery.scope`, every `fotg.review.draft.*`, and the matching in-memory state. Kept on
purpose: the guest cart (`fotg.cart.token`, `fotg.cart.v1`), the analytics session / buffer / opt-out, the installation
id and the development mode switches (`test/private_context_test.dart`, 11 tests).

## 2. Network

| Control | State | Evidence |
|---|---|---|
| Cleartext HTTP | off in the main manifest (`usesCleartextTraffic="false"`); only the `local` flavor re-enables it for the developer PC | `AndroidManifest.xml:24`, `src/local/AndroidManifest.xml` |
| HTTPS in production | a `production`-flavor build refuses to run when `API_BASE_URL` is empty or not `https://`, or when any of the 10 data modes is not `api`; it shows `UnconfiguredBuildScreen` instead | `app_config.dart:40-48, 131-142`, `main.dart:70-74` |
| Flavor decides the environment | `--flavor production` is always `production` whatever `APP_ENV` says; `staging` is never `local`; the plain-HTTP emulator default URL is used only by a local build | `BuildEnvironment`, `app_config.dart:18-49` |
| Network security config file | none (`networkSecurityConfig` absent) | manifest |
| Certificate pinning | **does not exist** (no pinning code, no `SecurityContext` / `badCertificateCallback`, no network security config) | grep of `mobile/lib` and `android/app/src` |
| WebSocket (Reverb) scheme | `REVERB_SCHEME` defaults to `http` (local); a production build that does not set `https` / `wss` is refused ("not configured"), as is a `PUSH_MODE` other than `fcm` and a non-empty `DEV_OTP` (M-5, done). BUG-321 fixed on the way: `wss` used to give an unencrypted `ws://` URL (`pusher_client.dart`, test added) | `app_config.dart`, `pusher_client.dart`, `build_environment_test.dart` |

Pinning is optional under MASVS-NETWORK (refs.md §11 item 10) and the Module 39 specification asks for a documented
decision rather than blind pinning. **Decision: not implemented; to be decided with the production hosting and
certificate rotation plan (Modules 40–42).** Whether Dart's own sockets honour the manifest cleartext flag for a
`ws://` URL was not verified; a production build must — and, since M-5, is forced to — set `REVERB_SCHEME=https` or `wss`.

## 3. Manifest, backup and platform surface

| Item | State | Evidence |
|---|---|---|
| Exported components | only `MainActivity`, with the LAUNCHER intent filter; no deep-link / app-link filters; no exported services, receivers or providers of the app's own | `AndroidManifest.xml:25-42` |
| `android:debuggable` | not set in the manifest (Gradle sets it for debug builds only) | manifest |
| Backup (Module 39) | `allowBackup="false"`, `fullBackupContent="false"` (Android ≤ 11); `dataExtractionRules` excludes every domain from cloud backup and device transfer (Android 12+) | `AndroidManifest.xml:21-23`, `res/xml/data_extraction_rules.xml` |
| Permissions | INTERNET, ACCESS_NETWORK_STATE, coarse / fine location (asked on tap only; no background location), POST_NOTIFICATIONS (asked in context) | `AndroidManifest.xml:2-8` |
| WebView | none (no package, no use) | M39 `audit_clients.md` |
| Notification taps | targets from a fixed set; references must match the order-number or case-number pattern; routes built in the app, never from a URL in the payload; the target screen still needs the session | `push_notice.dart`, `main.dart` |
| Payment return | the app forwards the provider's payment id and signature as a report; the backend verifies; the fake-provider sheet needs a local build **and** the server's fake provider | `audit_clients.md` (Android, Payment) |
| Clipboard | read only, when the user taps "Paste" on the OTP screen; nothing sensitive is copied automatically | `auth_screens.dart:306` |
| `FLAG_SECURE` (screenshots / recents of the pickup code, QR, payment screens) | **not added — product decision** (customers may screenshot their code; the code is single-use and bound to the order and location on the backend) | M39 decision; `audit_clients.md` C-07 |

Whether the backup exclusions survive the Gradle manifest merge and a real restore was **not verified on a device**
(no Gradle release build by the client agent).

## 4. Development switches and logging

| Item | State | Evidence |
|---|---|---|
| Development OTP shown on the sign-in screen | only when the build is `local` (`AppConfig.localDevOtp`); a staging / production build never shows it, even if `DEV_OTP` was defined | `app_config.dart:121, 144-146`, `auth_screens.dart:48-50`, `api_auth_repository.dart:22-23`, `api_account.dart:99-100` |
| `DEV_OTP` value in the binary | it is a compile-time constant: if passed to a release build, the string is still compiled in although never shown. Release builds must not pass it. | `app_config.dart:121` |
| Mock data modes | every mode defaults to `mock`; a production build with any mode not `api` does not start (section 2) | `app_config.dart:61-98` |
| Debug logging | the two `debugPrint` calls are guarded by `kDebugMode` (analytics emitter, push runtime); no `print`, no HTTP logging interceptor | `analytics_emitter.dart:376`, `push_runtime.dart:56` |
| Build info screen | a secret-free summary (environment, URL, modes, version, label, commit) | `app_config.dart:149-167` |
| Tests | `build_environment_test.dart` (8 tests: flavor wins, URL fallback, production problems) | client agent report |

## 5. Push token handling

- Firebase client options come from `FIREBASE_*` defines (public client configuration; no `google-services.json`, no
  service account in the app). Real FCM is PENDING FIREBASE CONFIGURATION; local builds use `PUSH_MODE=fake` or `off`.
- The installation id is 32 random hex characters from `Random.secure()`, kept in the secure store — never a
  hardware identifier (`device_registrar.dart:32-54`).
- On sign-in the app registers `PUT /customer/devices/current`; a token refresh repeats it. The FCM token is never
  logged or shown.
- Backend: `customer_devices.push_token` is cast `encrypted` and `$hidden`; lookups use `push_token_hash`
  (`CustomerDevice.php:16-33, 75-78`). Since Module 39 a binding made from a session is bound to that session's
  access token (`session_bound`, `access_token_id` with `nullOnDelete`): sign-out, revocation or expiry of that token
  stops push to the device (`module39_security.php:57-62`, `CustomerDeviceService.php:70-72`). Revoked tokens are
  nulled after 30 days (`notifications:prune`).
- Push bodies carry the notification type, ids and the order number — no pickup code, phone, address or payment
  detail (M39 `audit_privacy.md` §4). FCM is not end-to-end encrypted (refs.md §6.3).

## 6. Release build: signing, shrinking, obfuscation, tamper resistance

| Item | State | Evidence |
|---|---|---|
| Release signing | **uses the debug key** (`signingConfig = signingConfigs.getByName("debug")` with a TODO) — **production blocker** | `build.gradle.kts:50-55` |
| Code shrinking (R8) | not configured explicitly (`isMinifyEnabled` / `isShrinkResources` absent); the Flutter Gradle plugin's defaults were not verified | `build.gradle.kts` |
| Dart obfuscation (`--obfuscate --split-debug-info`) | not used by any build command found | APK manifests; no script contains it |
| Root / jailbreak detection | **does not exist** (no package, no code) | `pubspec.yaml`, grep |
| Tamper / integrity checks (Play Integrity, signature checks) | **do not exist** | grep |
| Secrets in the APK | none by name: `REVERB_APP_KEY` and `FIREBASE_API_KEY` are public client values; the Razorpay key id comes from the backend per attempt | `audit_clients.md` |

Root detection and obfuscation are resilience controls (MASVS-RESILIENCE). The specification says root detection
must not be a primary control; server authorization stays authoritative. They are recorded as open decisions, not
as gaps in authorization.

## 7. Mapping to OWASP MASVS 2.1.0 categories

This is a mapping of what exists to the MASVS control groups listed in M39 `refs.md` §1.5. It is **not** a MASVS
assessment and claims **no conformance** (no MAS profile was evaluated; MASTG tests were not run on an APK).

| MASVS category | What the app does today | Open |
|---|---|---|
| MASVS-STORAGE (1, 2) | all persisted values in `flutter_secure_storage` (Keystore-backed); no plain files; backups and device transfer excluded | backup exclusion not verified on a device |
| MASVS-CRYPTO (1, 2) | no custom cryptography; relies on the platform Keystore through the plugin and on TLS | — |
| MASVS-AUTH (1, 2, 3) | phone + OTP against the backend; opaque bearer token with server-side expiry and revocation; sensitive account actions require server-side re-authentication (OTP); no biometric gate | — |
| MASVS-NETWORK (1, 2) | cleartext off outside the local flavor; production requires an https API URL | no pinning (decision pending) |
| MASVS-PLATFORM (1, 2, 3) | one exported launcher activity; no deep links, no WebView; push taps allow-listed; clipboard read only on user action | FLAG_SECURE not added (product decision) |
| MASVS-CODE (1–4) | minSdk 24 / targetSdk 36 (Flutter defaults, per `audit_clients.md`); `flutter analyze` clean; dependencies not discontinued (`pub outdated`); push and notification inputs validated | release signing with the debug key (blocker) |
| MASVS-RESILIENCE (1–4) | none | no obfuscation, no root / tamper detection — decision pending |
| MASVS-PRIVACY (1–4) | first-party analytics only, no ad identifier, keyed pseudonyms on the backend, local opt-out switch; installation id is random | no consent screen (Module 39 decision: legal documents and consent are a production blocker) |

## 8. Open items

| # | Item | Status |
|---|---|---|
| M-1 | Release signing key and signing config from a git-ignored keystore / CI secret | **BLOCKER** |
| M-2 | Certificate pinning decision | OPEN (decision with hosting, Modules 40–42) |
| M-3 | R8 / resource shrinking explicit; `--obfuscate --split-debug-info` for release | OPEN |
| M-4 | Root / tamper detection (Play Integrity) — optional, not a primary control | OPEN (decision) |
| M-5 | Production check to also require `REVERB_SCHEME=https`/`wss`, `PUSH_MODE=fcm`, and an empty `DEV_OTP` | DONE (Module 39 follow-up) |
| M-6 | Clear checkout, payment, journey and discovery keys from secure storage on sign-out and on 401, as the web does | DONE (Module 39 follow-up) |
| M-7 | Verify backup exclusion and manifest merge on a device with a release build | OPEN |
| M-8 | Real FCM with a Firebase project; restrict the Android API key in the Google console | PENDING CONFIGURATION |
| M-9 | Razorpay Android checkout with real test credentials | NOT VERIFIED |
| M-10 | `FLAG_SECURE` on pickup code / payment screens | NOT ADDED — product decision |
