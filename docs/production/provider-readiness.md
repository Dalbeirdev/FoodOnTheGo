# Provider readiness

Status: Module 41 — 2026-10-16 — local only; **production = NO**. No credential value appears in this document, in the
tracker or in any evidence file. Live provider credentials are **not** enabled in Module 41.

## 1. Provider gate (Module 41 decision)

| Provider | Gate | Why |
|---|---|---|
| Google Maps / Places / Routes | **BLOCKED** | No Google Cloud project, API keys, API restrictions, quota or billing exist. The Google adapters are implemented and covered by contract tests against recorded answers; the live API was never called (CF-352). Provider terms for storing places and caching routes not confirmed (CF-353). |
| Razorpay | **BLOCKED** | No Razorpay account / TEST keys configured; the client (orders, verification, webhooks, refunds, settlement reports, test / live key-mode check) is implemented and was never exercised (CF-404 … CF-407, CF-480, CF-499). LIVE mode is refused in code (`config/payments.php` `allow_live => false`) and stays off in Module 41 (CF-413). |
| Firebase FCM | **BLOCKED** | No Firebase project, Android app registration, service account or client configuration; the FCM HTTP v1 adapter is implemented, the Android app reads its Firebase options from build defines (CF-450). |
| SMS / OTP | **BLOCKED** | No SMS provider account and no India DLT registration (entity, sender header, OTP template). The production configuration refuses the log driver, so **no customer could sign in** (CF-294). WhatsApp / Truecaller are optional channels (CF-297, CF-298). |
| E-mail | **BLOCKED** | Required at launch: staff invitations and password reset are e-mail-only. No provider / verified sender domain (CF-315, CF-451). |
| Object storage | **BLOCKED** (for more than one app host) | Images, avatars and exports use local disks; the S3 adapter is not installed and the media route reads local files. One app host with backed-up `storage/app`, or shared storage mounted on every web + worker host (CF-560). |

## 2. What each provider needs before Module 42 (no values here)

### Google Maps Platform
- Owner: create the Google Cloud project with billing and budget alerts; enable Places API (New) and Routes API.
- Two keys: a **server key** (backend only, `PLACES_API_KEY` / `ROUTING_API_KEY`) restricted by API and by the production
  egress IP addresses; a **browser key** only if a map is shown in the browser (`MAPS_BROWSER_KEY`, restricted by API and HTTP
  referrer to the production web origins). The Android app does not call Google directly.
- Quotas per API, daily caps and alerts; confirm the storage / caching terms (CF-353) before switching
  `JOURNEY_RECORD_RECENT_LIVE_PLACES` on.
- Verification: the Module 26 live-provider checks (`docs/backend/journey-domain.md`), then `php artisan security:check`
  item "places and routing use the live provider".
- **Never** put a live key into the repository, the tracker, a screenshot or an evidence file.

### Razorpay
- Owner: the Razorpay account (business KYC), TEST keys first: `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`,
  `RAZORPAY_WEBHOOK_SECRET` in the secret store; `PAYMENT_DRIVER=razorpay`, `RAZORPAY_MODE=test`.
- Webhook URL `https://<api host>/api/v1/webhooks/payments/razorpay` registered for the account (events of
  `docs/backend/payment-domain.md`), secret set; reachable from Razorpay only.
- Capture: automatic capture on the order (`RAZORPAY_CAPTURE`), methods enabled on the account (UPI, cards, net banking,
  wallets — what the account offers), refund behaviour (normal speed) confirmed.
- Verification in TEST mode (CF-404 … 407): web checkout, the Android SDK screen on a device, webhook-first and
  client-first verification, cancel, failure, timeout / unknown, duplicate callback, amount / currency tamper, refunds
  (full / partial), settlement report. Then the owner decides LIVE (a reviewed code change of `allow_live` plus
  `security:check` item 32 — Module 42).
