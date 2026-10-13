# FoodOnTheGo analytics (Module 38)

Analytics is a **read / aggregation layer**. It never decides what is true about an order, a payment, a refund or
money: every figure is read from the domain that owns it. The only thing analytics stores of its own is behavioural
telemetry — what a session looked at on the way to an order — and that telemetry can never move a payment, an order
or an amount.

LOCAL DEVELOPMENT ONLY. Production: NO. Every definition, band, threshold, retention period and owner in these
documents is a development default, **PENDING BUSINESS APPROVAL**.

| Document | What it answers |
|---|---|
| [architecture.md](architecture.md) | How the layers fit: sources → read models → services → APIs → screens and exports. Storage, aggregation, freshness, cache. |
| [metric-catalog.md](metric-catalog.md) | Every metric: definition, source, numerator / denominator, filters, time zone, currency, freshness, limitations. *Generated from the code.* |
| [event-registry.md](event-registry.md) | Every behavioural event: trigger, source, properties, privacy class, deduplication. *Generated from the code.* |
| [funnel-definitions.md](funnel-definitions.md) | The customer funnel: the eleven stages, what counts for each, the conversion and abandonment formulas, the windows. |
| [financial-metrics.md](financial-metrics.md) | GMV, platform revenue, the four restaurant money figures — where each comes from and why GMV is not revenue. |
| [restaurant-metrics.md](restaurant-metrics.md) | What a restaurant sees, what an administrator sees about restaurants, and what neither is given. |
| [route-analytics.md](route-analytics.md) | Journey, corridor and coverage figures — aggregated, with a minimum cohort and no stored route. |
| [support-analytics.md](support-analytics.md) | Support, review and notification figures and their limits. |
| [privacy.md](privacy.md) | What analytics may hold, what it never holds, pseudonyms, retention, deletion. |
| [report-exports.md](report-exports.md) | The curated CSV reports: who may ask, how a file is built, kept, downloaded and expired. |

Backend run book (commands, schedule, configuration): [../backend/analytics.md](../backend/analytics.md).

## What is not built — and is said so

- No data warehouse (Snowflake, BigQuery, ClickHouse, Databricks, Kafka): **not required at this scale, not implemented.**
  The design keeps stable event names, stable metric keys, UTC timestamps, public ids and market / location
  dimensions, so an export to a warehouse later is a copy, not a redesign.
- No materialized views: reviewed and **not used** (see architecture.md).
- No third-party advertising or analytics tracker of any kind.
- No customer lifetime value, no marketing attribution, no push "open rate", no score per support agent, no public
  "top restaurant" ranking, no automatic suspension or market activation from a figure.
- No behavioural history before Module 38: **HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38** — it was never recorded
  and is not invented. Transaction history (orders, payments, refunds, the ledger, cases, reviews) is rebuilt from the
  domains.
- No consent screen: the emitters have an opt-out switch and the endpoint honours `consent: false`; the screen and
  the policy belong to the privacy and compliance module (Module 39).
- No customer-facing analytics, no XLSX or PDF export, no free report builder.
