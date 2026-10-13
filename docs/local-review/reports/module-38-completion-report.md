# FOODONTHEGO — MODULE 38 COMPLETION REPORT
# ADVANCED ANALYTICS & BUSINESS INTELLIGENCE

Local only. Production was not touched. NO REAL MONEY (DEVELOPMENT payment provider). Module 38 commit fa60e9f on top of 82e6d32 (Module 37 report); not pushed. Every number below is read from the evidence files in docs/local-review/test-results/. The figures the reports showed in these runs are those of test orders and test sessions — never business figures.

## 1. MODULE STATUS

COMPLETE (local) — with these stated limits:
- WAREHOUSE = NOT REQUIRED / NOT IMPLEMENTED. MATERIALIZED VIEWS = REVIEWED, NOT USED.
- HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38: no behavioural event was recorded before this module, and none is invented. Transaction history (orders, payments, refunds, the ledger, cases, reviews, notifications) is rebuilt from the domains.
- CONSENT SCREEN = NOT BUILT. The applications have an opt-out switch and the event door honours it; the screen and the policy belong to Module 39 (CF-528).
- EXPORTS = CSV ONLY: no XLSX, no PDF, no scheduled or e-mailed export, no notification when an export is ready (the page polls).
- PROVIDER FIGURES come from the DEVELOPMENT payment and push providers only; Razorpay and FCM stay PENDING PROVIDER VERIFICATION (no credentials). PUSH READ = NOT CLAIMED.
- THE SCHEDULER DOES NOT RUN ON THIS MACHINE: the five-minute aggregation, the hourly checks and the daily prune were run by hand and by the suites.
- EVERY DEFINITION, THRESHOLD, BAND AND RETENTION PERIOD = PENDING BUSINESS APPROVAL: defaults chosen while building (CF-529, CF-530).
- NOT LOAD-TESTED: query counts and plans were measured on 3 001 orders and 13 500 events in the test environment (CF-541).

Tracker rows M38: 251 (249 COMPLETE, 1 PENDING (owner), 1 PARTIAL).
Raised again: the specification names Laravel 12 / PostgreSQL 16 — the project runs Laravel 13.35 / PostgreSQL 18 (CF-268). backend/CLAUDE.md asks for remote installers (Laravel Boost / PHP) — NOT run (CF-311).

## 2. ANALYTICS ARCHITECTURE

Operational Sources: the domains themselves — orders, order items, payment attempts, refunds, the finance ledger and allocations, settlements, payouts, support cases, reviews, notification deliveries, customers, journeys (through two backend events). Analytics reads them and writes only its own tables; it has no route, job or statement that changes one of them (secret scan §12).
Behavioral Events: one table (analytics_events) behind one first-party door, POST /api/v1/analytics/events — ten events the Customer Web and the Android app may send, four the backend records itself. Always 202; recording runs in a savepoint of its own.
Read Models: 16 tables in the same PostgreSQL database — events, session facts, order facts, nine daily tables (market, customers, restaurant, items, funnel, routes, finance, support, breakdowns), dirty days, runs, quality results, exports.
Materialized Views: REVIEWED, NOT USED (a view is refreshed as a whole; the daily tables are rewritten one local day at a time inside one transaction, which is what late events need).
Aggregation Jobs: analytics:aggregate (every five minutes when the scheduler runs; one lock, one transaction; what changed since the last run with a 120-second overlap + the trailing two days + the days marked dirty), analytics:rebuild (a range, a group, a market, a dry run; without a range the whole history), analytics:check (ten integrity checks, hourly), analytics:prune (retention, expired exports, daily), analytics:catalog (the generated documents).
Redis: a 60-second cache of report answers only; the key holds the report, the period, the whole scope and the aggregation it was read from; authorization runs before the cache; counts of this moment, finance totals and earnings are never cached. Nothing is authorized from a cached figure.
Warehouse: NOT REQUIRED / NOT IMPLEMENTED.
Freshness: every answer says which of three it is — AGGREGATED (with the time of the last aggregation and a stale flag), OPERATIONAL (read now) or LEDGER (read from the finance module now). An aggregated view is never called "live" (VERIFIED (e2e D5, N1, N4)).

## 3. METRIC CATALOG

