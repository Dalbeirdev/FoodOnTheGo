# FoodOnTheGo backend — foundation (Module 20) and identity (Module 21)

Laravel API in `/backend`. This document records what exists, how to run it locally, and the conventions
every later backend module must follow. Commands below were run on the development PC on 2026-09-30;
their output is in `docs/local-review/test-results/backend-m20-*.txt`.

## 1. Actual versions

| Component | Version | Note |
|---|---|---|
| PHP | 8.3.33 | |
| Laravel | 13.33.0 | The Module 20 brief names Laravel 12; the project was created on 13 on 2026-09-25 and was not downgraded (owner to confirm) |
| PostgreSQL | 18.6 | The brief names 16; 18.6 was installed under the owner's 2026-09-25 database decision and was not downgraded (owner to confirm) |
| PostGIS | 3.6.2 | |
| Redis | 5.0.14.1 | Windows development build; production needs a current Redis / Valkey |
| Composer | 2.10.3 | |
| Node | 24.14.0 | front-end tooling only |

No Docker: the local environment is native Windows installs (portable PostgreSQL and Redis under `C:\dev`).

## 2. Run it locally

```powershell
# once per machine: test database + PostGIS in both databases (needs the local superuser file C:\dev\pg-local-credentials.txt)
pwsh -File scripts/local/setup-database.ps1

# every session: Redis, PostgreSQL, Laravel on :8001
pwsh -File scripts/local/start-backend.ps1

# in /backend (php = the PHP 8.3 binary)
php artisan migrate            # schema
php artisan db:seed            # India market (all environments) + local fixtures (local / testing only)
php artisan foundation:verify  # PostgreSQL, PostGIS, Redis, cache, queue, market seed against the current environment
php artisan queue:work redis   # worker, only needed when jobs are dispatched
php artisan test               # 159 tests, real PostgreSQL + PostGIS + Redis
php artisan admin:create you@company.example "Your Name" --role=SUPER_ADMIN   # bootstrap an administrator (hidden password prompt)
vendor/bin/pint                # formatter

pwsh -File scripts/local/verify-local.ps1   # ports, readiness, market API, no production references
```

Environments: `local` (current), `testing` (phpunit.xml), `staging`, `production`. `.env` is git-ignored;
`.env.example` lists every configuration group with empty secrets. Code reads `config/*`, never `env()`
(enforced by a test).

## 3. Structure

```
routes/api.php                      everything under /api/v1
app/Http/Controllers/Api            thin: authorize, validate (FormRequest), call a service, return a Resource
app/Http/Requests, Resources        validation; response mapping (models are never returned raw)
app/Http/Middleware                 AssignRequestId, ApiSecurityHeaders, EnsureAccountActive, EnforceIdempotency
app/Http/Support/ListQuery          pagination / filter / sort convention
app/Exceptions                      ApiException + ApiExceptionRenderer (the error envelope)
app/Auth                            Principal, AccessControl, Scope (permission decisions)
app/Enums                           PrincipalType, Permission, MarketStatus, DistanceUnit
app/Services                        application logic (Auth\*: OTP, customer login, staff login, MFA, password reset, account status,
                                    tokens, security events; Rbac\RoleService; Market\MarketContext; Foundation; Sms)
app/Contracts                       provider contracts (Sms\SmsProvider)
app/Support                         Money, Distance, Geo\Geo, Logging\*
app/Models (+ Concerns)             HasPublicId, StoresUtcTimestamps
openapi/openapi.json                API contract, checked by OpenApiContractTest
```

Layers: HTTP → services → models / providers. Repositories are added only where they are a real boundary.
Eloquent lazy loading throws outside production, so N+1 queries fail in development and tests.

## 4. API conventions

- Base `/api/v1`. JSON is snake_case. A single resource is returned unwrapped; a collection is `{data, links, meta}`.
- Errors always: `{"error": {"code", "message", "details"?, "request_id"}}`. Codes: `validation_failed` (422,
  `details.fields`), `unauthenticated` (401), `forbidden` (403), `not_found` (404), `method_not_allowed` (405),
  `conflict` (409), `rate_limited` (429, `details.retry_after_seconds`), `internal_error` (500), plus domain
  codes such as `market_unavailable`. SQL, paths and traces never leave the server; with `APP_DEBUG=true` a
  separate `debug` key is added to 5xx responses.
