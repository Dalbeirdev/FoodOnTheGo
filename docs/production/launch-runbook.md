# Launch runbook

Status: Module 41 — 2026-10-16 — **written, NOT executed.** Module 42 executes it, and only after Module 41 records
READY FOR PRODUCTION DEPLOYMENT = YES and the owner approves the launch window. Production = NO today.

Rules for the whole run: one named release manager; every step is logged with time and result in the launch log; any
"rollback criterion" of [rollback-runbook.md](rollback-runbook.md) stops the run; nothing in this runbook flushes a
database or Redis, deletes a queue, marks a payment paid or a refund complete, or switches a provider to live mode as a
workaround.

## 0. Go / no-go (T-1 day)

- [ ] [production-blocker-register.md](production-blocker-register.md) has no open blocker (or each remaining one has the
      business owner's written acceptance).
- [ ] [business-acceptance.md](business-acceptance.md) sign-off matrix: every row APPROVED.
- [ ] Release candidate: one commit SHA for backend, web and Android; Module 41 regression evidence on that SHA.
- [ ] Rollback owner and support / restaurant contacts on call; status page / customer message prepared.

## 1. Production process model (what runs where)

| Process | Runtime | Instances | Notes |
|---|---|---|---|
| API | nginx → PHP-FPM 8.3 (OPcache on, `opcache.validate_timestamps=0`, `expose_php=Off`) | ≥ 2 behind the load balancer when storage is shared (CF-560), otherwise 1 | never `php artisan serve` / `php -S` |
| Queue workers | `php artisan queue:work redis --queue=payments,orders,finance,notifications,default --timeout=85 --max-time=3600 --sleep=1` under systemd / Supervisor | ≥ 2 (one dedicated `payments,orders`) | worker timeout < `retry_after` (90 s) |
| Scheduler | `php artisan schedule:run` every minute (cron) on every host — `onOneServer` keeps one run | every host | needs the shared Redis cache |
| Realtime | `php artisan reverb:start --host=0.0.0.0 --port=8080` on Linux with ext-uv / ext-event, raised `ulimit -n`, behind a TLS proxy | 1 (scaling via Redis when > 1 — CF-567) | `REVERB_ALLOWED_ORIGINS` = web hosts |
| Web (Customer Web + Dashboard + Admin) | static files of `vite build` served by nginx / a CDN, SPA fallback to `index.html` | — | never the Vite dev server; security headers sent by the server (CSP is in the HTML) |
| PostgreSQL 16 + PostGIS | managed service, TLS, PITR | primary (+ replica later) | PostGIS enabled by the DBA |
| Redis | managed Redis / Valkey, password + TLS, `noeviction`, persistence | 1 + failover | cache, limits, locks, queues, sessions |

## 2. Pre-deployment backup

1. Production is empty at first launch; for every later deploy: a fresh logical dump of the production database
   (`pg_dump -Fc`, as in `scripts/local/db-backup-restore.mjs`) **and** confirmation that PITR is current.
2. Restore the dump into a scratch database on the production platform and run the integrity commands (§10) — "a backup
   is not valid until a restore has worked" (Module 40).
3. Back up `storage/app` (images, avatars, exports) and the secret store export (with its own access control).

## 3. Infrastructure, DNS and TLS

| Record | Points to | TLS |
|---|---|---|
| `www` / apex (Customer Web) | web hosting / CDN | certificate, HSTS |
| `dashboard`, `admin` (if served on their own hosts; otherwise paths of the web origin) | web hosting | certificate; the admin host may be IP-restricted (CF-247) |
| `api` | load balancer → PHP-FPM | certificate; `APP_URL=https://api…` |
| `ws` (realtime) | load balancer (WebSocket) → Reverb | certificate (wss) |

- [ ] DNS TTLs lowered a day before; records created; certificates issued and auto-renewing.
- [ ] Load balancer: health check `GET /api/v1/health` (readiness), liveness `GET /api/v1/live`; WebSocket upgrade for `ws`;
      `X-Forwarded-*` set; its CIDRs in `TRUSTED_PROXIES`.

## 4. Environment verification and secret loading

1. Load the environment from [environment-matrix.md](environment-matrix.md) / `backend/.env.production.example` — every
   key decided; secrets from the secret store only ([production-secrets-checklist.md](production-secrets-checklist.md)).
2. On one API host: `php artisan about` (environment production, debug off), `php artisan foundation:verify`
   (PostgreSQL, PostGIS, Redis, cache, queue).
3. `php artisan security:check` → **must exit 0** (41 items). Any FAIL stops the launch.

## 5. Database migrations and reference seed

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
php artisan migrate --force
php artisan db:seed --force
php artisan admin:create <owner e-mail> "<name>" --role=SUPER_ADMIN
```
- Migrations: 33, deterministic order, additive; the Module 40 index migration builds CONCURRENTLY outside a transaction
  (afterwards: `select count(*) from pg_index where not indisvalid` must be 0).
- The seed in production writes reference data only (India market + configuration, 28 states and 8 union territories,
  roles and permissions, cuisines / features, dietary tags) — the fixture seeders refuse themselves outside local /
  testing (Module 41).
- The first administrator: hidden password prompt (or `ADMIN_BOOTSTRAP_PASSWORD` for that one run, removed afterwards);
  MFA enrolment is forced at first sign-in.
- Then the business configuration through the admin (never by SQL): approved tax rules, the approved commission plan,
  launch cities / service areas / corridors, legal document versions, restaurants with their owners.

## 6. Backend deployment

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
php artisan queue:restart
```
- Caches are built **with the production environment loaded** (the route cache freezes the dev-route decision).
- Start / reload PHP-FPM; start the queue workers (§1), the scheduler cron, Reverb.
- `php artisan ops:reliability --alerts` → no critical alert.

## 7. Frontend deployment (Customer Web, Restaurant Dashboard, Platform Admin)

```bash
npm ci
npm run build
```
- With the production `VITE_*` values (`VITE_ENVIRONMENT=production`); the release gate stops a build that is not
  configured (every mode `api`, https API, wss realtime, no dev OTP). Source maps are not produced.
- Upload `dist/`; the server sends HSTS, `frame-ancestors 'none'`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy` (camera for the restaurant scanner only).
- **Never deploy** an existing local `dist/`, `dist-share/` or `dist-share-admin/`.

## 8. Android production build

```bash
flutter build appbundle --flavor production --release --obfuscate --split-debug-info=build/symbols --build-name=<x.y.z> --build-number=<n> --dart-define=BUILD_NAME=<x.y.z> --dart-define=BUILD_NUMBER=<n> --dart-define=API_BASE_URL=https://<api>/api/v1 --dart-define=REVERB_HOST=<ws host> --dart-define=REVERB_PORT=443 --dart-define=REVERB_SCHEME=https --dart-define=REVERB_APP_KEY=<key> --dart-define=PUSH_MODE=fcm --dart-define=FIREBASE_API_KEY=<…> --dart-define=FIREBASE_APP_ID=<…> --dart-define=FIREBASE_SENDER_ID=<…> --dart-define=FIREBASE_PROJECT_ID=<…> --dart-define=AUTH_MODE=api --dart-define=MARKET_MODE=api --dart-define=RESTAURANT_MODE=api --dart-define=JOURNEY_MODE=api --dart-define=CART_MODE=api --dart-define=PICKUP_MODE=api --dart-define=CHECKOUT_MODE=api --dart-define=PAYMENT_MODE=api --dart-define=ORDER_MODE=api --dart-define=REVIEW_MODE=api --dart-define=REALTIME_MODE=api
```
- Signed with the upload key from the CI secret store (never the debug key; never committed — CF-548).
- The app refuses to start when any production setting is wrong (production gate). Keep `build/symbols` for crash
  de-obfuscation.
- Distribution per the business plan (Play internal testing → production track, or a controlled APK): the physical
  device acceptance (CF-574) is done on this exact build.

## 9. Provider webhooks and configuration

- Razorpay: webhook `https://api…/api/v1/webhooks/payments/razorpay` with its secret; mode = what the owner approved
  (TEST until LIVE is a reviewed change).
- FCM: service account mounted; the Android app's Firebase registration matches the signing certificate.
- SMS: DLT template live; `php artisan otp:test` to the release manager's phone.
- Mail: sender domain verified; an invitation to the release manager.
- Google: keys restricted; a journey planned on production.

## 10. Smoke tests

Run [smoke-test-plan.md](smoke-test-plan.md) in order. Then the integrity commands:

```bash
php artisan finance:verify-ledger
php artisan finance:reconcile-internal
php artisan payments:reconcile
php artisan orders:recover
php artisan analytics:check
php artisan ops:reliability --alerts
```

## 11. Monitoring

- Logs arrive in the collector (JSON lines; redaction tap; never slack / papertrail / syslog / errorlog channels).
- Alerts wired: `ops:reliability --strict` (critical alerts), provider health (payments, notifications, routing),
  queue depth / oldest job, failed jobs, 5xx rate, certificate expiry, backup success.
- Watch the first 2 hours actively, then the first 24 hours with the on-call contact (CF-569).

## 12. Rollback criteria

Any of: authentication failing for customers or staff; payment creation failing; an order lost (a verified payment
without an order beyond the recovery window); a migration failure; any cross-tenant / cross-customer data exposure;
sustained 5xx above the agreed threshold; a ledger verification problem. → [rollback-runbook.md](rollback-runbook.md).
External payment state is never "rolled back": it is reconciled.

## 13. Launch sign-off

- [ ] Smoke tests passed (with the controlled, approved live transaction if LIVE was approved).
- [ ] Monitoring green for the agreed window.
- [ ] Owner's written go-live confirmation recorded in the tracker; `production = YES` only then.
