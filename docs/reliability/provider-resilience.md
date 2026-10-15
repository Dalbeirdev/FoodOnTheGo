# Provider resilience and failure behaviour

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources:
- `backend/config/{services,payments,refunds,finance,notifications,journey,broadcasting,mail}.php`
- `backend/app/Services/{Journey,Payment,Refund,Finance/Payout,Notifications,Sms,Otp,Auth}/**`
- `backend/app/Listeners/BroadcastOrderUpdate.php`
- the Module 40 code inventory (read-only review, 2026-10-14)
- failure injection: `tests/performance/failure.mjs` → `docs/local-review/test-results/m40-failure-*.json` (second pass) and `m40-failure-first-pass.txt`

**Real providers:** Razorpay, FCM, SMS gateways and Google routing are implemented but **not configured locally**. Every measurement here uses the DEVELOPMENT providers. Real-provider behaviour is **PENDING PROVIDER VERIFICATION**: never claimed.

## 1. Principles in the code

1. **No provider call inside a database transaction** on any money path:
   - state is prepared in one transaction;
   - the call is made with no transaction open;
   - the outcome is recorded in another transaction.
   
   This applies to payment creation, refund submission, payouts, push, routing and SMS. Exceptions are listed in §5.
2. **Non-idempotent calls are never repeated blindly:**
   - creating a payment order (unique receipt) or a refund (`X-Refund-Idempotency` = receipt) is not retried in-process;
   - creating a payout (fixed `idempotency_key`) is not retried either;
   - an unknown outcome (timeout, 5xx) becomes UNKNOWN and is **reconciled** by reading the provider, never resent.
   
   Read calls are retried (2 attempts, exponential backoff with jitter).
3. **Sweeps restore what a failure interrupted:**

   | Sweep | Schedule | What it recovers |
   |---|---|---|
   | `payments:reconcile` | every minute | unsettled attempts, unprocessed webhooks, verified payments without an order |
   | `orders:recover` | every minute | due fulfilments; verified payments with no order after 60 s |
   | `refunds:reconcile` | every minute | due refund work |
   | `finance:post-pending` | every minute | missing ledger postings; due payout checks |
   | `notifications:recover` | every minute | stranded deliveries |
   
   Each ends in REVIEW_REQUIRED (a person) after its budget.
4. **Health is derived from the platform's own calls** (`/admin/system/providers`, `/payments`, `/notifications`, `/realtime`). There is **no circuit breaker**: a failing provider is called again on the next request (REL-038). The only automatic pause is push, after an authentication failure (300 s).

## 2. Timeouts and retries

| Provider / operation | Timeout (connect) | Retries | On failure |
|---|---|---|---|
| Places / routing (Google; DEVELOPMENT locally) | `PLACES_TIMEOUT` 5 s / `ROUTING_TIMEOUT` 8 s (connect ≤ 3 s) | 2 attempts, 200 ms × 2ⁿ + ≤ 150 ms jitter; only timeouts / unavailable (429 not retried) | 503 `places_temporarily_unavailable` / `routing_temporarily_unavailable` (with `retry_after`), 422 `route_not_found`; route cached 300 s, places 60 s |
| Payment: create order | `PAYMENT_TIMEOUT` 15 s (connect ≤ 5 s) | none; lookup by receipt after a timeout | UNKNOWN + reconcile (503 `payment_initialization_unknown`), or FAILED + checkout released (503 `payment_provider_unavailable`) |
| Payment: reads | same | 2 attempts, 200 ms × 2ⁿ + ≤ 100 ms | the sweep backs off 60 s → 8 h, then REVIEW_REQUIRED |
| Refund: create | same | none in-process; the same idempotency key is resubmitted at most `REFUND_MAX_UNKNOWN_RESUBMITS` = 2 when the provider has nothing for it | not sent (429) → retried after 60 / 300 / 900 / 3,600 s, then REVIEW_REQUIRED |
| Payout | DEVELOPMENT provider only; live payouts disabled | never repeated; fixed key | UNKNOWN → reconciled by key; nothing found → a person |
| Push (FCM) | `FCM_TIMEOUT_SECONDS` 10 s, **no connect timeout** (REL-015) | 5 attempts, 30 s × 2ⁿ (≤ 900 s) + jitter; 429 honours Retry-After | UNREGISTERED → device revoked; auth failure → push paused 300 s |
| SMS / WhatsApp OTP | `SMS_TIMEOUT` / `WHATSAPP_TIMEOUT` 10 s, connect 5 s | `retry(2, 300 ms)` on connection errors, **including read timeouts** (REL-016: a duplicate OTP message is possible) | fallback SMS driver; 503 `otp_delivery_failed` |
| Truecaller | 8 s, **no connect timeout** (REL-015) | none | 503 `truecaller_unreachable` |
| E-mail (SMTP) | **none** (`timeout => null`, REL-014) | notification e-mail: 5 attempts | invitations are sent synchronously **inside** the invite transaction (REL-014) |
| Realtime (Reverb, from the app) | **2 s, connect 1 s**. Fixed in Module 40: a duplicate config key had replaced them with the framework's 10 s / 30 s (REL-007) | none | caught, counted, logged; clients fall back to REST resync + polling |

