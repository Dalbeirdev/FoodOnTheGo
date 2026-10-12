# Refunds (Module 35) — refunds, the cancellation's financial side, provider refund execution, reconciliation, dispute foundation

Built 2026-10-11 / 12, local only. Laravel 13.35 / PHP 8.3 / PostgreSQL 18 (the spec says 12 / 16 — CF-268).

A refund is a **financial record of its own**. It belongs to one verified payment attempt and, when that payment became
an order, to the order. It never rewrites either: the payment stays `VERIFIED`, the order keeps its state, its prices and
its tax lines. Three states exist side by side and are never inferred from each other:

| | Example 1 | Example 2 | Example 3 | Example 4 |
|---|---|---|---|---|
| Order | `REJECTED` | `REJECTED` | `REJECTED` | `COMPLETED` |
| Payment | `VERIFIED` | `VERIFIED` | `VERIFIED` | `VERIFIED` |
| Refund | `REQUIRED` | `PROCESSING` | `REFUNDED` | `REFUNDED` (a part) |
| Order's derived refund status | `REQUIRED` | `PROCESSING` | `REFUNDED` | `PARTIALLY_REFUNDED` |

A rejected order is **not** a refunded order. Nothing is `REFUNDED` before the provider's own record says so.

**What is real and what is not.** The refund domain, its APIs and all four clients run on this machine against the
DEVELOPMENT (fake) payment provider — no money moves. The Razorpay adapter is implemented against Razorpay's documented
API and tested with the HTTP layer faked; it has **never been exercised against Razorpay** (no account, no test keys on
this machine — CF-404). `RAZORPAY TEST REFUNDS = PENDING PROVIDER VERIFICATION`. Live mode stays refused in code.

## Provider documentation reviewed

Reviewed on **2026-10-11** on razorpay.com/docs (REST API v1; no SDK is used — the adapter speaks HTTP):

| Topic | What the documentation says, and what the adapter does with it |
|---|---|
| Create a refund | `POST /v1/payments/{id}/refund` — `amount` in currency subunits (optional: all of it when omitted — the adapter always sends it), `speed` (`normal` / `optimum` — `normal` is sent: back to the method that paid), `notes` (≤ 15), `receipt` |
| Idempotency | `receipt` is unique per payment ("Duplicate receipt found …" on a second one). Header `X-Refund-Idempotency` (≥ 10 characters of letters, digits, `-`, `_`): the same key with the same body returns the same refund; with another body, or while the first request is still in progress, 409. A validity period is not stated |
| Statuses | `pending` (accepted, with the bank), `processed` (completed), `failed`. A status the adapter does not know concludes nothing |
| Eligibility | Only `captured` payments; some methods refund in full only; never more than was captured |
| Refusals (HTTP 400) | amount greater than captured · already fully refunded · not enough account balance · payment not captured · method / partial refund not supported · refunds not enabled · blocked by a dispute investigation · duplicate receipt · another operation in progress |
| After the fact | A refund cannot be cancelled or reversed once issued. Provider fees of the captured payment are not returned on a normal refund (accounting treatment: Module 36) |
| Lookups | `GET /v1/payments/{id}/refunds/{refund_id}`, `GET /v1/payments/{id}/refunds` |
| Webhooks | `refund.created`, `refund.processed`, `refund.failed` (`refund.speed_changed` is not handled). The payload carries the refund and the payment entity — the payment's e-mail and phone are never stored. Signature: `X-Razorpay-Signature` = HMAC-SHA256 of the raw body; `X-Razorpay-Event-Id` identifies a delivery; order is not guaranteed |
| Disputes | Events `payment.dispute.created / under_review / action_required / won / lost / closed`; entity `disp_…` with `payment_id`, `amount`, `amount_deducted`, `reason_code`, `respond_by`, `status` (`open`, `under_review`, `won`, `lost`, `closed`), `phase`; `GET /v1/disputes/{id}` |
| Timing | The documentation gives different numbers of working days in different places. **No number of days is promised to a customer anywhere** |
| Rate limits | HTTP 429, with backoff recommended; no figures published |
| Test mode | Refund behaviour in test mode is not described in detail — to be observed when test keys exist |

