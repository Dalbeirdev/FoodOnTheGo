# Personal data inventory

Module 39 — 2026-10-14 — local only; production = NO

Sources: M39 `_privacy_columns.txt` (catalog of the local database, 2026-10-13), M39 `audit_privacy.md` (sections 1–4, 6), `backend/database/migrations/*.php` (in particular `2026_10_19_100000_module39_security.php`), `backend/app/Models/{Customer,CustomerDevice,CustomerPaymentMethod,IdempotencyKey,OrderPickupCredential,StaffAccount}.php`, `backend/app/Http/Resources/Order/OrderPresenter.php:127-147, 195, 216, 515-533`, `backend/app/Services/Auth/SecurityEventRecorder.php:51`, `backend/app/Services/Analytics/Pseudonymizer.php`, `backend/config/security.php`, `customer-web/src/{api/client.ts,auth/api/ApiAuthRepository.ts,auth/AuthContext.tsx,auth/privateContext.ts,cart/api/apiCart.ts,cart/ServerCartProvider.tsx,discovery/scope.ts,journey/api/apiJourney.ts,journey/JourneyContext.tsx,analytics/index.ts}`, `mobile/lib/**` (storage keys), `mobile/android/app/src/main/AndroidManifest.xml`.

> **Technical mapping, not legal advice. PENDING LEGAL REVIEW.** It lists where personal data is held, why, and who
> can read it through the system. It does not state a legal basis, and it does not claim DPDP or any other compliance.

## Conventions

**Classification** follows the Module 39 model (specification §12–16): PUBLIC, INTERNAL, CONFIDENTIAL,
HIGHLY_SENSITIVE (HS). The specification names phone, e-mail, support messages and order history CONFIDENTIAL, and
provider / webhook / admin-authentication secrets, payout data, the pickup secret and security investigation details
HIGHLY_SENSITIVE. Items the specification does not name (precise location, device tokens) are classified by analogy
— PENDING review.

**Storage:** P = plaintext column, E = Laravel `encrypted` cast (key: `APP_KEY`), H = hash, K = keyed hash / HMAC,
G = PostGIS geography / geometry.

**Who can see** means through the API or the applications. Anyone with database, backup or `APP_KEY` access can read
everything; that is controlled outside the application (deployment).

No card number, CVV, expiry-with-number, UPI PIN, net-banking password, full bank account number or IFSC is stored
anywhere; request guards refuse such fields (M39 `audit_privacy.md` §2).

## 1. Customers

