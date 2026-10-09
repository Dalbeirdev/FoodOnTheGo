# Orders: creation from a verified payment, the immutable snapshot, the restaurant workflow, recovery (Module 31)

Everything here is **local-only**. The order domain runs on the development machine against PostgreSQL 18 with the
DEVELOPMENT payment provider; nothing has touched production, no real customer money exists, and Razorpay's own
window, API and webhooks are still PENDING PROVIDER CONFIGURATION (Module 30). Realtime tracking, secure pickup
verification, refund processing and notification delivery are NOT built — they are Modules 32 and later, and every
place that would show them says `NOT_IMPLEMENTED`.

## The rule

An order is created by the **backend**, from a **VERIFIED payment attempt** of a confirmed checkout — or, when the
confirmed checkout has nothing to pay, at the customer's request. Never from a client's "paid", a client callback, a
boolean, a status or an amount. The order is an **immutable copy** of what was confirmed and paid: items, options,
prices, promotion, taxes, fees, total, pickup time, the restaurant and the customer as they were, the legal
documents accepted, a safe reference to the payment. Nothing is priced again after payment; nothing is read again
from the menu, the tax rules or the restaurant. Payment and Order are separate records with separate states, and
**payment VERIFIED, order NOT CREATED** is a state the system represents, retries, surfaces and lets a person resolve.
A verified payment is never rewritten to FAILED because of the order.

## Vocabulary

| Term | Meaning |
| --- | --- |
| **order** | `orders` row: the immutable snapshot plus an operational state (`status`) and a money state (`payment_state`, `refund_status`). Never deleted. |
| **order number** | `FOTG-YYYYMMDD-XXXXXX`: the restaurant-local date the order was placed, six characters from an alphabet without 0/O/1/I. Unique. A name for people — **never authorization**: every lookup is narrowed by who asks. |
| **fulfillment** | `order_fulfillments` row: the one record of turning a checkout into an order. One per checkout — the row every worker locks, so of two workers exactly one creates the order and the other finds it. States PENDING · PROCESSING · SUCCEEDED · RETRYING · FAILED · REVIEW_REQUIRED. |
| **trigger** | What asked for the order: PAYMENT_VERIFIED (the event / the queue), ZERO_TOTAL (the customer's request for a checkout with nothing to pay), RECOVERY (the sweep or an administrator). |
| **pickup credential** | A random opaque token (only its SHA-256 stored) for the QR, and a six-character code (stored encrypted, with a hash for lookup) the customer reads out. Carries no order, customer or amount. Verifying it is Module 32. |
| **refund record** | `order_refunds` row with status REQUIRED: a paid order was rejected or cancelled and money is owed. The refund module processes it; nothing is refunded here. |

## Flow

```
PaymentVerified (after commit, Module 30)
   └─► FulfillVerifiedPayment (listener) ─► FulfillCheckoutJob (queue "orders", after commit, 3 tries)
            └─► OrderFulfillmentService::fulfillPayment(paymentId)
                      │  one transaction:
                      │   lock the checkout's fulfilment row (insert-or-lock by checkout id)   ← exactly once
                      │   order exists? → SUCCEEDED, done
                      │   lock the checkout row, lock the payment row
                      │   JUDGE   payment VERIFIED, for THIS checkout, same amount + currency
                      │           checkout PAYMENT_PENDING (a READY one is taken back), customer present
                      │           restaurant APPROVED and accepting orders, market serving customers
                      │           (the PRICE is not judged: it was paid; items are not re-validated —
                      │            an item gone after payment is the restaurant's rejection → refund)
                      │   CAPACITY the pickup hold becomes the order's reservation (ACTIVE → source ORDER,
                      │           no expiry) or the same slot is reserved again under the slot's advisory lock
                      │           → none: ORDER_PICKUP_CAPACITY_UNAVAILABLE (the time is never moved silently)
                      │   CREATE  order + items + options + tax lines + fee lines + pickup credential
                      │           order number generated, retried on a collision (savepoint)
                      │           timeline: ORDER_CREATED (CREATED → WAITING_FOR_RESTAURANT)
                      │   FINALIZE promotion use REDEEMED (+1 redeemed_count, under the promotion's lock)
                      │           checkout CONVERTED · cart CONVERTED · pickup selection CONVERTED
                      │           payment fulfilment ORDER_CREATED · fulfilment SUCCEEDED
                      └─ after commit: OrderEvent order.created, metrics
       blocked (no capacity, restaurant / market unavailable, payment mismatch, checkout invalid):
            fulfilment REVIEW_REQUIRED (+ code) · payment RECOVERY_REQUIRED (+ reason) · log · metric
            — the money is not touched; an administrator sees it under "needs attention"
       technical failure (exception):
            fulfilment RETRYING with backoff 30 s · 60 s · 5 min · 15 min · 60 min, then REVIEW_REQUIRED
            and payment RECOVERY_REQUIRED (ORDER_CREATION_FAILED)
orders:recover (every minute, without overlapping)
   └─► sweep: RETRYING rows that are due + VERIFIED/ORDER_PENDING payments older than 60 s with no fulfilment
POST /checkouts/{checkout}/fulfill (customer, zero total only)
   └─► OrderFulfillmentService::fulfillZeroTotal → the same path, trigger ZERO_TOTAL, no payment row
POST /admin/order-fulfillments/{fulfillment}/recover (admin.orders.recover, reason, audited)
   └─► OrderFulfillmentService::retry → the same path, trigger RECOVERY — nothing is created by hand
```

## State machine

```
CREATED ──► WAITING_FOR_RESTAURANT ──► ACCEPTED ──► PREPARING ──► READY_FOR_PICKUP ──► PICKED_UP ──► COMPLETED
                      │
                      └──► REJECTED                        (restaurant, with a structured reason)
WAITING_FOR_RESTAURANT / ACCEPTED / PREPARING / READY_FOR_PICKUP ──► CANCELLED   (administrators only)
```

- CREATED exists only inside the creating transaction; the first state anybody reads is WAITING_FOR_RESTAURANT
  (the database refuses a persisted CREATED).
- `OrderStateMachine` is the one place a status changes: it checks `OrderStatus::allows`, stamps the matching
  `*_at` column, increments `version` and appends an `order_events` line with who did it.
- PICKED_UP and COMPLETED are in the machine; nothing reaches them before Module 32 (secure pickup verification).
  There is deliberately no "picked up" button on the restaurant API.
- Terminal states (COMPLETED, REJECTED, CANCELLED) are never reopened.
- Customers cannot cancel in this module. An administrator cancels with a reason (admin.orders.override), audited.

### Money state (separate from the status)

| Column | Values | Rule |
| --- | --- | --- |
| `payment_state` | PAID · PAYMENT_NOT_REQUIRED | PAID ⇔ a payment reference exists; NOT_REQUIRED ⇒ total 0 (database constraints). |
| `refund_status` | NONE · REQUIRED · PENDING · PARTIAL · REFUNDED · FAILED | Rejecting or cancelling a PAID order sets REQUIRED and writes an `order_refunds` row for the whole total. REFUNDED is never set here: only a provider's confirmation (refund module) will. |

## Restaurant workflow

`GET /restaurant/orders?location=…` (the active queue, by pickup time, with counts by state), `GET /restaurant/orders/{order}`,
`POST /restaurant/orders/{order}/accept | reject | start-preparing | ready`.

- Permissions `restaurant.orders.view` / `restaurant.orders.update` for the order's **location**; an order of another
  organization — or of a location the member does not cover — is **404**, never 403 (no existence leak).
- Every step runs under the order's row lock, is **idempotent** (the same step twice succeeds once, then answers the
  order as it is without a second event) and honours `expected_version`: a stale screen is refused with
  `409 order_version_conflict` carrying `current_version` and `current_status`. `Idempotency-Key` replays the exact request.
