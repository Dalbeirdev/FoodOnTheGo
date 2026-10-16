# Launch feature matrix

Status: Module 41 — 2026-10-16 — local only; **production = NO**.

One row per launch feature. **Web / Android / Restaurant / Admin / Backend** = where the feature lives and its state there
(`✓` built and verified locally on the real backend, `P` partial, `—` not part of that surface, `✗` not built).
**Provider** = the external service it depends on and that service's state. **Status** uses the supported set.
**Production ready?**:
- `YES*` — technically ready on the evidence of Modules 20–41 (automated tests + browser / Android-preview suites on the
  real local backend); still needs the owner's acceptance (CF-573) and, for Android, the device check (CF-574).
- `NO` — a blocker of [production-blocker-register.md](production-blocker-register.md) applies (named in Notes).

Evidence for every `✓`: the module's completion report (`docs/local-review/reports/module-NN-completion-report.md`) and
the Module 41 regression (`docs/local-review/test-results/m41-*`).

## 1. Customer features

| Feature | Web | Android | Restaurant | Admin | Backend | Provider | Status | Production ready? | Notes |
|---|---|---|---|---|---|---|---|---|---|
| Home, How it works, About, For restaurants | ✓ | ✓ | — | — | — | — | COMPLETE | NO | About shows illustrative figures (CF-022); hero / how-it-works illustrations use fixture names and ratings; placeholder imagery (CF-001, CF-580) |
| Sign-in: phone + OTP, account setup, logout, logout all | ✓ | ✓ | — | — | ✓ | SMS / WhatsApp: **not configured** | VERIFIED (local) | NO | No real OTP delivery = nobody can sign in in production (CF-294, CF-297) |
| Market (India, INR, en-IN, metric) | ✓ | ✓ | — | ✓ | ✓ | — | COMPLETE | YES* | Global readiness by configuration; foreign fixtures never in the India UX |
| Plan a journey (places, route, corridor) | ✓ | ✓ | — | ✓ | ✓ | Google Places / Routes: **not configured** (development provider) | VERIFIED (local) | NO | Live routing provider required (CF-352); "synthetic roads" note shows until then |
| Restaurants along the route (discovery, detour, open at arrival) | ✓ | ✓ | — | — | ✓ (PostGIS) | Google Routes | VERIFIED (local) | NO | Same provider blocker; real service areas / corridors (CF-303) |
| Restaurant page (Design A) + menu tab | ✓ | ✓ | — | — | ✓ | — | VERIFIED | YES* | Canonical `/restaurants/:slug`; `/restaurant/*` redirects only |
| Item page, variants, modifiers, dietary, sold out | ✓ | ✓ | ✓ (menu) | — | ✓ | — | VERIFIED | YES* | Canonical `/restaurants/:slug/item/:itemSlug` |
| Guest browsing + guest cart | ✓ | ✓ | — | — | ✓ | — | VERIFIED | YES* | Guest token; one restaurant per cart |
| Cart (server prices, versions, one restaurant, sign-in claim / merge) | ✓ | ✓ | — | ✓ (cart view) | ✓ | — | VERIFIED | YES* | Different-restaurant conflict asks; nothing lost silently |
| Pickup time (ASAP, scheduled, hours, special hours, capacity, journey recommendation) | ✓ | ✓ | ✓ (settings) | ✓ | ✓ | — | VERIFIED | YES* | Android time zones: development offset table (correct for India) — CF-078 |
| Checkout (validation, promotions, legal acceptance, slot hold, expiry) | ✓ | ✓ | — | ✓ (rules) | ✓ | — | VERIFIED | NO | Legal documents are drafts (CF-394); no approved India tax rules (CF-392) |
| Payment | ✓ | ✓ | — | ✓ | ✓ | Razorpay: **implemented, never exercised**; LIVE disabled in code | PARTIAL | NO | Razorpay TEST mode not configured (CF-404 … 407); live mode (CF-413) |
| Zero-total order (no payment) | P | P | — | — | ✓ | — | PARTIAL | NO | Client flow from a zero-total confirmation not verified end to end (CF-438) |
| Order confirmation + pickup QR / manual code | ✓ | ✓ | ✓ (scanner) | — | ✓ | — | VERIFIED | YES* | Single-use, high-entropy, location-bound, READY required |
| Live tracking (realtime + polling fallback) | ✓ | ✓ | ✓ | ✓ | ✓ (Reverb) | — | VERIFIED (local) | NO | Android Origin vs Reverb allowed origins (CF-576); Linux Reverb topology (CF-567) |
| My orders, order details | ✓ | ✓ | — | ✓ | ✓ | — | VERIFIED | YES* | |
| Reorder | P | P | — | — | ✗ | — | PARTIAL | NO | Client-side reorder service in API mode (CF-187, CF-371, CF-586) |
| Receipt (not a tax invoice) | P | P | — | — | ✗ | — | PARTIAL | NO | Built in the browser; download / e-mail not available (CF-190, CF-586) |
| Reviews (completed orders only, one per order) | ✓ | ✓ | ✓ (replies) | ✓ (moderation) | ✓ | — | VERIFIED | YES* | Review display / edit policy decisions (CF-203, CF-206) |
| Refund status (customer view) | ✓ | ✓ | — | ✓ | ✓ | Razorpay refunds: not verified | VERIFIED (development provider) | NO | Razorpay test refunds (CF-480); customer cancellation disabled pending policy (CF-477) |
| Notifications: inbox, preferences | ✓ | ✓ | — | ✓ | ✓ | — | VERIFIED | YES* | |
| Push notifications | — | P | — | ✓ (health) | ✓ | FCM: **not configured** | PARTIAL | NO | Firebase project + credentials (CF-450); device check (CF-574) |
| E-mail notifications | — | — | — | ✓ | ✓ | Mail: **not configured** | PARTIAL | NO | Mail provider (CF-315, CF-451) |
| Profile (name, e-mail, locale, photo), phone change with OTP | ✓ | ✓ | — | — | ✓ | SMS | VERIFIED (local) | NO | OTP provider; photo storage (CF-560) |
| Favorites | ✓ | ✓ | — | — | ✓ | — | VERIFIED | YES* | |
| Saved places (journey shortcuts, not delivery addresses) | ✓ | ✓ | — | — | ✓ | Google Places (search) | VERIFIED | YES* | Place search uses the places provider; pasted coordinates work without it |
| Payment method references (no card data) | ✓ | ✓ | — | — | ✓ | Razorpay tokens: not built | PARTIAL | NO | Adding a method needs the provider flow (CF-418) |
| Help & support cases (create, reply, follow, resolve) | ✓ | ✓ | ✓ | ✓ | ✓ | — | VERIFIED | NO | Official support contact (CF-023 / CF-519); attachments disabled |
| Account deletion request / erasure | ✓ | ✓ | — | ✓ | ✓ | — | PARTIAL | NO | Erasure policy approval (CF-342), data export (CF-344 / CF-552) |
| Legal pages (terms, privacy, refund / cancellation, cookie) | ✓ (DRAFT) | ✓ (DRAFT) | — | — | ✓ (versions) | — | BLOCKED | NO | Final approved texts (CF-002, CF-394) |
| Get the app | ✓ | — | — | — | — | Play Store: not published | PENDING | NO | Distribution plan / Play listing (CF-016) |
| iOS | — | — | — | — | — | — | DEFERRED | — | Not a launch blocker |