Metrics Defined: 120 (backend/app/Services/Analytics/MetricCatalog.php; docs/analytics/metric-catalog.md is generated from it and a test fails when it drifts). The metric builders refuse a key that is not in the catalog.
Financial Metrics: 14 — each with its source in the finance module (GMV among them, labelled "not revenue").
Customer Metrics: 10 (customers 7, the session funnel 3).
Order Metrics: 40 (orders 14, payments 10, refunds 9, pickup 7).
Restaurant Metrics: 16 (restaurants 6, menu 6, reviews 4).
Route Metrics: 9.
Support Metrics: 18 (support 9, notifications 9); and 13 figures of this moment (operational, not of a period).
Metric Definitions Complete: YES — a definition, a source, a time-zone rule, a currency rule and a freshness for every metric; a numerator and a denominator for every rate (VERIFIED (e2e D6, N3, R3)). The wording of the definitions is PENDING BUSINESS APPROVAL.

## 4. EVENT REGISTRY

Web: the ten CLIENT events from the real pages — journey_search_started, journey_results_viewed, restaurant_viewed, menu_viewed, item_viewed, item_added_to_cart, pickup_viewed, pickup_selected, checkout_started, payment_started — each once per session (VERIFIED (e2e A0, A1, A2); vitest 998/998).
Android: the same ten events with the same names, references and properties, platform ANDROID with the app version (flutter test 660; preview e2e 9/9 — a browser preview of the app, not a device; build042 device check PENDING).
Guest Session: a random identifier the application makes up — not a device id; replaced after 30 minutes without an event or 12 hours of age; no customer is attached.
Authenticated Customer: stored as a keyed hash of 32 hex characters — never the id, the phone number, the e-mail address or the name (VERIFIED (e2e A3)).
Deduplication: a client-made UUID per event under a unique index; a batch sent again answers 202 and adds only what is new (VERIFIED (e2e C1)).
Schema Version: 1, stored on every event.
Property Allowlist: per event — required references, allowed properties with a type and a rule; anything else is refused for that event with a reason (VERIFIED (e2e C3)).
PII: NONE in events — no phone number, e-mail address, name, address, journey origin or destination, coordinate, route, IP address or device identifier. The four SERVER events are journey_search_succeeded, journey_search_no_result, restaurant_impression and pickup_slot_refused. No event exists for a verified payment, a created or completed order or a completed refund (VERIFIED (e2e C2, C5)).

## 5. CUSTOMER FUNNEL

The unit is the SESSION. Stages 1–8 are the session's own events, in order; stages 9–11 are read from the payment and order domains through the checkout the session started.
Journey Search: stage 1 — journey_search_started.
Restaurant Results: stage 2 — journey_results_viewed.
Restaurant View: stage 3 — restaurant_viewed.
Menu View: stage 4 — menu_viewed.
Cart: stage 5 — item_added_to_cart.
Pickup: stage 6 — pickup_selected.
Checkout: stage 7 — checkout_started.
Payment: stage 8 — payment_started (an event), then stage 9 — a VERIFIED payment attempt of that checkout in the payment domain, within 24 hours.
Order: stage 10 — an order of the order domain created for that checkout.
Completed: stage 11 — that order COMPLETED.
Conversion: from the previous stage and overall, each with its numerator and denominator; under five in the denominator the answer is "insufficient data", not a percentage. Backend test: 100 sessions → 90 → 80 → 60 → 40 → 35 → 30 → 25 → 20 → 20 → 18, every conversion exact. Browser run: VERIFIED (e2e A5, D1, N6).
Abandonment: sessions that did not continue per stage, and "where sessions stopped" in four plain groups.

## 6. CUSTOMER ANALYTICS

Counts only — no list of customers exists anywhere.
New Accounts: accounts created in the period (customer domain).
First-Time Ordering: customers whose first COMPLETED order falls in the period.
Repeat: customers with at least two completed orders, the latest in the period (returning = had completed an order before the period).
Active: distinct customers with a completed order or a behavioural event in the period.
Ordering Customers: distinct customers with a completed order in the period.
Orders/Customer: completed orders over ordering customers, with both parts.
AOV: completed orders' totals over completed orders, per currency.
Retention: FOUNDATION ONLY — a cohort table by the month of the first completed order (six cohorts). No LTV, no prediction, no marketing attribution.

## 7. ORDER ANALYTICS

