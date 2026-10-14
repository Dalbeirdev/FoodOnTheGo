# Data retention (backend)

Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/routes/console.php`, `backend/app/Models/{OtpChallenge,IdempotencyKey,CustomerPhoneChange,CredentialResetToken,RestaurantStaffInvitation}.php`, `backend/app/Console/Commands/{AnalyticsPrune,PruneCarts,PruneCheckouts,PruneJourneys,PruneNotifications,PruneRecentLocations}.php`, `backend/app/Services/{Cart/CartService,Checkout/CheckoutService,Journey/JourneyService,Analytics/Reports/ReportExportService,Analytics/DataQualityService}.php`, `backend/app/Http/Middleware/EnforceIdempotency.php`, `backend/config/{api,analytics,auth_security,cart,checkout,customer,journey,notifications,logging,support,reviews,sanctum}.php`, `backend/database/migrations/2026_10_19_100000_module39_security.php`, `2026_10_16_100000_module36_finance.php`, `2026_10_17_100000_module37_support.php`.

This document says what the backend keeps, for how long, and what removes it — as the code does it today. It is a
technical description, not a retention policy. **Every period below is a development default, PENDING BUSINESS
APPROVAL** (and, where personal data is involved, PENDING LEGAL REVIEW). The privacy view of the same facts, by data
category, is in `docs/privacy/retention-matrix.md`.

How to read the "Set by" column:

- **Code constant** — the period is written in PHP; changing it needs a code change and a deployment.
- **Config default** — the period comes from `config/*.php` and can be overridden by the named environment variable.
- **Config (fixed)** — in a config file but not read from the environment.

## 1. The schedule

`routes/console.php` registers every clean-up job. They run only when the Laravel scheduler runs
(`php artisan schedule:run` every minute, or `schedule:work`). **Locally the scheduler is not a service**; in
production it is a deployment item (`docs/security/production-security-checklist.md`).

| Line | Command | Frequency | Removes |
|---|---|---|---|
| 13 | `model:prune` | daily, without overlapping | expired rows of the five `Prunable` models (section 2) |
| 14 | `sanctum:prune-expired --hours=24` | daily | access tokens that expired more than 24 h ago |
| 15 | `queue:prune-failed --hours=720` | daily | failed queue jobs older than 30 days |
| 18 | `customer:prune-recent-locations` | daily | recent places unused longer than the retention |
| 21 | `journeys:prune` | hourly | expired journey contexts |
| 24 | `carts:prune` | hourly | closes expired carts; deletes closed carts past retention |
| 26 | `checkouts:prune` | every 5 min | expires checkouts / holds; deletes closed checkouts past retention |
| 38 | `notifications:prune` | daily | expired inbox notifications, old delivery attempts, old revoked push tokens |
| 57 | `analytics:check` | hourly (switch) | also deletes quality results older than 30 days |
| 58 | `analytics:prune` | daily | raw events, session facts, run records, expired export files |

Lines 28–51 (`payments:reconcile`, `orders:recover`, `refunds:reconcile`, `notifications:recover`, `finance:*`,
`support:sweep`) are recovery and reconciliation jobs. **None of them deletes data.**

## 2. Prunable models (`model:prune`, daily)

Each model uses Laravel's `Prunable` trait; `model:prune` deletes the rows its `prunable()` query returns.

| Model / table | `prunable()` rule | Period | Set by | Personal data removed |
|---|---|---|---|---|
| `OtpChallenge` / `otp_challenges` | `expires_at < now − 7 days` (`OtpChallenge.php:29-32`) | 7 days after expiry | Code constant (the code lifetime itself: `OTP_TTL_SECONDS`, default 300 s) | phone number of anyone who asked for a code; HMAC of the code |
| `IdempotencyKey` / `idempotency_keys` | `expires_at < now` (`IdempotencyKey.php:21-24`) | at expiry; `expires_at` = creation + `API_IDEMPOTENCY_TTL_MINUTES` (default 1440 = 24 h, `config/api.php:29`) | Config default | the stored reply (encrypted since Module 39; may hold a pickup code, QR payload or payer contact) |
| `CustomerPhoneChange` / `customer_phone_changes` | `expires_at < now − 30 days` (`CustomerPhoneChange.php:34-37`) | 30 days after expiry | Code constant | the new phone number asked for |
| `CredentialResetToken` / `credential_reset_tokens` | `expires_at < now − 7 days` (`CredentialResetToken.php:34-37`) | 7 days after expiry | Code constant (link lifetime: `AUTH_PASSWORD_RESET_TTL_MINUTES`, default 30) | token hash only |
| `RestaurantStaffInvitation` / `restaurant_staff_invitations` | `expires_at < now − 30 days` (`RestaurantStaffInvitation.php:37-40`) | 30 days after expiry | Code constant (link lifetime: `AUTH_INVITATION_TTL_HOURS`, default 72) | token hash only |

`EnforceIdempotency.php:90` also deletes an expired record lazily when the same actor reuses the same key.

## 3. Framework clean-up

| What | Command | Period | Set by | Notes |
|---|---|---|---|---|
| `personal_access_tokens` (session token hash, ip, user agent, last use) | `sanctum:prune-expired --hours=24` | 24 h after the token's own `expires_at` | Code constant (the `--hours` in `console.php:14`) | Token lifetimes are config defaults: customer 43200 min (30 d), restaurant 720 min (12 h), admin 480 min (8 h) (`config/auth_security.php:20-24`); admin tokens unused for `AUTH_ADMIN_IDLE_MINUTES` (default 60) are revoked on next use. `config/sanctum.php:53` `expiration` is null on purpose: every token carries its own `expires_at`. Revoked tokens (sign-out) are deleted at once. |
| `failed_jobs` (job payload, exception text) | `queue:prune-failed --hours=720` | 30 days | Code constant | |

## 4. Domain clean-up commands

| Data | Command / code | Rule | Set by |
|---|---|---|---|
| Recent places (`customer_recent_locations`) | `customer:prune-recent-locations` → `RecentLocationService::prune` | unused longer than 90 days | Config default `CUSTOMER_RECENT_LOCATIONS_RETENTION_DAYS` (`config/customer.php:35`) |
| Journeys (`journeys`: origin, destination, route) | `journeys:prune` → `JourneyService::prune` (`JourneyService.php:149-152`) | deleted 60 min after expiry; a journey expires 30 min after planning | Config defaults `JOURNEY_TTL_MINUTES`, `JOURNEY_PRUNE_GRACE_MINUTES` (`config/journey.php:90-91`) |
| Carts (`carts`, `cart_items`) | `carts:prune` → `CartService::prune` (`CartService.php:303-321`) | active carts past `expires_at` + 24 h are closed; EXPIRED / ABANDONED / INVALIDATED carts deleted 30 days after closing. **CONVERTED carts are never deleted.** | Config defaults `CART_GUEST_TTL_HOURS` (168), `CART_CUSTOMER_TTL_HOURS` (720), `CART_EXPIRED_GRACE_HOURS` (24), `CART_CLOSED_RETENTION_DAYS` (30) (`config/cart.php:22-25`) |
| Checkouts (`checkout_sessions`) | `checkouts:prune` → `CheckoutService::prune` (`CheckoutService.php:479-502`) | EXPIRED / STALE / CANCELLED deleted 30 days after closing, **unless a payment attempt points at them**; CONVERTED never | Config default `CHECKOUT_CLOSED_RETENTION_DAYS` (`config/checkout.php:25`) |
| Inbox notifications (`notifications`) | `notifications:prune` (`PruneNotifications.php`) | deleted after `expires_at`; `expires_at` is set at creation to 90 days (365 for security notices) | Config defaults `NOTIFICATIONS_RETENTION_DAYS`, `NOTIFICATIONS_SECURITY_RETENTION_DAYS` (`config/notifications.php:140-141`) |
| Delivery attempts | same command | finished more than 30 days ago, delivery not in flight | Config default `NOTIFICATIONS_DELIVERY_RETENTION_DAYS` |
| Push tokens of revoked devices | same command | `push_token` and its hash set to null 30 days after revocation; **the device row stays** | Config default `NOTIFICATIONS_REVOKED_DEVICE_RETENTION_DAYS` |
| Operational counters in Redis (cart, checkout, payment, order, refund, finance, support, analytics metrics) | Redis TTL | 48 h | Config defaults `*_METRICS_RETENTION_HOURS` |

## 5. Analytics retention (`analytics:prune`, daily)

`AnalyticsPrune.php:46-49`:

| Data | Rule | Set by |
|---|---|---|
| Raw behavioural events (`analytics_events`) | `occurred_at` older than 180 days → deleted | Config default `ANALYTICS_RAW_EVENT_DAYS` (`config/analytics.php:219`) |
| Session facts (`analytics_session_facts`) | `first_event_at` older than 400 days → deleted | Config default `ANALYTICS_SESSION_DAYS` (`:220`) |
| Aggregation runs (`analytics_runs`) | older than 30 days → deleted | Config (fixed) `run_days` (`:221`) |
| Export files (private disk) | READY exports past `expires_at` (24 h after creation): file deleted, row marked EXPIRED (`ReportExportService.php:153-173`) | Config default `ANALYTICS_EXPORT_EXPIRY_HOURS` (`:193`) |
| Export rows | EXPIRED / FAILED rows older than 30 days → deleted; exports not built within a day → FAILED | Config (fixed) `expired_export_days` (`:223`) |
| Quality check results | older than 30 days → deleted by `analytics:check` (`DataQualityService.php:50`) | Config (fixed) `quality_days` (`:222`) |

Not limited: `analytics_order_facts` (rebuilt from orders; removed only with the order) and the daily aggregate
tables (`analytics_daily_*`, counts only).

`analytics:prune --forget-customer=<public id>` removes one customer's pseudonym from events, session facts and (since
Module 39) order facts (`AnalyticsPrune.php:30-45`). It is run by hand; `privacy:erase-customer` does the same as part
of an erasure. See `docs/privacy/analytics-privacy.md` for a limit of this (a later rebuild re-derives the order-fact key).

## 6. Logs

| Channel | File | Rotation | Set by |
|---|---|---|---|
| `single` (the default stack: `LOG_STACK` unset → `single`; local development) | `storage/logs/laravel.log` | **none — the file grows without limit** | Config default `LOG_STACK` (`config/logging.php:65`); the production example sets `structured` (daily, 14 files) |
| `daily` | `storage/logs/laravel-*.log` | 14 files | Config default `LOG_DAILY_DAYS` (`:81`) |
| `structured` (JSON lines) | `storage/logs/app.json.log` | 14 files | Config default `LOG_DAILY_DAYS` (`:92`) |

`.env.production.example` sets `LOG_CHANNEL=stack` and no `LOG_STACK`, so a production install made from it would log
to `single` without rotation. Choosing `daily` / `structured` (or shipping to a log store with its own retention) is a
deployment item. All three channels above (and `stderr`) carry the `RedactSensitiveData` tap; `monthly`, `slack`, `papertrail`, `syslog` and
`errorlog` do not (`config/logging.php:97-149`) and must not be selected without adding it.

## 7. What is never removed today, and why

| Data | Why it stays | Guard in the database |
|---|---|---|
| Orders, order items, order events, pickup credentials, pickup verification attempts | Financial and operational record; erasure masks the customer contact in place (`privacy:erase-customer`) instead of deleting | foreign keys RESTRICT deletion of the customer row |
| Payment attempts, payment webhook events, refunds, refund events, disputes | Financial record; needed for reconciliation and provider disputes | — |
| Ledger transactions / entries, settlement lines | Accounting record | triggers refuse UPDATE and DELETE (`module36_finance.php:143-145, 502`) |
| Settlements, payouts, payout webhook events | Accounting record | — |
| `audit_events`, `security_events` | Evidence of who did what; incident investigation | Module 39 triggers: UPDATE always refused; DELETE refused unless the transaction sets `fotg.audit_purge = 'on'` (`module39_security.php:38-49`). **No purge job exists**; their retention is PENDING. `security:check` verifies the purge setting is off for the session. |
| Support cases, messages, internal notes, case events | Case history; retention is "PENDING LEGAL / PRIVACY APPROVAL" (`config/support.php:164-166`) | triggers refuse UPDATE on messages, notes and events (`module37_support.php:346-348`) |
| Reviews, review revisions, responses, reports | Policy `WITH_ORDER` (`config/reviews.php:79`): kept as long as the order, which is never deleted | — |
| Customer rows | An account is never deleted; a deletion request restricts it and erasure anonymises it | RESTRICT foreign keys from orders, notifications, devices, reviews, support |
| Restaurant and admin user rows, memberships | Accounts are disabled or revoked, never deleted; history points at them | — |
| Customer device rows | History of push deliveries; tokens are nulled (section 4) | — |
| Saved places | Until the customer deletes them (or erasure) | — |
| Legal acceptances of a checkout | Evidence of which document version was accepted | cascade only with the checkout |
| CONVERTED carts and checkouts | Lead to an order | — |
| Pickup slot reservations | Expired by status only | — |
| `sessions` table | Not used by the API (`SESSION_DRIVER=redis` in the production example) | — |

## 8. Gaps and open items

| # | Item | Status |
|---|---|---|
| R-1 | Every period in this document | PENDING BUSINESS APPROVAL; personal-data periods PENDING LEGAL REVIEW |
| R-2 | Retention of `audit_events` / `security_events` (purge job with `fotg.audit_purge`) | OPEN — no job; policy PENDING |
| R-3 | Retention of financial records (orders, payments, refunds, ledger) | OPEN — legal period not set; nothing deleted |
| R-4 | Support case and review retention | OPEN — `PENDING LEGAL / PRIVACY APPROVAL` |
| R-5 | Webhook event rows (`payment_webhook_events`, payout webhooks) | OPEN — no clean-up; hold no payload |
| R-6 | Log rotation in production (`LOG_STACK=structured` in the production example; retention PENDING BUSINESS APPROVAL) | DONE in the template / DEPLOYMENT |
| R-7 | Scheduler running in production | DEPLOYMENT |
| R-8 | Backups: retention of personal data inside backups (erased data survives in old backups) | DEPLOYMENT — no backup exists yet |
| R-9 | Pickup credentials keep their encrypted secret after consumption (status changes only) | OPEN (low) |
| R-10 | `carts.journey_summary` place labels on CONVERTED carts kept for ever | OPEN (low) |
