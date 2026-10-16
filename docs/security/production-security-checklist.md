# Production security checklist

Module 39 — 2026-10-14, updated in Module 41 (2026-10-15) — local only; production = NO

Sources: `backend/app/Console/Commands/SecurityCheck.php` (and its output on this machine, 2026-10-14), `backend/.env.production.example`, `backend/config/{auth_security,refunds,finance,payments,journey,filesystems,cors,security,database,logging,mail,notifications,support,legal}.php`, `backend/routes/api.php`, `backend/bootstrap/app.php`, `backend/tests/Feature/Security/*.php`, `customer-web/vite.config.ts`, `customer-web/src/{config/modeOverride.ts,auth/devOtp.ts,auth/privateContext.ts,security/contentSecurityPolicy.ts,api/client.ts}`, `mobile/android/app/build.gradle.kts`, `mobile/lib/core/app_config.dart`, M39 `docs_brief.md` (decisions and blockers), `client_agent_report.txt`, `npm_audit_after_fix.json`, `composer-audit.json`.

Status values:

- **DONE** — implemented and checked locally; the evidence is named. It does not mean verified in production.
- **OPEN** — work or a decision is still needed in the product. **BLOCKER** marks an open item that must be closed
  before production.
- **DEPLOYMENT** — belongs to the production environment (Modules 40–42); nothing to change in the code, or it is
  only checkable on the real installation.

No compliance (PCI DSS, DPDP, ISO 27001, SOC 2, OWASP ASVS / MASVS) is claimed; none was assessed.

## 1. `php artisan security:check` — 41 checks (32 of Module 39 + 9 of Module 41)

The command reads the configuration of the installation it runs on and never prints a secret. Outside `local` /
`testing` (or with `--as-production`) any failure exits 1: **a deployment must run it and stop on failure.** The
"Local" column is this machine's result on 2026-10-14 (10 / 32 pass — expected for a development install;
`docs/local-review/test-results/backend-m39-security-check.txt`).

