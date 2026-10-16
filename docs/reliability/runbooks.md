# Operational runbooks

Status: Module 40 — 2026-10-14 — local only; production = NO

Every runbook uses the platform's own signals:
- `GET /api/v1/admin/system/reliability` and `php artisan ops:reliability [--alerts]` (`observability.md`);
- the per-domain `/admin/system/*` views;
- `http.request` log lines (route pattern, status, duration, query count, `request_id`).

Severity and escalation contacts are **PENDING BUSINESS APPROVAL**.

**Never, in any runbook:**
- flush Redis or the database;
- delete a queue;
- mark a payment paid or a refund complete by hand;
- edit ledger rows;
- switch a provider to live mode as a workaround;
- disable authorization, validation or rate limits to "get things moving".

The integrity commands named below report. Where they act (sweeps), they act only on what is due and are idempotent.

**Post-incident checklist (every runbook ends with it):**

```bash
php artisan ops:reliability --alerts
```

```bash
php artisan finance:verify-ledger
```

```bash
php artisan finance:reconcile-internal
```

```bash
php artisan payments:reconcile
```

```bash
php artisan orders:recover
```

```bash
php artisan analytics:aggregate
```

```bash
php artisan analytics:check
```

Then one test order through purchase → kitchen → pickup on a test account.

---

## RB-01 API answering 5xx

| | |
|---|---|
| Symptoms | `http.request` lines at `warning` level with status ≥ 500 (the exception itself is the `error` entry, same `request_id`); load-balancer 5xx rate; customers see "something went wrong" |
| Metrics / logs | reliability view `dependencies`, `database`, `redis`; the exception after the `http.request` line (same `request_id`) |
| Impact | depends on the route pattern: money routes first (checkouts, payments, refunds, pickup verify) |
| First checks | 1. `/api/v1/live` (process) and `/health` (database, Redis). 2. Deploy in the last hour? 3. The top route patterns in the 5xx lines. 4. `ops:reliability --alerts`. |
| Safe actions | roll back the last deploy (release switch + `queue:restart`); scale the PHP pool if the cause is saturation (latency first, then 5xx); fix the dependency (RB-02 / RB-03) |
| Do NOT | turn on `APP_DEBUG` in production; clear `config:cache` without re-caching |
| Escalation | 5 min of 5xx on money routes → incident (PENDING contacts) |
| Recovery verified | 5xx rate back to baseline; post-incident checklist clean |

Measured locally: PHP processes killed under load caused 5 s of transport errors, then full recovery with every paid purchase producing exactly one order (`m40-failure-restart.json`).

## RB-02 Database slow or unavailable

| | |
|---|---|
| Symptoms | `/health` 503; 500 on every route except `/live`; or rising latency with `db_ms` high in `http.request` lines |
| Metrics / logs | reliability `database`: connections %, waiting on lock, longest statement / transaction, deadlocks; `pg_stat_statements` (top by total time); PostgreSQL log (`log_lock_waits`) |
| Impact | everything; no partial money state (transactions) |
| First checks | 1. Reachable? Connection count vs `max_connections` / pooler. 2. Lock waits and the oldest transaction (`pg_stat_activity`). 3. A new slow statement (`pg_stat_statements` reset window). 4. Disk / CPU of the database host. |
| Safe actions | cancel (not terminate) a runaway reporting query (`pg_cancel_backend`); terminate a session idle in transaction for minutes; scale the instance; add an index CONCURRENTLY after EXPLAIN evidence; fail over to a replica (managed service) |
| Do NOT | `VACUUM FULL` or long locks during service; kill the backend of a money transaction mid-flight unless it is idle in transaction; restore over the live database |
| Escalation | unavailable > 5 min → DR runbook R1 decision (`disaster-recovery.md`) |
| Recovery verified | `/health` 200; locks 0; post-incident checklist |

Measured: refusing connections → `/live` 200, `/health` 503, other routes fast 500. Back → 200 without an application restart. Connections killed mid-purchase → exactly-once kept (`m40-failure-database.json`).