| Table / field | Purpose | Source | Who can see | Class | Storage |
|---|---|---|---|---|---|
| `customers.phone_e164` | sign-in identity, contact for orders | customer (OTP-verified) | the customer; admin only **masked** (orders, payments, support); restaurant via the order snapshot (below) | CONFIDENTIAL | P |
| `customers.name`, `email` | profile, receipts, contact | customer | the customer; admin via order / support views | CONFIDENTIAL | P |
| `customers.date_of_birth`, `gender`, `favorite_cuisines`, `vegetarian_only`, `search_radius_km`, `preferred_locale` | optional profile and preferences | customer | the customer | CONFIDENTIAL | P |
| `customers.avatar_path` + file on the avatar disk | profile photo (re-encoded, no EXIF) | customer upload | the customer (unguessable URL) | CONFIDENTIAL | P |
| `customers.terms_accepted_at`, `promotions_consented_at`, `promotions_withdrawn_at` | consent records | customer action | the customer | CONFIDENTIAL | P |
| `customers.deletion_requested_at`, `deletion_reason` (free text, ≤ 300) | deletion request | customer | the customer; operators | CONFIDENTIAL | P |
| `customers.last_login_at`, `status` | account state | system | the customer; admin | INTERNAL | P |
| `customer_saved_locations` (address lines, locality, city, postal code, formatted address, point) | saved places | customer | the customer | CONFIDENTIAL (precise location) | P / G |
| `customer_recent_locations` (label, formatted address, place id, point) | recent places chosen by the customer | system, from journeys | the customer | CONFIDENTIAL | P / G |
| `customer_favorite_locations`, `customer_notification_preferences` | favourites, notification matrix | customer | the customer | INTERNAL | P |
| `customer_payment_methods` (provider customer / method references; brand, last 4, expiry month / year, masked UPI handle, label) | saved payment references | provider via backend | the customer (summary only, never references) | CONFIDENTIAL; references HS | references E; display data P |
| `customer_phone_changes.new_phone_e164` | phone change in progress | customer | the customer (flow) | CONFIDENTIAL | P |
| `otp_challenges.phone_e164`, `code_hash` | one-time codes | anyone requesting a code | nobody (internal) | CONFIDENTIAL | phone P, code K (HMAC) |
| `customer_devices` (`push_token`, `push_token_hash`, `installation_id`, app version, locale, time zone, permission state, `access_token_id`) | push delivery | the app | the customer (device list); token never returned | push token HS | token E + hidden; hash H |
| `personal_access_tokens` (token hash, ip, user agent, last use) | sessions of all principals | system | the owner (session list) | CONFIDENTIAL | token H; ip / UA P |
| `journeys` (origin, destination with coordinates and address / label, route geometry, polyline, legs) | one journey search | customer or guest | owner only (customer or guest token); restaurant sees only an arrival estimate | CONFIDENTIAL (precise trip) | P / G |
| `carts` (`journey_summary` labels, `guest_token_hash`), `cart_items.special_instructions` | cart | customer / guest | owner | CONFIDENTIAL | P; token H |
| `checkout_sessions` (snapshot, order note), `checkout_legal_acceptances` | checkout and accepted documents | customer | the customer | CONFIDENTIAL | P |
| `notifications` (title, body with restaurant, order number, refund amount; data) | in-app inbox | system | the customer | CONFIDENTIAL | P |

## 2. Orders, payments, refunds

| Table / field | Purpose | Source | Who can see | Class | Storage |
|---|---|---|---|---|---|
| `orders.snapshot.customer` (public id, name, **full phone**, e-mail) | contact at order time | copied at order creation | customer (own); **restaurant: full name + phone while the order is open and for `RESTAURANT_CONTACT_HOURS` (default 24 h) after it ended, then a shortened name and masked phone with `contact_masked: true`** (Module 39, `OrderPresenter.php:515-533`); admin: name + masked phone | CONFIDENTIAL | P (jsonb) |
| `orders.customer_note`, `order_items.special_instructions` | instructions | customer | customer, restaurant, admin | CONFIDENTIAL | P |
| `orders.snapshot.journey` | journey id, arrival estimate, detour — never origin, destination or route | system | customer, restaurant (arrival only), admin | INTERNAL | P |
| `order_pickup_credentials` (`token_secret`, `display_code`, hashes) | pickup proof | system | the customer while the order is active; restaurant verifies, never reads | HIGHLY_SENSITIVE | secret and code E; token hash H; display-code hash K (HMAC with `APP_KEY`, Module 39) |
| `order_pickup_verification_attempts` | who tried which code where | system | admin; restaurant summary | INTERNAL | P |
| `idempotency_keys.response_body` | replay of a retried request (may hold a pickup code, QR payload or payer contact) | system | same actor on replay only | HIGHLY_SENSITIVE | **E** (`encrypted:array`, Module 39; existing rows encrypted by the migration); pruned at expiry (24 h) |
| `payment_attempts` (customer public id, provider references, method summary: network, card type, last 4, masked VPA, bank code, wallet) | payment record | provider via backend | customer (own); admin with payment permissions (phone masked) | CONFIDENTIAL | P |
| `payment_webhook_events` (references, payload hash, safe summary) | provider events | provider | admin | INTERNAL | P; payload never stored |
| `refunds`, `refund_events`, `refund_attempts` (reason / approval / closing notes) | refunds | customer, admin, provider | customer (own, own words); admin with refund permissions | CONFIDENTIAL | P |
| `payment_disputes` | provider disputes | provider | admin (finance) | CONFIDENTIAL | P |

## 3. Reviews and support

