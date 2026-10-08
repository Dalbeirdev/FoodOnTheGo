# Payments: payment attempts, the provider hand-off, server-side verification, webhooks, reconciliation and recovery (Module 30)

Status on 2026-10-07 — **LOCAL only. Nothing here is live.**

- The payment domain is built and runs locally: payment attempts, the provider interface, verification on the
  server, webhooks, reconciliation, the customer and admin APIs, the Customer Web page, the Android screen.
- **Razorpay (India) is implemented but has not been exercised against Razorpay.** There are no Razorpay
  credentials on the development machine, so the real Razorpay API, Razorpay's own checkout window / Android
  screen and a webhook delivered by Razorpay are **PENDING PROVIDER CONFIGURATION**. The Razorpay client is covered
  by tests with recorded HTTP shapes from the documentation, not by a call to Razorpay.
- Locally the **development provider** (`PAYMENT_DRIVER=fake`) stands in. It moves no money. It exists only outside
  production.
- **A verified payment creates no order.** Orders are Module 31. A verified payment waits as `ORDER_PENDING`.
- Refunds, settlements, payouts, saved cards / tokens, cash at pickup and live mode are **not built**.

## The rule

Whether a payment happened is decided on the backend, from what the **provider** says about **our** order.

- Never trusted: a success screen, the provider's client callback alone, the Android SDK's success event alone, an
  amount, a currency or a status sent by a client.
- A payment is `VERIFIED` only when the provider reports a payment that is **captured**, belongs to **this
  attempt's own provider order**, for **this attempt's amount and currency**. There is one place in the code that
  can write `VERIFIED` (`PaymentStateMachine::verify`).
- `VERIFIED` is final. Nothing that arrives later can take it back.
- No client request carries an amount, a currency, a status, an owner or a provider. Requests that try are refused
  as a whole (422) before they reach the domain.
- FoodOnTheGo never collects or stores a card number, a CVV / CVC, a UPI PIN, a bank password or an OTP. What a
  customer types goes into the provider's own interface.
- Money is integer minor units with an explicit ISO currency everywhere.

## Provider documentation reviewed

Reviewed on **2026-10-07** at `razorpay.com/docs` and `pub.dev`. None of the pages shows a version or a
"last updated" date, so the date of the review is the only version that can be recorded.

Pages: Standard Checkout web integration steps · Orders API (create, fetch all, fetch payments of an order) ·
Payments API (fetch by id) · payment life cycle · capture settings · webhooks (overview, validate and test, best
practices, payment payloads) · API basics and errors · test cards · test UPI ids · Flutter standard integration ·
`razorpay_flutter` on pub.dev (1.4.7) · normal refunds · configuring payment methods.

What the implementation relies on:

| Fact from the documentation | Where it is used |
|---|---|
| `POST /v1/orders`, basic auth `key_id:key_secret`, amount in currency subunits | `RazorpayPaymentProvider::createSession` |
| `receipt` is unique and "treated as an idempotency key"; a duplicate is rejected | A lost answer is recovered with `GET /v1/orders?receipt=` instead of creating a second order |
| Capture can be set per order (`payment.capture`, `capture_options`) | `RAZORPAY_CAPTURE=automatic` (default) sends it; `dashboard` sends nothing |
| "Deliver only after the payment is captured" | `VERIFIED` requires `captured`; `authorized` is `AUTHORIZED`, not paid |
| Checkout signature = HMAC-SHA256(`order_id|payment_id`, key secret), with the **server's** order id | `verifyClientSignature` uses the attempt's stored order reference, never one from the request |
| Webhook signature = HMAC-SHA256(**raw body**, webhook secret) in `X-Razorpay-Signature` | The raw request body is verified before anything is parsed |
| `x-razorpay-event-id` is unique per event; events are not ordered; answer within 5 s; retried for 24 h | Deduplication on the event id, processing on a queue, order-independent handling |
| Rate limit answers `429`; use backoff | `PaymentProviderManager::call` retries idempotent reads with backoff and jitter |
| `razorpay_flutter` supports Android and iOS, not the web | The Android preview in a browser uses the development provider only |

To be confirmed against the real API once test keys exist: the capture parameters sent with an order, the exact
webhook payloads of the account, and which methods the account has enabled.

## Vocabulary

