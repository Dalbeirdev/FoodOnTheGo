# FOODONTHEGO — MODULE 34 COMPLETION REPORT

Customer reviews, verified order ratings, restaurant reputation and moderation backend. Built 2026-10-11 on this PC only.
Git HEAD before the Module 34 commit: 3bfcbfd. Every number below is read from the evidence files named in section 16.

Two standing notes, raised again: the spec header says Laravel 12 / PostgreSQL 16 and the project runs Laravel 13.35 / PostgreSQL 18 (CF-268); `backend/CLAUDE.md` asks for Laravel Boost / PHP remote installers and they were deliberately not run (CF-311).

## 1. MODULE STATUS

**COMPLETE (local)**. Everything is local. Nothing touched production.
Tracker rows M34-001 … M34-088: 80 COMPLETE, 6 PARTIAL, 2 PENDING (owner).
Not complete by design and visible in the tracker: the review policy windows are development defaults pending business approval (CF-462); advanced (AI) moderation is pending (CF-463); a confidence-adjusted ranking is not enabled (CF-466); restaurant-side review notifications are not built (CF-465); the dashboard list has no server-side paging or rating filter (CF-470); the owner's approval of Module 33 was not given in words (CF-473).

## 2. REVIEW ELIGIBILITY

- Completed Order: only a COMPLETED order can be reviewed; any other state answers ORDER_NOT_COMPLETED (409 on a write).
- Customer Ownership: the order must belong to the signed-in customer; anybody else gets 404 (existence is not disclosed). Customer, organization, location and market are copied from the order on the server; a body carrying them is refused 422.
- One Review per Order: UNIQUE(order_id) plus the order's row lock. The same content or the same submission token answers the same review; different content is 409. A withdrawn review still occupies the order.
- Review Window: 30 days after completion to review, 72 hours and 3 edits to change. These are configuration and DEVELOPMENT DEFAULTS, PENDING BUSINESS APPROVAL.
- Verification: "verified" cannot be sent. Every review is a verified order review because no other kind can be created.

## 3. REVIEW DOMAIN

- Tables: restaurant_reviews, review_revisions, review_moderation_cases, review_reports, restaurant_review_responses, restaurant_location_rating_summaries (one reversible migration; rollback and re-apply checked on the test database).
- Models: RestaurantReview, ReviewRevision, ReviewModerationCase, ReviewReport, RestaurantReviewResponse, RestaurantLocationRatingSummary; eight enums.
- Services: ReviewEligibilityService, ReviewService, ReviewContentScreen, ReviewText, RestaurantRatingService, ReviewReportService, RestaurantResponseService, ReviewModerationService, ReviewNotifier.
- APIs: 26 new routes (264 in total): 7 customer, 2 public, 5 restaurant, 12 admin. OpenAPI: 218 paths, 404 schemas.
- Statuses: PENDING_MODERATION, PUBLISHED, HIDDEN, REJECTED, REMOVED (final).

## 4. RATINGS

- Overall Rating: integer 1–5, required.
- Category Ratings: optional food quality, order accuracy, preparation time, pickup experience; averages in the summary.
- Average: sum / count of PUBLISHED reviews, one decimal, rounded half up with integers. No reviews means no average: the clients show "New" / "No reviews yet". No figure is invented anywhere.
- Star Distribution: count per star 5 … 1.
- Aggregation: a summary row per location, recalculated inside the transaction of every change (submit, edit, publish, hide, reject, restore, withdraw) under an advisory lock per location. `php artisan reviews:recalculate` rebuilds it.
- Caching: none. PostgreSQL is authoritative; Redis is not used for ratings.

## 5. MODERATION

- Workflow: a clean review is published at once. A rule-based check (link, contact details, spam phrase, repeated text) HOLDS a review for a person and never rejects one. A low rating is never a flag. This is pattern matching and is described as such; AI moderation is PENDING.
- Reporting: customers and restaurant staff report with a reason from a fixed list; one report per reporter per review; one open case per review. A report hides nothing.
- Admin Review: publish, hide, reject, restore, hide / restore the restaurant's response, assign / escalate / close a case. Hide, reject and restore need a written reason; every decision carries the version it was taken on.
- Audit: 15 review.* audit actions with actor, reason and change. Review text is kept in review_revisions, not in the audit trail.
- Restoration: a hidden or rejected review can be restored (admin.reviews.restore). A review withdrawn by the customer cannot.

