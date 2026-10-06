# Pickup availability: ASAP, scheduled slots, preparation time, capacity, arrival-aware pickup (Module 28)

WHEN an order can be collected. The backend decides it — on its own clock, in the restaurant's time zone — from
the restaurant's opening hours and pickup settings, from the cart, and, when the customer is travelling, from
their estimated arrival. A customer chooses one of the offered options; that choice is validated again every
time it is read.

FoodOnTheGo V1 is pickup-oriented: there are no delivery slots, delivery fees, drivers or delivery addresses
anywhere in this module. Nothing is ordered or reserved here either: checkout, taxes / fees / promotions
(Module 29), payment (Module 30) and orders (Module 31) come later.

Local development only — nothing here has touched production.

## The rule

```
SELECTED  ≠  RESERVED
```

Choosing a pickup time holds no capacity. Two customers may choose the last free place of a slot; checkout and
order creation will validate again and give it to exactly one of them. Every answer says `reserved: false`, and
no status is called RESERVED (the database refuses it).

## Vocabulary

| Term | What it is | Where |
|---|---|---|
| **PickupAvailability** | The options of one restaurant for one cart at one instant: ASAP, slots, recommendation, issues. A snapshot — calculated, never stored. | `PickupAvailabilityService`, `PickupAvailability` |
| **PickupMode** | `ASAP` or `SCHEDULED`. Always explicit; a missing time never means ASAP. | `App\Enums\PickupMode` |
| **PickupMethod** | `COUNTER`, `CURBSIDE`, `DRIVE_THROUGH` — offered when the restaurant enabled it AND its market allows it. | `PickupRules` (Module 23) |
| **PreparationEstimate** | How long the kitchen is expected to need, and from when the order can be handed over. An estimate, never a promise. | `PreparationTimeService` |
| **PickupSlot** | `[starts_at, ends_at)` on the restaurant's slot grid, with a key, an availability flag and a capacity state. An option, not a row. | `PickupSlotGenerator`, `PickupSlot` |
| **Slot key** | `ps1_<start>_<interval>_<tag>`: names a slot of ONE restaurant. Proves nothing about availability. | `PickupSlotKey` |
| **CapacityState** | What a customer may know about a slot: `AVAILABLE`, `LIMITED`, `FULL`. Never a count. | `App\Enums\PickupCapacityState` |
| **ArrivalContext** | The traveller's estimated arrival at the restaurant (from the cart's journey), its freshness, and the difference to a pickup time. | `PickupAvailability::$journey` |
| **AvailabilityIssue** | Why nothing can be chosen, or context the customer should know: `{code, severity, message, details}`. | `App\Enums\PickupIssueCode` |
| **PickupSelection** | The option a customer chose for their cart: mode, method, time, what it was validated against, status. One per cart. | `cart_pickup_selections`, `PickupSelectionService` |

## Architecture

```
Cart ──────────────────────────────┐            (the caller's own: session or guest cart token)
  │ CartValidationService          │
  ▼                                │
Cart valid?  ── no ──► CART_INVALID / CART_EXPIRED
  │ yes
  ▼
Restaurant location (the cart's)
  ├── approval chain, market / area ──► RESTAURANT_UNAVAILABLE / MARKET_UNAVAILABLE
  ├── pickup settings (enabled, modes, methods) ──► PICKUP_DISABLED
  ├── accepting orders / pause (server clock) ──► RESTAURANT_NOT_ACCEPTING
  ├── opening hours + special hours (location time zone)
  └── capacity in use (PickupCapacityRepository — one query)
  │
  ▼
PreparationTimeService ──► estimate ──► kitchen ready = now + max(estimate + buffer, minimum lead)

Journey context (optional, from the cart) ──► traveller's estimated arrival (only while fresh)

kitchen ready + arrival
  │
  ├──► ASAP: kitchen-ready time, if open now and before closing − cut-off; a full slot moves it to the next
  ├──► PickupSlotGenerator: grid slots from kitchen ready to the scheduling horizon, inside opening hours
  │        └── capacity check: FULL slots are listed as unavailable
  └──► recommendation: first valid option ≥ max(kitchen ready, arrival + buffer)
  │
  ▼
PickupAvailability  ──►  customer chooses  ──►  PickupSelection (validated again on every read)
```