| Term | Meaning |
|---|---|
| Payment attempt | One try to pay one confirmed checkout. Has its own id, amount, currency and exactly one provider order. A retry is a **new** attempt. |
| `provider_order_reference` | The provider's order for an attempt. The domain does not know the word "Razorpay order". |
| `provider_payment_reference` | The provider's payment that settled the attempt. Unique per provider. |
| Support reference | `PAY-` + ten hex digits of the attempt id — what a customer can quote. |
| Fulfilment state | What becomes of a verified payment: `NOT_APPLICABLE` · `ORDER_PENDING` · `RECOVERY_REQUIRED` · `ORDER_CREATED` (Module 31). Kept apart from the payment state. |

Payment states:

| State | Meaning |
|---|---|
| CREATED | The attempt exists; the provider has not answered yet |
| PROVIDER_READY | The provider has an order; the customer can pay in the provider's interface |
| PROCESSING | The customer's side reported something; it is being verified |
| PENDING | The provider has a payment that is neither successful nor failed yet |
| AUTHORIZED | Approved by the bank, not captured. Not paid |
| VERIFIED | Captured, our order, our amount and currency. Final |
| FAILED · CANCELLED · EXPIRED | Ended without money, as far as is known. The customer may try again |
| UNKNOWN | The evidence is missing or contradicts itself. Neither paid nor unpaid is assumed |
| REVIEW_REQUIRED | Automatic checking could not settle it, or what it found needs a person |

"Open" (no second attempt may start beside it): CREATED, PROVIDER_READY, PROCESSING, PENDING, AUTHORIZED, UNKNOWN.
A non-success is not turned into FAILED: pending, unknown and review are their own states.

## Architecture

```
Customer Web / Android
   │  POST /checkouts/{id}/payment-attempts      (fingerprint of the price seen; a method type)
   │  GET  /payments/{id}                         (the only source of "verified" for a client)
   │  POST /payments/{id}/complete                (what the provider's interface said — a report)
   ▼
PaymentController ──► PaymentService ──► CheckoutService::beginPayment   (READY → PAYMENT_PENDING, hold kept)
                          │
                          ├─► PaymentProviderManager ──► PaymentProvider (interface)
                          │                                 ├─ RazorpayPaymentProvider  (HTTP, no SDK)
                          │                                 └─ FakePaymentProvider      (development only)
                          │
                          └─► PaymentReconciliationService ──► PaymentStateMachine ──► payment_attempts
                                     ▲            ▲                                     payment_attempt_events
Provider ── POST /webhooks/payments/{provider} ──┘            │
   PaymentWebhookController ► PaymentWebhookService ► queue "payments" ► ProcessPaymentWebhookEvent
                                                              │
Scheduler ── payments:reconcile (every minute) ───────────────┘
```

- Provider calls are made **outside** database transactions; state changes happen under a row lock (the checkout
  row first, then the attempt). Domain events are dispatched after commit.
- Every path that can settle a payment — the client's report, a webhook, a status read, the sweep, an
  administrator — ends in the same function: `PaymentReconciliationService::reconcile`, which asks the provider
  and judges what it finds.
- There is no outbox. The sweep is the safety net for anything a lost event or a stopped worker left behind.

## Life cycle

```
CREATED ──► PROVIDER_READY ──► PROCESSING ──► VERIFIED
                 │                 ├──► AUTHORIZED ──► VERIFIED
                 │                 ├──► PENDING ─────► VERIFIED | FAILED
                 │                 ├──► FAILED | CANCELLED | EXPIRED
                 │                 └──► UNKNOWN ─────► … | REVIEW_REQUIRED
                 └──► CANCELLED | EXPIRED
```

- **Late success.** FAILED, CANCELLED, EXPIRED, UNKNOWN and REVIEW_REQUIRED can still become VERIFIED — only through
  reconciliation holding the provider's evidence. If another attempt of the same checkout is already VERIFIED, the
  late one becomes REVIEW_REQUIRED (`DUPLICATE_PAYMENT`): money that needs a person, never a silent second payment.
- **Mismatch.** A captured payment on our order for another amount or currency becomes REVIEW_REQUIRED
  (`PAYMENT_AMOUNT_MISMATCH` / `PAYMENT_CURRENCY_MISMATCH`). It is never verified.
- Every change is written to `payment_attempt_events` with its source: SYSTEM, CLIENT, PROVIDER, WEBHOOK, JOB or
  ADMIN.

