# Realtime order tracking, the order event stream, secure pickup verification, order completion (Module 32)

Local only. Nothing here has touched production. The payment provider on this machine is still the DEVELOPMENT
provider (Module 30); no money exists.

## The rules

- **PostgreSQL is the order.** The realtime layer tells a client "something changed, here is the new version"; it
  never carries the order and is never believed as the state. A client that hears READY, PICKED_UP or COMPLETED
  reads the order (or its event stream) from the API before it shows anything as true.
- **Every change is a persistent event.** Each state change writes one line of the order's append-only timeline
  (`order_events`) with a per-order `sequence` (1, 2, 3 …, unique per order) and is announced after its commit —
  never for a change that rolled back.
- **Delivery is not exactly-once.** A notice may arrive twice, late, out of order or not at all. Clients keep the
  order's `version` and the last `sequence` they applied: an older notice is ignored, a gap makes them read the
  stream again, a reconnection always resyncs.
- **Transport hierarchy:** WebSocket (Laravel Reverb) → REST resync (`GET order`, `GET events?after_sequence`) →
  polling. SSE is optional by design and not implemented. A WebSocket outage stops nothing: orders, steps, pickup
  verification and completion are REST.
- **Pickup credential:** high-entropy, opaque, single-use, verified on the server by authorized restaurant staff at
  the order's location, only when the order is READY. The order number is not a credential, the phone is not a
  credential, the QR carries no PII and no payment data. A customer cannot mark their own pickup; there is no
  administrative "mark picked up".
- **PICKED_UP and COMPLETED are two states.** Verification moves READY_FOR_PICKUP → PICKED_UP; completion (V1:
  automatic, its own transaction) moves PICKED_UP → COMPLETED. Both lines stay on the timeline. A completion that
  fails leaves the order PICKED_UP and the credential consumed; the sweep completes it later.

## Architecture

```
Order state change (OrderWorkflowService / PickupVerificationService / OrderCompletionService / fulfilment)
      │  one transaction, the order row locked
      ▼
order_events line (sequence n) · orders.version + 1
      │  commit
      ▼
App\Events\Order\OrderEvent (after commit)            ──► RecordOrderEvent (log line, ids / states / codes)
      │                                                ──► Module 33 (notifications) later
      ▼
BroadcastOrderUpdate (listener)
      │  reads the committed order once, builds two minimal payloads
      ▼
OrderUpdated (ShouldBroadcastNow, Reverb driver, timeout 2 s, failure counted — never thrown)
      ├── private-customer.order.{order public id}        → Customer Web, Android
      └── private-restaurant.location.{location public id} → Restaurant Dashboard (every staff member of the location)

If the socket is away:  GET /customer/orders/{order}  ·  GET /customer/orders/{order}/events?after_sequence=n
                        GET /restaurant/orders?location=…  ·  GET /restaurant/orders/{order}/events?after_sequence=n
                        polling (8 s while the order is on its way, 30 s otherwise; the dashboard 20 s, 60 s safety read)
```

Outbox: Modules 30 / 31 did not build one (CF-414). The broadcast is after-commit and synchronous; a failure is
counted (`broadcast_failed`) and logged, and clients recover through REST. The order is correct whatever the
broadcaster did.

## Channels and authorization

| Channel | Who | How |
|---|---|---|
| `private-customer.order.{order id}` | the customer who owns the order | `POST /api/v1/customer/broadcasting/auth` (guard `customer`, bearer token) → `routes/channels.php` checks `orders.customer_id` |
| `private-restaurant.location.{location id}` | staff with an ACTIVE membership reaching the location and `restaurant.orders.view` | `POST /api/v1/restaurant/broadcasting/auth` (guard `restaurant`) → `RestaurantAccess::authorize` |

- No public order channel exists; the auth endpoints accept only these two private names (422 otherwise).
- A channel name is an address, never a permission: every subscription is authorized on the server. Refusals are
  counted (`channel_auth_denied`) and answered 403 `channel_forbidden`; a gone session is 401.
- Clients re-authorize on every subscription and every reconnection, so a signed-out customer, a suspended or
  revoked member, or one who lost a location cannot subscribe again. **Limit (CF):** an existing subscription of a
  member revoked meanwhile stays open until the socket drops or reconnects; the payload is a notice only, and the
  REST reads it triggers are refused at once. Reverb has no per-user termination in this version.
- Allowed origins: `REVERB_ALLOWED_ORIGINS` = host names (Reverb matches the Origin header's host, without scheme or
  port: `localhost,127.0.0.1` locally). Never `*`. Clients may not publish (`accept_client_events_from = none`).
- Channel authorization is rate-limited (`channel-auth`, 60 / minute per principal).

## The notice (`order.updated`)

