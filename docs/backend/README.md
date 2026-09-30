# FoodOnTheGo backend — foundation (Module 20)

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
php artisan test               # 90 tests, real PostgreSQL + PostGIS + Redis
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
app/Http/Middleware                 AssignRequestId, ApiSecurityHeaders, EnsurePrincipalType, EnforceIdempotency
app/Http/Support/ListQuery          pagination / filter / sort convention
app/Exceptions                      ApiException + ApiExceptionRenderer (the error envelope)
app/Auth                            AccessControl, Scope (permission decisions)
app/Enums                           PrincipalType, Permission, MarketStatus, DistanceUnit
app/Services                        application logic (Market\MarketContext, Foundation\DependencyChecks, Sms, Auth)
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

Endpoints today: `GET /health` (public, minimal), `GET /ready` (per dependency; public only in local /
testing, otherwise `admin.system.view`), `GET /markets/current`, `GET /config`, `GET /admin/markets`
(`admin.markets.view`), and the interim password endpoints under `/auth/*` from the Module 03 prototype.

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

## 6. Security foundation

- Three principal types — `CUSTOMER`, `RESTAURANT_USER`, `ADMIN_USER` (`users.principal_type`, check
  constraint, not mass-assignable). `principal:<TYPE>` guards a route group.
- Permissions are the `App\Enums\Permission` catalogue, stored in `permission_grants` with an optional
  scope (organization / location / market). Checks go through the Gate: `can:admin.markets.view`,
  `$user->can('restaurant.orders.view', Scope::location($id))`. Restaurant permissions are always scoped;
  an admin has nothing without a grant.
- Tokens: Laravel Sanctum opaque bearer tokens for web, Android and both dashboards (no cookies, so no
  CSRF surface). Stored hashed, revocable per device or all at once, expiry via
  `SANCTUM_TOKEN_EXPIRATION_MINUTES`. Android must keep the token in secure storage.
- OTP: policy in `config/otp.php`, SMS behind `SmsProvider` (`log` driver today). The development code works
  only in local / testing; `DevelopmentOtp` refuses staging and production whatever the configuration.
- Rate limits: named limiters (`api`, `auth`, `password-reset`, `otp`, `search`, `payment`,
  `admin-sensitive`) with values in `config/rate_limits.php` — development defaults, not production numbers.
- Idempotency: `idempotent:<operation>` middleware + `Idempotency-Key` header, scoped to actor + operation,
  bound to the request hash, expiring (`API_IDEMPOTENCY_TTL_MINUTES`).
- CORS: origins from `FRONTEND_URLS`, explicit methods and headers, no wildcard.
- Logs: text locally, JSON lines with `LOG_STACK=structured`; sensitive keys and bearer tokens are redacted.
- Provider secrets live only in the backend `.env` / `config/services.php`.

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
- Mock → API: each `Mock*Repository` keeps its interface; an `Api*` adapter maps the snake_case DTO onto the
  existing domain type and is swapped in where the repository is constructed, one domain per backend module.
  `customer-web/src/api/marketApi.ts` is the first adapter (tested, not wired in). Mocks stay for tests.

## 9. Not in this module

Restaurants, menus, journeys, discovery, cart, pickup, checkout, payments, orders, tracking, reviews and the
dashboard APIs; live SMS, Maps / Places / Routes, Razorpay, FCM, WebSockets; states, cities, service areas and
corridors; production deployment, backups and monitoring. These are tracked in `docs/project-progress.html`.