## Domain

| Table | What it holds |
|---|---|
| `refunds` | One refund: the payment (required), the order (when there is one), copied references (order number, payment id, restaurant name, market), the provider's name / mode / payment reference / refund reference / status word / our receipt, type, requested and approved amount, currency, status, version, reason code + detail + note, who asked, the policy's decision, who approved, failure and review reasons, counters, timestamps |
| `payment_refund_balances` | One row per payment that has (or had) a refund: `paid`, `reserved`, `refunded`, `charged_back`. The lock of every refund change — and `CHECK (reserved + refunded <= paid)` |
| `refund_attempts` | Every question asked of the provider (`CREATE`, `LOOKUP`, `LIST`) with its idempotency key, the outcome, the provider's status and reference, the failure code and the latency. Completed once, never removed |
| `refund_events` | The refund's timeline: every step with its source and who caused it. Append-only |
| `payment_disputes` | A dispute / chargeback as the provider reported it. Written only from verified provider events |

`order_refunds` (the Module 31 foundation) was carried over into `refunds` and dropped. `orders.refund_status` is now
**derived** (`RefundProjection`): `NONE`, `REQUIRED`, `PROCESSING`, `PARTIALLY_REFUNDED`, `REFUNDED`, `FAILED`,
`REVIEW_REQUIRED`. `REQUIRED` means the refund is owed and the provider does not have it yet — also when it is approved or
queued for sending; `PROCESSING` starts only when the provider has the refund (or may have it: an unanswered submission).
**A customer is never given the words `FAILED` or `REVIEW_REQUIRED`:** every customer answer (the order, the order list,
the refund block, the timeline, the event stream, the customer's channel) says `UNDER_REVIEW` instead
(`OrderRefundStatus::forCustomer()`); the restaurant and the administrator read the internal words. Money is integer
minor units with an ISO currency everywhere; there is no float.

### Refund status (`RefundStatus`, changed only by `RefundStateMachine`)

```
REQUIRED ──┐                                        ┌──► REFUNDED          the provider confirmed it — final
REQUESTED ─┴─► APPROVED ─► SUBMITTING ─► PROCESSING ┼──► FAILED            the provider refused / failed it — a new try needs a person
     │             │            │                   └──► REVIEW_REQUIRED   a person decides
     │             │            └──► SUBMISSION_UNKNOWN ─► looked up at the provider, never guessed
     └─────────────┴──► CANCELLED                                          turned down before anything was sent — final
```

| Status | Meaning | Holds the amount? |
|---|---|---|
| `REQUIRED` | A system condition owes money (a rejected / cancelled paid order). Nobody approved it yet | yes |
| `REQUESTED` | A person asked for it. Nobody approved it yet | yes |
| `APPROVED` | Approved by the policy or by a person; not sent yet | yes |
| `SUBMITTING` | Being sent right now | yes |
| `SUBMISSION_UNKNOWN` | Sent, and no answer came: the provider may or may not hold it | yes |
| `PROCESSING` | The provider holds it and has not confirmed it (Razorpay `pending`). The specification's `PENDING` is this state | yes |
| `REFUNDED` | Confirmed by the provider. **Terminal** | moved to "refunded" |
| `FAILED` | Definitively refused or failed. **Not terminal**: a person may try again (a new provider refund) or close it | no — the amount is free again |
| `CANCELLED` | Turned down or closed. **Terminal** | no |
| `REVIEW_REQUIRED` | Cannot go on by itself | yes |

The provider's own word (`refunds.provider_status`) is kept beside the status and never read by business logic.
`REFUNDED` has one door (`RefundStateMachine::adopt`): the provider's record of **this** refund — the same payment, the
same amount and currency, our receipt. The database adds `CHECK`s: a `REFUNDED` row has the provider's reference and
`completed_at`; nothing that was not approved is `SUBMITTING` or later; one provider refund belongs to one row.

## Architecture

```
Order / payment condition          a paid order is rejected or cancelled · an administrator asks
        ↓
RefundRequired / refund request    OrderWorkflowService::closeOut → RefundService::requireForOrder (same transaction)
        ↓
RefundEligibilityService           paid? verified? order over? dispute? what is left?
        ↓
RefundPolicyService                AUTO_APPROVE · MANUAL_REVIEW · NOT_ELIGIBLE
        ↓
Refund                             REQUIRED / REQUESTED → APPROVED          (amount held on payment_refund_balances)
        ↓
PaymentProvider                    createRefund(receipt = idempotency key)   RefundSubmissionService
        ↓
Razorpay test refund               (documented API; not exercised — no credentials)   ·   fake provider locally
        ↓
Provider status                    processed · pending · failed · no answer
        ↓
Webhook / reconciliation           Module 30 webhook door → RefundReconciliationService   ·   refunds:reconcile
        ↓
Refund state                       REFUNDED · FAILED · REVIEW_REQUIRED       RefundStateMachine::adopt
        ↓
Customer / admin / notifications   order's derived refund status · timeline · realtime notice · Module 33 notices
```

## Eligibility (`RefundEligibilityService`)

Decided from the records, never from a request:

1. the order was **paid** — a zero-total order (`PAYMENT_NOT_REQUIRED`) never has a refund and no provider is asked (`refund_not_eligible`);
2. its payment is **VERIFIED** and the provider's payment reference is known (`refund_payment_not_verified`);
3. the currency is the payment's (`refund_currency_mismatch`);
4. the order is **over** — rejected, cancelled, picked up or completed. An order that is still being made is ended first, and ending a paid order creates its refund (`refund_order_active`);
5. no dispute is open on the payment (`refund_dispute_conflict`);
6. something is left: `paid − refunded − in flight − charged back` (`refund_already_processing` / `refund_already_completed`).

A payment that never became an order can be refunded in two cases only, and the reason is read from the payment, not
chosen: `VERIFIED` with fulfilment `RECOVERY_REQUIRED` (`PAYMENT_ORDER_FAILURE`), or `REVIEW_REQUIRED` with reason
`DUPLICATE_PAYMENT`. Once such a refund exists the payment can no longer become an order (`ORDER_PAYMENT_REFUNDED`). A
captured payment of a **wrong amount** stays a case for a person (not refundable here — CF).

## Amounts and the balance

- The amount is the server's. `FULL` = what is left of the payment — not blindly the original total. `PARTIAL` = the
  caller's wish, checked: a positive whole number of minor units, the payment's currency, not more than what is left.
- Every refund change takes locks in one order — **the order's row, then the payment's balance row, then the refund** —
  and moves the amount between `reserved` and `refunded` in the same transaction.
- In flight and unknown refunds **reserve**: `REQUIRED`, `REQUESTED`, `APPROVED`, `SUBMITTING`, `SUBMISSION_UNKNOWN`,
  `PROCESSING`, `REVIEW_REQUIRED`. Only `FAILED` and `CANCELLED` give the amount back.
- ₹500 paid, ₹400 and ₹300 asked at once: both wait for the balance row; the second finds ₹100 left and is refused
  (`refund_amount_exceeds_available`). And whatever the code is asked, `CHECK (reserved + refunded <= paid)` refuses it.

## Policy (`RefundPolicyService`, `config/refunds.php`)

| Outcome | When |
|---|---|
| `NOT_ELIGIBLE` | The records say the money cannot go back as things stand (e.g. a dispute is open) → the refund becomes `REVIEW_REQUIRED` |
| `AUTO_APPROVE` | A refund the **system** owes (`REQUIRED`) whose reason is in `REFUND_AUTO_APPROVE_REASONS`, within `REFUND_AUTO_APPROVE_MAX_MINOR` → approved, queued, sent |
| `MANUAL_REVIEW` | Everything else — every refund a person asked for and every reason nobody approved |

**AUTO-REFUND POLICY = PENDING BUSINESS APPROVAL.** `REFUND_AUTO_APPROVE_REASONS` is empty by default: every refund
waits for a person with `admin.refunds.approve`. The mechanism is built and tested; switching it on is a decision.
`REFUND_FOUR_EYES=true` additionally refuses an approval by the administrator who asked.

## Cancellation and its financial effect

```
Customer / restaurant / admin
        ↓
Cancellation request               customer: POST /customer/orders/{order}/cancel · restaurant: reject · admin: POST /admin/orders/{order}/cancel
        ↓
Order state + policy evaluation    under the order's row lock
        ↓
Cancellation allowed?              no → 409 (order_cancellation_not_allowed / order_invalid_transition)
        ↓
Order CANCELLED (or REJECTED)      cancelled_at · cancelled_by_type · cancellation_reason_code · audit · pickup place released · credential revoked
        ↓
Financial effect
        ├── no refund              the order was not paid, or the approved policy says none is due
        ├── full refund            a refund REQUIRED for what is left of the payment
        ├── partial refund         only as an administrator's refund request (no fee rule exists)
        └── manual review          a refund REQUESTED: a person decides
```

| Who | What exists | Financial effect |
|---|---|---|
| Restaurant | Rejects a waiting order (Module 31). No "refund" button and no refund permission | Paid → one refund `REQUIRED` for what is left (`RESTAURANT_REJECTED` + the rejection reason) |
| Administrator | Cancels an active order with a written reason and a reason code (`ADMIN_CANCELLATION`, `RESTAURANT_CANCELLATION`, `CUSTOMER_CANCELLATION`, `ORDER_CANNOT_BE_FULFILLED`, `ITEM_UNAVAILABLE`, `PICKUP_CAPACITY_FAILURE`) | Paid → one refund `REQUIRED` with that reason |
| Customer | **DISABLED — PENDING BUSINESS / LEGAL APPROVAL.** `GET …/cancellation` always answers (enabled = false); `POST …/cancel` answers 409 | When a policy is switched on: its `allowed_statuses` and `financial_effect` (`FULL_REFUND`, `NO_REFUND`, `MANUAL_REVIEW`) decide — never the client |

No rule such as "cancel any time before preparation" is built in. A completed or picked-up order is never turned into
a cancelled one: service recovery is a refund, and the order stays `COMPLETED`. Promotions are not given back after a
refund; no loyalty credit and no store credit exist; the order's tax lines are not touched (a tax credit note is a
legal design nobody has made).

