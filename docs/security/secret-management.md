# Secret management

Status: Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/.env.example`, `backend/.env.production.example`, `backend/config/*.php` (`app`, `database`, `broadcasting`, `reverb`, `services`, `notifications`, `mail`, `filesystems`, `queue`, `cache`, `analytics`, `auth_security`, `logging`, `finance`), `backend/app/Services/**` (HMAC and encryption users), `backend/app/Models/*` (casts), `backend/app/Console/Commands/SecurityCheck.php`, `.gitignore`, `backend/.gitignore`, `customer-web/.gitignore`, `customer-web/.env.example`, `mobile/android/.gitignore`, the Module 39 git history scan (`history_scan.txt`), `vendor/laravel/framework/src/Illuminate/Encryption/*` (Laravel 13.35.0).

This document names variables only. No value of any secret appears here. `backend/.env` was not opened.

No production environment and no production secret exist. Real providers (Razorpay, FCM, e-mail, SMS) are implemented but **not configured and not verified**. Every rotation period below is **PENDING BUSINESS / SECURITY APPROVAL**.

## 1. Secret inventory

"Public" means the value is meant to reach a browser or app and is not a secret. Everything else is server-side only.

| Variable | Read in | Who needs it | Kind |
|---|---|---|---|
| `APP_KEY` | `config/app.php:111` | Backend: `encrypted` casts, HMAC keys (see §3) | Secret |
| `APP_PREVIOUS_KEYS` | `config/app.php:113-117` | Backend: decrypting values written under an older key | Secret |
| `DB_USERNAME`, `DB_PASSWORD` | `config/database.php:93-94` (pgsql) | Backend, queue workers, scheduler | Secret |
| `REDIS_USERNAME`, `REDIS_PASSWORD` | `config/database.php:161-162`, `:174-175`; `config/reverb.php:47-48` | Backend (cache, queue, sessions, limiter), Reverb scaling | Secret |
| `REVERB_APP_ID`, `REVERB_APP_SECRET` | `config/broadcasting.php:35-37`; `config/reverb.php:76-78` | Backend and the Reverb server (channel auth signing) | Secret (`_SECRET`) |
| `REVERB_APP_KEY` / `VITE_REVERB_APP_KEY` | same; `customer-web/.env.example:48` | Web and app WebSocket client | Public by design (`customer-web/.env.example:43`) |
| `RAZORPAY_KEY_ID` | `config/services.php:124` | Backend; the browser / app checkout | Public (the only Razorpay value a client may see, `.env.example:244-245`) |
| `RAZORPAY_KEY_SECRET` | `config/services.php:125` | Backend (API calls, payment signature check) | Secret |
| `RAZORPAY_WEBHOOK_SECRET` | `config/services.php:126` | Backend webhook endpoint (HMAC over the raw body, `RazorpayPaymentProvider.php:215-222`) | Secret |
| `FIREBASE_PROJECT_ID`, `FIREBASE_CREDENTIALS` | `config/notifications.php:32-33` | Backend push sender (`FcmHttpV1PushProvider.php:44`); the credentials value is an absolute path to a service-account JSON outside the repository | Secret (the JSON file) |
| `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH` | `config/services.php:142-143` | **No code reads `services.fcm`** (searched `backend/app`). Legacy duplicates of the two above | Unused |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | `config/mail.php:46-47` | Backend SMTP mailer | Secret |
| `SMS_MSG91_AUTH_KEY` | `config/services.php:54` | Backend SMS (MSG91) | Secret |
| `SMS_2FACTOR_API_KEY` | `config/services.php:59` | Backend SMS (2Factor) | Secret |
| `SMS_TWILIO_ACCOUNT_SID`, `SMS_TWILIO_AUTH_TOKEN` | `config/services.php:64-65` | Backend SMS (Twilio) | Secret (`_AUTH_TOKEN`) |
| `WHATSAPP_ACCESS_TOKEN` | `config/services.php:77` | Backend WhatsApp OTP sender | Secret |
| `TRUECALLER_CLIENT_ID` | `config/services.php:90` | Backend Truecaller verification (`TruecallerVerifier.php:23`) | Identifier (treat as confidential) |
| `PLACES_API_KEY`, `ROUTING_API_KEY` | `config/services.php:103`, `:110` | Backend Google Places / Routes providers | Secret (server key) |
| `MAPS_BROWSER_KEY` | `config/services.php:97` | No backend code reads it (searched `backend/app`); a browser key must be restricted by referrer | Public if used |
| `ANALYTICS_PSEUDONYM_KEY` | `config/analytics.php:122` | Backend analytics pseudonyms; empty = derived from `APP_KEY` (`Pseudonymizer.php:54-58`) | Secret |
| `LOCAL_FIXTURE_PASSWORD` | `config/auth_security.php:71` | Local / testing fixture seeding only; never set elsewhere | Secret (local) |
| `ADMIN_BOOTSTRAP_PASSWORD` | `config/auth_security.php:78` | `php artisan admin:create` when unattended; empty = hidden prompt | Secret (one-time) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | `config/filesystems.php:54-55`, `config/services.php:26-27`, `config/queue.php:58-59`, `config/cache.php:91-92` | Framework defaults (S3 / SES / SQS / DynamoDB); not used by the local configuration | Secret if used |
| `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN` | `config/services.php:18`, `:22`, `:152` | Framework defaults; not in `.env.example`; used only if such a driver is chosen | Secret if used |
| Android release keystore (`key.properties`, `*.jks`) | `mobile/android` build | Release signing | Secret — **does not exist yet: release builds use the debug key (production blocker)** |
| `google-services.json` | Android build | Firebase client configuration | Kept out of git (`.gitignore:49`) |

No payout provider secret exists: there is no real payout adapter (`config/finance.php:138-142`).

Hard-coded development keys: `FakePaymentProvider` and `FakePayoutProvider` hold fixed signing / webhook keys in source (`FakePaymentProvider.php:144`, `:362`; `FakePayoutProvider.php:129`). They are usable only where fake providers are allowed (`APP_ENV` in `local` / `testing`, `config/payments.php:21`); `security:check` fails if a fake provider is allowed.

## 2. Where secrets live

| Environment | Store |
|---|---|
| Local development | `backend/.env` (git-ignored); `customer-web/.env.local` (git-ignored, contains only public `VITE_*` values by design) |
| Test suite | `phpunit.xml` forces local test values; no real credential |
| Production | **Decision for deployment (Modules 40–42):** a managed secret manager (cloud KMS-backed store or vault) injecting environment variables at start. Not chosen. `.env.production.example` lists every secret **empty** and says it is set in the production secret store, never in a file in the repository (`.env.production.example:1-3`, `:58-68`) |

Rules for the production store (proposal, PENDING APPROVAL): one secret per variable; access only for the runtime role of the API, queue worker and scheduler; separate secrets per environment; audit log of reads and changes; no human reads in normal operation.

## 3. Rotation procedures

### APP_KEY (Laravel 13 `APP_PREVIOUS_KEYS`)

Laravel 13.35 reads `APP_PREVIOUS_KEYS` (comma-separated) into `app.previous_keys` (`config/app.php:113-117`). The encrypter tries the current key and then each previous key when it decrypts (`Encrypter.php:379`, `:400-410`; `EncryptionServiceProvider.php:33-35`).

Procedure:

1. Generate a new key outside the repository.
2. Set `APP_PREVIOUS_KEYS=<old key>` (and any older keys still needed) and `APP_KEY=<new key>` in the secret store.
3. Restart the API, queue workers, scheduler and Reverb together.
4. Keep the old key in `APP_PREVIOUS_KEYS` until every value encrypted with it has been rewritten or has expired. **No re-encryption command exists** in `app/Console` (searched); values are re-encrypted only when the application writes them again.
5. Then remove the old key from `APP_PREVIOUS_KEYS`.

Values covered by `APP_PREVIOUS_KEYS` (Laravel `encrypted` casts):

| Value | Cast |
|---|---|
| Staff `mfa_secret`, `mfa_recovery_codes` | `Models/Concerns/StaffAccount.php:44-45` |
| `customer_devices.push_token` | `CustomerDevice.php:78` |
| `customer_payment_methods.provider_customer_reference`, `provider_payment_method_reference` | `CustomerPaymentMethod.php:56` |
| `idempotency_keys.response_body` | `IdempotencyKey.php:33` |
| `order_pickup_credentials.display_code`, `token_secret` | `OrderPickupCredential.php:35` |

Values **not** covered — HMACs keyed with `APP_KEY` change at once:

| Use | Code | Effect of rotation |
|---|---|---|
| OTP code hash | `OtpService.php:176` | Codes in flight fail; the customer asks for a new code |
| Manual pickup code | `PickupCredentials.php:143-164` | Open credentials fail manual-code verification; reissue the credential |
| Guest cart token | `GuestCartToken.php:56` | Existing guest carts become unreachable |
| Pickup slot key | `PickupSlotKey.php:48-50` | Selected slots no longer match; the cart revalidates |
| `security_events.identifier_hash` | `SecurityEventRecorder.php:49-51` | Old and new events of one identifier no longer match |
| Security event public id | `SecurityEventResource.php:22` | Ids shown to admins change |
| Analytics pseudonyms (only when `ANALYTICS_PSEUDONYM_KEY` is empty) | `Pseudonymizer.php:54-58` | A customer gets a new pseudonym; history is no longer linked |

Not affected: access tokens, reset tokens and invitation tokens are plain SHA-256 hashes (Sanctum; `PasswordResetService.php:48`; `RestaurantStaffService.php:351`).

Recommendation (PENDING APPROVAL): set `ANALYTICS_PSEUDONYM_KEY` separately in production so an app-key rotation does not reset analytics continuity; rotate `APP_KEY` at a quiet hour; and only on a schedule or after suspected exposure.

### Other secrets

| Secret | Procedure |
|---|---|
| `DB_PASSWORD` | Create the new password on the PostgreSQL role (or a second role), update the store, restart all processes, then drop the old one. Least-privilege role (app user not the table owner) is a deployment decision. |
| `REDIS_PASSWORD` | Redis ACL user with the new password, update the store, restart API / workers / Reverb, remove the old password. Cache loss is harmless (cache-only use). |
| `REVERB_APP_SECRET` | Change in the store; restart the API and the Reverb server together. Clients use only the public key. If `REVERB_APP_KEY` changes too, rebuild and release the web app and Android app. |
| `RAZORPAY_KEY_SECRET` | Regenerate the key pair in the Razorpay dashboard; update `RAZORPAY_KEY_ID` (public) and `RAZORPAY_KEY_SECRET` together; restart. Not exercised (no credentials). |
| `RAZORPAY_WEBHOOK_SECRET` | Set a new secret on the webhook in the Razorpay dashboard and in the store at the same time. Deliveries signed with the old secret during the switch are rejected and retried by the provider (provider behaviour not verified). |
| `FIREBASE_CREDENTIALS` | Create a new key for the service account, replace the JSON file, restart, then delete the old key in Google Cloud. The cached access token (`notifications:fcm:access-token`) expires on its own. |
| SMS / WhatsApp / mail / Places / Routing keys | Create the new key at the provider, update the store, restart, revoke the old key. Restrict server keys by IP / API where the provider allows it. |
| `ANALYTICS_PSEUDONYM_KEY` | Changing it starts new pseudonyms (`.env.example:373`); rotate only with a business decision on analytics continuity. |
| `ADMIN_BOOTSTRAP_PASSWORD` | One-time use; remove from the store after the first administrator exists and change that password at first sign-in. |
| Android release key | Not created yet. Store the keystore and its passwords in the secret manager; never in the repository (`.gitignore:8-10`). |

After any suspected exposure: rotate, revoke the old value at the provider, review `security_events` and provider logs, and follow the incident-response procedure (separate document).

## 4. Never commit, never log

Never committed (git ignore rules, current working tree):

| Rule | File:line |
|---|---|
| `backend/.env`, `backend/.env.*` except the two example files | `.gitignore:2-5`; `backend/.gitignore` (`.env`, `.env.backup`, `.env.production`) |
| `customer-web/.env.local` | `.gitignore:6`; `customer-web/.gitignore` (`.env`, `.env.*`, `*.local`, keeps `.env.example`) |
| Android `local.properties`, `key.properties`, `*.jks`, `*.keystore` | `.gitignore:7-10`; `mobile/android/.gitignore:12-14` |
| `backend/storage/*.key`, logs | `.gitignore:25-26` |
| Firebase service accounts, `google-services.json`, `GoogleService-Info.plist` | `.gitignore:44-50` |

Never logged:

- Log context keys matching `password`, `otp`, `one_time`, `cvv`, `cvc`, `pin`, `secret`, `token`, `authorization`, `cookie`, `api_key`, `apikey`, `private_key`, `card_number`, `signature`, `credential`, `recovery_code`, `mfa_challenge`, `=code` are masked on every channel (`config/logging.php:55-59`; tap on `:74`, `:83`, `:94`, `:135`).
- Messages: Bearer tokens, SQL text and bindings, `Key (…)=(…)` values, phone numbers (`+********1234`) and e-mail local parts are masked; outside local / testing one-time links too (`RedactSensitiveData.php:16-17`; `SensitiveDataRedactor.php:43-63`).
- Stack traces carry no call arguments (`bootstrap/app.php:20`).
- `security:check` prints names and verdicts only, never a value (`SecurityCheck.php:15`); pinned by `HardeningConfigTest::test_security_check_reports_locally_and_fails_when_judged_as_production`.
- Webhook logs carry size and signature validity, never the body (`PaymentWebhookService.php:69`).
- Clients: a production web build carries no `VITE_DEV_OTP` value (`customer-web/vite.config.ts:47`); Android debug logs are behind `kDebugMode` (client report).

## 5. Git history scan (Module 39)

Scan of 117 commits on every local ref, 1,250,565 patch lines read.

| Check | Result |
|---|---|
| Any value of the local `backend/.env` ever committed (4 secret-like variables checked by value: `APP_KEY`, `DB_PASSWORD`, `LOCAL_FIXTURE_PASSWORD`, `REVERB_APP_SECRET`) | **None** |
| Files that must never be committed (`.env`, key stores, service-account files, `google-services.json`) ever added | **None** |
| Private key blocks, Google API keys, service-account `private_key`, AWS key ids, Slack / GitHub tokens, JWTs, Laravel `base64:` app keys outside the examples | 0 locations each |
| Razorpay live key-id shape | 3 locations, all in tests that check a live key is refused (`PaymentSecurityTest.php`, `PaymentRefundAndAdapterTest.php`, `RazorpayPaymentProviderTest.php`) — test strings, not credentials |
| Razorpay test key-id shape | 6 locations in backend, web and mobile tests — test strings |
| Sanctum token shape | 1 location: `docs/local-review/test-results/m03-api-live-curl.txt` (a local development test log). Whether that local token is still valid was **not verified**; local tokens should be revoked / pruned |

## 6. Production checks

`php artisan security:check` (32 checks) fails outside local / testing when, among others: `APP_DEBUG` is on, `APP_KEY` is shorter than 32 characters, `DB_SSLMODE` is not require / verify-*, Redis has no password, mail / SMS / push are not real providers, a fake payment, journey or payout provider is allowed (`SecurityCheck.php:28-58`). Locally 9 of 29 pass (expected).

## 7. Open items

| Item | Status |
|---|---|
| Production secret manager | Deployment decision (Modules 40–42) |
| Android release signing key | Production blocker |
| Real provider credentials (Razorpay, FCM, e-mail, SMS, Google) | Pending; nothing verified against a real provider |
| `FCM_PROJECT_ID` / `FCM_CREDENTIALS_PATH` unused duplicates | Removed from `config/services.php` and `.env.example` (Module 39 follow-up) |
| Rotation periods | PENDING BUSINESS / SECURITY APPROVAL |
| `security:check` checks `REVERB_ALLOWED_ORIGINS` (item 30, added after this review); whether `ANALYTICS_PSEUDONYM_KEY` is set is not checked (it falls back to the application key) | Open (low) |
