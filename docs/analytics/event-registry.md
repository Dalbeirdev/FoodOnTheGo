# Behavioural event registry

> GENERATED from `backend/config/analytics.php` (`events.registry`) by `php artisan analytics:catalog --write`. Do not edit by hand (a test compares this file with the command's output).

An event that is not in this registry does not exist: the ingestion endpoint refuses the name, and a registered event with a property that is not listed is refused too. Schema version: 1. Names are stable lower_snake_case and mean the same on the web and on Android.

There is deliberately **no** `payment_verified`, `order_created`, `order_completed` or `refund_completed`: those are facts of the payment, order and refund domains and are read from them — no client can report them.

Session: a random identifier the application makes up (not a device identifier), replaced after 30 minutes idle or 12 hours. Batch: at most 25 events; events older than 72 hours are refused.

Privacy classes: `BEHAVIOURAL` = what a session did in the application · `OPERATIONAL` = what the service answered. Neither carries a phone number, an e-mail address, a name, an address, a coordinate, a route, a search text or an amount.

## Sent by the applications (web and Android)

### `journey_search_started`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The traveller submits the journey form. |
| Funnel stage | 1 |
| References | none |
| Properties | none |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; the client sends one per submission |
| Schema version | 1 |

### `journey_results_viewed`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The list of restaurants on the route is shown for a planned journey (also when it is empty). |
| Funnel stage | 2 |
| References | **journey** (required) |
| Properties | `results` (int:0,500) |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; once per journey per session |
| Schema version | 1 |

### `restaurant_viewed`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The restaurant page is opened and its details are shown. |
| Funnel stage | 3 |
| References | **restaurant** (required), journey |
| Properties | `origin` (enum:JOURNEY|BROWSE|DIRECT) |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; once per restaurant per session |
| Schema version | 1 |

### `menu_viewed`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The menu of a restaurant is displayed. |
| Funnel stage | 4 |
| References | **restaurant** (required), journey |
| Properties | none |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; once per restaurant per session |
| Schema version | 1 |

### `item_viewed`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The detail of a menu item is opened. |
| Funnel stage | — |
| References | **restaurant** (required), **item** (required) |
| Properties | none |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; once per item per session |
| Schema version | 1 |

### `item_added_to_cart`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The backend accepted an item into the cart. |
| Funnel stage | 5 |
| References | **restaurant** (required), item |
| Properties | `quantity` (int:1,50) |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; one per accepted add |
| Schema version | 1 |

### `pickup_viewed`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The pickup time page is shown. |
| Funnel stage | — |
| References | restaurant |
| Properties | none |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; once per restaurant per session |
| Schema version | 1 |

### `pickup_selected`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The backend accepted a pickup time for the cart. |
| Funnel stage | 6 |
| References | restaurant |
| Properties | `mode` (enum:ASAP|SCHEDULED), `method` (enum:COUNTER|CURBSIDE|DRIVE_THROUGH), `recommended` (bool) |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; one per accepted selection |
| Schema version | 1 |

### `checkout_started`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The backend opened a checkout for the cart. |
| Funnel stage | 7 |
| References | restaurant, **checkout** (required) |
| Properties | none |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; once per checkout per session |
| Schema version | 1 |

### `payment_started`

| | |
|---|---|
| Source | CLIENT |
| Trigger | The traveller pressed Pay and the backend created a payment attempt. |
| Funnel stage | 8 |
| References | **checkout** (required) |
| Properties | `method` (enum:CARD|UPI|WALLET|NET_BANKING|OTHER) |
| Privacy class | BEHAVIOURAL |
| Deduplication | event id; once per checkout per session |
| Schema version | 1 |

## Recorded by the backend itself

### `journey_search_succeeded`

| | |
|---|---|
| Source | SERVER |
| Trigger | The backend planned a journey (a route was found). |
| Funnel stage | — |
| References | **journey** (required) |
| Properties | `distance_band` (band), `duration_band` (band), `coverage` (enum:COVERED|NO_SERVICE_COVERAGE), `corridors` (ids), `origin_city` (id), `destination_city` (id) |
| Privacy class | OPERATIONAL |
| Deduplication | one per journey (the event id is derived from the journey) |
| Schema version | 1 |

### `journey_search_no_result`

| | |
|---|---|
| Source | SERVER |
| Trigger | Discovery found no restaurant for a journey, and not because of the traveller's own filters. |
| Funnel stage | — |
| References | **journey** (required) |
| Properties | `reason` (enum:NO_SERVICE_COVERAGE|NO_RESTAURANTS_IN_CORRIDOR|NO_REACHABLE_RESTAURANTS) |
| Privacy class | OPERATIONAL |
| Deduplication | one per journey |
| Schema version | 1 |

### `restaurant_impression`

| | |
|---|---|
| Source | SERVER |
| Trigger | A restaurant was RETURNED in a discovery answer for a journey (returned, not proven seen on a screen). |
| Funnel stage | — |
| References | **journey** (required), **restaurant** (required) |
| Properties | `detour_band` (band) |
| Privacy class | OPERATIONAL |
| Deduplication | one per journey per restaurant |
| Schema version | 1 |

### `pickup_slot_refused`

| | |
|---|---|
| Source | SERVER |
| Trigger | The backend refused a pickup time. |
| Funnel stage | — |
| References | **restaurant** (required) |
| Properties | `reason` (code) |
| Privacy class | OPERATIONAL |
| Deduplication | event id |
| Schema version | 1 |
