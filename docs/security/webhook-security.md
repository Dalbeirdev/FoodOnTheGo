# Webhook security

Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/app/Services/Payment/PaymentWebhookService.php`, `backend/app/Http/Controllers/Api/PaymentWebhookController.php`, `backend/app/Http/Controllers/Api/PayoutWebhookController.php`, `backend/app/Services/Payment/Providers/{RazorpayPaymentProvider,FakePaymentProvider}.php`, `backend/app/Services/Payment/{PaymentProviderManager,PaymentWebhookSummary}.php`, `backend/app/Jobs/Payment/ProcessPaymentWebhookEvent.php`, `backend/app/Console/Commands/ReconcilePayments.php`, `backend/routes/api.php:252-276`, `backend/routes/console.php:28`, `backend/app/Providers/AppServiceProvider.php:288`, `backend/config/{payments,rate_limits}.php`, `backend/database/migrations/2026_10_09_100000_create_payment_tables.php:141-171`, `2026_10_19_100000_module39_security.php:51-55`, `backend/tests/Feature/Payment/{PaymentWebhookTest,RazorpayPaymentProviderTest}.php`, `backend/tests/Feature/Security/DataProtectionTest.php:274-293`, `backend/tests/Feature/Refund/RefundNotificationAndDisputeTest.php`.

> **Real Razorpay webhooks are NOT verified.** The Razorpay provider is implemented and unit-tested against
> hand-built signed bodies, but no Razorpay account, key or webhook secret is configured, and no real Razorpay
> delivery has ever reached this system. Every end-to-end webhook test ran against the local FAKE provider.
> Status: PENDING PROVIDER VERIFICATION.

## 1. Endpoints

| Route | Controller | Credential | Throttle |
|---|---|---|---|
| `POST /api/v1/webhooks/payments/{paymentProvider}` (`routes/api.php:254-255`) | `PaymentWebhookController` | the provider's signature over the raw body — no session, no bearer token | `throttle:payment-webhook`: 600 / min per client address (`AppServiceProvider.php:288`, `RATE_LIMIT_PAYMENT_WEBHOOK`, `config/rate_limits.php:67`); the general API limit is removed for this route |
| `POST /api/v1/webhooks/payouts/{payoutProvider}` (`:258-259`) | `PayoutWebhookController` → `PayoutService::webhook` | the payout provider's signature | same limiter |

Payment, refund and dispute events all arrive through the payment endpoint (Module 35 reused it).

Only the **configured** provider has an endpoint: `PaymentProviderManager::named()` returns a provider only when its
name equals `PAYMENT_DRIVER`, the provider is usable here (the fake one only where `payments.providers.allow_fake` is
true, i.e. `APP_ENV` in `local` / `testing`) and its configuration is complete (`PaymentProviderManager.php:56-61,
142-153`). Any other name answers 404 (`UNKNOWN_PROVIDER`). A Razorpay installation without `RAZORPAY_WEBHOOK_SECRET`
is "missing configuration" and therefore has no webhook endpoint.

## 2. Request path (`PaymentWebhookService::accept`)

| Step | What happens | Answer |
|---|---|---|
| 1 | Body empty → refused; body larger than `PAYMENT_WEBHOOK_MAX_BYTES` (default 64 KiB, `config/payments.php:60`) → refused (`PaymentWebhookController`) | 400 `webhook_payload_invalid` / 413 `webhook_payload_too_large` |
| 2 | Provider resolved (section 1) | 404 when unknown |
| 3 | **Signature verified over the raw body before any field is read** (`PaymentWebhookService.php:67`). Failure: a `WEBHOOK_REJECTED` event and a warning log with provider, `signature_valid=false` and the byte count — never the body | 401 `webhook_signature_invalid` |
| 4 | Body parsed into a `ProviderWebhook`; unreadable or not an event → warning log | 400 `webhook_payload_invalid` |
| 5 | Stored once (section 4); a duplicate increments `deliveries` | 200 `{received: true, duplicate: true}` |
| 6 | A new, handled event is queued for processing; an unhandled type is stored as IGNORED | 200 `{received: true}` |

The answer is fast and carries no detail beyond the codes above. The request never changes a payment, refund or
dispute itself.

### Signature verification

| Provider | Header | Algorithm | Code |
|---|---|---|---|
| Razorpay | `X-Razorpay-Signature` | HMAC-SHA256 hex of the raw body, keyed with `RAZORPAY_WEBHOOK_SECRET` (separate from the API key secret); constant-time `hash_equals`; refuses an empty secret, a missing header or anything that is not 64 hex characters | `RazorpayPaymentProvider.php:215-223` |
| Fake (local / testing only) | `x-fake-signature` | HMAC-SHA256 with a fixed development key that "protects nothing" | `FakePaymentProvider.php:142-145` |

This follows the Razorpay guidance recorded in M39 `refs.md` §7.2 (verify the raw body; dedupe on the event id) —
as documentation, not as evidence of a working integration. Behaviour after a Razorpay secret rotation (older retries
signed with the old secret) is not handled: only one secret is configured at a time. NOT VERIFIED with Razorpay.

## 3. Event types handled

Razorpay mapping (`RazorpayPaymentProvider.php:77-90`): `payment.authorized`, `payment.captured`, `payment.failed`,
`order.paid`, `refund.created`, `refund.processed`, `refund.failed`, `payment.dispute.created`,
`payment.dispute.under_review` and the other `payment.dispute.*` states. Any other type is stored as IGNORED with
outcome `EVENT_NOT_HANDLED` and acknowledged (so the provider stops retrying).

## 4. Deduplication and replay

| Layer | Rule | Where |
|---|---|---|
| Event id | unique index `(provider, provider_event_reference)`; the reference is the provider's event-id header (`x-razorpay-event-id`, ≤ 100 chars) or, when absent, `sha256:<hash of the body>` | migration `create_payment_tables.php:165`; `PaymentWebhookService.php:81-82`; `RazorpayPaymentProvider.php:241-244` |
| Signed body (Module 39) | partial unique index `(provider, payload_hash)` for events stored after the migration: the same signed body under a **new** event-id header is a duplicate. The event-id header is not covered by the signature, so this closes a replay that changed only the header. | `module39_security.php:51-55`; `PaymentWebhookService.php:84-97` |
| Duplicate handling | the first row's `deliveries` is incremented; if its processing had FAILED it is queued again; a `WEBHOOK_DUPLICATE` event is recorded | `PaymentWebhookService.php:87-96` |
| Out of order / late events | an event never sets a status itself: processing asks reconciliation to judge the attempt from the provider's state, so a late `payment.failed` cannot undo a verified payment | `PaymentWebhookService.php:25-27, 129-147` |
| Amount mismatch | a webhook naming another amount never verifies a payment | `PaymentWebhookTest::test_a_webhook_naming_another_amount_never_verifies` |

The storage runs in its own savepoint, so a duplicate (unique violation) does not break an enclosing transaction
(`PaymentWebhookService.php:170-199`). Rows stored before the Module 39 migration are not covered by the body index
(it starts after the highest id at migration time).

## 5. Processing: queue and sweep

- `ProcessPaymentWebhookEvent` runs on the `payments` queue (`PAYMENT_WEBHOOK_QUEUE`), after the storing transaction
  commits, with 5 tries and back-off 10 / 30 / 120 / 300 s (`ProcessPaymentWebhookEvent.php`).
- `process()` is idempotent: PROCESSED / IGNORED events are left alone; attempts are counted; a failure marks the row
  FAILED with the exception class name only (≤ 160 chars) and re-throws so the queue retries
  (`PaymentWebhookService.php:112-153`).
- `REFUND_*` events go to `RefundReconciliationService::fromWebhook`, `DISPUTE_*` events to
  `DisputeService::fromWebhook`; neither sets a state by itself (`:120-128`).
- An event about no payment attempt here (another integration on the same provider account) is IGNORED with
  `NO_MATCHING_PAYMENT`.
- **Sweep**: `payments:reconcile` (every minute, `routes/console.php:28`) calls `sweep()`, which re-queues events
  still RECEIVED or FAILED after `PAYMENT_WEBHOOK_SWEEP_AFTER_SECONDS` (default 60), up to 200 per run and 20
  processing attempts per event (`PaymentWebhookService.php:158-168`). Nothing accepted is lost if a worker dies.

A queue worker and the scheduler must run for webhooks to have any effect (deployment item).

## 6. What is stored

`payment_webhook_events` (`create_payment_tables.php:141-171`, later columns from Module 35):

| Stored | Not stored |
|---|---|
| provider, event reference, event type, normalised type | **the raw payload** |
| `payload_hash` (SHA-256 of the body) | headers, signature, source address |
| provider order / payment / refund / dispute references | customer name, phone, e-mail, address |
| `summary` — safe fields only (`PaymentWebhookSummary::of`): payment reference, order reference, state, provider status, amount, currency, method type, method summary, error code / reason / source; refund and dispute summaries | card number, CVV, full VPA, bank account |
| processing status, attempts, deliveries, outcome, failure class name, timestamps | |

The method summary holds at most: card network, card type, last 4 digits, international flag; a **masked** UPI
handle and flow; a bank code; a wallet name (`RazorpayPaymentProvider.php:583-597`). This matches the RBI card-on-file
rule recorded in `refs.md` §10.4 (last four digits and issuer only). Test:
`PaymentWebhookTest::test_no_raw_payload_and_no_customer_contact_details_are_kept_of_a_webhook`.

Rows are never deleted (retention PENDING — `docs/security/data-retention.md`).

## 7. Tests (fake provider unless stated)

| Test | Proves |
|---|---|
| `PaymentWebhookTest` (`tests/Feature/Payment`) — 17 tests | a signed webhook alone verifies a payment; an invalid signature is rejected and changes nothing; the signature is checked over the raw body as it arrived; a customer session is neither needed nor useful; the same event twice is stored once and acts once; an event without an id is deduplicated by content; out-of-order events cannot take a settled payment back; webhook-first or callback-first verifies once; a webhook with another amount never verifies; unhandled / foreign events are acknowledged and ignored; bodies that cannot be events are refused; only the configured provider has an endpoint; the request only stores, the queue does the work; the sweep picks up unfinished events; no raw payload or contact details are kept |
| `RazorpayPaymentProviderTest::test_a_webhook_is_verified_over_its_raw_body_with_the_webhook_secret` (`:243`) | Razorpay HMAC verification with a test secret on hand-built bodies (unit level; **not** a real Razorpay delivery) |
| `DataProtectionTest::test_a_replayed_webhook_body_under_a_new_event_id_is_a_duplicate` (`:274-293`) | Module 39 body dedupe: one signed body = one event, `deliveries = 2` |
| `RefundNotificationAndDisputeTest` (`:144`, `:201`) | disputes are recorded from verified provider events and block refunds of that payment |
| `RefundLifecycleTest`, `PaymentRefundAndAdapterTest` (`tests/Feature/Refund`) | refund events through the same door |

## 8. Open items

| # | Item | Status |
|---|---|---|
| W-1 | Real Razorpay webhook delivery, signature and event shapes | NOT VERIFIED — no credentials; PENDING PROVIDER VERIFICATION |
| W-2 | Webhook secret in a secret manager; rotation procedure (two secrets during a rotation window) | DEPLOYMENT / OPEN |
| W-3 | Provider IP allow-listing at the edge (Razorpay recommends it, `refs.md` §7.3) | DEPLOYMENT |
| W-4 | HTTPS with TLS 1.2+ on the public endpoint | DEPLOYMENT |
| W-5 | Answer within the provider's timeout (Razorpay: 5 s) under load — not load-tested | OPEN |
| W-6 | Alert on bursts of `WEBHOOK_REJECTED` (forgery attempts) and on FAILED events | OPEN (monitoring, Modules 40–42) |
| W-7 | Retention of `payment_webhook_events` | PENDING BUSINESS APPROVAL |
| W-8 | Payout provider webhooks: only the fake payout provider exists; live payouts are disabled | NOT VERIFIED |
