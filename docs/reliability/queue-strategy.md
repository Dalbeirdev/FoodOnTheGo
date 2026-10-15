# Queue strategy

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources:
- `backend/config/queue.php`
- `backend/app/Jobs/**`
- `backend/app/Listeners/**`
- `backend/routes/console.php`
- the Module 40 code inventory (2026-10-14)
- the Module 40 measurements: `docs/local-review/test-results/m40-load-*.json`, `m40-failure-queue.json`, `m40-failure-redis.json`

Worker counts, timeouts and thresholds are engineering defaults **PENDING BUSINESS APPROVAL**.

## 1. Queues and priority

One Redis connection (`QUEUE_CONNECTION=redis`, `retry_after` 90 s). Five queues, served by every worker **in this order**:

```
php artisan queue:work redis --queue=orders,payments,finance,notifications,default --tries=3 --timeout=60 --sleep=1 --max-jobs=5000 --max-time=3600
```

| Queue | Jobs | Why this position |
|---|---|---|
| `orders` | `FulfillCheckoutJob` (a verified payment becomes an order), `CompleteOrderJob` | a paid customer is waiting for the order |
| `payments` | `ProcessPaymentWebhookEvent`, `SubmitRefundJob` | provider truth and refunds |
| `finance` | `PostFinancialEventJob` (ledger), `SubmitPayoutJob` | money records; minutes of delay are acceptable |
| `notifications` | `SendNotificationDeliveryJob` | push / e-mail; seconds matter, never more than money |
| `default` | `GenerateReportExportJob` (analytics CSV) | a person asked and can wait |

**Fixed in Module 40.** `docs/backend/README.md` and `docs/backend/analytics.md` used to start the worker without `--queue`. A worker started that way serves **only** `default`, so payments, orders, finance and notifications would never be processed. Both now show the full list.

**Production topology (recommendation, PENDING approval):**
- At least 2 workers on separate hosts or processes, supervised (systemd or Supervisor) and restarted on exit.
- One worker dedicated to `orders,payments` so a burst of notifications or exports can never delay a paid order.
- `queue:restart` on every deploy.

## 2. Jobs: tries, timeouts, idempotency

