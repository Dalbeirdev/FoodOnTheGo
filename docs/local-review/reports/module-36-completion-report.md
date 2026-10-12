# FOODONTHEGO — MODULE 36 COMPLETION REPORT
# COMMISSION, SETTLEMENTS, PAYOUTS & FINANCIAL RECONCILIATION

Local only. Production was not touched. NO REAL MONEY: every payment of this module ran on the DEVELOPMENT payment provider, every payout on the FAKE payout provider and every provider settlement on the FAKE settlement source. Module 36 commit 4302c41 on top of ee4725d (Module 35 report); not pushed. Every number below is read from the evidence files in docs/local-review/test-results/.

## 1. MODULE STATUS

COMPLETE (local) on the DEVELOPMENT / FAKE providers — with these stated limits:
- LIVE RESTAURANT PAYOUTS = DISABLED / NOT APPROVED. There is no real payout provider and no restaurant KYC / bank onboarding; the payout domain is proven on the FAKE provider only.
- RAZORPAY SETTLEMENT RECONCILIATION = PENDING PROVIDER VERIFICATION (no Razorpay credentials on this machine; nothing about Razorpay's behaviour is claimed).
- COMMISSION TERMS, EARNING / SETTLEMENT SCHEDULE, PROMOTION SPLIT, REFUND AND DISPUTE LIABILITY, PROVIDER FEE ALLOCATION = PENDING BUSINESS APPROVAL. Each is configuration with the most careful default; the only commission plans are TEST FIXTURES.
- TAX / ACCOUNTING POLICY = PENDING. No GST, TDS / TCS or withholding rule exists in code; no tax invoice, commission invoice or credit note is produced.
- Android: no finance screen (restaurant earnings and platform finance are web applications); build 40 is for the record.

Tracker rows M36: 204 (186 COMPLETE, 13 PARTIAL, 2 PENDING (owner), 2 PENDING (tax / accounting), 1 PENDING (business)).
Raised again: the specification names Laravel 12 / PostgreSQL 16 — the project runs Laravel 13.35 / PostgreSQL 18 (CF-268). backend/CLAUDE.md asks for remote installers (Laravel Boost / PHP) — NOT run (CF-311).

## 2. FINANCIAL LEDGER

Architecture: an accounting subledger in PostgreSQL — ledger_accounts, ledger_transactions, ledger_entries — with ONE writer (LedgerPostingService). Financial events reach it through a listener, a queued job on the "finance" queue and a sweep (php artisan finance:post-pending, also the backfill).
Double Entry: YES — every transaction has at least two entries; debits equal credits.
Accounts: 16 typed accounts — PAYMENT_CLEARING, PROVIDER_CLEARING, RESTAURANT_PAYABLE (one per location and currency), PLATFORM_COMMISSION_REVENUE, PLATFORM_FEE_REVENUE, PROMOTION_EXPENSE, TAX_LIABILITY, REFUND_CLEARING, PLATFORM_REFUND_EXPENSE, PROVIDER_FEE_EXPENSE, PROVIDER_TAX_EXPENSE, DISPUTE_CLEARING, PLATFORM_DISPUTE_EXPENSE, PAYOUT_CLEARING, PLATFORM_BANK, PLATFORM_ADJUSTMENTS.
Currencies: one currency per account and per transaction, enforced by composite foreign keys; never added together, no conversion. The active market posts INR from its configuration.
Balanced: YES — refused by the service before writing AND by the database (a deferred constraint trigger refuses a commit that does not balance). LedgerTest; e2e I1.
Immutable: YES — database triggers refuse UPDATE and DELETE on transactions and entries. LedgerTest.
Posting Key: UNIQUE per transaction — payment:{id}:verified, order:{id}:recognized, refund:{id}:completed, refund:{id}:liability, dispute:{id}:lost / :liability, payout:{id}:paid / :reversed, adjustment:{id}, provider-item:{provider}:{type}:{ref}:fee, provider-settlement:{provider}:{ref}:received. The same event delivered again answers the transaction that exists (LedgerTest; e2e A5).
Adjustments: a correction is a NEW transaction — a reversal (the exact opposite, once) or an adjustment; nothing posted is edited.
Integrity Check: php artisan finance:verify-ledger and POST /admin/finance/checks (LEDGER): unbalanced transactions, currency mismatches, duplicate or orphan postings, payable lines against the ledger. It reports and repairs nothing. e2e I1: OK.

## 3. CUSTOMER PAYMENT ACCOUNTING

Verified Payment: one posting when the payment is VERIFIED — Dr PROVIDER_CLEARING / Cr PAYMENT_CLEARING. It is customer money held; nobody's revenue and nobody's earning (OrderRecognitionTest; e2e A1).
Provider Clearing: what the payment provider holds for the platform: up on a verified payment, down on a confirmed refund and on a provider settlement received.
Order Allocation: ONE allocation per order, frozen when the order is created (merchandise, discounts by funding source, fees, tax, the commission terms and amount, the restaurant's net). Recognized in one posting when the order is COMPLETED: Dr PAYMENT_CLEARING (+ Dr PROMOTION_EXPENSE) / Cr RESTAURANT_PAYABLE + PLATFORM_COMMISSION_REVENUE (+ PLATFORM_FEE_REVENUE, TAX_LIABILITY). A rejected or cancelled order's allocation is VOID. Recognition at COMPLETED is a policy marked PENDING BUSINESS APPROVAL (CF-493).
Currency: the payment's; the order, its allocation and its postings carry the same one (internal reconciliation checks it).
Duplicate Protection: the posting key and UNIQUE(order) on the allocation; the sweep and the backfill find nothing to post again (e2e A5; LedgerTest; AdminFinanceApiTest).

## 4. COMMISSION

Model: commission_plans — dated commercial terms; a plan is never edited: it is ended, withdrawn or followed by a new one.
Scope: MARKET, ORGANIZATION, LOCATION; the most specific plan in force at the order's moment wins.
Market Default: supported as a MARKET plan. There is NO default in code: without an applicable plan an order is not treated as 0 % — its allocation waits for a decision (REVIEW_REQUIRED).
Restaurant Override: supported (ORGANIZATION plan).
Location Override: supported (LOCATION plan).
Type: PERCENTAGE (the only type built).
Rate Precision: integer basis points (0.01 %); amounts in integer minor units, rounded half up once; no floating point.
Commission Base: the plan's explicit policy — MERCHANDISE_GROSS, MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT or MERCHANDISE_NET_OF_ALL_DISCOUNTS; taxes and fees are never in the base. Which one the business uses = PENDING BUSINESS APPROVAL (CF-492).
Effective Dates: a plan may only start in the future; a following plan ends the earlier one at its start (CommissionTest; e2e J1).
Historical Snapshot: the plan, rate, base policy, base amount and commission are frozen on the order's allocation; a later plan never changes an existing order (CommissionTest).
Audit: creating, ending and withdrawing a plan are audited with who and why; permissions admin.finance.commission.view / .manage.

Configured DEVELOPMENT rates on this machine (TEST FIXTURES, approval = TEST, marked TEST on every screen and answer): India market 1000 basis points (10.00 %) on merchandise net of restaurant-funded discounts; one location (Brew & Bites) 800 basis points (8.00 %) on gross merchandise.
PRODUCTION-APPROVED rate: NONE. COMMISSION TERMS = PENDING BUSINESS APPROVAL (CF-492). Nothing in code or configuration holds a rate; the fixture seeder refuses to run outside local / testing.

## 5. PROMOTIONS

Platform-Funded: the discount is the platform's PROMOTION_EXPENSE; the restaurant's earning is not lowered (PromotionAndRefundTest).
Restaurant-Funded: the discount lowers the restaurant's earning; whether it lowers the commission base follows the plan's base policy (PromotionAndRefundTest).
Shared: split by FINANCE_SHARED_PROMOTION_RESTAURANT_BPS when the business defines it; it is UNSET — an order with a SHARED promotion waits for a finance decision instead of being split by a guess (PENDING BUSINESS APPROVAL, CF-495).
Earnings Effect: restaurant net = gross − restaurant-funded discount − commission.
Historical Snapshot: the funding source and both discount parts are frozen on the allocation.

## 6. PROVIDER FEES

Provider Fee Source: the provider's own settlement records only (the fee and the tax on it, per settled line).
Actual / Estimated: ACTUAL only. No fee is estimated on an order, in a report or anywhere else; the provider-cost report is empty until a provider settlement is imported. No fee formula exists.
Platform-Borne: YES — the only policy built: Dr PROVIDER_FEE_EXPENSE + Dr PROVIDER_TAX_EXPENSE / Cr PROVIDER_CLEARING; a restaurant's earning is never reduced by it.
Restaurant-Borne: NOT IMPLEMENTED.
Shared: NOT IMPLEMENTED.
Provider Tax: the tax on the fee as the provider reported it, posted to its own account.
Reconciliation: each fee is posted once per matched provider line (key provider-item:…:fee); a fee that changes on a re-import is a difference (ProviderReconciliationTest; e2e H2).
PROVIDER FEE ALLOCATION POLICY = PENDING BUSINESS APPROVAL (CF-497). The figures in the browser run were GIVEN to the fake provider as a test fixture; they say nothing about a real provider's pricing.

## 7. REFUND IMPACT

Full Refund: of an order never completed — the customer's money goes back out of clearing; no earning existed, none is touched (e2e B1). Of a completed order — the confirmed refund leaves the provider and waits in REFUND_CLEARING until the liability is decided (e2e B3).
Partial Refund: the same, for its amount; an open refund holds the order's unsettled earning until it is over (policy switch, PENDING BUSINESS APPROVAL; e2e B2).
Restaurant Liability: by decision — a person with admin.finance.adjustments.create assigns the split with a reason (e2e B4), or a configured policy per refund reason does. The restaurant's part becomes a payable line (REFUND_ADJUSTMENT).
Platform Liability: the rest is the platform's PLATFORM_REFUND_EXPENSE. The default for EVERY reason is UNASSIGNED: nobody is charged until someone decides. Commission is never given back automatically.
Post-Payout Refund: a refund after the restaurant was paid becomes a new negative payable line that the next settlement carries (PayoutTest; e2e E1, F1).
Historical Payout Mutation: NO — the paid payout, its settlement, its lines and its postings are unchanged (asserted before and after in PayoutTest and e2e E1).
REFUND AND DISPUTE LIABILITY POLICY = PENDING BUSINESS / LEGAL APPROVAL (CF-496).

## 8. RESTAURANT EARNINGS

Gross: the merchandise of the restaurant's recognized orders (never the customer's total paid).
Commission: the frozen commission of those orders.
Adjustments: refund, dispute and manual adjustment lines.
Net: gross − restaurant-funded discounts − commission ± adjustments.
Pending: the estimated net of paid orders not yet completed — an estimate, not owed (e2e A3).
Available: recognized, not held, not yet in a settlement (e2e A4). No cooling period exists because none was approved (CF-493).
Held: lines under an active hold.
Paid: the settlements whose payout the provider confirmed (e2e D4).
Every figure comes from the payable lines and the ledger; the integrity check proves lines = ledger. The answer carries the definition of each figure.

## 9. FINANCIAL HOLDS

Supported: YES — on one payable line or on a whole location; held lines stay out of settlements, a held location is neither settled nor paid.
Reasons: REFUND_PENDING, DISPUTE, COMPLIANCE, RECONCILIATION, MANUAL_REVIEW.
Refund: placed by the system while the order has an open refund, released when it is over (switchable; ON).
Dispute: the same while the payment has an open dispute (switchable; ON).
Compliance: placed by a person with a note.
Reconciliation: placed by a person with a note.
Manual: placed by a person with a note (e2e G2: settlement generation answers LOCATION_HELD and consumes nothing).
Audit: placing and releasing need admin.finance.holds.manage and a reason; both are audited.
HOLD POLICY = PENDING BUSINESS APPROVAL (CF-502).

## 10. RESTAURANT SETTLEMENT

Model: restaurant_settlements + restaurant_settlement_lines — one settlement per location and currency for the lines up to a cutoff.
Cadence: DAILY, WEEKLY and MONTHLY are built; WEEKLY is a development fixture. No production schedule exists and scheduled generation is OFF (PENDING BUSINESS APPROVAL, CF-493).
Timezone: periods end at midnight of the market's own time zone (India: Asia/Kolkata); stored in UTC.
Status: CALCULATED, REVIEW_REQUIRED, APPROVED, PAYMENT_PENDING, PAID, FAILED, CLOSED, CANCELLED. The restaurant reads IN_REVIEW, APPROVED, PAYMENT_PENDING, PAID, CLOSED.
Lines: one immutable line per payable item (ORDER_EARNING, REFUND_ADJUSTMENT, DISPUTE_ADJUSTMENT, MANUAL_ADJUSTMENT) with the order number, gross, commission and net — no opaque total (e2e C3).
Gross: the sum of the earning lines' gross.
Refunds: the sum of the refund adjustment lines.
Commission: the sum of the earning lines' commission.
Adjustments: dispute and manual adjustment lines.
Net Payable: the sum of the lines; recomputed against the ledger when the settlement is approved.
Duplicate Protection: a payable line belongs to at most one live settlement (UNIQUE), generation locks the location; two requests at the same moment through two API processes made ONE settlement (e2e C2; SettlementTest; FinanceConcurrencyAndPerformanceTest).
Negative Settlement: never a settlement — NEGATIVE_CARRIED_FORWARD: no payout, no debit; the lines wait for the next earnings and finance is told (e2e E2, F1). A zero total is a CLOSED statement. A minimum payout is configuration and is unset.
Approval: admin.finance.settlements.approve with the version read; a settlement that no longer agrees with the ledger cannot be approved; four-eyes is a switch (OFF). After approval nothing but its payout moves it (e2e C4).

## 11. RESTAURANT PAYOUT

Model: restaurant_payouts — a separate domain from the settlement: a calculated or approved settlement is not money paid.
Provider: the PayoutProvider contract with ONE implementation, the development FAKE provider (refused in production). No Razorpay payout adapter exists.
Mode: FAKE PROVIDER (development). A payout made outside the platform can be RECORDED with its bank reference, date and evidence, for exactly the settlement's amount.
Status: REQUIRED, SUBMITTING, PROCESSING, UNKNOWN, PAID, FAILED, REVIEW_REQUIRED, CANCELLED. The restaurant reads PROCESSING, PAID, FAILED.
Amount Authority: the server — the approved settlement's own total; the request has no amount, currency or destination (e2e U11; contract test).
Idempotency: one active payout per settlement (partial unique index) and one idempotency key sent with every attempt; two administrators at the same moment through two API processes made ONE payout (e2e D1).
Timeout: never a failure and never a success — the payout becomes UNKNOWN and nothing is posted (e2e F2).
Unknown State: looked up by its idempotency key before anything is sent again; adopted if the provider has it, otherwise a person decides (PayoutTest; e2e F3).
Reconciliation: PayoutService.reconcile and the sweep ask the provider with bounded waits; a verified webhook is stored once and then the provider is ASKED — a webhook's body never marks a payout paid (e2e D3). PAID is posted once: Dr RESTAURANT_PAYABLE / Cr PAYOUT_CLEARING. A payout the bank returns is reversed by a new transaction.

LIVE PAYOUT = DISABLED
It is a constant in code (config/finance.php: live_enabled = false, no environment switch). Nothing in this module can move a restaurant's money.

## 12. RESTAURANT PROVIDER ACCOUNT

Linked Account: PENDING — no provider product for paying restaurants is enabled for this project (Razorpay Route needs enablement by Razorpay and regulated onboarding; RazorpayX is a separate product): PENDING PROVIDER ACCOUNT ENABLEMENT.
KYC: NOT BUILT. Nothing is collected and nothing is faked.
Bank Destination: NOT COLLECTED — no account number, IFSC or UPI id is asked for, stored, logged or returned. restaurant_payout_profiles is a readiness record (provider, the provider's own account reference, a masked label, a verification state); the four profiles on this machine are FAKE fixtures.
Provider Tokenization: the design — only the provider's reference and mask would be kept; not exercised with a real provider.
Production Approval: NOT APPROVED (CF-498).

## 13. PROVIDER SETTLEMENT

Provider: the FAKE settlement source on this machine. For Razorpay a reader exists inside the Razorpay adapter: PENDING PROVIDER VERIFICATION — no Razorpay credentials exist on this machine (CF-404, CF-499). The settlement reader follows the published Settlements API and is tested with a faked HTTP layer only; no request reached Razorpay.
API/Report: the ProviderSettlementSource contract — the provider's settlement records and their reconciliation lines for a day. Importing a report from a file is not built.
Settlement Model: provider_settlements (the provider's reference, gross / fee / tax / net as reported, settled-at, bank reference) and provider_settlement_items (one logical record per provider line).
Payments: each payment line is matched to the verified payment by the provider's reference and compared in amount and currency.
Refunds: each refund line is matched to the completed refund by the provider's refund reference and compared.
Fees: the reported fee and tax, posted once per matched line.
Net: the settlement's net is compared with the sum of its lines and posted as received once.
Import: php artisan finance:reconcile-provider --day=… and POST /admin/finance/provider-settlements/import (e2e H1).
Deduplication: a provider record imported again — also twice at the same moment — is the same logical record; nothing is posted again; a record that CHANGED is a difference (e2e H4; ProviderReconciliationTest).

## 14. RECONCILIATION

Payment: provider payment lines against verified payments; internally every paid order against its verified payment (amount and currency), orphan payments, orders without a payment (e2e H1, I2).
Refund: provider refund lines against completed refunds; internally refunds never exceed the payment and each has its posting.
Provider Fee: as reported, once per line; a change is a difference.
Provider Settlement: the settlement's total against its lines; received once.
Payout: every paid payout equals its settlement; unsettled payouts are asked about by the sweep.
Differences: reconciliation_differences — what was expected, what was found, the records concerned; one record per subject and kind (a unique dedupe key).
Review Queue: GET /admin/finance/differences and Admin › Finance › Reconciliation (e2e H3, U16).
Resolution Audit: OPEN → INVESTIGATING → RESOLVED / IGNORED_WITH_REASON by a person with a note and the permission; the numbers of a difference cannot be edited; the system closes one only when the records agree again (e2e H5).

## 15. FINANCIAL DIFFERENCES

Missing Internal: MISSING_INTERNAL_PAYMENT, MISSING_REFUND, UNKNOWN_REFERENCE — the provider settled something we do not have (e2e H3).
Missing Provider: MISSING_PROVIDER_PAYMENT, MISSING_PROVIDER_REFUND — ours, in no provider settlement after the expected days (5, a development default).
Amount Mismatch: AMOUNT_MISMATCH, PAYMENT_ORDER_AMOUNT_MISMATCH.
Currency Mismatch: CURRENCY_MISMATCH, PAYMENT_ORDER_CURRENCY_MISMATCH.
Refund Mismatch: REFUND_AMOUNT_MISMATCH, REFUND_EXCEEDS_PAYMENT.
Fee Mismatch: FEE_MISMATCH, PROVIDER_RECORD_CHANGED.
Settlement Mismatch: SETTLEMENT_TOTAL_MISMATCH, PAYOUT_SETTLEMENT_MISMATCH.
Also: ORDER_WITHOUT_PAYMENT, ORPHAN_PAYMENT, MISSING_LEDGER_POSTING, LEDGER_IMBALANCE, PAYABLE_MISMATCH, CLEARING_INVERTED, NEGATIVE_RESTAURANT_BALANCE, ALLOCATION_REVIEW_REQUIRED — 23 types, each with a severity.

## 16. RESTAURANT DASHBOARD

Earnings: REAL — Restaurant Dashboard › Earnings (new page) reads GET /restaurant/finance/* ; earnings are never development data (e2e U1).
Gross Sales: REAL. Commission: REAL. Adjustments: REAL. Pending: REAL (an estimate, said so). Available: REAL. Paid: REAL.
Settlements: the list and the statement with its lines and payout; the statement says it is not a tax invoice (e2e U4).
Payouts: status in the restaurant's words; the masked destination for the owner; the page says live payouts are not enabled (e2e U5).
Location Filter: YES — narrows every figure; a member sees only the locations assigned to them (e2e U2).
Export: CSV of the earnings of a period and of a statement (bounded, audited, formula-safe; e2e K3).
RBAC: restaurant.finance.view (OWNER, MANAGER); restaurant.payout_account.view (OWNER); order staff get no Earnings entry and no page (e2e U7).
Not done: the Overview and Analytics pages of the dashboard still show development figures (CF-503). The Earnings page was not designed by the owner and awaits review (CF-505).

## 17. ADMIN FINANCE

Overview: REAL — the ledger in figures; gross marketplace sales are labelled and never called revenue (e2e I3, U8).
Commission: commercial terms with TEST / APPROVED, create (future-dated), end, withdraw (e2e J1, U14).
Restaurant Payables: what each restaurant is owed now; negative balances flagged (e2e U13).
Settlements: queue, generation with a dry run, detail with lines and the ledger recheck (e2e C1–C3, U9, U10).
Approvals: approve / withdraw with the version read (e2e C4, U10).
Payouts: queue and detail; start, retry, ask the provider, cancel, record an external payout — on the FAKE provider; the banner says LIVE PAYOUT = DISABLED (e2e D1–D3, G3, U11, U12).
Provider Settlements: list, detail, import (e2e H1, U15).
Reconciliation: the differences queue and its workflow, the checks and their runs (e2e H3, H5, I1, I2, U16).
Adjustments: manual adjustments with reason and supporting reference; liability decisions (e2e B4, G1, U17).
Audit: every action is audited (who, what, the record, the reason).
RBAC: one permission per action; FINANCE_ADMIN all; OPERATIONS_ADMIN and ANALYST read parts; SUPPORT_ADMIN none (e2e K2, U20). The role matrix is to be confirmed (CF-500).
Not done: a restaurant picker (the location id is pasted) and some report / ledger filters on the screen (CF-503).

## 18. FINANCIAL SECURITY

Customer Access: none — finance routes are restaurant-staff or administrator routes; a customer's token is answered 401 (RestaurantFinanceApiTest).
Cross-Restaurant IDOR: another organization's settlement, export, summary, earnings and payouts answer 404 (RestaurantFinanceApiTest; e2e K1).
Cross-Location IDOR: a member limited to one location reads only that location (RestaurantFinanceApiTest).
Commission Confidentiality: a restaurant reads its own terms only; admin.finance.view does not include commercial terms (e2e J2).
Admin RBAC: separate permissions inside the administrator's market scope (AdminFinanceApiTest; e2e K2).
Mass Assignment: status, totals, amounts, paid-at, provider references, ledger accounts and entries are prohibited fields on every finance request (422; contract test; e2e C4, D2, G1).
Duplicate Payout: impossible — one active payout per settlement in the database, locks, idempotency key (e2e D1).
Direct Balance Edit: NO — no route edits a balance; the ledger API is read-only; there is no POST /ledger-entry.
Generic Mark Paid: NO — no such route, function or button (LedgerTest; e2e C4, D2; secret scan section 10).
Export Security: permission, scope, at most 92 days / 5000 rows, audit, CSV formula injection protected (= + - @ tab CR).
Bank Data: none collected, stored, logged or returned; the payout destination is a mask shown to the owner only.
Secret and sensitive-data audit: PASS (m36-secret-scan.txt).

## 19. TAX / ACCOUNTING READINESS

GST Rate Hardcoded: NO
TDS/TCS Hardcoded: NO
Tax Invoice: NOT PRODUCED — PENDING TAX / ACCOUNTING DESIGN. A settlement statement says it is not a tax invoice.
Credit Note: NOT PRODUCED — PENDING TAX / ACCOUNTING DESIGN.
Commission Invoice: NOT PRODUCED — PENDING TAX / ACCOUNTING DESIGN.
Professional Tax Review: PENDING — merchant of record, who owes the tax collected on an order (built: UNASSIGNED → held in TAX_LIABILITY, nobody's revenue or earning), GST on commission, TDS / TCS and any withholding (nothing is deducted). CF-494.
Outstanding policy: everything in this section is open. No tax is seeded on this machine, so no order of the browser run carried tax.

## 20. DATABASE

Ledger Accounts: ledger_accounts. Ledger Transactions: ledger_transactions. Ledger Entries: ledger_entries.
Commercial Terms: commission_plans. Financial Allocation: order_financial_allocations (+ restaurant_payable_items, financial_adjustments).
Settlements: restaurant_settlements. Settlement Lines: restaurant_settlement_lines. Payouts: restaurant_payouts, restaurant_payout_profiles, payout_webhook_events.
Provider Settlements: provider_settlements, provider_settlement_items. Reconciliation Differences: reconciliation_differences. Financial Holds: financial_holds. Runs: finance_runs.
Indexes: 113 on the seventeen tables, 55 of them unique.
Constraints: 75 CHECK constraints, 76 foreign keys, 5 triggers (ledger_entries_balanced, ledger_entries_immutable, ledger_transactions_has_entries, ledger_transactions_immutable, restaurant_settlement_lines_immutable), 4 functions.
Migration: rollback and re-apply on the test database — DONE (backend-m36-migration-check.txt). The local database was migrated, given the roles and the development fixtures and backfilled (backend-m36-local-setup.txt).

## 21. CONCURRENCY

Ledger Posting: the unique posting key — the same event from two workers posts once (LedgerTest; FinanceConcurrencyAndPerformanceTest).
Refund Adjustment: one adjustment per refund and per dispute (UNIQUE); its liability posting has its own key (refund:{id}:liability), so a decision is posted once and deciding again answers the adjustment as it is.
Settlement: the location is locked; two requests at the same moment through two API processes made one settlement and every line was consumed once (e2e C2).
Payout: the settlement, then the payout, are locked; two administrators at the same moment made one payout (e2e D1).
Provider Import: two imports of the same day at the same moment made one logical record per line and posted nothing twice (e2e H4).
Reconciliation: one difference per subject and kind (a unique dedupe key); reported again — also by two runs at once — it is the same record.

## 22. PERFORMANCE

Measured in the test environment on this machine against the FAKE providers (m36-performance.json) — not a production measurement.
Ledger Posting: a manual adjustment with its balanced posting: 14 queries, 10.3 ms each (over 190). Completing an order with its recognition: 88 queries, 63 ms (inline under the test queue; a queued job otherwise).
Restaurant Earnings: summary 13 queries, 14 ms with 202 lines; a page of 100 lines 8 queries, 23 ms.
Settlement Generation: 202 lines — 12 queries, 78 ms. Approval with the ledger recheck: 23 queries, 58 ms.
Settlement Detail: 202 lines — 8 queries, 34 ms; the list 6 queries, 5 ms.
Finance Dashboard: overview 22 queries, 37 ms over 204 ledger transactions; payables 10 queries, 10 ms; a ledger page of 100 4 queries, 27 ms.
Reconciliation: provider import and reconciliation of 50 lines — 366 queries, 253 ms (bounded per record; acceptable for a nightly job, noted).
N+1: none found — the earnings summary and list, the payables, the ledger list and the overview cost at most one query more with 202 lines than with a few, and a settlement of 202 lines is generated and read without a query per line (asserted in FinanceConcurrencyAndPerformanceTest). EXPLAIN: 8 finance queries, each on its index.
HTTP through the local stack in the browser run (m36-web-timings.json): recognition_after_completion 2740 ms (n=1), admin_settlement_list 323 ms (n=1), payout_webhook_to_paid 471 ms (n=1), restaurant_summary 355 ms (n=1), admin_overview 476 ms (n=1).

## 23. OBSERVABILITY

GET /admin/system/finance and the Finance card on Admin › System (e2e Y1, U21); 41 counters over a 48-hour window; structured log lines by public id, amount, currency and status — no destination, credential or customer contact (e2e Y2).
Ledger Errors: the state and time of the last integrity check with its problems; counters ledger_refused and ledger_imbalance; what is verified / refunded / completed and has no posting yet (the finance queue's backlog).
Commission Posted: counters order_recognized, commission_minor and gross_minor; totals in the overview and the commission report.
Restaurant Payable: GET /admin/finance/payables and the overview (per restaurant: available, held, in settlement, balance; negative flagged).
Settlements: counters settlement_generated / approved / cancelled / negative_carried; the queue by status.
Payouts: counters payout_started / submitted / paid / external; the provider in use and LIVE PAYOUT = DISABLED.
Payout Failures: counters payout_failed / unknown / review_required / reversed / duplicate_blocked and the refused payout webhooks.
Provider Settlements: counters provider_import / item_imported / item_repeated / fee_posted; the last import run; the source in use (FAKE); PENDING PROVIDER VERIFICATION for Razorpay.
Reconciliation Differences: open by severity; counters difference_opened / resolved / auto_resolved; the last internal reconciliation run.
Negative Balances: a NEGATIVE_RESTAURANT_BALANCE difference per restaurant in the review queue; counter settlement_negative_carried.
The same view states every pending policy and the tax-document flags.

## 24. TESTING

Ledger: PASS — LedgerTest (5), OrderRecognitionTest (2); e2e A1, A4, A5, I1.
Commission: PASS — CommissionTest (6); e2e A2, J1, J2.
Promotions: PASS — PromotionAndRefundTest.
Refund Impact: PASS — PromotionAndRefundTest (8); e2e B1–B4.
Earnings: PASS — RestaurantFinanceApiTest (5); e2e A3, A4, D4.
Settlement: PASS — SettlementTest (8); e2e C1–C4, E2, F1.
Payout: PASS on the FAKE provider — PayoutTest (12); e2e D1–D4, F2, F3, G3. A real payout provider: NOT RUN (none exists).
Provider Settlement: PASS on the FAKE source and on a faked HTTP layer for the Razorpay reader — ProviderReconciliationTest (4); e2e H1–H5. Razorpay itself: NOT RUN (PENDING PROVIDER VERIFICATION).
Reconciliation: PASS — ProviderReconciliationTest, AdminFinanceApiTest (7); e2e H3, H5, I1, I2.
Post-Payout Refund: PASS — PayoutTest; e2e E1, E2, F1.
Concurrency: PASS — FinanceConcurrencyAndPerformanceTest (3); e2e C2, D1, H4 (two API processes).
Idempotency: PASS — LedgerTest, PayoutTest, ProviderReconciliationTest; e2e A5, D3, H4.
Security: PASS — RestaurantFinanceApiTest, AdminFinanceApiTest, FinanceOpenApiContractTest (2); e2e K1–K3, C4, D2, G1; secret scan PASS.
Restaurant Dashboard: PASS — e2e U1–U7 with an axe check; apiFinance.test.tsx (13).
Admin Dashboard: PASS — e2e U8–U22 with axe checks; ApiAdminFinanceRepository.test.tsx (18), FinanceMetricsCard.test.tsx (3).
Regression: backend 1229/1229; browser 947/970 over 16 suites (auth 48/48, market 51/51, site 68/68, restaurants 97/101, menus 48/48, account 62/63, journeys 61/63, cart 76/76, pickup 69/69, checkout 75/77, payments 57/63, orders 47/53, realtime 47/48, notifications 40/41, reviews 48/48, refunds 53/53). New misses with Module 36: none — every miss was already a recorded miss after Module 35. Defects of Module 36 found by the regression: none. Every miss is named with its cause in m36-regression-notes.txt.
Passed: backend 1229 of 1229 (53536 assertions; 62 new in tests/Feature/Finance); web unit 793/793 (tsc 0 errors; oxlint 0 errors, 135 warnings; vite build OK); web e2e 67/67; Flutter 581; browser regression 947/970.
Failed: backend 0; web unit 0; web e2e 0; browser regression 23 recorded misses (see the notes).
Skipped: Razorpay settlements in TEST MODE (no credentials); any real payout provider (none exists); a real device (owner; nothing on Android changed); an Android preview run (no Android screen changed).

## 25. MOCK-TO-REAL STATUS

Real (local backend): Authentication, Market, Restaurant, Menu, Journey (development routing providers), Cart, Pickup, Checkout, Payments (development provider; Razorpay pending), Orders, Realtime, Pickup Verification, Notifications (development push provider; real FCM / e-mail / SMS pending), Reviews, Refunds (development provider; Razorpay refunds pending), Financial Ledger, Commission Calculation (TEST plans), Restaurant Earnings, Restaurant Payables, Restaurant Settlements, Financial Reconciliation, Finance Reporting.
Provider-Dependent: Live Restaurant Payout Provider — NOT CONFIGURED (FAKE provider only; LIVE PAYOUT = DISABLED); Provider Marketplace Transfers — PENDING PROVIDER ACCOUNT ENABLEMENT; Provider Settlement API — PARTIAL (reader built, PENDING PROVIDER VERIFICATION); Restaurant KYC / Linked Account — PENDING (not built, not faked).
Still Mock: the Restaurant Dashboard's Overview and Analytics figures; Admin › Overview and Analytics; the Module 18 admin screens that no backend module has replaced yet (customers, support, configuration, admin users, audit logs, security) — listed per page in the tracker.
Future: Full Dispute / Chargeback Operations, Support Case Management, Operational Escalations, Tax Invoice / Credit Note, Accounting Platform Integration, Advanced Analytics / Data Warehouse, Production Infrastructure, Production Security / Load Testing, Production Deployment.

## 26. BUGS

Critical: none
High: none
Medium: BUG-263, BUG-264, BUG-265, BUG-267, BUG-268
Low: BUG-266, BUG-269, BUG-270, BUG-271, BUG-272, BUG-273, BUG-274
Open: none
Fixed: BUG-263, BUG-264, BUG-265, BUG-266, BUG-267, BUG-268, BUG-269, BUG-270, BUG-271, BUG-272, BUG-273, BUG-274
Verified: every fixed bug was verified by the test or the run named in its row.

- BUG-263 (Medium, BACKEND): Four finance actions validated the request before checking the permission — FIXED + VERIFIED
- BUG-264 (Medium, BACKEND): The finance reports failed in PostgreSQL when grouping by the market's day — FIXED + VERIFIED
- BUG-265 (Medium, BACKEND): A unique index per settlement period would have refused a legitimate second settlement — FIXED + VERIFIED
- BUG-266 (Low, BACKEND): migrate:fresh failed on the finance migration: "function ledger_refuse_change already exists" — FIXED + VERIFIED
- BUG-267 (Medium, BACKEND): The Razorpay settlement reader read provider credentials outside the provider adapter — FIXED + VERIFIED
- BUG-268 (Medium, BACKEND): Restaurant finance answers carried internal settlement and payout statuses — FIXED + VERIFIED
- BUG-269 (Low, BACKEND): Empty maps in finance answers were JSON arrays — FIXED + VERIFIED
- BUG-270 (Low, BACKEND): The browser could not read the file name of a finance export — FIXED + VERIFIED
- BUG-271 (Low, BACKEND): can.export was true with only one of the two permissions an export needs — FIXED + VERIFIED
- BUG-272 (Low, WEB): The small print of the Earnings banner failed WCAG AA contrast (4.42 : 1) — FIXED + VERIFIED
- BUG-273 (Low, QA): Runs of the Module 36 web suite that missed or measured wrongly for reasons of the suite (and once of the page) — FIXED + VERIFIED
- BUG-274 (Low, QA): A full backend run failed in three finance tests because the local .env changed while it ran — FIXED + VERIFIED

## 27. EVIDENCE

Database: backend-m36-migration-check.txt (rollback + re-apply; tables, triggers, constraints, indexes), backend-m36-local-setup.txt, backend/database/migrations/2026_10_16_100000_module36_finance.php.
Ledger: backend-m36-phpunit.txt (LedgerTest, OrderRecognitionTest); m36-web-e2e.json steps A1, A4, A5, I1; m36-api-samples.json (ledger_check); screenshot 19-admin-ledger.png.
Commission: CommissionTest; steps A2, J1, J2; m36-api-samples.json (commission_plan, restaurant_terms); screenshots 05-dashboard-terms.png, 14-admin-commission.png.
Settlement: SettlementTest; steps C1–C4, E2, F1; m36-api-samples.json (admin_settlement, restaurant_settlement); screenshots 03, 07, 08, 09, 11.
Payout: PayoutTest; steps D1–D4, F2, F3, G3; m36-api-samples.json (restaurant_payouts); screenshots 04, 10, 12. FAKE provider only — there is no real payout reference to record.
Provider: ProviderReconciliationTest; steps H1–H4; m36-api-samples.json (provider_settlement); screenshot 15. Razorpay settlement reference: NONE — PENDING PROVIDER VERIFICATION.
Reconciliation: steps H3, H5, I1, I2; m36-api-samples.json (difference, internal_check); screenshot 16.
Restaurant: RestaurantFinanceApiTest; steps A3, D4, K1, U1–U7; m36-api-samples.json (restaurant_summary); screenshots 01–05.
Admin: AdminFinanceApiTest; steps I3, K2, U8–U21; m36-api-samples.json (admin_overview, liability_decision, finance_health); screenshots 06–21.
Security: steps C4, D2, G1, K1–K3, U7, U20, Y2; FinanceOpenApiContractTest; m36-secret-scan.txt.
Tests: backend-m36-phpunit.txt (1229/1229), m36-performance.json, m36-web-e2e.json (67/67), m36-web-timings.json, web-build-lint-m36.txt, flutter-test-m36.txt, m36-regression-runs.txt, m36-regression-notes.txt, m36-regression-*.json, backend-m36-routes.txt, backend/openapi/openapi.json (292 paths, 503 schemas). Screenshots: docs/local-review/screenshots/web-m36/ (21). APK build040: built, 235.6 MB, SHA-256 2522E1969DB42D3DB907D47781888BE33E50088BACD7530C242D77F8B4F9AB74 — USER DEVICE VERIFICATION = PENDING.
No provider secret and no unmasked banking detail is recorded anywhere.

## 28. PROJECT TRACKER

Updated: /docs/project-progress.html (generated from docs/tracker/data.json by docs/tracker/build.py). Modules 38, requirements 3691 (M36: 204), decisions 830, carry-forwards 509 (new: CF-492 … CF-509), bugs 274 (new: BUG-263 … BUG-274), tests 396 (new: TE-382 … TE-396). Nothing was removed; the comparison with the copy taken before the update is clean.

## 29. PRODUCTION READINESS

FINANCIAL LEDGER = COMPLETE (local)
COMMISSION ENGINE = COMPLETE (local) — the engine; COMMISSION TERMS = PENDING BUSINESS APPROVAL
RESTAURANT EARNINGS = COMPLETE (local)
RESTAURANT SETTLEMENTS = COMPLETE (local) — the schedule and the policies = PENDING BUSINESS APPROVAL
RESTAURANT PAYOUT DOMAIN = PARTIAL — complete on the FAKE provider; no real payout provider, no restaurant onboarding
LIVE RESTAURANT PAYOUTS = DISABLED / NOT APPROVED
PROVIDER RECONCILIATION = PARTIAL — complete on the FAKE source; Razorpay = PENDING PROVIDER VERIFICATION
TAX/ACCOUNTING POLICY = PENDING
PRODUCTION = NO

## 30. MODULE 37 READINESS

MODULE 37 MAY START = YES

Not blockers for Module 37, and the owner's to decide or provide: the commission terms (CF-492); the earning and settlement policy (CF-493); the tax / accounting design with a professional (CF-494); promotion split and fee ownership (CF-495); refund and dispute liability (CF-496); provider fee allocation (CF-497); a payout provider and restaurant onboarding (CF-498); Razorpay TEST credentials (CF-499, CF-404); the finance role matrix and four-eyes (CF-500); what a restaurant sees (CF-501); the hold policy (CF-502); the negative-balance policy (CF-508); the device check of build040 (CF-504); the browser review (CF-505); approval of Modules 24–36 in words (CF-506).

The completion gate of the specification (section 353):

| Point | Answer | Where it is shown |
|---|---|---|
| Module 35 approved | by the gate (MODULE 36 MAY START = YES); the owner's approval in words is pending | CF-506 |
| finance domain audited | YES | docs/backend/finance.md; tracker M36-002 |
| ledger architecture implemented | YES | section 2 |
| ledger transactions balance | YES | LedgerTest; e2e I1 |
| ledger immutable | YES | LedgerTest; database triggers |
| duplicate postings blocked | YES | LedgerTest; e2e A5 |
| Payment financial posting works | YES | e2e A1 |
| Order financial allocation works | YES | e2e A2, A4 |
| Refund adjustment works | YES | e2e B1–B4 |
| commission engine works | YES | CommissionTest |
| commission configuration works | YES | e2e J1 |
| effective dates work | YES | CommissionTest; e2e J1 |
| historical commission remains immutable | YES | CommissionTest |
| no production commission rate hardcoded | YES | secret scan section 10 |
| Restaurant earnings work | YES | e2e A3, A4, D4 |
| Restaurant payable works | YES | e2e I1 |
| pending/available state works according to configured policy | YES — the policy itself is pending approval | section 8; CF-493 |
| Restaurant Settlement works | YES | e2e C1–C4 |
| line-item traceability works | YES | e2e C3 |
| duplicate settlement blocked | YES | e2e C2 |
| negative settlement safely handled | YES | e2e E2, F1 |
| approved Settlement immutable | YES | SettlementTest; e2e C4 |
| Restaurant Payout model works | YES on the FAKE provider | e2e D1–D4 |
| payout idempotency works | YES | e2e D1 |
| live provider capability accurately represented | YES — LIVE PAYOUT = DISABLED, no real provider | sections 11, 12 |
| no fake production payout claims | YES | sections 11, 29 |
| Provider Settlement/reconciliation works to actual available scope | YES on the FAKE source; Razorpay PENDING PROVIDER VERIFICATION | section 13 |
| Payment reconciliation works | YES | e2e H1, I2 |
| Refund reconciliation works | YES | e2e H1, I2 |
| provider fee reconciliation uses actual provider information | YES | e2e H2 |
| differences remain visible | YES | e2e H3, H5 |
| post-payout Refund adjustment works | YES | e2e E1, F1 |
| historical Payout remains unchanged | YES | e2e E1; PayoutTest |
| Restaurant Dashboard uses real finance backend | YES | e2e U1–U7 |
| location/tenant isolation works | YES | e2e K1, U2 |
| Restaurant finance RBAC works | YES | e2e U7 |
| Admin Finance uses real backend | YES | e2e U8–U21 |
| Finance RBAC works | YES | e2e K2, U20 |
| financial audit works | YES | AdminFinanceApiTest; e2e G2, H5 |
| no unsafe Mark Paid/balance-edit controls | YES | e2e C4, D2, U19; secret scan |
| no GST hardcoding | YES | secret scan section 10 |
| no TDS/TCS hardcoding | YES | secret scan section 10 |
| unknown tax/accounting policies remain visible blockers | YES | section 19; e2e U21 |
| concurrency tests pass | YES | section 21 |
| idempotency tests pass | YES | section 24 |
| security tests pass | YES | section 18; secret scan PASS |
| financial invariant tests pass | YES | e2e I1, I2 |
| regression tests pass | backend YES (1229/1229); browser 947/970 with every miss explained | m36-regression-notes.txt |
| no critical bugs remain | YES | section 26 |
| no high-severity Module 36 blockers remain | YES | section 26 |
| bugs recorded | YES | section 26 |
| evidence recorded | YES | section 27 |
| /docs/project-progress.html updated | YES | section 28 |

## 31. NEXT MODULE

NEXT:

MODULE 37 — CUSTOMER SUPPORT, SUPPORT CASES, PAYMENT/ORDER/REFUND DISPUTE OPERATIONS, RESTAURANT ESCALATIONS, ADMIN SERVICE DESK, CASE TIMELINES, INTERNAL NOTES & OPERATIONAL AUDIT

STOP.

DO NOT automatically begin Module 37.
