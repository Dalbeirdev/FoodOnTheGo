# FOODONTHEGO — MODULE 40 COMPLETION REPORT
# PERFORMANCE, SCALE, RELIABILITY & DISASTER RECOVERY

Date: 2026-10-14 → 2026-10-15 · Base commit c0d118e · Module 40 commit 95978fd · LOCAL ONLY · PRODUCTION = NO
Every number below is read from `docs/local-review/test-results/m40-*` and the other files named; nothing is estimated unless it says so.

## 1. MODULE STATUS

**COMPLETE (local)** — Critical reliability findings open: 0. Production-dependent items (load balancer, PgBouncer, managed PostgreSQL / Redis, object storage, PITR, monitoring destinations, production RTO / RPO) are PENDING BUSINESS / INFRASTRUCTURE APPROVAL.

## 2. TEST ENVIRONMENT

| Item | Value |
|---|---|
| CPU | 11th Gen Intel(R) Core(TM) i9-11900H @ 2.50GHz (16 logical) |
| RAM | 32 GB |
| Storage | local SSD disks (PostgreSQL data on C:, project on E:) |
| OS | Windows 11 Pro 10.0.26200 (the load generator runs on the same machine) |
| PHP | 8.3.33 + OPcache, expose_php Off, APP_DEBUG false |
| Laravel | 13.35 (spec: 12 — CF-268) |
| PostgreSQL | 18.6 + PostGIS 3.6.2 (spec: 16 — CF-268); pg_stat_statements, track_io_timing, log_lock_waits enabled locally |
| Redis | 5 (local); a perf-only Redis on port 6390 for the Redis failure tests |
| Workers | queue:work redis --queue=orders,payments,finance,notifications,default (1–2 per run) |
| Realtime | Laravel Reverb (one process; a perf instance on 8086) |
| Dataset | small (17 orders) · medium (18,765 orders) · large (187,515 orders, 937,575 ledger entries, 2,000,068 events, 3,412 MB) |
| Debug | APP_DEBUG=false; Server-Timing on in the perf pool only |
| Providers | DEVELOPMENT (fake) payment, payout, push, routing — no real provider, no money |

## 3. PERFORMANCE BASELINE

One client, medium dataset (`m40-baseline-medium.json`); p50 after tuning from `m40-baseline-medium-after.json`. Full table: `docs/reliability/performance-baseline.md`.

| Endpoint | p50 | p90 | p95 | p99 | Error rate | DB queries | DB time ms | p50 after |
|---|---|---|---|---|---|---|---|---|
| `GET /live` | 43.1 | 58.3 | 62.9 | 67.3 | 0.0 % | 0 | 0 | 27 |
| `GET /health` | 100.1 | 189.2 | 191.2 | 197.3 | 0.0 % | 2 | 67.6 | 87.1 |
| `GET /restaurants` | 180.4 | 268.7 | 286.9 | 305.4 | 0.0 % | 16 | 107.5 | 198.2 |
| `GET /restaurants/{restaurant}/menu` | 136 | 157.1 | 229.3 | 251.9 | 0.0 % | 14.2 | 93.8 | 141.3 |
| `POST /journeys/plan (long)` | 147.8 | 168.3 | 255.3 | 278.5 | 0.0 % | 21 | 103.4 | 154.2 |
| `GET /journeys/{journey}/restaurants` | 171.7 | 186.1 | 188.8 | 283.5 | 0.0 % | 18 | 97.4 | 190.3 |
| `POST /cart/items` | 248.8 | 323.9 | 365.6 | 365.6 | 0.0 % | 46.1 | 167 | 228.6 |
| `POST /checkouts/{checkout}/confirm` | 283.6 | 324.2 | 328.9 | 328.9 | 0.0 % | 53.2 | 177.9 | 277.5 |
| `POST /checkouts/{checkout}/payment-attempts` | 310 | 436.7 | 449.5 | 449.5 | 0.0 % | 59.5 | 206 | 300 |
| `POST /payments/{payment}/complete` | 306.8 | 412.9 | 417.3 | 417.3 | 0.0 % | 51.1 | 183.5 | 290.7 |
| `POST /restaurant/orders/{order}/accept` | 251.6 | 347.6 | 357.3 | 357.3 | 0.0 % | 46 | 161.3 | 249.8 |
| `POST /restaurant/pickup/verify` | 301.8 | 327.1 | 331.8 | 331.8 | 0.0 % | 79.4 | 160.1 | 293.1 |
| `GET /customer/orders` | 110.4 | 161.8 | 206.8 | 250.5 | 0.0 % | 5.1 | 70.8 | 119 |
| `GET /restaurant/orders (active board)` | 150 | 171.5 | 187.5 | 194.6 | 0.0 % | 8.2 | 88.9 | 139.3 |
| `GET /restaurant/finance/summary` | 273.8 | 301.6 | 369.3 | 382.5 | 0.0 % | 14.3 | 212.7 | 217.4 |
| `GET /admin/orders` | 136.7 | 226.6 | 241.9 | 251.2 | 0.0 % | 5.1 | 90.9 | 117.5 |
| `GET /admin/finance/overview` | 427.2 | 648.1 | 702.9 | 899.6 | 0.0 % | 24.5 | 292 | 485.4 |
| `GET /admin/analytics/overview` | 111.4 | 210.5 | 225.9 | 227 | 0.0 % | 4.1 | 77.8 | 106.1 |
| `GET /admin/support/cases (service desk)` | 168.5 | 235.7 | 260.1 | 290.8 | 0.0 % | 6.2 | 104.2 | 123.6 |