Everything runs inside `PickupAvailabilityService`; controllers only read input and present the answer. The
restaurant's own settings page calls the same engine (`preview`) — there is no second slot generator.

## Inputs the engine trusts — and the ones it refuses

Trusted: the cart (resolved for its owner), the cart's restaurant location, that location's hours, special hours,
pickup settings and pickup methods, the market's features, the server clock, the capacity repository, the journey
summary the cart kept.

Refused with 422, not ignored: `restaurant_id`, `location_id`, `customer_id`, `cart_id`, `pickup_at`,
`selected_at`, `time`, `starts_at`, `timezone`, `status`, `valid`, `reserved`, `capacity`, `capacity_state`,
`expires_at`, `version`, `estimated_prep_minutes`, `traveler_arrival_at`, `now`, `client_time`, `currency`.
A client sends a **mode**, a **method**, a **slot key** and optionally the **cart version** it saw. The device
clock and the device time zone are never read.

## Preparation estimate

```
base       = max(restaurant default_prep_minutes, the slowest item's preparation_minutes)
quantity   = step adjustment for a large order (config/pickup.php)
estimate   = min(base + quantity, platform maximum)

ready in   = max(estimate + buffer_minutes, minimum_lead_minutes)
```

- Item times are **never summed**: a kitchen prepares the lines of an order in parallel. The restaurant's default
  is the floor; one slow item raises the whole order to its time.
- Quantity: the first `free_items` units cost nothing; every further `items_per_step` units (started) add
  `minutes_per_step`, capped at `max_extra_minutes`. Development defaults 10 / 10 / 5 / 30 — an owner decision.
  `minutes_per_step = 0` switches the adjustment off.
- **Lead time is a floor, not an addition.** A 20-minute preparation with a 15-minute lead is ready in 20 minutes;
  with a 45-minute lead, in 45 — never 65.
- The buffer (`buffer_minutes`) is the restaurant's hand-over margin and is added to the preparation time.
- It is an estimate made before the order exists. Changing the time of a real order is the orders module's job.

## ASAP

ASAP is **the earliest currently valid pickup time** — not "immediately". It is available when

- the restaurant offers ASAP (its setting and the market feature),
- it is open now (special hours first, then weekly hours, in its own time zone) and accepts orders,
- the kitchen-ready time is before closing and at or before closing minus the cut-off.

| State | `asap.reason` |
|---|---|
| ASAP switched off (restaurant or market) | `ASAP_DISABLED` |
| Closed right now (outside hours, special closure, gap between two periods) | `RESTAURANT_CLOSED` |
| Open, but the order cannot be ready before the restaurant stops handing over | `CLOSES_TOO_SOON` |
| Every slot until closing is at capacity | `FULLY_BOOKED` |

With a capacity limit an ASAP order uses the capacity of the slot its ready time falls in; a full slot moves the
ASAP time to the start of the next slot with room. A closed restaurant has no ASAP but may still offer scheduled
times from its next opening.

## Scheduled slots

Generated per open interval (regular hours, special hours, several periods a day, overnight periods):

| Rule | |
|---|---|
| Grid | Counted from **local midnight** of the restaurant in steps of `slot_interval_minutes`: with 15 minutes the slots are :00 :15 :30 :45. A restaurant opening at 10:07 offers 10:15 first — never 10:07, never 10:22. |
| Earliest | At or after the kitchen-ready time (preparation, buffer, lead). |
| Latest | At or before now + `schedule_horizon_minutes`. |
| Closing | A slot never starts at or after closing, and never later than closing minus `order_cutoff_minutes`. |
| Gaps | Nothing between two opening periods, nothing on a closed day or a special closure. |
| Overnight | A period 18:00–02:00 continues past midnight onto the next calendar date; its slots carry that date. |
| Bound | At most `pickup.limits.max_slots` (600) per answer; `scheduled.truncated` says when the list was cut. |

