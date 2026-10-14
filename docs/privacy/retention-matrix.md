# Retention matrix

Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/routes/console.php`, `backend/app/Models/{OtpChallenge,IdempotencyKey,CustomerPhoneChange,CredentialResetToken,RestaurantStaffInvitation}.php`, `backend/app/Console/Commands/{AnalyticsPrune,PruneNotifications,PrivacyEraseCustomer}.php`, `backend/app/Services/{Cart/CartService,Checkout/CheckoutService,Journey/JourneyService,Customer/RecentLocationService,Customer/CustomerAvatarService,Privacy/CustomerErasureService,Analytics/Reports/ReportExportService}.php`, `backend/config/{api,analytics,auth_security,cart,checkout,customer,journey,notifications,logging,support,reviews}.php`, `backend/database/migrations/2026_10_19_100000_module39_security.php`. Engineering detail: `docs/security/data-retention.md`.

> **Technical mapping, not legal advice. PENDING LEGAL REVIEW.** Every period is a development default **PENDING
> BUSINESS APPROVAL**. No period below was approved by the business or reviewed by Legal. The "Proposed" column does
> not invent new numbers: it either proposes keeping the current default for approval, or says a decision is needed.

Legend for "Deletion method": **Auto** = a scheduled command deletes it; **Expiry** = a time-to-live in the cache;
**Customer** = the customer deletes it in the app; **Erasure** = `privacy:erase-customer` (operator, after a deletion
request — `docs/privacy/data-deletion-readiness.md`); **None** = nothing removes it today.

## 1. Identity and account

| Data category | Current retention (code) | Proposed retention | Deletion method | Legal basis |
|---|---|---|---|---|
| Customer account (phone, name, e-mail, birth date, gender, cuisines, avatar) | for the life of the account; never deleted | keep while active; after a deletion request, erase once nothing is open — waiting period: decision needed | Customer (profile edits, avatar remove); Erasure (anonymise in place) | PENDING LEGAL REVIEW |
| Deletion request record and reason | until erasure (reason cleared by erasure) | decision needed | Erasure | PENDING LEGAL REVIEW |
| Marketing consent timestamps | for the life of the account (`promotions_consented_at` cleared by erasure) | decision needed (proof of consent) | Erasure (partly) | PENDING LEGAL REVIEW |
| One-time code challenges (phone number, code HMAC) | 7 days after expiry (code constant); code valid 300 s (config) | keep current default | Auto (`model:prune` daily) | PENDING LEGAL REVIEW |
| Phone-change requests (new number) | 30 days after expiry (code constant) | keep current default | Auto (`model:prune`); Erasure | PENDING LEGAL REVIEW |
| Sessions (token hash, ip, user agent) | until expiry + 24 h; customer token 30 days, restaurant 12 h, admin 8 h, admin idle 60 min (config) | keep current defaults | Auto (`sanctum:prune-expired`); sign-out deletes at once; Erasure | PENDING LEGAL REVIEW |
| Push devices (token, installation id, app version, locale) | token encrypted; nulled 30 days after revocation (config); row kept | keep token default; row retention: decision needed | Auto (token only); Erasure (token removed, row revoked) | PENDING LEGAL REVIEW |
| Notification preferences | life of the account | keep | None (harmless settings) | PENDING LEGAL REVIEW |
| Staff accounts (name, e-mail, hashed password, encrypted MFA) | never deleted; DISABLED / REVOKED | decision needed | None — no staff erasure exists | PENDING LEGAL REVIEW |
| Staff invitation / reset tokens (hashes) | 30 / 7 days after expiry (code constants) | keep current defaults | Auto (`model:prune`) | PENDING LEGAL REVIEW |

## 2. Location and journeys

| Data category | Current retention (code) | Proposed retention | Deletion method | Legal basis |
|---|---|---|---|---|
| Journey (origin, destination, coordinates, route) | expires 30 min after planning; deleted 60 min later (config) → at most about 1.5–2.5 h | keep current defaults | Auto (`journeys:prune` hourly) | PENDING LEGAL REVIEW |
| Journey cache (route, places, results) | 60–300 s (config) | keep | Expiry | PENDING LEGAL REVIEW |
| Recent places | 90 days after last use (config) | keep current default | Auto (daily); Customer (clear); Erasure | PENDING LEGAL REVIEW |
| Saved places (addresses) | until the customer deletes them | keep | Customer; Erasure | PENDING LEGAL REVIEW |
| Journey labels on converted carts (`carts.journey_summary`) | for ever | decision needed | None | PENDING LEGAL REVIEW |

## 3. Ordering and money

| Data category | Current retention (code) | Proposed retention | Deletion method | Legal basis |
|---|---|---|---|---|
| Carts (items, instructions, guest token hash) | guest 7 d / customer 30 d TTL, closed +24 h, deleted 30 d after closing (config); **converted carts never** | keep defaults for unconverted; converted: decision needed | Auto (`carts:prune` hourly) | PENDING LEGAL REVIEW |
| Checkouts and their legal acceptances | closed (expired / stale / cancelled) deleted after 30 d unless a payment exists (config); converted never | decision needed for converted ones (acceptance evidence) | Auto (`checkouts:prune`) | PENDING LEGAL REVIEW |
| Orders (items, notes, customer contact snapshot) | **never deleted** (financial record) | statutory period: decision needed | None; Erasure masks the contact (name and e-mail null, phone masked, note removed) | PENDING LEGAL REVIEW |
| Restaurant view of a customer's contact | full while open and 24 h after the order ended, then masked (config `RESTAURANT_CONTACT_HOURS`) | keep current default | presentation rule (data stays in the order) | PENDING LEGAL REVIEW |
| Pickup credentials (encrypted secret and code) | kept with the order; status changes only | decision needed (could be nulled after use) | None | PENDING LEGAL REVIEW |
| Idempotency replies (encrypted; may hold pickup code or payer contact) | 24 h (config) | keep current default | Auto (`model:prune`) | PENDING LEGAL REVIEW |
| Payment attempts (references, last 4, masked VPA) | never deleted | statutory period: decision needed | None | PENDING LEGAL REVIEW |
| Payment webhook events (references, safe summary, no payload) | never deleted | decision needed | None | PENDING LEGAL REVIEW |
| Refunds, disputes (notes) | never deleted | statutory period: decision needed | None | PENDING LEGAL REVIEW |
| Ledger, settlements, payouts | never deleted; ledger and settlement lines immutable by trigger | statutory period: decision needed | None | PENDING LEGAL REVIEW |
| Saved payment-method references | until the customer revokes; rows kept as revoked | decision needed | Customer (revoke); Erasure (deleted) | PENDING LEGAL REVIEW |

## 4. Communication, reviews, support

| Data category | Current retention (code) | Proposed retention | Deletion method | Legal basis |
|---|---|---|---|---|
| Inbox notifications | 90 days; security notices 365 days (config) | keep current defaults | Auto (`notifications:prune` daily) | PENDING LEGAL REVIEW |
| Notification delivery attempts | 30 days after finishing (config) | keep current default | Auto | PENDING LEGAL REVIEW |
| Reviews, responses, revisions, reports | policy `WITH_ORDER` = never (orders are never deleted) | decision needed; revisions keep earlier text | None (a removed review is hidden, row kept) | PENDING LEGAL REVIEW |
| Support cases, messages, internal notes, events | never; `PENDING LEGAL / PRIVACY APPROVAL` (`config/support.php:164-166`); messages / notes / events cannot be updated | decision needed | None — erasure does not touch message text | PENDING LEGAL REVIEW |
| Support attachments | not implemented; `retention = PENDING POLICY` | decision needed before the feature | — | PENDING LEGAL REVIEW |

## 5. Security, audit, operations

| Data category | Current retention (code) | Proposed retention | Deletion method | Legal basis |
|---|---|---|---|---|
| Security events (ip, user agent, keyed identifier hash) | never; append-only trigger (every environment except the LOCAL development database, whose browser suites remove their own audit rows — BUG-323; `security:check` fails where the triggers are missing); deletion only with `fotg.audit_purge = 'on'` — **no purge job** | decision needed | None | PENDING LEGAL REVIEW |
| Audit events (actor ip, reasons) | never; same trigger | decision needed | None | PENDING LEGAL REVIEW |
| Application logs (redacted) | `single` channel: no rotation; `daily` / `structured`: 14 files (config) | decision needed; rotation is a deployment item | log rotation | PENDING LEGAL REVIEW |
| Failed queue jobs | 30 days (code constant) | keep current default | Auto (`queue:prune-failed`) | PENDING LEGAL REVIEW |
| Operational counters (Redis) | 48 h (config) | keep | Expiry | PENDING LEGAL REVIEW |
| Backups | none exist yet | decision needed (erased data persists in backups until they expire) | backup rotation | PENDING LEGAL REVIEW |

## 6. Analytics

| Data category | Current retention (code) | Proposed retention | Deletion method | Legal basis |
|---|---|---|---|---|
| Raw behavioural events (pseudonymous) | 180 days (config) | keep current default | Auto (`analytics:prune` daily); customer key removed by `--forget-customer` / Erasure | PENDING LEGAL REVIEW |
| Session facts | 400 days (config) | keep current default | Auto; key removed by forget / Erasure | PENDING LEGAL REVIEW |
| Order facts (pseudonymous key) | with the order (never) | decision needed | key removed by forget / Erasure (a rebuild can re-derive it — see analytics-privacy.md) | PENDING LEGAL REVIEW |
| Daily aggregates (counts) | not limited | keep (no person) | None | PENDING LEGAL REVIEW |
| Export files | 24 h (config); rows 30 days | keep current defaults | Auto | PENDING LEGAL REVIEW |
| Aggregation runs, quality results | 30 days (config, fixed) | keep | Auto | PENDING LEGAL REVIEW |

## 7. Client devices

| Data category | Current retention | Proposed retention | Deletion method | Legal basis |
|---|---|---|---|---|
| Web tokens | tab lifetime (sessionStorage) | keep | sign-out; tab close | PENDING LEGAL REVIEW |
| Web journey / checkout / payment / pickup / discovery data | until sign-out, 401 or expiry (Module 39) or tab close | keep | automatic on sign-out | PENDING LEGAL REVIEW |
| Web guest cart token, analytics opt-out | until the browser storage is cleared | keep | browser | PENDING LEGAL REVIEW |
| Android secure-storage keys other than the token | until app data is cleared or the app is uninstalled | clear on sign-out (open item) | uninstall / clear data | PENDING LEGAL REVIEW |

## 8. Decisions requested

1. Statutory retention for orders, payments, refunds, ledger and settlements (tax / accounting) — Legal.
2. Retention and purge job for `audit_events` and `security_events` — Security + Legal.
3. Support cases and messages, including redaction of personal data typed into messages — Legal / Privacy.
4. Waiting period between a deletion request and erasure, and whether erasure becomes automatic — Business + Legal.
5. Reviews: keep after erasure (anonymous display name) or remove — Business + Legal.
6. Backups: retention and how erasure is honoured in backups — Deployment + Legal.
7. Confirmation of every "keep current default" row above — Business.
