# Checkout: final validation, server-authoritative pricing, promotions, tax and fee rules, the pickup-time hold and the payment preparation (Module 29)

Status: **built and verified locally** (LOCAL ONLY — nothing here is deployed, and nothing here charges money).

This document describes what exists in the code. Where something is not built, it says so.

- No payment provider is integrated. `Payment Provider Status = NOT_INTEGRATED`. No payment is created, captured,
  verified or refunded, and there is no webhook. That is Module 30.
- No order is created. A checkout is **not** an order. That is Module 31.
- **No tax rule and no fee rule is seeded for any market.** India's tax configuration is `NOT_CONFIGURED` and stays
  a production blocker until approved rules are supplied. The code contains no rate and no tax name of any country.
- The legal texts a customer accepts are **drafts** (`DRAFT_PENDING_APPROVAL`). Final business / legal approval is a
  production blocker.
- Cash at pickup is **NOT APPROVED**: never offered, never accepted.

## The rule

The backend decides what is paid. A browser or a phone sends a promotion code, the legal documents it showed, how the
customer intends to pay and an order note — and nothing else. It never sends, and the backend never reads, a
subtotal, a discount, a tax, a fee, a total, a currency, a promotion value or a payment amount. A request that
carries such a field (or an owner, a status, a time, a card number, a CVV, a UPI PIN, a bank password) is **refused
with 422** before it reaches the domain.

A checkout is a short-lived, server-priced quote for one cart. It is never repaired: when anything it was priced
from changes it becomes `STALE` and a new one is created, so the customer always sees the new total before paying.

## Vocabulary

| Word | Meaning |
|---|---|
| Checkout (session) | One priced, validated snapshot of a signed-in customer's cart + pickup selection, with an expiry |
| DRAFT | Validated and priced; the customer has not confirmed it |
| READY | Confirmed: terms accepted, payment context named, price acknowledged — the pickup time is **held** |
| STALE | Something it was priced from changed. Closed; what it held is given back |
| EXPIRED | Its time ran out (server clock). Closed; what it held is given back |
| PAYMENT_PENDING | A payment has been started for it (set by Module 30 through `beginPayment`) |
| CONVERTED | An order exists (Module 31) |
| CANCELLED | Ended by the customer, or replaced by a newer checkout of the same cart |
| Pricing fingerprint | sha256 over everything a price consists of; any change gives another fingerprint |
| Hold | One capacity unit in a pickup slot, bound to a READY checkout, with the checkout's expiry |
| TEST rule | A tax or fee rule that is a development configuration; never applied in production |

## Architecture

```
CheckoutController (customer, auth:customer)          PromotionController / FinancialRuleController (admin)
        │                                                          │
        ▼                                                          ▼
  CheckoutService ─────────────────────────────────────── FinancialAdminService ── AuditRecorder
   │  open / show / setPromotion / confirm / cancel / beginPayment / prune
   ├─ CheckoutValidationService ── CartValidationService (Module 27) + PickupSelectionService (Module 28)
   ├─ CheckoutPricingService
   │     ├─ PromotionEligibilityService   (one promotion; status, window, scope, currency, minimum, limits)
   │     ├─ FeeCalculationService         (configured fee rules only)
   │     └─ TaxCalculationService         (configured tax rules only)   ── App\Support\Rounding
   ├─ PickupSlotHoldService ── PickupCapacityRepository::reserve (advisory lock, Module 28)
   ├─ LegalDocuments        (config/legal.php + market configuration)
   └─ PaymentOptions / PaymentPreparation  (context and preparation only — no provider)

CheckoutEvent ─► RecordCheckoutEvent (counters + one log line)      CartEvent / PickupSelectionEvent ─► InvalidateCheckoutsOfChangedCart
```

There is one cart domain and one pickup domain; checkout **uses** them (it does not duplicate their validation) and
adds what only checkout knows: the complete price, the legal acceptance, the hold and the payment preparation.

## Life cycle

```
            open                      confirm                    beginPayment (M30)        (M31)
 (cart) ───────────► DRAFT ─────────────────────────► READY ───────────────────► PAYMENT_PENDING ───► CONVERTED
                       │  ▲  promotion applied/removed   │
                       │  └──────────────────────────────┘ (back to DRAFT: the total changed, hold released)
                       ├──► STALE      cart / pickup / price / promotion / tax or fee rule / restaurant / legal version changed
                       ├──► EXPIRED    server clock ≥ expires_at
                       └──► CANCELLED  by the customer, or replaced by a newer checkout of the same cart
```

- A cart has **at most one live checkout** (DRAFT / READY / PAYMENT_PENDING) — a partial unique index. A second
  device, or a repeated request, gets the same one.