Read from order facts copied from the order domain (VERIFIED (e2e D2, N7)).
Created: orders placed in the period.
Completed: orders COMPLETED.
Rejected: rejected by the restaurant.
Cancelled: cancelled.
Completion Rate: completed over finished orders (completed + rejected + cancelled); an order still in progress is on neither side.
Acceptance Time: placed → accepted — average, median, 90th percentile.
Preparation Time: accepted → ready.
Ready-to-Pickup: ready → picked up.
Total Completion Time: placed → completed.

## 8. RESTAURANT ANALYTICS

Orders: placed, in progress, by status — per location, on the location's own local days.
Completed: completed orders.
Rejected: rejected orders.
Gross: gross sales from the finance module's allocations — shown to administrators with finance access.
Net Earnings: the finance module's own earnings summary, identical to the Earnings page (VERIFIED (e2e E3, R9)).
AOV: a restaurant reads "completed order total" and its average — not "GMV".
Acceptance Rate: accepted over decided orders.
Prep Time: accepted → ready; labelled operational information, not a score.
Refund Rate: refunded orders over completed orders.
Rating: the average of PUBLISHED reviews; hidden reviews are in no figure.
Review Count: published reviews in the period.
Location Scope: the caller's organization only; a manager only the assigned locations; any other location answers 404 on every report (VERIFIED (e2e E1, E2)).

## 9. MENU ANALYTICS

Two labelled sources side by side: behaviour (events) and orders (order items).
Views: menu_viewed events per restaurant.
Item Views: item_viewed events per item.
Add-to-Cart: item_added_to_cart events per item.
Units Sold: units of the item in COMPLETED orders.
Sales: the order items' line totals of completed orders, per currency.
Conversion: item views → adds, with both parts ("insufficient data" under five).
Top Items: items ordered by units sold; nothing is called "best" and nothing is published (VERIFIED (e2e R5, N11b)).

## 10. ROUTE ANALYTICS

Journey Searches: journey_search_started (client) and journey_search_succeeded (backend) per corridor (city pair), city and band.
No-Result Searches: journey_search_no_result, recorded by the backend when discovery returns no restaurant.
Corridor Demand: searches per corridor.
Restaurant Impressions: one per restaurant the backend returned for a journey, once per journey.
Restaurant Views: restaurant_viewed events that carry a journey.
Orders: orders whose cart was linked to a journey (order domain).
Conversion: journey → restaurant view → order, with both parts.
Average Detour: the detour of the returned restaurants, in bands and as an average.
Coverage Gaps: corridors with searches and no (or few) restaurants returned — a list for a person to read; nothing activates anything from it.
Privacy Threshold: a corridor or band with fewer than five sessions is not shown by itself (configuration, PENDING BUSINESS APPROVAL).
Precise Routes Stored: NO. No origin, destination, coordinate, geometry or journey id is in any analytics table: a keyed hash, bands and city names (VERIFIED (e2e A4, D4, Y1)). The journeys of this machine come from the DEVELOPMENT places and routing providers (CF-535).

## 11. PICKUP ANALYTICS

ASAP: orders with pickup mode ASAP.
Scheduled: orders with a scheduled slot.
Pickup Methods: orders per method.
Slot Full: pickup_slot_refused, recorded by the backend when the pickup engine refuses a slot, by reason.
Capacity: reserved over configured capacity — only where a capacity is configured; otherwise "no data" (CF-537).
Lead Time: order placed → requested pickup time.
Pickup Duration: ready → picked up (the same interval as "Ready-to-Pickup" in section 7).

## 12. PAYMENT ANALYTICS

Read from payment_attempts (VERIFIED (e2e N10)).
Attempts: attempts created in the period.
Verified: VERIFIED by the payment domain.
Failed: FAILED.
Cancelled: CANCELLED / EXPIRED.
Pending: not final yet — counted apart, in no success rate.
Unknown: UNKNOWN / REVIEW_REQUIRED — counted apart, never as success or failure.
Methods: the method the provider reported — the DEVELOPMENT provider only on this machine.
Failure Categories: the payment domain's own categories.
Provider Latency: created → final as the payment domain recorded it — DEVELOPMENT provider only; Razorpay = PENDING PROVIDER VERIFICATION (CF-536).

## 13. REFUND ANALYTICS

Count: completed refunds in the period (refund domain).
Amount: refunded amount per currency.
Full: full refunds.
Partial: partial refunds.
Processing Time: requested → completed — DEVELOPMENT provider only.
Reasons: the refund domain's reason codes; no free text.
Rate: refunded orders over completed orders; a refund of an older order changes the day it belongs to.