| Job | Queue | tries / backoff | timeout | afterCommit | Second run is safe because… |
|---|---|---|---|---|---|
| `FulfillCheckoutJob` | orders | 3 / 30, 120 s (and releases itself while the fulfilment is Retrying) | worker (60) | yes | fulfilment row locked by `checkout_public_id`; an existing order is adopted, never created again; unique `orders.checkout_public_id` |
| `CompleteOrderJob` | orders | 3 / 15, 60 s | worker | yes | order row locked; a no-op when already COMPLETED |
| `ProcessPaymentWebhookEvent` | payments | 5 / 10, 30, 120, 300 s | worker | yes | stored event unique per provider reference / payload hash; PROCESSED / IGNORED events return early; reconciliation under a cache lock and a row lock |
| `SubmitRefundJob` | payments | 1 | worker | yes | cache lock + APPROVED → SUBMITTING inside the transaction + the same idempotency key on every try; an unknown outcome is reconciled, never resent blindly |
| `PostFinancialEventJob` | finance | 1 | worker | yes | unique `posting_key`: a duplicate posting is recognised as already posted |
| `SubmitPayoutJob` | finance | 1 | worker | yes | row lock, Required → Submitting, fixed `idempotency_key`; a timeout becomes UNKNOWN (reconciled, never resent) |
| `SendNotificationDeliveryJob` | notifications | 1 (the service re-dispatches, 5 attempts) | worker | **no** (dispatched after the orchestrator's transaction) | `ShouldBeUniqueUntilProcessing` + an atomic lease UPDATE (Queued → Sending; exactly one row or return) |
| `GenerateReportExportJob` | default | 1 | **80 s** (Module 40; was 300) | yes | atomic claim QUEUED → PROCESSING |

The money and order jobs have no `failed()` handler by design. A failure leaves the record in a state a scheduled sweep finds again (`payments:reconcile`, `orders:recover`, `refunds:reconcile`, `finance:post-pending`, `notifications:recover`). Retry budgets end in REVIEW_REQUIRED, which brings in a person.

**Fixed in Module 40 — `retry_after` vs timeout.** `GenerateReportExportJob` had `$timeout = 300`, more than the connection's `retry_after` of 90 s. A slow export was handed to a second worker while the first was still building it:
- a spurious "max attempts exceeded" row was written to `failed_jobs`;
- the export stayed PROCESSING for a day if the first worker died.

The timeout is now 80 s (below `retry_after`). A new `failed()` handler closes the export as FAILED (`generation_timed_out`) at once. Test: `AnalyticsExportTest::test_an_export_the_worker_gave_up_on_is_closed_as_failed_at_once_and_its_timeout_stays_below_retry_after`.

**Rule for every new job:** `$timeout` must be below `retry_after` (90 s). A job that needs longer belongs on its own connection with a longer `retry_after`.

## 3. Scheduler

21 entries (`routes/console.php`). Module 40:
- **`onOneServer()` on every entry.** A second scheduler host (two app servers both running `schedule:run`) skips what the first already started. This uses the shared cache lock store (Redis).
- **Overlap locks expire:** 10 minutes for the per-minute and five-minute sweeps, 2 hours for the hourly ones.
  - The framework default is **24 hours**: a scheduler killed while `payments:reconcile` was running would have stopped the money sweeps for a day.
  - The sweeps take their own row and cache locks, so an expired overlap lock never lets the same work run twice.

Test: `SchedulerResilienceTest`.

Commands run in sequence inside one `schedule:run`. The per-minute sweeps each take about 0.6 s on the medium dataset (`m40-integrity-perf-medium.json`). Production must run `schedule:run` every minute from exactly one cron or systemd timer per host (`schedule:work` on a single host is acceptable).

## 4. Failure behaviour

Results of `tests/performance/failure.mjs queue` and `… redis` (`m40-failure-queue.json`, `m40-failure-redis.json`) are summarised in `provider-resilience.md` §4.

What the design guarantees:
- **Worker stopped:** the customer's payment is verified and recorded. The order is created as soon as a worker runs again, or by the `orders:recover` / `payments:reconcile` sweep.
- **Worker killed mid-job:** the job is reserved; after `retry_after` it is handed to another worker. Idempotency (above) makes the second run safe.
- **Queue lost (Redis flushed or restarted empty):** every job is a consequence of a committed PostgreSQL state, and the sweeps rebuild the work from that state. **Deleting a queue is never a fix.** It is a failure the sweeps tolerate.
- **Failed jobs:** `failed_jobs` (database). The reliability view reports the last 24 hours and alerts. Retention is 30 days (`queue:prune-failed --hours=720`).
  - Locally, all 96 failed jobs found (2026-10-08 → 2026-10-14) were `FulfillCheckoutJob` runs whose payment the end-to-end suites had deleted during their clean-up (REL-012). They are test noise, not a product defect.
  - Payments are never deleted outside tests, and failing loudly on a missing payment is correct.

## 5. Saturation and monitoring

**Measured:** queue depth and the age of the oldest waiting job are sampled every 5 s during every load run (`resources.queue_depth_max`, `oldest_job_age_s_max` in `m40-load-*.json`).

**Alerts** (`GET /admin/system/reliability`, `php artisan ops:reliability`; thresholds in `config/observability.php`):

| Measure | Warning | Critical |
|---|---|---|
| Jobs waiting in one queue | 500 | 5,000 |
| Oldest waiting job | 60 s | 300 s |
| Jobs failed in the last 24 h | 1 | 25 |
| Verified payments with no order after 5 minutes | — | 1 |

## 6. Open items

| Id | Item | Severity |
|---|---|---|
| REL-023 | `SendNotificationDeliveryJob` is not `afterCommit` (safe today: dispatched after the transaction; fragile if a caller wraps it in another) | low |
| REL-027 | No worker supervision config in the repository (systemd / Supervisor units belong to the deployment) | production prerequisite |
| REL-028 | A permanently failed `ProcessPaymentWebhookEvent` is surfaced only through `failed_jobs` and the sweep, not a notification to a person | medium |