- Rejection takes a structured reason — ITEM_UNAVAILABLE · KITCHEN_CAPACITY · CLOSING · TECHNICAL_ISSUE · OTHER — and
  an internal note. The customer reads a neutral message for the reason; the note never reaches them.
- Rejecting (or an administrator cancelling) releases the order's pickup reservation, revokes the pickup credential,
  and for a PAID order requires a refund (see above). The amount is never changed after payment.
- The restaurant's view carries the customer's name and phone **for this order**, the items and the pickup time —
  never the payment method, the promotion's economics, the journey or the pickup credential (it verifies that,
  Module 32; it does not read it).

## Customer side

`GET /customer/orders` (summaries, newest first, `filter[active]`), `GET /customer/orders/{orderNumber|publicId}`
(the caller's own only; anybody else's is 404), `POST /checkouts/{checkout}/fulfill` (zero total only; a checkout
that has to be paid is `409 order_payment_required`). The payment answer (`GET /payments/{payment}`) now says which
order it became: `fulfillment.order_id` / `fulfillment.order_number`. The Customer Web and the Android app wait for
that number after VERIFIED and then open `/order-confirmation/{orderNumber}` — a real order, after it was created;
`RECOVERY_REQUIRED` is shown as "payment received, order not confirmed yet, do not pay again".

The customer's order carries `pickup_credential` (code + opaque QR payload) while the order is active, a
customer-visible timeline (states and public reasons only), `next_action` (WAIT_FOR_RESTAURANT · TRACK ·
GO_TO_PICKUP · DONE · ORDER_AGAIN · AWAIT_REFUND) and `pickup_verification: NOT_IMPLEMENTED`.

## Administrators

`GET /admin/orders` (per market, `admin.orders.view`), `GET /admin/orders/{order}` (money state, payment and
fulfilment linked, refunds, full timeline), `POST /admin/orders/{order}/cancel` (`admin.orders.override`, reason,
audited as `order.cancelled`), `GET /admin/order-fulfillments` (`filter[attention]=1`: REVIEW_REQUIRED · FAILED ·
RETRYING — **payment VERIFIED, order NOT CREATED** is this list until a person resolves it),
`POST /admin/order-fulfillments/{fulfillment}/recover` (`admin.orders.recover`, reason, audited as
`order.recovery_attempted` — judged again from scratch; nothing is created by hand), `GET /admin/system/orders`
(counts: orders by state, verified payments without an order, retries / reviews, creation latency).