## 14. FINANCE ANALYTICS

Delegated to the finance module (Module 36): analytics calculates no financial figure of its own. Needs admin.finance.view; read from the ledger; not cached.
GMV Definition: the sum of completed orders' totals. NOT FoodOnTheGo revenue — said in the catalog and on the page.
Platform Commission: from the ledger. The only commission plans on this machine are TEST FIXTURES.
Restaurant Gross: from the financial allocations.
Restaurant Net: gross less commission and adjustments, from the ledger.
Provider Fees: only from the provider's own settlement records; none exist here — "no data", never an estimate.
Restaurant Payable: the payable lines of the finance module.
Restaurant Paid Out: PAID payouts of the finance module. LIVE PAYOUTS = DISABLED (fake provider).
Settlements: by status.
Reconciliation: open differences; and an integrity check that the per-day finance rows equal the ledger.
Reconciles to Module 36: YES — in the browser run the commission, platform revenue, restaurant earnings and gross marketplace sales of the report equalled, to the paisa, the finance module's own overview for the same day, and a restaurant's earnings equalled its Earnings page (e2e D3, E3); pinned by AnalyticsAdminApiTest and AnalyticsAggregationTest.

## 15. SUPPORT ANALYTICS

Needs admin.support.view. No figure per agent.
Created: cases opened in the period.
Open: open now (operational).
Resolved: resolved in the period.
Reopened: reopened over resolved.
Escalated: escalated cases by level.
Unassigned: open cases without an assignee (operational).
First Response: opened → first support reply.
Resolution Time: opened → resolved.
SLA At Risk: against the INTERNAL targets — which are TEST FIXTURES, never contractual; the page says so.
SLA Breached: the same.
Categories: cases per category over time.

## 16. REVIEW ANALYTICS

Average Rating: of PUBLISHED reviews.
Distribution: published reviews by star.
Review Count: published in the period.
Response Rate: published reviews with a restaurant response over published reviews.

## 17. NOTIFICATION ANALYTICS

From the notification domain's own deliveries and attempts — DEVELOPMENT push provider only.
Created: notifications created in the period.
Push Queued: push deliveries queued.
Push Sent: accepted by the provider.
Push Failed: failed deliveries.
Invalid Tokens: deliveries the provider refused for an invalid token.
In-App Read: in-app notifications the person opened — the only "read" that exists.
Push Read: NOT CLAIMED. A sent push is never shown as read.

## 18. ADMIN DASHBOARD

Platform Admin › Analytics on the backend, fourteen views; the Module 18 development figures stay in mock mode only. Browser run 78/78.
Overview: IMPLEMENTED — VERIFIED (e2e N1, N2, N3, N4, N5, N14)
Funnel: IMPLEMENTED — VERIFIED (e2e N6)
Customers: IMPLEMENTED — VERIFIED (e2e N6)
Restaurants: IMPLEMENTED — VERIFIED (e2e N8)
Routes: IMPLEMENTED — VERIFIED (e2e N9)
Orders: IMPLEMENTED — VERIFIED (e2e N7)
Payments: IMPLEMENTED — VERIFIED (e2e N10)
Refunds: IMPLEMENTED — VERIFIED (e2e N10)
Finance: IMPLEMENTED — VERIFIED (e2e N10, N16)
Support: IMPLEMENTED — VERIFIED (e2e N11)
Reviews: IMPLEMENTED — VERIFIED (e2e N11)
Notifications: IMPLEMENTED — VERIFIED (e2e N11)
Market Filter: India by default, any market the administrator may read, or all of them; sent to the server, which decides (VERIFIED (e2e N12, E5)).
Date Filter: today, yesterday, last 7 / 30 days, this / previous month, or a custom range of at most 366 days (VERIFIED (e2e N12)).
Also: Menu and Pickup views (VERIFIED (e2e N11b)), Reports (VERIFIED (e2e N13)), the admin Overview on the backend's figures and the System card (VERIFIED (e2e N14, N15)). Not designed by the owner — owner review pending (CF-539).

## 19. RESTAURANT DASHBOARD