## 6. RESTAURANT RESPONSE

- Permissions: restaurant.reviews.view / respond / report. Owner and manager do all three; menu manager views and reports; viewer views; order staff has none.
- Location Scope: only PUBLISHED reviews of locations in the member's ACTIVE membership; anything else is 404 / 403.
- Response: one per review, plain text up to 600 characters. The public response names the restaurant, never the staff member.
- Editing: needs the version that was read. Without it the backend answers 409 and overwrites nothing (BUG-247, found by the e2e and fixed).
- Audit: response_added / response_edited / response_hidden / response_restored; the previous text is kept.

## 7. CUSTOMER WEB

- Submission: the Module 16 review page on the backend (VITE_REVIEW_MODE=api). The backend's refusal is shown in its own words.
- View: My Reviews at /my-reviews with the state of each review and the restaurant's reply; My Orders and tracking follow the order's review block.
- Edit: with the loaded version; withdraw after a confirmation.
- Report: from the restaurant page, signed in, with a reason.
- Restaurant Detail: the Info tab shows the backend's average, count, distribution and the published reviews with sort, star filter and "show more". Cards, list, map card and favorites show the real rating or "New".

## 8. ANDROID

- Submission: the same screen on the backend (REVIEW_MODE=api). Nothing is queued offline: a failed submit is visible and the app sends nothing by itself when the network returns (preview e2e step B2).
- View: My Reviews; the restaurant's reviews on its Info tab; real ratings and "New" in the list.
- Edit: while the backend allows it; withdraw after a confirmation.
- Report: a sheet with the fixed reasons (widget tests).
- Build/Test: flutter analyze clean, 560 tests passed; preview e2e 14/14; APK build038 built, 235.6 MB, SHA-256 C585368834D7B66D8D62EF2D94856F1C6EB12F92894F816E0E82367AA20BD323. USER DEVICE VERIFICATION = PENDING.

## 9. RESTAURANT DASHBOARD

- Review List: the published reviews of the selected location from the backend (latest 50); no "demo data" banner.
- Location Filter: the dashboard's location switcher. A rating filter exists as the "3★ and below" chip over the loaded list; server-side paging and rating filter are not built (CF-470).
- Ratings: average, count, answered and distribution from the backend summary.
- Responses: respond and edit.
- Reports: report a review with a reason; the card then says "Reported".

## 10. ADMIN DASHBOARD

- Moderation Queue: All / Pending / Flagged / Published / Hidden / Rejected / Withdrawn with the backend's counts.
- Reports: every report with reason, reporter type, explanation and time; automated flags shown as what they are.
- Decisions: only the transitions the backend accepts are offered; reasons required; a stale decision is refused.
- Audit: the moderation history in the detail drawer is the audit trail.
- Market Scope: enforced on the server from the administrator's market grants. Proven by the backend tests with a second market; the browser run has India-scoped and global administrators only (CF-475).

## 11. NOTIFICATIONS

- Review Events: Module 33 is reused; no second engine. Types review.response, review.published, review.moderated.
- Restaurant Response: the customer gets one notice; a tap opens the review of that order (web and Android).
- Customer Notifications: in-app always; push for the reply by preference. The invitation to review is one line of the existing "order completed" notice. No reminder is ever sent.
- Preference Enforcement: the Module 25 matrix through the Module 33 policy. Restaurant-side notifications are not built (CF-465).

## 12. SECURITY

- Ownership: server-derived; unknown request fields refused.
- IDOR: another customer's order or review is 404; another restaurant's review is 404; admin outside the market scope is refused.
- XSS: markup is refused with 422, never rewritten; clients render review text as text. The secret scan finds no innerHTML in the review screens.
- Mass Assignment: StrictInput refuses customer_id, order_id, status, verified and any other unknown field.
- Rate Limits: read 120 / min, write 10 / min, report 5 / min; 20 reports per account per day.
- Concurrency: two submissions through two API processes give one review; two moderators deciding at once give one decision and one 409.
- Privacy: the public review has a display name ("First L."), the rating, the text and the restaurant's reply. No phone, e-mail, account id, order number, journey, payment detail or pickup code. Secret scan: PASS.

