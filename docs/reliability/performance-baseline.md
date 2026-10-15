# Performance baseline

Status: Module 40 — 2026-10-14 — local only; production = NO

Script: `tests/performance/baseline.mjs`.

Results:
- `docs/local-review/test-results/m40-baseline-small-before.json`, `m40-baseline-small-repeat.json` (small dataset);
- `m40-baseline-medium.json` (medium dataset, before tuning);
- `m40-baseline-medium-after.json` (after the Module 40 indexes and query fix).

The table below is generated from those files.

## 1. Method

- **One client, no other load.** Each read endpoint: 3 warm-up requests, then 30 timed. OTP sign-in: 15. Write flows: 15 complete purchase → kitchen → pickup cycles, every step timed. **15/15 cycles completed in every run.**
- **Environment:**
  - 11th Gen Intel i9-11900H (16 logical CPUs), 32 GB RAM, Windows 11;
  - PHP 8.3.33 with OPcache, a pool of 4 single-request PHP processes (`php -S` + Laravel's router), `APP_DEBUG=false`;
  - Laravel 13.35, PostgreSQL 18.6 + PostGIS 3.6.2, Redis 5, one queue worker;
  - providers: DEVELOPMENT (fake payment, fake routing, fake push).
- **Per sample:** client latency; the server's own time and its query count and database time (Server-Timing).
- **What the numbers include:** a new PostgreSQL connection per request, ≈ 30 ms on Windows (`database-performance.md` §4).
- **Datasets:**

  | Tier | Orders | Customers | Restaurant locations | Menu items | Ledger entries | Analytics events | Support cases |
  |---|---|---|---|---|---|---|---|
  | small | 17 | 59 | 19 | 165 | 82 | 89 | 0 |
  | medium | 18,780 | 5,058 | 339 | 3,305 | 93,900 | 200,145 | 2,504 |

## 2. Internal target classes (proposal — PENDING BUSINESS APPROVAL; not SLAs)

| Class | Proposed internal target (one request, no load) | Medium baseline |
|---|---|---|
| PROBE (`/live`, `/health`) | p95 ≤ 200 ms | met (63 / 191 ms) |
| NORMAL READ | p95 ≤ 300 ms | met by 19 of 21; **not met:** admin finance overview (703 ms, REL-005) and restaurant finance summary (369 ms; 326 ms after REL-006) |
| COMPLEX ROUTE (journey planning, corridor restaurants) | p95 ≤ 800 ms | met (≤ 255 ms with the DEVELOPMENT routing provider; a real provider adds its own latency) |
| WRITE / FINANCIAL WRITE | p95 ≤ 600 ms | met (≤ 450 ms) |
| ASYNC JOB (verified payment → order) | ≤ 5 s | met: the order exists within the customer's first payment poll(s); 20 queued order jobs completed in 3.1 s (`m40-failure-queue.json`) |

## 3. Endpoints (medium dataset; p50 small and p50 after tuning for comparison)

Times in ms. "Errors" counts 5xx + transport errors + unexpected 4xx: none in any run.

| Class | Endpoint | n | p50 | p90 | p95 | p99 | max | Errors | Queries | DB ms | p50 small | p50 after |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| COMPLEX ROUTE | `GET /journeys/{journey}/restaurants` | 30 | 171.7 | 186.1 | 188.8 | 283.5 | 283.5 | 0 | 18 | 97.4 | 135.8 | 190.3 |
| COMPLEX ROUTE | `POST /journeys/plan (long)` | 30 | 147.8 | 168.3 | 255.3 | 278.5 | 278.5 | 0 | 21 | 103.4 | 136.5 | 154.2 |
| COMPLEX ROUTE | `POST /journeys/plan (medium)` | 30 | 164 | 170.2 | 252.1 | 261.2 | 261.2 | 0 | 21 | 107.8 | 145.9 | 161.4 |
| FINANCIAL WRITE | `POST /checkouts` | 15 | 256.2 | 281.9 | 289.9 | 289.9 | 289.9 | 0 | 41.2 | 156.8 | 219.4 | 255.5 |
| FINANCIAL WRITE | `POST /checkouts/{checkout}/confirm` | 15 | 283.6 | 324.2 | 328.9 | 328.9 | 328.9 | 0 | 53.2 | 177.9 | 243.5 | 277.5 |
| FINANCIAL WRITE | `POST /checkouts/{checkout}/payment-attempts` | 15 | 310 | 436.7 | 449.5 | 449.5 | 449.5 | 0 | 59.5 | 206 | 266.8 | 300 |
| FINANCIAL WRITE | `POST /dev/payments/{payment}/simulate` | 15 | 114.7 | 196.1 | 218.4 | 218.4 | 218.4 | 0 | 3.2 | 65.1 | 100.1 | 115.1 |
| FINANCIAL WRITE | `POST /payments/{payment}/complete` | 15 | 306.8 | 412.9 | 417.3 | 417.3 | 417.3 | 0 | 51.1 | 183.5 | 255.4 | 290.7 |
| FINANCIAL WRITE | `POST /restaurant/pickup/verify` | 15 | 301.8 | 327.1 | 331.8 | 331.8 | 331.8 | 0 | 79.4 | 160.1 | 262.6 | 293.1 |
| NORMAL READ | `GET /admin/analytics/overview` | 30 | 111.4 | 210.5 | 225.9 | 227 | 227 | 0 | 4.1 | 77.8 | 106.5 | 106.1 |
| NORMAL READ | `GET /admin/finance/overview` | 30 | 427.2 | 648.1 | 702.9 | 899.6 | 899.6 | 0 | 24.5 | 292 | 325.7 | 485.4 |
| NORMAL READ | `GET /admin/orders` | 30 | 136.7 | 226.6 | 241.9 | 251.2 | 251.2 | 0 | 5.1 | 90.9 | 107.3 | 117.5 |
| NORMAL READ | `GET /admin/payments` | 30 | 132.1 | 223.5 | 243.2 | 262.6 | 262.6 | 0 | 7.2 | 89.6 | 112.4 | 125.8 |
| NORMAL READ | `GET /admin/refunds` | 30 | 97 | 149 | 217.3 | 220.8 | 220.8 | 0 | 3.1 | 62.2 | 99.5 | 95 |
| NORMAL READ | `GET /admin/support/cases (service desk)` | 30 | 168.5 | 235.7 | 260.1 | 290.8 | 290.8 | 0 | 6.2 | 104.2 | 101.1 | 123.6 |
| NORMAL READ | `GET /cart` | 30 | 92 | 182.5 | 204.1 | 215.2 | 215.2 | 0 | 3.1 | 59.7 | 82.2 | 91.1 |
| NORMAL READ | `GET /cart/pickup-availability` | 15 | 201.7 | 295.1 | 305.1 | 305.1 | 305.1 | 0 | 25.3 | 140.7 | 181.4 | 197.4 |
| NORMAL READ | `GET /customer/notifications` | 30 | 105.2 | 199.6 | 220 | 236 | 236 | 0 | 5.1 | 74.8 | 86.5 | 95.8 |
| NORMAL READ | `GET /customer/orders` | 30 | 110.4 | 161.8 | 206.8 | 250.5 | 250.5 | 0 | 5.1 | 70.8 | 88.7 | 119 |
| NORMAL READ | `GET /customer/orders/{order}` | 15 | 158.4 | 178.4 | 252.8 | 252.8 | 252.8 | 0 | 13.2 | 100.2 | 143.4 | 160.8 |
| NORMAL READ | `GET /customer/orders/{order}/pickup-credential` | 15 | 157.2 | 277.2 | 287.4 | 287.4 | 287.4 | 0 | 13.9 | 133.6 | 137.5 | 167.1 |
| NORMAL READ | `GET /customer/support/cases` | 30 | 130.3 | 223.6 | 239.7 | 254.2 | 254.2 | 0 | 5.1 | 90.3 | 109 | 102.5 |
| NORMAL READ | `GET /payments/{payment}` | 30 | 113.2 | 142.2 | 200.9 | 234.9 | 234.9 | 0 | 5 | 71 | 112.8 | 120.9 |
| NORMAL READ | `GET /restaurant/finance/summary` (restaurant earnings) | 30 | 273.8 | 301.6 | 369.3 | 382.5 | 382.5 | 0 | 14.3 | 212.7 | 136.9 | 217.4 |
| NORMAL READ | `GET /restaurant/orders (active board)` | 30 | 150 | 171.5 | 187.5 | 194.6 | 194.6 | 0 | 8.2 | 88.9 | 117 | 139.3 |
| NORMAL READ | `GET /restaurants` | 30 | 180.4 | 268.7 | 286.9 | 305.4 | 305.4 | 0 | 16 | 107.5 | 158.8 | 198.2 |
| NORMAL READ | `GET /restaurants/{restaurant}` | 30 | 141.7 | 227.8 | 230.3 | 261.7 | 261.7 | 0 | 14 | 101.2 | 127.8 | 141 |
| NORMAL READ | `GET /restaurants/{restaurant}/items/{item}` | 30 | 150.6 | 164.4 | 255.4 | 262.8 | 262.8 | 0 | 20 | 103.3 | 141.7 | 161.2 |
| NORMAL READ | `GET /restaurants/{restaurant}/menu` | 31 | 136 | 157.1 | 229.3 | 251.9 | 251.9 | 0 | 14.2 | 93.8 | 138.2 | 141.3 |
| NORMAL READ | `GET /restaurants/{restaurant}/reviews` | 30 | 126.3 | 224 | 237 | 239.6 | 239.6 | 0 | 7 | 99.1 | 109.6 | 129.1 |
| PROBE | `GET /health` | 30 | 100.1 | 189.2 | 191.2 | 197.3 | 197.3 | 0 | 2 | 67.6 | 87.1 | 87.1 |
| PROBE | `GET /live` | 30 | 43.1 | 58.3 | 62.9 | 67.3 | 67.3 | 0 | 0 | 0 | 36.2 | 27 |
| WRITE | `DELETE /cart` | 15 | 97.5 | 111.8 | 119.5 | 119.5 | 119.5 | 0 | 4 | 54.1 | 101.2 | 101.9 |
| WRITE | `POST /auth/customer/otp/verify` | 15 | 124.7 | 224.5 | 224.9 | 224.9 | 224.9 | 0 | 11 | 90.4 | 118.4 | 113.4 |
| WRITE | `POST /cart/items` | 15 | 248.8 | 323.9 | 365.6 | 365.6 | 365.6 | 0 | 46.1 | 167 | 215.2 | 228.6 |
| WRITE | `POST /cart/pickup-selection` | 15 | 223.5 | 361.9 | 418.6 | 418.6 | 418.6 | 0 | 34.1 | 157 | 200.1 | 226.6 |
| WRITE | `POST /restaurant/orders/{order}/accept` | 15 | 251.6 | 347.6 | 357.3 | 357.3 | 357.3 | 0 | 46 | 161.3 | 207.9 | 249.8 |
| WRITE | `POST /restaurant/orders/{order}/ready` | 15 | 227.3 | 387.7 | 396.6 | 396.6 | 396.6 | 0 | 46.2 | 155.9 | 210.7 | 243.4 |
| WRITE | `POST /restaurant/orders/{order}/start-preparing` | 15 | 229.1 | 278.2 | 330.8 | 330.8 | 330.8 | 0 | 44.4 | 143.3 | 225.8 | 242.9 |

**Notes:**
- Query counts are Laravel's. `pg_stat_statements` counts about twice as many statements per request, because of PDO's named-prepare `DEALLOCATE` (REL-042, `load-test-results.md` §4).
- **"After":** the statement-level gains are large (board 17 → 1.6 ms, admin list 16 → < 5 ms, finance period 50 → 4 ms, held lines 88 → 8 ms; `database-performance.md` §2).
- At the request level, a single run on this machine varies by ±15 %, so small request-level differences are not significant.
- The Redis time and provider time are part of the server time. A per-dependency split was not instrumented; the DEVELOPMENT providers answer in-process.

## 4. Restaurant Dashboard and Platform Admin surfaces

| Surface | Endpoint(s) | Medium p50 / p95 | Large lists |
|---|---|---|---|
| Restaurant active orders board | `GET /restaurant/orders?location=` | 150 / 188 → 139 ms after REL-002 | one location with 18,780 orders: paged 25, index-backed |
| Restaurant menu | `GET /restaurants/{r}/menu` (public) and the dashboard menu screens (Module 24) | 136 / 229 | 3,305 menu items across 339 locations; one menu document per location, cached with its `catalog_version` |
| Restaurant analytics | `/restaurant/analytics/*` (read models, Module 38) | analytics reads 4–8 queries (`m40-query-profile-medium*.json`) | aggregated per day; no raw scan |
| Restaurant finance | `GET /restaurant/finance/summary` | 274 / 369 → 217 ms after REL-006 | 18,780 payable lines of one location |
| Restaurant support | `/restaurant/support/cases` | same shape as the service desk | paged |
| Admin orders / payments / refunds | `GET /admin/orders`, `/payments`, `/refunds` | 137 / 132 / 97 ms | ≈ 20,000 rows each; paged 25, index-backed (REL-003) |
| Admin finance overview | `GET /admin/finance/overview` | 427 / 703 ms | grows with the ledger (REL-005) |
| Admin service desk | `GET /admin/support/cases` | 169 / 260 → 124 ms after | 2,504 cases |
| Admin analytics | `GET /admin/analytics/overview` | 111 / 226 ms | 200,145 behavioural events, read through daily read models |
| Exports | `POST …/exports` → queued job | asynchronous: the request returns 202 at once | built off the request path; row-capped; timeout 80 s (REL-009) |

The web front ends of these surfaces: `load-test-results.md` §8, `m40-web-vitals.json`.
