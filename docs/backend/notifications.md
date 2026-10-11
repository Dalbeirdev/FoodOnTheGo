# Notifications (Module 33) — delivery backend, devices, FCM, in-app inbox

Built 2026-10-09, local only. Laravel 13.35 / PHP 8.3 / PostgreSQL 18 (the spec says 12 / 16 — CF-268). Nothing here
changes an order: a business event (`ORDER_READY_FOR_PICKUP`) is one thing, a delivery outcome (`PUSH_SENT`, `PUSH_FAILED`)
another, and the second never writes to the first.

## Provider documentation reviewed (2026-10-09)

| Topic | What the current documentation says | Where it shaped the code |
|---|---|---|
| FCM HTTP v1 send | `POST https://fcm.googleapis.com/v1/projects/{project}/messages:send`, OAuth2 bearer from a service account, scope `firebase.messaging`; `token` / `topic` / `condition`; `notification{title,body}`; `data` string values only; `android{priority NORMAL\|HIGH, ttl "3600s", collapse_key (≤ 4 active), restricted_package_name, notification{channel_id, tag, visibility}}`; 4096 bytes per message | `FcmHttpV1PushProvider`, `PushMessage::toFcm()`, `config/notifications.php` `fcm.*` |
| Error codes | UNREGISTERED 404 → remove the token; INVALID_ARGUMENT 400 → no retry (token named → remove it); SENDER_ID_MISMATCH 403 → token of another sender; QUOTA_EXCEEDED 429 → exponential backoff, ≥ 1 minute; UNAVAILABLE 503 / INTERNAL 500 → retry with backoff, honour Retry-After, jitter; THIRD_PARTY_AUTH_ERROR 401 (APNs / web push) | `FcmHttpV1PushProvider::mapError()`, `NotificationErrorCategory`, `NotificationDeliveryService` |
| Quotas | 600k messages / minute / project; Android 240 / minute and 5,000 / hour per device; collapsible burst 20 per device (1 per 3 min refill) | the backoff floor for 429; the collapse key per subject; no fan-out beyond the customer's own devices |
| Legacy APIs | legacy HTTP / XMPP / batch send shut down June 2024; v1 has no batch endpoint (the SDKs' `sendEach*` = one request per message) | one request per device, one delivery row per device, per-device result accounting |
| Flutter | firebase_messaging ^16.7.0, firebase_core ^4.14.0 (pub.dev, 2026-10-09); `onTokenRefresh` fires at every start and on rotation; `requestPermission()` covers Android 13 `POST_NOTIFICATIONS`; `onMessage` / `onMessageOpenedApp` / `getInitialMessage` / `onBackgroundMessage`; `channel_id` must exist on the device or FCM falls back to the manifest default | `lib/notifications/*`, `MainActivity.kt` (channels), the manifest permission |
| PHP server SDK | the official Firebase Admin SDKs are Node / Java / Python / Go / .NET; PHP has a community SDK (kreait) with a maintenance / funding caveat in its own README | chosen: HTTP v1 over Guzzle + the official `google/auth` (v1.55.1, 2026-09-28) for the service-account token |

## Domain

- `customer_devices` — one row per app installation bound to a customer (never an authentication). `installation_id` is a
  random value the app generates once; the token is stored **encrypted** with a SHA-256 hash for lookup; one ACTIVE row
  per installation and per token (partial unique indexes). A row is REVOKED, never deleted: LOGOUT (the app names its
  installation in `POST /auth/logout` or `DELETE /customer/devices/current`), REBOUND (another customer signed in on the
  same installation), TOKEN_REASSIGNED (the same token on another installation), INVALID_TOKEN (the provider said
  unregistered), CUSTOMER, ADMIN, REPLACED (more than `limits.devices_per_customer`).
- `notifications` — the logical customer notification = the in-app record: one per dedupe key (domain event id + customer
  + type), with the rendered, escaped snapshot (title / body), template key + version + locale, the allow-listed action
  target + reference (never a URL), read_at, expires_at (retention).
- `notification_deliveries` — one per channel (and per device for PUSH): QUEUED → SENDING → SENT / RETRY_SCHEDULED /
  FAILED / INVALID_DESTINATION / SKIPPED / CANCELLED. SENT = the provider accepted the message; DELIVERED only with a
  trustworthy receipt (no current provider gives one). `policy_result` says why a channel was skipped.
- `notification_delivery_attempts` — append-only, one per provider call: result, provider code, normalised category,
  safe error, latency, payload size. Never the token, never the raw provider body.

## Registry (config/notifications.php `types` / `order_events` / `security_events`)

| Domain event | Type | Category | IN_APP | PUSH | EMAIL | SMS | Priority | Target |
|---|---|---|---|---|---|---|---|---|
| order.created | order.created | ORDER_UPDATES | mandatory | preference | preference | off | NORMAL | ORDER_DETAIL |
| order.restaurant_accepted | order.accepted | ORDER_UPDATES | mandatory | preference | off | off | NORMAL | ORDER_TRACKING |
| order.restaurant_rejected | order.rejected | ORDER_UPDATES | mandatory | preference | preference | off | HIGH | ORDER_DETAIL |
| order.preparing | order.preparing | ORDER_UPDATES | mandatory | **off** (no push fatigue) | off | off | NORMAL | ORDER_TRACKING |
| order.ready | order.ready | PICKUP_UPDATES | mandatory | preference | off | preference | **HIGH** | ORDER_TRACKING |
| order.picked_up | order.picked_up | PICKUP_UPDATES | mandatory | off (the customer is at the counter) | off | off | NORMAL | ORDER_DETAIL |
| order.completed | order.completed | ORDER_UPDATES | mandatory | off | preference | off | NORMAL | ORDER_DETAIL |
| order.cancelled | order.cancelled | ORDER_UPDATES | mandatory | preference | preference | off | NORMAL | ORDER_DETAIL |
| order.refund_required | refund.pending | PAYMENT_UPDATES | mandatory | preference | preference | off | NORMAL | PAYMENT_STATUS |
| order.recovery_required / fulfillment_failed | payment.recovery_required | PAYMENT_UPDATES | mandatory | preference | preference | off | HIGH | PAYMENT_STATUS |
| security PHONE_CHANGED (customers) | account.phone_changed | ACCOUNT_SECURITY | mandatory | **mandatory** | mandatory | off | HIGH | ACCOUNT_SECURITY |

Not notified separately: the Module 30 payment events (no "payment successful" spam — the order events carry the
customer-visible outcome), `order.status_changed` (generic), `order.pickup_credential_reissued` (the customer's own
action). Nothing marketing is emitted (no campaigns in this module). The wording of a refund is "being processed" — never
"completed" until a refund domain exists.

## Policy (`NotificationPolicyService`)

`decide(type, channel, customer, matrix, destination)` → `SEND | MANDATORY | SKIP_USER_PREFERENCE | SKIP_CHANNEL_DISABLED |
SKIP_NO_DESTINATION | SKIP_CUSTOMER_STATUS | SKIP_OS_PERMISSION_DENIED | UNSUPPORTED`.
- the registry rule `off` → UNSUPPORTED (no row); a channel whose driver is `off` → SKIP_CHANNEL_DISABLED;
- DEACTIVATED customers get security notices only; SUSPENDED customers get transactional notices only;
- PROMOTIONS need `promotions_consented_at` (never inferred) and the matrix cell; PRODUCT_UPDATES the cell;
- `mandatory` rules and the Module 25 **locked** cells ignore the preference; `preference` rules read the Module 25 matrix;
- destinations: PUSH = active devices with a token whose OS permission is not DENIED; EMAIL = a verified address
  (`channels.EMAIL.require_verified`; customers have no e-mail verification yet → every e-mail is SKIPPED, carry-forward);
  SMS = a verified phone — and the SMS channel is `off` locally (REAL SMS PROVIDER = PENDING).

## Providers

| Channel | Driver (env) | Local | Real |
|---|---|---|---|
| PUSH | `PUSH_DRIVER=fake` / `fcm` / `off` | the development provider: outcomes read from the token (`unregistered`, `transient`, `quota`, `authfail`), the last messages kept in the cache without the token (admin `GET /admin/system/notifications` shows them) | `fcm`: FCM HTTP v1 with `FIREBASE_PROJECT_ID` + `FIREBASE_CREDENTIALS` (path of the git-ignored service-account JSON). No Firebase project exists on this machine → **REAL FCM = PENDING FIREBASE CONFIGURATION**; the health probe says UNAVAILABLE, never pretends |
| EMAIL | `EMAIL_NOTIFICATIONS_DRIVER=mail` / `fake` | Laravel's mailer, `MAIL_MAILER=log` (written locally, delivered nowhere) | **REAL EMAIL PROVIDER = PENDING** |
| SMS | `SMS_NOTIFICATIONS_DRIVER=off` / `fake` | off (tests use fake) | **REAL SMS PROVIDER = PENDING**; the Module 21 OTP sender is a separate contract and policy |
| IN_APP | database | the notification row | — |

## Delivery (`SendNotificationDeliveryJob` on queue `notifications`, `NotificationDeliveryService`)

Lease by conditional UPDATE (QUEUED / RETRY_SCHEDULED → SENDING; a second worker finds nothing). One attempt row per
provider call. Outcomes: accepted → SENT (+ provider reference); INVALID_DESTINATION → the device revoked (INVALID_TOKEN),
no retry; AUTH_CONFIGURATION → FAILED, the push provider paused `retry.auth_failure_pause_seconds` (no storm), an error
logged; PERMANENT → FAILED; TRANSIENT / RATE_LIMITED / UNKNOWN → RETRY_SCHEDULED with `base · 2^(n-1) + jitter` (30 s, 60 s,
120 s …, cap 900 s; 429 at least 60 s or Retry-After) up to `retry.max_attempts` (5) then FAILED. The job is unique per
delivery *until processing* so the retry it dispatches is not swallowed by its own lock; `notifications:recover`
(every minute) re-queues SENDING rows past the lease (120 s), due RETRY_SCHEDULED rows and QUEUED rows older than five
minutes, releasing the unique lock first; `notifications:prune` (daily) applies retention (90 days; 365 for
ACCOUNT_SECURITY; attempts 30 days; tokens of revoked devices purged after 30 days). Order history is never touched.

## API (16 routes; OpenAPI 195 paths)

Customer (`auth:customer`): `GET /customer/devices`, `PUT /customer/devices/current` (idempotent registration; throttle
`device-register` 20 / min), `DELETE /customer/devices/current {installation_id}`, `PATCH|DELETE /customer/devices/{device}`
(own, else 404); `GET /customer/notifications` (page + `unread_count`, `?unread=1`, `?category=`), `GET
/customer/notifications/unread-count`, `POST /customer/notifications/{id}/read` (idempotent), `POST
/customer/notifications/read-all`. `POST /auth/logout {installation_id?}` ends the binding in the same request;
`POST /auth/logout-all` ends every binding. The Module 25 matrix answers `channel_status` (AVAILABLE | DEVELOPMENT |
PENDING | UNAVAILABLE per channel).

Admin: `GET /admin/notifications` (search by notification id, order number, customer id, delivery id; filters) and
`GET /admin/notifications/{id}` (deliveries + attempts) and `GET /admin/notifications/deliveries` and
`GET /admin/notifications/templates` (the registry) need `admin.notifications.view` (new); `POST
/admin/notifications/deliveries/{id}/retry {reason}` needs `admin.notifications.manage` (only a FAILED delivery with a
live destination; audited `notification.delivery_retried`); `POST /admin/notifications/test` needs
`admin.notifications.test` (new) and exists only where `notifications.admin_test_enabled` (never production; audited);
`GET /admin/system/notifications` (`admin.system.view`) = providers probed, queue backlog, counters, devices. No answer
ever carries a push token, an e-mail address or a phone number.

## Push payload (what a device receives)

`notification{title, body}` from the template; `data{type, notification_id, category, target, reference, subject_id,
channel_id, created_at}` (strings); `android{priority, ttl 3600s, collapse_key subject-…, notification{channel_id, tag,
visibility PRIVATE}}`. Never: a pickup QR, a pickup code, a phone, an e-mail, an address, a journey, an amount, an item, a
payment reference, a token. The ready push says "open the app to show your pickup code".

## Clients

- **Customer Web**: the inbox is the backend's (`ApiNotificationRepository`), the header bell shows the real unread count,
  the tracking page re-reads the inbox on a status change, the preferences panel shows the channel status; no browser push.
- **Platform Admin**: Notifications › Delivery status (search, filters, detail drawer with deliveries and attempts, Send
  again with a reason), Templates (the registry), System › Notification delivery card. Alerts and announcements stay
  development data (campaigns are not part of this module).
- **Android**: `PushRuntime` (Firebase only when `PUSH_MODE=fcm` and the FIREBASE_* client defines exist — options from
  the defines, no google-services.json; `fake` = a deterministic token so the whole path runs locally; `off` = inbox only),
  `DeviceRegistrar` (installation id in the secure store; bind at sign-in and on token refresh; the installation id in the
  logout body), `PushNotice` (allow-listed targets → the app's routes), channels created natively (orders, pickup, account,
  offers), the OS permission asked once per session after sign-in. Foreground push → inbox refresh + a quiet snackbar
  (none on the live screen of that order); tap → the allow-listed route after sign-in. build037 (`PUSH_MODE=fake`).

**When the clients read the inbox (both):** at sign-in; quietly again (the list stays on screen, no skeleton; a failed read keeps it) when the
Notifications screen opens, when an order confirmation is shown, and whenever the order status changes on the tracking / confirmation
screens; Android also on a foreground push. Web: `useInboxRefreshOn(trigger)` in `AccountContext.tsx` — the effect depends on the trigger
only and `refreshNotifications` keeps one identity (a new function per inbox state made the tracking page read in a loop: BUG-234).
Android: `AccountState.refreshNotifications()` drops a list that arrives after another customer signed in.

## Tests and evidence

`tests/Feature/Notifications/*` (28 tests): devices (binding, rebinding, token reassignment, IDOR, logout, limit),
the order flow (in-app + per-device push, payload PII, dedupe, rejection wording, dead token, preferences, skips,
inbox, mandatory security notice), retries (backoff, lease, credential failure pause, stranded recovery, admin retry /
RBAC / audit, system report, test send gating), the FCM client (error mapping, mocked v1 send, size limit), templates
(escaping, fallback, bounds), OpenAPI contract (16 routes) and query counts. Evidence in `docs/local-review/test-results/`
(`backend-m33-*`, `m33-*`) and `docs/local-review/screenshots/web-m33/`, `android-preview/m33-*`.

## Carry-forwards

Real FCM, e-mail and SMS providers (configuration pending; FCM needs the owner's Firebase project and a physical device
test); e-mail verification for customers (until then no e-mail is sent); web push (not in scope); campaigns / admin
alerts (development data); iOS (deferred).