| Table / field | Purpose | Source | Who can see | Class | Storage |
|---|---|---|---|---|---|
| `restaurant_reviews` (`author_display_name` = first name + initial, `title`, `comment`, ratings) | reviews | customer | public once published; restaurant; admin moderation | PUBLIC once published, else CONFIDENTIAL | P |
| `review_revisions.body` | every earlier version | system | admin | CONFIDENTIAL | P |
| `review_reports` | reports of a review | customer / restaurant | admin | CONFIDENTIAL | P |
| `restaurant_review_responses` (`responder_name`, text) | restaurant reply | restaurant staff | public | PUBLIC | P |
| `support_cases` (subject, summary, requester ids), `support_case_messages.body` | support conversation | customer / restaurant / admin | requester (own case); restaurant (scoped); admin by market and restricted class; phone masked | CONFIDENTIAL (free text may contain anything) | P |
| `support_case_internal_notes.body` | internal notes | admin | admin only (`admin.support.notes`) — never a customer or restaurant API | HIGHLY_SENSITIVE (security investigation, as in `docs/security/data-classification.md`) | P |
| `support_case_attachments` | schema only, feature off | — | — | — | — |

## 4. Staff (restaurant and administrators)

| Table / field | Purpose | Source | Who can see | Class | Storage |
|---|---|---|---|---|---|
| `admin_users` / `restaurant_users`: `name`, `email`, `last_login_at` | staff accounts | invitation | admin with `admin.users.view`; restaurant staff list shows members' e-mail | CONFIDENTIAL | P |
| `password` | authentication | staff | nobody | HIGHLY_SENSITIVE | `hashed` |
| `mfa_secret`, `mfa_recovery_codes` | second factor | staff | nobody | HIGHLY_SENSITIVE | E (`StaffAccount.php`) |
| `restaurant_memberships.invited_name`; invitations / reset tokens | onboarding | admin / owner | admin, organization staff managers | CONFIDENTIAL; tokens H | P / H |
| `restaurant_payout_profiles` (provider account reference — hidden, not encrypted; destination mask) | payouts | admin | admin finance; restaurant masked | HIGHLY_SENSITIVE (payout data) | P (hidden) |

## 5. Security, audit, logs

| Table / place | Personal data | Who can see | Class | Storage |
|---|---|---|---|---|
| `security_events` | principal id, `identifier_hash` of an unknown caller's phone / e-mail (HMAC keyed with `APP_KEY` since Module 39, `SecurityEventRecorder.php:51`), ip, user agent, redacted metadata | admin with platform-wide `admin.security.view` (ip and user agent shown) | HIGHLY_SENSITIVE (security investigation) | P / K; append-only |
| `audit_events` | actor and target public ids, actor ip, free-text reason; profile changes recorded as "changed" (not values); phone masked | admin with `admin.audit.view`, per market | CONFIDENTIAL | P; append-only |
| Application logs | public ids, request ids; redacted (tokens, SQL values, phone → `+********1234`, e-mail → `***@domain`) | server operators | CONFIDENTIAL | file |
| `failed_jobs` | job payload (ids), exception text | operators | INTERNAL | P; 30 days |
| Redis cache | journey route / places answers (keyed by hashes), re-authentication window, MFA challenge, lockout counters | nobody via API | CONFIDENTIAL | TTL 60–300 s for journey data |

## 6. Analytics (Module 38)

| Table | Personal data | Who can see | Class |
|---|---|---|---|
| `analytics_events`, `analytics_session_facts`, `analytics_order_facts` | `customer_key` / `journey_key` = keyed SHA-256 pseudonyms (first 32 hex), random session id, typed properties only | aggregates only (admin by market, restaurant own locations) | INTERNAL (pseudonymous) |
| `analytics_daily_*` | counts; small groups (< 5) hidden in reports | as above | INTERNAL |
| `report_exports` + CSV files | filters; files hold aggregates and order / case numbers, no names, phones, e-mails or addresses | requester only, 24 h | CONFIDENTIAL |

Details: `docs/privacy/analytics-privacy.md`.

## 7. Browser storage (Customer Web, Restaurant Dashboard, Platform Admin — one origin today)

