# Observability

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources:
- `backend/app/Http/Middleware/{AssignRequestId,RequestMetrics}.php`
- `backend/config/observability.php`
- `backend/app/Services/Foundation/{DependencyChecks,ReliabilityStatus}.php`
- `backend/app/Console/Commands/OpsReliability.php`
- `backend/app/Http/Controllers/Api/{HealthController,Admin/ReliabilityController}.php`
- the existing per-domain `/admin/system/*` views (Modules 26–38)
- tests: `tests/Feature/Foundation/{ReliabilityStatusTest,RequestObservabilityTest,SchedulerResilienceTest}.php`, `HealthEndpointTest.php`

Thresholds are engineering defaults **PENDING BUSINESS APPROVAL**; none is a production SLO.

No APM, tracing backend, log shipper or alerting service is connected. The platform **produces** the signals; where they are collected (Datadog, Grafana / Prometheus, CloudWatch, …) is a deployment decision (REL-030).

## 1. Probes

| Probe | Touches | For |
|---|---|---|
| `GET /api/v1/live` (Module 40) | nothing (no query: `RequestObservabilityTest::test_liveness_answers_without_touching_a_dependency`) | a load balancer or orchestrator restarting a hung process |
| `GET /api/v1/health` | PostgreSQL, PostGIS, Redis (status only, 200 / 503) | readiness for traffic |
| `GET /api/v1/ready` | every dependency, detailed (restricted outside local / testing) | operators |

- `/health` and `/ready` are behind the API rate limiter, which needs Redis.
- `/live` is not (Module 40): with the limiter in front of it, a Redis outage answered 500 to the liveness probe, and an orchestrator would have restarted every healthy process.
- Measured: `m40-failure-first-pass.txt` (before the change) and `m40-failure-redis.json` (after); see also `provider-resilience.md` §4.
- The exemption is the only one, and `RouteInventoryTest` now checks the middleware every API route really runs.

## 2. Request metrics and logs

`RequestMetrics` middleware, on every API request:
- **`Server-Timing`** header: `app;dur=…, db;dur=…;desc="N queries"`.
  - OFF by default (`OBSERVABILITY_SERVER_TIMING`): it tells an outsider how long the database took.
  - It is ON in the perf environment; every load-test sample carries it (`server_app_*`, `db_queries_avg`, `db_ms_avg` in the results).
- **Structured log line** `http.request` for slow requests (≥ `OBSERVABILITY_SLOW_MS`, default 1,000 ms) and every 5xx (`OBSERVABILITY_REQUEST_LOG`: `off` | `slow` | `all`).
  - Fields: `method`, `route` (the **pattern**, e.g. `api/v1/customer/orders/{order}`, never the URL with values), `status`, `duration_ms`, `db_queries`, `db_ms`, `actor_type` (GUEST / CUSTOMER / RESTAURANT_USER / ADMIN_USER).
  - Never logged: bodies, headers, tokens, or any identifier of a person.
  - Tested: `test_a_slow_request_is_logged_by_its_route_pattern_without_anything_personal`.
- **Request id:** `X-Request-Id` (client-supplied when well-formed, otherwise generated) is returned on every answer and in every error body. It is put into Laravel `Context`, so:
  - every log line of the request carries it;
  - **every job the request queues carries it**: the worker restores it from the job payload. Tested with a real Redis queue and a separate worker run: `test_the_request_id_reaches_the_jobs_the_request_queues`.
  - A support case, a failed job and a slow request can therefore be followed through one id.

**Production:** `expose_php=Off` (PHP otherwise adds `X-Powered-By: PHP/8.3.x`; the perf pool runs with it off), and `LOG_LEVEL=warning` or `info` with JSON formatting to the collector.

## 3. The reliability view

`GET /api/v1/admin/system/reliability` (`admin.system.view`) and `php artisan ops:reliability [--alerts] [--strict]`. The command exits 1 on a critical alert (with `--strict`, also on a warning), so a cron or monitoring probe can call it.

**Read only.** It changes nothing and repairs nothing. It returns counts, ages and flags only: no query text, host, credential or customer identifier (tested).