## 4. LOAD TESTS

| Test | Users | Duration | Requests | Throughput | p95 ms | p99 ms | Errors (5xx + transport) | Bottleneck |
|---|---|---|---|---|---|---|---|---|
| Smoke | 1 | 7 s | 36 | 5.4 req/s | 294.9 | 302.9 | 0 (429: 0) | — |
| Expected | 30 | 186 s | 5042 | 27.1 req/s | 1016.6 | 1300.9 | 0 (429: 0) | host CPU (saturated) |
| Expected (pooled DB) | 30 | 183 s | 8195 | 44.8 req/s | 295.8 | 598.3 | 0 (429: 29) | not saturated (host CPU avg 43 %) |
| Peak | 75 | 188 s | 6737 | 35.8 req/s | 3448.9 | 4513.2 | 0 (429: 0) | host CPU (saturated) |
| Peak (pooled DB + Redis, no named prepares) | 75 | 184 s | 17728 | 96.3 req/s | 984.8 | 1339 | 0 (429: 0) | host CPU (saturated) |
| Stress | 20 → 200 | 319 s | 11089 | 34.8 req/s | 6155.8 | 7295 | 0 (429: 0) | host CPU (saturated) |
| Spike | 10 → 150 → 10 | 243 s | 6221 | 25.6 req/s | 4944.2 | 6162.8 | 0 (429: 0) | host CPU during the 150-VU burst |
| Soak (pooled) | 25 | 1803 s | 83734 | 46.4 req/s | 149.1 | 433.5 | 0 (429: 0) | not saturated (host CPU avg 40 %) |

Persistent-connection A/B and the per-stage stress / spike figures: `docs/reliability/load-test-results.md` §2. The 5 × 500 of the "peak / persistent DB only" run were Windows client-port exhaustion on per-request Redis connections (REL-041), not the application.

## 5. DATABASE

