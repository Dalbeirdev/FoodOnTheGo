# Realtime scaling

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources:
- `backend/config/{reverb,broadcasting}.php`, `backend/app/Events/Order/OrderUpdated.php`, `backend/app/Listeners/BroadcastOrderUpdate.php`, `backend/routes/channels.php`, `backend/app/Services/Order/RealtimeHealth.php`
- `tests/performance/realtime.mjs` → `docs/local-review/test-results/m40-realtime-1000.json`, `m40-realtime-240.json`
- `tests/performance/failure.mjs reverb` → `m40-failure-reverb.json`

## 1. Design (Module 32)

- **Laravel Reverb** (Pusher protocol), **private** channels only:
  - `customer.order.{order}`: the customer who owns the order;
  - `restaurant.location.{location}`: staff who reach the location with `restaurant.orders.view`.
  
  Both are authorized by the API (`POST /customer/broadcasting/auth`, `/restaurant/broadcasting/auth`).
- **One event**, `order.updated`. Its payload carries the order's number, version, status and event sequence, so a client can detect a gap and resync. It is sent **after the commit**, synchronously from the request (`ShouldBroadcastNow`), with a 2 s / 1 s client timeout. A failure is caught, counted and logged and never fails the request.
- **Fallback:** REST resync of the event stream plus polling when the socket is down. Server-sent events are not implemented (optional by design).

## 2. Measurements (local Windows host, one Reverb process, perf pool broadcasting to it)

| Test | Result |
|---|---|
| **Connection ceiling** — 1,000 connections requested in waves of 250 | **255 established, 745 refused**; reproduced twice (`m40-realtime-1000.json`). Connect time of the established ones: p50 105 ms. Reverb memory 59 → 75 MB. |
| Within the ceiling — 180 idle + 50 staff + 10 customer sockets | 180/180 connected in 0.1 s (connect p50 53 ms); memory 59 → 61 MB |
| **Broadcast latency** — 10 orders × 3 kitchen transitions, 50 staff sockets on the location channel + 1 customer socket per order (private, authorized through the API) | staff: **1,500 / 1,500 delivered**, p50 **198 ms**, p95 300 ms, p99 329 ms; customer: **30 / 30**, p50 191 ms, p95 283 ms. Measured from the moment the transition **request was sent** (the request itself, ≈ 200 ms, is included: the broadcast leaves after the commit, before the answer). |
| **Reconnect storm** — all 230 sockets dropped at once and reopened at once, the 50 private ones re-authorized through the API | **230 / 230 back in 1.7 s**, 50 / 50 re-authorized, 0 failures; connect p95 95 ms |
| **Realtime server down** — kitchen transitions | all succeed; p50 **1,215 ms** (216 ms with the server up). The admin realtime view reports `UNAVAILABLE` with the reason. |

**The 255-connection ceiling is a property of this machine, not of the application.**
- Reverb's default event loop is PHP `stream_select`. PHP's Windows build limits `stream_select` to 256 descriptors, and one of them is the listening socket.
- On Linux the same loop is limited by `FD_SETSIZE` (1,024).
- With the `ext-uv` or `ext-event` extension, Reverb switches to an event loop without that limit. The connection count is then bounded by the open-file limit (`ulimit -n`) and memory: about 60 KB per idle connection measured here, (75 − 59) MB / 255.

## 3. Production plan (PENDING approval of the expected numbers)

1. Reverb on **Linux**, with `ext-uv` (or `ext-event`), `ulimit -n` ≥ 4 × the expected connections, behind a load balancer that supports WebSocket upgrade and TLS (`wss`).
2. **Connections to plan for:** one per open customer order screen plus one per restaurant tablet. The customer socket lives only while an order is active (minutes), so concurrent connections ≈ orders in progress plus active kitchen screens. That is far below one node's capacity at launch scale.
3. **More than one Reverb node:** `REVERB_SCALING_ENABLED=true` (Redis pub/sub, a separate Redis database or instance from the queue preferred). Not tested here (one node).
4. **Monitoring:** `/admin/system/realtime` (health, broadcast / authorization / resync counters), process memory and connection count, broadcast failures in the log.
5. **Reconnect storms** (deploy, network blip): measured locally as cheap. Channel authorization goes through the API rate limiter (`throttle:api`). Clients should reconnect with jitter. The Pusher JS client backs off by default; the Android client reconnect is Module 32 code.

## 4. Open items

| Id | Item |
|---|---|
| REL-011 | Local Windows Reverb: 255-connection ceiling (stream_select). Production: Linux + `ext-uv` / `ext-event` + file limits. |
| REL-039 | Multi-node Reverb (Redis scaling) not tested |
| REL-040 | The broadcast is sent synchronously after the commit; a slow or down Reverb adds up to ≈ 1 s per order action (2 attempts with a 1 s connect timeout, measured). Queuing it (`ShouldBroadcast`) would remove that from the request at the cost of a worker hop; not changed. |
