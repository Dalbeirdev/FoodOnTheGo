# Capacity plan

Status: Module 40 — 2026-10-14 — local only; production = NO

Every figure carries one of three labels:
- **MEASURED** — on the development machine, `docs/local-review/test-results/m40-*.json`;
- **TEST ASSUMPTION** — chosen to exercise the system;
- **BUSINESS FORECAST** — **none was given**. Every launch number (orders per day, peak multiplier, markets, restaurants) is **PENDING BUSINESS APPROVAL**.

Nothing here is a production guarantee, and no "the API handles N requests/s" claim is made.

## 1. What one order costs the platform (MEASURED)

| Resource | Per order | Source |
|---|---|---|
| API requests, purchase path | 13: sign-in excluded; cart, pickup time, checkout, confirm, payment start, provider screen (simulated), completion, 1–4 payment polls | `scenarios.mjs purchase` |
| API requests, kitchen path | 4–5: board, accept, preparing, ready, pickup verification | `scenarios.mjs prepare / pickupRace` |
| API requests, browsing before buying | TEST ASSUMPTION: 20–40 (restaurants, menus, items, journey plan + corridor) | load mix weights |
| Queue jobs | order creation; 2 ledger postings; notifications (1–3 deliveries); completion | `write-profile` (queue step) |
| PHP CPU | 74–89 ms per request at saturation, this i9 (Windows, `php -S`, OPcache) | `php_cpu_s / requests`, expected / peak runs with pooling |
| PostgreSQL CPU | 12–23 ms per request with persistent connections | `postgres_cpu_s / requests` |
| PHP memory | ≈ 55 MB per process (1,055 MB for 19 processes) | `php_ws_mb_max` |
| Database storage | **≈ 15.5 KB of transactional rows + indexes per order**, plus ≈ 3 KB of behavioural events (≈ 10 events × 311 B) | large tier: orders 2,517 B/row, order events 307 B × 7, payment events 241 B × 6, ledger transactions 865 B × 2, ledger entries 268 B × 5, payment 1,190 B, pickup credential 1,041 B, allocation 785 B, payable line 678 B, notification 890 B + deliveries 275 B × 2, … |

## 2. Measured limits on this machine

| Limit | Value | First bottleneck | Failure mode | Recovery |
|---|---|---|---|---|
| Throughput, a new connection per request | ≈ 35 req/s (expected 27.1 at 30 VUs; peak 35.8; stress plateau 33–36) | host CPU (PHP + PostgreSQL process start-up per connection) | latency grows linearly with VUs (p95 0.9 s → 7.3 s from 20 to 200 VUs); **0 errors** | immediate when load drops (spike: back to p50 ≈ 240 ms) |
| Throughput, persistent PostgreSQL + Redis, no named prepares | **≈ 96 req/s** at 75 VUs (p95 985 ms) | host CPU (PHP) | latency; 0 errors | immediate |
| Per-request Redis connections | ≈ 70 req/s | Windows client ports (TIME_WAIT) | 5 × 500 | persistent Redis connections |
| Concurrent WebSocket connections, one Reverb process on Windows | **255** | `stream_select` descriptor limit | refused connections | Linux + `ext-uv` / `ext-event` (production) |
| Broadcast latency | p95 ≈ 300 ms from the request (1,500 deliveries) | — | +≈ 1 s per order action when Reverb is down | — |
| Queue | 20 order jobs in 3.1 s on one worker; oldest job never older than 2 s in any run | — | backlog visible in the reliability view | scale workers |
| Pickup verification abuse limit | 30 per staff member per minute (design) | — | 429 | spread over staff accounts |

**Throughput in orders (MEASURED, peak / pooled):** 255 purchases completed in 184 s inside the full traffic mix at 75 VUs. That is about **5,000 orders per hour** on one development machine, alongside the browsing, kitchen, admin and analytics traffic of the mix. Only about 9 % of that traffic is the purchase path.

## 3. Sizing formulas (to apply when the business gives a forecast)

```
peak_rps        = peak_orders_per_hour / 3600 × requests_per_order (≈ 13 purchase + 5 kitchen + 30 browse = 48, TEST ASSUMPTION)
php_cores       = peak_rps × 0.09 s  ÷ 0.6 target utilisation        (0.09 s = measured PHP CPU per request, this CPU)
php_workers     = php_cores × 4–8 (I/O wait per request)               memory: workers × 60 MB
pg_connections  = PgBouncer pool ≈ 2 × database cores (not one per PHP worker)
queue_workers   = 2 minimum (one dedicated to orders,payments) — the measured queue never lagged
reverb          = concurrent open order screens + kitchen screens; 1 Linux node with ext-uv covers thousands
db_growth/day   = orders_per_day × 19 KB  (+ indexes already included)   e.g. 1,000 orders/day ≈ 19 MB/day ≈ 7 GB/year
```