| # | Check (`SecurityCheck.php:29-61`) | Local | Status |
|---|---|---|---|
| 1 | `APP_DEBUG` is off | FAIL | DEPLOYMENT (`.env.production.example` sets `false`) |
| 2 | `APP_KEY` is set | PASS | DEPLOYMENT (from the secret store) |
| 3 | `APP_URL` is https | FAIL | DEPLOYMENT |
| 4 | Trusted hosts configured (`APP_URL` / `TRUSTED_HOSTS`) | PASS | DONE in code (`config/security.php:22`); production host DEPLOYMENT |
| 5 | Session cookie Secure | FAIL | DEPLOYMENT (`SESSION_SECURE_COOKIE=true`) |
| 6 | Session cookie HttpOnly | PASS | DEPLOYMENT (keep) |
| 7 | CORS: no wildcard, no credentials | PASS | DONE (`config/cors.php`; `HardeningConfigTest::test_cors_answers_only_configured_origins_and_never_allows_credentials`) |
| 8 | CORS: no localhost origin | FAIL | DEPLOYMENT (`FRONTEND_URLS`) |
| 9 | Readiness endpoint not public | FAIL | DEPLOYMENT (`API_READINESS_PUBLIC=false`) |
| 10 | Database requires TLS (`DB_SSLMODE` require / verify-*) | FAIL | DEPLOYMENT (default is `prefer`, `config/database.php:99`; example sets `verify-full`) |
| 11 | Redis has a password | FAIL | DEPLOYMENT |
| 12 | Mail is a real provider (set; not log / array) | FAIL | DEPLOYMENT — the check also refuses an empty `MAIL_MAILER` (fixed after the documentation review). Set a real mailer. |
| 13 | SMS is a real provider (not log / fake / empty) | FAIL | DEPLOYMENT — real SMS PENDING PROVIDER VERIFICATION |
| 14 | Push is FCM | FAIL | DEPLOYMENT — real FCM PENDING CONFIGURATION |
| 15 | Fake payment provider refused | FAIL | DONE in code: true only when `APP_ENV` ∈ {local, testing} (`config/payments.php:21`; `HardeningConfigTest::test_a_deployment_that_sets_nothing_gets_the_safe_defaults`) |
| 16 | Fake journey providers refused | FAIL | DONE in code (`config/journey.php:24`; same test) |
| 17 | Payout provider is not the fake one | FAIL | DEPLOYMENT — only the fake payout provider exists; live payouts disabled |
| 18 | Administrator MFA required | FAIL | DONE: default on outside local / testing (`config/auth_security.php:58`; same test) |
| 19 | Four-eyes: refunds | FAIL | DONE: default on outside local / testing (`config/refunds.php:28`) |
| 20 | Four-eyes: settlements | FAIL | DONE (`config/finance.php:130`) |
| 21 | Four-eyes: payouts | FAIL | DONE (`config/finance.php:147`) |
| 22 | Four-eyes: manual adjustments | FAIL | DONE (`config/finance.php:166`) |
| 23 | Administrator idle timeout on | PASS | DONE (default 60 min, also in the production example; PENDING BUSINESS APPROVAL) — `AuthHardeningTest::test_an_idle_administrator_session_ends_and_an_active_one_does_not` |
| 24 | Private disk not served over HTTP | PASS | DONE (`config/filesystems.php:38`) |
| 25 | No development routes (`/api/v1/dev/*`, `_boost`) | FAIL | `/dev` DONE in code (registered only where fake providers are allowed, `routes/api.php:263`; `RouteInventoryTest::test_development_routes_exist_only_where_fake_providers_are_allowed`); `_boost` DEPLOYMENT (install without dev packages) |
| 26 | No default `/broadcasting/auth` web route | PASS | DONE (`RouteInventoryTest::test_the_class_rules_cover_the_whole_api_and_the_dangerous_framework_routes_are_gone`) |
| 27 | Development packages not installed (`laravel/boost`) | FAIL | DEPLOYMENT (`composer install --no-dev`) |
| 27b | Audit and security trails append-only (database triggers present) | FAIL | DONE in every environment except the LOCAL development database (BUG-323: the browser suites delete their own audit rows); `DataProtectionTest` proves them on the test database |
| 28 | Audit purge not enabled for the session | PASS | DONE locally; DEPLOYMENT (never set `fotg.audit_purge` at database level) |
| 29 | Live payments stay off until a deliberate change | PASS | DONE (code constant `allow_live => false`, `config/payments.php:24`); turning it on is a later, reviewed code change |
| 30 | Realtime: no wildcard or localhost origin (`REVERB_ALLOWED_ORIGINS`) | FAIL | DEPLOYMENT (the production example sets the web origin; locally `localhost,127.0.0.1`) |
| 31 | Request body limit on (`SECURITY_MAX_BODY_BYTES`, default 262144) | PASS | DONE — `LimitRequestBody` (413 before decoding); `HardeningConfigTest::test_an_oversized_request_body_is_refused_before_it_is_read_and_uploads_keep_their_own_limit` |

Module 41 (final readiness review) added nine items — locally all FAIL by design (a development install), each turns
PASS with the production value of [environment-matrix.md](../production/environment-matrix.md):

| # | Check | Local | Status |
|---|---|---|---|
| 33 | TEST tax / fee rules are refused (`checkout.financial_rules.allow_test`, now an allow-list of local / testing) | FAIL | DEPLOYMENT (false outside local / testing by construction) |
| 34 | Fake notification providers are refused (push / e-mail / SMS — `notifications.allow_fake`, new runtime guard) | FAIL | DONE in code — `HardeningConfigTest::test_outside_local_and_testing_no_fake_notification_provider_runs_and_no_fixture_is_seeded` |
| 35 | Places and routing use the live provider (`PLACES_DRIVER` / `ROUTING_DRIVER` = google) | FAIL | OPEN — BLOCKER (CF-352) |
| 36 | FCM credentials present (`FIREBASE_PROJECT_ID`, readable `FIREBASE_CREDENTIALS`) | FAIL | OPEN — BLOCKER (CF-450) |
| 37 | Web origins set and https only (`FRONTEND_URLS`, `FRONTEND_URL`) | FAIL | DEPLOYMENT |
| 38 | Trusted proxies configured (`TRUSTED_PROXIES`) | FAIL | DEPLOYMENT |
| 39 | Log level is not debug (`LOG_LEVEL`) | FAIL | DEPLOYMENT |
| 40 | No development secret set (`LOCAL_FIXTURE_PASSWORD`, `OTP_DEV_CODE`, `ADMIN_BOOTSTRAP_PASSWORD`) | FAIL | DEPLOYMENT |
| 41 | Legal documents are approved versions (no `draft-*`) | FAIL | OPEN — BLOCKER (CF-394) |