Overview: IMPLEMENTED — VERIFIED (e2e R1, R2, R3, R4, R12)
Orders: IMPLEMENTED — VERIFIED (e2e R2)
Menu: IMPLEMENTED — VERIFIED (e2e R5)
Operations: IMPLEMENTED — VERIFIED (e2e R6)
Routes: IMPLEMENTED — VERIFIED (e2e R7)
Reviews: IMPLEMENTED — VERIFIED (e2e R8)
Earnings: IMPLEMENTED — VERIFIED (e2e R9)
Date Filter: the same presets and a custom range (VERIFIED (e2e R10)).
Location Filter: one location or all of the caller's; sent to the server and kept in the address (VERIFIED (e2e R10)).
RBAC: restaurant.analytics.view to read, restaurant.analytics.export (OWNER, MANAGER) to export, restaurant.finance.view for earnings; order staff see no figure (VERIFIED (e2e R13, E1)). Exports through the page: VERIFIED (e2e R11).

## 20. REPORT EXPORTS

Admin: eight curated reports — orders, payments, refunds, restaurant performance, customer funnel, route performance, support cases, financial summary (VERIFIED (e2e F1, F2, F5, N13)). No free query.
Restaurant: two — orders and menu items of the caller's own locations (VERIFIED (e2e F7, R11)).
Async: 202 QUEUED at once; a queued job builds the CSV (at most 50 000 rows; five waiting exports a person) — VERIFIED (e2e F1).
Private Storage: the private disk under analytics-exports/; no public link; no answer says where the file is (VERIFIED (e2e F3)).
Expiration: 24 hours; then 410 export_expired and the file is removed (VERIFIED (e2e F8)).
RBAC: its requester only (404 for anybody else, an administrator included); the permission is checked again at download; payments / refunds / support / finance reports need those permissions too (VERIFIED (e2e F4, F5)).
CSV Injection: a cell that starts with = + - @ is written as text (AnalyticsExportTest).
Audit: analytics.export_requested and analytics.export_downloaded on the audit trail (VERIFIED (e2e F6)).
Not built: XLSX, PDF, scheduled or e-mailed exports, a notification when an export is ready.

## 21. PRIVACY

Phone in Analytics Events: NO
Email in Analytics Events: NO
Full Journey Route: NO
Precise Origin/Destination: NO — a journey is a keyed hash, a distance band, a duration band and city names.
Customer Pseudonymization: YES — a keyed hash; the key is configuration (derived from the application key on this machine); forgetting a customer removes the hash from behavioural traces by command (not yet wired to the account deletion flow: CF-543).
Small Cohort Protection: YES — route groups under five sessions are not shown by themselves; rates under five are "insufficient data". Thresholds PENDING BUSINESS APPROVAL.
Advertising Trackers: NO — no third-party analytics or advertising SDK, pixel or script in the web or Android sources (secret scan §12).
Consent: an opt-out switch in both applications and a consent flag on the door; the consent SCREEN and POLICY are NOT BUILT (Module 39, CF-528).
Browser run: VERIFIED (e2e A2, A3, Y1). Secret and sensitive-data audit: PASS (m38-secret-scan.txt).

## 22. DATA QUALITY

Checks: 10 — order facts cover orders, order totals match, facts have dimensions, verified payments have orders, events have a market, no negative counts, funnel transitions, event ids unique, finance daily matches ledger, aggregation is fresh. They report; they repair nothing.
Warnings: 0 in the last browser run (all ten HEALTHY — e2e G3). Seen while the suite was being built: one session with holes (a customer whose earlier batches had been lost) was reported as a WARNING by "funnel transitions" — the check doing its job.
Errors: 0 (e2e G3).
Backfill: analytics:rebuild without a range builds the whole transaction history from the domains; running it again changes nothing (VERIFIED (e2e G1); AnalyticsLifecycleTest, AnalyticsAggregationTest).
Historical Transaction Metrics: AVAILABLE — rebuilt from orders, payments, refunds, the ledger, cases, reviews and notifications.
Historical Behavioral Metrics: HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38.
Missing Historical Event Handling: nothing is invented — a period before the module has no funnel, no item views and no route demand, and the API, the pages and the documents say so (VERIFIED (e2e D1, N15, G4)). A late event (up to 72 hours) lands on the day it happened (VERIFIED (e2e G2)).

## 23. PERFORMANCE