`admin.orders.recover` is a new permission (super admin, operations admin, support admin).

## Capacity

Real orders replace the development fixture as the source of pickup capacity: an active order's reservation
(source ORDER, no expiry) counts; a rejected or cancelled order's reservation is RELEASED at once. The hold a checkout
made is the same row the order takes over.

## Database

Migration `2026_10_10_100000_create_order_tables`: `order_fulfillments`, `orders`, `order_items`, `order_item_options`,
`order_tax_lines`, `order_fee_lines`, `order_events` (append-only), `order_pickup_credentials`, `order_refunds`.
Rules the database itself enforces: one order per checkout (two unique columns) and per payment attempt; a unique
order number of the documented form; `total = subtotal − discount + tax + fee`; PAID ⇔ payment reference;
NOT_REQUIRED ⇒ total 0; REJECTED ⇔ reason + rejected_at; CANCELLED ⇔ cancelled_at; version ≥ 1; the five rejection
reasons; the eight visible statuses (never CREATED); one credential per order, one open refund per order; the
customer and the restaurant cannot be deleted while they have orders (RESTRICT); a deleted checkout, cart or payment
leaves the order standing (the public ids stay as references).

## Events, numbers, logs

- `App\Events\Order\OrderEvent` after commit: `order.created`, `order.restaurant_accepted`, `order.preparing`,
  `order.ready`, `order.picked_up`, `order.completed`, `order.restaurant_rejected`, `order.cancelled`,
  `order.refund_required`, `order.pickup_credential_reissued` — the hooks for the realtime layer (Module 32:
  `BroadcastOrderUpdate`, see [`realtime-and-pickup.md`](realtime-and-pickup.md)), the notification module and the
  refund module. `RecordOrderEvent` writes one log line per event with ids, states and codes only. Every timeline
  line carries a per-order `sequence` (Module 32).
- `OrderMetrics`: hourly counters (created, creation_failed, recovery_required, review_required, accepted, rejected,
  preparing, ready, cancelled, refund_required, zero_total), creation latency, and the live counts.
- Logs never carry a customer's phone or name, an item name, a pickup code, a token or a provider secret.

## Clients

- **Customer Web** (`VITE_ORDER_MODE=api`, needs the payment on the backend): `ApiOrderRepository` maps the backend
  order onto the order model the confirmation, tracking, details and history pages already render; the payment page
  waits for `fulfillment.order_number` and opens the confirmation; tracking listens to the order's private channel
  and polls as the fallback (Module 32). Zero-total checkouts call `POST /checkouts/{checkout}/fulfill`.
- **Restaurant Dashboard**: `ApiRestaurantOrderRepository` — the queue, accept / reject / ready (the one "ready"
  button takes the two backend steps ACCEPTED → PREPARING → READY_FOR_PICKUP in order); the queue is live on the
  location's channel; pickup verification (QR / manual code) is `POST /restaurant/pickup/verify` (Module 32).
  "Report delay" is refused plainly (not on the backend yet).
- **Platform Admin**: `ApiAdminOrderRepository` — orders, detail with the payment and refunds, exceptions from the
  order creation records; cancel and recover.
- **Android** (`ORDER_MODE=api`): `ApiOrderRepository`, `ApiPickupVerificationRepository`, `ApiOrderTrackingService`
  (the private channel over `lib/realtime/pusher_client.dart`, polling as the fallback — Module 32); the payment
  screen waits for the order number and opens the confirmation; a restart re-reads the payment and finds the same
  order — never a second one.

## Running locally

Payments → orders need a queue worker for the queues `payments` and `orders` (or `queue:work redis --queue=orders,payments`);
`orders:recover` is scheduled every minute and is the safety net when no worker runs. `php artisan orders:recover`
can be run at any time.

## Tests

`tests/Feature/Order`: `OrderCreationTest` (creation, exactly once, promotion redemption, zero total, no creation from
a client's word, order numbers), `RestaurantOrderWorkflowTest` (queue, steps, idempotency, version conflicts,
rejection and refund requirement, tenant isolation, customer reads), `OrderRecoveryTest` (sweep, expired hold,
payment mismatch, technical failure with backoff, administrator recovery, scheduling), `OrderSecurityTest` (database
constraints, retention, no customer cancellation, admin cancel with audit, credential hashing, privacy, rate limits),
`OrderOpenApiContractTest`, `OrderPerformanceTest` (query counts, indexes, repeated fulfilment).

## Not in this module

Refund processing · notification delivery · customer cancellation · delay reporting · settlements. Every one of them
is named `NOT_IMPLEMENTED` where a client or an administrator would look for it. Realtime tracking, secure pickup
verification and PICKED_UP / COMPLETED were Module 32 — see [`realtime-and-pickup.md`](realtime-and-pickup.md).