## Starting a payment

`POST /checkouts/{checkout}/payment-attempts` with `pricing_fingerprint` and, at most, `payment_method.type`.

1. No provider configured → `503 payment_provider_unavailable`. Nothing is touched. Nothing is simulated.
2. The checkout must be the caller's, confirmed (READY), unexpired, payable (total > 0) and still valid: the cart,
   the restaurant and the pickup time are judged again. Otherwise `409 checkout_stale` / `410 checkout_expired`.
3. The fingerprint must be the confirmed one. Another price → `409 checkout_stale`.
4. An attempt that is already open is settled first. A payable one (PROVIDER_READY, not expired) is **returned**
   (200) — the same attempt, the same provider order. One that may still end in money →
   `409 payment_already_in_progress`. A verified one → `409 payment_already_verified`.
5. Otherwise the checkout goes to PAYMENT_PENDING and the attempt is written in one transaction; then the provider
   order is created. The checkout's expiry and its pickup-time hold are extended to the attempt's expiry plus a
   grace period.
6. A provider that answers "no" → the attempt is FAILED, the checkout is READY again, `503`. A provider that does
   not answer → the attempt stays CREATED (`UNKNOWN` to the client), is looked up by its receipt later, and the
   customer is told to wait — not to pay again.

Lifetimes (development defaults, `config/payments.php`): attempt 15 min · grace 5 min · at most 5 attempts per
checkout · payment window 45 min after confirmation · order hand-off 30 min.

## What a client does

- The amount shown on the payment page / screen is the backend's: the attempt's, or the confirmed checkout's before
  an attempt exists.
- The client opens the provider's interface with the **public key id** and the order reference from
  `provider_session`. Web: Razorpay's `checkout.js`, loaded only from `https://checkout.razorpay.com`. Android:
  Razorpay's `razorpay_flutter` plugin.
- What the interface says is sent to `POST /payments/{id}/complete` as `outcome` (`success` · `failed` ·
  `cancelled`), for success with the provider's payment reference and proof. "success" means "please verify".
- The client shows what `GET /payments/{id}` says. It never decides that a payment succeeded.
- After a reload, a closed tab, a killed app or a lost connection the client asks the backend. When the device kept
  nothing, it finds the payment through the checkout the backend names as being paid for (`409
  payment_in_progress` carries `checkout_id`; the checkout names its latest attempt).
- A lost connection is "the status needs confirmation" — never "failed", and never an offer to pay again.

## Verification of a client report

- `success`: the proof is checked with the server's key against the **stored** order reference. A wrong proof →
  `422 payment_signature_invalid`, counted on the attempt. The provider is then asked about our order at once: a
  payment that was really made is found and verified on the provider's word; an invented one is simply not there.
- A valid proof does not make a payment VERIFIED. It starts verification: the provider is asked, and only a
  captured, matching payment verifies.
- `failed` / `cancelled`: the provider is asked. No payment on the order → FAILED / CANCELLED. A captured payment
  on the order → VERIFIED, whatever the device said.

## Webhooks

`POST /api/v1/webhooks/payments/{provider}` — no customer session, its own rate limit.

1. Empty or oversized body (64 KB) → 400 / 413.
2. The **raw body** is verified against the provider's signature header. Invalid → `401`. Nothing is stored.
3. Deduplicated on the provider's event id (or a hash of the body when there is none). A repeat is counted
   (`deliveries`) and answered `200`.
4. A normalized summary of safe fields is stored — never the raw message.
5. Answered `200` at once; the event is handled by `ProcessPaymentWebhookEvent` on the queue `payments`.
6. Handling means reconciliation: the provider is asked again. What the event itself says is used only when the
   provider cannot be reached, and only to move forward.

Events are not ordered and may arrive more than once; a late "failed" cannot undo a verified payment.

## Reconciliation

- `payments:reconcile` runs every minute (`routes/console.php`). It looks at open attempts whose time has come,
  at provider events nobody processed, and at verified payments whose checkout can no longer become an order.
- Backoff between looks: 1, 2, 5, 10, 30, 60, 60, 120, 240, 480 minutes. An attempt the sweep could not settle in
  all its tries becomes REVIEW_REQUIRED.
