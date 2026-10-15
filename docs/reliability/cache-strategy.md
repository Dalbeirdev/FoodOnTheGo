# Cache strategy

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources: `backend/config/cache.php`, `backend/config/database.php` (redis), `backend/app/Auth/AccessControl.php`, `backend/app/Services/Market/*`, `backend/app/Services/Restaurant/RestaurantCatalog.php`, `backend/app/Services/Menu/MenuCatalog.php`, `backend/app/Services/Journey/*`, `backend/app/Services/Analytics/AnalyticsCache.php`, `backend/app/Providers/AppServiceProvider.php` (rate limiters, version bumps), the Module 40 code inventory (three read-only reviews, 2026-10-14) and the Module 40 measurements in `docs/local-review/test-results/m40-*.json`.

Every TTL and limit below is an engineering default **PENDING BUSINESS APPROVAL**.

## 1. Principles

1. **PostgreSQL is the source of truth; Redis is never the only copy of anything that matters.** Orders, payments, refunds, the ledger, settlements, payouts, pickup credentials, slot capacity, OTP challenges, idempotency records and access tokens all live in PostgreSQL. Redis holds caches, rate-limit counters, locks, queued jobs and short-lived security state (MFA challenge, lockout counters, re-authentication marker).
2. **Nothing about money or authorization is answered from a cache.** These are always read live:
   - balances, ledger figures, settlements and payouts;
   - payment status, the order status shown to the customer, and pickup credentials;
   - slot capacity and idempotency records.
   
   Finance figures in analytics are delegated to the ledger and are never cached (Module 38).
