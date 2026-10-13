# Analytics architecture

```
   Web / Android behavioural events                Operational domains (PostgreSQL)
   (what a session looked at)                      orders · payment attempts · refunds · financial ledger
              │                                    settlements · support cases · reviews · notifications
              ▼                                    journeys (planned → bands and corridors only)
   POST /api/v1/analytics/events                                  │
   registry · allowlist · dedupe                                  │  read only
              │                                                   │
              ▼                                                   ▼
   analytics_events (raw, short-lived)  ───────────►  AGGREGATION  (analytics:aggregate, every 5 minutes)
   + events the backend records itself                 facts:  analytics_order_facts, analytics_session_facts
     (journey planned, restaurants returned,           daily:  analytics_daily_market / _customers / _restaurant /
      no restaurant found, pickup time refused)                _items / _funnel / _routes / _finance / _support /
                                                               _breakdowns
                                                                  │
                                                                  ▼
                                               ANALYTICS SERVICES  (one per area, app/Services/Analytics)
                                               Platform · Customer · Funnel · Order · Restaurant · Route ·
                                               Payment (+ pickup) · Refund · Finance · Support (+ reviews,
                                               notifications)        Finance figures: Module 36's own services
                                                    │                              │
                                                    ▼                              ▼
                                    /admin/analytics/*  (market scope)   /restaurant/analytics/*  (own locations)
                                                    │                              │
                                                    ▼                              ▼
                                           Admin dashboard               Restaurant dashboard
                                                    │                              │
                                                    └────────── Secure CSV exports ┘
                                                    (queued job → private storage → requester only → expiry)
```

## Layers

| Layer | What it is | Where |
|---|---|---|
| Sources | The domains of Modules 20–37. Analytics reads them; it has no write path into any of them. | orders, payment_attempts, refunds, ledger_*, order_financial_allocations, restaurant_settlements, support_cases, restaurant_reviews, notifications… |
| Behavioural events | Telemetry of sessions (client) and of the service's own answers (server). | `analytics_events` |
| Fact tables | One row per order / per session. A copy that can be thrown away and made again. | `analytics_order_facts`, `analytics_session_facts` |
| Daily read models | Counts and sums per (market or location, local day). | `analytics_daily_*` |
| Services | One class per area — no single "AnalyticsService". They read the read models only (money: the finance module's services). | `backend/app/Services/Analytics/` |
| APIs | Reports for administrators and for restaurants, the event door, the exports. | `backend/app/Http/Controllers/Api/Admin/Analytics/`, `Restaurant/AnalyticsController.php`, `AnalyticsEventController.php` |

Dashboards never run ad hoc joins over the transactional tables: a report is a few indexed reads of the read models
(measured: at most 31 queries per report, the same number with one order as with three thousand — see
`docs/local-review/test-results/m38-performance.json`).

## Storage decisions

- **PostgreSQL, same database, tables prefixed `analytics_`.** At the present scale nothing else is justified.
- **Warehouse: NOT REQUIRED / NOT IMPLEMENTED.**
- **Materialized views: reviewed, NOT USED.** A materialized view is refreshed as a whole; the daily tables are
  recomputed one day at a time (below), which is the incremental form of the same idea and lets a late event or a
  refund of last week change exactly last week.
- **Redis: a short cache of API answers only** — never a balance, and nothing that authorizes an action.

## Aggregation

`analytics:aggregate` (scheduled every 5 minutes, one run at a time):

1. copies the orders that changed since the last successful run into `analytics_order_facts` (upsert) and rebuilds
   the facts of the sessions that received events;
2. finds the **local days that changed** — the most recent two, plus the day of every source row that changed since
   the last run (a late event, a refund of an old order, a case resolved today that was opened last week);
3. for each of those days and each group: **deletes the day and writes it again** from the sources.

Because a day is deleted and rewritten, running the same day twice changes nothing (no double counting), and a
correction of the past changes the past. The financial ledger itself stays immutable — it is the per-day *copy* that
is recomputed. A run is one transaction: it is written completely or not at all, and a failed run harms no order.

`analytics:rebuild [--from --to --group --market --dry-run]` does the same for a named range; without a range it is
the **backfill** of everything the domains still hold.

Groups: `orders`, `customers`, `restaurants`, `items`, `funnel`, `routes`, `payments`, `refunds`, `finance`,
`support`, `notifications`, `pickup`.

## Time

Everything is stored in UTC. A report has an explicit time zone: the **market's** (`markets.default_timezone` — for
India `Asia/Kolkata`, read from the market, never hard-coded), and for a restaurant's own report the **restaurant's**.
A period is whole local days, both ends included. Days are cut by PostgreSQL (`AT TIME ZONE`), so a market with
daylight saving time gets its 23- and 25-hour days right; an hourly chart of a 25-hour day has 25 buckets.

| Period | Chart buckets |
|---|---|
| 1–2 days | hour |
| up to 62 days | day |
| up to 182 days | week (from Monday) |
| longer (max 366 days) | month |

Every bucket of the period is returned — an explicit zero where nothing happened.

## Money

Amounts are integer minor units from the backend's own financial facts and are formatted only on a screen. A money
figure is **one amount per currency**; with several currencies (the all-markets view) the answer lists them side by
side and gives no single total, and a money chart is not drawn.

## Freshness

| Label | Meaning | Used for |
|---|---|---|
| `AGGREGATED` | Read model, rebuilt about every 5 minutes; the answer says when (`aggregated_at`) and whether it is stale. | Every period report except finance totals |
| `LEDGER` | Read from the financial ledger at the moment of the request. | Finance totals, restaurant earnings |
| `OPERATIONAL` | Read from the operational records at the moment of the request. | The "right now" counts (`/admin/analytics/live`) |

Nothing aggregated is called "live". Historical dashboards may be a few minutes behind; operational alerts are read
from the source.

## Cache

Answers are cached for 60 seconds under a key made of the report, the period, the caller's scope (markets or
locations, finance / support access) and a generation number that changes with every aggregation. Two callers with a
different scope cannot share a key. Finance totals, restaurant earnings and the live counts are never cached.

## Data quality

`analytics:check` (hourly) runs ten checks and stores HEALTHY / WARNING / ERROR for each: facts cover the orders,
fact totals equal the orders', facts have their dimensions, verified payments have their orders, events have a
market, no negative figure, funnel transitions, event ids unique, the per-day finance figures cover the ledger, the
aggregation is fresh. **The checks report; they repair nothing** — a wrong copy is rebuilt, a wrong source is its own
domain's matter.

## Indexes

The read models are indexed by (market or location, local day); `analytics_events` by event name + time, market +
time, session + time, location + event + time, journey key, received time. For the incremental run nine source
tables got an index on the column "changed since" is asked on (`updated_at` / `created_at`). Query plans of the heavy
queries are in the performance evidence file.
