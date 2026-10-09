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
        // Module 32. A credential stays usable while the order is on its way; this absolute expiry, counted from the
        // pickup time, only closes credentials of orders nobody collected. A late customer is NOT cut off at the minute.
        'expiry_hours_after_pickup' => (int) env('ORDER_PICKUP_CREDENTIAL_EXPIRY_HOURS', 24),
        // Wrong codes against ONE credential before it is locked for a while (the customer is not stranded: the lock
        // ends, and the QR of the same credential is not a guess).
        'lockout_after_failures' => (int) env('ORDER_PICKUP_LOCKOUT_AFTER', 5),
        'lockout_minutes' => (int) env('ORDER_PICKUP_LOCKOUT_MINUTES', 15),
        // Rotations a customer may ask for, per order and hour.
        'reissue_per_hour' => (int) env('ORDER_PICKUP_REISSUE_PER_HOUR', 3),
        // Verification attempts per location and minute (every staff member together) and per staff member and minute.
        'attempts_per_location_minute' => (int) env('ORDER_PICKUP_ATTEMPTS_PER_LOCATION', 120),
        'attempts_per_staff_minute' => (int) env('ORDER_PICKUP_ATTEMPTS_PER_STAFF', 30),
    ],

    'completion' => [
        // Module 32 V1: a verified pickup completes the order by itself (there is no post-pickup step yet). The
        // completion is its own transaction after the pickup commit; if it fails the order stays PICKED_UP and the
        // sweep (orders:recover) completes it later. PICKED_UP and COMPLETED are both kept on the timeline.
        'automatic' => (bool) env('ORDER_COMPLETION_AUTOMATIC', true),
        // Seconds a PICKED_UP order may wait before the sweep completes it.
        'sweep_after_seconds' => (int) env('ORDER_COMPLETION_SWEEP_AFTER', 60),
    ],

    'realtime' => [
        // Module 32: the broadcast layer (Laravel Reverb). Off = nothing is sent; clients poll (the fallback they
        // have anyway). The order state never depends on it.
        'enabled' => (bool) env('ORDER_REALTIME_ENABLED', true),
        // The broadcaster that signs private-channel subscriptions (the WebSocket server's key / secret).
        'auth_driver' => env('ORDER_REALTIME_AUTH_DRIVER', 'reverb'),
        'schema_version' => 1,
        // Seconds between polls that clients are told to use when the socket is not available.
        'poll_seconds_active' => (int) env('ORDER_POLL_SECONDS_ACTIVE', 8),
        'poll_seconds_idle' => (int) env('ORDER_POLL_SECONDS_IDLE', 30),
    ],

    'restaurant' => [
        // How many active orders the dashboard's operational queue returns at most (history is paged).
        'active_queue_limit' => (int) env('ORDER_ACTIVE_QUEUE_LIMIT', 200),
    ],

    'metrics' => [
        'retention_hours' => (int) env('ORDER_METRICS_RETENTION_HOURS', 48),
    ],
];