- Status codes: 200, 201, 204, 400, 401, 403, 404, 409, 422, 429, 500 — never 200 for a failure.
- Collections: `?filter[field]=value&sort=name,-created_at&page[number]=2&page[size]=25`, allow-listed per
  endpoint; unknown filters or sorts are a 422. Cursor pagination (`page[cursor]`) is reserved for feeds.
- Every response has `X-Request-Id` (a well-formed client value is kept). It is in error bodies, in every log
  line and in queued jobs (Laravel Context).
- Money: `{"amount": 24900, "currency": "INR"}` — integer minor units + ISO 4217. Countries ISO 3166-1
  alpha-2, time zones IANA ids, timestamps ISO 8601 UTC, distances in metres.
- Identifiers: bigint primary keys stay internal; the API exposes the UUID `public_id` as `id`.
- Documentation: `openapi/openapi.json`. A test fails when a route is undocumented or a response does not
  match its schema.

Endpoints today (25, all in `openapi/openapi.json`): `GET /health`, `GET /ready`, `GET /markets/current`,
`GET /config`, `GET /admin/markets`; customer `POST /auth/customer/otp/request`, `POST /auth/customer/otp/verify`,
`PATCH /auth/customer/profile`; restaurant and admin `POST /auth/{restaurant|admin}/login`, `/mfa/verify`,
`/password/forgot`, `/password/reset`; any signed-in principal `GET /auth/me`, `POST /auth/logout`,
`POST /auth/logout-all`, `GET /auth/sessions`, `DELETE /auth/sessions/{id}`; restaurant / admin
`POST /auth/password`, `POST /auth/mfa/totp/setup`, `POST /auth/mfa/totp/confirm`, `DELETE /auth/mfa/totp`.

## 5. Database conventions

- PostgreSQL + PostGIS only. Tests use the dedicated `foodonthego_test` database — never SQLite.
- UTF-8. Sessions run in UTC (`config/database.php`); models use `StoresUtcTimestamps`; new tables use
  `timestampTz`. A restaurant's operating time zone is stored as its own IANA column.
- Every schema change is a migration with a working `down()`. The PostGIS extension is created by a
  superuser at environment setup; the migration verifies it and never drops it.
- snake_case plural tables, `<table>_<columns>_<unique|index|check>` constraint names, real foreign keys,
  unique constraints for public ids / codes / idempotency keys, check constraints for closed value sets.
- Soft deletes are decided per domain. Orders, payments and audit records are retained, not deleted.
- Indexes are deliberate: foreign keys, statuses, public ids, market scope, timestamps used for ranges,
  GiST for every spatial column.
- Seeders: `MarketSeeder` is reference data (all environments). `LocalFixtureSeeder` runs only in local /
  testing. Tests use factories.
- Spatial: WGS84 / SRID 4326. Searchable points are `geography(Point, 4326)` (distances in metres, GiST);
  lines and areas are `geometry(<Type>, 4326)`. PostGIS shortlists; the routing provider computes detours.

## 6. Identity, authentication and authorization (Module 21)

### Identity model

Three separate tables, models and token guards — a token always belongs to exactly one of them, and the client
never says which it is:

| Principal | Table | Signs in with | Guard | States |
|---|---|---|---|---|
| Customer | `customers` | phone + one-time code (no password) | `auth:customer` | ACTIVE, RESTRICTED (may sign in; later modules limit actions), SUSPENDED, DEACTIVATED (cannot sign in) |
| Restaurant user | `restaurant_users` | e-mail + password, optional TOTP | `auth:restaurant` | INVITED (no password yet), ACTIVE, SUSPENDED, DISABLED |
| Admin user | `admin_users` | e-mail + password, optional / mandatory TOTP | `auth:admin` | same as restaurant user |

The prototype `users` table is gone. Status, phone, verification, e-mail and password are never mass-assignable.
There is no public way to create a restaurant or admin account: admins come from `php artisan admin:create`
(hidden password prompt), restaurant staff from the restaurant module later.

### Tokens and sessions