- Key mix: a `rzp_live_` key with `RAZORPAY_MODE=test` (or the reverse) is refused (tested); LIVE is refused while
  `allow_live` is false.

### Firebase Cloud Messaging
- Owner: a production Firebase project; register the Android app `com.foodonthego.app` (SHA-256 of the release / Play app
  signing certificate); service-account JSON for the backend mounted as a file (`FIREBASE_PROJECT_ID`,
  `FIREBASE_CREDENTIALS`, `FCM_RESTRICTED_PACKAGE_NAME`).
- The Android build gets the Firebase client options as defines (`FIREBASE_API_KEY`, `FIREBASE_APP_ID`,
  `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID`) — client values, public by design; the production gate refuses a build
  without them (Module 41).
- Notification channels exist in the app (orders, pickup, account, offers); lock-screen visibility PRIVATE; no pickup
  code, QR token or OTP in any push (verified in code and tests).
- Verification: a push to the physical device in foreground, background and terminated state; tap navigation;
  Android 13+ permission prompt and the "denied" path (CF-574).

### SMS / OTP (India)
- Owner: choose the provider (MSG91, 2Factor or Twilio are implemented), DLT registration (principal entity, sender
  header, OTP content template), keys in the secret store, `SMS_DRIVER`, `OTP_SMS_TEMPLATE` (the DLT text).
- Verification: `php artisan otp:test` to a real phone, then sign-in on web and Android; delivery-failure fallback;
  rate limits and lockouts as configured (Module 39).
- `OTP_DEV_CODE` must not exist in production (security:check item 40; the development code is refused outside
  local / testing in code).

### E-mail
- Owner: a transactional mail provider (SMTP or API) with an SPF / DKIM / DMARC-verified sending domain; `MAIL_*`;
  `MAIL_FROM_ADDRESS` on that domain.
- Verification: a staff invitation and a password reset end to end; notification e-mail to a verified customer
  address. Note REL-014: invitations are sent inside the invite transaction and SMTP has no timeout (CF-565).

### Object storage
- Decision (CF-560): single app host for launch with `storage/app` on a backed-up volume, or shared storage mounted on
  every web + worker host, or add the S3 adapter (code change: the media route must stream / redirect).
- Do not run `storage:link`; the private disk is never served (security:check item 26).

## 3. Development / fake provider guards (verified in Module 41)

| Guard | Where | Test |
|---|---|---|
| Fake payment / journey / payout providers only in local / testing | `config/payments.php`, `config/journey.php`, `PayoutProviderManager` | `HardeningConfigTest::test_a_deployment_that_sets_nothing_gets_the_safe_defaults` |
| Fake push / e-mail / SMS notification providers only in local / testing (Module 41) | `config/notifications.php` `allow_fake`, `AppServiceProvider` | `HardeningConfigTest::test_outside_local_and_testing_no_fake_notification_provider_runs_and_no_fixture_is_seeded` |
| TEST tax / fee rules and TEST commission plans only in local / testing (Module 41: allow-list) | `config/checkout.php`, `CommissionCalculationService`, `CommissionPlanService` | same test (config) + the finance suite |
| SMS log driver refused outside local / testing (Module 41: allow-list) | `SmsManager` | same test |
| Development OTP code only in local / testing with the log driver | `DevelopmentOtp` | Auth suite |
| `/api/v1/dev/*` routes only where fake providers are allowed | `routes/api.php` | `RouteInventoryTest` |
| `security:check` — 41 items, exit 1 outside local / testing | `app/Console/Commands/SecurityCheck.php` | `HardeningConfigTest::test_security_check_reports_locally_and_fails_when_judged_as_production` |
| Web release build refuses development data (Module 41) | `customer-web/src/security/releaseBuild.ts` | `releaseBuild.test.ts` |
| Android production build refuses development data, missing Firebase options, a local realtime host (Module 41 additions) | `mobile/lib/core/app_config.dart` | `build_environment_test.dart` |
