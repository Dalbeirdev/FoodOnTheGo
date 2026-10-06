<?php

/*
|--------------------------------------------------------------------------
| Pickup availability (Module 28)
|--------------------------------------------------------------------------
|
| Platform-level values of the pickup engine. What a RESTAURANT decides —
| preparation time, lead time, buffer, slot interval, scheduling horizon,
| cut-off before closing, capacity per slot, ASAP / scheduled, pickup
| methods — is its own pickup configuration (restaurant_pickup_settings,
| Module 23), never a constant here.
|
*/

return [

    /*
    | Preparation estimate: how the size of an order adds to the kitchen's time. The kitchen works in parallel, so
    | item times are never summed: the estimate is the slowest item (or the restaurant's default, whichever is
    | longer) plus this adjustment for large orders. `free_items` units cost nothing extra; every further
    | `items_per_step` units (started) add `minutes_per_step`, up to `max_extra_minutes`. Set minutes_per_step to 0
    | to switch the adjustment off. Development defaults — an owner decision before launch.
    */

    'preparation' => [
        'quantity' => [
            'free_items' => (int) env('PICKUP_PREP_QUANTITY_FREE_ITEMS', 10),
            'items_per_step' => (int) env('PICKUP_PREP_QUANTITY_ITEMS_PER_STEP', 10),
            'minutes_per_step' => (int) env('PICKUP_PREP_QUANTITY_MINUTES_PER_STEP', 5),
            'max_extra_minutes' => (int) env('PICKUP_PREP_QUANTITY_MAX_EXTRA_MINUTES', 30),
        ],
        // Upper bound of any estimate (minutes): a mis-configured item can never push pickup out by days.
        'max_minutes' => (int) env('PICKUP_PREP_MAX_MINUTES', 480),
    ],

    /*
    | Minutes added to the traveller's estimated arrival before a pickup time is recommended (parking, walking in).
    | 0 = recommend the first valid time at or after the estimated arrival. No value is assumed by the platform.
    */

    'arrival_buffer_minutes' => (int) env('PICKUP_ARRIVAL_BUFFER_MINUTES', 0),

    /*
    | Capacity: a slot with a configured capacity is LIMITED for customers when at most this share of it is left
    | (and at least one order was already placed in it). Customers never see counts.
    */

    'capacity' => [
        'limited_share' => (float) env('PICKUP_CAPACITY_LIMITED_SHARE', 0.25),
    ],

    /*
    | Safety bounds of one availability answer.
    */

    'limits' => [
        // Most slots one answer may carry (the earliest ones are kept; the answer says when it was cut).
        'max_slots' => (int) env('PICKUP_MAX_SLOTS', 600),
    ],

    /*
    | Seconds after which a client should ask again: availability depends on the clock, on capacity and on the
    | restaurant, so an answer is only a snapshot.
    */

    'revalidate_after_seconds' => (int) env('PICKUP_REVALIDATE_AFTER_SECONDS', 60),

    /*
    | Hours the event counters are kept (one-hour buckets).
    */

    'metrics' => [
        'retention_hours' => (int) env('PICKUP_METRICS_RETENTION_HOURS', 48),
    ],

];