## RB-03 Redis unavailable

| | |
|---|---|
| Symptoms | every API route except `/live` answers 500 after ≈ 2 s (rate limiter, RBAC cache); queue dispatch fails; `ops:reliability` shows `dependency.redis` critical |
| Metrics / logs | `Predis\…\Connection` exceptions; reliability `redis` section `null` |
| Impact | the whole API (reads included) until Redis returns; queued jobs lost if Redis restarts empty |
| First checks | 1. Redis process / host / memory (OOM?). 2. `maxmemory-policy` (must be `noeviction`). 3. Network between app and Redis. |
| Safe actions | restart or fail over Redis. **No application restart is needed.** Then let the sweeps run: `orders:recover` (60 s grace), `finance:post-pending`, `notifications:recover`, `payments:reconcile`, `refunds:reconcile`. |
| Do NOT | `FLUSHALL` "to clean up"; delete queue keys; replay jobs by hand |
| Escalation | > 5 min → incident; decide REL-019 (fail-open limiter) for the future |
| Recovery verified | measured: Redis restarted empty → 3 lost order jobs → 3 orders after the grace, each exactly once (`m40-failure-redis.json`) |

## RB-04 Queue backlog

| | |
|---|---|
| Symptoms | reliability `queue_depth` / `queue_oldest_s` alerts; customers see "your order is being created" longer; `payments_verified_without_order` alert |
| Metrics / logs | per-queue waiting / delayed / reserved / oldest age; `failed_jobs` (last 24 h) |
| Impact | `orders` / `payments` queue = paid customers waiting; `notifications` = late pushes; `default` = late exports |
| First checks | 1. Workers running? (`ps`, supervisor). 2. A poison job failing repeatedly (`failed_jobs` exception)? 3. A provider timing out inside jobs (provider health)? 4. CPU of the worker host. |
| Safe actions | start / scale workers (dedicated `orders,payments` worker first); `queue:restart` after a deploy; read the failed job's exception before any retry |
| Do NOT | `queue:clear`; `queue:retry all` on money jobs without reading why they failed; raise `--tries` on single-try money jobs |
| Escalation | `payments_verified_without_order` critical > 5 min → incident |
| Recovery verified | depth 0; oldest age 0; every verified payment has its order (`ops:reliability`). Measured: 20 queued order jobs → 20 orders in 3.1 s; a worker killed holding a job → re-delivered after 90 s, 12/12 orders exactly once (`m40-failure-queue.json`, `m40-failure-crash.json`). |

## RB-05 Razorpay (payment provider) outage

| | |
|---|---|
| Symptoms | payment start answers 503 `payment_provider_unavailable` / `payment_initialization_unknown`; `/admin/system/payments` health degraded |
| Metrics / logs | payment attempts UNKNOWN, `next_reconcile_at`; provider error kinds in logs (never payloads) |
| Impact | no new payments; existing orders unaffected; carts are not left locked (measured) |
| First checks | provider status page; our credentials / mode (TEST vs LIVE — never switched as a fix); network egress |
| Safe actions | none in the database. UNKNOWN attempts are reconciled automatically when the provider answers. Communicate (`incident-response.md`). Optionally pause ordering for a market (market status, Module 22). |
| Do NOT | mark attempts paid; create orders by hand; resend refund requests by hand; switch to live mode |
| Escalation | > 15 min at a lunch peak → business decision on pausing ordering |
| Recovery verified | new purchases succeed; no UNKNOWN attempts older than the reconcile window; `payments:reconcile` clean |

Measured with the DEVELOPMENT provider: 503 in ≈ 1.4 s, recovery immediate (`m40-failure-payment.json`). Razorpay itself: PENDING PROVIDER VERIFICATION.

## RB-06 Google Routes / Places outage