3. **Cache keys carry their version or scope.** A write invalidates by moving a version number (market version, restaurant version, the menu's `catalog_version` stored in PostgreSQL, the RBAC version, the analytics generation), never by searching for keys to delete.
4. **Public catalogue answers are cached briefly; personal answers are not cached server-side.**

## 2. Inventory

| What | Key | TTL (env, default) | Invalidated by | Stampede protection |
|---|---|---|---|---|
| RBAC grants of a principal | `rbac:{version}:{type}:{id}` | 300 s | the RBAC version bump after a role or grant change commits | none (cheap rebuild) |
| Public market payload / list / coverage | `market:{ver}:…` | `GEO_MARKET_CACHE_SECONDS` 300 | the market version bump (model events, geography admin) | none |
| Cuisine taxonomy with counts | `market:{mver}:restaurant:{rver}:cuisines:{market}` | `RESTAURANT_PUBLIC_CACHE_SECONDS` 300 | restaurant version bump (every restaurant service and model event) | none |
| Public menu document and item detail | `menu:{menu}:v{catalog_version}:…` | `MENU_PUBLIC_CACHE_SECONDS` 300 | `catalog_version`, bumped inside the menu write transaction | none |
| Base route of a journey | `journey:route:{hash(provider, rounded coordinates, options, departure bucket)}` | `JOURNEY_ROUTE_CACHE_SECONDS` 300 | TTL | none |
| Place search | `journey:places:{provider}:{country}:{hash}` | `JOURNEY_PLACES_CACHE_SECONDS` 60 | TTL | none |
| Corridor candidates of a journey | `journey:{journey}:results:{filters}` | `JOURNEY_RESULTS_CACHE_SECONDS` 300 | TTL; ratings and open / closed are re-read on every page | **single-flight lock**: lock held 30 s, wait 10 s (`JOURNEY_COMPUTE_LOCK_SECONDS` / `JOURNEY_COMPUTE_WAIT_SECONDS`). If the wait times out, the request computes it itself. |
| Analytics report answers | `analytics:{generation}:{report}:{hash(scope, parameters)}` | `ANALYTICS_CACHE_SECONDS` 60 | generation bump after each aggregation run (every 5 min) | none (see REL-018) |
| Realtime / push health | `orders:realtime:health`, `notifications:health:push` | 10 s | TTL | none |
| FCM OAuth access token | `notifications:fcm:access-token` | token lifetime − 120 s | TTL | none |
| Hourly metric counters | `{domain}:metrics:{event}:{YmdH}` | retention hours per domain | TTL | n/a |

**Not cached (verified):**
- balances, ledger, settlements, payouts;
- payment status and order status;
- pickup credentials;
- pickup slot capacity (PostgreSQL counters with row locks);
- restaurant open / closed state;
- idempotency records.

## 3. Tenant isolation

- Public keys are scoped by market (and by menu or location). Journey keys belong to one journey.
- Analytics keys hash the full scope: audience, markets and locations (`AnalyticsScope`). A restaurant can therefore never receive another restaurant's cached answer, and an admin restricted to one market never receives an all-markets answer.
- RBAC keys are per principal. The scope check happens at decision time, not in the cache.
- No cache key contains a phone number, an e-mail address, a name or a coordinate.
- Rate-limit keys hash phone numbers and e-mail addresses.

## 4. Locks

| Lock | Store | TTL | Protects | If not acquired |
|---|---|---|---|---|
| `journey:{id}:results:{k}:lock` | Redis | 30 s, wait 10 s | duplicate routing-provider calls for one journey | computes anyway (correct, just more provider calls) |
| `payments:reconcile:{attempt}` | Redis | 30 s | two concurrent reconciliations of one payment | returns the attempt as it stands; the PostgreSQL row lock in the apply step still serialises |
| `refunds:submit:{id}` / `refunds:reconcile:{id}` | Redis | provider timeout + 30 s / 30 s | a double provider refund | returns the refund as it stands; the status (APPROVED → SUBMITTING) and the fixed idempotency key make a resend impossible |
| `finance:provider-import:{source}` | Redis | 600 s | concurrent settlement imports | 409 `import_running` |
| `analytics:aggregation` | Redis | 900 s | concurrent aggregation | the run is SKIPPED |
| scheduler overlap (`withoutOverlapping`) | default cache store | **10 min** for per-minute and five-minute sweeps, **2 h** for hourly ones (Module 40; was 24 h) | overlapping runs of a sweep | run skipped |
| scheduler single host (`onOneServer`) | default cache store | per run | a second scheduler host running the same entry | run skipped on the second host |
| Unique notification job | Redis | `uniqueFor` 300 s | duplicate dispatch of one delivery | released by `notifications:recover` |

**Database locks, not Redis:**
- cart rows (`lockForUpdate`);
- pickup slot capacity;
- order, payment, refund, payout and fulfilment rows;
- ratings (advisory transaction lock);
- support cases.

**Two layers for money.** Every Redis lock that protects money is a second layer over a PostgreSQL row lock and a unique key (`posting_key`, `checkout_public_id`, receipt / idempotency key). Losing Redis can therefore stop such work, but never make it run twice.

## 5. Stampede and warm-up

- Measured (`m40-baseline-medium.json`): public catalogue reads cost 14–20 queries and about 130–180 ms with one client on this machine.
  - A cold key costs one rebuild per process that misses at the same time.
  - With a 300 s TTL, the 2 PHP pools of a small production deployment add at most a few duplicate rebuilds per key every 5 minutes. This is acceptable without single-flight.
- **REL-018:** the analytics generation bump (every 5 minutes) makes every report key cold at once. With many concurrent admin viewers this is a burst of report rebuilds.
  - Today: there are a handful of admin viewers, and reports cost 4–8 queries (`m40-query-profile-medium*.json`).
  - Recommendation before many concurrent analysts: `Cache::flexible` (stale-while-revalidate) for report answers.
- No cache warming is needed. A cold start rebuilds on first use. A **Redis flush** loses only cache values and counters, plus the queued jobs (see `queue-strategy.md` and the failure-injection results).

## 6. Memory and eviction

- Measured during the expected-load run: Redis peak about 5 MB (`m40-load-expected-medium.json`, `redis_memory_mb_max`). The cache is small: public catalogue answers, a few hundred route and journey entries, counters.
- **Queued jobs and locks share Redis with the cache.** Under a memory limit, only `maxmemory-policy noeviction` guarantees that a waiting payment or order job is never evicted to make room.
- **Production:** either a separate Redis (or logical database with its own server) for the queue, or `noeviction` on the shared one.
- Module 40 added a **critical alert** to the reliability view (`redis_eviction_policy`): it fires when Redis has a memory limit and an evicting policy.

## 7. Redis unavailable

What the code does (verified):
- **Every `/api/v1` route except `/live`** is under the Redis-backed `throttle:api` limiter.
  - **Measured** with the perf-only Redis killed: every route answered 500 after the ≈ 2 s connect timeout.
  - Before Module 40, `/live` did too (`m40-failure-first-pass.txt`). Since Module 40, `/live` is not rate limited and answers 200 (`m40-failure-redis.json`).
  - With Redis back, everything answered 200 without restarting the application.
- **RBAC grants** are read through the cache, so staff and admin authorization needs Redis.
- **Queue dispatch** fails. The jobs of an already-committed state change (a verified payment) are recovered by the sweeps (`payments:reconcile`, `orders:recover`, `finance:post-pending`, `notifications:recover`), which read PostgreSQL.
- **Locks** throw. Nothing that needs a lock runs, and nothing runs twice.
- **No failover store is configured.** `failover` cache and queue definitions exist in config but are unused (REL-019).

## 8. Open items

| Id | Item | Severity |
|---|---|---|
| REL-018 | Analytics report cache: no stale-while-revalidate after the 5-minute generation bump | low |
| REL-019 | No cache / limiter / queue failover; Redis is a hard dependency of the API (via throttling) | high for production availability (decision) |
| REL-024 | The FCM OAuth access token is stored in plain text in Redis (protect Redis access, or encrypt the value) | medium |
| REL-025 | The `/health` probe pings the default Redis connection, not the cache connection | low |
| REL-026 | RBAC version bump is an after-commit cache write; if that write fails, grants may be stale for up to 300 s | low |
