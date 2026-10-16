# FoodOnTheGo

FoodOnTheGo is a **pickup-only** food ordering platform for people on the move. A customer plans a journey, sees the restaurants
along the route, orders ahead, pays online and collects the food at the restaurant counter with a single-use QR code or a manual
pickup code. There is no delivery, no driver and no cash at pickup.

- **Launch market:** India (INR, en-IN, metric). The code is market-driven (country, currency, locale, time zone and units come
  from the market configuration), so further markets are configuration plus legal work, not a rewrite.
- **Launch platforms:** Customer Web and the Customer Android app. The Restaurant Dashboard and the Platform Admin are web
  applications. **iOS is deferred.**
- **Status:** built and verified **locally** (Modules 01–41). **Production is not deployed.** Module 41 decides production readiness;
  Module 42 deploys. The current decision and its blockers are in [docs/production/production-blocker-register.md](docs/production/production-blocker-register.md).

## Architecture

```
 Customer Web ─┐                                   ┌─ PostgreSQL 18 + PostGIS 3.6 (the only source of truth)
 Restaurant    ├─ HTTPS /api/v1 (JSON, bearer) ─ Laravel API ─┼─ Redis (cache, rate limits, locks, queues — never authoritative)
 Dashboard     │                                   ├─ Queue workers (orders, payments, finance, notifications, default)
 Platform Admin┤   WebSocket (private channels) ─ Laravel Reverb  ├─ Scheduler (sweeps, reconciliation, aggregation, pruning)
 Android app ──┘                                   └─ Providers: Razorpay · Google Places/Routes · FCM · SMS/WhatsApp · mail · storage
```

- Clients talk **only to the API** — never to the database.
- **Money and authorization are backend-authoritative.** Prices, totals, taxes, fees, promotions, payment verification, order
  creation, refunds, the ledger and RBAC are decided on the server; client values are display only.
- **Payment, order and refund statuses are separate.** An order is created only by the backend, from a payment the server
  verified, exactly once.
- **The financial ledger is append-only accounting truth.** Support cases and analytics never change domain truth.

Detailed architecture: [docs/backend/README.md](docs/backend/README.md) (backend conventions and every domain),
[docs/security/threat-model.md](docs/security/threat-model.md), [docs/security/data-flow.md](docs/security/data-flow.md),
[docs/reliability/performance-architecture.md](docs/reliability/performance-architecture.md),
[docs/reliability/backup-dr-architecture.md](docs/reliability/backup-dr-architecture.md),
[docs/analytics/architecture.md](docs/analytics/architecture.md).

## Repository layout

| Path | What |
|---|---|
| `backend/` | Laravel 13 API (PHP 8.3), migrations, seeders, queue jobs, scheduler, Reverb, `openapi/openapi.json`, PHPUnit suite |
| `customer-web/` | React 19 + TypeScript + Vite: Customer Web, Restaurant Dashboard (`/dashboard`), Platform Admin (`/admin`) |
| `mobile/` | Flutter customer app (Android; `local` / `staging` / `production` flavors) |
| `tests/performance/` | Module 40 performance, load, failure-injection and DR tooling (Node) |
| `scripts/local/` | Local setup / start / verify scripts (Windows PowerShell), the local API proxy, backup / restore tool |
| `docs/` | Domain, security, privacy, reliability, analytics and production documentation; the project tracker |
| `docs/project-progress.html` | The project tracker (generated from `docs/tracker/data.json` by `docs/tracker/build.py`) |

## Prerequisites (local development)

| Tool | Version used | Note |
|---|---|---|
| PHP | 8.3 (extensions: pdo_pgsql, redis or predis, intl, mbstring, openssl, sodium, zip, gd) | Composer 2 |
| PostgreSQL | 18.6 locally (production plan: 16 + PostGIS — see the version decision CF-268) | PostGIS 3.6 |
| Redis | 5.x locally (production: a current Redis / Valkey with a password and TLS) | cache, queues, locks |
| Node.js | 24 | web tooling, the local API proxy, browser and performance suites |
| Flutter | stable (Dart 3) + Android SDK + JDK 17 | the APK is built in WSL Ubuntu on the development PC (BUG-009) |

The development PC uses native Windows installs under `C:\dev` (no Docker). The scripts in `scripts/local/` install and start them.

## Local setup

```powershell
# 1. Database (once): creates foodonthego_local + foodonthego_test, enables PostGIS (needs the local superuser file)
pwsh -File scripts/local/setup-database.ps1

# 2. Backend configuration
cd backend
copy .env.example .env          # fill the local values; never commit .env
php artisan key:generate
composer install
php artisan migrate
php artisan db:seed             # reference data everywhere; development fixtures only when APP_ENV is local / testing

# 3. Web configuration
cd ../customer-web
copy .env.example .env.local    # every VITE_*_MODE=api for the real backend
npm install
```