| Item | Result |
|---|---|
| Slow queries | pg_stat_statements per endpoint (`m40-query-profile-*`): board 17.4 → 1.6 ms, admin list 16.2 → < 5 ms, finance period 50.3 → 4.2 ms, held lines 88 → 8 ms, daily-customers aggregation 11.1 s → 0.21 s (identical results) |
| Indexes added | 3, CONCURRENTLY: orders (location_id, pickup_at, id), orders (placed_at desc, id), ledger_entries (occurred_at) |
| Indexes removed | 0 (no removal on development-size evidence) |
| N+1 | none over rows: no statement more than 5× per request (`m40-write-profile-*`); half of all statements were DEALLOCATE (named prepares, REL-042) |
| Pagination | every list paged with a stable tie-breaker; keyset for very deep admin histories recommended (REL-037) |
| Connections | a connection per request ≈ 30 ms on Windows; persistent A/B expected p50 452 → 91 ms; full / refusing PostgreSQL → fast 500, /health 503, recovery without restart |
| Locking | 0 lock waits in every profile (1 in persistent stress) |
| Deadlocks | pickup race: 102 deadlocks → 0 after FOR NO KEY UPDATE (REL-035); none elsewhere |
| Timeouts | statement 15 s / lock 5 s / idle-in-transaction 60 s proposed — PENDING BUSINESS APPROVAL |
| PgBouncer readiness | ready (no session state; transaction-scoped advisory locks; named prepares can be disabled) |
| Read replica readiness | not needed at the measured sizes; reports only, never money / order paths |

## 6. POSTGIS

| Item | Result |
|---|---|
| Journey candidate query | one GiST probe per route piece (0.057 ms); 16.8 ms for a 586 km route |
| Service area | coverage along the route: sequential scan (geography cast), ≈ 1 ms per area — rewrite per piece before ≈ 100 areas (REL-029, open; 15 areas today) |
| Spatial index | GiST on restaurant_locations.location (geography) and service_areas.geometry; 5 GiST indexes verified after every restore |
| Long routes | segmentize 2 km + subdivide ≤ 5 vertices; simplified 25 m, capped 2,000 points |
| Candidate bound | corridor width + SQL limit 2,000 + shortlist cap 40 (8 per 25 km segment) before any provider detour call |
| EXPLAIN ANALYZE | docs/reliability/postgis-performance.md §2–3 |
| Performance | journey planning p50 148–164 ms (medium) / 158–191 ms (large), corridor restaurants 172 / 232 ms — DEVELOPMENT routing provider |

## 7. REDIS / CACHE

| Item | Result |
|---|---|
| Cache | public catalogue / market / journey / analytics answers; never money, order status, pickup credentials or authorization answers (RBAC grants are version-keyed) |
| TTL | 60–300 s per domain, configurable (PENDING BUSINESS APPROVAL) |
| Invalidation | version keys (market, restaurant, menu catalog_version, RBAC, analytics generation) |
| Stampede | journey candidates single-flight; analytics answers after the 5-min generation bump: recorded (REL-018, low) |
| Tenant isolation | keys scoped by market / menu / journey / principal; analytics by the full scope |
| Redis failure | measured (`m40-failure-redis.json`): every route except /live 500 after ≈ 2 s; back empty → 200 without restart; lost order jobs recovered by orders:recover after its 60 s grace, each exactly once |
| Memory | peak 5–17 MB in the load runs |
| Eviction | noeviction required; critical alert redis_eviction_policy added |
| Locks | every money lock backs a row lock + unique key; overlap locks expire (10 min / 2 h) |

## 8. QUEUES

| Item | Result |
|---|---|
| Queues | orders, payments, finance, notifications, default (priority order) |
| Workers | supervised in production (REL-027); a dedicated orders,payments worker recommended |
| Priorities | paid customers first; exports last |
| Timeouts | every job below retry_after 90 s (export 300 → 80 s, REL-009) |
| Retries | only where safe; single-try money jobs + sweeps |
| Idempotency | row locks, unique keys (posting_key, checkout_public_id), idempotency keys |
| Failed jobs | visible in the reliability view; 96 local ones = end-to-end clean-up noise (REL-012) |
| Backlog | 20 queued order jobs → 20 orders in 3.1 s (`m40-failure-queue.json`); oldest job ≤ 2 s in every load run |
| Worker crash | killed holding a job → re-delivered after retry_after (≈ 94 s), 12/12 orders exactly once (`m40-failure-crash.json`) |
| Side-effect recovery | sweeps rebuild work from PostgreSQL state; no duplicate order or posting in any scenario |

## 9. REALTIME

