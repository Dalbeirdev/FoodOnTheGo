# The customer funnel

```
 1  Journey search        behavioural event  journey_search_started      ┐
        │  did not continue: searched, never saw results                 │
 2  Route results         behavioural event  journey_results_viewed      │
        │  did not continue: saw results, opened no restaurant           │
 3  Restaurant viewed     behavioural event  restaurant_viewed           │  stages 1–8:
        │                                                                │  events of ONE session,
 4  Menu viewed           behavioural event  menu_viewed                 │  in this order
        │  did not continue: saw a menu, added nothing                   │
 5  Added to cart         behavioural event  item_added_to_cart          │
        │                                                                │
 6  Pickup selected       behavioural event  pickup_selected             │
        │  did not continue: chose a time, opened no checkout            │
 7  Checkout started      behavioural event  checkout_started            │
        │  did not continue: opened a checkout, did not press Pay        │
 8  Payment started       behavioural event  payment_started             ┘
        │  payment not verified (failed, cancelled, expired, pending)
 9  Payment verified      PAYMENT DOMAIN: a VERIFIED attempt of the session's checkout   ┐  stages 9–11:
        │  verified payment without an order (a recovery case)                           │  read from the
10  Order created         ORDER DOMAIN: the order of that checkout                       │  domains — never
        │  rejected, cancelled, or still in progress                                     │  from an event
11  Order completed       ORDER DOMAIN: that order is COMPLETED                          ┘
```

## Unit

The unit is the **session**: a random identifier the application makes up, replaced after 30 minutes idle or 12
hours. Three numbers are reported per stage and never mixed:

| Number | Meaning |
|---|---|
| `sessions` | Sessions that reached the stage **in order**. This is the funnel. |
| `customers` | Different signed-in customers among those sessions (a customer of two days counts once). |
| `events` | Every occurrence of the stage's event, repeats included. Not available for stages 9–11. |

Every conversion divides sessions by sessions.

## What counts

- A session **reaches a stage only after the stage before it**. A later event without its predecessor (a session that
  opened a checkout without the stages before) is not counted and is reported by the data-quality check.
- A session's events count only **inside its own life** (12 hours from its first event). A search in January never
  converts against an order in October.
- A session is counted on the **local day of its first event** (its cohort day); later stages of the same session
  count for that day.
- Stage 9 needs a payment attempt of the session's checkout that the backend **verified within 24 hours** of the
  checkout being opened. Stage 10 needs that checkout's order. Stage 11 needs that order to be COMPLETED; sessions
  are looked at again for 7 days so that an order completed later still counts.
- A checkout reference is kept on an event only when the server saw that the checkout belongs to the signed-in
  caller. Knowing somebody's checkout id proves nothing.
- A session that opened several checkouts counts once, at the furthest any of them came.

## Formulas

| Figure | Numerator | Denominator |
|---|---|---|
| Conversion from the previous stage | Sessions that reached stage *n* | Sessions that reached stage *n − 1* |
| Overall conversion (journey search → completed order) | Sessions that reached stage 11 | Sessions that reached stage 1 |
| Did not continue at stage *n* | Sessions at stage *n* minus sessions at stage *n + 1* | — (a count) |

Under the minimum denominator (default 5) a rate is "insufficient data"; with a denominator of zero it is "no data".

## Abandonment points

| Key | Sessions that… |
|---|---|
| `journey_to_restaurant` | searched a journey and opened no restaurant (stage 1 − stage 3) |
| `restaurant_to_cart` | opened a restaurant and added nothing to a cart (3 − 5) |
| `cart_to_checkout` | added to a cart and started no checkout (5 − 7) — the definition of cart abandonment used here |
| `checkout_to_payment` | started a checkout and started no payment (7 − 8) |
| `payment_to_order` | started a payment and no order was created (8 − 10) |

A checkout that ran out of time is "did not continue", not a failure. The payment side (verified, failed, cancelled,
expired, pending, unknown) is the payment report's, from the payment domain.

## What a client cannot do

A client can claim every behavioural stage — that is what telemetry is. It cannot claim a payment or an order: there
is no `payment_verified`, `order_created` or `order_completed` event in the registry, the ingestion endpoint refuses
those names, and rows written into the event table by hand are ignored by the funnel. Nothing in analytics is read by
the payment, order, refund or finance domains.

## History

Behavioural events are recorded from Module 38 on. For earlier periods the funnel is empty and says
**HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38**; the orders of those periods are in the order report.