A **full** slot is listed as unavailable (`available: false`, `reason: SLOT_FULL`) so a client can show it as
full. Times that are too early, past or beyond the horizon are simply not listed. Slots are grouped by
`local_date`, the restaurant-local calendar date.

## Capacity

- Unit: **orders per slot** (`restaurant_pickup_settings.capacity_per_slot`). No configured capacity = no limit;
  one is never invented.
- Source: `PickupCapacityRepository`. Its database implementation reads `pickup_slot_reservations` in one
  aggregated query. Rows have a source: `ORDER` (Module 31), `HOLD` (a short checkout hold, Module 29) and
  `FIXTURE` (development / test data). **Today only FIXTURE rows exist** — nothing pretends an order exists.
- Usage is counted inside the *current* slot grid: a reservation made at 13:15 when slots were 15 minutes long
  counts for the 13:00–13:30 slot after the restaurant switched to 30 minutes.
- Customers see `AVAILABLE`, `LIMITED` (at most a quarter of the capacity left, and something already taken) or
  `FULL`. Never a count, never anything about other orders.
- **Concurrency.** `reserve()` takes units atomically: a transaction-scoped PostgreSQL advisory lock on
  (location, slot) makes "count, then insert" one step, so the last place can never be given to two callers.
  It is tested and ready — and **not called by any customer flow yet**.

```
PICKUP SLOT HOLD = MODULE 29 / 31        (Module 28: selection only)
```

PostgreSQL is the truth about capacity. Redis may carry a short-lived hold later, but never alone: losing it
must not oversell a slot.

## Journey and arrival-aware recommendation

A journey is optional. With one, the cart carries the routing provider's estimated arrival at the restaurant
(Module 26 / 27). The engine uses it only while it is **fresh**: the journey is alive and the arrival still lies
ahead. Otherwise `journey.status` is `STALE`, `requires_refresh` is true, an INFO issue `JOURNEY_STALE` is added
and nothing is recommended from it — the client may plan the journey again through the journey API; the pickup
controller never calls the routing provider.

```
recommended = first valid option at or after  max(kitchen ready, arrival + arrival buffer)
```

| Situation | Recommendation |
|---|---|
| No journey | The earliest option (ASAP when possible, else the first slot) — `basis: KITCHEN` |
| Kitchen ready 13:00, traveller arrives 13:25 | The slot at 13:30 — `basis: ARRIVAL`, not 13:00 |
| Traveller arrives 12:50, kitchen ready 13:00 | 13:00 — `basis: KITCHEN` |
| Both 13:15 | 13:15 |
| Arrival after the last possible time | The latest option; the difference says how much earlier it is |

`arrival_vs_pickup_delta_seconds` (on ASAP, on every slot, on the recommendation and on the selection) is the
pickup time minus the arrival: positive = the traveller is there first, negative = the order may be ready before
they arrive. The customer may choose any other valid option; earlier valid slots are never hidden.
`pickup.arrival_buffer_minutes` (platform configuration, 0 by default) is added to the arrival. The journey block
of a pickup answer carries times only — no place name, no coordinates, no route.

Module 28 does not track a journey: no GPS, no live updates. A future departure is covered by the journey's own
estimate; without traffic prediction the arrival is an estimate and is labelled by its source.

## Time zones

- Every calculation uses the **restaurant location's IANA time zone** (`Asia/Kolkata` for India through
  configuration). Never the server's, the browser's or the phone's.
- Instants are stored and returned in UTC; next to each one the API gives the same instant on the restaurant's
  clock with its UTC offset (`local_starts_at`, `local_pickup_at`) and the restaurant-local `local_date`.
- Daylight saving (tested with a fixture restaurant on `America/New_York`, never a customer market): when clocks
  go forward the missing hour has no slots; when they go back the repeated hour gives two slots with the same
  wall-clock reading, different offsets, different instants and different keys. Opening hours stay on the wall
  clock ("opens at 09:00" stays 09:00).
- The backend sends no labels ("Today", "7:20 PM"): clients format with their own locale (`en-IN`).

