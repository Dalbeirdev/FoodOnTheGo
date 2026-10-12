# FOODONTHEGO — MODULE 35 COMPLETION REPORT
# REFUNDS, CANCELLATION FINANCIAL WORKFLOWS & PROVIDER RECOVERY

Local only. Production was not touched. NO REAL MONEY and no live mode: every refund of this module ran on the DEVELOPMENT payment provider. Base commit e49125e + the Module 35 commit. Every number below is read from the evidence files in docs/local-review/test-results/.

## 1. MODULE STATUS

COMPLETE (local) on the DEVELOPMENT payment provider — with these stated limits:
- RAZORPAY TEST REFUNDS = PENDING PROVIDER VERIFICATION (no Razorpay credentials on this machine; nothing about Razorpay's behaviour is claimed).
- CUSTOMER CANCELLATION = DISABLED / PENDING POLICY APPROVAL (built and tested, switched off).
- AUTO-REFUND POLICY = PENDING BUSINESS APPROVAL (every refund waits for a person).
- DISPUTES = FOUNDATION (recorded, read-only, block refunds while open).

Tracker rows M35: 172 (163 COMPLETE, 6 PARTIAL, 2 PENDING (owner), 1 PENDING PROVIDER VERIFICATION).
Raised again: the specification names Laravel 12 / PostgreSQL 16 — the project runs Laravel 13.35 / PostgreSQL 18 (CF-268). backend/CLAUDE.md asks for remote installers (Laravel Boost / PHP) — NOT run (CF-311).

## 2. REFUND DOMAIN

Model: `refunds` (replaces the Module 31 `order_refunds` foundation — its rows were carried over and the table dropped), with `refund_attempts` (every provider call), `refund_events` (every transition, append-only), `payment_refund_balances` (one row per payment) and `payment_disputes`.
Public ID: a UUID; customers see a support reference `RFD-XXXXXXXXXX`. Internal ids, receipts and provider references never reach a customer or a restaurant.
Types: FULL (everything that is left of the payment, derived by the server), PARTIAL (an amount in minor units).
Statuses: REQUIRED, REQUESTED, APPROVED, SUBMITTING, SUBMISSION_UNKNOWN, PROCESSING, REFUNDED, FAILED, CANCELLED, REVIEW_REQUIRED.
Currency: the payment's currency; a request naming another is refused (422 refund_currency_mismatch).
State Machine: one class is the only door to every status (allowed-transition table, version, an event row). REFUNDED is reachable only by adopting the payment provider's own record — no route, job, command or test sets it. REFUNDED and CANCELLED are final; FAILED releases its amount and may be retried as a new provider refund.
Retention: financial records are never deleted by the application; deleting a payment or an order that has refunds is refused by the database. The retention PERIOD is a legal decision (CF-486).

## 3. REFUND ELIGIBILITY

Verified Payment: required (409 refund_payment_not_verified otherwise).
Order: must be over — REJECTED, CANCELLED, COMPLETED or PICKED_UP (409 refund_order_active otherwise); or a verified payment that never became an order / is a duplicate (payment-only refund).
Reason: a code from a fixed list (RESTAURANT_REJECTED, ORDER_CANNOT_BE_FULFILLED, CUSTOMER_CANCELLATION, RESTAURANT_CANCELLATION, ADMIN_CANCELLATION, DUPLICATE_PAYMENT, PAYMENT_ORDER_FAILURE, ITEM_UNAVAILABLE, PICKUP_CAPACITY_FAILURE, ADMIN_FINANCIAL_CORRECTION, OTHER — a note is required for OTHER); customers read a separate, safe label.
Remaining Refundable: paid − refunded − held − charged back, from one locked row per payment.
Full Refund: YES — the server derives "everything that is left".
Partial Refund: YES — several per payment.
Zero-Total: an order with nothing to pay has no payment, so no refund record and no provider call.
Dispute Check: while a dispute is open on the payment nothing is asked, approved or sent (409 refund_dispute_conflict).

## 4. REFUND FINANCIALS

Original Paid: `payment_refund_balances.paid_minor` (the verified payment's amount; never changed by a refund).
Previously Refunded: `refunded_minor` — only what the provider confirmed.
In-Flight: `reserved_minor` — every refund that is REQUIRED, REQUESTED, APPROVED, SUBMITTING, SUBMISSION_UNKNOWN, PROCESSING or REVIEW_REQUIRED holds its amount.
Remaining: paid − refunded − reserved − charged back (lost disputes).
Requested: `refunds.requested_amount_minor`.
Approved: `refunds.approved_amount_minor` (the asked amount, set on approval).
Completed: moved from reserved to refunded in the same transaction as REFUNDED.
Money behaviour as measured in the web e2e (T = the order total): after the rejection paid / held / refunded = T / T / 0; after "send" still T / T / 0 (PROCESSING is not refunded); after the provider's webhook T / 0 / T; a failed refund frees its amount (T / 0 / 0); a partial refund of ₹100.00 on a completed order T / 0 / 10000 with the order PARTIALLY_REFUNDED. All amounts are integer minor units; the database refuses reserved + refunded > paid.

## 5. RESTAURANT REJECTION

Paid Order Rejected: the order becomes REJECTED (Module 31) and, in the same transaction, exactly one refund record is opened.
Refund Required: REQUIRED for the whole payment, reason RESTAURANT_REJECTED; reported twice it is still one refund.
Auto Approval: built as policy (`REFUND_AUTO_APPROVE_REASONS`), tested with a test configuration; NO reason is approved — AUTO-REFUND POLICY = PENDING BUSINESS APPROVAL (CF-476).
Manual Approval: an administrator with `admin.refunds.approve` approves or turns down (a reason is required to turn down), with the version that was read.
Provider Submission: an administrator with `admin.refunds.issue` sends it; one queued job, one request, the receipt as idempotency key.
Customer Status: "Refund due" → "Processing" → "Refunded" (or "Under review"), on web and Android, with a notice at each real step. Rejected is never shown as refunded.

## 6. CANCELLATION

CUSTOMER CANCELLATION = DISABLED / PENDING POLICY APPROVAL

Customer Cancellation: built (quote + cancel with a reason, never an amount) and switched off (`ORDER_CUSTOMER_CANCELLATION_ENABLED=false`): the quote answers CUSTOMER_CANCELLATION_DISABLED / PENDING_BUSINESS_APPROVAL, a cancel request is refused (409 order_cancellation_not_allowed), and both clients show no cancel button and say so. The enabled paths (full refund owed, no refund, a person decides, the restaurant moved first, rate limit) are covered by the backend tests and the clients' unit tests — not by a browser run (CF-477).
Restaurant Cancellation: the restaurant rejects a new order (Module 31). After acceptance an administrator cancels on its behalf with reason RESTAURANT_CANCELLATION; a restaurant-side "cannot fulfil" action is not built (CF-479).
Admin Cancellation: an administrator with `admin.orders.cancel` cancels with a written reason and a reason code; a paid order then owes one refund REQUIRED. A completed order cannot be cancelled.
Policy: configuration (enabled, allowed order states, financial effect FULL_REFUND / NO_REFUND / MANUAL_REVIEW, reasons, policy version) — the shape of a policy, not an approved one.
Financial Quote: `GET /customer/orders/{order}/cancellation` — possible or not, why not, the financial effect, the refundable amount only for a full refund.
Capacity Release: the pickup reservation is RELEASED on cancellation (asserted in RefundCancellationTest).
Pickup Credential Revocation: the credential is REVOKED and the customer's answer carries none (asserted in RefundCancellationTest).

## 7. PROVIDER

Provider: Razorpay (the initial India provider), behind the PaymentProvider contract; the DEVELOPMENT provider is what ran here.
Mode: TEST is the only mode the configuration accepts on a development machine — and it is NOT CONFIGURED here: PENDING PROVIDER VERIFICATION — no Razorpay credentials exist on this machine (CF-404, CF-480). The adapter follows the published API and is tested with a faked HTTP layer only; no request reached Razorpay and no Razorpay refund reference exists.
Official Docs Reviewed: YES — refunds (create, idempotency, statuses, lookups), refund webhooks, disputes.
Documentation Date: 2026-10-11.
Refund API: `POST /v1/payments/{id}/refund` (amount in subunits, speed normal, receipt, notes) — implemented; tested on a faked HTTP layer.
Partial Refund: the same call with a smaller amount — implemented; tested on a faked HTTP layer.
Idempotency: header `X-Refund-Idempotency` = our receipt `rf-{refund}-{generation}`.
Refund Lookup: by refund reference, and all refunds of a payment (to find ours by receipt).
Webhook: `refund.created`, `refund.processed`, `refund.failed`; `payment.dispute.*`.
RAZORPAY TEST REFUNDS = PENDING PROVIDER VERIFICATION. Nothing is fabricated: there is no Razorpay refund reference in the evidence.

## 8. REFUND SUBMISSION

Internal Idempotency: the Idempotency-Key header on the request routes, a unique request key per order and reason, and a version on every action (a double click makes one request; e2e C4: two API processes, one 201 and one 409).
Provider Idempotency: the receipt is the provider idempotency key; a repeated request carries the same key.
Locking: order row → the payment's balance row → the refund row; a cache lock per refund for the submission; two workers cannot submit the same refund.
Timeout: never a failure. Before the provider acted: the same request is sent again with the same key. After it acted: the refund is found by its receipt.
Unknown Submission: SUBMISSION_UNKNOWN keeps the amount held, is looked up at the provider, is re-sent only if the provider holds nothing (bounded), and reaches a person when it cannot be settled (e2e D1, D2: one request, generation 1, adopted by receipt).
Retry: a FAILED refund is retried by an administrator with a reason as a NEW provider refund (generation + 1, receipt …-2) — e2e D6. A completed refund cannot be retried (409).

## 9. REFUND WEBHOOK

Signature: the raw body is verified with the provider's signature before anything is read; a wrong signature is 401 and stores nothing.
Deduplication: by the provider's event id — an event is stored once.
Replay: the same event again changes nothing (e2e B8: still REFUNDED once, one completion event).
Out-of-Order: a late "created" after "processed" / "failed" is ignored; a webhook that arrives before the submission's own answer ends in the same state (e2e D3).
Queue: the Module 30 queued webhook job (queue "payments"); the HTTP answer is immediate.
Failed Jobs: a failing job is retried by the queue and the scheduled reconciliation reaches the same state from the provider's record; nothing depends on a single delivery.

## 10. RECONCILIATION

Automated: `refunds:reconcile`, scheduled every minute, takes the refunds whose next look is due (batches of 100).
Backoff: 1, 5, 15 minutes, 1, 4, 12 hours, then daily; when it is used up → REVIEW_REQUIRED.
Provider Lookup: by refund reference, else by our receipt among the payment's refunds.
Pending: stays PROCESSING with the amount held and is asked about again.
Failed: FAILED, the amount is free again, the customer reads "under review", an administrator may retry.
Unknown: looked up first; adopted when found; re-sent with the same key only when the provider holds nothing.
Review Required: a mismatch of amount or currency, a refund the provider never confirms, a refund seen at the provider that FoodOnTheGo did not make — a person decides; nothing is recorded as refunded on a guess.
Manual Reconciliation: `POST /admin/refunds/{refund}/reconcile` (admin.refunds.reconcile, a reason is required) — the result is the provider's word.

## 11. PAYMENT / ORDER STATE

Final state of the first order of the web e2e (step B6):
Order: REJECTED
Payment: VERIFIED
Refund: REFUNDED
The payment remains VERIFIED and its amount unchanged after every refund in the suite; the order keeps REJECTED / CANCELLED / COMPLETED. The order carries a derived `refund_status`: NONE / REQUIRED / PROCESSING / PARTIALLY_REFUNDED / REFUNDED / FAILED / REVIEW_REQUIRED.

## 12. PARTIAL REFUND

Supported: YES (e2e C2–C9; RefundAmountTest).
Multiple Partial Refunds: YES — each with its own record, attempts and events.
Remaining Balance: recalculated under the lock at every request, approval, failure, turn-down and completion; a "full" refund after a partial one is everything that is left.
Over-Refund Guard: one minor unit more than is left is refused (409 refund_amount_exceeds_available); the database CHECK is behind it.
Concurrency: two administrators on two API processes asking for the last of the balance at the same moment — one accepted, one refused (e2e C4).

## 13. ADMIN

Refund List: real backend (`GET /admin/refunds`), paged, inside the administrator's market scope, searchable by safe references.
Refund Detail: approval, failure, provider references, what the payment can still give back, every provider call, events, verified webhooks, the audit trail, and only the actions the backend allows this administrator.
Required Queue: queues required / awaiting approval / approved / processing / failed / review required / completed / cancelled, counted by the backend.
Approval: approve / turn down with a confirmation naming the order, the payment, the amount and the reason.
Execution: "Send to the provider" (admin.refunds.issue); a refund that was sent cannot be cancelled.
Retry: with a written reason; a new provider refund for a failed one, a lookup first for an unanswered one.
Reconcile: "Ask the provider again" with a reason.
Review Required: its own queue and an "only what needs a person" filter.
RBAC: admin.refunds.view / create / approve / issue / reconcile, admin.disputes.view. FINANCE_ADMIN holds all; OPERATIONS_ADMIN and SUPPORT_ADMIN read only (e2e C6); customers and restaurants are refused on every admin refund route (e2e A6).
Audit: every action with who, why and the versions; shown in the refund's audit trail.
Mark Refunded Button = NO (no route, no control; the page says a refund cannot be marked as refunded by hand — e2e B1, B9).

## 14. CUSTOMER WEB

Refund Required: "Refund due", refunded so far ₹0.00, still to be refunded = the total, the RFD- reference (e2e A3, A4).
Processing: "Processing" — on its way, not completed (e2e B4).
Partial: "Partially refunded" with each partial refund (e2e C8).
Refunded: "Refunded" — the open page follows the refund there without a reload (e2e B7).
Failed/Review: "Under review — you do not need to do anything, and you will not be charged again"; the words "failed" and "payment failed" do not appear (e2e D4).
Cancellation: no cancel button while the policy is off; the page says orders cannot be cancelled in the app yet (e2e E2). The cancel dialog on the backend's quote exists and is unit-tested.
Notifications: refund.pending, refund.initiated, refund.completed, refund.attention in the Module 33 inbox (e2e A7, B12, D5).
Web e2e: 53/53. Unit: vitest 759/759; tsc 0 errors; oxlint 0 errors, 130 warnings; build OK.

## 15. ANDROID

Refund Status: the same four states and words on Order Details and Tracking; the open screen follows an open refund by itself, also on a finished order, and stops asking once it is settled (preview e2e B1, C1–C4, E1, E2).
Notifications: the refund notices arrive in the in-app inbox through Module 33 (preview e2e C5). Real push delivery is still PENDING FIREBASE CONFIGURATION (Module 33, CF-450).
Cancellation: the same backend policy — no cancel button while it is off (preview e2e D1); the cancel dialog is widget-tested on scripted answers.
Deep Link: a refund notice opens the order (the Module 33 route PAYMENT_STATUS → /order/<number>, unchanged).
Recovery: a cold start reads the order again from the backend; nothing about a refund or a cancellation is kept or queued on the device.
Flutter: 581 tests, analyzer clean. Preview e2e: 17/17 (the Android code in the Flutter web preview — not a device). APK build039: built, 235.6 MB, SHA-256 0AC476322B7713AF7CAEAC7CC6386F7DB5FC69F16C7344CDFCA88178D3F6C8C6, API base URL http://10.56.37.229:8001/api/v1. USER DEVICE VERIFICATION = PENDING (CF-487).

## 16. RESTAURANT DASHBOARD

Rejected Paid Order: shows what happens to the money as information (e2e A5).
Refund Required: "Refund required — FoodOnTheGo is arranging it", the amount, refunded so far ₹0.00.
Processing: "Refund processing — with the payment provider" (unit test; the same field as the other states).
Refunded: "Refunded to the customer" with the amount (e2e B11).
Provider Controls = NO (the restaurant's order answer says controls NOT_PROVIDED; the dashboard has no refund method, button, id or provider reference — e2e A5, A6).

## 17. DISPUTES

Provider Support Reviewed: YES (Razorpay payment.dispute.* events and the dispute lookup, 2026-10-11).
Model: `payment_disputes` (provider reference, status, phase, reason, amount, amount taken back, respond-by date).
Status: OPEN, ACTION_REQUIRED, UNDER_REVIEW, WON, LOST, CLOSED, UNKNOWN.
Webhook: dispute events pass the same signature check and deduplication (e2e F1, F4 on the development provider).
Admin View: read-only list and the warning on the affected refund (e2e F2, F3).
Refund Conflict Guard: YES — 409 refund_dispute_conflict while a dispute is open; a lost dispute reduces what can be refunded.
Full Dispute Workflow: NOT BUILT (evidence, accept / contest, deadlines, the accounting of a lost dispute) — CF-481.
Actual scope: FOUNDATION.

## 18. SECURITY

Customer IDOR: another customer's order, refunds, quote and cancel action answer 404 (e2e E3; RefundSecurityTest).
Restaurant Financial Access: read-only state and amounts of its own orders; refused (401) on every admin refund route.
Admin RBAC: one permission per action, market scope, versions; view-only administrators cannot ask, approve, send, reconcile or use the development simulator (e2e C6).
Amount Tampering: zero, negative, fractional, too large, an amount on a FULL refund — refused (e2e C3); a cancel request naming an amount — refused (e2e E1).
Currency Tampering: another currency is refused (422).
Provider Reference Tampering: no request has room for a provider reference; a webhook naming another refund, amount or currency never completes a refund.
Mass Assignment: no request has room for a status, an approved amount or a customer id (RefundOpenApiContractTest; e2e B9).
Webhook Security: signature on the raw body, deduplication, out-of-order safety.
Secret Audit: PASS (m35-secret-scan.txt) — no secret, token, signature or credential in what would be committed, the bundle, the sources, the saved answers or the log; no refund screen asks for a card number, CVV, UPI PIN or bank password.

## 19. DATABASE

Refunds: `refunds` — type, requested / approved amounts, currency, status, version, reason, request key, requester, policy decision, approval, closure, failure, review reason, generation, provider references, counters, timestamps.
Refund Attempts: `refund_attempts` — every provider call (CREATE / LOOKUP / LIST) with generation, source, outcome and duration; no payload.
Provider References: unique (provider, refund reference), unique receipt, unique request key, unique dispute reference.
Constraints: CHECK on amounts, statuses, types and the balance (reserved + refunded <= paid); restrict-on-delete from refunds to payments and orders.
Indexes: for the queues (status, next look), the order, the payment, the references and the dates (asserted by RefundPerformanceTest).
Audit: `refund_events` (append-only) and `audit_events` for every administrator action.
Retention: nothing is pruned; the period is a legal decision (CF-486).
Migration: rollback and re-apply checked on the test database (backend-m35-migration-check.txt: DONE).

## 20. OBSERVABILITY

`GET /admin/system/refunds` (admin.system.view) as answered at the end of the web e2e, before its clean-up (m35-api-samples.json › system_refunds). The event counters cover the last 24 hours on this machine, so they include the earlier runs of the same suite that day:
Refunds by status at that moment: {"required": 0, "requested": 0, "approved": 1, "submitting": 0, "submission_unknown": 0, "processing": 0, "refunded": 3, "failed": 0, "cancelled": 2, "review_required": 0}
Required / Submitted / Completed / Failed / Pending / Review Required (events, 24 h): {"required": 7, "requested": 6, "approved": 9, "submitted": 8, "submission_unknown": 2, "processing": 6, "completed": 6, "failed": 2, "cancelled": 4, "review_required": 0, "reconciled": 10, "over_refund_blocked": 2, "external_refund_seen": 0, "dispute_recorded": 4}
Queues: {"required": 0, "awaiting_approval": 0, "approved": 1, "processing": 0, "failed": 0, "review_required": 0, "completed": 3}
Refund Amount (minor units, by currency and status): {"INR": {"refunded": 95400, "approved": 42700, "cancelled": 65400}}
Disputes: {"open": 0, "won": 1, "lost": 0, "closed": 0} · Policy: {"auto_approve_reasons": [], "four_eyes": false, "customer_cancellation": "DISABLED_PENDING_POLICY_APPROVAL"} · Settlement: NOT_IMPLEMENTED
Provider: {"name": "fake", "mode": "FAKE", "status": "READY"}; measured average latency of its refund calls {"provider_avg": 2} — the DEVELOPMENT provider, in-process.
Provider Latency: recorded per provider call in `refund_attempts` and in the Module 30 provider metrics under the capability "refund" — for the DEVELOPMENT provider only; Razorpay latency was not measured (PENDING PROVIDER VERIFICATION).
Reconciliation: runs and next look per refund; structured log lines without payloads.

## 21. PERFORMANCE

Measurements, not targets. Test environment, FAKE provider in-process (m35-performance.json); HTTP timings from the web e2e on the PHP built-in server without OPcache (m35-web-timings.json).
Refund Creation: 25 queries, 22.5 ms.
Eligibility: 4 queries, 2.5 ms.
Provider Call: execute with the fake provider 63 queries, 47.2 ms; from "send" to PROCESSING in the browser run 2196 ms (n=1) (queue + provider call). Razorpay: not measured.
Webhook: from the provider's webhook to REFUNDED 1525 ms (n=1).
Admin List: {"page_of_1": 3, "page_of_4": 3} queries, 4.5 ms; over HTTP 318 ms (n=1); detail 10 queries, over HTTP 361 ms (n=1).
N+1: none — the list uses the same number of queries for one row or four (RefundPerformanceTest).

## 22. TESTING

Eligibility: PASS — RefundAmountTest, RefundLifecycleTest.
Full Refund: PASS — RefundLifecycleTest, RefundProviderTest; e2e A–B.
Partial Refund: PASS — RefundAmountTest; e2e C.
Over-Refund: PASS — RefundAmountTest; e2e C1, C3.
Concurrency: PASS — RefundAmountTest, RefundProviderTest; e2e C4 (two API processes).
Provider: PASS on the DEVELOPMENT provider and on a faked HTTP layer for the Razorpay adapter — RAZORPAY TEST MODE = NOT RUN (PENDING PROVIDER VERIFICATION).
Webhooks: PASS — RefundWebhookTest; e2e B6, B8, D3.
Reconciliation: PASS — RefundWebhookTest, RefundProviderTest; e2e D2.
Cancellation: PASS — RefundCancellationTest (enabled paths with a test policy); e2e E (disabled, administrator cancellation).
Security: PASS — RefundSecurityTest; e2e A6, C6, E3; secret scan PASS.
Customer Web: PASS — e2e 53/53; vitest 759/759.
Android: PASS — preview e2e 17/17; flutter test 581. Device: PENDING (owner).
Restaurant: PASS — e2e A5, A6, B11; unit tests.
Admin: PASS — e2e B, C, D, F, G; unit tests.
Notifications: PASS — RefundNotificationAndDisputeTest; e2e A7, B12, D5; Android C5.
Regression: backend 1167/1167; browser 894/917 over 15 suites (auth 48/48, market 51/51, site 68/68, restaurants 97/101, menus 48/48, account 62/63, journeys 61/63, cart 76/76, pickup 69/69, checkout 75/77, payments 57/63, orders 47/53, realtime 47/48, notifications 40/41, reviews 48/48). The 23 misses are expectations of older suites that this module (7) or an earlier one (16) made obsolete — each is named with its cause in m35-regression-notes.txt; four order-creating suites ran as clean-up variants (the removed table), their steps untouched. Defects of Module 35 found by the regression: none. (The two product findings of this module's own new suite — BUG-256, BUG-257 — were fixed before the regression ran.)
Passed: backend 1167 of 1167 (50618 assertions; 56 new in tests/Feature/Refund); web unit 759/759; web e2e 53/53; Flutter 581; Android preview e2e 17/17; browser regression 894/917.
Failed: backend 0; web unit 0; web e2e 0; Android preview e2e 0; browser regression 23 recorded misses (see the notes).
Skipped: Razorpay TEST MODE (no credentials); a real device (owner); the enabled customer-cancellation paths in a browser (policy off); the earlier Android preview suites (not run again).

## 23. MOCK-TO-REAL STATUS

Real (local backend): Authentication, Market, Restaurant, Menu, Journey (development routing providers), Cart, Pickup, Checkout, Payment (development provider; Razorpay pending), Order, Realtime, Pickup Verification, Notifications (development push provider; real FCM / e-mail / SMS pending), Reviews, Refund Eligibility, Refund Requests, Refund Reconciliation, Refund Status, Cancellation Financial Foundation, Financial Recovery, Partial Refunds.
Real on the DEVELOPMENT provider only: Provider Refund Execution (Razorpay = PENDING PROVIDER VERIFICATION).
Foundation: Disputes / Chargebacks (recorded, read-only, guard); Customer Cancellation Policy (built, disabled, policy pending).
Still Mock: Admin · Settlements and the other finance screens of Module 18 that Module 36 will build; the payment-methods screens' saved-method tokens (Module 25 references only).
Future: Restaurant Settlements, Restaurant Payouts, Platform Commission Accounting, Payment/Refund Settlement Reconciliation, Finance Ledger, Tax Invoice / Credit Note, Advanced Financial Reports, Production Deployment.

## 24. BUGS

Critical: none
High: none
Medium: BUG-253, BUG-254, BUG-256, BUG-257
Low: BUG-255, BUG-258, BUG-259, BUG-260, BUG-261, BUG-262
Open: none
Fixed: BUG-253, BUG-254, BUG-255, BUG-256, BUG-257, BUG-258, BUG-259, BUG-260, BUG-261, BUG-262
Verified: every fixed bug was verified by the test or the run named in its row.

- BUG-253 (Medium, BACKEND): A verified refund.failed event for a refund that was already REFUNDED was ignored without a trace — FIXED + VERIFIED
- BUG-254 (Medium, BACKEND): The rejection notice promised "Your payment will be refunded" — FIXED + VERIFIED
- BUG-255 (Low, QA): AdminPaymentApiTest and OrderSecurityTest could fail by chance (about 1 run in 676) — FIXED + VERIFIED
- BUG-256 (Medium, BACKEND): The customer's order answers carried the internal refund states FAILED / REVIEW_REQUIRED — FIXED + VERIFIED
- BUG-257 (Medium, BACKEND): An approved refund that had not been sent was shown to the customer as "Processing — on its way" — FIXED + VERIFIED
- BUG-258 (Low, WEB): React warned about a missing key in the Restaurant Dashboard's order drawer (item options) — FIXED + VERIFIED
- BUG-259 (Low, WEB): The refund list on Order Details / Tracking had no styles (a default bullet, no card) — FIXED + VERIFIED
- BUG-260 (Low, QA): The first two runs of the Module 35 web suite missed for reasons of the suite — FIXED + VERIFIED
- BUG-261 (Low, QA): The first run of the Module 35 Android preview suite missed for reasons of the harness — FIXED + VERIFIED
- BUG-262 (Low, BACKEND): GET /admin/system/orders still said notification_delivery NOT_IMPLEMENTED — FIXED + VERIFIED

## 25. EVIDENCE

Database: backend-m35-migration-check.txt (rollback + re-apply), backend/database/migrations/2026_10_15_100000_module35_refunds.php.
APIs: m35-api-samples.json (the customer's refund block at "due" and at "refunded", the restaurant's refund block, the admin refund detail, the queues, the disabled cancellation quote, a dispute, the system view), backend-m35-routes.txt, backend/openapi/openapi.json (235 paths, 427 schemas).
Provider: backend-m35-phpunit.txt (RefundProviderTest, PaymentRefundAndAdapterTest). Razorpay Test refund reference: NONE — PENDING PROVIDER VERIFICATION.
Webhooks: RefundWebhookTest; m35-web-e2e.json steps B6, B8, D3, F1, F4.
Reconciliation: RefundWebhookTest, RefundProviderTest; m35-web-e2e.json step D2.
Security: RefundSecurityTest; m35-web-e2e.json steps A6, B9, C3, C6, E1, E3, G3, G5; m35-secret-scan.txt.
Web: m35-web-e2e.json (53/53), web-build-lint-m35.txt, screenshots/web-m35/ (19 screenshots), m35-web-timings.json.
Android: flutter-test-m35.txt, m35-android-preview-e2e.json (17/17), screenshots/android-preview/m35-*; APK build039 with manifest and SHA-256.
Restaurant: m35-web-e2e.json steps A5, A6, B11; screenshots web-m35/m35-03, m35-09.
Admin: m35-web-e2e.json steps B1–B10, C2–C9, D2, F2, F3, G1; screenshots web-m35/m35-04, 07, 08, 10, 12, 16, 17, 18.
Tests: backend-m35-phpunit.txt (1167/1167), m35-performance.json, m35-regression-runs.txt, m35-regression-notes.txt, m35-regression-*.json.
No provider secret is recorded anywhere.

## 26. PROJECT TRACKER

Updated: /docs/project-progress.html (generated from docs/tracker/data.json by docs/tracker/build.py). Modules 37, requirements 3487 (M35: 172), decisions 774, carry-forwards 491 (new: CF-476 … CF-491), bugs 262, tests 381 (new: TE-368 … TE-381). Nothing was removed; the comparison with the copy taken before the module reports no removed row.

## 27. PRODUCTION READINESS

REFUND DOMAIN = COMPLETE (local)
FULL REFUNDS = COMPLETE (local, on the development provider)
PARTIAL REFUNDS = COMPLETE (local, on the development provider)
RAZORPAY TEST REFUNDS = BLOCKED — PENDING PROVIDER VERIFICATION (no credentials; adapter implemented and tested on a faked HTTP layer only)
REFUND WEBHOOKS = COMPLETE (local, development provider's signed events; a webhook sent by Razorpay is not verified)
REFUND RECONCILIATION = COMPLETE (local)
CUSTOMER CANCELLATION = DISABLED PENDING POLICY
DISPUTE FOUNDATION = COMPLETE (foundation only: recorded, read-only, refund guard)
SETTLEMENTS = NOT COMPLETE
RESTAURANT PAYOUTS = NOT COMPLETE
PRODUCTION = NO

## 28. MODULE 36 READINESS

MODULE 36 MAY START = YES

Not blockers for Module 36, and the owner's to decide or provide: Razorpay TEST credentials to verify refunds against the provider (CF-480); the auto-refund policy (CF-476); the customer cancellation policy (CF-477); who may ask for and approve refunds (CF-478); the device check of build039 (CF-487); the browser review (CF-488); approval of Modules 24–35 in words (CF-490). Module 36 inherits the questions of who bears a refund and a lost dispute (CF-491).

The completion gate of the specification (section 266):

| Point | Answer | Where it is shown |
|---|---|---|
| Module 34 approved | by the gate (MODULE 35 MAY START = YES); the owner's approval in words is pending | CF-490 |
| Refund domain works | YES | sections 2–4 |
| Explicit Refund state machine works | YES | RefundLifecycleTest; e2e B5, B9 |
| Refund separate from Payment/Order | YES | section 11 |
| Refund eligibility works | YES | section 3 |
| Amount backend-authoritative | YES | e2e C3, C9 |
| Remaining refundable amount correct | YES | e2e B1, C2, C5, C9 |
| Over-refund impossible | YES | e2e C1, C3; database CHECK |
| Concurrency protected | YES | e2e C4; RefundAmountTest, RefundProviderTest |
| Full refund works | YES (development provider) | e2e B3, B6 |
| Partial refund architecture works | YES (development provider) | e2e C2–C9 |
| Zero-total refund blocked | YES | RefundLifecycleTest, RefundCancellationTest |
| RefundRequired integration works | YES | e2e A1 |
| Paid Restaurant rejection produces refund workflow | YES | e2e A–B |
| Duplicate RefundRequired safe | YES | RefundLifecycleTest |
| PaymentProvider refund contract works | YES | RefundProviderTest |
| Razorpay Test Mode refund works or provider-blocked status accurately documented | PROVIDER-BLOCKED, documented: PENDING PROVIDER VERIFICATION | section 7; CF-480 |
| Provider timeout safe | YES | RefundProviderTest; e2e D1 |
| Unknown submission safe | YES | e2e D1, D2 |
| Provider idempotency / internal idempotency work | YES | section 8 |
| Refund webhooks verified | YES | RefundWebhookTest; e2e B6 |
| Duplicate / out-of-order webhooks safe | YES | e2e B8, D3 |
| Reconciliation works | YES | e2e D2; RefundWebhookTest |
| Unresolved states reach review queue | YES | RefundProviderTest, RefundWebhookTest |
| Customer refund state works | YES | e2e A3, B4, B7, C8, D4 |
| Android refund state works | YES in the preview; the device check is the owner's | Android e2e; CF-487 |
| Restaurant refund status works | YES | e2e A5, B11 |
| Admin Refund Dashboard uses real backend | YES | e2e B1, B10, G1 |
| Payment remains VERIFIED after refund | YES | e2e B6 |
| Order state remains unchanged by refund completion | YES | e2e B6, C7, D6 |
| Partial / full refund status calculated correctly | YES | e2e B6, C7 |
| Customer cancellation remains clearly disabled / pending approval | YES | e2e E1, E2; Android D1; CF-477 |
| Admin financial RBAC works | YES | e2e C6 |
| Audit works | YES | e2e B10 |
| No generic Mark Refunded bypass exists | YES | e2e B1, B9; secret scan section 9 |
| Dispute foundation implemented only to actual supported scope | YES — foundation | section 17 |
| Refund / dispute duplicate-compensation guard exists | YES | e2e F1, F4 |
| Notification integration works | YES | e2e A7, B12, D5; Android C5 |
| No false "Refund completed" notifications occur | YES | RefundNotificationAndDisputeTest; e2e B12, D5 |
| Security tests pass | YES | RefundSecurityTest; secret scan PASS |
| Provider tests pass | YES on the development provider and the faked HTTP layer; Razorpay itself not run | section 22 |
| Concurrency tests pass | YES | e2e C4 |
| Regression tests pass | backend YES (1167/1167); browser 894/917 with every miss explained | m35-regression-notes.txt |
| No critical bugs remain | YES | section 24 |
| No high-severity Module 35 blockers remain | YES | section 24 |
| Bugs recorded | YES | section 24 |
| Evidence recorded | YES | section 25 |
| /docs/project-progress.html updated | YES | section 26 |

## 29. NEXT MODULE

NEXT:

MODULE 36 — PLATFORM COMMISSION, RESTAURANT SETTLEMENTS, PAYOUT LEDGER, PAYMENT/REFUND RECONCILIATION, RESTAURANT EARNINGS & FINANCE REPORTING BACKEND

STOP.

DO NOT automatically begin Module 36.
