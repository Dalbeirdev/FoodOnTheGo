# Rollback runbook

Status: Module 41 — 2026-10-16 — written, not executed (there is no production environment). Production = NO.

## 1. When to roll back (decision rules)

Roll back — or stop a rollout — when any of these is true and a forward fix is not available within the agreed window:

| # | Trigger | Signal |
|---|---|---|
| R1 | Customers or staff cannot authenticate (OTP not delivered, staff sign-in failing) | sign-in error rate; `otp:test` fails; support reports |
| R2 | Payment creation fails (customers cannot start a payment) | `/admin/system/payments` OUTAGE, 5xx on payment start |
| R3 | Order loss: verified payments without an order beyond the recovery window | reliability alert `payments_verified_without_order` (critical); `orders:recover` cannot clear them |
| R4 | A migration failed or left the schema inconsistent | migrate exit ≠ 0; invalid indexes; boot errors |
| R5 | Cross-tenant / cross-customer exposure (any IDOR) | security report; `TenantIdorMatrixTest` / `CustomerIdorMatrixTest` failing on the release |
| R6 | Sustained 5xx above the agreed threshold | 5xx request lines (`http.request`, warning), load balancer metrics |
| R7 | Ledger corruption | `finance:verify-ledger` PROBLEMS after the deploy |

Who decides: the release manager with the engineering owner; the business owner is informed immediately. A security
exposure (R5) is also an incident (`docs/security/incident-response.md`).

## 2. What a rollback is — and is not

- **Is:** returning the application code (backend, web, Android distribution) to the previous release, restarting
  workers, and — only if a migration is the cause and it cannot be fixed forward — restoring the database.
- **Is not:** undoing anything that happened at a provider. **External payment / refund / payout state is never rolled
  back.** A payment captured by Razorpay during the bad release stays captured: after the rollback the platform
  reconciles it (`payments:reconcile`, then `orders:recover`, then refunds where the order cannot be made). Refunds that
  were submitted stay submitted and are reconciled (`refunds:reconcile`).
- **Is not:** editing rows by hand, flushing Redis or the database, deleting queues, marking payments paid or refunds
  complete.

## 3. Code rollback (no schema change, or only additive migrations)

1. Put the previous release's code on every API / worker host (the deployment tool's previous artifact).
2. `php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache`.
3. Reload PHP-FPM; `php artisan queue:restart` (workers finish their current job, then start on the old code);
   restart Reverb.
4. Web: re-publish the previous `dist/` (keep at least the last two builds). Android: halt the staged rollout in the
   store / stop distributing the new APK (installed apps keep working against the API — the API is additive, `/api/v1`).
5. Migrations of this codebase are additive (expand → migrate → contract, Module 40 `capacity-plan.md` §6): the
   previous code runs on the newer schema. Do **not** run `migrate:rollback` in production unless the migration itself
   is the problem (§4).

## 4. Database rollback (only for R4 / R7, and only when a forward fix is impossible)

1. Maintenance mode on every host (`php artisan down` with the cache maintenance driver — CF-568).
2. Decide between: (a) the migration's own `down()` (`php artisan migrate:rollback --step=1 --force`; tested on real
   data in Module 41 for the newest migration), or (b) a restore from the pre-deployment backup / PITR to the moment
   before the deploy.
3. With (b): everything written after that moment is lost from the database — **orders and payments of that window
   must be recovered from the providers** (Razorpay payments by receipt, then `payments:reconcile`, `orders:recover`)
   and communicated to the affected customers. Note the window's start and end in the incident record.
4. Integrity (§5) before taking maintenance mode off.

## 5. Verification after any rollback

```bash
php artisan ops:reliability --alerts
php artisan finance:verify-ledger
php artisan finance:reconcile-internal
php artisan payments:reconcile
php artisan refunds:reconcile
php artisan orders:recover
php artisan analytics:aggregate
php artisan analytics:check
```
Then the short smoke list ([smoke-test-plan.md](smoke-test-plan.md) S1–S8) and one test order on a test account.

## 6. Communication

Customers: a banner / status message without technical detail; customers whose payment was taken without an order are
contacted individually (support case per customer). Restaurants: dashboard notice; orders accepted during the incident
stay valid. Record everything in the incident report (`docs/security/incident-response.md` template).