## Sending a refund (`RefundSubmissionService`)

1. Under the locks: `APPROVED → SUBMITTING`; the receipt of this provider refund is fixed
   (`rf-{refund id without dashes}-{generation}` — also the idempotency key); the attempt is written. Committed.
2. Outside any transaction: one provider call with the payment timeout (`PAYMENT_TIMEOUT`, 15 s). Never repeated here.
3. Under the locks again, what came back becomes the state:

| The provider … | Refund |
|---|---|
| returns the refund `processed` / `pending` / `failed` | `REFUNDED` / `PROCESSING` / `FAILED` (retryable) |
| refuses it (method, partial, not captured, refunds disabled, too old …) | `FAILED`, with a safe reason; "not enough balance" is retryable |
| says more is asked than is left / already refunded / a dispute blocks it | `REVIEW_REQUIRED` — the provider's books and ours disagree |
| does not answer (timeout, 5xx), or answers "duplicate receipt" / "in progress" / 409 | `SUBMISSION_UNKNOWN` — it may exist. Looked up; never sent again blindly |
| certainly did not carry it out (429, key refused, provider not usable) | back to `APPROVED`, sent again later with the **same** key (60 s, 5 min, 15 min, 1 h), then `REVIEW_REQUIRED` |

One submission at a time per refund (a cache lock), `tries = 1` on the job, and one key per provider refund: two
workers, a double click or a retry after a timeout cannot make two refunds. A retry of a `FAILED` refund is a **new**
provider refund (the next generation, a new receipt and key) and holds its amount again first.

