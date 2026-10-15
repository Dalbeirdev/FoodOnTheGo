# Production reliability checklist

Status: Module 40 — 2026-10-14 — local only; **production = NO**

Every item must be verified on the production environment before launch. Nothing here has been verified on a production environment: there is none. "Local evidence" names what Module 40 proved on the development machine. Owner decisions are marked **PENDING BUSINESS APPROVAL**. Companion lists: `docs/security/production-security-checklist.md` (Module 39) and the `php artisan security:check --as-production` gate.

## A. Runtime

| # | Item | Local evidence / note |
|---|---|---|
| A1 | PHP 8.3+ with **OPcache on** (`opcache.validate_timestamps=0` in production, `opcache.memory_consumption` ≥ 256 MB) | perf pool runs with OPcache; `GET /live` p50 ≈ 40 ms locally (Windows, built-in server) |
| A2 | `expose_php=Off` (no `X-Powered-By`) | observed: PHP 8.3.33 sends it by default; the perf pool turns it off |
| A3 | PHP-FPM (or FrankenPHP / Octane after a separate review) behind nginx / a load balancer; `pm.max_children` sized from memory (≈ 90–100 MB working set per PHP process measured) | `php_ws_mb_max` in `m40-load-*.json` |
| A4 | `APP_DEBUG=false`, `APP_ENV=production`, `config:cache`, `route:cache`, `event:cache` on deploy | Module 39 gate |
| A5 | `LOG_LEVEL` warning / info, JSON logs to a collector; `OBSERVABILITY_REQUEST_LOG=slow` (or `all` into a metrics pipeline) | `observability.md` |
| A6 | `OBSERVABILITY_SERVER_TIMING=false` | default off |

## B. PostgreSQL / PostGIS

| # | Item | Local evidence / note |
|---|---|---|
| B1 | **Connection pooling: PgBouncer (transaction mode) between PHP and PostgreSQL**, or persistent connections after the caveats in `database-performance.md` §4 | A/B measured: per-request connections cost the expected-load run p50 452 → 91 ms and p95 1,017 → 296 ms (`m40-load-expected-medium*.json`) |
| B2 | `max_connections` ≥ (PHP workers + queue workers + scheduler + Reverb + admin tools) × 1.5, or PgBouncer `default_pool_size` sized to the database | `pg_connections_max` in the load results |
| B3 | `statement_timeout` for the API role (proposal 15 s), `lock_timeout` (5 s), `idle_in_transaction_session_timeout` (60 s); a separate role / setting for migrations and long reports. **PENDING** | longest statement in all load runs: `pg_longest_active_ms_max` in the results |
| B4 | `shared_preload_libraries = 'pg_stat_statements'`, `track_io_timing = on`, `log_lock_waits = on`, `log_min_duration_statement` (proposal 500 ms) | enabled locally in Module 40 |
| B5 | Module 40 indexes applied (`2026_10_20_100000_module40_performance_indexes`, built CONCURRENTLY) | `database-performance.md` §2 |
| B6 | Automated backups + point-in-time recovery (WAL archiving / managed PITR); a restore test at least monthly | `backup-restore.md`; local backup / restore measured |
| B7 | A read replica only for reporting if needed (analytics and finance reports read the primary today) | `database-performance.md` §7 |
| B8 | Autovacuum defaults reviewed for the append-only ledger / event tables | not measured (single-day local data) |
| B9 | Append-only triggers present (Module 39 creates them outside `local`) | Module 39 `security:check` |

## C. Redis

| # | Item | Local evidence / note |
|---|---|---|
| C1 | `maxmemory` set and **`maxmemory-policy noeviction`** (queue and locks share Redis with the cache) — or the queue on its own Redis | the reliability view raises `redis_eviction_policy` (critical) otherwise |
| C2 | Persistence (AOF everysec) on the queue Redis; replica / managed failover | a Redis restarted empty loses queued jobs; the sweeps recover them (`m40-failure-redis.json`) |
| C3 | Redis password / ACL, TLS if over a network; not reachable from the internet | Module 39 |
| C4 | Decide the behaviour of the API rate limiter when Redis is down (fail closed today) | REL-019, `provider-resilience.md` §4 |

## D. Queues and scheduler

| # | Item | Local evidence / note |
|---|---|---|
| D1 | Workers supervised (systemd / Supervisor), `--queue=orders,payments,finance,notifications,default`, `--timeout=60`, `--max-time=3600`, `--max-jobs`; at least 2, one dedicated to `orders,payments` | `queue-strategy.md` §1 |
| D2 | `php artisan queue:restart` on every deploy | — |
| D3 | Exactly one `schedule:run` per minute per host; `onOneServer` + expiring overlap locks are in the code | `SchedulerResilienceTest` |
| D4 | Every job's `$timeout` < `retry_after` (90 s) | `GenerateReportExportJob` fixed (300 → 80 s) |
| D5 | Failed jobs monitored (reliability view / `ops:reliability --strict` from monitoring) | — |

## E. Realtime (Reverb)

| # | Item | Local evidence / note |
|---|---|---|
| E1 | Reverb on **Linux** with an event loop extension (`ext-uv` or `ext-event`) and a raised file-descriptor limit (`ulimit -n`) | local Windows Reverb stops at **255 connections** (stream_select / FD_SETSIZE) — `m40-realtime-1000.json` |
| E2 | More than one Reverb node → `REVERB_SCALING_ENABLED=true` (Redis pub/sub) behind a load balancer with WebSocket support | not tested (one node) |
| E3 | TLS (wss), allowed origins = the production web origins only | Module 32 / 39 |
| E4 | Broadcast client timeouts 2 s / 1 s in effect | fixed in Module 40 (duplicate config key) — `SchedulerResilienceTest::test_the_broadcast_client_keeps_its_short_timeouts` |
| E5 | Clients fall back to polling when realtime is down | Module 32 |

## F. Providers

| # | Item | Local evidence / note |
|---|---|---|
| F1 | Razorpay / FCM / SMS / routing credentials configured and **verified in their test modes** before live | PENDING PROVIDER VERIFICATION (Modules 26, 30, 33, 35, 36) |
| F2 | Provider timeouts reviewed (`provider-resilience.md` §2); FCM and Truecaller get a connect timeout | REL-015 |
| F3 | SMTP timeout set; invitation e-mails not sent inside a transaction | REL-014 |
| F4 | Webhook endpoints reachable from the providers only (Module 39) | — |

## G. Capacity and testing

| # | Item | Local evidence / note |
|---|---|---|
| G1 | A load test against the **production-like** environment (Linux, PHP-FPM, PgBouncer, managed PostgreSQL / Redis) before launch; this machine's figures are not production figures | `load-test-results.md` |
| G2 | Business targets (orders per hour at launch, peak multiplier, latency targets) agreed — **PENDING BUSINESS APPROVAL**; no target was invented in Module 40 | `capacity-plan.md` |
| G3 | Data growth plan: ledger balance snapshots before the ledger reaches ~1 M entries; service-area coverage query before ~100 service areas | REL-005, REL-029 |

## H. Disaster recovery

| # | Item | Local evidence / note |
|---|---|---|
| H1 | RPO / RTO agreed — **PENDING BUSINESS APPROVAL** | `disaster-recovery.md` (TEST values measured locally) |
| H2 | Backups encrypted, off-site, retention agreed; restore tested | `backup-restore.md` |
| H3 | Runbooks: database restore, Redis loss, queue backlog, provider outage, realtime outage | `disaster-recovery.md` |
| H4 | Tabletop exercise with the people who will be on call | `disaster-recovery.md` §6 (template; not held) |
