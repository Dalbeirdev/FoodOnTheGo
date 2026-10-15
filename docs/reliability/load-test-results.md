# Load-test results

Status: Module 40 — 2026-10-14 — local only; production = NO

Plan, environment and safety rules: `load-test-plan.md`. Raw results: `docs/local-review/test-results/m40-*.json` (every number below is read from them).

**What these numbers are.**
- One development laptop: i9-11900H, 16 logical CPUs, 32 GB, Windows 11.
- It runs the load generator, the PHP pool (12 single-request PHP processes + 2 queue workers), PostgreSQL 18 + PostGIS, Redis and the monitor at the same time.
- Every run ended **CPU-bound on this machine**.
- They show how the application behaves as load grows, what breaks first and what each change buys. They are **not** a production capacity figure, and "the API handles N requests per second" is **not** claimed.

## 1. Baseline — one client, medium dataset (18,765 orders)

`m40-baseline-medium.json` (before tuning) and `m40-baseline-medium-after.json` (after the Module 40 indexes and the query fix):
- 30 timed requests per read endpoint, after 3 warm-ups;
- 15 OTP sign-ins;
- 15 complete purchase → kitchen → pickup cycles. **15/15 completed, 0 errors**, in both runs.

| Endpoint (p50 ms, before → after) | | Queries |
|---|---|---|
| `GET /live` | 43 | 0 |
| `GET /health` | 100 | 2 |
| `GET /restaurants`, `/restaurants/{r}`, `/menu`, `/items/{i}` | 180 / 142 / 136 / 151 | 14–20 |
| `POST /journeys/plan` (medium / long route) | 164 / 148 | 21 |
| `GET /journeys/{j}/restaurants` | 172 | 18 |
| `POST /cart/items`, `/cart/pickup-selection` | 249 / 224 | 46 / 34 |
| `POST /checkouts`, `/confirm`, `/payment-attempts`, `/payments/{p}/complete` | 256 / 284 / 310 / 307 | 41 / 53 / 59 / 51 |
| `POST /restaurant/orders/{o}/accept`, `start-preparing`, `ready` | 252 / 229 / 227 | 44–46 |
| `POST /restaurant/pickup/verify` | 302 → 293 | 79 |
| `GET /restaurant/orders` (board) | 150 → 139 | 8 |
| `GET /admin/orders` | 137 → 118 | 5 |
| `GET /restaurant/finance/summary` | 274 → 217 | 14 |
| `GET /admin/finance/overview` | 427 → 485 | 24 — the period statement fell 50 → 4 ms; the request is dominated by the all-time balances (REL-005) and the run-to-run noise of this machine |

Small dataset (`m40-baseline-small-before.json`, 15 orders) against medium:
- Most endpoints are 5–20 % slower on the medium dataset, from more rows plus run-to-run noise.
- The clear growers:

  | Endpoint | p50 small → medium | Why |
  |---|---|---|
  | restaurant finance summary | 137 → 274 ms (217 after REL-006) | the payable lines |
  | admin finance overview | 326 → 427 ms | the ledger |
  | service desk | 101 → 169 ms (124 after) | more cases on the first page: 3 → 6 queries |
  | restaurant order board | 117 → 150 ms (139 after REL-002) | — |
  | corridor restaurants | 136 → 172 ms | 339 instead of 21 locations |

## 2. Load profiles (medium dataset)

**Connection modes:**
- **—**: PHP's default, a new PostgreSQL and Redis connection per request.
- **db**: persistent PostgreSQL connections (`DB_PERSISTENT`).
- **+redis**: persistent Redis connections (`REDIS_PERSISTENT`, one named socket per connection).
- **+noprep**: no named prepared statements (`DB_PGSQL_DISABLE_PREPARES`).

All three switches were added in Module 40 and are **off by default**.