## 2. Restaurant features (Restaurant Dashboard, web)

| Feature | Restaurant | Backend | Status | Production ready? | Notes |
|---|---|---|---|---|---|
| Sign-in, MFA (optional), password reset, invitation acceptance | ✓ | ✓ | VERIFIED | NO | Invitation / reset e-mails need the mail provider (CF-315); restaurant MFA decision |
| Organization / location scope, location-limited staff | ✓ | ✓ | VERIFIED | YES* | Tenant IDOR matrix (Module 39) |
| Overview | ✓ | ✓ | VERIFIED | YES* | |
| Orders: queue, accept, reject (refund owed), preparing, ready | ✓ | ✓ | VERIFIED | YES* | Multi-staff concurrency idempotent (Module 40) |
| Pickup verification (QR scanner + manual code) | ✓ | ✓ | VERIFIED | YES* | Camera scanner on a real device not verified (CF-442) |
| Menu management (categories, items, options, availability, images) | ✓ | ✓ | VERIFIED | P | Category / publish switches in the UI (CF-329); image upload for logo / cover / gallery (CF-319) |
| Profile, hours, special hours, pause, accepting orders | ✓ | ✓ | VERIFIED | YES* | Customer behaviour follows (Module 23 / 28 suites) |
| Pickup settings (prep time, lead time, capacity, methods) | ✓ | ✓ | VERIFIED | YES* | |
| Staff (invite, role, revoke) | ✓ | ✓ | VERIFIED | NO | Invitation e-mail (CF-315) |
| Reviews (view, respond, report) | ✓ | ✓ | VERIFIED | YES* | A negative review cannot be deleted by the restaurant |
| Analytics | ✓ | ✓ | VERIFIED | YES* | Read layer only |
| Earnings, settlements, payout status | ✓ | ✓ | VERIFIED (TEST plans) | NO | Commission terms, settlement policy, live payouts disabled (CF-492, CF-493, CF-498) |
| Support cases | ✓ | ✓ | VERIFIED | YES* | |
| Notifications, Settings sections | P | ✗ | PARTIAL | NO | Development data in API mode (CF-586) |