- A status read by the customer reconciles an open attempt inline, at most every 10 seconds.
- An administrator can ask the provider again (with a reason, audited). That is the only action: it changes only
  what the provider confirms.

## Duplicate protection

| Risk | Guard |
|---|---|
| Two taps, two tabs, two devices | One open attempt per checkout (unique partial index); the second request gets the first attempt |
| A retried request | Idempotency key on start; a lost answer is repeated with the same key |
| The provider order created twice | The receipt is unique at the provider; a lost answer is looked up by receipt |
| Two verified attempts of one checkout | One VERIFIED per checkout (unique partial index); a second capture → REVIEW_REQUIRED |
| One provider payment used for two attempts | Provider payment reference unique per provider; the proof is bound to our order |
| The same webhook twice | Unique event reference per provider |
| A report repeated after settlement | A settled attempt is returned as it is |

## Checkout, pickup time and a payment that is in progress

- **T1 — before Pay.** The checkout expired, the restaurant paused or was suspended, an item sold out: no attempt
  is created.
- **T2 — the provider's interface is open.** The checkout and the pickup hold are kept until the attempt's expiry
  plus grace. The cart and the pickup selection are locked (`409 cart_locked_for_payment`).
- **T3 — the money arrives after something changed.** A payment made at the provider cannot be un-made by us. It is
  verified, and when its checkout can no longer become an order as it stands the fulfilment state is
  `RECOVERY_REQUIRED` with the reason. It appears under "needs attention" for an administrator.

An attempt that ends unpaid gives the checkout back (READY), or closes it when its time ran out.

## Order hand-off, recovery and refund readiness

- `fulfillment_status` is the contract with Module 31: `ORDER_PENDING` attempts are the ones an order is created for
  (`PaymentVerified` → `FulfillCheckoutJob` → `OrderFulfillmentService`, see [`order-domain.md`](order-domain.md));
  `ORDER_CREATED` names the order in the payment answer (`fulfillment.order_id` / `order_number`); `RECOVERY_REQUIRED`
  carries the reason the order could not be made as it stands.
- Nothing creates an order in the payment domain itself; the clients wait for the order number after VERIFIED.
- Refunds are not built. What a refund will need is kept: the provider payment reference, the captured amount and
  currency, the provider and its mode, and the timeline. `PaymentProvider::refund()` exists on the interface and is
  not called.
- With Module 31 the paid checkout and its cart become CONVERTED when the order is created.

## Provider configuration

Names only — values belong in the git-ignored `backend/.env`, never in the repository:

`PAYMENT_DRIVER` (`razorpay` · `fake` · empty) · `RAZORPAY_KEY_ID` · `RAZORPAY_KEY_SECRET` ·
`RAZORPAY_WEBHOOK_SECRET` · `RAZORPAY_MODE` · `RAZORPAY_CAPTURE` (`automatic` · `dashboard`).

- There is **no fallback**. With nothing configured, payments are unavailable (`503`) and the checkout says
  `provider.status = UNAVAILABLE`.
- The fake provider is allowed only where `APP_ENV` is not `production`.
- A live key (`rzp_live_…`) is refused: live mode is switched off in code (`payments.providers.allow_live = false`).
- Only the key **id** ever leaves the backend. The key secret and the webhook secret are read by the Razorpay
  client and by nothing else; the admin report names missing settings, never a value.

## API

| Method | Path | |
|---|---|---|
| POST | `/api/v1/checkouts/{checkout}/payment-attempts` | Start paying, or get the open attempt. 201 new · 200 existing |
| GET | `/api/v1/payments/{payment}` | The attempt as the backend holds it |
| POST | `/api/v1/payments/{payment}/complete` | Report how the provider's interface ended |
| POST | `/api/v1/webhooks/payments/{provider}` | The provider's events |
| POST | `/api/v1/dev/payments/{payment}/simulate` | Development provider only; not registered in production |
| GET | `/api/v1/admin/payments` | List (filters: status, provider, method, currency, fulfillment, restaurant, attention, from, to; exact-reference search) |
| GET | `/api/v1/admin/payments/{payment}` | Detail with timeline and provider events |
| POST | `/api/v1/admin/payments/{payment}/reconcile` | Ask the provider again (`admin.payments.reconcile`, reason, audited) |
| GET | `/api/v1/admin/system/payments` | Counts and provider health |