Local result 2026-10-15: **10 / 41** (`docs/local-review/test-results/m41-security-check.txt`). On a clean install with
`APP_ENV=production` (Module 41 clean-migration test): 20 / 32 of the Module 39 items before the new ones were added —
every FAIL a deployment value (`docs/local-review/test-results/m41-migration-tests.txt`).

## 2. Blockers (production = NO until closed)

| # | Item | Status |
|---|---|---|
| B-1 | Administrator step-up (recent re-authentication) for high-risk actions (refund approval, payouts, role / status changes, exports) | DONE (Module 41) — `step-up` middleware on 17 high-risk administrator routes; a sign-in younger than 15 min or `POST /auth/admin/step-up` (password + MFA code) on the same session within 15 min (`config/auth_security.php` `step_up`, PENDING BUSINESS APPROVAL of the windows); Platform Admin dialog repeats the action once; `tests/Feature/Auth/AdminStepUpTest.php`, `src/qa/module41.test.tsx` |
| B-2 | Legal documents (terms, privacy policy, refund, cookie) are DRAFT (`config/legal.php`, `customer-web/src/data/legal.ts`); analytics consent screen not built (an opt-out flag exists) | OPEN — BLOCKER, PENDING LEGAL REVIEW |
| B-3 | Android release signing | PARTIAL (Module 41) — `build.gradle.kts` signs release with the upload key from `android/key.properties` (git-ignored) or `FOTG_UPLOAD_*` CI variables, and a production release without it stops the build (`m41-android-release-signing-guard.txt`); the upload keystore and Play App Signing are the owner's — OPEN — BLOCKER until then (CF-548) |
| B-4 | Real provider verification: Razorpay (payments, refunds, webhooks), FCM, e-mail, SMS | OPEN — BLOCKER, pending credentials |
| B-5 | Malware scanning before support attachments are enabled (attachments are off by code constant) | OPEN — BLOCKER for attachments only |
| B-6 | Web release build with every `VITE_*_MODE=api` (SEC-44) | DONE (Module 41) — the release gate `customer-web/src/security/releaseBuild.ts` stops `vite build` unless `VITE_ENVIRONMENT` is declared and, for staging / production, every switch is `api`, https / wss, no dev OTP (`releaseBuild.test.ts`) |
| B-7 | Retention periods and the erasure policy approved; audit / security trail retention decided | OPEN — PENDING BUSINESS APPROVAL / LEGAL REVIEW |
| B-8 | Every row of section 5 (infrastructure) | DEPLOYMENT |

## 3. Web clients (Customer Web, Restaurant Dashboard, Platform Admin)