OpenAPI `OrderUpdatedBroadcast`: `schema_version`, `order_public_id`, `order_number`, `version`, `status`,
`event_type` (the domain event name, e.g. `order.restaurant_accepted`), `event_sequence`, `event_id`
(`<order id>-<sequence>`), `payment_state`, `refund_status`, `pickup_at`, `ready_at`, `occurred_at`; the customer's
copy adds `message_key` (`order.accepted` … — a key, never copy), the restaurant's adds `item_count` and the pickup
slot. Never a phone, a name, an item, an amount, a payment reference, a journey, a note, a staff member, a token or a
code. A client that meets an unknown `event_type` ignores it and reads the order.

Client rule: `version < known && sequence ≤ known` → ignore; `sequence == known + 1` → read the order (only the new
lines are delivered to the screen); anything else → read the order (the gap is filled from the API). Reconnection,
a tab coming back, a network change, an Android resume → read the order.

## The event stream

`GET /customer/orders/{order}/events?after_sequence=n` (customer-visible lines) and
`GET /restaurant/orders/{order}/events?after_sequence=n` (every line of an order of the caller's locations). Bounded
to 200 lines; the answer carries the order's `status` and `version` and the realtime hints (channel, event, auth
endpoint, poll seconds, terminal), so one call is a full resync. Internal lines (`PICKUP_VERIFIED`, recovery /
security lines) are never in the customer's stream.

## Pickup credential

| | |
|---|---|
| Model | `order_pickup_credentials`: a history per order, ONE ACTIVE (partial unique index), `public_id`, `token_hash` (SHA-256, unique), `token_secret` (encrypted), `display_code` (encrypted), `display_code_hash` (SHA-256 of `<order public id>:<code>`), `format_version`, `status` ACTIVE / CONSUMED / REVOKED / EXPIRED, `issued_at`, `expires_at`, `consumed_at`, `consumed_method`, `consumed_by_restaurant_user_id`, `revoked_at`, `revoke_reason`, `failed_attempts`, `locked_until` |
| QR payload | `FOTG1.` + 43 base64url characters = 32 bytes from the CSPRNG (256 bits). Opaque: no order, customer, amount, payment, restaurant. Rendered client-side (web `qrcode`, Android `qr_flutter`), error correction M, with the code below it. |
| Raw secret stored | Encrypted at rest (Laravel's encrypter) next to its hash — the "secure encrypted snapshot" the spec allows, so the same QR renders on every visit and device. Lookup and comparison use the hash only (`hash_equals`). Justified: the alternative (client-side persistence) fails the multi-device and restart cases. |
| Manual code | 6 characters from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no 0/O/1/I), random, never derived from the order number or the phone; stored encrypted with a lookup hash bound to the order, so a code is only ever tried against ONE order (the restaurant enters the order number with it). |
| Created | With the order (Module 31), shown on the confirmation / tracking / details screens; prominent when READY. Verification is refused before READY. |
| Expiry | Pickup time + 24 h (config) — protects orders nobody collected; a late customer is not cut off at the minute. Terminal order states revoke it. |
| Rotation | `POST /customer/orders/{order}/pickup-credential/reissue` (own active order, 3 / hour / order): the old row is REVOKED (`ROTATED`), a new one issued; the timeline says `PICKUP_CREDENTIAL_REISSUED`; a security event is recorded. Module 31 credentials (no secret kept) are rotated on the next customer read. |
| Reading | `GET /customer/orders/{order}/pickup-credential` and inside `GET /customer/orders/{order}` (`pickup_credential`), `Cache-Control: private, no-store`. Never to the restaurant or an administrator. |

## Verification

`POST /api/v1/restaurant/pickup/verify` — `{location, method: QR, payload}` or
`{location, method: MANUAL_CODE, order_number, code}`; `restaurant.pickup.verify` at `location` (OWNER, MANAGER,
ORDER_STAFF; never VIEWER / MENU_MANAGER); throttle `pickup-verify` (30 / minute per staff member) plus, inside the
service, 120 / minute per location and a per-credential failure counter (5 wrong codes → locked 15 minutes).

```
parse (bounded, format-versioned; malformed / foreign / Module 31 placeholder = invalid)
→ find the credential by token hash (QR) or the order (number, within the caller's locations) + code hash (manual)
→ TRANSACTION: lock the order (FOR UPDATE), lock the credential (FOR UPDATE)
   · the staff member reaches the order's location?            no → 422 pickup_credential_invalid (nothing revealed)
   · the order's location is the scanner's `location`?         no → 403 pickup_location_unauthorized (own organization: the location is named)
   · restaurant.pickup.verify there?                            no → 403
   · credential locked (too many wrong codes)?                  yes → 429 pickup_attempts_exceeded
   · secret / code matches (hash_equals)?                       no → 422 pickup_credential_invalid (+1 failure, outside the transaction)
   · credential CONSUMED?                                       yes → 409 pickup_already_verified {order_number, status, verified_at, verified_by_you}
   · REVOKED?  EXPIRED (or past expires_at)?                    → 409 pickup_credential_revoked / pickup_credential_expired
   · order PICKED_UP / COMPLETED?                               → 409 pickup_already_verified
   · order REJECTED / CANCELLED?                                → 409 order_not_pickup_eligible
   · order not READY_FOR_PICKUP?                                → 409 order_not_ready_for_pickup
   · consume (CONSUMED, consumed_at = server time, method, staff) → order PICKED_UP (version + 1, picked_up_at)
     timeline: CUSTOMER_PICKED_UP (customer-visible, method) + PICKUP_VERIFIED (internal: credential, location, staff)
     attempt row SUCCESS
→ COMMIT → broadcast → OrderCompletionService (its own transaction) → COMPLETED (ORDER_COMPLETED, trigger AUTO_AFTER_PICKUP)
```

