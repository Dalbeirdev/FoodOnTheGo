# Environment matrix

Status: Module 41 — 2026-10-15 — local only; **production = NO** (no staging or production environment exists yet).

One row per setting that differs between environments or that production must decide. The full list of the 537
environment variables the backend reads (file and line, default, secret flag) is in
[environment-variables-inventory.md](environment-variables-inventory.md). Templates: `backend/.env.example` (LOCAL),
`backend/phpunit.xml` (TEST), `backend/.env.production.example` (PRODUCTION — every key a deployment must decide),
`customer-web/.env.example` (web build), the dart-defines in `mobile/README.md` (Android build).

**Secret values are never written here** — a secret is named and marked; its value lives in the environment's
secret store. LOCAL shows the committed `.env.example` value (a development value), TEST the value `phpunit.xml`
forces for the automated suite. STAGING follows PRODUCTION with its own hosts, its own secrets and the providers'
TEST modes; there is no staging environment yet.

Gates that enforce the production column: `php artisan security:check` (41 items, exit 1 when any fails outside
local / testing), the web release gate (`customer-web/src/security/releaseBuild.ts`, stops `vite build`), the Android
production gate (`mobile/lib/core/app_config.dart` `configurationProblems`, the app refuses to start).

## 1. Backend (Laravel)

