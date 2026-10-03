# FoodOnTheGo backend — foundation (Module 20), identity (Module 21), market geography (Module 22), restaurants (Module 23), menus (Module 24)

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

# every session: Redis, PostgreSQL, the API on :8001 (Laravel on 127.0.0.1:8002 behind scripts/local/api-proxy.mjs —
# PHP's one-connection development server stalls behind idle browser connections; local only)
pwsh -File scripts/local/start-backend.ps1

# in /backend (php = the PHP 8.3 binary)
php artisan migrate            # schema
php artisan db:seed            # India market (all environments) + local fixtures (local / testing only)
php artisan foundation:verify  # PostgreSQL, PostGIS, Redis, cache, queue, market seed against the current environment
php artisan queue:work redis   # worker, only needed when jobs are dispatched
php artisan test               # 260 tests, real PostgreSQL + PostGIS + Redis
php artisan otp:check          # how one-time codes are delivered here (channels, providers) and whether it is configured
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

Endpoints today (26, all in `openapi/openapi.json`): `GET /health`, `GET /ready`, `GET /markets/current`,
`GET /config`, `GET /admin/markets`; customer `POST /auth/customer/otp/request`, `POST /auth/customer/otp/verify`, `POST /auth/customer/truecaller`,
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
- Local / testing: `SMS_DRIVER=log` sends nothing and `OTP_DEV_CODE` is the code. `DevelopmentOtp` refuses
  staging and production whatever the configuration, and any environment with a real SMS driver.

### How the code reaches the customer (Truecaller → WhatsApp → SMS)

FoodOnTheGo generates, hashes, expires and verifies every code itself. WhatsApp and SMS providers only carry
the message, so they are interchangeable and choosing them is configuration, not code. The order is the
cheapest first — the pattern large Indian consumer apps use:

