# Analytics privacy

Module 39 — 2026-10-14 — local only; production = NO

Sources: `docs/analytics/{privacy,event-registry,architecture}.md` (Module 38, generated registry), `backend/config/analytics.php:31-37, 77, 115-135, 190-224`, `backend/app/Services/Analytics/{Pseudonymizer,EventIngestService,ServerEventRecorder}.php`, `backend/app/Services/Analytics/Aggregation/FactBuilder.php`, `backend/app/Services/Analytics/Reports/ReportExportService.php`, `backend/app/Console/Commands/AnalyticsPrune.php`, `backend/app/Services/Privacy/CustomerErasureService.php:106-109`, `backend/database/migrations/2026_10_18_100000_module38_analytics.php`, `customer-web/src/analytics/index.ts`, `mobile/lib/analytics/analytics_emitter.dart`, `backend/tests/Feature/Analytics/{AnalyticsEventsTest,AnalyticsDomainsTest,AnalyticsLifecycleTest}.php`.

> **Technical mapping, not legal advice. PENDING LEGAL REVIEW.** Whether behavioural analytics may run on an
> opt-out basis, and what notice and consent are required, is a legal decision that has not been made. The DPDP
> Rules 2025 call for separate, plain consent notices stating the specific purpose (PIB backgrounder, M39 `refs.md`
> §10.1) — an input for that review, not a conclusion.

## 1. What analytics is

Module 38 built analytics as a read / aggregation layer in the same PostgreSQL database: behavioural events sent by
the Customer Web and the Android app, a few events the backend records itself, and facts and daily aggregates read
from the order, payment, refund, review, support and notification domains. There is no warehouse, **no third-party
analytics SDK and no advertising tracker** (`config/analytics.php` `privacy.advertising_trackers = false`; no ad
identifier is collected).

## 2. The event registry (closed list)

`docs/analytics/event-registry.md` is generated from `config/analytics.php` by `php artisan analytics:catalog --write`
and checked by a test. An event not in the registry is refused, and so is a registered event carrying a property
that is not listed.

| Sent by the apps (10) | Recorded by the backend (4) |
|---|---|
| `journey_search_started`, `journey_results_viewed`, `restaurant_viewed`, `menu_viewed`, `item_viewed`, `item_added_to_cart`, `pickup_viewed`, `pickup_selected`, `checkout_started`, `payment_started` | `journey_search_succeeded`, `journey_search_no_result`, `restaurant_impression`, `pickup_slot_refused` |

- There is deliberately no event for a verified payment, a created or completed order, or a completed refund: those
  are read from their own domains; no client can report them.
- Properties are typed (integers in ranges, enums, bands, codes, ids); there is no free text, no search text and no
  amount.
- Batches hold at most 25 events; events older than 72 hours are refused (`config/analytics.php:31, 37`).
- The endpoint `POST /analytics/events` always answers 202 and can never block a customer
  (`AnalyticsEventsTest::test_analytics_being_down_or_switched_off_stops_no_journey_no_cart_and_no_order`).

## 3. No personal data in analytics tables

| Column kind | In analytics tables? |
|---|---|
| Name, phone, e-mail, postal address | no |
| IP address, user agent, device or advertising identifier | no |
| Coordinates, geometry, route, polyline | no — a schema test refuses such columns and any geometry / geography type (`AnalyticsDomainsTest.php:275-279`) |
| Signed-in customer | `customer_key` = keyed pseudonym (section 4) |
| Journey | `journey_key` = keyed pseudonym; plus distance / duration / detour **bands**, corridor ids, and the city near an end only within 40 km of the city centre (`config/analytics.php` `city_radius_km`) |
| Session | random id made by the app, replaced after 30 min idle or 12 h (`:77`); not derived from hardware; no cross-device stitching |

Tests: `AnalyticsEventsTest::test_a_guest_and_a_customer_send_events_and_nothing_personal_is_stored`,
`::test_only_registered_events_with_registered_properties_are_stored`,
`::test_the_backend_records_a_planned_journey_as_bands_and_corridors_and_never_as_a_route`.

## 4. Keyed pseudonyms

`Pseudonymizer.php`:

- `customer_key` = first 32 hex characters of SHA-256 over `<key>:customer:<internal customer id>`; `journey_key` the
  same over the journey's public id.
- The key is `ANALYTICS_PSEUDONYM_KEY`; when empty it is derived from `APP_KEY`
  (`sha256('foodonthego-analytics|' + APP_KEY)`). Without the key a pseudonym cannot be computed from an id.
- No API returns a pseudonym. Changing the key starts new pseudonyms (no link to the old ones).
- Anyone holding the key **and** database access can re-identify a customer (compute the pseudonym of each id). The
  key must live in the secret store; whoever can read `APP_KEY` can derive it when no separate key is set.

## 5. Opt-out and consent

