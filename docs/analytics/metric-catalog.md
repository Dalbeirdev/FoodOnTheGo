# Metric catalog

> GENERATED from `backend/app/Services/Analytics/MetricCatalog.php` by `php artisan analytics:catalog --write`. Do not edit by hand: change the catalog and run the command (a test compares this file with the command's output).

Every figure the analytics API can return is defined here — 120 metrics. A screen cannot show a figure that is not in this catalog: the API refuses to build one.

Words used on purpose: **GMV is not revenue.** Platform revenue is commission plus the platform's own fees, from the financial ledger. A restaurant's gross sales, its net earnings, what it is owed and what was paid out are four different figures. Money is kept in minor units and reported per currency — currencies are never added together. Every definition, threshold and owner is a DEVELOPMENT DEFAULT, **PENDING BUSINESS APPROVAL**.

Freshness: `AGGREGATED` = read model rebuilt about every 5 minutes · `OPERATIONAL` = read from the operational records at the moment of the request · `LEDGER` = read from the financial ledger at the moment of the request. Nothing aggregated is called "live".

## Platform

### Gross merchandise value (GMV)

| | |
|---|---|
| Key | `gmv` |
| Unit | money |
| Definition | The sum of what customers paid for the orders COMPLETED in the period: items after discounts, plus tax and fees. |
| Source | Order domain (orders.total_minor of completed orders) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Orders that are active, rejected or cancelled. Refunds are NOT subtracted. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | GMV is NOT FoodOnTheGo revenue: almost all of it is the restaurants' money. |

## Orders

### Orders placed

| | |
|---|---|
| Key | `orders_placed` |
| Unit | count |
| Definition | Orders created in the period (by the time they were placed). |
| Source | Order domain (orders.placed_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None: every order counts, whatever became of it. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Orders completed

| | |
|---|---|
| Key | `orders_completed` |
| Unit | count |
| Definition | Orders that reached COMPLETED in the period (by the time they were completed). |
| Source | Order domain (orders.completed_at, status COMPLETED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders that are active, rejected or cancelled. A picked-up order counts once it is completed. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | A "successful order" on any screen means this. |

### Orders rejected

| | |
|---|---|
| Key | `orders_rejected` |
| Unit | count |
| Definition | Orders placed in the period that the restaurant rejected. |
| Source | Order domain (status REJECTED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Counted on the day the order was placed. |

### Orders cancelled

| | |
|---|---|
| Key | `orders_cancelled` |
| Unit | count |
| Definition | Orders placed in the period that were cancelled. |
| Source | Order domain (status CANCELLED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Counted on the day the order was placed. |

### Orders still in progress

| | |
|---|---|
| Key | `orders_in_progress` |
| Unit | count |
| Definition | Orders placed in the period that have not reached a final state yet. |
| Source | Order domain (status WAITING_FOR_RESTAURANT, ACCEPTED, PREPARING, READY_FOR_PICKUP, PICKED_UP) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | These are never counted as completed. |

### Orders with a refund

| | |
|---|---|
| Key | `orders_refunded` |
| Unit | count |
| Definition | Orders placed in the period for which at least one refund completed. |
| Source | Refund domain (refunds with status REFUNDED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Refunds that are requested, processing, failed or cancelled. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Order completion rate

| | |
|---|---|
| Key | `order_completion_rate` |
| Unit | percent |
| Definition | Of the orders placed in the period that reached a final state, the share that completed. |
| Source | Order domain |
| Numerator | Orders placed in the period with status COMPLETED |
| Denominator | Orders placed in the period with status COMPLETED, REJECTED or CANCELLED |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders still in progress are in neither number, so a recent period is not understated. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Order rejection rate

| | |
|---|---|
| Key | `order_rejection_rate` |
| Unit | percent |
| Definition | Of the orders placed in the period, the share the restaurant rejected. |
| Source | Order domain |
| Numerator | Orders placed in the period with status REJECTED |
| Denominator | Orders placed in the period |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Average order value

| | |
|---|---|
| Key | `average_order_value` |
| Unit | money |
| Definition | What a completed order came to, on average. |
| Source | Order domain (orders.total_minor) |
| Numerator | Sum of the totals of orders completed in the period |
| Denominator | Orders completed in the period |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Refunds are not subtracted (they are reported on their own). |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | The total a customer paid: items after discounts, plus tax and fees. |

### Median order value

| | |
|---|---|
| Key | `median_order_value` |
| Unit | money |
| Definition | The middle total of the orders completed in the period. |
| Source | Order domain (orders.total_minor) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Refunds are not subtracted. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Time to accept

| | |
|---|---|
| Key | `acceptance_time` |
| Unit | seconds |
| Definition | From an order being placed to the restaurant accepting it. |
| Source | Order domain (placed_at → accepted_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders that were never accepted. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Average, median and 90th percentile. An operational figure — not a judgement of the food. |

### Preparation time

| | |
|---|---|
| Key | `preparation_time` |
| Unit | seconds |
| Definition | From the restaurant accepting an order to marking it ready. |
| Source | Order domain (accepted_at → ready_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders that were never marked ready. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Compared with the estimate the customer was shown; operational only. |

### Ready-to-pickup time

| | |
|---|---|
| Key | `pickup_wait_time` |
| Unit | seconds |
| Definition | From an order being ready to it being handed over. |
| Source | Order domain (ready_at → picked_up_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders that were never picked up. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Time to completion

| | |
|---|---|
| Key | `completion_time` |
| Unit | seconds |
| Definition | From an order being placed to it being completed. |
| Source | Order domain (placed_at → completed_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders that did not complete. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Includes the time a scheduled order waited for its pickup time. |

## Customers

### New accounts

| | |
|---|---|
| Key | `new_accounts` |
| Unit | count |
| Definition | Customer accounts created in the period. |
| Source | Customer accounts (customers.created_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | An account, not an order: see first-time ordering customers. |

### First-time ordering customers

| | |
|---|---|
| Key | `first_time_ordering_customers` |
| Unit | count |
| Definition | Customers whose FIRST completed order was completed in the period. |
| Source | Order domain (first completed order per customer) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Ordering customers

| | |
|---|---|
| Key | `ordering_customers` |
| Unit | count |
| Definition | Different customers with at least one order completed in the period. |
| Source | Order domain (completed orders) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | A customer is counted once however many orders they completed. |

### Repeat customers

| | |
|---|---|
| Key | `repeat_customers` |
| Unit | count |
| Definition | Ordering customers of the period who, by its end, had completed at least two orders in total. |
| Source | Order domain (completed orders) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Repeat customer share

| | |
|---|---|
| Key | `repeat_order_rate` |
| Unit | percent |
| Definition | Of the customers who ordered in the period, the share who are repeat customers. |
| Source | Order domain |
| Numerator | Repeat customers |
| Denominator | Ordering customers |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Active customers

| | |
|---|---|
| Key | `active_customers` |
| Unit | count |
| Definition | Different signed-in customers who, in the period, placed an order or did something the applications record while signed in. |
| Source | Order domain + behavioural events of signed-in sessions |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Guests. A customer who only received a notification. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | Behavioural events exist from Module 38 on; earlier periods count ordering activity only. |

### Orders per ordering customer

| | |
|---|---|
| Key | `orders_per_customer` |
| Unit | number |
| Definition | Completed orders of the period divided by the customers who completed them. |
| Source | Order domain |
| Numerator | Orders completed in the period |
| Denominator | Ordering customers |
| Calculation | Numerator ÷ denominator over the whole period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

## Customer funnel

### Sessions that searched a journey

| | |
|---|---|
| Key | `funnel_sessions` |
| Unit | count |
| Definition | Sessions that started a journey search in the period — the top of the funnel. |
| Source | Behavioural event journey_search_started |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Sessions that never searched a journey. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Journey search to completed order

| | |
|---|---|
| Key | `funnel_overall_conversion` |
| Unit | percent |
| Definition | Of the sessions that searched a journey in the period, the share whose order was completed. |
| Source | Behavioural events + payment and order domains |
| Numerator | Sessions of the period that reached "order completed" |
| Denominator | Sessions of the period that reached "journey search" |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | Sessions against sessions. A session is bounded: an old search never converts against a later order. |

### Conversion from the previous stage

| | |
|---|---|
| Key | `funnel_stage_conversion` |
| Unit | percent |
| Definition | Of the sessions that reached a stage, the share that reached the next one. |
| Source | Behavioural events + payment and order domains |
| Numerator | Sessions that reached the next stage |
| Denominator | Sessions that reached the stage |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Growth (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

## Restaurants

### Sales of completed orders

| | |
|---|---|
| Key | `completed_order_total` |
| Unit | money |
| Definition | What customers paid for the orders completed in the period: items after discounts, plus tax and fees. |
| Source | Order domain (orders.total_minor of completed orders) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Orders that are active, rejected or cancelled. Refunds are NOT subtracted. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | Not what the restaurant earned: commission is not taken off here. Earnings are on the Earnings page. |

### Restaurants with completed orders

| | |
|---|---|
| Key | `active_restaurants` |
| Unit | count |
| Definition | Restaurant locations with at least one order completed in the period. |
| Source | Order domain |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | Not the same as "orderable now". |

### Restaurant gross sales

| | |
|---|---|
| Key | `restaurant_gross_sales` |
| Unit | money |
| Definition | The merchandise restaurants sold through the platform in orders RECOGNIZED in the period. |
| Source | Financial allocations (Module 36: merchandise_gross_minor, recognized_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Tax and platform fees. Orders that are not recognized. |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | The same figure the finance report calls gross marketplace sales. |

### Restaurant net earnings

| | |
|---|---|
| Key | `restaurant_net_earnings` |
| Unit | money |
| Definition | What restaurants earned on the orders recognized in the period, after commission and their own discounts. |
| Source | Financial allocations (Module 36: restaurant_net_minor) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Later refund or dispute adjustments (reported in the finance module). |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | Identical to the finance module — it is read from it. |

### Acceptance rate

| | |
|---|---|
| Key | `restaurant_acceptance_rate` |
| Unit | percent |
| Definition | Of the orders placed in the period that the restaurant answered, the share it accepted. |
| Source | Order domain |
| Numerator | Orders placed in the period that were accepted |
| Denominator | Orders placed in the period that were accepted or rejected |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders still waiting for the restaurant, and orders cancelled before an answer. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Refund rate (orders)

| | |
|---|---|
| Key | `restaurant_refund_rate` |
| Unit | percent |
| Definition | Of the orders placed in the period, the share with a completed refund. |
| Source | Order + refund domains |
| Numerator | Orders placed in the period with a completed refund |
| Denominator | Orders placed in the period |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

## Menu

### Menu views

| | |
|---|---|
| Key | `menu_views` |
| Unit | count |
| Definition | Times a restaurant's menu was displayed. |
| Source | Behavioural event menu_viewed |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | Recorded from Module 38 on. |

### Item views

| | |
|---|---|
| Key | `item_views` |
| Unit | count |
| Definition | Times the detail of a menu item was opened. |
| Source | Behavioural event item_viewed |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | Recorded from Module 38 on. |

### Add-to-cart

| | |
|---|---|
| Key | `cart_adds` |
| Unit | count |
| Definition | Times an item was accepted into a cart. |
| Source | Behavioural event item_added_to_cart |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | What was put in a cart — not what was sold. |

### Units sold

| | |
|---|---|
| Key | `units_sold` |
| Unit | count |
| Definition | Units of menu items in orders completed in the period. |
| Source | Order items of completed orders (snapshots) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Items of orders that did not complete. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Item sales

| | |
|---|---|
| Key | `item_sales` |
| Unit | money |
| Definition | Line subtotals of the items in orders completed in the period. |
| Source | Order items of completed orders (snapshots) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Order-level discounts, tax and fees. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Add-to-cart rate

| | |
|---|---|
| Key | `add_to_cart_rate` |
| Unit | percent |
| Definition | Of the sessions that saw the menu, the share that added an item to a cart. |
| Source | Behavioural events |
| Numerator | Sessions with item_added_to_cart at the restaurant |
| Denominator | Sessions with menu_viewed at the restaurant |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Restaurant success (PENDING BUSINESS APPROVAL) |
| Known limitations | Sessions against sessions — never mixed with customers. |

## Journeys, routes and corridors

### Journey searches

| | |
|---|---|
| Key | `journey_searches` |
| Unit | count |
| Definition | Journeys the backend planned in the period (a route was found). |
| Source | Server-recorded event journey_search_succeeded |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Searches that failed before a route was found. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | Recorded from Module 38 on. No address, coordinate or route is kept. |

### Searches without a restaurant

| | |
|---|---|
| Key | `no_result_searches` |
| Unit | count |
| Definition | Planned journeys for which discovery found no restaurant — and not because of the traveller's own filters. |
| Source | Server-recorded event journey_search_no_result |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | An empty answer caused by filters. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Share of searches without a restaurant

| | |
|---|---|
| Key | `no_result_rate` |
| Unit | percent |
| Definition | Of the journeys planned in the period, the share that found no restaurant. |
| Source | Server-recorded events |
| Numerator | Searches without a restaurant |
| Denominator | Journey searches |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Restaurant impressions

| | |
|---|---|
| Key | `restaurant_impressions` |
| Unit | count |
| Definition | Times a restaurant was RETURNED in a discovery answer for a journey (once per journey and restaurant). |
| Source | Server-recorded event restaurant_impression |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | Returned by the backend — not proven to have been seen on a screen. |

### Restaurant views from a journey

| | |
|---|---|
| Key | `journey_restaurant_views` |
| Unit | count |
| Definition | Times a restaurant page was opened from a journey. |
| Source | Behavioural event restaurant_viewed with a journey |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Orders from a journey

| | |
|---|---|
| Key | `journey_orders` |
| Unit | count |
| Definition | Orders placed in the period whose cart was linked to a journey. |
| Source | Order domain (the journey recorded on the order) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Journey to order

| | |
|---|---|
| Key | `journey_order_conversion` |
| Unit | percent |
| Definition | Orders from a journey per journey planned in the period. |
| Source | Order domain + server-recorded events |
| Numerator | Orders from a journey placed in the period |
| Denominator | Journey searches of the period |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | Orders against journeys of the same period; not a session funnel. |

### Average detour of ordering travellers

| | |
|---|---|
| Key | `average_detour` |
| Unit | seconds |
| Definition | The detour to the restaurant, for the orders from a journey placed in the period. |
| Source | Order domain (the detour recorded on the order) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Orders without a journey. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Restaurants returned per journey

| | |
|---|---|
| Key | `average_restaurants_returned` |
| Unit | number |
| Definition | Restaurant impressions divided by the journeys that found at least one restaurant. |
| Source | Server-recorded events |
| Numerator | Restaurant impressions |
| Denominator | Journey searches minus searches without a restaurant |
| Calculation | Numerator ÷ denominator over the whole period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Marketplace (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

## Pickup

### ASAP pickups

| | |
|---|---|
| Key | `pickup_asap_orders` |
| Unit | count |
| Definition | Orders placed in the period for pickup as soon as possible. |
| Source | Order domain (pickup_mode ASAP) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Scheduled pickups

| | |
|---|---|
| Key | `pickup_scheduled_orders` |
| Unit | count |
| Definition | Orders placed in the period for a chosen pickup time. |
| Source | Order domain (pickup_mode SCHEDULED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Pickup lead time

| | |
|---|---|
| Key | `pickup_lead_time` |
| Unit | seconds |
| Definition | From an order being placed to its pickup time, on average. |
| Source | Order domain (placed_at → pickup_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Pickup times refused

| | |
|---|---|
| Key | `pickup_refusals` |
| Unit | count |
| Definition | Times the backend refused a pickup time when it was chosen. |
| Source | Server-recorded event pickup_slot_refused |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Recorded from Module 38 on. |

### Pickup times refused: slot full

| | |
|---|---|
| Key | `pickup_slot_full` |
| Unit | count |
| Definition | Refusals because the slot had no capacity left. |
| Source | Server-recorded event pickup_slot_refused (reason SLOT_FULL) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Capacity used in slots with orders

| | |
|---|---|
| Key | `pickup_capacity_utilization` |
| Unit | percent |
| Definition | Units reserved by orders, of the capacity of the slots that had at least one order. |
| Source | Pickup reservations of orders + the restaurant's configured capacity per slot |
| Numerator | Units reserved by orders |
| Denominator | Capacity per slot × slots with at least one order |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Restaurants with unlimited capacity: nothing is made up for them. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Only where a capacity per slot is configured. |

### Recommended time chosen

| | |
|---|---|
| Key | `recommended_slot_acceptance` |
| Unit | percent |
| Definition | Of the pickup selections where the application said whether the time was the recommended one, the share that was. |
| Source | Behavioural event pickup_selected |
| Numerator | Selections of the recommended time |
| Denominator | Selections that say whether the time was recommended |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

## Payments

### Payment attempts

| | |
|---|---|
| Key | `payment_attempts` |
| Unit | count |
| Definition | Payment attempts created in the period. |
| Source | Payment domain (payment_attempts.created_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Payments verified

| | |
|---|---|
| Key | `payments_verified` |
| Unit | count |
| Definition | Attempts created in the period that the backend verified. |
| Source | Payment domain (status VERIFIED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | Verified by the backend against the provider — never reported by a client. |

### Payments failed

| | |
|---|---|
| Key | `payments_failed` |
| Unit | count |
| Definition | Attempts created in the period that failed. |
| Source | Payment domain (status FAILED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Payments cancelled

| | |
|---|---|
| Key | `payments_cancelled` |
| Unit | count |
| Definition | Attempts created in the period that the customer cancelled. |
| Source | Payment domain (status CANCELLED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Payments expired

| | |
|---|---|
| Key | `payments_expired` |
| Unit | count |
| Definition | Attempts created in the period that ran out of time. |
| Source | Payment domain (status EXPIRED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | Not completed — not called a failure. |

### Payments pending

| | |
|---|---|
| Key | `payments_pending` |
| Unit | count |
| Definition | Attempts created in the period that are not finished yet. |
| Source | Payment domain (status CREATED, PROVIDER_READY, PROCESSING, PENDING, AUTHORIZED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | Never counted as verified. |

### Payments unknown or in review

| | |
|---|---|
| Key | `payments_unknown` |
| Unit | count |
| Definition | Attempts created in the period whose outcome is not known or needs a person. |
| Source | Payment domain (status UNKNOWN, REVIEW_REQUIRED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Payment success rate

| | |
|---|---|
| Key | `payment_success_rate` |
| Unit | percent |
| Definition | Of the attempts created in the period that are finished, the share that was verified. |
| Source | Payment domain |
| Numerator | Attempts with status VERIFIED |
| Denominator | Attempts with status VERIFIED, FAILED, CANCELLED or EXPIRED |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Pending and unknown attempts are in neither number. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Verified payment amount

| | |
|---|---|
| Key | `payments_verified_amount` |
| Unit | money |
| Definition | The amounts of the attempts created in the period that were verified. |
| Source | Payment domain (payment_attempts.amount_minor, status VERIFIED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | Customer money received — not revenue. |

### Time to verification

| | |
|---|---|
| Key | `payment_verification_time` |
| Unit | seconds |
| Definition | From a payment attempt being created to it being verified, on average. |
| Source | Payment domain (created_at → verified_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Attempts that were not verified. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

## Refunds

### Refunds completed

| | |
|---|---|
| Key | `refunds_completed` |
| Unit | count |
| Definition | Refunds that completed in the period. |
| Source | Refund domain (status REFUNDED, completed_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Refunds that are requested, processing, failed or cancelled. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Refund amount

| | |
|---|---|
| Key | `refund_amount` |
| Unit | money |
| Definition | The money that went back to customers in refunds completed in the period. |
| Source | Refund domain (approved amount of REFUNDED refunds) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | From the refunds themselves — never estimated from an order's status. |

### Full refunds

| | |
|---|---|
| Key | `refunds_full` |
| Unit | count |
| Definition | Refunds completed in the period for the whole payment. |
| Source | Refund domain (type FULL) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Partial refunds

| | |
|---|---|
| Key | `refunds_partial` |
| Unit | count |
| Definition | Refunds completed in the period for a part of the payment. |
| Source | Refund domain (type PARTIAL) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Refunds in progress

| | |
|---|---|
| Key | `refunds_in_progress` |
| Unit | count |
| Definition | Refunds asked for in the period that are not finished. |
| Source | Refund domain (status REQUIRED, REQUESTED, APPROVED, SUBMITTING, SUBMISSION_UNKNOWN, PROCESSING, REVIEW_REQUIRED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Refunds failed

| | |
|---|---|
| Key | `refunds_failed` |
| Unit | count |
| Definition | Refunds asked for in the period that failed. |
| Source | Refund domain (status FAILED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Refund processing time

| | |
|---|---|
| Key | `refund_processing_time` |
| Unit | seconds |
| Definition | From a refund being asked for to it completing, on average. |
| Source | Refund domain (requested_at → completed_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Refunds that did not complete. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Refund rate (orders)

| | |
|---|---|
| Key | `refund_rate_orders` |
| Unit | percent |
| Definition | Of the orders placed in the period, the share with a completed refund. |
| Source | Order + refund domains |
| Numerator | Orders placed in the period with a completed refund |
| Denominator | Orders placed in the period |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | A different figure from the refund rate by amount. |

### Refund rate (amount)

| | |
|---|---|
| Key | `refund_rate_amount` |
| Unit | percent |
| Definition | Refund amount of the period as a share of the verified payment amount of the period. |
| Source | Refund + payment domains |
| Numerator | Refund amount (refunds completed in the period) |
| Denominator | Verified payment amount (attempts created in the period) |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Payments (PENDING BUSINESS APPROVAL) |
| Known limitations | A different figure from the refund rate by orders. |

## Finance (from the financial ledger)

### Gross marketplace sales

| | |
|---|---|
| Key | `gross_marketplace_sales` |
| Unit | money |
| Definition | The merchandise restaurants sold through the platform in orders recognized in the period. |
| Source | Financial allocations (Module 36) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | Tax and platform fees. |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | NOT FoodOnTheGo revenue. |

### Platform commission

| | |
|---|---|
| Key | `platform_commission` |
| Unit | money |
| Definition | Commission the platform earned in the period. |
| Source | Financial ledger (account PLATFORM_COMMISSION_REVENUE) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | Every commission plan on this machine is a TEST FIXTURE. |

### Platform fees

| | |
|---|---|
| Key | `platform_fee_revenue` |
| Unit | money |
| Definition | Customer fees that are the platform's own, in the period. |
| Source | Financial ledger (account PLATFORM_FEE_REVENUE) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Platform revenue

| | |
|---|---|
| Key | `platform_revenue` |
| Unit | money |
| Definition | Commission plus the platform's own fees. |
| Source | Financial ledger |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | GMV and gross marketplace sales are never part of it. |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | The only figure called revenue. |

### Customer payments

| | |
|---|---|
| Key | `customer_payments` |
| Unit | money |
| Definition | Verified customer payments posted to the ledger in the period. |
| Source | Financial ledger (transactions PAYMENT_VERIFIED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | Customer money — not revenue. |

### Refunds (ledger)

| | |
|---|---|
| Key | `ledger_refunds` |
| Unit | money |
| Definition | Completed refunds posted to the ledger in the period. |
| Source | Financial ledger (transactions REFUND_COMPLETED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Payment provider fees

| | |
|---|---|
| Key | `provider_fees` |
| Unit | money |
| Definition | What the payment provider itself reported as its fees, posted in the period. |
| Source | Financial ledger (account PROVIDER_FEE_EXPENSE) from imported provider settlement records |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | Never estimated: without an import the figure is zero. |

### Platform-funded discounts

| | |
|---|---|
| Key | `promotion_expense` |
| Unit | money |
| Definition | Discounts the platform paid for, in the period. |
| Source | Financial ledger (account PROMOTION_EXPENSE) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Owed to restaurants now

| | |
|---|---|
| Key | `restaurant_payable` |
| Unit | money |
| Definition | What the platform owes restaurants at this moment. |
| Source | Financial ledger (balance of RESTAURANT_PAYABLE) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | A balance, not a figure of the period. |

### Paid out to restaurants

| | |
|---|---|
| Key | `restaurant_paid_out` |
| Unit | money |
| Definition | Payouts to restaurants posted to the ledger in the period. |
| Source | Financial ledger (account PAYOUT_CLEARING) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | One figure per currency (INR in India). Currencies are never added together. |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | LIVE PAYOUTS ARE DISABLED: on this machine payouts come from the fake payout provider only. |

### Settlements generated

| | |
|---|---|
| Key | `settlements_generated` |
| Unit | count |
| Definition | Restaurant settlements generated in the period. |
| Source | Settlement domain (restaurant_settlements.generated_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Settlement to payout

| | |
|---|---|
| Key | `settlement_time_to_payout` |
| Unit | seconds |
| Definition | From a settlement being generated to it being paid, on average. |
| Source | Settlement domain (generated_at → paid_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Settlements that are not paid. |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Open reconciliation differences

| | |
|---|---|
| Key | `reconciliation_differences_open` |
| Unit | count |
| Definition | Differences between the records that nobody has resolved — now. |
| Source | Reconciliation (status OPEN, INVESTIGATING) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | LEDGER — Read from the financial ledger at the moment of the request |
| Owner | Finance (PENDING BUSINESS APPROVAL) |
| Known limitations | A current figure. |

## Support

### Cases opened

| | |
|---|---|
| Key | `support_cases_created` |
| Unit | count |
| Definition | Support cases opened in the period. |
| Source | Support domain (support_cases.created_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Cases resolved

| | |
|---|---|
| Key | `support_cases_resolved` |
| Unit | count |
| Definition | Cases resolved in the period. |
| Source | Support domain (resolved_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Cases closed

| | |
|---|---|
| Key | `support_cases_closed` |
| Unit | count |
| Definition | Cases closed in the period. |
| Source | Support domain (closed_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Cases reopened

| | |
|---|---|
| Key | `support_cases_reopened` |
| Unit | count |
| Definition | Cases whose last reopening was in the period. |
| Source | Support domain (reopened_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Cases escalated

| | |
|---|---|
| Key | `support_cases_escalated` |
| Unit | count |
| Definition | Cases escalated in the period. |
| Source | Support domain (escalated_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Targets missed

| | |
|---|---|
| Key | `support_sla_breached` |
| Unit | count |
| Definition | Cases that missed an internal target in the period. |
| Source | Support domain (sla_breached_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | The targets are TEST FIXTURES and internal — not a promise to anyone. |

### Reopen rate

| | |
|---|---|
| Key | `support_reopen_rate` |
| Unit | percent |
| Definition | Cases reopened in the period per case resolved in the period. |
| Source | Support domain |
| Numerator | Cases reopened in the period |
| Denominator | Cases resolved in the period |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | A quality indicator of the service — not of a person. |

### First response time

| | |
|---|---|
| Key | `support_first_response_time` |
| Unit | seconds |
| Definition | From a case opened in the period to the first answer of support, on average. |
| Source | Support domain (created_at → first_response_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Cases without a first response. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | Wall-clock time. |

### Resolution time

| | |
|---|---|
| Key | `support_resolution_time` |
| Unit | seconds |
| Definition | From a case being opened to it being resolved, for the cases resolved in the period, on average. |
| Source | Support domain (created_at → resolved_at) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Support (PENDING BUSINESS APPROVAL) |
| Known limitations | Wall-clock time: the time support waited for the requester is NOT taken out. |

## Reviews

### Reviews published

| | |
|---|---|
| Key | `reviews_published` |
| Unit | count |
| Definition | Reviews published in the period that are still published. |
| Source | Review domain (status PUBLISHED, published_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Reviews that are hidden, rejected, removed or waiting for moderation. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Trust and quality (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Average rating

| | |
|---|---|
| Key | `average_rating` |
| Unit | rating |
| Definition | The average of the overall ratings of the reviews published in the period. |
| Source | Review domain (overall_rating of published reviews) |
| Numerator | Sum of the ratings |
| Denominator | Reviews published in the period |
| Calculation | Sum of ratings ÷ number of reviews over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | Hidden, rejected and removed reviews. |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Trust and quality (PENDING BUSINESS APPROVAL) |
| Known limitations | The rating shown to customers is the all-time one; this is the period's. |

### Restaurant response rate

| | |
|---|---|
| Key | `review_response_rate` |
| Unit | percent |
| Definition | Of the reviews published in the period, the share the restaurant answered. |
| Source | Review domain |
| Numerator | Published reviews of the period with a published response |
| Denominator | Reviews published in the period |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Trust and quality (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Reports of reviews

| | |
|---|---|
| Key | `reviews_reported` |
| Unit | count |
| Definition | Reports made about reviews in the period. |
| Source | Review domain (review_reports.created_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Trust and quality (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

## Notifications

### Notifications created

| | |
|---|---|
| Key | `notifications_created` |
| Unit | count |
| Definition | Notifications created for customers in the period. |
| Source | Notification domain (notifications.created_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Push waiting

| | |
|---|---|
| Key | `push_queued` |
| Unit | count |
| Definition | Push deliveries of the period's notifications that are waiting or being sent. |
| Source | Notification domain (PUSH: QUEUED, SENDING, RETRY_SCHEDULED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Push sent

| | |
|---|---|
| Key | `push_sent` |
| Unit | count |
| Definition | Push deliveries of the period's notifications that the provider accepted. |
| Source | Notification domain (PUSH: SENT, DELIVERED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | "Sent" means the provider accepted it. It does NOT mean anybody read it. |

### Push failed

| | |
|---|---|
| Key | `push_failed` |
| Unit | count |
| Definition | Push deliveries of the period's notifications that failed. |
| Source | Notification domain (PUSH: FAILED) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Invalid device tokens

| | |
|---|---|
| Key | `push_invalid_tokens` |
| Unit | count |
| Definition | Push deliveries refused because the device token is no longer valid. |
| Source | Notification domain (PUSH: INVALID_DESTINATION) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Push not sent by preference

| | |
|---|---|
| Key | `push_preference_skips` |
| Unit | count |
| Definition | Push deliveries not attempted because of the customer's settings or because there is no device. |
| Source | Notification domain (PUSH: SKIPPED, with the policy result) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### In-app notifications read

| | |
|---|---|
| Key | `in_app_read` |
| Unit | count |
| Definition | Notifications of the period that were marked read in the application. |
| Source | Notification domain (notifications.read_at) |
| Calculation | Sum over the period |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | The in-app read mark only. |

### In-app read rate

| | |
|---|---|
| Key | `in_app_read_rate` |
| Unit | percent |
| Definition | Of the notifications created in the period, the share marked read in the application. |
| Source | Notification domain |
| Numerator | Notifications of the period with a read mark |
| Denominator | Notifications created in the period |
| Calculation | Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | Not a push open rate: opening a push is not tracked. |

### Push provider answer time

| | |
|---|---|
| Key | `push_provider_latency` |
| Unit | milliseconds |
| Definition | How long the push provider took to answer a delivery attempt, on average. |
| Source | Notification domain (delivery attempts) |
| Calculation | Average over the period; median and 90th percentile where the report shows them |
| Filters | Period, market; restaurant location where the report is a restaurant's |
| Time zone | The market's reporting time zone (markets.default_timezone); a restaurant's own report uses the restaurant's time zone |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Platform (PENDING BUSINESS APPROVAL) |
| Known limitations | On this machine the push provider is the development provider. |

## Figures of this moment (not of a period)

### Orders waiting for a refund

| | |
|---|---|
| Key | `orders_refund_required` |
| Unit | count |
| Definition | Orders whose refund is required and not issued yet — now. |
| Source | Order domain (refund_status REQUIRED) |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | AGGREGATED — Aggregated read model, rebuilt about every 5 minutes |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | A current figure, not a figure of the period. |

### Restaurants accepting orders now

| | |
|---|---|
| Key | `orderable_restaurants` |
| Unit | count |
| Definition | Approved restaurant locations that are accepting orders at this moment. |
| Source | Restaurant domain (status APPROVED, accepting_orders) |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | A current figure, not a figure of the period. |

### Open cases

| | |
|---|---|
| Key | `support_open_cases` |
| Unit | count |
| Definition | Cases that are not resolved, closed or cancelled — now. |
| Source | Support domain (status) |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | A current figure. |

### Unassigned cases

| | |
|---|---|
| Key | `support_unassigned_cases` |
| Unit | count |
| Definition | Open cases nobody is assigned to — now. |
| Source | Support domain |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | A current figure. |

### Urgent open cases

| | |
|---|---|
| Key | `support_urgent_cases` |
| Unit | count |
| Definition | Open cases with priority URGENT — now. |
| Source | Support domain |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | A current figure. |

### Targets at risk

| | |
|---|---|
| Key | `support_sla_at_risk` |
| Unit | count |
| Definition | Open cases close to missing an internal target — now. |
| Source | Support domain (sla_status AT_RISK) |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Internal targets on TEST FIXTURE policies. |

### Open cases past a target

| | |
|---|---|
| Key | `support_sla_breached_open` |
| Unit | count |
| Definition | Open cases that missed an internal target — now. |
| Source | Support domain (sla_status BREACHED) |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | Internal targets on TEST FIXTURE policies. |

### Active orders

| | |
|---|---|
| Key | `live_active_orders` |
| Unit | count |
| Definition | Orders that are waiting, accepted, being prepared or ready — now. |
| Source | Order domain |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | A current figure. |

### Waiting for the restaurant

| | |
|---|---|
| Key | `live_waiting_for_restaurant` |
| Unit | count |
| Definition | Orders the restaurant has not answered — now. |
| Source | Order domain |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Being prepared

| | |
|---|---|
| Key | `live_preparing` |
| Unit | count |
| Definition | Orders accepted or in preparation — now. |
| Source | Order domain |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Ready for pickup

| | |
|---|---|
| Key | `live_ready` |
| Unit | count |
| Definition | Orders ready and not picked up — now. |
| Source | Order domain |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Payments needing recovery

| | |
|---|---|
| Key | `live_payment_recovery` |
| Unit | count |
| Definition | Verified payments that have no order — now. |
| Source | Payment domain (fulfillment RECOVERY_REQUIRED) |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |

### Refunds needing a person

| | |
|---|---|
| Key | `live_refund_review` |
| Unit | count |
| Definition | Refunds waiting for review — now. |
| Source | Refund domain (status REVIEW_REQUIRED) |
| Calculation | Sum over the period |
| Filters | Market (the caller's markets) |
| Time zone | Not applicable (a figure of this moment) |
| Currency | Not a money figure |
| Exclusions | None |
| Freshness | OPERATIONAL — Read from the operational records at the moment of the request |
| Owner | Operations (PENDING BUSINESS APPROVAL) |
| Known limitations | None recorded |