| Run | Mode | VUs | Requests | req/s | p50 ms | p95 ms | p99 ms | 5xx | Transport errors | Server p95 ms | Purchases → picked up | Host CPU avg | PG conns | Lock waits | Queue max / oldest job |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| smoke | — | 1 | 36 | — | 167 | 295 | 303 | 0 | 0 | 265 | 1 → 1 | — | — | — | — |
| expected | — | 30 | 5,042 | 27.1 | 452 | 1,017 | 1,301 | 0 | 0 | 517 | 64 → 61 | 94 % | 11 | 0 | 4 / 1.5 s |
| expected | db | 30 | 8,195 | 44.8 | 91 | 296 | 598 | 0 | 0 | 172 | 132 → 90 * | 43 % | 16 | 0 | 7 / 1.5 s |
| peak | — | 75 | 6,737 | 35.8 | 1,568 | 3,449 | 4,513 | 0 | 0 | 483 | 88 → 54 | 98 % | 14 | 0 | 3 / 1.4 s |
| peak | db | 75 | 12,712 | 68.9 | 668 | 1,709 | 2,493 | **5** | 0 | 271 | 175 → 115 | 96 % | 16 | 0 | 6 / 1.6 s |
| peak | db+redis | 75 | 16,999 | 92.2 | 464 | 1,019 | 1,338 | 0 | 0 | 162 | 240 → 163 | 92 % | 16 | 0 | 11 / 1.6 s |
| peak | db+redis+noprep | 75 | 17,728 | **96.3** | 434 | 985 | 1,339 | 0 | 0 | 150 | 255 → 172 | 90 % | 16 | 0 | 8 / 1.6 s |
| stress | — | 20 → 200 | 11,089 | 34.8 | 2,277 | 6,156 | 7,295 | 0 | 0 | 488 | 152 → 86 | 95 % | 14 | 0 | 21 / 1.9 s |
| stress | db | 20 → 200 | 13,174 | 41.6 | 1,790 | 5,330 | 6,488 | 0 | 0 | 491 | 177 → 98 | 88 % | 16 | 1 | 12 / 1.9 s |
| spike | — | 10 → 150 → 10 | 6,221 | 25.6 | 240 | 4,944 | 6,163 | 0 | 0 | 407 | 52 → 21 | 63 % | 12 | 0 | 6 / 1.3 s |
| soak (opening hours) | db+redis+noprep | 25 × 30 min | 83,734 | 46.4 | 64 | 149 | 434 | 0 | 0 | 98 | 1585 → 1489 | 40 % | 15 | 0 | 7 / 1.8 s |
| soak (06:11–06:41, restaurants closed: reads only) | db+redis+noprep | 25 × 30 min | 59,962 | 33.3 | 56 | 124 | 331 | 0 | 0 | 86 | 0 → 0 | 21 % | 13 | 0 | 0 / 0 s |

\* 29 answers 429: the pickup-verification abuse limit (30 per staff member per minute) hit by the load generator's single staff account (REL-032). The limit was raised for later capacity runs.

**Purchases → picked up:** a purchase is counted when its order exists. The kitchen scenario then prepares and verifies the orders it finds, so pickups trail purchases at the end of a run.

**Soak** (`m40-load-soak-medium.json`, 25 VUs for 30 minutes, pooled connections):
- 83,734 requests, **0 errors**, 1,585 purchases → 1,489 pickups.
- Queue depth ≤ 7; oldest job ≤ 1.8 s.
- Redis ≤ 11.2 MB; PostgreSQL connections ≤ 15; 0 lock waits.
- Latency per 5-minute bucket: 0–5 min p95 170 ms; 5–10 min p95 127 ms; 10–15 min p95 128 ms; 15–20 min p95 140 ms; 20–25 min p95 187 ms; 25–30 min p95 174 ms. Throughput stayed at ≈ 46 req/s.
- The second half is somewhat slower (p95 140–188 ms against 127 ms at 5–15 minutes), but there is no monotonic growth and no error drift. The machine is shared with the load generator (CPU peak 99.8 %).
- **Limit:** the sampler keeps maxima, not a time series of process memory: the PHP peak working set was 1234 MB for 19 processes. A slow memory leak can therefore not be ruled out from this run alone. Workers are recycled by `--max-jobs` / `--max-time` in production.
- A first soak at 06:11 ran while the restaurants were closed (no orderable item): 59,962 read requests, 0 errors, p95 124 ms (`m40-load-soak-medium-closed-hours.json`). It was repeated during opening hours above.