| Item | Result |
|---|---|
| Connections tested | 1000 requested → 255 established (local Windows ceiling, REL-011); 180 + 50 staff + 10 customer sockets within it |
| Events/sec | 30 order transitions → 1500 staff + 30 customer deliveries |
| Latency | staff p50 198 / p95 300 ms from the request |
| Reconnect | 230/230 back in 1.7 s |
| Reconnect storm | all at once; 50 private channels re-authorized through the API; 0 failures |
| Missed events | 0 (every expected delivery arrived) |
| Duplicate events | version / sequence in every payload; clients resync |
| Fallback | REST resync + polling (Module 32) |
| Failure recovery | Reverb down: kitchen actions 200, ≈ 1.2 s instead of ≈ 0.2 s; broadcast timeouts restored (REL-007) |

## 10. PROVIDERS

| Provider | Timeout | Retry | Rate limit | Failure behaviour | Fallback |
|---|---|---|---|---|---|
| Google (routing / places) | 8 s / 5 s (connect ≤ 3 s) | 2 attempts, backoff + jitter, transient only | 429 not retried | 503 with retry_after; no fabricated route (feature tests) | cached route 300 s; browse by city |
| Razorpay (payments) | 15 s (connect ≤ 5 s) | reads 2×; create never repeated (receipt) | n/a | measured with the DEVELOPMENT provider: 503 in ≈ 1.4 s, cart unlocked | UNKNOWN → reconcile; PENDING PROVIDER VERIFICATION |
| Refund | 15 s | same idempotency key ≤ 2 resubmits after a lookup | not-sent retried 60 s → 1 h | REVIEW_REQUIRED after the budget (feature tests) | refunds:reconcile; PENDING PROVIDER VERIFICATION |
| FCM | 10 s, no connect timeout (REL-015) | 5 attempts, 30 s × 2ⁿ ≤ 900 s | Retry-After honoured | auth failure pauses push 300 s (feature tests) | inbox + realtime; PENDING PROVIDER VERIFICATION |
| Email | SMTP none (REL-014) | 5 attempts (notifications) | — | invitations sent inside a transaction (REL-014) | — |
| SMS | 10 s, connect 5 s | retry 2 (also on read timeouts — REL-016) | — | 503 otp_delivery_failed | fallback SMS driver |
| Storage | local disk | — | — | export failure → FAILED, partial file deleted; not live-injected | object storage needed for multi-host (REL-049) |

## 11. HEALTH / OBSERVABILITY

| Item | Result |
|---|---|
| Liveness | GET /live — no dependency, not rate limited (200 during the Redis outage after the change) |
| Readiness | /health (database, Redis), /ready (detail, restricted) |
| Dependency Health | GET /admin/system/reliability + ops:reliability (database, PostGIS, Redis, queues, failed jobs, domain signals) |
| Correlation IDs | X-Request-Id on every answer; carried into queued jobs (tested with a real Redis worker) |
| Structured Logs | http.request lines (route pattern, status, duration, queries, actor type) for slow requests and 5xx |
| Metrics | Server-Timing (opt-in), the reliability view, per-domain /admin/system views |
| Alerts | 20 keys with [warning, critical] thresholds (PENDING BUSINESS APPROVAL); no collector connected (REL-030) |
| Runbooks | docs/reliability/runbooks.md: API 5xx, database, Redis, queue backlog, Razorpay, Google Routes, FCM, realtime, backup failure, ledger imbalance |

## 12. BACKUP

| Item | Result |
|---|---|
| Database Backup | development database 2,765,993 B in 1034 ms; perf medium ≈ 63 MB in 7.8–9.2 s; perf large 267,469,870 B in 44872 ms |
| Method | pg_dump -Fc -Z 6 --no-owner --no-privileges (application role), SHA-256 recorded |
| Size | see above |
| Duration | see above |
| Encryption Readiness | production: encrypted object storage in another account — PENDING BUSINESS / INFRASTRUCTURE APPROVAL |
| Retention | 35 days PITR + monthly snapshots proposed — PENDING BUSINESS APPROVAL (financial-record retention PENDING LEGAL REVIEW) |
| Monitoring | runbook RB-09; destination = deployment |