## The selection

`cart_pickup_selections`: one row per cart (unique `cart_id`, removed with the cart). It stores the mode, the
method, the time (`pickup_at`: the slot start, or for ASAP the estimate when it was last brought up to date), the
slot key, the restaurant's time zone, and what the choice was validated against: `cart_version`,
`settings_version`, `estimated_prep_minutes`.

| Status | Meaning |
|---|---|
| `SELECTED` | Valid when last checked. An ASAP selection follows the clock: its time is the earliest valid one *now*. |
| `STALE` | The cart changed and the chosen time no longer fits it (`CART_CHANGED`), or the cart itself needs attention (`CART_INVALID`). |
| `INVALID` | The restaurant, its hours / settings, the pickup method or the slot's capacity no longer allow it. |
| `EXPIRED` | The time has passed or can no longer be met (`SLOT_EXPIRED`), or the cart expired (`CART_EXPIRED`). |
| `CONVERTED` | Copied onto an order — reserved for Module 31. |

**Validated on every read** (`GET /cart/pickup-selection`, and inside `GET /cart/pickup-availability`): after a
cart change, a change of hours or settings, a pause, a suspension, a slot filling up, or simply time passing. A
selection that still fits stays `SELECTED` (and is re-associated with the new cart version); one that became
possible again (capacity freed, pause over, slow item removed) becomes `SELECTED` again without choosing anew.

| What happens | Effect on the selection |
|---|---|
| Item added / removed / quantity changed, still feasible | stays `SELECTED` |
| A slower cart makes the time unreachable | `STALE` / `CART_CHANGED` |
| Cart has a blocking issue or an unreviewed price change | `STALE` / `CART_INVALID` (pickup step blocked) |
| Restaurant pauses orders / is suspended / area paused | `INVALID` |
| Hours shortened, special closure, interval / horizon / mode / method changed | `INVALID` |
| Chosen slot fills up | `INVALID` / `SLOT_FULL` |
| Cart cleared, restaurant switched | selection gone with the cart |
| Cart expires | `EXPIRED` / `CART_EXPIRED` |
| Guest signs in, cart claimed | selection stays with the cart |
| Guest cart merged into the account's cart (same restaurant) | moves along unless the account's cart has its own; validated against the merged cart |
| Two restaurants at sign-in | each cart keeps its own; nothing is carried across restaurants |

## API

All four customer operations live under the cart, behind `customer.optional` and `cart.owner`: the cart is the
caller's own (session or `X-Cart-Token`), and **no route takes a cart, restaurant or customer id**.

| Operation | |
|---|---|
| `GET /cart/pickup-availability[?method=]` | `{pickup: {...}}` — restaurant, time zone, cart version, methods, preparation, ASAP, scheduled slots, recommendation, journey, current selection, issues, `generated_at`, `revalidate_after`. 200 also when nothing can be chosen (`can_select: false`). |
| `GET /cart/pickup-selection` | `{selection: {...} \| null}` — validated again. |
| `POST /cart/pickup-selection` | `{mode, method?, slot_key?, cart_version?}` → `{selection}`. Replaces an earlier choice. Optional `Idempotency-Key`. |
| `DELETE /cart/pickup-selection` | `{selection: null}`. Safe to repeat. |
| `GET /restaurant/locations/{location}/pickup-preview` | Staff (`restaurant.profile.view`): the same engine for an order with the default preparation time, per local day, with warnings. Read-only. |
| `GET /admin/system/pickup` | Administrators (`admin.system.view`): counts only. |

