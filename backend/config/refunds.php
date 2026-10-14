<?php

/*
|--------------------------------------------------------------------------
| Refunds and the financial side of cancellation (Module 35)
|--------------------------------------------------------------------------
|
| Nothing here is a refund amount: an amount is always derived from the payment and from what was already refunded.
| What IS here is policy — and policy that the business has not approved yet is switched OFF by default:
|
|   - no refund reason is approved automatically (every refund waits for a person with admin.refunds.approve);
|   - a customer cannot cancel an order by themselves.
|
| Both are switches for the day the policy is approved; the mechanisms behind them are built and tested.
|
*/

$list = fn (?string $value): array => array_values(array_filter(array_map(fn (string $v): string => strtoupper(trim($v)), explode(',', (string) $value))));

return [
    'policy' => [
        // Refund reasons that are approved by the policy itself, without a person (e.g. "RESTAURANT_REJECTED").
        // AUTO-REFUND POLICY = PENDING BUSINESS APPROVAL: empty means every refund is reviewed by a person.
        'auto_approve_reasons' => $list(env('REFUND_AUTO_APPROVE_REASONS')),
        // An automatic approval never exceeds this amount (minor units of the payment's currency). Null = no limit.
        'auto_approve_max_minor' => env('REFUND_AUTO_APPROVE_MAX_MINOR') === null ? null : (int) env('REFUND_AUTO_APPROVE_MAX_MINOR'),
        // When true, the administrator who asked for a refund cannot be the one who approves it.
        'four_eyes' => (bool) env('REFUND_FOUR_EYES', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),   // Module 39: on by default everywhere except local development and the test suite
    ],

    'submission' => [
        'queue' => env('REFUND_QUEUE', 'payments'),
        // A request the provider certainly did not carry out (rate limit, provider not usable) is sent again after
        // these waits (seconds); when they are used up a person looks.
        'not_sent_backoff_seconds' => [60, 300, 900, 3600],
        // After a submission without an answer the provider is asked what it has. Only when it has nothing may the SAME
        // request (same receipt, same idempotency key) be sent again — at most this many times.
        'max_unknown_resubmits' => (int) env('REFUND_MAX_UNKNOWN_RESUBMITS', 2),
        // A submission is "in flight" for the provider timeout plus this margin (seconds); only then is an unanswered
        // one looked up.
        'settle_margin_seconds' => 15,
    ],

    'reconcile' => [
        // The scheduled sweep asks the provider about an unsettled refund after these waits, one after the other
        // (seconds): quickly at first, then daily — a bank can take days. When they are used up: REVIEW_REQUIRED.
        'backoff_seconds' => [60, 300, 900, 3600, 14400, 43200, 86400, 86400, 86400, 86400, 86400, 86400, 86400, 86400, 86400, 86400],
        'batch' => 100,
    ],

    /*
    | Customer cancellation: PENDING BUSINESS / LEGAL APPROVAL. Disabled. The values below are the SHAPE of a policy,
    | not an approved one: which order states may be cancelled by the customer and what that does to the money
    | (FULL_REFUND, NO_REFUND or MANUAL_REVIEW — a partial refund needs a fee rule nobody has decided).
    */
    'customer_cancellation' => [
        'enabled' => (bool) env('ORDER_CUSTOMER_CANCELLATION_ENABLED', false),
        'policy_version' => env('ORDER_CUSTOMER_CANCELLATION_POLICY_VERSION', 'unapproved-draft'),
        'allowed_statuses' => $list(env('ORDER_CUSTOMER_CANCELLATION_STATUSES', 'WAITING_FOR_RESTAURANT')),
        'financial_effect' => strtoupper((string) env('ORDER_CUSTOMER_CANCELLATION_EFFECT', 'MANUAL_REVIEW')),
        'reasons' => ['CHANGED_MIND', 'ORDERED_BY_MISTAKE', 'PICKUP_TIME_NO_LONGER_WORKS', 'OTHER'],
    ],

    'metrics' => [
        'retention_hours' => (int) env('REFUND_METRICS_RETENTION_HOURS', 48),
    ],
];