**Reading the table:**
- **No run produced a server error from the application**, at any load up to 200 VUs. The 5 errors in "peak / db" were Windows running out of client ports for the per-request Redis connections, at ≈ 70 req/s (REL-041). Persistent Redis connections removed them.
- **Degradation is graceful:**
  - Past saturation, latency grows (stress: p95 0.9 s at 20 VUs → 7.3 s at 200 VUs, per stage in `m40-load-stress-medium.json`).
  - Throughput stays flat (33–36 req/s without pooling).
  - Nothing fails, and the queue keeps up (the oldest job is never older than 2 s).
- **Saturation point on this machine:**
  - about 35 req/s with a connection per request;
  - about 95 req/s with persistent PostgreSQL and Redis connections.

  In both cases the host CPU is the limit, shared with the load generator and PostgreSQL.
- **Server time stays small.** The server's own p95 (Server-Timing) was 150–520 ms while the client p95 was 1–7 s. The difference is requests **waiting for a free PHP process**, so the pool size, and the CPU behind it, is the capacity lever.
- **Spike:** 10 → 150 → 10 VUs. The burst raised p95 to 4.9 s with no errors; after the burst, latency returned to the pre-burst level (`windows` in the result file).
- **Database:** at most 16 connections, 0 lock waits (1 in the persistent stress run), no deadlock outside the pickup race (fixed, §3). Redis peaked at 17 MB.

## 3. Correctness under concurrency

| Test | Result |
|---|---|
| Pickup race: 20 orders × 8 simultaneous verifications (`m40-load-race-medium*.json`) | before the fix: exactly-once held, but **102 × 500** (deadlock) and p95 5.3 s. After REL-035: **20 × 200 + 140 × 409**, 0 deadlocks, p95 370 ms. |
| 6 payments completed 6× at once + duplicated webhook (`m40-failure-concurrency.json`) | 42 × 200; **exactly 1 order and 1 posting per payment** |
| 5 orders accepted / made ready by 4 simultaneous requests | all 200 (idempotent); **exactly 1 event per transition** |
| Ledger after all runs (`m40-failure-*.json`, `m40-integrity-*.json`) | `finance:verify-ledger` OK every time (no duplicate or orphan postings, payables match) |

## 4. Write paths, statement by statement (`m40-write-profile-medium*.json`)

Statements per request, as counted by `pg_stat_statements`, PDO default vs `DB_PGSQL_DISABLE_PREPARES`:

| Step | Default | No named prepares |
|---|---|---|
| `POST /cart/items` | 112 | 58 |
| `POST /cart/pickup-selection` | 76 | 39 |
| `POST /checkouts` | 90 | 46 |
| `POST /checkouts/{c}/confirm` | 115 | 59 |
| `POST /checkouts/{c}/payment-attempts` | 128 | 66 |
| `POST /payments/{p}/complete` | 112 | 58 |
| order creation (queue: `FulfillCheckoutJob` + listeners) | 157 | 83 |
| `accept` / `start-preparing` / `ready` | 96–101 | 50–52 |
| `POST /restaurant/pickup/verify` | 171 | 89 |