## 13. RESTORE

| Item | Result |
|---|---|
| Restore Completed | YES |
| Restore Duration | development database 2960 ms + analyze 707 ms; perf medium 8.6–10.4 s + analyze 3.3–16.9 s |
| PostGIS | version 3.6.2, 15/15 service areas ST_IsValid, 5 GiST indexes |
| Orders / Payments / Refunds / Ledger / Settlements | development database: 138 tables, 20212 rows, tables differing from the source: 0; DR copy row counts identical to the backup |
| Integrity Checks | finance:verify-ledger, finance:reconcile-internal, payments:reconcile, refunds:reconcile, orders:recover, analytics:aggregate + check: finance:verify-ledger 0, finance:reconcile-internal 0, payments:reconcile 0, refunds:reconcile 0, orders:recover 0, analytics:aggregate 0, analytics:check 0 (exit codes) |

**RESTORE COMPLETED = YES**

## 14. DISASTER RECOVERY

| Item | Result |
|---|---|
| Plan | docs/reliability/disaster-recovery.md (R1–R7) + runbooks |
| Tabletop | desk walkthrough EXECUTED (engineer only; found REL-043, REL-044, REL-030); with the on-call team NOT HELD |
| Technical Exercise | 2 runs (`m40-dr-exercise.json`, `m40-dr-exercise-first-run.txt`): backup → business → primary lost → restore → application → first order picked up → integrity |
| Test RTO | 22.0 s and 38.5 s (technical part) |
| Test RPO | everything after the last dump (3 of 3 orders written after the backup missing) |
| Production RTO | PENDING BUSINESS / INFRASTRUCTURE APPROVAL (proposal ≤ 1 h) |
| Production RPO | PENDING BUSINESS / INFRASTRUCTURE APPROVAL (proposal ≤ 5 min with PITR) |

## 15. FAILURE INJECTION

| Failure | Result |
|---|---|
| Database | refusing: /live 200, /health 503, others fast 500, recovery without restart; connections killed mid-purchase: exactly once kept; full: see m40-failure-connections.json |
| Redis | every route but /live 500; restarted empty → sweeps recovered every lost job |
| Queue | no worker: payments verified, orders waited; a worker created 20/20 in 3.1 s |
| Realtime | kitchen actions still 200 (≈ 1.2 s); admin view UNAVAILABLE |
| Routing | feature tests (fake provider failures); not live-injected |
| Payment | DEVELOPMENT provider down: 503 in ≈ 1.4 s, cart unlocked, recovery immediate |
| Refund | feature tests (Module 35 fake provider failure modes); not live-injected |
| FCM | feature tests (Module 33); not live-injected |
| Storage | not injected (local disk); export failure path tested |
| Worker Crash | re-delivered after ≈ 94 s; 12/12 exactly once |
| App Restart | 5 s outage; pool back in 1.3 s; 32 paid → 32 orders |

## 16. WEB PERFORMANCE

| Item | Result |
|---|---|
| Bundle | customer entry 1,474.73 kB (407.68 kB gzip); Dashboard 436.78 kB (lazy); Admin 771.31 kB (lazy); Charts 84.70 kB (lazy) |
| Initial Load | customer home: FCP 520 ms, LCP 2720 ms cold; restaurant page: FCP 460 ms, LCP 3348 ms cold; menu item: FCP 368 ms, LCP 4476 ms cold; restaurant dashboard (sign-in): FCP 436 ms, LCP 2864 ms cold; platform admin (sign-in): FCP 520 ms, LCP 2128 ms cold |
| Images | one static hero image; menu images through the API media route |
| Maps | no map library bundled |
| API Waterfall | item page: 8 calls, chain restaurant → item → menu, list + cuisines not shown (REL-054) |
| Realtime Cleanup | subscriptions closed on unmount / sign-out (tests) |
| Lighthouse/Equivalent | Playwright navigation timing + FCP / LCP + throttled phone run (`m40-web-vitals.json`): throttled home 3248 ms LCP |