Measured by AnalyticsPerformanceTest in the test environment on this machine (m38-performance.json): 3001 orders, 13500 events, 3000 sessions over 60 days; a backfill in 2021.1 ms (8382 rows), an incremental run in 2216.4 ms. Over HTTP: medians of the browser run on the PHP built-in server without OPcache (m38-web-timings.json). NOT a production measurement; no load test was run (CF-541).
Admin Overview: 31 queries, 41.8 ms on the large data set (31 queries on the small one); over HTTP 478 ms (n=1).
Customer Funnel: 6 queries, 12.1 ms on the large data set (6 queries on the small one); over HTTP 368 ms (n=1).
Restaurant Analytics: admin table 8 queries, 13.2 ms on the large data set (8 queries on the small one); a restaurant's overview 21 queries, 57.2 ms on the large data set (21 queries on the small one); over HTTP 343 ms (n=1) / 368 ms (n=1).
Route Analytics: 19 queries, 19.2 ms on the large data set (19 queries on the small one); over HTTP 331 ms (n=1).
Finance Analytics: 31 queries, 32.8 ms on the large data set (31 queries on the small one); over HTTP 542 ms (n=1).
Support Analytics: 8 queries, 17.4 ms on the large data set (8 queries on the small one); over HTTP not measured.
Export: asked → READY 1327 ms (n=3) in the browser run (a queue worker on this machine; two rows). An aggregation run by command: 794 ms (n=5). Event ingestion: 312 ms (n=9).
EXPLAIN ANALYZE: 8 queries, plans kept in m38-performance.json; the "changed since" reads of the source tables use the nine indexes of this module (BUG-308).
N+1: none — 21 reports at 5–31 queries each (budget 60), the same number on the small and the large data set.

## 24. SECURITY

Admin Market Scope: decided by the server; an administrator scoped to a market gets 403 elsewhere and "all" means their markets (VERIFIED (e2e E5)).
Restaurant IDOR: another organization's location answers 404 on each of the seven reports (VERIFIED (e2e E1)).
Location IDOR: a location of the same organization that the manager is not assigned to answers 404 (VERIFIED (e2e E2)).
Finance Permission: without admin.finance.view the finance report is refused and finance figures come back "restricted", never as zero; the financial export needs admin.finance.export too (VERIFIED (e2e E4, F5, N16)).
Support Permission: support figures need admin.support.view (AnalyticsAdminApiTest, AnalyticsDomainsTest). No ACTIVE fixture administrator has analytics without support access, so this was not shown in the browser (CF-532).
Event Spoofing: a client cannot report a payment, an order or a refund; somebody else's checkout reference is dropped; events alone never move a session past "payment started" (VERIFIED (e2e C2, C4, C5)).
Export IDOR: an export is its requester's only (VERIFIED (e2e F4)).
Cache Isolation: the key holds the whole scope and authorization runs first; a cached answer of one restaurant is never returned to another (AnalyticsRestaurantApiTest).
SECURITY HARDENING of the platform as a whole is Module 39 and is NOT COMPLETE; what Module 38 leaves for it is in CF-528 and CF-543.

## 25. TESTING