| # | Item | Status | Evidence |
|---|---|---|---|
| W-1 | Release build sets all 12 data modes to `api`: `VITE_AUTH_MODE`, `VITE_MARKET_MODE`, `VITE_RESTAURANT_MODE`, `VITE_JOURNEY_MODE`, `VITE_CART_MODE`, `VITE_PICKUP_MODE`, `VITE_CHECKOUT_MODE`, `VITE_PAYMENT_MODE`, `VITE_ORDER_MODE`, `VITE_REVIEW_MODE`, `VITE_REALTIME_MODE`, `VITE_ANALYTICS_MODE` (the last two accept `api` or `off`; `off` only as a deliberate decision) | OPEN (SEC-44; manual today) | variables in `customer-web/src` |
| W-2 | `VITE_API_BASE_URL` https; `VITE_REVERB_SCHEME` https | DEPLOYMENT | `api/client.ts:15` default is `http://127.0.0.1:8001` |
| W-3 | Content-Security-Policy (meta) in production builds: no `unsafe-inline`, no `unsafe-eval`; Razorpay and Google Fonts hosts allowed | DONE | `vite.config.ts` plugin `fotg-csp`; `contentSecurityPolicy.ts`; 0 violations in an all-mock build walk (client agent report). Razorpay checkout under the CSP NOT VERIFIED |
| W-4 | `frame-ancestors`, HSTS, `Permissions-Policy`, COOP (`same-origin-allow-popups` for payment) as web-server headers — a meta CSP cannot carry them | DEPLOYMENT | `contentSecurityPolicy.ts:22` |
| W-5 | Development OTP never shown and `VITE_DEV_OTP` emptied in builds | DONE | `auth/devOtp.ts`; plugin `fotg-no-dev-otp` |
| W-6 | localStorage mode overrides honoured only in development builds | DONE | `config/modeOverride.ts` |
| W-7 | Sign-out / 401 / expiry clears journey, checkout, payment, pickup, discovery data and the checkout note (`fotg.cart.note`) | DONE | `auth/privateContext.ts` (`privateContext.test.tsx`) |
| W-8 | Bearer tokens in `sessionStorage` (exposed to any XSS) — accepted with the CSP; alternatives (cookie SPA auth, BFF) not chosen | OPEN (decision recorded, refs.md §13 item 1) | `api/client.ts:52-53` |
| W-9 | Separate origins for Platform Admin and Restaurant Dashboard (one origin serves all three today) | DEPLOYMENT decision (Module 42) | |
| W-10 | `npm audit`: 0 vulnerabilities | DONE (2026-10-14) | M39 `npm_audit_after_fix.json` |
| W-11 | Self-host fonts (third-party CSS from Google today) | OPEN (low) | `audit_clients.md` C-09 |

## 4. Android

| # | Item | Status | Evidence |
|---|---|---|---|
| A-1 | Release signing key | OPEN — BLOCKER | `build.gradle.kts:50-55` |
| A-2 | Production flavor forces production config; requires https API URL and all 10 modes `api`, else shows "not configured" | DONE | `app_config.dart:18-49`, `main.dart:70-74`, `build_environment_test.dart` (8) |
| A-3 | Dev OTP only in local builds; do not pass `DEV_OTP` to release builds | DONE / DEPLOYMENT | `app_config.dart:144-146` |
| A-4 | Backup off; data extraction rules exclude everything | DONE (not verified on a device) | manifest, `data_extraction_rules.xml` |
| A-5 | Cleartext off outside the local flavor | DONE | manifest |
| A-6 | Production check also refuses `REVERB_SCHEME` not https/wss, `PUSH_MODE` not fcm, a non-empty `DEV_OTP` (and BUG-321 `wss`→`ws://` fixed) | DONE | `build_environment_test.dart`; `mobile-security.md` M-5 |
| A-7 | Certificate pinning, obfuscation, root / tamper detection | OPEN (decisions) | `mobile-security.md` |
| A-8 | `FLAG_SECURE` | NOT ADDED — product decision | |
| A-9 | Secure-storage keys cleared on sign-out / 401 / expiry (beyond the token) | DONE | `private_context_test.dart` (11); `mobile-security.md` M-6 |

## 5. Infrastructure and operations