## Reconciliation (`RefundReconciliationService`, `php artisan refunds:reconcile` every minute)

- The provider's reference is known → that refund is read. Only our receipt is known → the payment's refunds are listed
  and ours is found by its receipt.
- Found → adopted (`PROCESSING`, `REFUNDED`, `FAILED`) after it was checked to be this refund. A mismatch (another
  payment, amount, currency, receipt, or a reference already used) is never attached: `REVIEW_REQUIRED`.
- Not found, and the submission is certainly over → the **same** request goes again (at most
  `REFUND_MAX_UNKNOWN_RESUBMITS`, default 2), then `REVIEW_REQUIRED` (`SUBMISSION_UNRESOLVED`).
- The provider cannot be asked → nothing is concluded. An outage never makes a refund "failed".
- Waits: 1 min, 5 min, 15 min, 1 h, 4 h, 12 h, then daily for ten days; when they are used up `REVIEW_REQUIRED`
  (`RECONCILIATION_EXHAUSTED`) — the amount stays held, and a late confirmation still completes it.
- The same command applies the policy to refunds it never looked at and sends approved refunds that are due.

## Webhooks

Refund and dispute events come through the **Module 30 webhook endpoint** (`POST /webhooks/payments/{provider}`): the
signature over the raw body is the only credential, an event is stored once however often it is delivered, the raw
payload is never stored, and processing is queued with the same sweep. A refund event never sets a state: it points at
the refund (by the provider's reference, or by our receipt when the reference never reached us) and the provider is
asked. So duplicates make one transition, a late `refund.created` cannot undo a `REFUNDED`, a webhook may arrive before
the answer to our request, and a refund completes from the webhook alone when nobody is there to see the answer.
A refund event for a refund FoodOnTheGo did not make (issued in the provider's dashboard) is flagged on the payment's
timeline and records nothing as refunded (recording an external refund is a controlled process nobody has defined — CF).

## Disputes / chargebacks — foundation only

A dispute is started by a customer's bank. **It is not a refund.** `payment_disputes` is written only from verified
provider events (the provider is asked for the current record; a decided dispute is never re-opened by a late event) and
is read-only: `GET /admin/disputes`, `GET /admin/disputes/{dispute}` (`admin.disputes.view`). Two consequences for
refunds, and only these: while a dispute is open no refund of that payment is asked, approved or sent
(`refund_dispute_conflict` / `REVIEW_REQUIRED: DISPUTE_OPEN`), and what a **lost** dispute took lowers what can still be
refunded. Not built: evidence upload, accept / contest, deadlines as tasks, any customer-facing view.

## API

### Customer (`auth:customer`) — refunds are read through the order

| | |
|---|---|
| `GET /customer/orders/{order}` | `refund_status` (derived) and `refund`: `status`, `paid`, `refunded` (provider-confirmed only), `pending`, `items[]` (`id`, `reference` `RFD-…`, `type`, `amount`, `status` ∈ `REQUIRED` / `PROCESSING` / `REFUNDED` / `UNDER_REVIEW`, `reason {code, label}`, `requested_at`, `completed_at`), `timing_note: null`. `customer_cancellation {enabled, can_cancel, reason}` |
| `GET /customer/orders/{order}/cancellation` | The quote: `enabled`, `policy_status`, `can_cancel`, `reason`, `financial_effect`, `refundable_amount`, `reasons` |
| `POST /customer/orders/{order}/cancel` | `reason_code` (+ `note`, `expected_version`). 409 `order_cancellation_not_allowed` while disabled or for a state the policy does not allow. No amount, effect or status can be sent |

A customer never sees a provider reference, who approved, or why a refund failed; a request nobody approved and a
cancelled refund are not listed. For a customer a refund is `REQUIRED` ("refund due") until the provider has it — approving
it changes nothing they are told — then `PROCESSING`, then `REFUNDED`; a refund that failed or needs a person is
`UNDER_REVIEW`, and so is the order's `refund_status`. Somebody else's order is 404.

### Restaurant (`auth:restaurant`)

The order answers carry `refund_status` and `refund {status, amount, refunded, controls: "NOT_PROVIDED"}`. There is no
refund route and no refund permission for a restaurant.

### Administrator (`auth:admin`, market scope enforced by the server)

| Route | Permission | |
|---|---|---|
| `GET /admin/refunds`, `/refunds/queues`, `/refunds/{refund}` | `admin.refunds.view` | List with filters (`status`, `queue`, `type`, `reason`, `provider`, `restaurant`, `market`, `review`, `from`, `to`), search by exact references (refund id, `RFD-…`, order number, payment id, `PAY-…`, provider refund reference), the queues (Required · Awaiting approval · Approved · Processing · Failed · Review required · Completed · Cancelled), the record with balance, provider detail, attempts, timeline, provider events, audit, disputes, allowed actions |
| `POST /admin/orders/{order}/refunds` | `admin.refunds.create` | Ask: `type` `FULL` / `PARTIAL`, `amount_minor`, `reason_code`, `note`. `Idempotency-Key` supported |
| `POST /admin/payments/{payment}/refunds` | `admin.refunds.create` | Ask for the refund of a payment without an order (`note` only) |
| `POST /admin/refunds/{refund}/approve` · `/reject` | `admin.refunds.approve` | Reject needs a reason |
| `POST /admin/refunds/{refund}/execute` · `/retry` | `admin.refunds.issue` | The only actions that reach the provider |
| `POST /admin/refunds/{refund}/reconcile` | `admin.refunds.reconcile` | Asks the provider again, with a reason |
| `GET /admin/system/refunds` | `admin.system.view` | Counts, queues, provider refund health, disputes, policy switches |
| `GET /admin/disputes`, `/disputes/{dispute}` | `admin.disputes.view` | Read only |

`FINANCE_ADMIN` holds all of them; `OPERATIONS_ADMIN` and `SUPPORT_ADMIN` hold `admin.refunds.view` only (whether
support may ask for refunds is the owner's decision). **There is no "mark as refunded"**, no status editor, and every
field that would set a status, an amount, a currency, a payment or a provider reference is refused with 422. Every
decision is audited (`refund.requested / approved / rejected / executed / retried / reconciled`, `order.cancelled`).

### Development only (never registered in production)

`POST /dev/refunds/{refund}/simulate` and `POST /dev/payments/{payment}/dispute` play the FAKE provider's side — the bank
finishing a refund, a dispute moving on — by changing the fake provider's record and sending its signed webhook through
the real path. They cannot set a refund's status.

### Error codes

`refund_not_found` · `refund_not_eligible` · `refund_payment_not_verified` · `refund_order_active` ·
`refund_amount_invalid` · `refund_amount_exceeds_available` · `refund_currency_mismatch` · `refund_already_processing` ·
`refund_already_completed` · `refund_not_approved` · `refund_invalid_transition` · `refund_version_conflict` ·
`refund_submission_unknown` · `refund_failed` · `refund_review_required` · `refund_not_authorized` ·
`refund_dispute_conflict` · `order_cancellation_not_allowed` · `dispute_not_found` (lower case, as every code of this API).
Customer-facing answers never carry a provider's sentence; administrators see FoodOnTheGo's own failure text and code.

## Events, realtime and notifications

`RefundEvent` (after commit): `required`, `requested`, `approved`, `submitted`, `submission_unknown`, `processing`,
`completed`, `failed`, `cancelled`, `review_required`, `reconciled`, `over_refund_blocked`, `external_refund_seen`,
`dispute_recorded`. When what the customer is told about a refund changes, a line is added to the order's timeline
(`REFUND_REQUIRED`, `REFUND_PROCESSING`, `REFUND_COMPLETED`, `REFUND_UNDER_REVIEW`) and `order.refund_updated` is
announced, so the Module 32 realtime channels tell every client to re-read the order.

Notifications reuse Module 33 — no second engine:

| Notice | When | Says |
|---|---|---|
| `refund.pending` (v2) | A refund is owed (`order.refund_required`) | "Refund due — we are arranging it." Not "on its way", not "done" |
| `refund.initiated` | The provider holds it (`PROCESSING`) | The amount, "sent to your payment provider" |
| `refund.completed` | `REFUNDED` — only then | The amount; "when it shows on your statement depends on your bank" |
| `refund.attention` | `FAILED` / `REVIEW_REQUIRED`, once per refund | "Taking longer — our team is on it; you do not need to do anything" |

A request nobody approved, an approval and a rejection notify nobody. No notice promises a number of days. A
notification problem never touches a refund.

## Logging and metrics

Logged: refund and payment public ids, order number, amount in minor units, currency, status, provider name and refund
reference, request id, codes. Never: a key or secret, a signature, a card / UPI detail, a customer's contact details, a
provider's raw answer. `GET /admin/system/refunds`: refunds per state, the queues, amounts per currency and state
(operational — not settlement truth), event counts incl. `over_refund_blocked`, the provider's average answer time for
refund calls (counted apart from payment calls: capability `refund`), disputes, the policy switches.

## Rate limits

`RATE_LIMIT_REFUND_ACTION` (30 / min per administrator, every refund action), `RATE_LIMIT_ORDER_CANCEL` (6 / min per
customer), beside the existing `admin-sensitive` and `payment-webhook` limits.

## Clients

- **Customer Web / Android**: the order detail, tracking and order list show the order's derived refund status and each
  refund (amount, status, reference) from the backend; a failed or reviewed refund reads "under review", never "payment
  failed". A cancel action appears only when `customer_cancellation.can_cancel` is true (it is false).
- **Restaurant Dashboard**: after rejecting a paid order — "Refund required / processing / refunded", with the amounts.
  No refund controls.
- **Platform Admin**: the Refunds page on the backend (queues, filters, search, detail with attempts / provider events /
  audit, approve · reject · execute · retry · reconcile behind their permissions, a confirmation that shows order,
  payment, amount, currency and reason before a refund is sent), disputes read-only, the order and payment details
  linking to their refunds.

## Not built (stated, not hidden)

Razorpay verification against real test keys · an approved auto-refund policy · an approved customer cancellation policy
and any cancellation fee · recording a refund made outside FoodOnTheGo · refunding a captured payment of a wrong amount ·
the dispute workflow (evidence, accept / contest) · restaurant settlements, payouts, commission, provider-fee and
refund funding-source accounting, the finance ledger (Module 36) · tax credit notes · promotion restoration, loyalty or
store credit · re-authentication before a refund is sent (production hardening).

## Tests

`tests/Feature/Refund` (56 tests): `RefundLifecycleTest` (6), `RefundAmountTest` (7), `RefundProviderTest` (8),
`RefundWebhookTest` (8), `RefundSecurityTest` (7), `RefundCancellationTest` (6), `RefundNotificationAndDisputeTest` (5),
`PaymentRefundAndAdapterTest` (5), `RefundOpenApiContractTest` (3), `RefundPerformanceTest` (1) — against the FAKE
provider and, for the Razorpay adapter, a faked HTTP layer. No test sets a refund's status directly.