Backend: 1325/1325 tests, 60931 assertions in the final full run (backend-m38-phpunit.txt); 44 of them in tests/Feature/Analytics. Web unit: vitest 998/998, tsc 0 errors, oxlint 0 errors, 162 warnings, vite build OK. Web e2e: 78/78. Flutter: 660 tests, analyzer clean. Android preview e2e: 9/9. Regression of the earlier suites: 1067/1090 over 18 suites.
Events: AnalyticsEventsTest (7); analytics.test.ts + callSites.test.tsx; analytics_emitter_test.dart (37) + analytics_flow_test.dart (3); e2e A0–A5, B1, C1–C5.
Funnel: AnalyticsFunnelTest (3); e2e A5, D1, N6.
Customers: AnalyticsFunnelTest (customer figures); AnalyticsAdminApiTest.
Orders: AnalyticsAdminApiTest (3); AnalyticsDomainsTest; e2e D2, N7.
Restaurants: AnalyticsRestaurantApiTest (3); e2e E1–E3, R1–R13.
Menu: AnalyticsAggregationTest; AnalyticsExportTest; e2e R5, N11b.
Routes: AnalyticsDomainsTest; AnalyticsEventsTest; e2e A4, D4, N9, R7.
Pickup: AnalyticsDomainsTest; e2e N11b, R6.
Payments: AnalyticsDomainsTest; e2e N10.
Refunds: AnalyticsAggregationTest; AnalyticsLifecycleTest; e2e N10.
Finance: AnalyticsAdminApiTest; AnalyticsAggregationTest; e2e D3, E3, N10, R9.
Support: AnalyticsDomainsTest; e2e N11.
Reviews: AnalyticsDomainsTest; e2e N11, R8.
Notifications: AnalyticsDomainsTest; e2e N11.
Currency: AnalyticsScopeTimeCurrencyTest (two currencies side by side, never added).
Timezone: AnalyticsScopeTimeCurrencyTest (midnight, a 25-hour and a 23-hour day).
Privacy: AnalyticsEventsTest; AnalyticsDomainsTest (small groups); e2e A2, A3, A4, Y1; the secret and sensitive-data audit.
RBAC: AnalyticsAdminApiTest; AnalyticsRestaurantApiTest; AnalyticsScopeTimeCurrencyTest; e2e E1–E5, N16, N17, R13.
Exports: AnalyticsExportTest (6); e2e F1–F8, N13, R11.
Aggregation: AnalyticsAggregationTest (2); AnalyticsLifecycleTest (5); e2e G1, G2.
Backfill: AnalyticsLifecycleTest (history rebuilt from the domains, nothing invented).
Performance: AnalyticsPerformanceTest (1); m38-performance.json.
Regression: auth 48/48, market 51/51, site 68/68, restaurants 97/101, menus 48/48, account 62/63, journeys 61/63, cart 76/76, pickup 69/69, checkout 75/77, payments 57/63, orders 47/53, realtime 47/48, notifications 40/41, reviews 48/48, refunds 53/53, finance 67/67, support 53/53. New misses with Module 38: none — every miss below was already a recorded miss after Module 37.
Passed: backend 1325, web unit 998, web e2e 78, Flutter 660, Android preview 9, regression 1067.
Failed: backend 0, web unit 0, web e2e 0, Android preview 0; regression misses 23 — each named with its cause in m38-regression-notes.txt.
Skipped: none in the Module 38 suites. NOT RUN: nothing; a real device (build042), a load test, real payment / push providers.

## 26. MOCK-TO-REAL STATUS

Real: the analytics backend (events, facts, daily read models, 35 routes, exports, checks) on the local PostgreSQL; Platform Admin › Analytics, the admin Overview and the System card; Restaurant Dashboard › Analytics and the dashboard home; the event emitters of the Customer Web and the Android app — all with the backend (order mode = api).
Still Mock: in mock mode (no backend) the Module 17 / 18 analytics pages show their development figures, labelled as such, and the emitters send nothing. Providers behind the figures: payment, push, places and routing are DEVELOPMENT providers.
Future: the consent screen and policy (Module 39); XLSX / PDF / scheduled exports; a restaurant filter on every admin view; currency conversion; a warehouse if scale ever requires one; figures on real providers.

## 27. BUGS

Critical: 0
High: 0
Medium: 3 — BUG-303, BUG-304, BUG-314
Low: 12 — BUG-305, BUG-306, BUG-307, BUG-308, BUG-309, BUG-310, BUG-311, BUG-312, BUG-313, BUG-315, BUG-316, BUG-317
Open: BUG-317 — observed once, not reproduced, cause not known (CF-545); it is order-creation code of Module 31, unchanged by this module
Fixed: 14 of 15 (BUG-303 … BUG-317).
Verified: each by the test or the run named beside it in the tracker.
- BUG-303 (Medium, BACKEND): An all-markets order trend added the money of two currencies.
- BUG-304 (Medium, BACKEND): The hourly chart of a 25-hour day lost an hour.
- BUG-305 (Low, BACKEND): A backfill and a rebuild wrote different row sets.
- BUG-306 (Low, BACKEND): A failing event insert could poison the caller's transaction.
- BUG-307 (Low, BACKEND): The admin overview ran six whole reports to show eight figures.
- BUG-308 (Low, BACKEND): The incremental aggregation walked whole source tables.
- BUG-309 (Low, BACKEND): A restaurant's order report spoke of "GMV".
- BUG-310 (Low, QA): Six older route inventories counted the new report routes as their domain's.
- BUG-311 (Low, WEB): A chart width factor tripped the gate against tax-like constants.
- BUG-312 (Low, DOCS): The generated catalog documents lost their blank lines and encoding.
- BUG-313 (Low, DOCS): openapi.json was regenerated with another indentation.
- BUG-314 (Medium, WEB): The event emitter never read the answers of its requests.
- BUG-315 (Low, QA): The Module 38 browser suite consumed a seeded cart.
- BUG-316 (Low, WEB): A call-site test read the events before the page had reported them.
- BUG-317 (Low, QA): In one run of the browser suite the order job stayed reserved and silent.