| # | Item | Status |
|---|---|---|
| D-1 | **Least-privilege PostgreSQL role**: the runtime user must not own the tables (today it owns 137 of 138, so it could disable the append-only and ledger triggers); a separate migration / owner role; `REVOKE UPDATE, DELETE, TRUNCATE` on audit, security and ledger tables for the runtime role | DEPLOYMENT decision |
| D-2 | TLS everywhere: HTTPS on the API, web and Reverb (wss); TLS 1.2+; database `DB_SSLMODE=verify-full`; Redis TLS + password | DEPLOYMENT |
| D-3 | HSTS (preload only after every subdomain is HTTPS, refs.md §8) | DEPLOYMENT |
| D-4 | WAF / DDoS protection; edge rate limits; Razorpay webhook IP allow-list | DEPLOYMENT |
| D-5 | `TRUSTED_PROXIES` set to the load balancer only; `TRUSTED_HOSTS` | DEPLOYMENT |
| D-6 | Secret manager for every secret in `.env.production.example`; nothing in files in the repository | DEPLOYMENT |
| D-7 | Backups: encrypted, restore-tested, retention defined (erased personal data persists in old backups) | DEPLOYMENT |
| D-8 | Monitoring and alerting: security events (ACCOUNT_LOCKED, OTP_BUDGET_EXCEEDED, failed logins), webhook rejections, failed queue jobs, `security:check` result, error rates | DEPLOYMENT |
| D-9 | Logs: the production example now sets `LOG_STACK=structured` (one JSON line per entry, rotated daily, `LOG_DAILY_DAYS=14` PENDING BUSINESS APPROVAL); shipping to an append-only store | DONE in the template / DEPLOYMENT |
| D-10 | Scheduler (`schedule:run`), queue workers (`payments`, `default`, …) and Reverb as supervised services | DEPLOYMENT |
| D-11 | `composer install --no-dev`; `APP_DEBUG=false`; `php artisan security:check` exit 0 as a deploy gate | DEPLOYMENT |
| D-12 | Never run `storage:link`; serve media only through `/api/v1/media` | DEPLOYMENT |
| D-13 | Dynamic scan (e.g. OWASP ZAP) against staging — none was run in Module 39 (no scanner installed; dynamic testing was the automated route walks, IDOR matrix and hostile-input suites) | OPEN |
| D-14 | Load test of rate limits and webhook latency | OPEN |
| D-15 | Incident response contacts and on-call (`incident-response.md`) | OPEN — TO BE ASSIGNED |
| D-16 | `composer audit`: no advisories (2026-10-13) — repeat at release | DONE locally / DEPLOYMENT |

## 6. Done in code (Module 39), for reference

Each of these has a negative test in `backend/tests/Feature/Security/`:

| Control | Test |
|---|---|
| Per-account lockout (password, second factor) | `AuthHardeningTest` (`:38`, `:68`) |
| Limiter keys name the principal type; OTP global and per-number budgets; reset per e-mail | `AuthHardeningTest` (`:127`, `:157`, `:182`) |
| Security headers on every API answer; no server detail on 500 | `HardeningConfigTest::test_every_kind_of_api_answer_carries_the_security_headers_and_no_server_detail` |
| Malformed / array input refused, never a crash (BUG-319) | `HardeningConfigTest::test_malformed_and_wrongly_typed_input_is_refused_never_a_crash` |
| Log redaction of tokens, SQL values, phones, e-mails, one-time links | `HardeningConfigTest::test_free_text_in_logs_loses_tokens_sql_values_phone_numbers_e_mails_and_one_time_links` |
| Idempotency replies encrypted; audit / security trails append-only (not on the local development database — BUG-323); push binding per session; restaurant contact masking; RESTRICTED blocks checkout and payment; erasure; upload re-encoding; keyed pickup code hash; webhook body dedupe; menu cache per host | `DataProtectionTest` (10 tests) |
| No route without a token; no cross-principal access; staff without permissions get nothing | `PrincipalBoundaryTest` |
| Customer A reaches nothing of customer B | `CustomerIdorMatrixTest` |
| A restaurant owner reaches nothing of another organization; location-limited staff nothing of another location; a market-scoped administrator nothing of another market (with positive controls) | `TenantIdorMatrixTest` |
| Oversized or deeply nested request bodies refused (413) | `HardeningConfigTest::test_an_oversized_request_body_is_refused_before_it_is_read_and_uploads_keep_their_own_limit` |
| Second owner, order event stream permission, commission report scope, permission cache after commit | `StaffAuthorizationHardeningTest`; superior-admin rule in `tests/Feature/Auth/AdminUsersApiTest.php` |
| Hostile query strings, stored script / SQL text, media traversal | `InjectionTest` |
| Route inventory and class rules (422 entries, `docs/security/route-inventory.json`) | `RouteInventoryTest` |

---

**PRODUCTION = NO.**