A payment answer carries: `id`, `checkout_id`, `attempt_number`, `status`, `amount`, `provider {name, mode}`,
`method` (safe summary), `failure {category, code, message, retryable}`, `message`, `next_action`,
`retry_after_seconds`, `provider_session` (while payable), `fulfillment {status, order_id: null,
order_creation: "NOT_IMPLEMENTED"}`, `support_reference` and timestamps.

`next_action`: OPEN_PROVIDER · WAIT · REFRESH_STATUS · RETRY_PAYMENT · SELECT_ANOTHER_METHOD · RETURN_TO_CHECKOUT ·
CONTACT_SUPPORT · PROCEED_TO_ORDER.

Errors: `payment_not_found` 404 · `payment_already_verified`, `payment_already_in_progress`, `payment_in_progress`,
`payment_attempt_limit_reached`, `payment_window_closed`, `payment_not_required`, `payment_not_ready`,
`checkout_stale`, `checkout_not_ready` 409 · `checkout_expired` 410 · `payment_signature_invalid`,
`payment_method_unavailable`, `validation_failed` 422 · `rate_limited` 429 · `payment_provider_unavailable`,
`payment_initialization_failed`, `payment_initialization_unknown` 503 · `webhook_payload_invalid` 400 ·
`webhook_signature_invalid` 401 · `webhook_payload_too_large` 413.

Rate limits per minute: start and report 10 per customer · status 120 · webhooks 600 per address.

## Database

`payment_attempts` · `payment_attempt_events` · `payment_webhook_events`
(`2026_10_09_100000_create_payment_tables.php`).

Enforced by the database: the state and fulfilment vocabularies, positive amounts, a three-letter currency,
`verified_at` exactly when VERIFIED, the fulfilment state matching the payment state, one open attempt and one
verified attempt per checkout, unique provider order and payment references, unique provider event reference.

An attempt outlives its checkout (`checkout_session_id` is set to null, the public ids stay): money has a record
whatever is pruned around it.

## Events, numbers, logs

- `PaymentEvent` (attempt created, provider ready, client callback, signature invalid, state changes, webhook
  received / processed, fulfilment changes) and `PaymentVerified`.
- Counters in hourly cache buckets (`PaymentMetrics`), read by `GET /admin/system/payments` with the provider's
  measured health (calls, latency, failures).
- One log line per event: ids, states, amounts in minor units, reason codes. No phone number, no item, no token, no
  proof of payment, no secret.

## Clients

- **Customer Web** — `VITE_PAYMENT_MODE=api` (needs the checkout on the backend). `src/payment/api/apiPayment.ts`,
  `src/payment/api/providerLauncher.ts`, `src/pages/BackendPaymentPage.tsx`. Admin: Payments page and the System
  page's payment card on backend data.
- **Android** — `--dart-define=PAYMENT_MODE=api`. `lib/payment/api_payment.dart`,
  `lib/payment/provider_launcher.dart`, `lib/state/payment_backend_state.dart`,
  `lib/screens/payment_backend_screen.dart`.
- With the development provider both show a clearly labelled development sheet in the provider's place.
- Without `api` mode both still run the earlier development payment (used by unit tests).

## Running locally

- `PAYMENT_DRIVER=fake` in `backend/.env`.
- Provider events are handled on the queue: `php artisan queue:work redis --queue=payments`. Without a worker the
  sweep handles them a minute later, and a status read reconciles inline.
- The scheduler is not running locally by default: `php artisan payments:reconcile` runs one sweep.
- A webhook from Razorpay needs a public HTTPS address (the documentation names a tunnel). **None is set up.**

## Tests

Backend: `tests/Feature/Payment` — initialization, verification, webhooks, reconciliation, retry and idempotency,
the Razorpay client (HTTP faked), the admin API, the OpenAPI contract, security, performance. Web:
`src/payment/api/apiPayment.test.tsx`, `src/admin/pages/BackendPaymentsPage.test.tsx`. Android:
`test/api_payment_test.dart`, `test/payment_backend_screen_test.dart`. End to end on the local backend with the
development provider: `docs/local-review/test-results/m30-*.json`.

## Not in this module

Refunds · settlements and payouts · saved cards / tokens / mandates ·
EMI, pay-later and international cards as product features · cash at pickup (not approved) · iOS · live mode ·
an outbox for domain events · a public webhook address · provider IP allow-listing.