## 17. ANDROID PERFORMANCE

| Item | Result |
|---|---|
| Build Type | local debug APK (build044) + release-mode size analysis |
| APK Size | debug 235.7 MB; release analysis: {"debug_MB": 235.7, "release_arm64_MB": 23.5, "dart_aot_MB": 9.1, "engine_MB": 11.2} |
| Startup | NOT MEASURED on a device (no device session; USER DEVICE VERIFICATION = PENDING) |
| Network | no duplicate polling (Module 32 / 38 tests) |
| Images | framework image cache (bounded) |
| Memory | not profiled on a device |
| Realtime | socket closed when the order screen closes |
| Battery | no background socket; not measured on a device |

## 18. RESTAURANT DASHBOARD

| Area | Result |
|---|---|
| Orders | board p50 150 ms medium → 173.3 ms large (187,515 orders at one location; index REL-002) |
| Menu | public menu p50 136 ms; cached by catalog_version |
| Analytics | read models; 4–8 queries per report |
| Finance | summary p50 273.8 → 217.4 ms (REL-006); 553.4 ms with 187,515 unsettled lines |
| Support | paged; same shape as the service desk |
| Large Lists | paged 25, index-backed |

## 19. ADMIN DASHBOARD

| Area | Result |
|---|---|
| Orders | p50 128.2 ms on 187,515 orders (REL-003) |
| Payments | p50 129.4 ms |
| Refunds | p50 97.9 ms |
| Finance | overview p50 977.8 ms at 937,575 ledger entries — grows with the ledger (REL-005, open) |
| Support | service desk p50 164.2 ms (25,004 cases) |
| Analytics | overview p50 103.7 ms (2,000,068 events) |
| Exports | asynchronous (202), row-capped, timeout 80 s, closed FAILED at once on a worker timeout |

## 20. CAPACITY

TESTED on one development laptop (the load generator on the same machine) or ESTIMATED from the measured costs — **not guarantees**.

| Item | Value | Kind |
|---|---|---|
| Tested Requests/sec | 35.8 (a connection per request) · 96.3 (pooled) at 75 VUs, 0 application errors | TESTED |
| Tested Concurrent Users | 200 VUs (stress) without errors; latency p95 7.3 s at 200 | TESTED |
| Journey Searches | 20 % of the mix; journey planning p50 ≈ 150–190 ms one client | TESTED |
| Orders | 255 purchases in 184 s inside the full mix at 75 VUs (≈ 5,000 / hour on one laptop) | TESTED |
| WebSocket Connections | 255 per Reverb process on Windows; thousands per Linux node with ext-uv (not tested) | TESTED / ESTIMATED |
| Jobs/min | oldest queued job ≤ 2 s at peak; 20 order jobs in 3.1 s on one worker | TESTED |
| Database Growth | ≈ 15.5 KB per order (rows + indexes) + ≈ 3 KB of events; 3,412 MB at 187,515 orders | TESTED (large tier) |
| Storage Growth | exports expire after 24 h; images bounded by size limits | ESTIMATED |
| Primary Bottleneck | PHP CPU (and, without pooling, connection set-up); then Windows client ports (Redis); the Windows Reverb ceiling | TESTED |
| Recommended Headroom | 2× the forecast peak + one spare host — PENDING BUSINESS APPROVAL | RECOMMENDATION |

## 21. SCALE READINESS

| Item | State |
|---|---|
| Stateless App | YES (bearer tokens in PostgreSQL) |
| Shared Session | n/a (no API session) |
| Shared Storage | NO — exports / images on local disks (REL-049, open) |
| Shared Redis | YES |
| Worker Scaling | YES (idempotent jobs) |
| Realtime Scaling | PARTIAL — one node measured; multi-node via Redis scaling not tested |
| Connection Pooling | READY (PgBouncer transaction mode; opt-in persistent connections measured) |
| Read Replica | NOT NEEDED yet |
| Autoscaling | NOT CONFIGURED (PENDING BUSINESS / INFRASTRUCTURE APPROVAL) |