API mode only; the `fotg.mock.*` keys belong to the development mock and hold fixture data.

| Key | Store | Content | Cleared on sign-out / 401 |
|---|---|---|---|
| `fotg.auth.token.customer`, `.restaurant`, `.admin` | sessionStorage | bearer token | yes (token removed) |
| `fotg.auth.challenge` | sessionStorage | phone number + challenge id during sign-in | removed after verification |
| `fotg.auth.token.customer.pending`, `fotg.auth.pending` | sessionStorage | token before profile completion; pending login state | on completion |
| `fotg.auth.otp.delivery` | sessionStorage | whether the backend delivered the code by the development channel | — |
| `fotg.journey.current`, `.context`, `.tokens`, `.recent-places`, … | sessionStorage | journey with origin / destination (coordinates, labels), journey tokens, recent places | **yes** (Module 39, `privateContext.ts`) |
| `fotg.checkout.*`, `fotg.payment.*` | session / local | checkout request, accepted terms, payment attempt references | **yes** |
| `fotg.pickup.selection` | session | chosen pickup time | **yes** |
| `fotg.discovery.scope` | localStorage | manually chosen location (may be a saved address) | **yes** |
| `fotg.cart.token` | localStorage | guest cart token (anonymous cart) | no — by design |
| `fotg.cart.note` | sessionStorage | note typed for the restaurant | yes (added in the Module 39 follow-up; it belongs to the checkout) |
| `fotg.analytics.session`, `.once` | sessionStorage | random analytics session id, once-per-session markers | no — not personal |
| `fotg.analytics.consent` | localStorage | `off` when the visitor opted out | no |
| `fotg.*.mode`, `fotg.pref.units` | local | development switches (honoured only in development builds), units | no |

## 8. Android storage

All keys are in `flutter_secure_storage` (Keystore-backed); Android backup and device transfer are disabled.

| Key | Content | Removed on sign-out |
|---|---|---|
| `fotg.auth.token.customer` | session token | yes |
| `fotg.installation.id` | random installation id (not a hardware id) | no — identifies the installation for push |
| `fotg.cart.token` | guest cart token | no |
| `fotg.journey.tokens` | journey tokens | yes (Module 39 follow-up) |
| `fotg.checkout.request`, `fotg.checkout.terms` | checkout request, accepted terms | yes (Module 39 follow-up) |
| `fotg.payment.attempt`, `fotg.payment.current` | payment attempt references | yes (Module 39 follow-up) |
| `fotg.discovery.scope` | chosen location | yes (Module 39 follow-up) |
| `fotg.analytics.session`, `.buffer`, `.optout` | analytics session id, unsent events, opt-out flag | no |
| `fotg.review.draft.<order id>` | review text being written (`review_screen.dart:44`) | yes (Module 39 follow-up) |
| `fotg.orders.v1`, `fotg.pickup_verifications.v1`, `fotg.reviews.v1`, `fotg.cart.v1`, `fotg.mock.*` | mock-mode data only | — |

The pre-profile token and the OTP challenge are kept in memory only. Sign-out, a 401 and an expired session clear the
checkout / payment / journey / discovery / review-draft keys and the pickup selection (`lib/auth/private_context.dart`;
`docs/security/mobile-security.md` M-6, done).

## 9. Third parties receiving personal data (when configured)

| Party | Data | Status |
|---|---|---|
| Razorpay | payer name, phone, e-mail as checkout prefill; payment data entered on Razorpay's own screens | implemented, NOT configured |
| Firebase Cloud Messaging | push token, notification title / body (order number, restaurant, refund amount) | implemented, NOT configured |
| SMS provider | phone number, one-time code | NOT configured |
| E-mail provider | e-mail address, staff reset / invitation links, notices | NOT configured (log mailer locally) |
| Places / routing provider (Google) | search text, coordinates of a journey | NOT configured (development providers locally) |
| Google Fonts (web) | visitor IP address on font download | active in the web build |

Each needs a processor agreement and a privacy-notice entry — PENDING LEGAL REVIEW.