**REL-042:** about half of all statements were `DEALLOCATE`. PDO's pgsql driver prepares every query as a named server-side statement and deallocates it afterwards, which costs **two round trips per query**.
- Locally a round trip costs about 0.1 ms. Against a database over the network, at about 1 ms per round trip, that would add an estimated 40–80 ms per write request (not measured).
- Named prepares also break a transaction-mode pooler.
- With `DB_PGSQL_DISABLE_PREPARES` the parameters are still bound by the server (libpq `PQexecParams`), never interpolated.
- Measured: peak 92.2 → 96.3 req/s, database time per request 22.4 → 16.3 ms (−27 %).

No statement runs more than 5 times in one request: no N+1 over rows. The repeats are the market, order and location reads of the domain-event listeners and the authorization checks (REL-034, low).

## 5. Large dataset (10× medium)

Dataset (`foodonthego_large_perf`, 3,412 MB):
- 187,515 orders;
- 937,575 ledger entries;
- 2,000,068 behavioural events;
- 50,058 customers;
- 1,619 restaurant locations.

It was built in one 392 s transaction by `dataset.mjs grow --tier large`. Ledger verification is OK (375,030 transactions); integrity: `m40-integrity-perf-large.json`.

The large database was first restored from the small snapshot **taken before the Module 40 migration**, so the first large baseline ran without the new indexes (`m40-baseline-large-before-indexes.json`). The indexes were then built CONCURRENTLY in ≈ 1 s, and the baseline re-run (`m40-baseline-large.json`).

One client, p50 / p95 in ms; 15/15 cycles in every run:

| Endpoint | medium (after) p50 | large, before the indexes p50 | large p50 / p95 | DB ms (large) |
|---|---|---|---|---|
| `GET /restaurants` | 198.2 | 183.4 | 179.2 / 286.9 | 108 |
| `GET /restaurants/{restaurant}/menu` | 141.3 | 128.8 | 135.5 / 236.4 | 95.1 |
| `POST /journeys/plan (long)` | 154.2 | 151.1 | 191.2 / 315.7 | 140.7 |
| `GET /journeys/{journey}/restaurants` | 190.3 | 183.2 | 232.1 / 352.8 | 157 |
| `POST /checkouts/{checkout}/payment-attempts` | 300 | 253.5 | 278.2 / 416.4 | 184.5 |
| `POST /payments/{payment}/complete` | 290.7 | 252.1 | 285.1 / 444.3 | 206.8 |
| `POST /restaurant/pickup/verify` | 293.1 | 296.4 | 291.6 / 368.8 | 155 |
| `GET /customer/orders` | 119 | 98.8 | 149.2 / 249.1 | 102.9 |
| `GET /restaurant/orders (active board)` | 139.3 | 299 | 173.3 / 279.5 | 121.9 |
| `GET /admin/orders` | 117.5 | 253.7 | 128.2 / 233.2 | 75.7 |
| `GET /admin/support/cases (service desk)` | 123.6 | 158.1 | 164.2 / 268.3 | 111 |
| `GET /admin/analytics/overview` | 106.1 | 105.3 | 103.7 / 211.2 | 65.9 |
| `GET /restaurant/finance/summary` | 217.4 | 590.9 | 553.4 / 697.5 | 441.7 |
| `GET /admin/finance/overview` | 485.4 | 965.3 | 977.8 / 1230.3 | 818.6 |

- **Flat with 10× the data:** browsing, journeys, checkout, payment, kitchen, pickup verification, customer and admin lists.
- **Grows with the data:**
  - the admin finance overview (REL-005: all-time balances, 178 ms per statement, plus whole-table health counts);
  - the restaurant finance summary. All 187,515 synthetic orders belong to one location and none was ever settled — a real location's unsettled lines are bounded by its settlement cadence.
  - the board's pagination total, a `count(*)` over the location's 187,515 orders (48 ms).
- **Analytics catch-up over a year of large history** (REL-052): the first SQL had not finished after 788 s; with the fix it took **73 s** (456,664 read-model rows), and an incremental run takes 2.5 s (`m40-integrity-perf-large.json`).

## 6. Realtime, failure injection, recovery

