<?php

/*
|--------------------------------------------------------------------------
| Orders (Module 31)
|--------------------------------------------------------------------------
|
| How a confirmed checkout becomes an order, and how hard the platform tries when it cannot. No amount, tax, fee or
| price lives here: an order is always the immutable copy of the confirmed checkout it was made from.
|
*/

return [
    'fulfillment' => [
        // The queue the fulfilment job runs on (the same worker as the payment webhooks locally).
        'queue' => env('ORDER_FULFILLMENT_QUEUE', 'orders'),
        // Seconds between tries after a TECHNICAL failure (a database error, an exception). After the last one a
        // person is asked (REVIEW_REQUIRED). A capacity, restaurant or market problem is not retried blindly.
        'backoff_seconds' => [30, 60, 300, 900, 3600],
        'max_attempts' => (int) env('ORDER_FULFILLMENT_MAX_ATTEMPTS', 5),
    ],

    'pickup_credential' => [
        // Characters of the code the customer reads out at the counter (an alphabet without 0/O/1/I).
        'display_code_length' => (int) env('ORDER_PICKUP_CODE_LENGTH', 6),
    ],

    'restaurant' => [
        // How many active orders the dashboard's operational queue returns at most (history is paged).
        'active_queue_limit' => (int) env('ORDER_ACTIVE_QUEUE_LIMIT', 200),
    ],

    'metrics' => [
        'retention_hours' => (int) env('ORDER_METRICS_RETENTION_HOURS', 48),
    ],
];
