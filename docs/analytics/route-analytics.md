# Journey, route and corridor analytics

Route analytics is **aggregates of journeys, never a journey**.

## What is kept about a journey

A journey lives for about an hour in the journey domain and is then deleted — "no permanent journey history is
kept". When the backend plans one, analytics records one server-side event with deliberately less than the journey:

| Kept | Not kept |
|---|---|
| A keyed hash of the journey's id (counts journeys; cannot be followed back) | The journey's id |
| The **band** of its length (0–25, 25–50, 50–100, 100–200, 200+ km) and duration | Its length and duration |
| The static route corridors (Module 22) it runs along | Its route, polyline or geometry |
| The **city near** each end (within 40 km of the city's centre), or nothing | Its origin and destination: label, address, coordinates |
| Whether the route is covered by a service area | |
| Which restaurants were **returned** for it, each with its detour **band** (0–5, 5–10, 10–15, 15+ minutes) | The detour in minutes, the arrival time |

No analytics table has a column that could hold a coordinate, a geometry or an address; tests check the schema and
the stored rows for both.

The bands are configuration (`analytics.bands`), not constants.

## Figures (`/admin/analytics/routes`)

| Figure | Definition |
|---|---|
| Journey searches | Journeys the backend planned in the period |
| Searches without a restaurant | Planned journeys for which discovery found no restaurant — and not because of the traveller's own filters |
| Restaurant impressions | Times a restaurant was **returned** in a discovery answer, once per journey and restaurant. Returned by the backend — not proven to have been seen on a screen |
| Restaurant views from a journey | Restaurant pages opened in a journey context (behavioural) |
| Orders from a journey | Orders placed in the period whose cart was linked to a journey (order domain) |
| Journey to order | Orders from a journey ÷ journey searches of the same period (not a session funnel) |
| Average detour | The detour recorded on the orders that came from a journey |
| Restaurants returned per journey | Impressions ÷ journeys that found at least one restaurant |

By corridor: searches, searches without a restaurant, impressions, views, orders, completed orders, conversion,
average detour. A journey along two corridors counts in both; the market total counts it once. Journeys along no
static corridor are counted for the market as a whole — no dynamic geographic bucket is made up for them.

## Coverage gaps

Searches without a restaurant are demand the service does not cover yet. They are reported by reason (no service
area along the route, no restaurant near the route, no restaurant within the detour limit), by the city near the
origin, by the city near the destination, and by corridor. This is information for people: **nothing here activates
a market, a city or a service area.**

## Small groups

A row built on fewer journeys than the minimum cohort (`analytics.privacy.min_cohort`, default 5) is not shown: a
corridor, a city or a band with a handful of travellers would describe those travellers. Such rows are folded into
"other" — and if together they are still under the minimum, "other" has no numbers either. The same rule applies to
a restaurant's own detour bands and to the new / returning split of its customers.

## What a restaurant sees

Its own impressions, views, orders from a journey, average detour, and the detour bands of its own orders. No
traveller's route, origin or destination — none exists to show.

## Heatmaps

Not built. If one is added it must be drawn from these aggregates (corridors, cities, bands) — never from stored
routes, because none are stored.

## History

Recorded from Module 38 on. Journeys themselves are deleted about an hour after they are planned, so nothing earlier
can be reconstructed: **HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38.**