See:
- `realtime-scaling.md` (255-connection local ceiling, broadcast p95 ≈ 300 ms, reconnect storm 1.7 s);
- `provider-resilience.md` §4 (queue, crash, restart, Redis, database, payment provider, Reverb, concurrency);
- `backup-restore.md` / `disaster-recovery.md` (TEST RTO ≈ 22 s, RPO = since the last dump).

## 7. What was not tested

- A production-like environment: Linux, PHP-FPM, PgBouncer, managed PostgreSQL and Redis over a network, a separate load generator.
- Real providers.
- Multi-node Reverb.
- More than 200 VUs.
- A soak longer than 30 minutes; a memory time series per process.

## 8. Web performance (production build)

Method:
- `vite build` (`web-build-m40.txt`), served by `vite preview` on :5173, talking to the local API through the api-proxy (:8001 → the two single-request development API processes).
- Measured with Chrome (Playwright, `m40-web-vitals.json`): 3 cold loads (new context, no cache) and 3 warm loads per page, plus one throttled phone-like run (1.6 Mbps, 150 ms RTT, 4× CPU, 390 × 844).
- This is a **Lighthouse-equivalent measurement, not Lighthouse.** Byte figures are decoded sizes.

| Page | FCP cold | LCP cold | LCP warm | JS (decoded) | Requests |
|---|---|---|---|---|---|
| Customer home | 520 ms | 2,720 ms | 128 ms | 1,440 kB | 16 |
| Restaurant page | 460 ms | 3,348 ms | 508 ms | 1,440 kB | 17 |
| Menu item | 368 ms | 4,476 ms | 1,312 ms | 1,440 kB | 17 |
| Restaurant Dashboard (sign-in) | 436 ms | 2,864 ms | 48 ms | 1,440 kB | 13 |
| Platform Admin (sign-in) | 520 ms | 2,128 ms | 504 ms | 2,276 kB | 14 |
| Customer home, throttled phone | 3,248 ms | 3,248 ms | — | 1,440 kB | 5 |

**Bundle** (`web-build-m40.txt`):

| Chunk | Size (gzip) | Loading |
|---|---|---|
| customer entry | 1,474.73 kB (407.68 kB) | — |
| Restaurant Dashboard | 436.78 kB (117.20 kB) | lazy |
| Platform Admin | 771.31 kB (164.54 kB) | lazy |
| Charts | 84.70 kB (23.76 kB) | lazy |
| CSS | 210.86 kB (36.30 kB) | — |

- No map library is bundled (no tiles to load).
- There is one static image (hero).

**Reading it:**
- First paint is fast on a desktop (≈ 0.4–0.5 s cold): the bundle downloads and parses quickly locally.
- LCP is late because the content arrives through the API, and locally the API is two single-request processes. Requests that start in parallel queue there.
- On the menu-item page (resource timings in the browser), the restaurant, markets, coverage, cart and restaurant-list calls all start at ≈ 117 ms and finish between 0.6 and 2.4 s. Then the item starts at 2.1 s (after the restaurant), and the menu at 3.7 s (after the item).

Findings:
- **REL-051 (open)** — the customer entry chunk carries every customer page and the mock-mode repositories / fixtures (selected at run time, so not tree-shaken). Route-level splitting and a dynamic import of the mock mode would cut the first download. On the throttled phone run the home page painted at 3.2 s.
- **REL-054 (open)** — the item page loads 8 API calls with a 3-step dependency chain (restaurant → item → menu) and fetches the full restaurant list and the cuisines it does not show. The menu can be fetched in parallel with the item, and the list / cuisines deferred. Against a pooled production API the chain still costs three round trips before the largest paint.
- **Realtime listener clean-up:** subscriptions are closed on unmount and sign-out (Module 32 / 39 tests in `web-build-lint-m40.txt`).

## 9. Android performance

See `m40-android-size.json` and the completion report §17.
- Startup on a device was **not** measured: there was no device session, and USER DEVICE VERIFICATION is PENDING.
