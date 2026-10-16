# FoodOnTheGo — Platform Admin guide

For FoodOnTheGo operations, support, finance and security staff. The Platform Admin is a web application; every action
is checked by the server against your role and market scope and recorded in the audit log.

> Status (Module 41): runs on the real backend locally; production is not launched. The **Customers** section and some
> market figures still show development data (CF-586).

## 1. Access and security

- Your account is created by a super administrator (`php artisan admin:create` for the very first one). You receive an
  invitation e-mail, set a password and **enrol MFA** (mandatory for administrators).
- Idle sessions are signed out. Several wrong passwords lock the account temporarily.
- **Step-up:** high-risk actions — approving or executing refunds, approving settlements, payouts, financial
  adjustments, changing an administrator's role / status / MFA, changing a restaurant's or organization's status,
  exports — need a recent sign-in. After 15 minutes the admin asks for your password (and authenticator code) once
  more and then completes the action. Cancelling leaves the action undone.
- **Admin Users** (super admin): invite, change role, suspend, reset MFA. **Audit Logs**: who did what, when, with the
  reason. **Security**: failed sign-ins, lockouts, suspicious pickup attempts, permission changes.
- Your **market scope** decides which markets you see; India is the default and only active market.

## 2. Markets and geography

- **Markets**: status, currency, locale, time zone, features, legal document status, ordering settings.
- Inside a market: **States** (36 reference regions), **Cities**, **Service areas** (where FoodOnTheGo serves),
  **Routes** (route corridors) and the market **configuration** (journey policy: corridor width, detour limit).
- Real launch coverage (surveyed service areas and corridors) must replace the development shapes before launch
  (CF-303).

## 3. Restaurants

- **Restaurants**: the list with status; details with locations, hours, pickup settings, staff and notes.
- Lifecycle: DRAFT → SUBMITTED → APPROVED, or REJECTED / SUSPENDED with a reason (public reason vs internal note).
  Suspending a restaurant removes it from discovery and stops new orders immediately.
- Creating an organization, its locations and its first owner is currently done through the API (CF-318).

## 4. Orders

- **Orders**: the authoritative order record — status, items, pickup, payment and refund state, full timeline.
- No arbitrary status edits. An administrator can **cancel** an order with a reason (a paid order then owes a refund).
- **Order recovery**: payments that were verified but have no order yet (fulfilment REVIEW_REQUIRED / FAILED) can be
  re-tried with a reason — the order is judged again from scratch; nothing is created by hand
  (runbook RB-13, `docs/reliability/runbooks.md`).

## 5. Payments

- **Payments**: attempts with provider references, method summary, verification and webhook timeline.
- **Reconcile** asks the provider again (with a reason). There is no button to mark a payment paid — VERIFIED comes only
  from the server's verification with the provider.

## 6. Refunds

- Request a **full** or **partial** refund on an order (or on a payment without an order) with a reason code.
- **Four-eyes**: another administrator with the approval permission approves; then **execute** sends it to the provider.
  Over-refunding is impossible (the remaining refundable amount is enforced).
- The refund becomes REFUNDED only when the provider confirms; the customer is told only then. There is no "mark
  refunded".

## 7. Reviews

- Moderation queue: reported reviews and responses; hide / restore with a reason. Restaurants cannot delete reviews.

## 8. Promotions, taxes and fees

- **Promotions**: fixed-amount or percentage codes with validity window, scope (market / restaurant), minimum order,
  limits and currency.
- **Configuration → taxes and fees**: rules per market (and location / pickup method). Only APPROVED rules apply in
  production; TEST rules are for development only. There is no delivery fee — a delivery fee rule is refused.

## 9. Finance

- **Settlements** section: the ledger (append-only), commission plans (only APPROVED plans apply in production),
  restaurant payables, settlements per period, payouts (live payouts disabled — recorded as manual), reconciliation
  differences and **adjustments** (four-eyes).
- No balance can be edited. A correction is always a new posting.
- Integrity: `finance:verify-ledger` runs hourly; problems appear in System Status.

## 10. Support (service desk)

- **Support**: case queues by status, priority and category; assign, reply to the customer or restaurant, **internal
  notes** (never visible outside the desk), escalate, resolve.
- Closing a case never changes an order, payment, refund or settlement — those need their own authorized action.

## 11. Analytics and exports

- **Analytics**: customer funnel, orders, restaurants, routes (aggregated — never an individual's route), payments,
  refunds, finance (reconciles to the ledger), support, reviews, notifications. Market time zone and currency.
- Exports (CSV) run in the background, are private, permission-checked, expire, and are safe against spreadsheet
  formula injection.

## 12. System status

- **System Status**: providers (routing, payments, push, mail, SMS), payments, orders, refunds, finance, realtime,
  notifications and the **reliability** view (database, Redis, queues, failed jobs, domain alerts). Runbooks:
  `docs/reliability/runbooks.md` (RB-01 … RB-15).

## 13. Never

Edit ledger rows · mark payments paid or refunds complete · flush Redis or the database · switch a provider to live
mode as a workaround · share a customer's pickup code · export data outside an approved purpose.