| Layer | Behaviour | Code |
|---|---|---|
| Web | consent is **on by default**; `setAnalyticsConsent(false)` stores `fotg.analytics.consent = off` in localStorage, and nothing is queued or sent while off | `customer-web/src/analytics/index.ts:43, 76-85` |
| Android | consent is **on by default** (`_consent = true`); opting out stores `fotg.analytics.optout = '1'` in secure storage | `analytics_emitter.dart:78, 102, 151, 282` |
| Backend | a batch with `consent: false` is answered 202 and nothing is stored (`ingest_consent_withheld` metric); a batch **without** the flag is treated as consent | `EventIngestService.php:46-50`; `AnalyticsEventsTest.php:195` |
| Consent screen / settings toggle | **NOT BUILT** — no page or screen calls `setAnalyticsConsent` / the Android setter outside the analytics modules | grep of `customer-web/src`, `mobile/lib` |
| Consent policy and notice | **NOT DECIDED** — legal documents are DRAFT | production blocker (B-2 in the production checklist) |
| Switch everything off | `VITE_ANALYTICS_MODE=off` (web), `ANALYTICS_MODE` (Android), `analytics.events.enabled` (backend) | config |

So today the opt-out flag exists in code, but a person has no way to use it. Whether analytics may run before a
consent decision is PENDING LEGAL REVIEW.

## 6. Retention and forgetting

| Data | Retention | Set by |
|---|---|---|
| Raw events | 180 days, then deleted by `analytics:prune` (daily) | `ANALYTICS_RAW_EVENT_DAYS` |
| Session facts | 400 days | `ANALYTICS_SESSION_DAYS` |
| Order facts | with the order (never deleted) | — |
| Daily aggregates | not limited (counts only) | — |
| Export files | 24 h; rows 30 days | `ANALYTICS_EXPORT_EXPIRY_HOURS`; fixed |
| Runs, quality results | 30 days | fixed |

All periods are development defaults, PENDING BUSINESS APPROVAL.

**Forgetting one customer:** `php artisan analytics:prune --forget-customer=<public id>` sets `customer_key` to null in
`analytics_events`, `analytics_session_facts` and — since Module 39 — `analytics_order_facts`
(`AnalyticsPrune.php:30-45`). `privacy:erase-customer --execute` does the same inside the erasure
(`CustomerErasureService.php:106-109`). Test: `AnalyticsLifecycleTest::test_retention_and_forgetting_a_customer_touch_only_behavioural_traces`.

**Limit (found in Module 39 documentation, not yet fixed):** `FactBuilder` rebuilds `analytics_order_facts` from
`orders.customer_id` with the same keyed pseudonym and overwrites existing rows (`on conflict … do update`). The
customer row is kept (anonymised, same id) after an erasure, so an `analytics:rebuild` that covers that customer's
orders — or a later change to one of those orders — writes the `customer_key` back into the order facts. Events and
session facts stay anonymous (they are not rebuilt from a customer id). The incremental five-minute aggregation only
touches orders changed since the last run; erasure's own update of the orders does not change `updated_at`. Fix
options: skip DEACTIVATED customers in the fact builder, or keep an erased-customer list. Status: OPEN.

## 7. Who sees what

| Audience | Sees | Never sees |
|---|---|---|
| Administrator | aggregates of the markets their permission covers; finance and support figures only with those permissions ("restricted", never zero, otherwise) | a customer list, a traveller's route, another market |
| Restaurant member | aggregates of their own locations (404 for others) | another restaurant, a customer identity, any customer list |
| Customer | nothing — there is no customer-facing analytics | |

Exports (`admin.analytics.export`, `restaurant.analytics.export`): curated CSV reports built by a queued job on the
private disk, for the requester only, permission checked again at download, 24 h, audited
(`analytics.export_requested / _downloaded`), formula-injection safe. They carry order and case numbers — no name,
phone, e-mail, address, route, or text a customer wrote.

## 8. Small-group suppression

| Rule | Default | Set by |
|---|---|---|
| Fine-grained rows (a corridor, a city, a band, a restaurant's new / returning split) built on fewer than N journeys / customers are not shown | N = 5 | `ANALYTICS_MIN_COHORT` (`config/analytics.php:124`) |
| A rate is shown only when its denominator is at least N; otherwise "insufficient data" with numerator and denominator | N = 5 | `ANALYTICS_MIN_DENOMINATOR` (`:134`) |

Test: `AnalyticsDomainsTest::test_route_demand_is_aggregated_a_search_without_a_restaurant_is_a_gap_and_small_groups_are_hidden`.
Thresholds are PENDING BUSINESS APPROVAL. Suppression applies to the report answers; the underlying tables are
readable by database operators.

## 9. Open items

| # | Item | Status |
|---|---|---|
| AP-1 | Consent screen / settings toggle (web and Android) and the consent policy behind it | NOT BUILT — PENDING LEGAL REVIEW (production blocker with the legal documents) |
| AP-2 | Default before a choice (currently on) | PENDING LEGAL REVIEW |
| AP-3 | Order facts re-derive the pseudonym of an erased customer on rebuild (section 6) | OPEN |
| AP-4 | Separate `ANALYTICS_PSEUDONYM_KEY` in the secret store (not derived from `APP_KEY`) | DEPLOYMENT |
| AP-5 | Retention periods and thresholds | PENDING BUSINESS APPROVAL |
| AP-6 | Privacy notice text describing first-party analytics, pseudonyms and retention | PENDING LEGAL REVIEW |