| | |
|---|---|
| Symptoms | `POST /journeys/plan` 503 `routing_temporarily_unavailable` (with `retry_after`); `/admin/system/providers` OUTAGE |
| Impact | journey planning unavailable; browsing restaurants by city, menus, ordering unaffected |
| First checks | provider status; quota / key (MISCONFIGURED is logged as error); our timeout and retry settings |
| Safe actions | none in data. Cached routes keep serving for 300 s. Communicate in the app if long. |
| Do NOT | show a fabricated route or invented detour times (Module 26 rule) |
| Recovery verified | provider health OPERATIONAL; a plan succeeds |

Live Google: never configured (PENDING).

## RB-07 FCM (push) outage

| | |
|---|---|
| Symptoms | `/admin/system/notifications` push health; deliveries RETRY_SCHEDULED / FAILED; push paused after an auth failure (300 s) |
| Impact | no pushes; the in-app inbox, order screen and realtime still update; orders unaffected |
| First checks | provider status; service-account key validity (auth failure); token revocations (UNREGISTERED) |
| Safe actions | none: deliveries retry with backoff (5 attempts, ≤ 900 s); `notifications:recover` re-queues stranded deliveries |
| Do NOT | mass-resend notifications; delete devices in bulk |
| Recovery verified | deliveries SENT again; no stranded SENDING rows (lease 120 s) |

FCM itself: PENDING CONFIGURATION (Module 33).

## RB-08 Realtime (Reverb) outage

| | |
|---|---|
| Symptoms | `/admin/system/realtime` `UNAVAILABLE`; clients on polling; order actions ≈ 1 s slower (measured) |
| Impact | screens update by polling (seconds instead of sub-second); no data impact |
| First checks | Reverb process; file-descriptor limit (connection ceiling); TLS / proxy WebSocket upgrade |
| Safe actions | restart Reverb; clients reconnect (measured: 230 sockets back in 1.7 s) |
| Do NOT | disable broadcasting in config in a hurry (it is already bounded: 2 s / 1 s timeouts) |
| Recovery verified | realtime health OK; broadcast counters rising; resync counters back to normal |

## RB-09 Backup failure

| | |
|---|---|
| Symptoms | the backup job's exit code / alert (managed-service backup status); missing dump in storage; the last restore test older than a month |
| Impact | none today; RPO grows until fixed |
| First checks | storage space / permissions; credentials of the backup role; database size vs backup window |
| Safe actions | take a manual backup now (`pg_dump -Fc` or a managed snapshot); verify it by a restore into a scratch database (`restore-check.mjs` shape: counts + integrity commands) |
| Do NOT | delete old backups to make room before a new one is verified |
| Recovery verified | a new backup exists **and has been restored**; integrity commands clean on the restored copy |

## RB-10 Ledger imbalance

| | |
|---|---|
| Symptoms | `finance:verify-ledger` PROBLEMS; reliability `ledger_problems` critical; `/admin/finance` reconciliation differences OPEN |
| Metrics / logs | the finance run's `result` (which check: unbalanced transactions, duplicate / orphan postings, payable mismatches, inverted accounts, settlement / payout consistency) |
| Impact | settlements and payouts must not proceed for the affected locations |
| First checks | 1. Which check and which keys (`reconciliation_differences`). 2. Recent deploy touching posting code? 3. A restore or data repair in the window? |
| Safe actions | hold settlements / payouts for the affected locations (finance holds, Module 36); investigate with read-only queries; correct **only by a new compensating posting** through the finance admin tools (adjustment with four-eyes when enabled) |
| Do NOT | update or delete ledger rows (append-only; the triggers refuse it outside local); re-run settlement generation over the problem |
| Escalation | always: finance owner + engineering |
| Recovery verified | `finance:verify-ledger` OK; the difference marked settled by the next run; payable figures match |

Measured: `finance:verify-ledger` OK after every load, failure and restore run in Module 40. It caught the first synthetic dataset's missing payable lines (18,750 problems), which is how the generator bug was found (`m40-integrity-perf-medium.json` history in `load-test-plan.md` §2).

---