## 13. PERFORMANCE

- Query Results: public page 7 queries, summary 3, my reviews 7, dashboard 12, admin 12, restaurant list sorted by rating 15.
- Indexes: public list on (location, status, published_at) — the plan is an index scan; rating filter; customer list; admin queues; one open case per review.
- Pagination: every list is paged (at most 50 per page).
- Aggregation: one summary row per location.
- Cache Invalidation: nothing to invalidate; the summary is rewritten in the writing transaction.
- Measured on this PC (PHP built-in server, no OPcache): submit through the page 438 ms, public list 310 ms, admin queue 937 ms. Measurements, not targets.

## 14. TESTING

| Suite | Result |
|---|---|
| Backend (unit + feature, incl. 37 review tests) | 1112/1112 passed, 48010 assertions |
| Web unit (vitest) | 738/738; tsc 0 errors; oxlint 0 errors, 123 warnings; build OK |
| Web e2e, Module 34, real backend | 48/48 |
| Flutter unit + widget | 560 passed, analyzer clean |
| Android preview e2e, Module 34 | 14/14 |
| Regression, earlier browser suites, unchanged scripts | 853/869 over 14 suites |

- Unit / Integration / Database: the backend suite above (eligibility 7, rating 7, security 8, moderation 5, edit and response 7, contract 2, performance 1).
- Security / Concurrency: ReviewSecurityTest, ReviewModerationTest and e2e steps A3, B3, B4, C1, E7, F7, G1, G4.
- Web / Restaurant / Admin: 30 new unit tests and the e2e.
- Android: 24 new tests and the preview e2e.
- Regression: 16 misses in the earlier suites. New with Module 34: 4 expectations that this module made obsolete on purpose — journeys D2b and D3 (a rating and a rating sort / filter now exist along a journey), restaurants D19b (17 permission rows, was 16), notifications G3 (14 notification types, was 11). No product defect was found by the regression.

Passed: backend 1112, web unit 738, Flutter 560, Module 34 e2e 48 + 14, regression 853.
Failed: backend 0, web unit 0, Module 34 e2e 0 + 0, regression 16 (obsolete expectations, named).
Skipped: none.

## 15. BUGS

- Critical: none.
- High: BUG-243, BUG-247.
- Medium: BUG-244, BUG-246.
- Low: BUG-245, BUG-248, BUG-249, BUG-250, BUG-251, BUG-252.
- Fixed: 9 of 10.
- Open: BUG-251.

The one that mattered: BUG-247. A second restaurant response without a version overwrote the first. The browser run found it; the backend now refuses it (409) and a backend assertion covers it. BUG-251 stays open: it is a race in the Module 33 suite's reading, not a product defect.

## 16. EVIDENCE

