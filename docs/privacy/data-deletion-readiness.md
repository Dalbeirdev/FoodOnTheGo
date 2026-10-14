# Data deletion readiness

Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/app/Console/Commands/PrivacyEraseCustomer.php`, `backend/app/Services/Privacy/CustomerErasureService.php`, `backend/app/Console/Commands/AnalyticsPrune.php`, `backend/app/Services/Analytics/{Pseudonymizer,Aggregation/FactBuilder}.php`, `backend/app/Services/Auth/CustomerLoginService.php`, `backend/app/Services/Customer/CustomerAccountService.php:75-91`, `backend/tests/Feature/Security/DataProtectionTest.php:152-203`, M39 `audit_privacy.md` (P-03, FK listing), M39 `bugs.md` (BUG-320).

> **Technical mapping, not legal advice. PENDING LEGAL REVIEW.** The erasure command is a FOUNDATION built in Module 39.
> What it keeps, and for how long, is a business and legal decision that has not been made (PENDING BUSINESS APPROVAL).

## 1. The command

```
php artisan privacy:erase-customer {customer public id}                      # dry run (default)
php artisan privacy:erase-customer {customer public id} --execute --reference=<ticket or request id>
```

| Behaviour | Detail |
|---|---|
| Who runs it | an operator with shell access, after the request was reviewed — never automatically (`CustomerErasureService.php:20-22`) |
| Dry run (default) | prints `{"erased": false, "dry_run": true, "would_change": {…counts…}}` and changes nothing |
| Execute | requires `--reference` (stored, ≤ 80 characters, in the audit reason); prints the counts it changed |
| Unknown id | exit 1, "This customer does not exist." |
| Output | JSON counts only — no personal data is printed |
| Transaction | everything except the avatar file deletion runs in one database transaction (a failure changes nothing — BUG-320 was found this way and fixed) |
| Audit | `customer.erased`, reason "Erasure after a deletion request (<reference>)" |

### Blockers (`blockers()`, exit 1 with the list)

| Blocker | Rule |
|---|---|
| `already_erased` | the phone already starts with the reserved `+999` placeholder — a second run is refused |
| `no_deletion_request` | `deletion_requested_at` is empty — erasure answers a customer's request only |
| `open_orders:<n>` | any order of the customer not in a terminal status |
| `refunds_not_final:<n>` | any refund not REFUNDED, FAILED or CANCELLED |
| `open_support_cases:<n>` | any case not RESOLVED, CLOSED or CANCELLED |

Open business needs the person to be reachable, so it must finish first.

## 2. What erasure changes (anonymise in place)

Erasure never deletes the customer row: orders, payments, refunds, reviews and support cases reference it (foreign
keys RESTRICT), and those are kept as financial or case records.

| Data | Action | Code |
|---|---|---|
| `customers`: name, e-mail, date of birth, gender, favourite cuisines, avatar path / type / date, deletion reason, marketing-consent date | set to null | `CustomerErasureService.php:112-116` |
| `customers.phone_e164` | replaced by `+999` + the internal id padded to 11 digits (reserved, not dialable; keeps the unique constraint); `phone_verified_at` null | `:113` |
| `customers.status` | DEACTIVATED (cannot sign in) | `:115` |
| Avatar file | deleted from the configured avatar disk (`CUSTOMER_AVATAR_DISK`) after the transaction | `:119-121` |
| `orders` (all of the customer's) | `customer_note` → null; `snapshot.customer.name` → null, `.email` → null, `.phone` → masked (`+91******1234` style); order kept | `:96-100` |
| `personal_access_tokens` | deleted | `:101` |
| `customer_devices` | revoked (`ACCOUNT_CLOSED`), push token and hash nulled; rows kept | `:102` |
| `customer_saved_locations`, `customer_recent_locations`, `customer_favorite_locations`, `customer_payment_methods`, `customer_phone_changes` | deleted | `:103-105` |
| `analytics_events`, `analytics_session_facts`, `analytics_order_facts` | `customer_key` → null | `:106-109` |

Note on naming: the dry-run key is `devices_revoked` (renamed after this review — devices are revoked, not deleted); `item_instructions_removed` counts order items whose free-text instructions are cleared.

## 3. What is kept, and why

| Kept | Why | Personal data left |
|---|---|---|
| Customer row (anonymised) | referenced by kept records | public id, market, locale, `vegetarian_only`, `search_radius_km`, `terms_accepted_at`, `promotions_withdrawn_at`, `last_login_at`, timestamps |
| Orders, items, events, pickup credentials, verification attempts | financial / operational record | order items' `special_instructions` (free text) are cleared (fixed after this review); masked phone in the snapshot |
| Payment attempts, webhook events | financial record | `customer_public_id`, method summary (last 4, masked VPA) |
| Refunds, refund events, disputes | financial record | free-text notes, `customer_public_id` |
| Ledger, settlements | accounting | no customer identity |
| Reviews, revisions, reports | `WITH_ORDER` policy | `author_display_name` (first name + initial), comment and revision text — **not changed** |
| Support cases and messages | case history | message text, subject, summary — **not changed** (messages cannot be updated by trigger) |
| Inbox notifications | until their own expiry (90 / 365 days) | order numbers, restaurant names |
| Carts, checkouts, legal acceptances | until their own clean-up (converted ones for ever) | cart journey labels, checkout snapshot |
| Notification preferences | settings only | none |
| Security events, audit events | evidence; append-only | principal id, ip, user agent |
| Logs, backups | outside the database | redacted logs; backups untouched |
| Journeys | deleted by `journeys:prune` within about 2.5 h anyway (not touched by erasure) | — |

## 4. Known limits found while documenting

| # | Limit | Effect |
|---|---|---|
| L-1 | Order facts are rebuilt from `orders.customer_id` with the same keyed pseudonym (`FactBuilder.php`, `pseudonyms->sql('customer', 'o.customer_id')`, `on conflict … do update`). The erased customer row keeps its id. | After an `analytics:rebuild` covering the customer's orders, or a later change to one of those orders, `analytics_order_facts.customer_key` is filled again. Events and session facts stay anonymous. Decision (Module 39): accepted for an erasure — the re-derived key points to the anonymised account (no name, e-mail, number or address), so it identifies no one; for an analytics-only forget (`analytics:prune --forget-customer`) the command must be run again after a rebuild — carried forward. |
| L-2 | (Fixed after this review) the avatar file is deleted from the configured avatar disk | — |
| L-3 | (Fixed after this review) the audit row records the status the account really had before the erasure | — |
| L-4 | The old phone number can sign up again and gets a new, unlinked account (`CustomerLoginService::signIn`) | expected; to be stated in the privacy notice |
| L-5 | Free text written by the customer (review text, support messages, refund notes) is not reviewed or redacted; order customer notes and item instructions ARE cleared | personal data may remain in it |

## 5. Not built

| Item | Status |
|---|---|
| Self-service data export (access / portability) | NOT BUILT |
| Automatic erasure after a waiting period | NOT BUILT — the waiting period is a business / legal decision |
| Erasure or redaction of support message text and review text | NOT BUILT |
| Restaurant staff and administrator erasure | NOT BUILT |
| Admin screen listing deletion requests and their age | NOT BUILT |
| Notice to the customer when erasure is done | NOT BUILT |
| Erasure inside backups and log stores | DEPLOYMENT (no backups exist yet) |
| Processor deletion requests (Razorpay, FCM, SMS, e-mail providers) | NOT BUILT; providers not configured |

## 6. Tests

`DataProtectionTest::test_erasure_waits_for_open_business_then_anonymises_and_keeps_the_financial_record`
(`:152-203`): without a request and with an open order, erasure is refused (`no_deletion_request`, `open_orders:1`);
after pickup and a deletion request, the dry run reports one order to mask and changes nothing; `--execute` without
`--reference` fails; with it the account becomes DEACTIVATED with a `+999` number and no e-mail, the order stays
COMPLETED with its total and without the full phone number in its snapshot, the session no longer works, one
`customer.erased` audit row carries the reference, and a second run is refused with `already_erased`. L-1 is not
covered by a test.

## 7. Readiness

| Capability | Ready? | Notes |
|---|---|---|
| Record a deletion request with re-authentication | YES (local) | `account-deactivation.md` |
| Stop new purchases after the request | YES (local) | RESTRICTED blocks checkout and payment |
| Refuse erasure while business is open | YES (local) | orders, refunds, support cases |
| Dry run before erasure | YES (local) | counts only |
| Anonymise account and order contact; delete places, payment references, tokens | YES (local) | tested |
| Remove the analytics pseudonym | PARTIAL | events and sessions durable; order facts re-derived on rebuild (L-1) |
| Keep financial records | YES | retention period itself NOT DECIDED |
| Audit trail of erasure with a reference | YES (local) | `customer.erased` |
| Erase free text (reviews, support, notes) | NO | |
| Data export for the customer | NO | |
| Deadline tracking and confirmation notice | NO | |
| Staff erasure | NO | |
| Backups / logs / processors | NO | deployment and provider work |
| Approved retention and erasure policy | NO | PENDING BUSINESS APPROVAL and LEGAL REVIEW |

Overall: a working foundation for operator-run erasure of a customer; **not ready for production use** until the
policy is approved and the NO rows have an owner and a decision.