Every attempt — successful or refused — is a row of `order_pickup_verification_attempts` (order, credential, staff,
location, method, result, request id, time; never the submitted secret or code). Refusals roll the transaction back
(nothing consumed); the failure counter and the EXPIRED mark are written outside it so that they stick. Replays and
wrong-location attempts are security events (`PICKUP_REPLAY_ATTEMPT`, `PICKUP_LOCATION_UNAUTHORIZED`); rate limits
(`PICKUP_ATTEMPTS_EXCEEDED`) and rotations (`PICKUP_CREDENTIAL_REISSUED`) too.

Concurrency: two staff scanning the same QR at once take the same row locks; the second finds CONSUMED and gets
`pickup_already_verified` — exactly one pickup (proven over HTTP with two API processes in the e2e suite; in PHPUnit
by the sequence replay / lost-answer test). A scanner retry after a lost answer meets `pickup_already_verified` with
`verified_by_you = true` and shows success. The route also accepts an idempotency key.

Timing: a wrong secret and a wrong code cost the same path (lookup by hash, `hash_equals`); an unknown credential
and a foreign one answer the same `pickup_credential_invalid`.

## Completion

`OrderCompletionService::complete`: PICKED_UP → COMPLETED (actor SYSTEM, `completed_at`), idempotent (a completed
order is returned as it is), 409 for any other state. Tried right after the pickup commit; on failure a
`CompleteOrderJob` (queue `orders`, 3 tries) and the sweep `orders:recover` (PICKED_UP older than 60 s) finish it.
Never touches the money, the snapshot or the credential. `OrderEvent::COMPLETED` after commit is the hook for the
review module (Module 34) and notifications (Module 33).

## Clients

- **Customer Web** (`VITE_REALTIME_MODE=api`, `VITE_REVERB_*`): `src/realtime/echo.ts` (Laravel Echo + pusher-js,
  bearer-token authorizer), `ApiOrderTrackingService` (channel first, version / sequence gating, gap → resync,
  reconnect / tab / online → resync, polling fallback; transport CONNECTING / CONNECTED / RECONNECTING /
  DISCONNECTED / FALLBACK_POLLING shown on the tracking page apart from the connection and the status), the real
  QR / code on the confirmation, tracking and details pages. Dashboard: `useLocationOrdersLive` (one channel per
  location, dedupe by event id, debounced queue re-read, 20 s polling fallback, 60 s safety read, "new" counter), the
  scanner page (camera via `jsQR`, a field for hardware scanners, order number + code), every outcome shown plainly.
  Admin: the pickup verification card on the order, the Realtime and pickup card on Insights.
- **Android** (`REALTIME_MODE=api`, `REVERB_*` dart-defines): `lib/realtime/pusher_client.dart` (Pusher protocol 7
  over `web_socket_channel`, private auth through the API, ping / pong, bounded backoff with jitter),
  `ApiOrderTrackingService` (channel first, polling fallback, resume → reconnect + read), the transport line on the
  tracking screen, the real QR / code (`qr_flutter`). The credential is read from the order on each open and kept
  in memory only; the last loaded order is shown offline with the offline notice.

## Operations and observability

`GET /admin/system/realtime` (admin.system.view): health (`OPERATIONAL` when Reverb answers its channel listing,
`DEGRADED`, `UNAVAILABLE`, `OFF`; cached 10 s, `fresh=1` probes), transport, allowed origins, counters
(`broadcast_sent / failed / avg ms`, `channel_auth_granted / denied`, `events_resync`), pickup counters (verified by
method, refused by reason, replays, rate limited, locked, picked up not completed), completion counters.
`GET /admin/pickup-attempts` (security view): the attempts newest first and the staff accounts with five or more
failures in 24 h. Connections themselves are the WebSocket server's. Logs carry ids, states and codes only.

## Running locally

`php artisan reverb:start --host=127.0.0.1 --port=8085` (preview `reverb`); `.env`: `BROADCAST_CONNECTION=reverb`,
`REVERB_APP_ID / KEY / SECRET` (local random values), `REVERB_HOST / PORT / SCHEME`, `REVERB_ALLOWED_ORIGINS`. Tests
run with `BROADCAST_CONNECTION=null` and test values for the signing driver (phpunit.xml). The queue worker and the
scheduler of Module 31 are still needed (`orders` queue for the completion job, `orders:recover` for the sweep).

## Not in this module

FCM / background push, SMS and e-mail delivery, refund execution, settlements, reviews, an admin pickup override,
offline restaurant verification, dynamic (rotating) QR, SSE.
