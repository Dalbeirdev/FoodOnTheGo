<?php

/*
|--------------------------------------------------------------------------
| Observability (Module 40)
|--------------------------------------------------------------------------
|
| server_timing   add a Server-Timing header (application time, database time, number of queries) to every API answer.
|                 A diagnostic for load tests and local work; OFF by default — the header tells an outsider how long the
|                 database took, which a production API has no reason to say.
| request_log     one structured log line per API request: 'off', 'slow' (only answers slower than slow_ms, and every
|                 5xx) or 'all'. The line carries the request id, the route (its pattern, never the URL with its
|                 values), the status, the duration, the number and time of database queries and the kind of caller —
|                 never a body, a header, a token or an identifier of a person.
| slow_ms         what counts as slow for request_log = 'slow'. PENDING BUSINESS APPROVAL (an engineering default).
| queues          the queues the workers serve, in priority order (the reliability view reports each of them).
| alerts          the thresholds of the reliability view (GET /admin/system/reliability, php artisan ops:reliability):
|                 [warning, critical]; a value at or above a threshold raises that alert. Every threshold is an
|                 engineering default PENDING BUSINESS APPROVAL — none is a production SLO.
|
*/

return [
    'server_timing' => (bool) env('OBSERVABILITY_SERVER_TIMING', false),
    'request_log' => env('OBSERVABILITY_REQUEST_LOG', 'slow'),
    'slow_ms' => (int) env('OBSERVABILITY_SLOW_MS', 1000),

    'queues' => ['orders', 'payments', 'finance', 'notifications', 'default'],

    'alerts' => [
        // infrastructure
        'db_connections_pct' => [70, 90],          // connections of this database as a % of max_connections
        'db_waiting_on_lock' => [5, 20],           // sessions waiting on a lock now
        'db_longest_query_s' => [10, 60],          // the longest running statement
        'db_longest_transaction_s' => [60, 300],   // the oldest open transaction (idle in transaction included)
        'db_deadlocks' => [1, 10],                 // deadlocks since the statistics were last reset
        'redis_memory_pct' => [70, 90],            // used memory as a % of maxmemory (only when maxmemory is set)
        'redis_evicted_keys' => [1, 1000],         // keys evicted since Redis started
        'queue_depth' => [500, 5000],              // waiting jobs in one queue
        'queue_oldest_s' => [60, 300],             // age of the oldest waiting job in one queue
        'failed_jobs_24h' => [1, 25],              // jobs that failed for good in the last 24 hours
        // domain signals (money first)
        'payments_verified_without_order' => [1, 1],   // verified payments with no order after payment_grace_minutes
        'refunds_stuck' => [1, 10],                    // refunds in flight longer than refund_stuck_hours
        'payouts_needing_attention' => [1, 5],         // payouts FAILED, UNKNOWN or REVIEW_REQUIRED
        'webhooks_failed_24h' => [1, 10],              // provider webhooks that failed processing in the last 24 hours
        'ledger_problems' => [1, 1],                   // problems found by the latest ledger verification
        'ledger_verification_age_h' => [2, 24],        // hours since the latest ledger verification finished
        'support_urgent_open' => [1, 10],              // active URGENT support cases
        'support_sla_breached' => [1, 20],             // active support cases past an internal target
    ],

    'payment_grace_minutes' => (int) env('OBSERVABILITY_PAYMENT_GRACE_MINUTES', 5),
    'refund_stuck_hours' => (int) env('OBSERVABILITY_REFUND_STUCK_HOURS', 24),
];