## 28. EVIDENCE

Database: backend-m38-migration-check.txt (rollback and re-apply on the test database), backend-m38-local-setup.txt, backend-m38-routes.txt (35 analytics routes of 419).
Events: m38-web-e2e.json A0–A5, B1, C1–C5; m38-android-preview-e2e.json; docs/analytics/event-registry.md.
Funnel: m38-web-e2e.json A5, D1, N6; m38-api-samples.json; screenshots web-m38/02.
Routes: m38-web-e2e.json A4, D4, N9, R7; screenshots web-m38/05.
Finance: m38-web-e2e.json D3, E3, N10, R9; screenshots web-m38/06.
Support: m38-web-e2e.json N11; AnalyticsDomainsTest.
Admin: screenshots web-m38/01 … 09 and 15.
Restaurant: screenshots web-m38/10 … 14.
Exports: m38-web-e2e.json F1–F8, N13, R11; screenshots web-m38/08, 13.
Privacy: m38-secret-scan.txt; m38-web-e2e.json A2, A3, Y1; docs/analytics/privacy.md.
Performance: m38-performance.json; m38-web-timings.json.
Tests: backend-m38-phpunit.txt; web-build-lint-m38.txt; flutter-test-m38.txt; m38-regression-runs.txt + m38-regression-notes.txt + m38-regression-<suite>.json.
APK: FoodOnTheGo-local-review-v0.1.0-build042.apk (235.7 MB), SHA-256 620F39DE7ABED2FC77D24F31C0459FF11844155FDB80CD3C1A88319E8DC74DE7 — git-ignored, with its manifest; USER DEVICE VERIFICATION = PENDING.
Contract and documents: backend/openapi/openapi.json (360 paths, 590 schemas); docs/analytics/ (eleven documents); docs/backend/analytics.md.

## 29. PROJECT TRACKER

Updated: /docs/project-progress.html (generated from docs/tracker/data.json): 251 Module 38 rows (249 COMPLETE, 1 PENDING (owner), 1 PARTIAL); 52 decisions (the 30 of the specification word for word, and what was decided while building); 17 carry-forward items (CF-528 … CF-545; the number CF-534 was not used); 15 bugs; the tests, artifacts, pages, the Android row, the security rows, the endpoint inventory and the readiness row. No earlier row was removed.

## 30. PRODUCTION READINESS

ANALYTICS BACKEND = COMPLETE (local; scheduler not running here; thresholds and retention pending approval)
CUSTOMER FUNNEL = COMPLETE (local; no behavioural history before Module 38; consent screen not built; device check pending)
RESTAURANT ANALYTICS = COMPLETE (local)
ROUTE ANALYTICS = COMPLETE (local; DEVELOPMENT places and routing providers)
FINANCE ANALYTICS = COMPLETE (local; the finance module's figures — TEST commission plans, live payouts disabled)
SUPPORT ANALYTICS = COMPLETE (local; targets are TEST FIXTURES)
SECURE EXPORTS = COMPLETE (local; CSV only)
SECURITY HARDENING = NOT COMPLETE
LOAD / DR TESTING = NOT COMPLETE
FINAL QA = NOT COMPLETE
PRODUCTION = NO

## 31. MODULE 39 READINESS

MODULE 39 MAY START = YES
Not blockers, but open: owner approval of Modules 24–38 (CF-544), the owner's review of the analytics pages (CF-539), the device check of build042 (CF-538), the definitions / thresholds / retention (CF-529, CF-530), consent (CF-528), the other inputs for Module 39 (CF-543) and one order job that stayed silent once (BUG-317, CF-545).

## 32. NEXT MODULE

NEXT:
MODULE 39 — SECURITY, PRIVACY & COMPLIANCE HARDENING, RBAC / IDOR AUDIT, OWASP-STYLE SECURITY TESTING, SECRETS & DATA PROTECTION, RETENTION / PRIVACY CONTROLS, ABUSE PREVENTION & PRODUCTION SECURITY GATE

STOP.
DO NOT automatically begin Module 39.