**Worked example (TEST ASSUMPTION, not a forecast):** a launch peak of 1,000 orders/hour.
- 1,000 / 3,600 × 48 ≈ 13 req/s.
- PHP cores: 13 × 0.09 / 0.6 ≈ 2 cores.
- The measured limit of one development laptop (≈ 96 req/s pooled) is ≈ 7× that.

Recommended headroom:
- **2× the forecast peak** (lunch rush, retries, re-connection storms after a deploy);
- **plus one spare app host**, so that losing a host keeps the peak served.

## 4. Data growth (MEASURED shapes, large tier)

| Tier | Orders | Ledger entries | Analytics events | Database size |
|---|---|---|---|---|
| small | 17 | 82 | 89 | ≈ 40 MB (fixtures, PostGIS, empty indexes) |
| medium | 18,765 | 93,825 | 200,068 | 386 MB |
| large | 187,515 | 937,575 | 2,000,068 | **3,412 MB** |

Time-dependent costs that grow with the data:
- **REL-005:** the admin finance overview's all-time balances (a sum over the ledger).
- **REL-029:** the service-area coverage check (the number of service areas).
- **The analytics catch-up after a gap:** 30 s for the medium history. The large-tier figure is in `m40-integrity-perf-large.json`.

Retention jobs keep the hot tables bounded:
- journeys, carts and checkouts are pruned hourly / every 5 minutes;
- notifications daily; analytics events daily, per the Module 38 retention;
- failed jobs after 30 days.

**Storage growth (files):**
- report exports expire after 24 h;
- avatars and menu images grow with customers and menus (bounded by size limits, Module 39);
- support attachments are not implemented.

## 5. Scale readiness

| Item | State | Note |
|---|---|---|
| Stateless application | **yes** | bearer tokens in PostgreSQL (Sanctum); no server-side session for the API (sessions only for the `/` welcome page); RBAC cache in Redis (shared) |
| Shared session | n/a for the API | — |
| Shared storage | **no — REL-049** | report exports are written to the `local` disk (fixed in `config/analytics.php`); avatars and menu images default to `public` (local). With more than one app host, an export built by a worker on host A cannot be downloaded from host B. Production needs an object-storage disk (S3-compatible) for exports, avatars, menu images and (future) attachments. |
| Shared Redis | yes | one logical instance; queue + locks + cache. A separate queue Redis is recommended. |
| Worker scaling | yes | idempotent jobs; dedicated `orders,payments` worker recommended |
| Realtime scaling | partial | one node measured; multi-node via `REVERB_SCALING_ENABLED` (not tested, REL-039) |
| Connection pooling | ready | PgBouncer transaction mode: no session state relied on; named prepares off via `DB_PGSQL_DISABLE_PREPARES`; persistent connections as an alternative (measured) |
| Read replica | not needed yet | reports only; never money or order paths (`database-performance.md` §7) |
| Autoscaling | not configured | app and worker hosts are stateless (once REL-049 is fixed) and can scale on CPU / queue depth; Reverb and the database scale manually |
| Cold start | measured | a PHP pool restarts in 1.3 s; OPcache warms on the first request per worker |

## 6. Deployment readiness

| Item | State |
|---|---|
| Safe shutdown / drain | Queue workers stop after the current job on SIGTERM (`queue:work`, Linux with pcntl) and `queue:restart` lets them finish. PHP-FPM reload is graceful. Load-balancer readiness should use `/health`; liveness `/live`. Reverb clients reconnect (measured 1.7 s for 230). |
| Maintenance mode | `php artisan down` — **file driver = one host only (REL-043)**; use the `cache` driver with a shared store in multi-host production |
| Backward-compatible schema | expand → migrate → contract. Module 40's indexes are built `CONCURRENTLY` (no write lock). Destructive changes only after the code that needs them is gone. A failed migration is rolled back by its `down()` (tested per module on the test database). |
| Large-table migrations | `ALTER TABLE … ADD COLUMN` with a default is metadata-only in PostgreSQL 11+; index builds CONCURRENTLY; backfills in batches by a command, never inside the migration |
| Queue payload compatibility | jobs carry scalar identifiers (no serialized models): a job queued by version N runs on version N+1 as long as the constructor keeps its parameters; `queue:restart` on deploy |
| Webhook compatibility | provider webhooks are stored first (unique event), processed by a job; an endpoint down during a deploy is retried by the provider |
| Event schema tolerance | realtime payloads carry `schema_version` (Module 32); how each client treats an unknown field or a newer schema version was not re-verified in Module 40 (Module 41 E2E) |
| Android API compatibility | `/api/v1` only; fields are added, never renamed or removed within v1. **No minimum-client-version mechanism exists (REL-050)**: an old Android build cannot be told to update. A `min_supported_build` in `/config` is recommended before the first store release. |
| Rollback | the previous release + `queue:restart`; migrations are additive, so a rollback of code does not need a schema rollback |