| Error | Status | When |
|---|---|---|
| `cart_not_found` | 404 | no cart (also: no identity at all) |
| `cart_empty` | 409 | the cart has no lines |
| `cart_invalid` | 409 | the cart has a blocking issue or needs review |
| `cart_version_conflict` | 409 | `cart_version` is not the current one |
| `cart_expired` | 410 | |
| `pickup_disabled`, `restaurant_unavailable`, `market_unavailable`, `restaurant_not_accepting` | 409 | the restaurant cannot be ordered from |
| `asap_unavailable` | 409 | ASAP is offered but not possible now (`details.reason`) |
| `slot_full` | 409 | the slot has no capacity left |
| `slot_expired` | 409 | the slot's time has passed |
| `pickup_mode_unavailable` | 422 | the restaurant does not offer that mode |
| `pickup_method_unavailable` | 422 | the restaurant does not offer that method |
| `slot_invalid` | 422 | not a slot of this restaurant, off the grid, outside hours, too early (`details.reason`) |
| `slot_outside_horizon` | 422 | beyond the scheduling horizon |
| `validation_failed` | 422 | missing / unknown mode, a field the server decides |

Issue codes in answers (upper case): `PICKUP_DISABLED`, `RESTAURANT_UNAVAILABLE`, `MARKET_UNAVAILABLE`,
`RESTAURANT_NOT_ACCEPTING`, `RESTAURANT_CLOSED` (INFO when a later time is offered), `CLOSES_TOO_SOON`,
`ASAP_DISABLED`, `SCHEDULED_DISABLED`, `NO_PICKUP_SLOTS` (with `details.cause`: `FULLY_BOOKED`,
`RESTAURANT_CLOSED`, `CLOSES_TOO_SOON`, `NO_PICKUP_SLOTS`), `FULLY_BOOKED`, `SLOT_FULL`, `SLOT_EXPIRED`,
`SLOT_INVALID`, `SLOT_TOO_EARLY`, `SLOT_OUTSIDE_HORIZON`, `PICKUP_METHOD_UNAVAILABLE`, `CART_INVALID`,
`CART_CHANGED`, `CART_EXPIRED`, `JOURNEY_STALE` (INFO).

Rate limits are the cart's: `cart-read` for the two reads, `cart-write` for choosing and clearing.

## Concurrency and idempotency

- Choosing a time runs in one transaction on the **locked cart row**: a change to the cart and a choice of pickup
  time never interleave; an optional `cart_version` refuses a choice made against an old cart (409).
- One selection per cart is a database fact (unique `cart_id`); choosing again updates that row.
- `Idempotency-Key` is optional and owner-scoped, like the cart's: a repeated request replays the first answer.
  Without a key the operation is safe to repeat anyway.
- Two customers choosing the last place of a slot both succeed — a selection reserves nothing. The reservation
  boundary is `PickupCapacityRepository::reserve` (above), to be called by checkout / order creation.

## Caching

None. An answer depends on the minute, on remaining capacity and on the accepting-orders switch, and it is cheap:
the cart inspection the cart API already does, plus **one** capacity query — 26 queries whatever the number of
lines, slots or reservations (measured, `docs/local-review/test-results/m28-performance.json`). Slot templates
are not cached either: generating 600 slots costs less than a cache round trip would save. Redis holds rate
limits and event counters only. Every change (cart, hours, settings, pause, capacity) therefore takes effect on
the next request.

## Database

| Table | Rules |
|---|---|
| `cart_pickup_selections` | `cart_id` unique, FK → carts ON DELETE CASCADE; CHECK mode ∈ ASAP / SCHEDULED; CHECK method; CHECK status ∈ SELECTED / STALE / INVALID / EXPIRED / CONVERTED; CHECK scheduled ⇔ slot key, interval and slot end present; indexes (status, expires_at), (pickup_at) |
| `pickup_slot_reservations` | FK → restaurant_locations ON DELETE CASCADE; CHECK units 1…500; CHECK source ∈ FIXTURE / HOLD / ORDER; CHECK status ∈ ACTIVE / RELEASED; CHECK a HOLD has `expires_at`; indexes (location_id, slot_starts_at, status), (status, expires_at) |

Availability is **computed, not stored**: there is no row for a possible future time.
Settings are Module 23's `restaurant_pickup_settings`; this module added no settings column.

## Events and numbers