1. **Truecaller one-tap** (Android, free): no code at all. `POST /auth/customer/truecaller` exchanges the
   authorization code from the Truecaller SDK and takes the phone number from Truecaller's answer, never from
   the app. Enabled by `TRUECALLER_CLIENT_ID`. **The backend is built; the Android SDK screen is not** (it needs
   the owner's Truecaller client id and a device with the Truecaller app to be built and verified).
2. **WhatsApp** (about a third of the SMS price, no DLT): Meta WhatsApp Cloud API, an approved *authentication*
   template with a copy-code button.
3. **SMS** (works on every phone): MSG91, 2Factor or Twilio.

`OTP_CHANNELS=whatsapp,sms` sets the order. The first code goes out on the first channel; **every resend moves
to the next channel** (a customer without WhatsApp simply presses resend and gets an SMS); a client may ask for
a channel (`"channel": "sms"`); if a channel cannot deliver, the next one is tried with the same code. A channel
without credentials is skipped. The response says which channel was used and which one a resend will use, and
the screens show it ("on WhatsApp", "Send the code by SMS instead").

| Setting | What it does | Needs |
|---|---|---|
| `OTP_CHANNELS` | `sms` (default) or `whatsapp,sms` | — |
| WhatsApp | Meta Cloud API authentication template | `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_OTP_TEMPLATE` |
| `SMS_DRIVER=log` | **Sends nothing.** Masked log line. Local / testing only; refused in production | — |
| `SMS_DRIVER=msg91` | MSG91 OTP endpoint with our code; text, sender and DLT id live in the MSG91 template | `SMS_MSG91_AUTH_KEY`, `SMS_MSG91_OTP_TEMPLATE_ID` |
| `SMS_DRIVER=twofactor` | 2Factor.in OTP route with our code and an approved template | `SMS_2FACTOR_API_KEY`, `SMS_2FACTOR_TEMPLATE` |
| `SMS_DRIVER=twilio` | Twilio Programmable Messaging; text from `OTP_SMS_TEMPLATE` | `SMS_TWILIO_ACCOUNT_SID`, `SMS_TWILIO_AUTH_TOKEN`, `SMS_TWILIO_MESSAGING_SERVICE_SID` or `SMS_TWILIO_FROM` |
| `SMS_FALLBACK_DRIVER` | Second SMS provider used when the first cannot deliver | that provider's keys |

- With any real channel the code is always random, also on the development PC; the fixed `OTP_DEV_CODE` exists
  only while nothing real is sent (`SMS_DRIVER=log`, no WhatsApp).
- Providers get a timeout and one retry on a connection failure. When no channel can deliver, the client gets
  `503 otp_delivery_failed`; errors and logs never contain the code, the request URL or a credential.
- India: SMS needs DLT registration (business entity, sender header, the OTP template) before any provider
  will deliver. WhatsApp needs Meta business verification and an approved authentication template instead.
- Going live: provider account(s) → registration / template approval → credentials in the environment →
  `php artisan otp:check` (readiness, prints no secret) → `php artisan otp:test <phone> [--channel=whatsapp|sms]`.
- Everything above is tested against faked provider HTTP. **No real message has been sent yet** — there is no
  provider account.

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

### Administrator accounts

`GET /admin/users`, `GET /admin/roles`, `POST /admin/users` (invite), `PATCH /admin/users/{id}/status`,
`PUT /admin/users/{id}/role`, `POST /admin/users/{id}/invitation` (resend), `POST /admin/users/{id}/mfa/reset` — `admin.users.view` / `.manage`,
`admin.roles.view` / `.manage`, each held platform-wide. `POST /auth/admin/invitation/accept` is public.

- **Invitation**: the account is created INVITED with no password. The invited person gets a single-use link
  (`AUTH_INVITATION_TTL_HOURS`, 72; only its SHA-256 is stored; a new link cancels the old) and chooses their own
  password, which makes the account ACTIVE. The inviting administrator never sets or sees a password. The ordinary
  password-reset flow does not activate an invited account. Locally the mailer is `log`: the message, including
  the link, is written to `storage/logs/laravel.log` and nothing is sent — a mail provider is required before any
  shared environment.
- **Rules no permission overrides** (`AdminUserService`): nobody changes their own status or role; a role can only
  be granted by someone who holds every permission in it platform-wide; the last active administrator who can
  manage accounts and roles cannot be suspended, disabled or given a lesser role; DISABLED is final; a status that
  cannot sign in ends every session at once; a role change is effective on the next request of open sessions.
- **MFA reset** (`POST /admin/users/{id}/mfa/reset`, `admin.users.manage`, reason required): for a colleague who
  lost the authenticator app and the recovery codes. The enrolment is deleted (never returned), every session of
  that account ends, a `MFA_DISABLED` security event (`via: admin_reset`) and an `admin_user.mfa_reset` audit event
  are written. Not on your own account (409), nothing to reset (409 `mfa_not_enabled`). Afterwards the password
  alone signs in — or, with `AUTH_MFA_REQUIRED_ADMIN=true`, the person must enrol again before getting a session.
  The backend cannot check that the request really came from that person: the administrator must confirm it
  outside the system, and the screen says so.
- **MFA change e-mail** (`MfaChangedNotification`, sent by the `NotifyAccountOfMfaChange` listener on `MfaChanged`):
  the owner of a restaurant or admin account is told when MFA is turned on, turned off, or reset by an
  administrator. It is sent after the change is committed; a mail failure is reported and never undoes the
  change. The message has its own plain layout (`resources/views/mail/security-notice*`) with no link, button,
  code, reason or administrator name. Locally `MAIL_MAILER=log`: it is written to `storage/logs/laravel.log`,
  nothing is delivered until a mail provider is configured.
- Every change writes an audit event (`admin_user.invited`, `.activated`, `.status_changed`, `.role_changed`) in
  addition to the security events. The response never contains the password, MFA secret or recovery codes.

### Security events for administrators

`GET /admin/security-events` and `GET /admin/security/summary` (`admin.security.view`, held platform-wide — a
market-scoped grant does not open them; read-only). The list is newest first with `filter[event]`,
`filter[principal_type]`, `?outcome=failed`, `?from=` / `?to=`; an event carries a severity (permission, account
status and MFA-off changes are high; failures and credential changes medium), the account's display name when it is
known, the address, device, request id and the recorded details. The summary counts stored events: failed sign-ins
and wrong codes in 24 hours, permission and account-status changes in 7 days, blocked accounts, MFA coverage of
active administrators, and addresses with 5 or more failures in the last 60 minutes.

Never returned: the identifier hash, internal ids, a phone number, an e-mail, a code, a password, a token (asserted
by a test). Locally every address is 127.0.0.1 because requests pass the local API proxy; a live server behind a
load balancer needs its trusted-proxy configuration first.

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

## 8. Markets, geography and availability (Module 22)

India is the only market that serves customers. Nothing in the code is India-specific: a market is a row, and
currency, locale, time zone, units, phone format, features, regions, cities, coverage and corridors are data.

### Data model

```
markets ──1:1── market_configurations
   │
   ├──< market_regions ──< cities ──< service_areas
   │                          ▲
   └──< route_corridors ──────┘  (origin / destination city, optional)

audit_events   (who changed what, when, why — append-only)
```

| Table | What it is | Spatial column (all SRID 4326, all GiST-indexed) |
|---|---|---|
| `markets` | a country FoodOnTheGo can operate in | `bounds geometry(Polygon)` — coarse envelope used to decide which market a point is in |
| `market_configurations` | payment / tax / legal / address / ordering settings and `locked_features`, as jsonb | — |
| `market_regions` | state, union territory, province (`code` = ISO 3166-2) | — (no boundary yet, see limitations) |
| `cities` | a city of a region, with its time zone | `center geography(Point)` |
| `service_areas` | operational coverage inside a city | `geometry geometry(MultiPolygon)`, `ST_IsValid` check constraint |
| `route_corridors` | an operational highway corridor: centreline + `corridor_width_meters` (100 … 100 000) | `centerline geometry(LineString)` |

Every editable row has `public_id` (UUID, exposed as `id`), `version` (optimistic concurrency) and timestamptz
columns. Internal bigint keys never leave the backend. Rows are never deleted through the API — they are taken
out of service with a status, which keeps history and foreign keys intact.

**A row is reference geography, not an operating claim.** `IndiaGeographySeeder` creates all 28 states and
8 union territories as `PLANNED`; a region, city or area serves customers only when an administrator opens it.

### Spatial standard

- WGS84 / SRID 4326 everywhere. GeoJSON (RFC 7946) is the only interchange format: positions are
  `[longitude, latitude]`.
- Containment and topology use `geometry`; anything measured in metres (distance, corridor width, area, length)
  is computed on `geography` — never by buffering degrees.
- Coordinates and GeoJSON reach SQL only as bound parameters (`HasSpatialColumns`, `Location::pointSql()`).
- Raw spatial columns are never serialised. Lists return a summary (area in m², vertex count, bounding box,
  length); the geometry itself is returned for a single record and by the map endpoint.
- Submitted geometry is validated before it is stored (`GeoJsonGeometry`): expected type only, no `crs`
  member, positions inside the WGS84 range, closed rings, at most `GEO_MAX_POSITIONS` (5000) positions, then
  `ST_IsValid` / `ST_IsEmpty` in PostGIS. Invalid geometry is **rejected, never repaired** (422
  `invalid_geometry`). Geometry outside the market's bounds — the usual sign of swapped latitude / longitude —
  is refused with 422 `geometry_outside_market`.

### Status hierarchy

| Level | Statuses | Serves customers |
|---|---|---|
| Market | DRAFT, PILOT, ACTIVE, PAUSED, CLOSED | PILOT, ACTIVE |
| Region | PLANNED, PILOT, ACTIVE, PAUSED, DISABLED | PILOT, ACTIVE |
| City | PLANNED, PILOT, ACTIVE, PAUSED, UNAVAILABLE | PILOT, ACTIVE |
| Service area / corridor | PLANNED, TESTING, ACTIVE, PAUSED, DISABLED | ACTIVE, inside `effective_from` … `effective_until` (server clock) |

A child is never more available than its parent: an ACTIVE area in a paused city, or an ACTIVE city in a
paused market, is not available. This is evaluated on every check — statuses are not cascaded, so reopening a
market restores exactly what was open before. TESTING is internal and never serves customers.

Status changes follow the transition table of each enum (`ControlledStatus`); anything else is 409
`invalid_status_transition`. A retired state (CLOSED, DISABLED, UNAVAILABLE) can only go back to the first
state (DRAFT / PLANNED) — never straight to live. New records always start PLANNED.

### Location availability — `MarketAvailabilityService`

`POST /api/v1/availability/location {lat, lng, country_code?}` — public, rate limited (`RATE_LIMIT_AVAILABILITY`,
30 / minute / address), the location is not stored. Clients never decide this.

1. **Market**: the market whose `bounds` covers the point. No market, or one that is DRAFT / CLOSED →
   `MARKET_UNSUPPORTED` (the market is not named). PAUSED → `MARKET_PAUSED`.
2. **Service areas** covering the point (`ST_Covers` — a point exactly on the boundary is inside), best first:
   higher `priority`, then the smaller area, then the older row. **Overlap is allowed**; the first covering area
   whose whole chain is open makes the location supported.
3. If a covering area exists but none is open: `REGION_UNAVAILABLE`, `CITY_UNAVAILABLE` or `SERVICE_AREA_PAUSED`
   (paused / testing / planned / disabled, or outside its effective dates).
4. If no area covers the point: the nearest known city within `GEO_CITY_MATCH_RADIUS_METERS` (40 km) decides
   between `REGION_UNAVAILABLE`, `CITY_UNAVAILABLE` and `OUTSIDE_SERVICE_AREA`.

The response carries `supported`, the machine-readable `reason` (clients choose the wording), and the market /
region / city / service area only when they are open to customers, plus the live corridors whose band contains
the point. Availability never reads the cache.

**Limitations, stated plainly.** The market is found through a bounding envelope, not a legal border: a point
just across a border can fall inside India's envelope. A `country_code` sent with the location that contradicts
the market is treated as "not this market", and real coverage is decided by service areas, so such a point ends
as `OUTSIDE_SERVICE_AREA` — never as supported. Regions have no boundary geometry: the region is the one of the
resolved city. Both are recorded as carry-forward items.

### Route corridors, distance, journeys

- `RouteCorridorQuery::containing(point)` — corridors in effect whose band (centreline ± its own width,
  geodesic) contains the point. `intersecting(line)` is true topology (the line crosses the centreline);
  `within(line)` is proximity (the line enters the band). They are different questions and are kept apart.
- `DistanceService::between()` — geodesic metres on the WGS84 spheroid. Driving distance and detours come
  from the routing provider later.
- `JourneyCoverage` — availability of both ends of a journey and the corridors along a route line. It contains
  no routing; the journey module supplies the route geometry. No Google Routes / Places call exists yet.

### Public API (no token)

| Endpoint | Returns |
|---|---|
| `GET /markets` | markets open to customers (ACTIVE / PILOT) |
| `GET /markets/current[?country=IN]` | the market serving this client |
| `GET /markets/current/coverage` | regions and cities (PLANNED, PILOT, ACTIVE, PAUSED), service areas (ACTIVE in effect, PAUSED) with geometry, corridors (ACTIVE in effect) with geometry |
| `POST /availability/location` | the availability decision |

Never public: DRAFT / CLOSED markets, DISABLED / UNAVAILABLE regions and cities, PLANNED / TESTING / DISABLED
areas and corridors, launch stage, priority, effective dates, versions, configuration, audit data.

The market payload and the coverage are cached (`GEO_MARKET_CACHE_SECONDS`, 300 s) under a version key; every
write to market data — through the API or a model — changes the version, so stale entries are unreachable at once.

### Admin API (`auth:admin` + `active`, then permission **and market scope** in the controller)

| Endpoint | Permission |
|---|---|
| `GET /admin/markets`, `GET /admin/markets/{market}`, `GET …/regions` | `admin.markets.view` |
| `PATCH /admin/markets/{market}` (status), `POST …/regions`, `PATCH /admin/regions/{region}` | `admin.markets.manage` |
| `PATCH /admin/markets/{market}/features` | `admin.market_features.manage` |
| `GET` / `PATCH /admin/markets/{market}/configuration` | `admin.market_configuration.view` / `.manage` |
| `GET …/cities`, `GET /admin/cities/{city}` · `POST …/cities`, `PATCH /admin/cities/{city}` | `admin.cities.view` · `.manage` |
| `GET …/service-areas`, `…/route-corridors`, `…/map`, `POST …/availability-check`, single records | `admin.service_areas.view` |
| `POST` / `PATCH` service areas and route corridors | `admin.service_areas.manage` |
| `GET /admin/audit-events` | `admin.audit.view` |

- A permission held platform-wide covers every market; one held for a market (`Scope::market`) covers only
  that market and its regions, cities, areas and corridors. Market and audit lists are limited to the markets
  in scope. A record of one market can never be attached to another (422).
- Every write (`GeographyAdminService`): the caller sends the `version` it edited (409 `stale_update` if it is
  no longer current), the row is locked, the transition is checked, a **reason** is required when service is
  taken away from customers — and always for market status, features and configuration — the change and an
  audit event are written in one transaction, and the public cache is invalidated. An update that changes
  nothing writes nothing.
- Features: only keys the market already has; a key listed in `locked_features` cannot be enabled (409
  `feature_locked`). For India `cash_at_pickup` and `cross_border_ordering` are locked — cash at pickup is NOT APPROVED.
- Configuration refuses anything that looks like a credential (422 `secrets_not_allowed`): provider secrets
  live in server configuration only. A payment method is PLANNED, ENABLED or NOT_APPROVED, and one listed in
  `locked_features` cannot be ENABLED (409 `feature_locked`).
- Writes are rate limited per administrator (`admin-sensitive`).

### Audit trail

`audit_events`: action (`city.updated`, `market.features_updated`, …), actor type and public id, target type and
public id, market, reason, `changes` (`field → {from, to}`; a geometry change records a summary, not the
polygon), request id, address, time. Values pass through the log redactor. Append-only: the application never
updates or deletes a row. Authentication events stay in `security_events`.

`GET /admin/audit-events` serves the admin Audit screen: newest first, with the actor's name and the current
name of the changed record (looked up per page; missing when the account or record is gone), `?q=` (text in the
action or reason), `?from=` / `?to=` (UTC dates, inclusive), `filter[action]`, `filter[target_type]`, and `facets`
(the actions and target types that exist in the caller's scope). The stored IP address is not returned. Only changes
that were made are audited — a refused attempt is not an audit event.

### Seed data

- Every environment: the India market and its bounds, 36 regions (all PLANNED), the India configuration.
- Local / testing only (`LocalGeographyFixtureSeeder`): 23 cities, 15 service areas and 7 corridors with mixed
  statuses — **development fixtures; a name here does not mean FoodOnTheGo operates there.** The areas are
  32-gon circles generated by PostGIS and the corridor centrelines are straight lines between city centres,
  stand-ins for surveyed boundaries and road geometry. Re-seeding never overwrites a row that already exists.

## 9. Front-end integration

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
  Mocks stay for tests.
- Market and geography are on the real backend (Module 22): `VITE_MARKET_MODE=api` /
  `--dart-define=MARKET_MODE=api`. The web app loads `GET /markets` + `GET /markets/current/coverage` once
  before rendering (`MarketGate`; snapshot in sessionStorage, refreshed on reload and when a tab regains focus
  after 5 minutes), Android does the same on the splash screen. The existing synchronous market repositories
  read that read-only snapshot, so no page changed. The snapshot is display data: the restaurant list asks
  `POST /availability/location` for the chosen location, and shows an error with a retry (Android) or falls
  back to the snapshot (web) only when the request itself fails.
- Platform Admin → Markets uses `ApiAdminMarketControlRepository`: it reads `/admin/markets`, `/map` and
  `/configuration`, and sends status and feature changes with the version shown and the reason. A refusal
  (403, 409 transition, 409 stale) is displayed. States, cities, service areas and route corridors can be
  added and edited there, and the market configuration edited (`MarketGeoForms.tsx`, `MarketAdminForms.tsx`). There
  is no map provider to draw on yet: a boundary is a circle (turned into a 32-point polygon) or pasted GeoJSON, a
  corridor centreline is straight lines through the chosen cities or a pasted LineString. New records start PLANNED. Restaurant pins, order counts and revenue in those screens are
  still development fixtures. The Audit screen (`ApiAdminAuditRepository`) shows the backend audit trail; the
  development log of the areas that are still mock is a separate, labelled tab. The Security screen shows the backend's security events
  (`ApiAdminSecurityRepository`, `SecurityBackendPage.tsx`). The Admin Users screen (`ApiAdminUserRepository`) lists, invites,
  re-roles and suspends administrators on the backend; `/admin/accept-invitation` is the public page where an
  invited administrator sets a password.
  The list is searched (name / e-mail), filtered by status and paged by the backend (10 per page); a role can be
  given for all markets or one market (`market_id`), and an account can be disabled (final).
- Staff account security (both dashboards, `customer-web/src/auth/staff/`): `/…/forgot-password` and
  `/…/reset-password` (public; the token travels in the URL fragment and is removed from the address bar),
  `/…/account-security` (profile menu: MFA on / off, change password, signed-in devices). `MfaSetup.tsx` draws
  the QR code in the browser, confirms with a first code and shows the recovery codes once; the same component
  runs on the sign-in screen when the backend answers `mfa_enrollment_required` (the enrol-only token stays in
  memory). Locally no e-mail is sent — the reset link is written to `storage/logs/laravel.log` — and mandatory
  MFA is off (`AUTH_MFA_REQUIRED_ADMIN=false`), so enrol-at-sign-in is covered by a unit test only.

- Restaurants are on the real backend (Module 23): `VITE_RESTAURANT_MODE=api` / `--dart-define=RESTAURANT_MODE=api`
  (meant to be used with the auth and market modes on `api`). Customer Web loads `GET /restaurants` (all pages) and
  `GET /cuisines` once before the first customer page (`RestaurantGate`; snapshot in sessionStorage, refreshed when
  older than a minute and when the tab regains focus) and asks `GET /restaurants/{slug}` again on every restaurant
  page; a 404 removes the restaurant from the snapshot. The existing `RestaurantRepository` reads the snapshot, so
  the discovery pages did not change; "open now" is recomputed in the browser with the backend's schedule rules
  (`computeAvailability`, same tests on both sides). Android does the same at start-up (`api_restaurants.dart`).
  Nothing falls back to fixtures: without the backend the list is empty and the gate offers a retry. Ratings /
  reviews, carts and orders are still development data and are labelled as such.
- Menus are on the real backend (Module 24, same `RESTAURANT_MODE=api` switch). Customer Web: `menu/menuRepository.ts`
  picks `ApiMenuRepository` (`menu/api/apiMenu.ts`), which reads `GET /restaurants/{slug}/menu` once per restaurant
  (reused 30 s; search, filters and paging are applied in the browser as the fixtures did) and
  `GET /restaurants/{slug}/items/{itemSlug}` for the item page; `getPriceQuote` asks `POST …/price-quote`. The pages
  and the cart review did not change (an item the backend does not show is `null`, exactly as before). Android:
  `menu/api_menu.dart` with `defaultMenuRepository()` in the four menu screens. An item without a photo shows its
  fallback (no empty image).
- Restaurant Dashboard → `apiDashboard.ts`: `GET /restaurant/context` decides which organizations and locations the
  signed-in user may open (default location = the backend's `default_location_id`); profile, hours, special hours,
  pickup settings, the accepting-orders switch and staff (`/restaurant/organizations/{org}/staff`, roles from
  `/restaurant/roles`) go through the API with the versions the page loaded. A refusal is shown in the page (403
  "access denied" without signing out, 404 "no longer available to you", 409 reload-and-retry, 422 the field
  message). The Menu page is on the backend too (Module 24, `apiMenuManagement.ts`): the management document with
  archived rows, categories (create, rename, reorder), items with their option groups as one document (ids kept,
  the rest archived), the availability switch as the backend status (available → ACTIVE, sold out → SOLD_OUT,
  temporarily unavailable → TEMPORARILY_UNAVAILABLE, unavailable → DISABLED), duplicate, archive, photos uploaded as
  multipart after the save and removed when taken away, dietary labels as the backend list (checkboxes). Orders,
  pickup verification, reviews, analytics, notifications and settings still carry a "Demo data" label. `/restaurant-dashboard/accept-invitation#<token>` is the public page where invited staff join (a new
  account chooses its password there).
- Platform Admin → `ApiAdminRestaurantRepository`: list (`/admin/restaurants`, backend paging / stage filter /
  search), one restaurant with the moves this administrator may make (`allowed_transitions`), approval / rejection
  (category + explanation) / suspension / reactivation of the location and of its organization, locations of the
  organization, staff summary, internal notes and the history (audit trail). The header badge says per section
  whether the data is backend or development data. The restaurant details page has a read-only **Menu** tab
  (`RestaurantMenuTab.tsx`, `GET /admin/restaurants/{location}/menu`): counts per state, the hidden categories and
  the menu as customers see it, with prices (Module 24).

- The customer account is on the real backend (Module 25) and **follows the sign-in mode**: with `VITE_AUTH_MODE=api` /
  `--dart-define=AUTH_MODE=api` the Api* account repositories (`customer-web/src/account/api/apiAccount.ts`,
  `mobile/lib/account/api_account.dart`) replace the Module 04 development data for profile, favorites, saved journey
  places, payment-method references and notification preferences; the notification inbox stays development data
  and is labelled so. Favorites toggle optimistically and roll back on refusal; sensitive actions run the
  re-authentication code flow when the backend asks for it (403 `reauthentication_required`).

## 10. Not built yet

Journeys and route-aware discovery, cart, pickup slots, checkout, payments, orders, tracking, reviews, object
storage for images (menu photos and profile photos live on the local `public` disk), verification documents, restaurant self-service
onboarding, menu schedules (OUTSIDE_ITEM_SCHEDULE reserved), promotions and tax; customer e-mail verification, the
account erasure process (a deletion request only restricts the account — legal rules pending), data export,
push-token registration and notification delivery, a Places provider for saved-place pins; live SMS and e-mail
providers, Maps / Places / Routes, Razorpay, FCM, WebSockets; region boundary polygons and a surveyed market
border; drawing a boundary or corridor on a real map; production deployment, backups and monitoring. These are
tracked in `docs/project-progress.html`.

## 11. Restaurants: organizations, locations, hours, pickup settings, staff (Module 23)

The domain diagram and the lifecycles are in [`restaurant-domain.md`](restaurant-domain.md).

### Data model

- `restaurant_organizations` — the business (legal name, display name, primary market, contact), with its own
  administrative `status`. `restaurant_locations` — the outlets customers see as "a restaurant": one row per
  location with `geography(Point, 4326)` + GiST index, the market / region / city it lies in and the covering
  `service_area_id` (resolved by PostGIS when created or moved and re-resolved by `ReassignRestaurantServiceAreas`
  when the geography changes; never trusted from a client), IANA time zone and ISO currency, slug unique per market,
  its own administrative `status`, an `operational_status` (OPERATING / TEMPORARILY_CLOSED), `accepting_orders` with
  pause reason / until, `hours_version` and `version`.
- Profile content: `cuisines` and `restaurant_features` are taxonomies (seeded; 29 cuisines incl. the 19 India
  ones, 16 features) joined through pivot tables; `restaurant_images` holds metadata only (type, URL, alt text,
  order, status) — uploads and object storage are a later module.
- Hours: `restaurant_location_hours` (day of week, opens / closes `HH:MM`, up to four periods a day, overnight by
  `closes <= opens`, equal = 24 h) and `restaurant_special_hours` (+ `_periods`) for dates that replace the week.
  Not JSON: queryable, validated rows.
- Pickup: `restaurant_pickup_settings` (one row per location: enabled, ASAP / scheduled, preparation, lead,
  interval, horizon, buffer, cut-off, capacity) and `restaurant_location_pickup_methods` (COUNTER / CURBSIDE /
  DRIVE_THROUGH, each with instructions and vehicle-info flag). Slot booking is Module 28.
- Staff: `restaurant_memberships` (user × organization, role, INVITED / ACTIVE / SUSPENDED / REVOKED,
  `all_locations`), `restaurant_membership_locations` (the locations a limited membership may work on),
  `restaurant_staff_invitations` (hashed single-use tokens, expiring). `restaurant_admin_notes` are internal.
- Every table has `public_id` (UUID, exposed as `id`), integer `version` where clients write, created / updated
  timestamps; foreign keys restrict deletes (rows are kept for history and set INACTIVE / REVOKED instead).

### Services (`app/Services/Restaurant`)

- `RestaurantAvailabilityService` — visible / orderable and the first failing reason (see the diagram); customers
  get `AREA_UNAVAILABLE` for every geography pause and a 404 for anything hidden. `preloadCoverage()` resolves the
  covering areas of many locations in one query (the N+1 found by the query-count test).
- `RestaurantHoursService` + `Schedule` — validation (overlaps across midnight and the week), open now, closes at,
  opens next, in the location's zone; `RestaurantVisibility` — the list query (visible only, optional "open now").
- `RestaurantLifecycleService` — status moves of organizations and locations (transitions, permissions, reason /
  category / explanation, version, lock, audit, cache flush). `RestaurantAdminService` — admin create / update.
- `RestaurantProfileService`, `PickupSettingsService`, `PickupRules` (methods the market allows: only COUNTER in
  India today), `RestaurantStaffService` (invite / accept / update / suspend / revoke / resend, owner rules),
  `RestaurantAccess` (404 not yours / 403 no permission, per organization and per location), `RestaurantCatalog`
  (cache of the cuisine taxonomy only; lists and details are not cached server-side).

### API

Public (no token, `throttle:search`): `GET /restaurants` (filters `city`, `region`, `service_area`, `cuisine`,
`feature`, `open_now`, `price_level`, `q`, `lat`/`lng`/`radius_meters` for a straight-line distance — no route
detour, that is Module 26; sort `name` / `distance`), `GET /restaurants/{slug}`, `GET /cuisines`.
Restaurant (`auth:restaurant` + ACTIVE membership): `GET /restaurant/context`, `/taxonomy`, `/roles`,
`/locations/{location}` (+ `PATCH /profile`, `/availability`, `/images/{image}`, `GET|PUT /hours`,
`/special-hours[/{id}]`, `GET|PATCH /pickup-settings`), `/organizations/{org}/staff[/{membership}[/invitation]]`.
`POST /auth/restaurant/invitation/accept` is public (`throttle:password-reset`).
Admin (`auth:admin`, permission + market scope in the controller): `GET /admin/restaurants` (stage filter,
counts), `GET|PATCH /admin/restaurants/{location}`, `POST …/status`, `GET|POST /admin/restaurant-organizations`,
`GET|PATCH …/{org}`, `POST …/status`, `POST …/locations`, `POST …/notes`, `POST|DELETE …/staff[/{membership}]`.
Separate resources for customers, staff and administrators; protected fields (status, coordinates, organization,
market) are refused with 422 when a restaurant sends them; free text is plain text (no markup).

### Fixtures and seeds

`RestaurantTaxonomySeeder` (every environment) and `LocalRestaurantFixtureSeeder` (local only: 17 organizations,
19 locations across the India fixtures — approved, under review, draft, rejected, suspended organization, paused,
temporarily closed, outside coverage, 24 h, overnight, split and special hours; Riverside Hospitality Group with
owner / manager / order staff / menu manager / invited viewer / suspended viewer; Second Kitchen with its own owner).
`LocalFixtureSeeder` creates the restaurant accounts; the memberships give them their roles.

## 12. Menus, items, customization and pricing (Module 24)

The entity model, the services, the availability order and the decision list are in
[`menu-domain.md`](menu-domain.md).

- One menu per location (created on first use, ACTIVE, in the **location's currency**); categories and items with
  explicit `display_order`, stable per-menu slugs, statuses `ACTIVE / SOLD_OUT / TEMPORARILY_UNAVAILABLE / DISABLED /
  ARCHIVED`; variant and modifier groups in one table (`kind`) with `required / min / max`; options with integer
  price adjustments; photos checked by content and served by `GET /api/v1/media/{path}`; dietary tags as data.
- `MenuService` is the only writer (menu row locked per write, versions on edits → 409 `stale_update`, status switches
  without version, archive instead of delete, every change audited, `catalog_version` bumped). `MenuPricingService`
  is the price authority (`POST …/price-quote`; a client price is refused). `MenuAvailabilityService` gives the one
  visible / orderable / reason answer for every audience. `MenuCatalog` caches the customer documents per catalog
  version (no explicit flush; the restaurant's open / accepting state is merged per request).
- Routes: 21 new `/api/v1` operations (122 in total), all in `openapi/openapi.json` (97 paths, 174 schemas) and
  checked by the contract tests (`MenuOpenApiContractTest`, `OpenApiContractTest`).
- Permissions: `restaurant.menu.view` (read) and `restaurant.menu.manage` (write) at the location; another
  restaurant's menu is a 404 even with known ids; administrators read with `admin.restaurants.view` in the market.
- Fixtures: `DietaryTagSeeder` (every environment, 10 labels) and `LocalMenuFixtureSeeder` (local only, through
  `MenuService`: menus for the 19 fixture locations with India prices in paise — sold-out, temporarily unavailable,
  disabled and archived items, an inactive category, a DRAFT menu (Ambala Chai Point), a large customization (Pizza
  Point "Build Your Own Pizza"), a half-portion reduction, a large menu (Rajwada Thali, 8 × 8)). A location that
  already has a menu is left alone on re-seed.
- Tests: `tests/Unit/Menu` (pricing arithmetic ₹200 + ₹50 + ₹20 = ₹270, rules), `tests/Feature/Menu`
  (management API, authorization and isolation, customer API incl. cache freshness and a constant query count,
  images, admin oversight, fixtures, OpenAPI contract). Whole suite 455 tests / 5458 assertions
  (`docs/local-review/test-results/backend-m24-phpunit.txt`).

## 13. Customer account: profile, favorites, saved journey locations, payment references, notification preferences (Module 25)

The entity model, the services, the coverage answer, the API table, the privacy documentation and the decision list
are in [`customer-account-domain.md`](customer-account-domain.md). Everything below is local-only; nothing has
touched production.

- The identity stays the Module 21 customer (phone + OTP). Module 25 extends `customers` (birthday, gender, cuisine
  codes, vegetarian filter, search radius, photo path, deletion request, marketing consent timestamps, `version`) and
  adds `customer_favorite_locations`, `customer_saved_locations` (PostGIS point, nullable), `customer_recent_locations`,
  `customer_payment_methods` (encrypted provider references, never a PAN / CVV / UPI PIN), `customer_notification_preferences`
  (only chosen cells) and `customer_phone_changes` (migration `2026_10_04_100000_create_customer_account_tables`).
- Routes: 25 new `/api/v1/customer/...` operations (147 in total), all `auth:customer` + `active`, all in
  `openapi/openapi.json` (114 paths, 203 schemas) and checked by `CustomerOpenApiContractTest` / `OpenApiContractTest`.
  The phone is read-only on the profile (422 `prohibited`, like status, market, roles and verification fields) and
  changes only through `POST /customer/phone-change/request` + `/verify` (code to the **new** number, other sessions
  revoked). The Module 21 `PATCH /auth/customer/profile` stays for the sign-up step.
- Sensitive actions (phone change, deletion request) need **recent authentication**: a session younger than 30 minutes,
  or a code to the account's own phone verified on this session within 10 minutes (`POST /customer/account/reauth` +
  `/verify`); otherwise 403 `reauthentication_required`. A deletion request records the request, restricts the account
  (sessions stay) and erases nothing — the erasure process is PENDING FINAL BUSINESS & LEGAL APPROVAL.
- Favorites target restaurant locations; add is idempotent on the unique pair (201 / 200), only visible restaurants
  can be added (404 otherwise), a favorite whose restaurant became hidden is kept and answered with its name and
  `available: false`. Saved locations are journey shortcuts (never delivery addresses): market, city and service area
  are resolved on the server, `coverage` is decided per request from the current statuses, a place abroad is saved and
  reported `unsupported` (saving it never activates a market), the first saved place is the default (one per customer),
  edits carry a `version`, IDOR answers 404. Recent places: bounded (10), deduplicated, clearable, pruned after 90 days
  (`customer:prune-recent-locations`, scheduled daily); clients wire them in Module 26.
- Payment methods: references only — no endpoint accepts payment details (`POST /customer/payment-methods` is 405, a
  card number in any body is 422), the list shows brand / last4 / expiry / masked UPI handle, `EXPIRED` is computed on
  read, remove marks the reference `REVOKED` (row kept; provider-side revocation is Module 30), one default per customer.
- Notification preferences: a category × channel matrix from `config/customer.php` (ORDER_UPDATES, PICKUP_UPDATES,
  PAYMENT_UPDATES, ACCOUNT_SECURITY, PROMOTIONS, PRODUCT_UPDATES × PUSH, SMS, EMAIL, IN_APP); security notices are
  locked on SMS and in-app (422 on an attempt to switch them off); marketing is off until the customer switches a channel
  on, which stamps the consent (`promotions_consented_at` / `promotions_withdrawn_at`). Delivery is a later module
  (push tokens: Module 33).
- Profile photos are checked by content (JPEG / PNG / WebP, ≤ 2 MiB, 120–2000 px), stored under
  `avatars/{customer}/{uuid}.{ext}` on the configured disk and served by `GET /api/v1/media/{path}` (now
  `menu/...` or `avatars/...`); the previous file is deleted on replace / remove.
- Audit: `customer.profile_updated` (personal fields recorded as "changed", never their values), `customer.avatar_changed`,
  `customer.saved_location_*`, `customer.payment_method_*`, `customer.notification_preferences_changed`,
  `customer.deletion_requested`, `customer.phone_changed` (masked); security events `REAUTHENTICATED`,
  `PHONE_CHANGE_REQUESTED`, `PHONE_CHANGED`, `ACCOUNT_DELETION_REQUESTED`. Logs never carry full phones, codes,
  tokens, provider references, addresses or coordinates.
- Fixtures (`LocalCustomerFixtureSeeder`, local only, idempotent): Rahul Sharma — profile (1990-03-15, MALE, North
  Indian / Burgers / Healthy, 20 km), favorites Burger Hub, Spice Nest, Night Owl Kitchen and the hidden Expressway
  Grill, saved places Home (served), Work (outside the service areas), Mumbai hotel (region unavailable), Dubai airport
  (outside our markets), Grandma's place (no coordinates), payment references Visa •••• 4242 (default, 12/2028),
  UPI ra***@okaxis, Mastercard •••• 4444 (expired), ORDER_UPDATES e-mail off; Asha Verma — favorite Pizza Point.
- Tests: `tests/Feature/Customer` (profile, favorites, saved + recent locations, payment references, notification
  preferences, account security, fixture seeder, OpenAPI contract) — whole suite `docs/local-review/test-results/backend-m25-phpunit.txt`.
- Apps: Customer Web (`src/account/api/apiAccount.ts`, selected by the sign-in mode in `src/account/accountRepositories.ts`)
  and Android (`lib/account/api_account.dart`, selected in `AccountState.defaultRepositories`) replace the Module 04
  mocks for profile, favorites, saved places, payment references and preferences; the notification inbox stays
  development data and is labelled so. Phone change, re-authentication and the deletion request run in both apps.