- **Decision: opaque bearer tokens (Laravel Sanctum) for every client — web, Android and both dashboards.**
  Cookie / session authentication is not used (`sanctum.guard = []`), so there is no CSRF surface; a test proves a
  cookie never authenticates. Reason: the three portals share one web origin and need three independent
  sessions, Android needs tokens anyway, and one mechanism is listed, revoked and tested once.
  The trade-off is that a web token is readable by script: it is kept in `sessionStorage` (gone when the tab
  closes, never on disk), lives 8 h (admin) / 12 h (restaurant) / 30 days (customer), is revocable, and the web
  app renders no raw HTML. Moving the web portals to HttpOnly cookies is a recorded pre-production decision.
- One token = one device. Stored as SHA-256, with its own `expires_at`, device label, IP and user agent.
  `GET /auth/sessions`, `DELETE /auth/sessions/{id}`, `POST /auth/logout`, `POST /auth/logout-all`.
- Android keeps the token in `flutter_secure_storage` (Keystore-backed), never in plain preferences.
- `active` middleware re-reads the account state on every request: a suspended / disabled account is refused
  even if a token survived. `AccountStatusService` also revokes all tokens when it suspends.
- Password change signs out every other device; password reset signs out all devices.

### Customer phone + OTP

- `PhoneNumber::parse($input, $market)` normalises to E.164. The dialling code, the national rule and the trunk
  prefix are columns of the market row (India: `+91`, `^[6-9][0-9]{9}$`, `0`) — nothing is hardcoded.
- A challenge (`otp_challenges`, PostgreSQL) holds only `HMAC-SHA256(challenge id | code)` keyed with the
  application key. It expires (`OTP_TTL_SECONDS`), allows `OTP_MAX_ATTEMPTS` wrong codes, and is verified under a
  row lock, so two simultaneous requests cannot both succeed. PostgreSQL rather than Redis because verification
  must be atomic, single-use and auditable; Redis supplies the rate limiters.
- A new request invalidates the previous code. Resend cooldown and an hourly cap per phone are enforced by the
  server; the client countdown is decoration. Rate limits apply per phone and per address.
- The response is the same for a new and an existing customer and never contains the code.
- First verification creates the customer (`INSERT … ON CONFLICT DO NOTHING` + unique `phone_e164`).
- Local / testing: `OTP_DEV_CODE` is the code; `SMS_DRIVER=log` writes a masked line instead of sending.
  `DevelopmentOtp` refuses staging and production whatever the configuration.

### Restaurant / admin sign-in

- One generic failure (`invalid_credentials`) for unknown e-mail and wrong password, with the same work done in
  both cases; the account state is disclosed only to someone who proved the password.
- MFA (TOTP, RFC 6238, verified against the RFC vectors): secret encrypted at rest and shown once, a code works
  once, 8 single-use recovery codes stored hashed, disabling needs password + code. With
  `AUTH_MFA_REQUIRED_ADMIN=true` an admin without MFA receives a token that can only enrol.
- Password policy: minimum length (`AUTH_PASSWORD_MIN_LENGTH`, 12), no composition rules, framework hashing.
  Reset tokens: random 256 bit, stored as SHA-256, 30 minutes, single use, same answer whether or not the
  account exists. E-mail delivery is the `log` mailer locally — no mail provider is integrated yet.

### Authorization

- Customers: ownership. `Gate::authorize('own', $record)` — the record must carry the caller's `customer_id`.
- Restaurant and admin users: roles → permissions → scoped assignments (`roles`, `role_permissions`,
  `role_assignments`). Code checks permissions (`can:admin.refunds.issue`,
  `Gate::authorize('restaurant.orders.view', Scope::location($id, $organizationId))`), never role names.
- A restaurant assignment is always scoped to an organization or a location (also a database constraint); a
  location check is satisfied by that location or by the organization that owns it. The organization is looked
  up by the server — scope sent by the client is ignored.
- An admin assignment is platform-wide or limited to a market. SUPER_ADMIN is a role like any other.
- Wrong principal type on a route = 401 (that token is not an authentication there). Authenticated but not
  permitted = 403. Another account's session id = 404 (existence is not revealed).
- Effective permissions are cached per principal under a global version; every role / permission / assignment
  change bumps the version, so nothing stale can be read.

### Events, logs, limits

- `security_events` (append-only): OTP requested / failed / verified, login success / failure, logout, session
  revoked, password reset / changed, account status changed, permission changed, MFA enabled / disabled / failed.
  Unknown identifiers are stored as a hash; metadata passes through the log redactor.