| Area | Variable | LOCAL | TEST | STAGING | PRODUCTION (required value / rule) | Secret |
|---|---|---|---|---|---|---|
| APP | `APP_ENV` | `local` | `testing` | as PRODUCTION (own hosts, provider TEST modes) | exactly `production` (every allow-list guard keys on `local`/`testing`; deny-list guards key on the literal `production`) | no |
| APP | `APP_DEBUG` | `true` | `true` | as PRODUCTION (own hosts, provider TEST modes) | `false` (security:check #1) | no |
| APP | `APP_URL` | `http://127.0.0.1:8001` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `https://<api host>` (security:check #3; also feeds trusted hosts `security.php:13,22` and media URLs) | no |
| APP | `APP_KEY` | (empty) | as LOCAL / config default | its own secret (never the production one) | 32-byte `base64:` key, generated once, backed up (encrypts MFA secrets, push tokens, pickup credentials, idempotency bodies; derives analytics pseudonyms when `ANALYTICS_PSEUDONYM_KEY` is empty) | **yes** |
| APP | `APP_PREVIOUS_KEYS` | — | as LOCAL / config default | its own secret (never the production one) | only during key rotation | **yes** |
| APP | `APP_VERSION` | `local-dev` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | build id / commit SHA (shown by `/api/v1/health`) | no |
| APP | `APP_NAME` | `FoodOnTheGo` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `FoodOnTheGo` (also cache / Redis prefixes and session cookie name) | no |
| APP | `APP_MAINTENANCE_DRIVER` | `file` | `file` | as PRODUCTION (own hosts, provider TEST modes) | `cache` when >1 app server | no |
| APP | `FRONTEND_URLS` | `http://localhost:5173,http://127.0.0.1:5173` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | comma list of the HTTPS origins of customer web, restaurant web and admin web; never localhost (security:check #9) | no |
| APP | `FRONTEND_URL` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | HTTPS customer-web origin used in reset / invitation e-mail links | no |
| APP | `MARKET_DEFAULT_COUNTRY` | `IN` | `IN` | as PRODUCTION (own hosts, provider TEST modes) | `IN` | no |
| DB | `DB_CONNECTION` | `pgsql` | `pgsql` | as PRODUCTION (own hosts, provider TEST modes) | `pgsql` | no |
| DB | `DB_HOST/PORT/DATABASE/USERNAME` | `127.0.0.1` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | managed PostgreSQL 16 + PostGIS; app role without superuser | no |
| DB | `DB_PASSWORD` | (empty) | as LOCAL / config default | its own secret (never the production one) | secret store | **yes** |
| DB | `DB_URL` | — | (empty) | its own secret (never the production one) | optional | **yes** (embeds password) |
| DB | `DB_SSLMODE` | `prefer` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `verify-full` (security:check #11 accepts require / verify-*). with `DB_SSLROOTCERT` = the CA file (key added in Module 41) | no |
| DB | `DB_PERSISTENT`, `DB_PGSQL_DISABLE_PREPARES` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | per Module 40: pooler (PgBouncer transaction mode) ⇒ `DB_PGSQL_DISABLE_PREPARES=true`, `DB_PERSISTENT=false` | no |
| Redis | `REDIS_CLIENT` | `predis` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `predis` (only predis is in composer.json) or install ext-redis | no |
| Redis | `REDIS_HOST/PORT/DB/CACHE_DB` | `127.0.0.1` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | managed Redis | no |
| Redis | `REDIS_PASSWORD`, `REDIS_USERNAME`, `REDIS_URL` | (empty) | as LOCAL / config default | its own secret (never the production one) | password required (security:check #12); TLS via `rediss://` in `REDIS_URL` (INFERRED) | **yes** |
| Redis | `REDIS_PERSISTENT` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | Module 40 decision | no |
| Queue | `QUEUE_CONNECTION` | `redis` | `sync` | as PRODUCTION (own hosts, provider TEST modes) | `redis` (as `.env.example`) — see §9 note on "Redis cache-only" | no |
| Queue | `REDIS_QUEUE_RETRY_AFTER` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | > longest job timeout + worker `--timeout` (longest job: `GenerateReportExportJob` 80 s) | no |
| Queue | `QUEUE_FAILED_DRIVER` | `database-uuids` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | keep (PostgreSQL `failed_jobs`) | no |
| Session | `SESSION_DRIVER` | `redis` | `array` | as PRODUCTION (own hosts, provider TEST modes) | `redis` | no |
| Session | `SESSION_SECURE_COOKIE` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `true` (security:check #5) | no |
| Session | `SESSION_HTTP_ONLY` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `true` (security:check #6) | no |
| Session | `SESSION_SAME_SITE` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `lax` | no |
| Session | `SESSION_DOMAIN` | `null` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | null (API host only) | no |
| Cache | `CACHE_STORE` | `redis` | `array` | as PRODUCTION (own hosts, provider TEST modes) | `redis` (scheduler `onOneServer` locks and rate limiters live here) | no |
| Cache | `CACHE_PREFIX` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | unique per environment if Redis is shared | no |
| Realtime | `BROADCAST_CONNECTION` | `reverb` | `null` | as PRODUCTION (own hosts, provider TEST modes) | `reverb` | no |
| Realtime | `REVERB_APP_ID` / `REVERB_APP_KEY` | (empty) | `100001` | as PRODUCTION (own hosts, provider TEST modes) | generated per environment | key = *public*, id = no |
| Realtime | `REVERB_APP_SECRET` | (empty) | (secret) | its own secret (never the production one) | secret store | **yes** |
| Realtime | `REVERB_HOST/PORT/SCHEME` | `127.0.0.1` | `127.0.0.1` | as PRODUCTION (own hosts, provider TEST modes) | public WSS host, 443, `https` | no |
| Realtime | `REVERB_SERVER_HOST/PORT` | `127.0.0.1` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | bind behind the TLS proxy | no |
| Realtime | `REVERB_ALLOWED_ORIGINS` | `localhost,127.0.0.1` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | production web host names only (security:check #8). See §9 for the Android app | no |
| Realtime | `REVERB_SCALING_ENABLED` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `true` when >1 Reverb process | no |
| Realtime | `REVERB_APP_RATE_LIMITING_ENABLED` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `true` recommended | no |
| Realtime | `ORDER_REALTIME_ENABLED` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | true | no |
| Google / journey | `PLACES_DRIVER`, `ROUTING_DRIVER` | (empty) | `fake` | as PRODUCTION (own hosts, provider TEST modes) | `google` | no |
| Google / journey | `PLACES_API_KEY`, `ROUTING_API_KEY` | (empty) | as LOCAL / config default | its own secret (never the production one) | server keys, IP-restricted, API-restricted | **yes** |
| Google / journey | `MAPS_DRIVER`, `MAPS_BROWSER_KEY` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | HTTP-referrer / package-restricted browser key | *public* |
| Google / journey | `JOURNEY_ALLOW_FAKE_PROVIDERS` | (empty) | `true` | as PRODUCTION (own hosts, provider TEST modes) | irrelevant in production (allow-list makes it false) | no |
| Payments | `PAYMENT_DRIVER` | (empty) | `fake` | as PRODUCTION (own hosts, provider TEST modes) | `razorpay` | no |
| Payments | `RAZORPAY_KEY_ID` | (empty) | (empty) | as PRODUCTION (own hosts, provider TEST modes) | `rzp_live_…` once live is enabled (today only `rzp_test_…` is accepted — §2f) | *public* |
| Payments | `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` | (empty) | (empty) | its own secret (never the production one) | secret store | **yes** |
| Payments | `RAZORPAY_MODE` | `test` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | must match the key prefix | no |
| Payments | `RAZORPAY_CAPTURE` | `automatic` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `automatic` | no |
| Payments | `PAYMENT_ALLOW_FAKE_PROVIDER` | (empty) | `true` | as PRODUCTION (own hosts, provider TEST modes) | irrelevant in production | no |
| Refunds | `REFUND_FOUR_EYES` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | true (security:check #20) | no |
| Refunds | `REFUND_AUTO_APPROVE_REASONS`, `REFUND_AUTO_APPROVE_MAX_MINOR` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | empty until the auto-refund policy is approved | no |
| Refunds | `ORDER_CUSTOMER_CANCELLATION_ENABLED` | `false` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | false until policy approved | no |
| Finance | `PAYOUT_DRIVER` | (empty) | (empty) | as PRODUCTION (own hosts, provider TEST modes) | empty (no real adapter; `fake` refused — security:check #18) | no |
| Finance | `FINANCE_SETTLEMENT_FOUR_EYES`, `FINANCE_PAYOUT_FOUR_EYES`, `FINANCE_ADJUSTMENT_FOUR_EYES` | — | `false` | as PRODUCTION (own hosts, provider TEST modes) | true (security:check #21-23) | no |
| Finance | `FINANCE_SETTLEMENTS_SCHEDULED`, `FINANCE_PROVIDER_RECONCILIATION_SCHEDULED` | `false` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | business decision | no |
| Finance | `FINANCE_TAX_OWNER`, `FINANCE_FEE_OWNER`, `FINANCE_SETTLEMENT_CADENCE`, `FINANCE_MINIMUM_PAYOUT_MINOR`, `FINANCE_REFUND_LIABILITY` | `UNASSIGNED` | `UNASSIGNED` | as PRODUCTION (own hosts, provider TEST modes) | PENDING BUSINESS / TAX approval | no |
| Notifications | `PUSH_DRIVER` | `fake` | `fake` | as PRODUCTION (own hosts, provider TEST modes) | `fcm` (security:check #15). No runtime guard — §2a | no |
| Notifications | `FIREBASE_PROJECT_ID` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | Firebase project id | no |
| Notifications | `FIREBASE_CREDENTIALS` | (empty) | (empty) | its own secret (never the production one) | mounted secret file | **yes** (path to service-account JSON) |
| Notifications | `FCM_RESTRICTED_PACKAGE_NAME` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | Android application id | no |
| Notifications | `EMAIL_NOTIFICATIONS_DRIVER` | `mail` | `fake` | as PRODUCTION (own hosts, provider TEST modes) | `mail` | no |
| Notifications | `SMS_NOTIFICATIONS_DRIVER` | `off` | `fake` | as PRODUCTION (own hosts, provider TEST modes) | `off` (any other value = the FAKE SMS provider, `AppServiceProvider.php:98`) | no |
| Notifications | `NOTIFICATIONS_ADMIN_TEST_ENABLED` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | irrelevant in production | no |
| Mail | `MAIL_MAILER` | `log` | `array` | as PRODUCTION (own hosts, provider TEST modes) | real transport (`smtp`/`ses`/`postmark`/`resend`) (security:check #13) | no |
| Mail | `MAIL_HOST/PORT/SCHEME/USERNAME` | `127.0.0.1` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | provider | no |
| Mail | `MAIL_PASSWORD`, `MAIL_URL`, `POSTMARK_API_KEY`, `RESEND_API_KEY`, `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` | (empty) | as LOCAL / config default | its own secret (never the production one) | secret store | **yes** |
| Mail | `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `"no-reply@foodonthego.local"` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | verified sender domain (SPF/DKIM/DMARC) | no |
| SMS / OTP | `SMS_DRIVER` | `log` | `log` | as PRODUCTION (own hosts, provider TEST modes) | `msg91` / `twofactor` / `twilio` (security:check #14; `log` throws when `APP_ENV=production`, `SmsManager.php:84-91`) | no |
| SMS / OTP | `SMS_FALLBACK_DRIVER` | (empty) | (empty) | as PRODUCTION (own hosts, provider TEST modes) | optional second provider | no |
| SMS / OTP | `SMS_MSG91_AUTH_KEY`, `SMS_2FACTOR_API_KEY`, `SMS_TWILIO_AUTH_TOKEN`, `SMS_TWILIO_ACCOUNT_SID` | (empty) | as LOCAL / config default | its own secret (never the production one) | secret store | **yes** |
| SMS / OTP | `SMS_MSG91_OTP_TEMPLATE_ID`, `SMS_2FACTOR_TEMPLATE`, `OTP_SMS_TEMPLATE` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | DLT-registered template (India) | no |
| SMS / OTP | `OTP_CHANNELS` | `sms` | `sms` | as PRODUCTION (own hosts, provider TEST modes) | `sms` or `whatsapp,sms` | no |
| SMS / OTP | `OTP_DEV_CODE` | (empty) | `123456` | as PRODUCTION (own hosts, provider TEST modes) | **must be unset** (also ignored in production — §2b) | sensitive |
| SMS / OTP | `OTP_LENGTH/TTL_SECONDS/MAX_ATTEMPTS/RESEND_COOLDOWN_SECONDS/MAX_SENDS_PER_HOUR` | `6` | `6` | as PRODUCTION (own hosts, provider TEST modes) | keep or approve | no |
| SMS / OTP | `WHATSAPP_ACCESS_TOKEN` | (empty) | (empty) | its own secret (never the production one) | only if WhatsApp OTP is used | **yes** |
| SMS / OTP | `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_OTP_TEMPLATE` | (empty) | (empty) | as PRODUCTION (own hosts, provider TEST modes) | approved AUTHENTICATION template | no |
| SMS / OTP | `TRUECALLER_CLIENT_ID` | (empty) | (empty) | as PRODUCTION (own hosts, provider TEST modes) | set only if one-tap sign-in is approved | no (public client id) |
| Storage | `FILESYSTEM_DISK` | `local` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `local` (S3 is not usable — §8) | no |
| Storage | `CUSTOMER_AVATAR_DISK`, `MENU_IMAGE_DISK` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | must be a LOCAL disk on shared storage (§8) | no |
| Storage | `AWS_BUCKET/URL/ENDPOINT/DEFAULT_REGION` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | unused (no S3 adapter) | no |
| Logging | `LOG_CHANNEL` / `LOG_STACK` | `stack` | `null` | as PRODUCTION (own hosts, provider TEST modes) | `stack` / `structured` (or `stderr` in containers) — both carry the redaction tap | no |
| Logging | `LOG_LEVEL` | `debug` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | `info` (or `warning`) | no |
| Logging | `LOG_DAILY_DAYS` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | PENDING retention approval | no |
| Logging | `LOG_SLACK_WEBHOOK_URL`, `PAPERTRAIL_URL` | — | as LOCAL / config default | its own secret (never the production one) | do NOT use — these channels have no redaction tap (§7) | **yes** (webhook) |
| Observability | `OBSERVABILITY_REQUEST_LOG/SLOW_MS/SERVER_TIMING` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | keep; `SERVER_TIMING=false` | no |
| CORS | `FRONTEND_URLS` | `http://localhost:5173,http://127.0.0.1:5173` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | see APP row | no |
| Cookie / Sanctum | `SANCTUM_STATEFUL_DOMAINS` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | irrelevant (no stateful guard, §3) | no |
| Cookie / Sanctum | `SANCTUM_TOKEN_PREFIX` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | recommended e.g. `fotg_` (secret-scanning friendly) | no |
| Security | `TRUSTED_PROXIES` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | **load balancer CIDRs** — without it every client is the LB's IP and every per-IP limit (OTP 10/min per IP, login, journey) becomes one global bucket (INFERRED from `AppServiceProvider.php:106-109`, `:217-323`) | no |
| Security | `TRUSTED_HOSTS` | (empty) | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | extra API host names if any | no |
| Security | `API_READINESS_PUBLIC` | `true` | `true` | as PRODUCTION (own hosts, provider TEST modes) | `false` (security:check #10) | no |
| Security | `AUTH_MFA_REQUIRED_ADMIN` | — | `false` | as PRODUCTION (own hosts, provider TEST modes) | true (security:check #19) | no |
| Security | `AUTH_MFA_REQUIRED_RESTAURANT` | `false` | `false` | as PRODUCTION (own hosts, provider TEST modes) | business decision (recommend true for OWNER) | no |
| Security | `AUTH_ADMIN_IDLE_MINUTES` | `60` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | > 0 (security:check #24) | no |
| Security | `AUTH_TOKEN_TTL_CUSTOMER/RESTAURANT/ADMIN_MINUTES` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | approve | no |
| Security | `AUTH_LOCKOUT_*` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | keep | no |
| Security | `SECURITY_MAX_BODY_BYTES` | `262144` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | > 0 (security:check #25) | no |
| Security | `RESTAURANT_CONTACT_HOURS` | `24` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | PENDING approval | no |
| Security | `LOCAL_FIXTURE_PASSWORD` | (empty) | (secret) | its own secret (never the production one) | **must be unset** in production | **yes** |
| Security | `ADMIN_BOOTSTRAP_PASSWORD` | (empty) | (empty) | its own secret (never the production one) | set only for the one bootstrap run, then removed | **yes** |
| Rate limits | `RATE_LIMIT_*` (58 keys) | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | keep defaults; review `RATE_LIMIT_OTP_GLOBAL_PER_HOUR=600` against SMS budget | no |
| Analytics | `ANALYTICS_PSEUDONYM_KEY` | (empty) | as LOCAL / config default | its own secret (never the production one) | set explicitly (so an APP_KEY rotation does not re-key every pseudonym) | **yes** |
| Analytics | `ANALYTICS_EVENTS_ENABLED` | `true` | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | decide with the consent design (no consent screen is built) | no |
| Legal | `LEGAL_TERMS/PRIVACY/REFUND_VERSION`, `…_EFFECTIVE_DATE` | — | as LOCAL / config default | as PRODUCTION (own hosts, provider TEST modes) | approved document versions (LAUNCH BLOCKER until legal text approved) | no |

## 2. Customer Web / Restaurant Dashboard / Platform Admin (`vite build`)

| Variable | LOCAL | TEST (vitest) | STAGING / PRODUCTION | Secret |
|---|---|---|---|---|
| `VITE_ENVIRONMENT` | `local` | not used | `staging` / `production` — REQUIRED; unset stops the build | no |
| `VITE_API_BASE_URL` | `http://127.0.0.1:8001/api/v1` | mocked | `https://<api host>/api/v1` (not local) | no |
| `VITE_AUTH_MODE … VITE_REVIEW_MODE (10 domain switches)` | `api` | always mock | `api` each — any other value stops the build | no |
| `VITE_REALTIME_MODE` | `api` | off | `api` | no |
| `VITE_REVERB_HOST / PORT / SCHEME` | `127.0.0.1` / `8085` / `http` | — | realtime host / `443` / `https` (wss) | no |
| `VITE_REVERB_APP_KEY` | the local Reverb key | — | the Reverb APP KEY (public by design; never the secret) | no |
| `VITE_DEV_OTP` | local test code (stripped from every build) | — | EMPTY — a set value stops the build | no |
| `VITE_ANALYTICS_MODE` | unset (follows ORDER mode) | off | unset or `api` (consent decision CF-528) | no |
| `VITE_SHARE_BUILD / VITE_ROUTER` | share preview only | — | never set | no |

## 3. Android app (`flutter build … --dart-define=…`)

| Define | LOCAL (`--flavor local`) | TEST (flutter test) | STAGING / PRODUCTION (`--flavor staging` / `production`) | Secret |
|---|---|---|---|---|
| `APP_ENV` | `local` | local | decided by the flavor (production flavor = production) | no |
| `API_BASE_URL` | `http://<PC LAN address>:8001/api/v1` | — | `https://<api host>/api/v1` — required | no |
| `AUTH_MODE … REVIEW_MODE (10)` | `api` | mock | `api` each — required | no |
| `REALTIME_MODE` | `api` | — | `api` | no |
| `REVERB_HOST / PORT / SCHEME / APP_KEY` | PC address / `8085` / `http` / local key | — | realtime host / `443` / `https` / Reverb APP KEY — host and scheme checked | no |
| `PUSH_MODE` | `fake` | off | `fcm` — required | no |
| `FIREBASE_API_KEY / APP_ID / SENDER_ID / PROJECT_ID` | empty | — | the production Firebase Android app (client values) — required (Module 41) | no |
| `DEV_OTP` | local test code | — | EMPTY — a set value stops the app | no |
| `BUILD_NAME / BUILD_NUMBER` | `0.1.0` / `45` | defaults | the release version (same as --build-name / --build-number) | no |
| `Signing (key.properties / CI secret)` | debug key | — | upload keystore outside git (CF-548) — **secret** | yes |

## 4. Infrastructure values (Module 42)

| Item | LOCAL | PRODUCTION |
|---|---|---|
| Web server / runtime | `php artisan serve` / `php -S` behind scripts/local/api-proxy.mjs (development servers) | nginx + PHP-FPM 8.3 (OPcache, `opcache.validate_timestamps=0`) behind the load balancer |
| Queue workers | started by hand / by the suites | supervised `queue:work redis --queue=payments,orders,finance,notifications,default --timeout=85 --max-time=3600`, at least 2 |
| Scheduler | by hand | one `schedule:run` per minute (cron); onOneServer needs the shared Redis cache |
| Realtime | `reverb:start` on 127.0.0.1:8085 | Reverb on Linux (ext-uv / ext-event) behind a TLS proxy (wss); allowed origins = the web hosts |
| PostgreSQL | 18.6 + PostGIS 3.6.2 (portable, Windows) | managed PostgreSQL 16 + PostGIS (CF-268), TLS verify-full, PITR, least-privilege role (CF-550) |
| Redis | 5.0 (Windows build), no password | current Redis / Valkey with password + TLS, `maxmemory-policy noeviction`, persistence for the queue |
| Storage | local disks | one app host, or `storage/app` on shared storage for every web + worker host (CF-560) |
| Monitoring | reliability view + logs on disk | log collector + alerts from `ops:reliability --alerts` (CF-566) |
