# Restaurant metrics

The physical **restaurant location** is the unit of every figure. An organization is the sum of the locations the
caller may read: an owner sees all of them, a manager bound to one location sees that one.

## What a restaurant sees (`/restaurant/analytics/*`)

| Report | Figures | Source |
|---|---|---|
| Overview | Orders placed, orders completed, sales of completed orders, average order value, acceptance rate, refund rate, average rating, reviews; net earnings (only with earnings access); new and returning customers as **counts** | Order domain; reviews; finance module |
| Orders | Counts, completion and rejection rates, the trend, status distribution, the four order times | Order domain |
| Menu | Per item: units sold, orders, item sales, share of sales (from order items of completed orders) — and views and add-to-cart (from behavioural events), in separately labelled columns | Order items; events |
| Operations | Time to accept, preparation time (accepted → ready), ready-to-pickup time, rejections and reasons, peak times by day and hour, estimated vs actual preparation, pickup (ASAP / scheduled, methods, refused times, capacity) | Order domain; pickup |
| Routes | Times the restaurant was returned for a journey, opened from one, ordered from; detour bands of its own orders | Server-recorded events; order domain |
| Reviews | Published reviews, average rating, distribution, response rate, reports | Review domain |
| Earnings | The finance module's own summary and series — identical to the Earnings page | Finance module |

Times are **operational**: they say how long a step took, not whether the food was good. "Sales" on the overview is
what customers paid for completed orders; it is not what the restaurant earned — earnings are on the Earnings tab.

Peak times and the restaurant's days are in the **restaurant's own time zone**.

## What a restaurant is never given

- Another restaurant's figures, a comparison with another restaurant, or a rank.
- A customer's name, phone number, e-mail address, or any list of customers. "New" and "returning" are counts, and
  under the minimum cohort (default 5 customers) even the split is withheld.
- A traveller's route, origin or destination — none is stored. Route figures are counts and bands.
- Low-selling items are information for the restaurant; nothing marks an item as bad and nothing changes a menu.

A location that is not the caller's does not exist for them (404, like an id that was never issued), whatever the
query string says. The cache key carries the locations, so one restaurant's cached answer is never another's.

## What an administrator sees about restaurants (`/admin/analytics/restaurants`)

One row per location of the caller's markets: orders placed / completed / rejected / cancelled / in progress, sales
of completed orders, average order value, acceptance rate, rejection rate, refund rate and refund amount, the three
times, average rating and review count, impressions, views, orders from a journey — and, only with
`admin.finance.view`, gross sales and net earnings from the finance module.

It is a comparison for people with the permission. The rows carry **no ranking score**; nothing here publishes a
"top restaurant" list, suspends a restaurant, or acts on a falling rating or a rise in support cases. Those decisions
belong to the restaurant domain and to people.

## Definitions that are easy to get wrong

| Metric | Numerator | Denominator | Note |
|---|---|---|---|
| Acceptance rate | Orders placed in the period that were accepted | Orders placed in the period that were accepted **or rejected** | Orders still waiting, and orders cancelled before an answer, are in neither |
| Rejection rate | Orders placed in the period that were rejected | Orders placed in the period | |
| Refund rate (orders) | Orders placed in the period with a completed refund | Orders placed in the period | Not the refund rate by amount |
| Average rating | Sum of ratings of reviews **published** in the period | Reviews published in the period | Hidden, rejected and removed reviews are in no figure. The rating customers see is the all-time one |
| Response rate | Published reviews of the period with a published response | Reviews published in the period | |
| Add-to-cart rate | Sessions with add-to-cart at the restaurant | Sessions with menu viewed at the restaurant | Sessions against sessions |
| Capacity used | Units reserved by orders | Capacity per slot × slots with at least one order | Only where a capacity per slot is configured; nothing is made up for "unlimited" |

"Restaurants with completed orders" (in the period) and "restaurants accepting orders now" (at this moment) are two
figures with two names.