| Section | Figures |
|---|---|
| dependencies | database / postgis / redis: ok / error |
| database | connections of this database and % of `max_connections`, active, idle in transaction, waiting on a lock, longest running statement (s), oldest open transaction (s), deadlocks, cache hit %, size (MB) |
| redis | used / peak memory, `maxmemory` and %, eviction policy, evicted keys, clients |
| queues | per queue (orders, payments, finance, notifications, default): waiting, delayed, reserved, age of the oldest waiting job |
| failed_jobs | total, last 24 h |
| domain | verified payments with no order after 5 min, payments needing recovery, refunds in flight > 24 h, refunds in review, payouts FAILED / UNKNOWN / REVIEW_REQUIRED, failed webhooks (24 h), the latest ledger verification (status, problems, age), urgent open support cases, support cases past an internal target |
| alerts | `{key, severity: warning | critical, value, threshold}`; `status` = the most severe |

**Alert thresholds** (`config/observability.php` → `alerts`, [warning, critical]):

| Key | Warning | Critical |
|---|---|---|
| db_connections_pct | 70 | 90 |
| db_waiting_on_lock | 5 | 20 |
| db_longest_query_s | 10 | 60 |
| db_longest_transaction_s | 60 | 300 |
| db_deadlocks (cumulative) | 1 | 10 |
| redis_memory_pct | 70 | 90 |
| redis_evicted_keys | 1 | 1,000 |
| redis_eviction_policy (memory limit + an evicting policy) | — | always |
| queue_depth (one queue) | 500 | 5,000 |
| queue_oldest_s | 60 | 300 |
| failed_jobs_24h | 1 | 25 |
| payments_verified_without_order | — | 1 |
| refunds_stuck | 1 | 10 |
| payouts_needing_attention | 1 | 5 |
| webhooks_failed_24h | 1 | 10 |
| ledger_problems | — | 1 |
| ledger_verification_age_h | 2 | 24 |
| ledger_verification_missing | always (warning) | — |
| support_urgent_open | 1 | 10 |
| support_sla_breached | 1 | 20 |
| dependency.* down | — | always |

A section that cannot be read is `null` (its dependency is down) or `{"error": "unavailable"}`. The rest of the view still answers; tested with Redis unreachable.

**Measured on the medium perf dataset** (`m40-integrity-perf-medium.json`):
- `ops:reliability` takes about 1.1 s as a fresh CLI process. The request itself is a few dozen cheap queries.
- It reported `warning` with one alert: `failed_jobs_24h = 24`. These were end-to-end-suite noise copied in with the local database (REL-012).

The **per-domain views** of Modules 26–38 remain the detail behind the domain signals:
- `/admin/system/providers`, `/carts`, `/pickup`, `/checkout`, `/payments`, `/orders`, `/refunds`, `/finance`, `/realtime`, `/notifications`, `/support`, `/analytics`.

## 4. Dashboards and metrics an operator should have (production, PENDING tooling decision)

| Signal | Source today |
|---|---|
| Request rate, error rate, p50 / p95 / p99 latency per route pattern | `http.request` lines (slow + 5xx only by default; `all` for a metrics pipeline) or the load balancer |
| DB connections, lock waits, long transactions, deadlocks | reliability view; `pg_stat_activity`, `pg_stat_database`; `pg_stat_statements` (enabled locally in Module 40: `shared_preload_libraries`) |
| Queue depth and age, failed jobs | reliability view |
| Money signals (payment without order, stuck refunds, payouts, ledger) | reliability view + finance runs |
| Realtime health, broadcast failures | `/admin/system/realtime` |
| Push / provider health | `/admin/system/notifications`, `/providers`, `/payments` |
| Host CPU / memory / disk | the platform's host monitoring (not in the application) |

## 5. Open items

| Id | Item |
|---|---|
| REL-030 | No log / metrics / alert collector connected; `ops:reliability --strict` is ready to be called by one |
| REL-031 | `pg_stat_statements` and `log_lock_waits` are enabled locally (Module 40); production PostgreSQL needs the same settings |
