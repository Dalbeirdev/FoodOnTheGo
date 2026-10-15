# Database performance

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources:
- PostgreSQL 18.6 on `foodonthego_perf`, with `pg_stat_statements` and `track_io_timing` enabled locally for Module 40;
- `tests/performance/{baseline,query-profile,write-profile,run}.mjs`;
- results `docs/local-review/test-results/m40-{baseline-*,query-profile-*,write-profile-*,load-*}.json`;
- the PostgreSQL server log (`log_lock_waits = on`);
- `backend/database/migrations/2026_10_20_100000_module40_performance_indexes.php`.

Thresholds and proposals are engineering defaults **PENDING BUSINESS APPROVAL**.

## 1. Where the time goes (one client, medium dataset: 18,765 orders, 93,825 ledger entries)

- **The floor:** `GET /live` (no query) p50 ≈ 40 ms.
- **A request that touches the database** pays about 30 ms more before its first query, because a new PostgreSQL connection is opened per request (PHP's default). On Windows this means a new backend **process** per connection (§4).
- **Reads:** 3–20 queries, p50 90–180 ms.
- **Purchase writes:** 34–59 queries, p50 200–310 ms.
- **Pickup verification:** 79 queries, p50 302 ms (`m40-baseline-medium.json`).

Statement time is small. In the medium profile almost every statement averages under 5 ms; the exceptions are listed in §2. Most of a request is connection setup, framework, serialisation and the number of round trips.

## 2. Findings and fixes (pg_stat_statements, EXPLAIN ANALYZE)

| Id | Statement | Before | Cause | Fix | After |
|---|---|---|---|---|---|
| REL-002 | restaurant order board: `orders where location_id = ? order by pickup_at, id limit 25` | 17.4 ms; sequential scan + top-N sort of all 18,780 orders of the location | no index matched the default sort without a status filter | index `orders (location_id, pickup_at, id)` | 1.6 ms |
| REL-003 | admin order list: `orders order by placed_at desc, id asc limit 25` | 16.2 ms; sequential scan + sort | no index on `placed_at` alone (mixed direction) | index `orders (placed_at desc, id)` | < 5 ms |
| REL-004 | finance overview, period figures platform-wide: `ledger_entries where occurred_at in [from, to)` | 50.3 ms; parallel sequential scan of the ledger | indexes exist only per account / location / market | index `ledger_entries (occurred_at)` | 4.2 ms |
| REL-006 | restaurant earnings, "held" lines: `exists (… financial_holds … h.payable_item_id = i.id or (scope = LOCATION and h.location_id = i.location_id))` | 88–92 ms for 18,780 lines (even with **no** holds) | a correlated subquery with an OR, run once per payable line | two uncorrelated `IN` lists (PostgreSQL hashes each once); same meaning, NULLs excluded (`RestaurantEarningsService::HELD`); finance suite 62/62 | 8 ms |
| REL-005 | finance overview, standing balances: all-time sum of the entries of the standing accounts; plus whole-table health counts (orders without an allocation, payments without a posting, all ledger entries) | 50–56 ms at 93,825 entries; **178 ms at 937,575** (the health counts: 168 / 104 / 68 ms); endpoint p50 427 → 978 ms | inherent: a balance is the sum of every entry | **open** — running / snapshot balances (per account per day) and bounded health checks before the ledger reaches about 1 M entries | — |
| REL-052 | analytics aggregation, daily customers: five correlated sub-queries **per requested day** over the market's whole history (including every behavioural event per day), on fact rows written in the same transaction without statistics | medium: 11.1 s per call for 365 days; large: the catch-up transaction had not finished after **788 s** (cancelled; the aggregation lock is held 900 s) | quadratic shape + no statistics for freshly written facts | each figure computed once, grouped by day, scans bounded by the window's timestamps (existing market + time indexes); `ANALYZE` of the fact tables inside the run after ≥ 5,000 new facts | medium: **0.21 s, identical figures on all 365 days** (`m40-aggregation-equivalence.json`); large catch-up **73 s** for a year (456,664 rows); incremental 2.5 s; analytics suite 45/45 |

The indexes are built **CONCURRENTLY** in their migration, so they can be created on a live production table without blocking writes.

Endpoint effect on the medium dataset (one client):
- `GET /restaurant/orders` (board) and `GET /admin/orders`: see `m40-baseline-medium-after.json` against `m40-baseline-medium.json`.
- `GET /restaurant/finance/summary`: the 92 ms statement is gone.

## 3. Write paths and N+1

`m40-write-profile-medium.json` lists, for each step of purchase → kitchen → pickup:
- the statements per request;
- every statement that runs more than once per request.

Summary in `load-test-results.md` §4. The repeated statements come from:
- domain events and their listeners, which are synchronous by design: the order event stream, realtime broadcast payloads, notification orchestration, analytics facts, finance allocation;
- authorization: RBAC scope checks per resource.

They are not unbounded loops over rows: no write step's query count grows with the size of the cart, the menu or the data.

## 4. Connections (REL-001)

| Expected profile, 30 VUs × 180 s, medium | per-request connections (PHP default) | persistent connections (`DB_PERSISTENT=true`) |
|---|---|---|
| requests | 5,042 | 8,195 |
| throughput | 27.1 req/s | 44.8 req/s |
| p50 / p95 / p99 | 452 / 1,017 / 1,301 ms | 91 / 296 / 598 ms |
| server-side p95 (Server-Timing) | — | 172 ms |
| host CPU avg / max | 94 % / 98 % | 43 % / 60 % |
| 5xx / transport errors | 0 / 0 | 0 / 0 (29 × 429: the pickup abuse limit, §6) |

The CPU the per-request run spent is mostly **PostgreSQL backend processes being started and torn down**. They live for one request, so the 5-second process sampler never sees them; it reported only 27 s of postgres CPU for the run.

**Production recommendation:**
- **PgBouncer in transaction mode** between PHP-FPM and PostgreSQL (or the managed database's pooler).
- Laravel works with it as long as no session state is relied on across transactions. In this codebase session settings are re-applied at connect (`timezone`, `search_path`), advisory locks are transaction-scoped (`pg_advisory_xact_lock`), and no `LISTEN` or prepared-statement re-use across requests is used.
- Set `PDO::ATTR_EMULATE_PREPARES` only if the pooler requires it.

**Persistent PDO connections** (`DB_PERSISTENT`, added in Module 40, **default off**) are a valid alternative on a single host with a fixed PHP-FPM pool. Caveats:
- one connection per PHP worker for its lifetime, so `max_connections` must cover every worker of every host;
- a worker that dies mid-transaction leaves PostgreSQL to roll it back when the socket closes;
- per-session settings persist (none are set beyond timezone and search path).

**Connection limits:**
- `max_connections` = 100 locally.
- The load runs peaked at 14–16 connections (12 PHP processes, 2 workers, the monitor).
- **Connection exhaustion** (`failure.mjs connections`, `m40-failure-connections.json`): with every slot of the application role taken, `/live` 200, `/health` 503, other routes a fast 500 (no request hangs waiting for a connection); recovery without a restart. Refusing / dropped connections: `failure.mjs database`. Both in `provider-resilience.md` §4.

## 5. Locks, deadlocks, transactions

- **Lock waits** across all load runs: `pg_lock_waits_max` = 0 in every profile (expected, peak, stress, spike).
- **Longest statement** seen: `pg_longest_active_ms_max` in each `m40-load-*.json`. The 1.9 s at peak is a request queued behind CPU, not a slow plan.

**REL-035 — deadlock in the pickup race (FIXED):**
- **Scenario:** 20 orders, each verified by 8 simultaneous requests (`run.mjs --profile race`). Exactly-once held (20/20 orders verified once, all COMPLETED).
- **But** 102 of the 160 answers were **500**, and p95 was 5.3 s. The PostgreSQL log showed `deadlock detected` 102 times.
- **Cycle:**
  - The winning transaction held the **order** `FOR UPDATE` and waited for the **credential** `FOR UPDATE`.
  - A refused request's audit row (`INSERT INTO order_pickup_verification_attempts`, outside the transaction) checked its foreign keys. That took `FOR KEY SHARE` on the credential, then on the order. `FOR UPDATE` conflicts with `FOR KEY SHARE`.
- **Fix:** the verification locks both rows `FOR NO KEY UPDATE`. This is still exclusive between verifiers, but it no longer conflicts with foreign-key checks. Nothing else in the transaction changed.
- **After:** 160 answers, 20 × 200 and 140 × 409 `pickup_already_verified`, 0 deadlocks in the server log, p95 370 ms (`m40-load-race-medium-after.json`). Regression test: `PickupLockingTest`.
- **General rule (REL-036, recommendation):**
  - A row lock taken only to change a row's state should be `FOR NO KEY UPDATE`.
  - `FOR UPDATE` should be kept for deleting a row or changing its key. In this codebase that is never done under these locks.
  - The other `lockForUpdate()` sites (order store, carts, payments, refunds, payouts, settlements) did not deadlock in any run: concurrent payment completion, concurrent kitchen transitions, stress up to 200 VUs. They should be reviewed with this rule before a high-concurrency launch.

**Concurrency checks** (`m40-failure-concurrency.json`):
- 6 payments, each completed 6 times at once plus a duplicated webhook: 42 answers, all 200; exactly 1 order and 1 `PAYMENT_VERIFIED` posting per payment.
- 5 orders, each accepted by 4 simultaneous requests and then made ready by 4: all 200 (idempotent), exactly one `RESTAURANT_ACCEPTED` and one `READY_FOR_PICKUP` event per order.
- Ledger verification OK afterwards.

**Transaction length:** provider calls are made outside database transactions (code inventory: payment creation, refund submission, payouts, push). Two exceptions are recorded in `provider-resilience.md`:
- invitation e-mails (REL-014);
- avatar file deletion.

## 6. Timeouts (proposals, PENDING)

| Setting | Proposal | Why |
|---|---|---|
| `statement_timeout` (API role) | 15 s | the slowest statement in any run is far below; a runaway query must not hold a PHP worker |
| `lock_timeout` | 5 s | a waiting request fails fast (the API answers 5xx and the client retries with its idempotency key) instead of piling up |
| `idle_in_transaction_session_timeout` | 60 s | protects against a worker that died inside a transaction |
| worker / reports / migrations | separate role or `SET` per job with longer limits | exports, aggregation and backfills legitimately run longer |

None of these is set locally.

## 7. Read replicas, pagination, search

- **Read replica:** not needed at the measured sizes. Candidates if it ever is: analytics reports, admin reports, CSV exports and the finance reports, which read many rows and tolerate seconds of lag.
  - Never a candidate: the customer's own order and payment status, pickup verification, any write path. Read-your-writes is required there.
  - Laravel's `read` / `write` connection split with `sticky = true` would be the mechanism.
- **Pagination:** every list endpoint pages with `limit` / `offset` and a stable tie-breaker (`id`).
  - At the medium size the deepest admin page is cheap with the new indexes.
  - Offset pagination over hundreds of thousands of rows (admin order or payment history) should move to keyset (cursor) pagination when such pages are used. Today they are not, because filters narrow the lists (REL-037, low).
- **Search:** restaurant / menu text search filters a market's restaurants (hundreds) in SQL. No full-text index is needed at this size.

## 8. Settings enabled locally for Module 40

- `shared_preload_libraries = 'pg_stat_statements'`, `pg_stat_statements.track = top`
- `track_io_timing = on`
- `log_lock_waits = on`

These are set in `postgresql.auto.conf` via `ALTER SYSTEM`. Production needs the same (`production-reliability-checklist.md` B4).