- Audit hooks (Laravel events) for the later audit module: `AccountStatusChanged`, `RoleAssignmentChanged`,
  `MfaChanged`.
- Stack traces never contain call arguments (`zend.exception_ignore_args` forced on + `#[SensitiveParameter]`),
  and expected client errors are not written to the error log.
- Rate limits (`config/rate_limits.php`, development defaults): `otp-request`, `otp-verify`, `staff-login`,
  `admin-login` (stricter), `mfa-verify`, `password-reset`, plus `api`, `search`, `payment`, `admin-sensitive`.
- Idempotency: `idempotent:<operation>` middleware + `Idempotency-Key` header, scoped to actor + operation,
  bound to the request hash, expiring (`API_IDEMPOTENCY_TTL_MINUTES`).
- CORS: origins from `FRONTEND_URLS`, explicit methods and headers, no wildcard.
- Logs: text locally, JSON lines with `LOG_STACK=structured`; sensitive keys and bearer tokens are redacted.
- Provider secrets live only in the backend `.env` / `config/services.php`.

### Local fixture accounts

`php artisan db:seed` (local / testing only) creates test accounts on reserved `.example` domains:

| Context | Accounts |
|---|---|
| Customer (any number works; code = `OTP_DEV_CODE`) | 98765 43210 existing customer, 98765 00001 suspended |
| Restaurant | john@ (owner), sarah@ (manager), mike@ (order staff, one location), emily@ (menu manager), yuki@ (invited), suspended@ — all `@riverside.example`; owner@second-kitchen.example (a second organization) |
| Admin | alex.morgan@ (super admin), nina.patel@ (operations, India scope), tom.okafor@ (onboarding), lea.dubois@ (support), kenji.watanabe@ (finance), mia.fernandes@ (moderation), ravi.menon@ (invited), sam.reyes@ (suspended) — all `@foodonthego.example` |

Their password is the value of `LOCAL_FIXTURE_PASSWORD` in the git-ignored `backend/.env` (nothing is hardcoded;
without it no password account is created). Seeding again resets the fixtures to this state.

## 7. Concurrency guide (for later modules)

| Situation | Mechanism |
|---|---|
| Multi-table change that must be all-or-nothing (order creation, refund) | database transaction |
| Two actors changing the same row (order status, pickup verification) | `lockForUpdate` inside the transaction |
| Stale edits from a form (menu, settings, market configuration) | optimistic version column |
| Short exclusive section across servers (pickup capacity, payment reconciliation) | Redis lock |
| Client or provider retries (checkout, payment creation, webhooks, refunds, order acceptance) | idempotency key |

Redis holds cache, queues, rate limits, locks and temporary state. It never holds the only copy of an order
or payment.

## 8. Front-end integration

- One HTTP client per platform: `customer-web/src/api/client.ts` (`VITE_API_BASE_URL`) and
  `mobile/lib/data/api_client.dart` (`--dart-define=API_BASE_URL`). Both send `X-Request-Id`, understand the
  error envelope and classify 401 / 403 / 404 / 409 / 422 / 429 / 5xx / network the same way.
- Authentication is the first domain on the real backend (Module 21). `VITE_AUTH_MODE=api` /
  `--dart-define=AUTH_MODE=api` selects `ApiAuthRepository`; unit tests and the static share builds always use
  the mock. The Restaurant Dashboard and Platform Admin sit behind `StaffAuthGate` (sign-in screen, identity,
  roles and permissions from the backend; their operational data is still mock).
- 401 ends the session once and returns to sign-in with the return route; 403 shows access denied and keeps the
  session; 429 shows how long to wait.
- Mock → API: each `Mock*Repository` keeps its interface; an `Api*` adapter maps the snake_case DTO onto the
  existing domain type and is swapped in where the repository is constructed, one domain per backend module.
  `customer-web/src/api/marketApi.ts` is the first adapter (tested, not wired in). Mocks stay for tests.

## 9. Not in this module

Restaurants, menus, journeys, discovery, cart, pickup, checkout, payments, orders, tracking, reviews and the
dashboard APIs; live SMS, Maps / Places / Routes, Razorpay, FCM, WebSockets; states, cities, service areas and
corridors; production deployment, backups and monitoring. These are tracked in `docs/project-progress.html`.