Environment variables: the backend reads `config/*.php` only (a test forbids `env()` elsewhere). Every variable, its local value
rule and its required production value is listed in [docs/production/environment-matrix.md](docs/production/environment-matrix.md).
`.env` files are git-ignored; secrets never go into the repository, the tracker or evidence files
([docs/security/secret-management.md](docs/security/secret-management.md)).

## Run locally

```powershell
pwsh -File scripts/local/start-backend.ps1        # Redis, PostgreSQL, Laravel on 127.0.0.1:8002 behind the proxy on :8001
cd backend
php artisan queue:work redis --queue=orders,payments,finance,notifications,default
php artisan schedule:work
php artisan reverb:start --host=127.0.0.1 --port=8085
cd ../customer-web; npm run dev                    # http://127.0.0.1:5173  (dashboard: /dashboard, admin: /admin)
```

The Android app: see [mobile/README.md](mobile/README.md) (flavors, `--dart-define` switches, building and installing the APK).
`php artisan serve` and `npm run dev` are **development servers only** — never production servers (see the process model in
[docs/production/launch-runbook.md](docs/production/launch-runbook.md)).

Useful commands (in `backend/`): `php artisan foundation:verify`, `php artisan security:check [--as-production]`,
`php artisan ops:reliability --alerts`, `php artisan finance:verify-ledger`, `php artisan analytics:check`,
`php artisan admin:create <email> "<name>" --role=SUPER_ADMIN` (hidden password prompt; the only way to create an administrator).

## Providers

| Provider | Purpose | Local | Production |
|---|---|---|---|
| Razorpay | payments, refunds | DEVELOPMENT provider (`PAYMENT_DRIVER=fake`); Razorpay client implemented, never exercised | TEST mode first, then LIVE (Module 42, owner approval) |
| Google Places / Routes | journey planning, route discovery | DEVELOPMENT providers | server key with API + IP restrictions |
| Firebase Cloud Messaging | push notifications | development push provider | production Firebase project + service account |
| SMS / WhatsApp OTP | sign-in codes | log driver (local test code) | an approved provider with India DLT registration |
| Mail | invitations, password reset, notification e-mail | log mailer | a real mail provider |
| Object storage | menu / restaurant images, avatars, exports | local disks | S3-compatible storage (required before more than one app host) |

Readiness of each provider: [docs/production/provider-readiness.md](docs/production/provider-readiness.md). Live provider credentials
are never placed in local files, the tracker or evidence.

## Tests

| Suite | Command | Where |
|---|---|---|
| Backend (PHPUnit, real PostgreSQL + PostGIS + Redis) | `php artisan test` (uses `foodonthego_test`; never run two at once) | `backend/tests` |
| Web unit / component | `npm test`, `npx tsc -b`, `npm run lint`, `npm run build` | `customer-web/src/**/*.test.tsx` |
| Flutter | `flutter analyze`, `flutter test` | `mobile/test` |
| Browser end-to-end (real local backend) | the per-module suites listed in the completion reports | `docs/local-review/test-results/*-e2e.json` |
| Performance / failure / DR | `node tests/performance/<script>.mjs` (isolated perf databases only) | `docs/reliability/load-test-plan.md` |

## Documentation

- Backend domains: [docs/backend/](docs/backend/) · OpenAPI: [backend/openapi/openapi.json](backend/openapi/openapi.json)
- Security: [docs/security/](docs/security/) · Privacy: [docs/privacy/](docs/privacy/) · Reliability, backup and DR: [docs/reliability/](docs/reliability/)
- Analytics: [docs/analytics/](docs/analytics/) · Finance: [docs/backend/finance.md](docs/backend/finance.md) · Support: [docs/backend/support.md](docs/backend/support.md)
- User guides: [restaurant](docs/guides/restaurant-user-guide.md) · [admin](docs/guides/admin-user-guide.md) · [customer help](docs/guides/customer-help.md)
- Production: [readiness checklist](docs/production/production-readiness-checklist.md) · [launch runbook](docs/production/launch-runbook.md) ·
  [rollback runbook](docs/production/rollback-runbook.md) · [smoke tests](docs/production/smoke-test-plan.md) ·
  [feature matrix](docs/production/feature-matrix.md) · [blocker register](docs/production/production-blocker-register.md)
- Module reports: [docs/local-review/reports/](docs/local-review/reports/)

## Deployment boundary

Everything in this repository has been run **only on the local development machine** with development providers. No production
environment, production database, live provider account or real customer data has been touched. Production deployment is
Module 42 and may start only when Module 41 records **READY FOR PRODUCTION DEPLOYMENT = YES**.