Module 41 (2026-10-15) adds the five domain runbooks below. The same rules apply: use only platform signals. Nothing is marked paid, created, refunded or picked up by hand. Every recovery goes through the domain's own service and is audited.

## RB-11 Payment stuck (customer paid, the screen still waits)

| | |
|---|---|
| Symptoms | A customer reports "money taken, still processing". Payment attempts sit in `PAYMENT_PENDING` / `UNKNOWN` / `REVIEW_REQUIRED` longer than the `observability.alerts` thresholds. There is no dedicated alert: the attention filter lists them |
| Metrics / logs | `GET /admin/payments?filter[attention]=1`. The attempt's timeline (`GET /admin/payments/{payment}`): provider order, webhook events, verification tries. The `payments:reconcile` output |
| Impact | One customer is waiting. A duplicate payment happens if the customer is told to "just pay again" |
| First checks | 1. Did the provider capture the payment (provider dashboard, by the attempt's receipt)? 2. Did the webhook arrive (`payment_webhook_events`)? 3. Are the payments queue worker and the scheduler running (`payments:reconcile` every minute)? |
| Safe actions | `POST /admin/payments/{payment}/reconcile` (reason required, audited) asks the provider again; otherwise wait for the sweep. Tell the customer "payment being confirmed — do not pay again". A REVIEW_REQUIRED attempt (amount / currency mismatch, duplicate capture) goes to a refund decision (RB-12), never to a manual "paid" |
| Do NOT | Mark the attempt VERIFIED; create an order by hand; ask the customer to pay a second time while the first payment is unknown |
| Escalation | One customer unknown for more than 15 min: support lead. Several customers: engineering, and RB-05 (provider outage) |
| Recovery verified | The attempt is VERIFIED, or FAILED with no money taken. A VERIFIED attempt has an order (RB-13) |

## RB-12 Refund stuck

| | |
|---|---|
| Symptoms | Refunds sit in `PROCESSING` / `REQUIRED` / `APPROVED` longer than `observability.alerts.refund_stuck_hours`. `REVIEW_REQUIRED` refunds appear in `GET /admin/refunds/queues`. The reliability view raises `refunds_stuck` |
| Metrics / logs | The refund's timeline (`GET /admin/refunds/{refund}`), its attempts and provider references. The `refunds:reconcile` output |
| Impact | A customer waits for money. The customer sees "Taking longer — our team is on it", never "failed" |
| First checks | 1. Was it sent (an attempt with a provider id)? 2. Does the provider show it processed or failed? 3. Is the second approval missing (`APPROVED` needs `admin.refunds.issue`)? 4. Is a dispute open on the payment (`REVIEW_REQUIRED: DISPUTE_OPEN`)? |
| Safe actions | `POST /admin/refunds/{refund}/reconcile` asks the provider again (reason required). `…/execute` or `…/retry` with `admin.refunds.issue` resends with the same idempotency key, so the provider cannot refund twice. A FAILED refund is replaced by a **new** refund once the cause is fixed |
| Do NOT | Set a refund to REFUNDED by hand; refund outside the platform and then "close" the record; change the order's status to explain the refund (order and refund states are independent) |
| Escalation | REVIEW_REQUIRED: finance owner. Many refunds waiting: engineering (if the provider is down, RB-05) |
| Recovery verified | The refund is REFUNDED with the provider reference. The ledger posting exists (`finance:verify-ledger` OK). The customer got the refund notification only after the provider confirmed |

## RB-13 Verified payment without an order

| | |
|---|---|
| Symptoms | A payment is VERIFIED with fulfilment `ORDER_PENDING` for more than 60 s, or `RECOVERY_REQUIRED`. The reliability view raises `payments_verified_without_order` (critical). `GET /admin/order-fulfillments?filter[attention]=1` lists them |
| Metrics / logs | The fulfilment row (state, last error code, tries, next try). The `orders:recover` output. The orders queue depth and oldest job age |
| Impact | The customer paid but the restaurant has nothing to cook. The customer sees "payment received, order not confirmed yet, do not pay again" |
| First checks | 1. Is the orders queue worker running (`--queue=orders,…`)? 2. What is the fulfilment's error: no capacity at the pickup time, restaurant suspended or closed, market paused, or a technical failure? 3. Is the scheduler running (`orders:recover` every minute)? |
| Safe actions | Start or restart the worker. Run `php artisan orders:recover`. Use `POST /admin/order-fulfillments/{fulfillment}/recover` (reason required, audited; the order is judged again from scratch). When the order can never be made (restaurant closed for good, no capacity for hours), issue a **refund** (RB-12) and tell the customer |
| Do NOT | Insert an order row; change the payment state; tell the customer the order exists before the backend has created it |
| Escalation | Any single case older than 10 min: support lead and engineering. Several cases: RB-04 (queue backlog) |
| Recovery verified | The fulfilment is `ORDER_CREATED` with an order number, or a refund went from REQUIRED to REFUNDED. The reliability alert is cleared |

## RB-14 Restaurant cannot accept orders

| | |
|---|---|
| Symptoms | A restaurant reports "Accept does nothing" or an error. Orders in `WAITING_FOR_RESTAURANT` keep ageing. The dashboard shows a "connection lost" banner |
| Metrics / logs | `http.request` lines for `POST restaurant/orders/{order}/accept` (status, request id). The staff member's role assignment and locations. `GET /admin/system/realtime` |
| Impact | Customers wait. If nothing is accepted before the pickup time, the order must be rejected and refunded |
| First checks | 1. Read the answer code: 403 = the staff member lacks `restaurant.orders.manage` for that location (role / location scope); 409 = another staff member already moved the order (reload); 401 = signed out or token expired; 5xx = RB-01. 2. Is only realtime down? The board still works by polling (RB-08) |
| Safe actions | The restaurant owner, or an administrator through restaurant staff management, fixes the role or location assignment. The staff member signs in again and reloads the board. Pausing new orders (`accepting orders` off) while the restaurant recovers is the restaurant's decision |
| Do NOT | Accept orders "on behalf of" the restaurant from the admin tools; grant a broader role than needed to "make it work" |
| Escalation | Restaurant success / support lead; engineering for 5xx |
| Recovery verified | The order moves to ACCEPTED, with the staff member as the actor in the order timeline |

## RB-15 Pickup verification problem at the counter

| | |
|---|---|
| Symptoms | The scanner or the manual code entry shows an error while the customer is at the counter |
| Metrics / logs | The answer code. `GET /admin/pickup-attempts`: attempts, refusals by reason, locked credentials, staff with 5 or more refusals. Security events `PICKUP_REPLAY_ATTEMPT` and `PICKUP_LOCATION_UNAUTHORIZED` |
| Impact | A customer cannot collect a paid order |
| First checks | Act on the answer code:<br>• `order_not_ready_for_pickup`: mark the order READY first.<br>• `pickup_location_unauthorized`: the scanner is set to another location; choose the order's location.<br>• `pickup_credential_invalid`: wrong code or a foreign QR; ask the customer to open the order screen.<br>• `pickup_attempts_exceeded`: 5 wrong codes, locked for 15 min.<br>• `pickup_credential_revoked` / `expired`: the customer re-opens the order (reissue: `POST /customer/orders/{order}/pickup-credential/reissue`, 3 per hour).<br>• `pickup_already_verified`: the order was already picked up; the timeline shows by whom and when |
| Safe actions | Ask the customer to show the code on their own device (the manual code is the fallback when a QR will not scan). The customer reissues the credential from the order screen. After a lock, wait out the 15-minute window |
| Do NOT | Hand over food without a successful verification; read the customer's code over the phone to a third party; mark an order PICKED_UP from the admin tools (there is no such action, by design) |
| Escalation | Repeated refusals for one staff account (possible misuse): security and the restaurant owner. System-wide errors: engineering |
| Recovery verified | The order timeline shows `PICKED_UP` then `COMPLETED`, with the verifying staff member |