- Lifetimes (`config/checkout.php`): DRAFT 15 minutes, READY 10 minutes. A repeated confirmation prolongs nothing.
- Expiry uses the **server clock**. The answer carries `expires_at` and `server_time`; clients count down from the
  difference and never from the device's date.
- Every read judges the checkout again (`show`): expired → EXPIRED; cart version, pickup selection, a line price,
  the promotion, a tax or fee rule, the restaurant's state, or the version of a required legal document differs →
  STALE with the reason. A change of the cart or of the pickup selection also stales it **at once** through a
  listener, so its hold is released without waiting for somebody to look.
- A request that finds the checkout stale or expired is refused (409 `checkout_stale`, 410 `checkout_expired`) — and
  that judgement is **committed**: the refusal is returned from the transaction, not thrown inside it.

## Final validation

`CheckoutValidationService` runs before every `open` and inside every read / confirm:

- cart exists, is ACTIVE, not expired, not empty, belongs to the customer; one restaurant; one currency;
- every line again: item and options exist and are orderable **now** (Module 27's inspection);
- restaurant APPROVED, visible, accepting orders; its market active;
- pickup selection exists, belongs to this cart version, and is valid **now** for the restaurant's hours, lead
  time, horizon and capacity (Module 28's overview — the cart's own hold is not counted against it).

Blocking issues refuse the checkout with the first issue's code and `details.issues[]`, each with a `recover`
hint: `cart`, `pickup`, `checkout`, `refresh` or `payment`.

**A changed price is never kept.** When the only thing wrong with the cart is that a menu price changed, `open`
brings the cart up to date, prices the checkout from the current menu and says what moved in `changes`
(`PRICE_CHANGED` with the previous and the new unit price).

## Financial calculation sequence

Integers only: every amount is in minor units of the cart's currency, every rate in basis points. No float is used
anywhere, and no money column is a float or a decimal. Rounding is **half up at the minor unit**
(`App\Support\Rounding`); an inclusive tax is split over its components by largest remainder so the parts add up.

1. **Merchandise subtotal** = Σ line (current unit price × quantity). Current menu prices, never the cart's
   display snapshot.
2. **Promotion discount** — at most one promotion. `FIXED_AMOUNT`: the amount, capped at the subtotal.
   `PERCENTAGE`: subtotal × basis points, capped by `maximum_discount_minor` when set, then at the subtotal.
   The minimum spend is measured on the subtotal **before** the discount.
3. **Fees** — one line per ACTIVE fee rule in force for the location / market / pickup method. `FIXED`, or
   `PERCENTAGE` of `SUBTOTAL` or of `DISCOUNTED_SUBTOTAL`, with optional minimum and maximum. A promotion reduces a
   fee only when the fee is configured `DISCOUNTED_SUBTOTAL`. A fee of zero is not a line.
4. **Taxes** — one ACTIVE rule per target (`MERCHANDISE`, `FEES`), a location-specific rule before a market rule,
   the latest `effective_from`. `AFTER_DISCOUNT` (default) or `BEFORE_DISCOUNT` is explicit per rule. `EXCLUSIVE`
   tax is added; `INCLUSIVE` tax is reported (`tax_included_total`) and not added. The `FEES` rule applies to
   taxable fees only.
5. **Total** = subtotal − discount + fees + added tax, never below zero. The database enforces the equation
   (`checkout_sessions_total_check`).
6. `payment_required = total > 0`. A total of zero needs no payment method and no payment is ever created for it.

No rule ⇒ no line. The answer says so: `pricing.configuration = { tax: NOT_CONFIGURED | TEST | APPROVED, fees: … }`.

## Promotions

- Types: `FIXED_AMOUNT`, `PERCENTAGE`. Nothing else exists. **Stacking: NOT APPROVED** — a second code replaces the
  first.
- Scopes: `PLATFORM`, `MARKET`, `RESTAURANT_ORGANIZATION`, `RESTAURANT_LOCATION`. A promotion with an amount has a
  currency and applies only to checkouts in that currency; a plain percentage has none.
- Stored states: DRAFT, ACTIVE, PAUSED, DISABLED. SCHEDULED and EXPIRED follow from the server clock.
- Codes are compared trimmed and in upper case, and are unique.
- Limits: total uses (`usage_limit`) and per customer. Usage = redeemed + uses **held** by READY checkouts. A use
  is held at confirmation under a row lock on the promotion — of two customers competing for the last use one
  keeps it — and released when the checkout ends. Redemption is counted by the order module, not here.
- What a customer is told is deliberately coarse: an unknown code, a draft, a paused, a disabled and a not-yet-
  started promotion all answer `promotion_not_found`.
- `funding_source` (PLATFORM / RESTAURANT / SHARED) is stored for later reporting and affects no calculation.
- Restaurant staff cannot create promotions; there is no restaurant promotion endpoint.

## Tax and fee rules

- Per market, optionally per restaurant location. Status DRAFT → ACTIVE → DISABLED. Editable only while DRAFT; an
  ACTIVE rule accepts only its end (`effective_until`) or being disabled. To change a rate: end the rule, create a
  new one. A checkout — and later an order — can therefore always name the rule it was priced with.
- `approval`: `TEST` or `APPROVED`. TEST rules are applied only where `checkout.financial_rules.allow_test` is
  true — **always false when `APP_ENV=production`**, whatever the environment file says.
- A fee code containing `DELIVERY` or `COMMISSION` is refused by validation and by a database CHECK: this is a
  pickup product, and a restaurant commission is a settlement matter, never a customer fee.
- Every create, change and status change needs a reason and writes an audit event with before / after values.

## Pickup-time hold

`SLOT HOLD = IMPLEMENTED`.

- Choosing a pickup time (Module 28) holds nothing. **Confirming a checkout** holds one order's worth of capacity in
  the chosen slot (an ASAP pickup holds the slot its pickup time falls in).
- The hold is a row of `pickup_slot_reservations` with source `HOLD`, bound to its checkout, with the checkout's
  expiry. It is taken atomically — count and insert in one transaction under a PostgreSQL advisory lock per
  restaurant and slot.
- It counts against capacity only while ACTIVE and not run out; a run-out hold frees its place at that instant,
  without a clean-up job. It is released when the checkout is cancelled, replaced, goes stale or expires.
- `PickupSlotHoldService::consume()` turns the hold into the order's reservation (same row) — ready for Module 31.
- PostgreSQL is the authority; Redis is not involved in capacity.
- Known limit, for Module 30: the hold ends when the READY window ends (10 minutes). A provider payment that takes
  longer needs the payment module to extend or re-take it.

## Legal acceptance

- `config/legal.php` names the documents required at checkout (terms, privacy, refund / cancellation), each with a
  version, an effective date and a slug. Whether a market's text is approved comes from the market configuration.
- The checkout lists them (`legal.required`); confirming requires every one **at its current version** (422
  `legal_acceptance_required`, `details.missing`).
- One row per document in `checkout_legal_acceptances`: customer, checkout, type, version, effective date, the
  document's approval status at that moment, the server time, the request id. No IP address, no device.
- When a required version changes, a READY checkout goes STALE and the documents are accepted again.
- `legal.final_approval` is true only when every required document of the market is approved. Today it is false.

## Payment context and preparation

- `payment_methods.available` — the method types the market is configured for (UPI, card, net banking, wallet for
  India, all `PLANNED`); `saved` — the customer's own usable references from Module 25 (a label and four digits).
- Confirming names a method type, optionally a saved reference of that type. That is **context**, not a provider
  capability check.
- `payment` (READY only): status `READY_FOR_PAYMENT` or `PAYMENT_NOT_REQUIRED`, the checkout id and version, the
  amount (= the checkout total), the pricing fingerprint, a deterministic idempotency key and
  `provider.status = NOT_INTEGRATED`.
- `CheckoutService::beginPayment()` is the hand-over for Module 30: READY → PAYMENT_PENDING in one transaction,
  only for the confirmed fingerprint. While PAYMENT_PENDING the cart and the pickup selection are locked
  (409 `cart_locked_for_payment`). No route calls it yet.

## API

Customer (`auth:customer`, account active). Opening and confirming accept an optional `Idempotency-Key`.

| Method | Path | Does |
|---|---|---|
| POST | `/api/v1/checkouts` | Open: 201 new DRAFT, 200 the unchanged live checkout |
| GET | `/api/v1/checkouts/{checkout}` | The checkout as it stands now |
| POST | `/api/v1/checkouts/{checkout}/promotion` | Apply one code (`{code}`) |
| DELETE | `/api/v1/checkouts/{checkout}/promotion` | Remove it |
| POST | `/api/v1/checkouts/{checkout}/confirm` | `{pricing_fingerprint, accepted_documents[], payment_method?, order_note?}` → READY |
| POST | `/api/v1/checkouts/{checkout}/cancel` | Leave it |

Admin:

| Method | Path | Permission |
|---|---|---|
| GET, POST | `/api/v1/admin/promotions` | `admin.promotions.view` / `.manage` |
| GET, PATCH | `/api/v1/admin/promotions/{promotion}` | view / manage |
| POST | `/api/v1/admin/promotions/{promotion}/status` | manage |
| GET | `/api/v1/admin/markets/{market}/financial-rules` | `admin.market_configuration.view` |
| POST | `/api/v1/admin/markets/{market}/tax-rules`, `…/fee-rules` | `admin.market_configuration.manage` |
| PATCH, POST | `/api/v1/admin/tax-rules/{taxRule}`, `…/status`; same for `fee-rules` | manage |
| GET | `/api/v1/admin/system/checkout` | `admin.system.view` — counts only |

A checkout is found only for the customer it belongs to; anybody else gets 404 `checkout_not_found`.
Restaurant staff and administrators cannot read a customer's checkout.

Errors: `cart_not_found` 404 · `cart_expired`, `checkout_expired` 410 · `cart_empty`, `cart_invalid`,
`item_unavailable`, `restaurant_unavailable`, `restaurant_not_accepting`, `market_unavailable`, `pickup_required`,
`pickup_invalid`, `pickup_slot_full`, `pickup_selection_expired`, `pickup_slot_unavailable`, `checkout_stale`,
`payment_in_progress`, `cart_version_conflict`, `cart_locked_for_payment` 409 · `promotion_not_found`,
`promotion_not_applicable`, `promotion_expired`, `promotion_minimum_not_met`, `promotion_limit_reached`,
`promotion_customer_limit_reached`, `legal_acceptance_required`, `payment_method_required`,
`payment_method_unavailable`, `validation_failed` 422 · `rate_limited` 429.
Admin: `stale_update`, `invalid_status_transition`, `promotion_live_edit_refused`, `promotion_disabled`,
`promotion_code_taken`, `rule_frozen`, `rule_incomplete` 409.

Rate limits per customer and minute: reads 120, writes 30, promotion attempts 10.

## Database

Migration `2026_10_08_100000_create_checkout_tables`:
`promotions`, `promotion_reservations`, `tax_rules`, `tax_rule_components`, `fee_rules`, `checkout_sessions`,
`checkout_legal_acceptances`; `pickup_slot_reservations` gains `checkout_session_id` and the `EXPIRED` status.

Enforced by the database, not only by the code: the total equation and "discount ≤ subtotal"; payment required
iff total > 0; READY needs a confirmation time; one live checkout per cart; one active hold per checkout; rate
between 0 and 10000 basis points; a fee is fixed with an amount and currency or a percentage without an amount;
no `DELIVERY` / `COMMISSION` fee code; windows end after they start.

## Events, numbers, logs

`CheckoutEvent`: created, reused, blocked, confirmed, stale, expired, cancelled, promotion_applied / refused /
removed, hold_failed, payment_started. Each is counted (hourly cache buckets) and written as one log line with the
checkout's public id, status, currency, total in minor units and a reason code — no cart contents, no order note,
no phone number, no promotion code, no payment reference.

`checkouts:prune` (every five minutes): expires run-out checkouts and holds, releases run-out promotion uses,
deletes closed checkouts after 30 days. PAYMENT_PENDING and CONVERTED are never touched.

## Clients

- **Customer Web** (`VITE_CHECKOUT_MODE=api`, needs the cart and pickup on the API): `ServerCheckoutProvider`
  behind the interface the pages already used. The checkout page shows the backend's customer, restaurant, pickup
  time, lines, promotion, tax and fee lines and total; the button carries the backend total; a stale or expired
  checkout is started again and the customer is told what changed; a total of zero does not go to a payment step.
  The cart page has no promo field of its own. The payment step that follows is still the **development** payment
  of Module 12 — it shows the backend total, and it asks the backend again before "paying".
- **Android** (`--dart-define=CHECKOUT_MODE=api`): `CheckoutState` with a `CheckoutBackend`; the same rules,
  revalidation on app resume, and a countdown on the server clock.
- **Platform Admin**: Promotions on the backend; Market › Configuration › Tax lists the real tax and fee rules
  with TEST / APPROVED badges and lets an administrator with the permission create, activate, end and disable
  them; System › Checkout shows the counters.
- Unit tests and static share builds keep the in-browser / in-app development checkout (`mock`).

## Tests

PHPUnit `tests/Feature/Checkout/*` (session, revalidation, pricing, promotions, slot hold, concurrency, security,
payment preparation, admin promotions, admin tax / fee rules, performance, OpenAPI contract, fixture) and
`tests/Unit/Support/RoundingTest`. Web: `src/checkout/api/apiCheckout.test.tsx`. Android:
`test/api_checkout_test.dart`. End to end on the real local backend: `m29-web-e2e.json`,
`m29-android-preview-e2e.json` under `docs/local-review/test-results/`.

## Not in this module

Real payment creation, capture, verification, webhooks, refunds (Module 30); order creation and confirmation
(Module 31); invoices and receipts; restaurant-created promotions; promotion stacking; buy-one-get-one, free-item
and cashback promotions; settlement, commission and platform revenue accounting; live tax rates for any country.