## 3. Admin features (Platform Admin, web)

| Feature | Admin | Backend | Status | Production ready? | Notes |
|---|---|---|---|---|---|
| Sign-in, mandatory MFA, idle timeout, administrator accounts (RBAC), step-up for high-risk actions (Module 41) | ✓ | ✓ | VERIFIED | NO | Invitations need the mail provider (CF-315); step-up windows PENDING BUSINESS APPROVAL |
| Markets, geography, service areas, route corridors | ✓ | ✓ | VERIFIED | P | Drawing on a real map needs the map provider (CF-301); real coverage data (CF-303) |
| Restaurants: approval, suspension, details | ✓ | ✓ | VERIFIED | NO | Creating organizations / locations / first owner is API-only (CF-318) |
| Orders (authoritative truth, recovery, cancel with reason) | ✓ | ✓ | VERIFIED | YES* | No arbitrary status edit |
| Payments (no status edit; reconcile with reason) | ✓ | ✓ | VERIFIED | NO | Razorpay (CF-404) |
| Refunds (request, four-eyes approve, execute, reconcile) | ✓ | ✓ | VERIFIED | NO | Razorpay refunds (CF-480); refund policy (CF-476) |
| Reviews moderation | ✓ | ✓ | VERIFIED | YES* | |
| Promotions | ✓ | ✓ | VERIFIED | YES* | Types beyond fixed / percentage not approved (CF-401) |
| Tax and fee configuration | ✓ | ✓ | VERIFIED | NO | No approved India tax rules (CF-392); no delivery fee possible |
| Finance: ledger, commission plans, settlements, payouts, reconciliation, adjustments | ✓ | ✓ | VERIFIED (TEST) | NO | Commission / settlement / tax policies (CF-492 … 497); live payouts disabled |
| Support service desk (queues, internal notes, SLA) | ✓ | ✓ | VERIFIED | YES* | Internal notes isolated (Module 37 / 39) |
| Analytics + secure exports | ✓ | ✓ | VERIFIED | NO | Consent screen (CF-528); export storage on one host (CF-560) |
| System health: providers, payments, orders, realtime, notifications, reliability | ✓ | ✓ | VERIFIED | YES* | No external alerting yet (CF-566) |
| Audit log, security events, pickup attempts | ✓ | ✓ | VERIFIED | YES* | Append-only triggers |
| Customers | P | ✗ | PARTIAL | NO | Development data in API mode (CF-521, CF-586) |

## 4. Provider matrix

| Provider | Used for | Development / test (local) | Production readiness | Blocker |
|---|---|---|---|---|
| Google Places / Routes | journey planning, discovery, saved-place search | DEVELOPMENT providers (deterministic); Google adapters implemented, never called | NOT READY: no account, no keys, no restrictions | CF-352, CF-353 |
| Razorpay | payments, refunds, settlement reports | DEVELOPMENT provider; Razorpay client implemented (test / live key-mode check), never exercised | NOT READY: TEST mode not configured; LIVE disabled in code by design | CF-404 … 407, CF-413, CF-480, CF-499 |
| Firebase Cloud Messaging | push (Android) | development push provider; FCM HTTP v1 adapter implemented | NOT READY: no Firebase project / credentials / google-services | CF-450, CF-574 |
| SMS (OTP) — MSG91 / 2Factor / Twilio | sign-in codes | log driver (local test code) | NOT READY: no provider, no DLT registration — **nobody can sign in** | CF-294 |
| WhatsApp / Truecaller | optional sign-in channels | not configured | OPTIONAL (not launch scope unless approved) | CF-297, CF-298 |
| E-mail | staff invitations, password reset, notification e-mail | log mailer | NOT READY: no provider / verified sender | CF-315, CF-451 |
| Object storage | menu / restaurant images, avatars, exports | local disks | NOT READY for more than one app host (S3 adapter not installed) | CF-560 |
| Payouts | restaurant payouts | fake provider; live payouts disabled | DISABLED by decision (manual settlement until approved) | CF-498 |

## 5. Platforms

| Platform | Status | Notes |
|---|---|---|
| Customer Web | VERIFIED (local) | Release gate added (Module 41); production build with production values not made (no production values exist) |
| Customer Android | VERIFIED (preview + unit) — **PENDING USER DEVICE VERIFICATION** | No upload key yet — a production release refuses to build without it (CF-548); no Firebase configuration |
| Restaurant Dashboard | VERIFIED (local) | Web only |
| Platform Admin | VERIFIED (local) | Web only |
| iOS | DEFERRED | Not a launch blocker |
