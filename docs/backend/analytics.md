# Analytics, reporting and exports — backend run book (Module 38)

LOCAL DEVELOPMENT ONLY. Production: NO. A read / aggregation layer: it reads the domains of Modules 20–37 and writes
only `analytics_*` tables and `report_exports`. Definitions and design: [`docs/analytics/`](../analytics/README.md).

## What exists

| Piece | Where |
|---|---|
| Configuration (registry, funnel, bands, privacy, periods, aggregation, cache, exports, retention) | `backend/config/analytics.php` |
| Schema: 16 tables + 9 source-table indexes | `backend/database/migrations/2026_10_18_100000_module38_analytics.php` |
| Events: registry, ingestion, server-recorded events | `app/Services/Analytics/{EventRegistry,EventIngestService,ServerEventRecorder,Pseudonymizer,Bands}.php`, `app/Events/Journey/*`, `app/Listeners/RecordAnalyticsEvents.php` |
| Aggregation | `app/Services/Analytics/Aggregation/{AggregationService,FactBuilder,DailyAggregator}.php` |
| Period, scope, figures, catalog, read helper, cache | `app/Services/Analytics/{AnalyticsPeriod,AnalyticsScope,Metric,MetricCatalog,ReadModel,AnalyticsCache}.php` |
| Reporting services | `app/Services/Analytics/{Platform,Customer,Funnel,Order,Restaurant,Route,Payment,Refund,Finance,Support}AnalyticsService.php` |
| Data quality | `app/Services/Analytics/DataQualityService.php` |
| Exports | `app/Models/ReportExport.php`, `app/Services/Analytics/Reports/*`, `app/Jobs/Analytics/GenerateReportExportJob.php` |
| HTTP | `app/Http/Controllers/Api/Admin/Analytics/*`, `Api/Restaurant/AnalyticsController.php`, `Api/AnalyticsEventController.php` |
| Commands | `analytics:aggregate`, `analytics:rebuild`, `analytics:check`, `analytics:prune`, `analytics:catalog` |

35 routes: 21 `/admin/analytics/*` (17 reports and definitions + 4 exports), 12 `/restaurant/analytics/*` (8 + 4),
`/admin/system/analytics`, `POST /analytics/events`. OpenAPI: `backend/openapi/openapi.json`.

## Permissions

| Permission | Holders (seeded roles) | Opens |
|---|---|---|
| `admin.analytics.view` | SUPER_ADMIN, OPERATIONS_ADMIN, RESTAURANT_ONBOARDING, FINANCE_ADMIN, ANALYST | The administrators' reports, market-scoped |
| `admin.analytics.export` (new) | SUPER_ADMIN, OPERATIONS_ADMIN, FINANCE_ADMIN, ANALYST | Asking for and downloading exports |
| `admin.finance.view` / `admin.support.view` | as before | Additionally needed for the finance / support report and blocks |
| `admin.finance.export`, `admin.payments.view`, `admin.refunds.view` | as before | Additionally needed for those exports |
| `restaurant.analytics.view` | OWNER, MANAGER, VIEWER | A restaurant's reports, per location |
| `restaurant.analytics.export` (new) | OWNER, MANAGER | A restaurant's exports |
| `restaurant.finance.view` | as before | Additionally needed for earnings |
| `admin.system.view` | as before | `/admin/system/analytics` |

After pulling this module, re-seed the roles so the two new permissions reach them:
`php artisan db:seed --class=RoleSeeder`.

## Running it locally

```
php artisan migrate
php artisan db:seed --class=RoleSeeder
php artisan analytics:rebuild                 # backfill from everything the domains hold
php artisan analytics:check                   # the ten integrity checks
php artisan queue:work redis --queue=orders,payments,finance,notifications,default   # exports are built by a queued job (queue "default")
php artisan schedule:work                     # analytics:aggregate every 5 min, :check hourly, :prune daily
```

| Command | What it does |
|---|---|
| `analytics:aggregate` | What changed since the last successful run + the two most recent local days. One run at a time. |
| `analytics:rebuild [--from=YYYY-MM-DD] [--to=] [--group=*] [--market=slug] [--dry-run]` | A range again; without a range, the backfill. Idempotent. |
| `analytics:check` | Runs the checks, stores HEALTHY / WARNING / ERROR; exit code 1 on ERROR. |
| `analytics:prune [--forget-customer=<public id>]` | Retention: raw events, old sessions and run records, expired export files; optionally makes one customer's behavioural trace anonymous. |
| `analytics:catalog [--events] [--write]` | Prints the metric catalog / the event registry as Markdown; `--write` writes `docs/analytics/metric-catalog.md` and `event-registry.md`. |