- Screenshots: docs/local-review/screenshots/web-m34/ (13) and docs/local-review/screenshots/android-preview/m34-*.
- Test Results: docs/local-review/test-results/ — backend-m34-phpunit.txt, backend-m34-routes.txt, backend-m34-migration-check.txt, m34-performance.json, web-build-lint-m34.txt, m34-web-e2e.json, m34-web-timings.json, flutter-test-m34.txt, m34-android-preview-e2e.json, m34-regression-runs.txt, m34-regression-notes.txt, m34-regression-*.json, m34-secret-scan.txt.
- API Examples: m34-api-samples.json (the customer's review, the public review and summary, the restaurant's and the admin's view, the reply notice).
- Audit: e2e step F6 reads the audit rows of a hide and a restore.
- Database: e2e steps B2, D1, F4, G5, H1 read the review, revision, case and summary rows.
- Android APK: docs/local-review/apk/FoodOnTheGo-local-review-v0.1.0-build038.apk (235.6 MB), SHA-256 C585368834D7B66D8D62EF2D94856F1C6EB12F92894F816E0E82367AA20BD323, API http://10.56.37.229:8001/api/v1. Not committed to git.

## 17. PROJECT TRACKER

Updated: /docs/project-progress.html (generated from docs/tracker/data.json). Module M34 row; requirements M34-001 … M34-088; decisions; carry-forwards CF-462 … CF-475; bugs BUG-243 … BUG-252; tests TE-354 … TE-367; artifacts; APK build038; areas, database, security, pages, Android, the endpoint inventory. Counters now: {'modules': 36, 'requirements': 3315, 'pages': 83, 'android': 42, 'decisions': 717, 'carry': 475, 'bugs': 252, 'tests': 367, 'artifacts': 194, 'apks': 38, 'backendAreas': 33, 'databaseItems': 34, 'securityItems': 102, 'globalReadiness': 37, 'qa': 33}. Nothing removed.

## 18. PRODUCTION STATUS

PRODUCTION = NO

Local only. No real customer money. No provider account was created. Real FCM, e-mail and SMS providers are still pending (Module 33). iOS remains deferred.

## 19. MODULE 35 GATE

MODULE 35 MAY START = YES

The 28 points of the spec's completion gate:

| # | Point | Answer | Where it is shown |
|---|---|---|---|
| 1 | Module 33 authorized Module 34 | YES by the gate (MODULE 34 MAY START = YES); the owner's approval in words is pending | Module 33 report; CF-473 |
| 2 | Review domain implemented | YES | section 3 |
| 3 | Only completed Orders eligible | YES | ReviewEligibilityTest; e2e A1 |
| 4 | Customer ownership enforced | YES | ReviewSecurityTest; e2e A3, B3 |
| 5 | One Review per Order enforced | YES | e2e B3, G1 |
| 6 | Review creation works | YES | e2e B1, B2; Android B3 |
| 7 | Review editing policy works | YES, on development defaults | e2e D1, D2; CF-462 |
| 8 | Review history/audit works | YES | e2e D1 (revision), F6 (audit) |
| 9 | Moderation workflow works | YES | e2e F3–F6, G2, G4 |
| 10 | Restaurant response works | YES | e2e E2–E6 |
| 11 | Admin moderation works | YES | e2e F3–F7 |
| 12 | Public reviews filter correctly | YES | ReviewRatingTest; e2e C1, C2; unit tests |
| 13 | Rating summary accurate | YES | e2e B2, D1, G5, G6 |
| 14 | Hidden reviews excluded | YES | e2e F4 |
| 15 | Restored reviews included | YES | e2e F6 |
| 16 | Restaurant location ratings isolated | YES | ReviewRatingTest (two locations); e2e C3, C5 |
| 17 | Customer Web integrated | YES | e2e A–I |
| 18 | Android integrated | YES in the preview; the device check is the owner's | Android e2e; CF-471 |
| 19 | Restaurant Dashboard integrated | YES | e2e E1–E7, F2 |
| 20 | Admin Dashboard integrated | YES | e2e F3–F7 |
| 21 | Notification integration works | YES for customers; restaurant-side not built | e2e E4, E5, F5; CF-465 |
| 22 | No customer PII exposed | YES | e2e C1, E1, I2, I4; secret scan |
| 23 | IDOR tests pass | YES | ReviewSecurityTest; e2e A3, D2, E7, F7 |
| 24 | Concurrency tests pass | YES | e2e G1, G4; backend tests |
| 25 | Regression tests pass | YES for the unit suites; the browser regression has 16 obsolete expectations, none a defect | section 14; m34-regression-notes.txt |
| 26 | Critical bugs resolved | YES — none critical; both high ones fixed | section 15 |
| 27 | Evidence recorded | YES | section 16 |
| 28 | Project tracker updated | YES | section 17 |

Open items that are the owner's and do not block: approval of Modules 24–34 in words (CF-473), the review policy windows (CF-462), the ranking decision (CF-466), the browser review (CF-472), the device check of build038 (CF-471).

## 20. NEXT MODULE

MODULE 35 — REFUNDS, PAYMENT DISPUTES, CANCELLATION FINANCIAL WORKFLOWS, PROVIDER REFUND EXECUTION, REFUND STATE RECONCILIATION & FINANCIAL AUDIT BACKEND

STOP.

DO NOT automatically begin Module 35.