## 22. DEPLOYMENT READINESS

| Item | State |
|---|---|
| Safe Shutdown | workers finish the current job (SIGTERM / queue:restart); PHP-FPM graceful reload |
| Traffic Drain | readiness /health, liveness /live; maintenance mode is per host (REL-043) |
| Backward-Compatible Schema | expand → migrate → contract; indexes CONCURRENTLY; migration rollback tested |
| Queue Compatibility | jobs carry scalar identifiers |
| Webhook Compatibility | stored first, processed by a job; providers retry |
| Android API Compatibility | /api/v1 additive; no minimum-client-version mechanism (REL-050) |
| Rollback | previous release + queue:restart; additive migrations |

## 23. RELIABILITY FINDINGS

Register REL-001 … REL-055: `docs/reliability/` documents and below.

| Severity | Count / items |
|---|---|
| Critical Open | 0 |
| High Open | 0 blocking Module 41 — production-dependent decisions carried: connection strategy (CF-558), shared storage before a second app host (CF-560), observability destinations (CF-566), production-like load test (CF-570) |
| Medium | REL-005 finance balances growth, REL-014 invitations / SMTP, REL-019 Redis hard dependency (decision), REL-024 FCM token in Redis, REL-028 failed webhook surfacing, REL-038 no circuit breaker, REL-043 maintenance driver, REL-049 shared storage, REL-050 minimum client version |
| Low | REL-015, REL-016, REL-017, REL-018, REL-023, REL-025, REL-026, REL-029, REL-036, REL-037, REL-051, REL-054 |
| Critical Fixed | 0 (none found) |
| High Fixed | REL-035 pickup race deadlock (BUG-324), REL-007 broadcast timeouts (BUG-325), REL-008 scheduler 24 h locks (BUG-327), REL-052 aggregation catch-up (BUG-328), REL-041 persistent Redis shared socket (BUG-331) |

## 24. SECURITY REGRESSION

Status: PASS — tests/Feature/Security in the full backend suite (route inventory now checks the real middleware of every API route; the only unthrottled route is /live by design); security:check locally 10 / 32 (development install, identical items to Module 39) and --as-production exits 1 (`backend-m40-security-check.txt`). No Module 39 control was removed for a benchmark.

## 25. FINANCIAL REGRESSION

| Area | Status |
|---|---|
| Payment | PASS — feature tests; 6 payments × 6 concurrent completions + duplicated webhook → 1 order, 1 posting each |
| Refund | PASS — feature tests |
| Order | PASS — feature tests; crash / restart / Redis loss / connections killed: exactly once |
| Ledger | PASS — finance:verify-ledger OK after every load, failure and restore run (375,030 transactions on the large tier) |
| Settlement | PASS — feature tests; payables match after every run |
| Payout | PASS — feature tests (live payouts disabled) |

## 26. TESTING

| Area | Passed | Failed | Skipped |
|---|---|---|---|
| Performance (baselines small / medium / large) | all cycles 15/15 | 0 | — |
| Load | expected / peak / A/B: 0 application errors | 0 | — |
| Stress | 20 → 200 VUs, 0 errors | 0 | — |
| Spike | 0 errors | 0 | — |
| Soak | see §4 | — | — |
| Database | query / write profiles | — | — |
| PostGIS | EXPLAIN | — | — |
| Redis | failure + recovery | 0 | — |
| Queue | queue / crash | 0 | — |
| Realtime | 1,500/1,500 + 30/30 deliveries | 0 | 1,000-connection target (Windows ceiling) |
| Providers | payment (live), routing / refund / FCM (feature tests) | 0 | real providers |
| Backup | 3 backups | 0 | — |
| Restore | 0 tables differ | 0 | — |
| DR | 2 exercises | 0 | tabletop with the team |
| Failure Injection | 9 scenarios | 0 | storage |
| Web | vitest 1016/1016; web vitals | — | — |
| Android | flutter 683; preview 17/17 | — | device startup |
| Security | Security suite in the backend run; security:check | — | — |
| Regression | backend 1382/1382 (62226 assertions); browser 1145/1168 over 19 suites, new misses: none | — | — |

