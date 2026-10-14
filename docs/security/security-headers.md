# Security headers, CORS, trusted hosts and proxies

Status: Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/app/Http/Middleware/ApiSecurityHeaders.php`, `backend/bootstrap/app.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/config/{cors,security,session,sanctum,reverb,filesystems,api}.php`, `backend/app/Http/Controllers/Api/{MediaController,HealthController}.php`, `backend/app/Http/Controllers/Api/Customer/PickupCredentialController.php`, `backend/app/Support/Csv.php`, `backend/app/Services/Analytics/Reports/ReportExportService.php`, `backend/app/Console/Commands/SecurityCheck.php`, `backend/.env.production.example`, `customer-web/src/security/contentSecurityPolicy.ts`, `customer-web/vite.config.ts`, `backend/tests/Feature/Security/HardeningConfigTest.php`, the Module 39 client report.

Header values below are the security posture this module requires. The production web server configuration does not exist yet (Module 42). Every value is **PENDING SECURITY / BUSINESS APPROVAL**.

## 1. API response headers (Laravel)

`ApiSecurityHeaders` is prepended to the global middleware, so error answers produced before routing carry the headers too (`bootstrap/app.php:34`).

| Header | Value | Where set | Notes |
|---|---|---|---|
| `X-Content-Type-Options` | `nosniff` | `ApiSecurityHeaders.php:19` | Every answer |
| `X-Frame-Options` | `DENY` | `ApiSecurityHeaders.php:20` | Every answer |
| `Referrer-Policy` | `no-referrer` | `ApiSecurityHeaders.php:21` | Every answer |
| `Cache-Control` | `no-store, private` | `ApiSecurityHeaders.php:22-24` | Set when the answer has none, or has Laravel's default `no-cache, private` |
| `Cache-Control` (media) | `public, max-age=86400, immutable` | `MediaController.php:30` | **Exception:** public menu images and avatars under `/api/v1/media/{path}` are cacheable for a day |
| `Cache-Control` (pickup credential) | `private, no-store, max-age=0` + `Pragma: no-cache` | `PickupCredentialController.php:63` | |
| `Cache-Control` (CSV exports) | `no-store, private` | `Csv.php:55`, `ReportExportService.php:145` | |
| `X-Request-Id` | per request | `AssignRequestId` (`bootstrap/app.php:34`) | Exposed through CORS |

Error answers: with `APP_DEBUG` off a 500 carries a generic message, an error code and the request id; no exception text, trace, SQL or file path (`bootstrap/app.php:48-64`; `ApiExceptionRenderer`). Trace arguments are disabled (`bootstrap/app.php:20`).

Not sent by the application (must come from the edge — see §5): `Strict-Transport-Security`, `Content-Security-Policy` for API answers, `Permissions-Policy`, `Cross-Origin-*` policies.

## 2. CORS (`config/cors.php`)

| Setting | Value | Line |
|---|---|---|
| `paths` | `api/*` only | 19 |
| `allowed_methods` | GET, POST, PUT, PATCH, DELETE, OPTIONS | 21 |
| `allowed_origins` | exact list from `FRONTEND_URLS` (comma-separated); no default | 23 |
| `allowed_origins_patterns` | none | 25 |
| `allowed_headers` | Accept, Authorization, Content-Type, Idempotency-Key, X-Request-Id, X-Requested-With, X-Journey-Token, X-Cart-Token | 28 |
| `exposed_headers` | X-Request-Id, Retry-After, Idempotency-Replayed, Content-Disposition | 31 |
| `max_age` | 600 | 33 |
| `supports_credentials` | false | 35 |

Why no credentials and no CSRF token: the API is bearer-only. Sanctum consults no session guard (`config/sanctum.php:40` `'guard' => []`), its CSRF-cookie route is off (`config/sanctum.php:71` `'routes' => false`), and Laravel's default `/broadcasting/auth` web route is not registered (`bootstrap/app.php:27-30`; channels are authorized through `/api/v1/customer/broadcasting/auth` and `/api/v1/restaurant/broadcasting/auth`). No cookie authenticates an API call, so cross-site request forgery does not apply to the API.

Reverb WebSocket origins are a separate list: `REVERB_ALLOWED_ORIGINS` (`config/reverb.php:87`), default `localhost,127.0.0.1`; client events are refused by default (`:93`). **Gap:** `.env.production.example` does not set `REVERB_ALLOWED_ORIGINS`, and `security:check` does not check it. Production must set it.

## 3. Trusted hosts and proxies (`config/security.php`)

| Item | Behaviour | Code |
|---|---|---|
| Trusted hosts | Anchored patterns `^host$` built from the host of `APP_URL` plus `TRUSTED_HOSTS`; no subdomains | `config/security.php:22`; `bootstrap/app.php:39` |
| When enforced | Laravel's `TrustHosts` skips the check in the `local` environment and in unit tests; it applies everywhere else | `vendor/.../Http/Middleware/TrustHosts.php:99-103` |
| Why | Links built from the request (media URLs in cached menu documents) cannot be pointed at another host; menu documents are also cached per host | `DataProtectionTest::test_cached_menu_documents_are_kept_per_host` |
| Trusted proxies | Only addresses / CIDR ranges in `TRUSTED_PROXIES` may set `X-Forwarded-*`; empty = none; `*` only behind a proxy that overwrites the headers | `config/security.php:18`; `AppServiceProvider.php:106-109` |
| Why | IP-keyed rate limits (OTP, login, webhooks, media) use the real client address and cannot be chosen by the client | `config/security.php:16-17` |

## 4. Web Content-Security-Policy (customer-web)

One production build serves Customer Web, Restaurant Dashboard (`src/dashboard`) and Platform Admin (`src/admin`) from one origin. Separate origins are a deployment decision (Module 42).

The `fotg-csp` Vite plugin writes the policy into `index.html` as `<meta http-equiv="Content-Security-Policy">` right after the charset, for `vite build` only (`vite.config.ts:21-38`). The build fails if `index.html` has no `<meta charset="UTF-8" />` (`:33`). The dev server and the mock-only share build (`VITE_SHARE_BUILD=1`) carry no policy.

Policy (`contentSecurityPolicy.ts:63-79`):

| Directive | Sources | Why |
|---|---|---|
| `default-src` | `'self'` | |
| `script-src` | `'self'`, `https://checkout.razorpay.com` | App bundle and the Razorpay checkout script. No `'unsafe-inline'`, no `'unsafe-eval'` |
| `style-src` | `'self'`, `https://fonts.googleapis.com` | No `'unsafe-inline'` |
| `font-src` | `'self'`, `https://fonts.gstatic.com` | Google Fonts until self-hosted |
| `img-src` | `'self'`, `data:`, `blob:`, API origin | MFA QR code is a data URL; media from the API |
| `connect-src` | `'self'`, API origin, Reverb `ws(s)://host:port` when realtime is on, `https://api.razorpay.com` | |
| `frame-src` | `https://api.razorpay.com`, `https://checkout.razorpay.com` | Razorpay checkout window |
| `object-src` | `'none'` | |
| `base-uri` | `'self'` | |
| `form-action` | `'self'` | |

Build-time guards: `VITE_API_BASE_URL` must be http(s) (`:43-49`); `VITE_REVERB_HOST` must be a plain host name or IP, so nothing can be injected into the policy (`:56-57`). Default API origin when unset: `http://127.0.0.1:8001` (`:41`) — a release build must set `VITE_API_BASE_URL`.

Other build guards: `fotg-no-dev-otp` replaces `VITE_DEV_OTP` with an empty string in every build (`vite.config.ts:47`). Bearer tokens are kept in `sessionStorage`, one key per context (`src/api/client.ts:18`, `:51-52`).

## 5. What the production web server / edge must add (Module 42)

A `<meta>` policy cannot carry these. They are not configured anywhere in the repository.

| Header / setting | Required value (proposal, PENDING APPROVAL) | Applies to |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (preload only after a decision) | Web app and API |
| TLS | HTTPS only; HTTP redirects to HTTPS; TLS 1.2+ | Web app, API, Reverb (`wss`) |
| `Content-Security-Policy` header | The same policy as §4 **plus** `frame-ancestors 'none'` (or `'self'`) and a `report-to` / `report-uri` endpoint | Web app |
| `X-Content-Type-Options` | `nosniff` | Web app (the API already sends it) |
| `Referrer-Policy` | `no-referrer` or `strict-origin-when-cross-origin` | Web app |
| `Permissions-Policy` | `camera=(self)` (pickup QR scanner), everything else off | Web app |
| `X-Frame-Options` | `DENY` (legacy browsers) | Web app |
| Cache | `index.html`: `no-store`; hashed assets: long-lived | Web app |
| Host / proxy | Unknown Host headers refused at the edge; the load balancer overwrites `X-Forwarded-*`; its range goes into `TRUSTED_PROXIES` | API |
| WAF / DDoS / body-size limits | Deployment decision (Modules 40–42) | All |

Also required in the backend environment (checked by `php artisan security:check`): `APP_DEBUG=false`, `APP_URL` https, trusted hosts configured, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, CORS without wildcard, credentials or localhost origins, `API_READINESS_PUBLIC=false`, `DB_SSLMODE` require / verify-*, no `/dev` or `_boost` route, no default `/broadcasting/auth` route (`SecurityCheck.php:28-58`). `.env.production.example` sets these values (`:9-39`).

## 6. How each item was verified

| Item | Test / evidence | Result |
|---|---|---|
| Headers on 200, 401, 404, 405, 422 and 500 answers; JSON shape; no exception text, path, SQL, phone number or trace in two different 500 answers | `HardeningConfigTest::test_every_kind_of_api_answer_carries_the_security_headers_and_no_server_detail` | Automated (PHPUnit) |
| CORS: an unknown origin, `null`, a look-alike suffix and an `http://` variant get no `Access-Control-Allow-Origin`; the configured origin is echoed; no `Allow-Credentials`; no cookie header allowed | `HardeningConfigTest::test_cors_answers_only_configured_origins_and_never_allows_credentials` | Automated |
| Bearer-only defaults: private disk not served, `supports_credentials` false, `paths` = `api/*`, Sanctum routes off | `HardeningConfigTest::test_a_deployment_that_sets_nothing_gets_the_safe_defaults` | Automated |
| `security:check` reports locally, fails with `--as-production`, never prints a secret | `HardeningConfigTest::test_security_check_reports_locally_and_fails_when_judged_as_production` | Automated |
| Malformed / wrongly typed input never answers 5xx | `HardeningConfigTest::test_malformed_and_wrongly_typed_input_is_refused_never_a_crash` | Automated |
| Default `/broadcasting/auth` and other dangerous framework routes absent | `RouteInventoryTest::test_the_class_rules_cover_the_whole_api_and_the_dangerous_framework_routes_are_gone` | Automated |
| Trusted hosts: cached menu documents kept per host | `DataProtectionTest::test_cached_menu_documents_are_kept_per_host` | Automated |
| CSP text: exact sources; no inline / eval script, no inline style; origins from build settings; injection refused | `customer-web/src/security/contentSecurityPolicy.test.ts` (4 tests) | Automated (Vitest) |
| Mode switches honoured only in development builds; production ignores `fotg.auth.mode=mock` | `customer-web/src/config/modeOverride.test.ts` (5 tests) | Automated |
| Development OTP never in a production build | `customer-web/src/auth/devOtp.test.tsx` (4 tests) | Automated |
| Sign-out / 401 clears private browser storage | `customer-web/src/auth/privateContext.test.tsx` (4 tests) | Automated |
| CSP in a real browser: inline script, style attribute and `eval` blocked; an all-mock build walked over the 12 views listed in the report (public pages, dashboard, admin) with 0 violations | Module 39 client report (`vite preview`) | Manual, once |
| Whole web suite after the change | Client report: Vitest 81 files / 1015 passed / 0 failed; `tsc -b` exit 0; build OK | Reported by the client agent; not re-run for this document |

## 7. Not verified / open

- Razorpay checkout under this CSP: **not verified** (no credentials). It may need further sources (for example `style-src-attr`) after a live test.
- Trusted proxies behind a real load balancer: not verified (no production topology).
- HSTS, `frame-ancestors`, `Permissions-Policy`: not configured anywhere yet (Module 42).
- `REVERB_ALLOWED_ORIGINS` is now in `.env.production.example` and checked by `security:check` (item 30) — fixed after this review.
- No dynamic scanner (for example OWASP ZAP) was run; none is installed.
- SEC-44: a production web build made with `VITE_AUTH_MODE` unset or `mock` opens the staff screens on mock data. The release build must set every `VITE_*_MODE=api` (release checklist item).