## 3. Graceful-degradation matrix (what a customer / restaurant / admin experiences)

| Failure | Customer | Restaurant | Admin | Money |
|---|---|---|---|---|
| Routing / places provider down | route planning answers 503 with `retry_after`; restaurant browsing by city works | unaffected | provider health shows OUTAGE | unaffected |
| Payment provider down | payment start answers 503 `payment_provider_unavailable` in ≈ 1.4 s; **the cart is not left locked**; browsing works | unaffected | payment health | nothing charged; a provider that answers late is reconciled |
| Push provider down | in-app inbox and realtime still update; push retried with backoff | unaffected | notification health | — |
| Realtime server down | order screen falls back to resync / polling | **every order action still succeeds**, about 1 s slower (two bounded broadcast attempts) | `/admin/system/realtime`: `UNAVAILABLE` with the reason | — |
| Queue worker down | payment verified; the order appears when a worker runs (`orders:recover` after 60 s if the job is lost) | the order arrives late | reliability view: queue depth / age alerts | verified payments recorded; ledger posting late, never lost |
| Redis down | **every API route except `/live` answers 500** (the rate limiter, RBAC cache, queue and locks need Redis) | same | same; `ops:reliability` reports `dependency.redis` | nothing half-done: work that needs a lock or the queue does not start |
| PostgreSQL refusing connections | `/live` 200, `/health` 503, every other route a fast 500 (≈ 0.1 s) | same | same | nothing written |
| PHP processes killed | connection errors for the seconds until they restart | same | same | exactly-once preserved |

## 4. Failure injection results (local, DEVELOPMENT providers, `foodonthego_perf`)