## 27. EVIDENCE

| Area | Files |
|---|---|
| Load Tests | docs/local-review/test-results/m40-load-*.json, m40-load-peak-redis-persistent-shared-socket.txt |
| Database | docs/local-review/test-results/m40-query-profile-*.json, m40-write-profile-*.json, m40-aggregation-equivalence.json |
| PostGIS | docs/reliability/postgis-performance.md (EXPLAIN) |
| Redis | docs/local-review/test-results/m40-failure-redis.json, m40-failure-first-pass.txt |
| Queue | docs/local-review/test-results/m40-failure-queue.json, m40-failure-crash.json |
| Realtime | docs/local-review/test-results/m40-realtime-1000.json, m40-realtime-240.json, m40-failure-reverb.json |
| Providers | docs/local-review/test-results/m40-failure-payment.json |
| Backup | docs/local-review/test-results/m40-backup-*.json |
| Restore | docs/local-review/test-results/m40-backup-restore-local.json |
| DR | docs/local-review/test-results/m40-dr-exercise.json, m40-dr-exercise-first-run.txt |
| Web | docs/local-review/test-results/web-build-m40.txt, m40-web-vitals.json, web-build-lint-m40.txt |
| Android | docs/local-review/test-results/m40-android-size.json, flutter-test-m40.txt, m40-android-preview-e2e.json |
| Metrics | docs/local-review/test-results/m40-integrity-*.json, m40-laravel-caches.json |
| Tests | docs/local-review/test-results/backend-m40-phpunit.txt, backend-m40-migration-check.txt, backend-m40-security-check.txt, m40-regression-*.json, m40-secret-scan.txt |

## 28. PROJECT TRACKER

Updated: /docs/project-progress.html (M40-001 … M40-210 + discovered rows M40-211 … M40-225, decisions, CF-556 … CF-572, BUG-324 … BUG-334, TE-443 … TE-463). Nothing of Modules 01–39 removed.

## 29. PRODUCTION READINESS

| Area | State |
|---|---|
| PERFORMANCE | PARTIAL — measured locally; a production-like load test is required (CF-570) |
| DATABASE | COMPLETE (local) — PgBouncer / timeouts PENDING BUSINESS / INFRASTRUCTURE APPROVAL |
| POSTGIS | COMPLETE (local) — REL-029 before ≈ 100 service areas |
| CACHE | COMPLETE (local) — Redis HA / fail-open decision (CF-559) |
| QUEUES | COMPLETE (local) — worker supervision is deployment |
| REALTIME RELIABILITY | PARTIAL — Linux / ext-uv / multi-node not tested (CF-567) |
| PROVIDER RESILIENCE | PARTIAL — real providers PENDING PROVIDER VERIFICATION; gaps CF-565 |
| BACKUP | COMPLETE (local) — production backups / PITR PENDING BUSINESS / INFRASTRUCTURE APPROVAL |
| RESTORE | COMPLETE (local) |
| DISASTER RECOVERY | PARTIAL — technical exercise done; tabletop with the team not held; production RTO / RPO PENDING BUSINESS / INFRASTRUCTURE APPROVAL |
| OBSERVABILITY | PARTIAL — signals built; no collector / alerting destination (CF-566) |
| FINAL E2E QA | NOT COMPLETE |
| PRODUCTION | NO |

## 30. MODULE 41 READINESS

**MODULE 41 MAY START = YES**

## 31. NEXT MODULE

NEXT: MODULE 41 — FINAL END-TO-END QA, FULL REGRESSION, WEB + ANDROID ACCEPTANCE TESTING, PRODUCTION CREDENTIAL / CONFIG REVIEW, LEGAL / ASSET / BUSINESS APPROVALS, DOCUMENTATION COMPLETION, BUG CLOSURE & FINAL PRODUCTION READINESS GATE

STOP. DO NOT automatically begin Module 41.