Each prints one JSON line.

## Environment

See `.env.example` ("Module 38"). The ones that matter locally:

| Variable | Default | Meaning |
|---|---|---|
| `ANALYTICS_EVENTS_ENABLED` | true | Off = the event door stores nothing and the backend records no event of its own |
| `ANALYTICS_PSEUDONYM_KEY` | (derived from `APP_KEY`) | The key of the customer / journey pseudonyms |
| `ANALYTICS_MIN_COHORT` / `ANALYTICS_MIN_DENOMINATOR` | 5 / 5 | Privacy threshold of fine-grained rows / smallest denominator of a rate (tests set both to 1) |
| `ANALYTICS_CACHE_ENABLED` / `ANALYTICS_CACHE_SECONDS` | true / 60 | The answer cache (off in tests) |
| `ANALYTICS_AGGREGATION_SCHEDULED` / `ANALYTICS_QUALITY_SCHEDULED` | true / true | The scheduled runs |
| `ANALYTICS_EXPORT_EXPIRY_HOURS` / `ANALYTICS_EXPORT_MAX_ROWS` | 24 / 50000 | Exports |
| `ANALYTICS_RAW_EVENT_DAYS` | 180 | Retention of raw behavioural events |
| `RATE_LIMIT_ANALYTICS_READ` / `_EXPORT` / `_INGEST` | 120 / 10 / 60 per minute | Request limits |

Clients: analytics follows the ORDER mode (`VITE_ORDER_MODE=api` / `ORDER_MODE=api`); `VITE_ANALYTICS_MODE` /
`ANALYTICS_MODE` (`api` | `off`) overrides it.

## Rules that must hold

- Analytics never writes to an order, a payment, a refund, the ledger, a case or a review, and nothing in those
  domains reads an analytics table.
- A behavioural event is telemetry. The registry has no payment, order or refund fact; stages 9–11 of the funnel
  are read from the payment and order domains.
- Recording an event never fails a customer's request: the event door always answers 202 and the server-side
  recorder swallows its own errors (in a savepoint, so a caller's transaction is not poisoned).
- Scope is the server's: markets for an administrator, the caller's own locations for a restaurant member. A query
  string only chooses among what the caller may already see.
- Money per currency, never added across currencies; finance totals from the finance module, never recomputed.
- No figure without a definition: `Metric` refuses a key the catalog does not know.
- Only plain arrays are cached; finance totals, earnings and live counts are never cached.

## Traps

- **The backfill ends today.** `analytics:rebuild` without a range stops at the current local day; days in the
  future (test fixtures) must be named with `--from/--to`.
- **A test that writes read-model rows by hand must do it after the aggregation** — the aggregation owns its tables
  and rewrites the days it runs.
- **Hour buckets are labels**, not dates: on the day the clocks go back there are 25, one of them named with the
  zone's abbreviation (`2026-11-01 01:00 (EST)`).
- **Exports need the queue worker** locally; with `QUEUE_CONNECTION=sync` (tests) they are READY at once.
- **Bulk test fixtures hit the request limits** (`throttle:api`, 120 / minute per address): flush the cache between
  batches in a test.
- The read models describe whatever is in the local database — on this machine that is test and e2e data, not
  business figures.
- **The web emitter must read every answer to its end.** A response body nobody reads keeps the request (and its
  connection) open in the browser; a browser suite then never reaches "network idle" (BUG-314). The emitter reads
  the answer and throws it away.
- **`keepalive` is for the last send of a page only.** Browsers keep a small shared quota for keepalive bodies, and
  Chrome sends such a request itself, outside a browser automation's reach: Playwright neither reports nor
  intercepts it. To make the event door unreachable in a browser test, make `fetch` itself reject for that address
  (an init script) — intercepting the route blocks only the ordinary sends.
- **A session with holes is "out of order".** When earlier batches of a session were lost and later ones arrive, the
  session counts only as far as it came in order, and the `funnel_transitions` check reports it (WARNING above
  10 % of the sessions). That is the check doing its job, not a defect of the aggregation.

## Tests

`backend/tests/Feature/Analytics/` — 44 tests: aggregation and idempotency, administrators' and restaurants' APIs,
events, funnel and customers, time zones / currencies / market scope, exports, the per-domain reports, the life of
the read models (late events, backfill, data quality, failure, retention), performance and query plans, the OpenAPI
contract, and the generated documents. Fixture helpers: `tests/Support/BuildsAnalytics.php`.