| Scenario | What was done | Observed | Result |
|---|---|---|---|
| **queue** | 20 payments completed with **no worker** | 20 × VERIFIED / ORDER_PENDING, 20 order jobs and 20 finance jobs waiting | a worker created **20/20 orders in 3.1 s**; every payment has exactly 1 order and 1 `PAYMENT_VERIFIED` posting; ledger OK |
| **crash** | 12 payments, then a worker killed **while holding a job** (reserved set = 1; 5 orders done, 7 pending) | the reserved job was re-delivered after the connection's `retry_after` | queue empty after **93 s**; **12/12 orders, each exactly once**; 0 failed jobs; ledger OK |
| **restart** | 4 VUs buying; all 4 PHP processes killed at 11 s, restarted at 16 s | 0 ok / 16–20 failed per second for 5 s (transport errors), pool back in 1.3 s | 32 purchases paid → **32 orders**, none duplicated; probes all 200 afterwards; ledger OK |
| **redis** (perf-only Redis on port 6390) | 3 payments queued, Redis killed, then restarted **empty** (= a flush) | Redis down: every route 500 after ≈ 2 s (connect timeout); **`/live` 200** (Module 40 change; the first pass, before it, answered 500 — `m40-failure-first-pass.txt`). Redis back: everything 200 without restarting the app | the 3 lost order jobs: sweeps inside the 60 s grace did nothing (as designed); **past the grace `orders:recover` picked up 3 → 3 orders**, each exactly once; the missing ledger postings were posted by `finance:post-pending`; ledger OK |
| **database** | `ALTER DATABASE … ALLOW_CONNECTIONS false` + backends terminated; then 4 rounds of connections killed during 6 concurrent purchases | refusing: `/live` 200, `/health` 503, others 500 in ≈ 0.1 s; back: all 200 without a restart | killed mid-purchase: every purchase that reached payment has exactly 1 order and 1 posting; ledger OK (3 purchases of that run were refused `cart_locked_for_payment` because of the redis scenario's then-unrecovered payments — the cart rule working) |
| **connections** | PostgreSQL filled to 98 client connections (max 100, 3 reserved for superusers: no slot left for the application role) | `/live` 200, `/health` 503, every other route a fast 500 (≈ 70–200 ms, no hang) | slots freed → all 200 **without an application restart**; a new purchase became an order in 4.1 s (`m40-failure-connections.json`; a first attempt counted PostgreSQL's background processes as connections and never filled the database — `m40-failure-connections-not-full.json`) |
| **payment** | development provider `PROVIDER_DOWN`, 3 purchases, `PROVIDER_UP`, 3 purchases | 503 `payment_provider_unavailable` in 1.4–1.7 s; cart unlocked; other routes 200 | after: 3/3 orders; ledger OK |
| **reverb** | 4 orders through the kitchen with the realtime server up, then down | transitions all 200: p50 216 ms up, **1,215 ms down** | the admin view reports `UNAVAILABLE (ConnectException)`; with the old 10 s / 30 s timeouts (REL-007) a transition would have cost ≥ 20 s |
| **concurrency** | 6 payments completed 6× at once + a duplicated webhook; 5 orders accepted / made ready by 4 simultaneous requests | 42 answers, all 200; kitchen 40 answers, all 200 | **1 order and 1 posting per payment; 1 event per transition** |
| **race** (`run.mjs --profile race`) | 20 orders × 8 simultaneous pickup verifications | before the Module 40 fix: 102 × 500 (deadlock), p95 5.3 s; after: 20 × 200 + 140 × 409, p95 370 ms | exactly-once 20/20 both times (`database-performance.md` §5) |

**Not injected:**
- the real providers (no credentials);
- storage failure. Exports and attachments use the local disk. An export that fails is closed as FAILED and its partial file deleted (code).
- network partition between hosts (single host).

## 5. Gaps (open)

| Id | Gap | Severity |
|---|---|---|
| REL-010 / REL-019 | Redis is a hard dependency of every API route (rate limiter, RBAC cache). Only `/live` is independent now. A fail-open limiter would keep reads alive in a Redis outage, at the cost of unthrottled OTP and login. **Decision for the owner.** | high (availability) |
| REL-014 | Admin / staff invitation e-mails sent synchronously inside the invite transaction; SMTP has no timeout | medium |
| REL-015 | FCM (and its OAuth token request) and Truecaller have no connect timeout | low |
| REL-016 | SMS / WhatsApp: a read timeout is retried, so a duplicate OTP message is possible | low |
| REL-017 | `payments:reconcile` / `refunds:reconcile` cache locks (30 s) can expire before a worst-case provider gather (2 reads × 2 attempts × 15 s); the row lock still serialises the state change | low |
| REL-028 | A permanently failed webhook job reaches a person only through `failed_jobs` and the sweep | medium |
| REL-038 | No circuit breaker: during an outage every request still waits for the provider's timeout (payment start ≈ 1.4 s with the development provider; up to 15 s with a hanging real one) | medium |
| — | Avatar file deletion inside a transaction (local disk today; a network disk would make it a network call under a lock) | low |
