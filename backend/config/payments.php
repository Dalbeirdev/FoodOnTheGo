<?php

/*
|--------------------------------------------------------------------------
| Payments (Module 30)
|--------------------------------------------------------------------------
|
| Which provider is used is PAYMENT_DRIVER (config/services.php): "razorpay", "fake" or nothing. There is no fallback:
| with nothing configured, payments are unavailable — the application never invents a successful payment.
|
| The provider's credentials are in config/services.php ("razorpay"). No amount, currency, tax or fee lives here:
| a payment is always for the total of a confirmed checkout. The lifetimes below are development defaults.
|
*/

return [
    'providers' => [
        // The fake provider moves no money and exists for tests and local development. It is allowed ONLY in the
        // environments named here (Module 39: an allow-list — a "staging" or misnamed environment refuses it, like
        // production), and even there PAYMENT_ALLOW_FAKE_PROVIDER can switch it off (to see production's view).
        'allow_fake' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) && ! in_array(env('PAYMENT_ALLOW_FAKE_PROVIDER'), [false, 'false', '0', 0, 'off', 'no'], true),
        // Module 30 is TEST MODE ONLY. Live payments are switched on by a later, deliberate change of this line —
        // not by an environment variable.
        'allow_live' => false,
        // Reads of the provider may be repeated; creating a payment session never is.
        'retry' => [
            'attempts' => (int) env('PAYMENT_PROVIDER_RETRY_ATTEMPTS', 2),
            'backoff_ms' => (int) env('PAYMENT_PROVIDER_RETRY_BACKOFF_MS', 200),
            'jitter_ms' => (int) env('PAYMENT_PROVIDER_RETRY_JITTER_MS', 100),
        ],
    ],

    'attempt' => [
        // How long the customer has to pay once the provider's interface may be opened (minutes).
        'ttl_minutes' => (int) env('PAYMENT_ATTEMPT_TTL_MINUTES', 15),
        // The checkout and its held pickup time outlive the attempt by this much, so a payment made at the last
        // second, and its webhook, still find them (minutes).
        'grace_minutes' => (int) env('PAYMENT_HOLD_GRACE_MINUTES', 5),
        // A checkout cannot be used to open provider sessions without end.
        'max_per_checkout' => (int) env('PAYMENT_MAX_ATTEMPTS_PER_CHECKOUT', 5),
    ],

    // No attempt starts later than this after the checkout was confirmed, and nothing is held beyond it: a slot is
    // not kept from other customers by trying to pay again and again (minutes).
    'window_minutes' => (int) env('PAYMENT_WINDOW_MINUTES', 45),

    // After a payment is VERIFIED the checkout and its held pickup time are kept this long for the order to be
    // created (Module 31). When that time runs out without an order, the payment needs recovery (minutes).
    'order_handoff_minutes' => (int) env('PAYMENT_ORDER_HANDOFF_MINUTES', 30),

    'reconcile' => [
        // A customer asking for the status may trigger a look at the provider at most this often (seconds).
        'inline_interval_seconds' => (int) env('PAYMENT_RECONCILE_INLINE_INTERVAL_SECONDS', 10),
        // The scheduled sweep looks at an unsettled attempt after these waits, one after the other (seconds) …
        'backoff_seconds' => [60, 120, 300, 600, 1800, 3600, 3600, 7200, 14400, 28800],
        // … and when they are used up, a person has to look (REVIEW_REQUIRED).
    ],

    'webhook' => [
        'max_bytes' => (int) env('PAYMENT_WEBHOOK_MAX_BYTES', 65536),
        'queue' => env('PAYMENT_WEBHOOK_QUEUE', 'payments'),
        // An accepted event that no worker has processed after this long is processed by the sweep (seconds).
        'sweep_after_seconds' => (int) env('PAYMENT_WEBHOOK_SWEEP_AFTER_SECONDS', 60),
    ],

    // What the provider's interface shows the customer. No secret, no logo URL of somebody else's server.
    'display' => [
        'name' => env('PAYMENT_DISPLAY_NAME', 'FoodOnTheGo'),
        'description' => env('PAYMENT_DISPLAY_DESCRIPTION', 'Food order for pickup'),
        'theme_color' => env('PAYMENT_DISPLAY_THEME_COLOR', '#E4572E'),
    ],

    'metrics' => [
        'retention_hours' => (int) env('PAYMENT_METRICS_RETENTION_HOURS', 48),
    ],
];