`PickupSelectionEvent` (selected / cleared / invalidated / refused) → `RecordPickupEvent`: one counter and one
log line `pickup.<name>` with the cart id, owner type, restaurant id and codes — never the chosen minute, the
cart's contents or the journey. `PickupMetrics` (one-hour buckets in the cache): availability requests, blocked,
no slots, ASAP available, slots generated, journey-aware, selections by mode, cleared, invalidated, refused,
slot full. `GET /admin/system/pickup` reports them with the selection counts.

## Restaurant Dashboard and Platform Admin

- Pickup Settings (Module 23) are the engine's input: what staff save is what customers are offered on the next
  request. The page shows the **customer preview** from `GET …/pickup-preview` and its warnings
  (`NO_OPENING_HOURS`, `CUTOFF_LONGER_THAN_OPENING`, `ASAP_NEVER_POSSIBLE`, `ALL_SLOTS_FULL`, `NO_PICKUP_TIMES`).
- Administrators see a restaurant's pickup configuration read-only and the counters; they cannot change a
  restaurant's pickup settings.

## Clients

| | Mode switch | Code |
|---|---|---|
| Customer Web | `VITE_PICKUP_MODE=api` | `src/pickup/api/apiPickup.ts`, `ServerPickupProvider` behind the existing `usePickup()` interface |
| Android | `--dart-define=PICKUP_MODE=api` | `lib/pickup/api_pickup.dart`, `PickupState` on the backend |

Both keep the development slot engine as the mock mode behind the same interface.

Rules both clients follow:

- **They send a choice, never a time**: a mode, the pickup method and the key of an offered slot (plus the cart
  version they saw). The request carries an `Idempotency-Key` and is repeated once with the same key after a lost
  connection.
- **They show the backend's clock.** The web formats instants in the restaurant's IANA zone. Android keeps no
  time-zone rules for these screens: it uses the UTC offset the backend wrote for each instant (`local_…`
  timestamps), so a clock change in the restaurant's zone is shown correctly too, and the phone's zone is never
  asked.
- **They ask again**: when the backend's own interval has passed (`revalidate_after − generated_at`), when the
  page / app comes back to the front, when the cart version changes, and before checkout opens.
- **"No selection" is only true once it is known.** After a page load (or a cart change) the stored choice arrives
  one request after the cart. The web's pickup interface exposes `ready`; checkout neither prepares nor redirects
  before the choice of the current cart has been read (otherwise a reload of `/checkout` would send the customer
  back to choose a time they already chose). Android re-reads the choice whenever the cart appears or changes and
  re-prepares checkout when it arrives.
- **Nothing is offered from memory.** When the backend cannot be reached, the times of an earlier answer are not
  listed and Continue is off. Android shows the last selection the backend confirmed as *"Needs connection to
  confirm availability"* (kept in the device's secure storage for exactly that purpose); it never opens checkout.
- **The mode shown is always an offered one.** A stored ASAP choice at a restaurant that has switched ASAP off
  opens on the slots.
- **Only a method the customer picked is sent**; until then the restaurant's default counts, so switching a method
  off never turns every request into a refusal.
- **Wording**: "selected, not reserved" — neither client says a time is reserved.

## Hand-over to the next modules

- **Module 29 (checkout)** must validate cart **and** pickup again (`PickupSelectionService::current`), may place
  a short `HOLD` through `PickupCapacityRepository::reserve`, and owns taxes, fees and promotions.
- **Module 31 (orders)** writes an `ORDER` reservation in the same transaction as the order, snapshots the
  pickup onto the order (mode, method, scheduled time, time zone, location, preparation estimate, the traveller's
  arrival estimate) and sets the selection to `CONVERTED`. Capacity then counts real orders.

## Tests

`tests/Feature/Pickup` and `tests/Unit/Pickup` — all on a fixed clock: ASAP, slot generation, preparation,
capacity (including the reservation boundary), journey, selection and revalidation, ownership, time zones and
daylight saving, dashboard preview and cross-surface settings, fixtures and counters, performance and indexes,
OpenAPI contract.

## Not in this module

Delivery of any kind; checkout pricing; promotions; taxes; fees; payment; orders; the final pickup reservation;
order confirmation; tracking; pickup QR verification; live journey tracking.
