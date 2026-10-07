<?php

/*
|--------------------------------------------------------------------------
| Checkout (Module 29)
|--------------------------------------------------------------------------
|
| A checkout session is a short-lived, server-priced quote for one cart. Nothing below is a business truth written
| in code: the lifetimes are development defaults the owner may change, and no tax rate, fee or currency lives here —
| those come from the market, the restaurant and the configured rules (tax_rules, fee_rules, promotions).
|
*/

return [

    'ttl' => [
        // How long a priced checkout that has not been confirmed stays usable (minutes).
        'draft_minutes' => (int) env('CHECKOUT_DRAFT_TTL_MINUTES', 15),

        // How long a confirmed checkout — and the pickup time held for it — stays usable (minutes). The hold and the
        // checkout end at the same instant: a checkout never outlives its hold.
        'ready_minutes' => (int) env('CHECKOUT_READY_TTL_MINUTES', 10),

        // Closed checkouts (expired, stale, cancelled) are deleted after this many days. A converted one never is.
        'closed_retention_days' => (int) env('CHECKOUT_CLOSED_RETENTION_DAYS', 30),
    ],

    'order_note' => [
        'max_length' => (int) env('CHECKOUT_ORDER_NOTE_MAX_LENGTH', 300),
    ],

    'financial_rules' => [
        // Tax and fee rules marked TEST are development configurations. They are applied only where this is true.
        // In production it is ALWAYS false, whatever the environment file says — a test rate cannot reach a real
        // customer. Elsewhere it is true unless CHECKOUT_ALLOW_TEST_RULES switches it off (to see production's view).
        'allow_test' => env('APP_ENV', 'production') !== 'production' && ! in_array(env('CHECKOUT_ALLOW_TEST_RULES'), [false, 'false', '0', 0, 'off', 'no'], true),
    ],

    'payment' => [
        // Module 30 integrates the provider. Until then checkout only PREPARES a payment and says so.
        'provider_status' => 'NOT_INTEGRATED',

        // The method names a market may offer, mapped from its payment configuration. Cash at pickup is not approved
        // and is never offered, whatever a client sends.
        'methods' => ['upi' => 'UPI', 'card' => 'CARD', 'netbanking' => 'NET_BANKING', 'wallet' => 'WALLET'],
    ],

    'metrics' => [
        'retention_hours' => (int) env('CHECKOUT_METRICS_RETENTION_HOURS', 48),
    ],

];
