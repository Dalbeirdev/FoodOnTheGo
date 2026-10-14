<?php

/*
|--------------------------------------------------------------------------
| Support cases (Module 37)
|--------------------------------------------------------------------------
|
| One case domain for customers, restaurants, administrators and the system. A case DESCRIBES and COORDINATES work on
| an order, a payment, a refund, a settlement or a payout — it never decides what is true about them: closing a case
| changes no order, no payment, no refund and no money.
|
| Everything here is operating policy. The priorities, the routing, the windows and the SLA targets are DEVELOPMENT
| DEFAULTS, PENDING BUSINESS APPROVAL. No target in this file is a promise to a customer or a restaurant: nothing
| contractual is shown to them.
|
*/

$optionalInt = fn (string $key): ?int => env($key) === null || env($key) === '' ? null : (int) env($key);

return [

    /*
    | The taxonomy — the ONE place categories live. Clients read it from the catalog endpoints; no client carries its
    | own list.
    |   audiences   who may open a case of this category: CUSTOMER, RESTAURANT, ADMIN, SYSTEM
    |   team        the queue a new case is routed to; null = by who asks (see routing.by_requester)
    |   priority    the priority a new case gets. A requester never sets a priority.
    |   restricted  null, or the class of a case only some administrators may open at all: FINANCE or SECURITY
    |   restaurant_permission  what a restaurant member needs IN ADDITION to the support permission
    |   subcategories  code => [audiences?, priority?]
    */
    'categories' => [
        'ORDER' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN'], 'team' => null, 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => [
            'ORDER_NOT_ACCEPTED' => [], 'ORDER_DELAYED' => [], 'ORDER_REJECTED' => [], 'ORDER_NOT_READY' => [], 'WRONG_ORDER' => [],
            'CUSTOMER_DISPUTE' => ['audiences' => ['RESTAURANT', 'ADMIN']],
        ]],
        'PAYMENT' => ['audiences' => ['CUSTOMER', 'ADMIN', 'SYSTEM'], 'team' => 'PAYMENTS', 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => [
            'PAYMENT_PENDING' => [], 'PAYMENT_FAILED' => [], 'DUPLICATE_PAYMENT' => ['priority' => 'HIGH'], 'PAYMENT_CHARGED_NO_ORDER' => ['priority' => 'HIGH'],
        ]],
        'REFUND' => ['audiences' => ['CUSTOMER', 'ADMIN', 'SYSTEM'], 'team' => 'PAYMENTS', 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => [
            'REFUND_PENDING' => [], 'REFUND_FAILED' => ['priority' => 'HIGH'], 'REFUND_NOT_RECEIVED' => [],
        ]],
        'PICKUP' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN'], 'team' => null, 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => [
            'PICKUP_CODE_ISSUE' => [], 'PICKUP_NOT_RECEIVED' => ['priority' => 'HIGH'],
        ]],
        'RESTAURANT' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN'], 'team' => 'RESTAURANT_SUPPORT', 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => [
            'RESTAURANT_ACCESS' => ['audiences' => ['RESTAURANT', 'ADMIN']], 'MENU_ISSUE' => ['audiences' => ['RESTAURANT', 'ADMIN']],
        ]],
        'ACCOUNT' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN'], 'team' => null, 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => [
            'ACCOUNT_ACCESS' => [], 'STAFF_ACCESS' => ['audiences' => ['RESTAURANT', 'ADMIN']],
        ]],
        'PROMOTION' => ['audiences' => ['CUSTOMER', 'ADMIN'], 'team' => 'CUSTOMER_SUPPORT', 'priority' => 'LOW', 'restricted' => null, 'subcategories' => []],
        'REVIEW' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN'], 'team' => null, 'priority' => 'LOW', 'restricted' => null, 'subcategories' => []],
        'SETTLEMENT' => ['audiences' => ['RESTAURANT', 'ADMIN', 'SYSTEM'], 'team' => 'FINANCE', 'priority' => 'NORMAL', 'restricted' => null, 'restaurant_permission' => 'restaurant.finance.view', 'subcategories' => [
            'SETTLEMENT_MISMATCH' => [], 'COMMISSION_QUESTION' => [],
            // What Finance's reconciliation found. Opened by the system; finance-restricted.
            'RECONCILIATION_DIFFERENCE' => ['audiences' => ['ADMIN', 'SYSTEM'], 'priority' => 'HIGH', 'restricted' => 'FINANCE'],
        ]],
        'PAYOUT' => ['audiences' => ['RESTAURANT', 'ADMIN', 'SYSTEM'], 'team' => 'FINANCE', 'priority' => 'NORMAL', 'restricted' => null, 'restaurant_permission' => 'restaurant.finance.view', 'subcategories' => [
            'PAYOUT_PENDING' => [], 'PAYOUT_FAILED' => ['priority' => 'HIGH'],
        ]],
        // A provider dispute / chargeback. Never opened by a customer or a restaurant, and never shown to them.
        'DISPUTE' => ['audiences' => ['ADMIN', 'SYSTEM'], 'team' => 'FINANCE', 'priority' => 'HIGH', 'restricted' => 'FINANCE', 'subcategories' => []],
        'TECHNICAL' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN', 'SYSTEM'], 'team' => 'TECHNICAL_OPERATIONS', 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => []],
        'SECURITY' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN', 'SYSTEM'], 'team' => 'SECURITY', 'priority' => 'HIGH', 'restricted' => 'SECURITY', 'subcategories' => []],
        'OTHER' => ['audiences' => ['CUSTOMER', 'RESTAURANT', 'ADMIN'], 'team' => null, 'priority' => 'NORMAL', 'restricted' => null, 'subcategories' => []],
    ],

    'routing' => [
        // The queue of a category without a team of its own.
        'by_requester' => ['CUSTOMER' => 'CUSTOMER_SUPPORT', 'RESTAURANT' => 'RESTAURANT_SUPPORT', 'ADMIN' => 'CUSTOMER_SUPPORT', 'SYSTEM' => 'TECHNICAL_OPERATIONS'],
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Text. Plain text in any language, stored as written (control characters removed) and rendered as text by every
    | client — never as markup. Lengths in Unicode code points.
    */
    'limits' => [
        'subject_max' => 140,
        'message_max' => 4000,
        'note_max' => 4000,
        'reason_max' => 500,
        'page_size' => 20,
        'max_page_size' => 100,
        'messages_page_size' => 50,
        // How many cases that are not closed one customer / one restaurant member may have at a time.
        'open_cases_per_requester' => (int) env('SUPPORT_OPEN_CASES_PER_REQUESTER', 20),
        'links_per_case' => 12,
    ],

    /*
    | Reopening. DEVELOPMENT DEFAULTS, PENDING BUSINESS APPROVAL.
    |   resolved_days  how long after a case was RESOLVED its requester may reopen it
    |   closed_days    how long after it was CLOSED; 0 = a closed case is never reopened by its requester (a new case)
    | An administrator with the permission reopens with a reason at any time.
    */
    'reopen' => [
        'resolved_days' => (int) env('SUPPORT_REOPEN_RESOLVED_DAYS', 14),
        'closed_days' => (int) env('SUPPORT_REOPEN_CLOSED_DAYS', 7),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Closing by the clock. Both are OFF until the business sets a number of days:
    |   resolved_after_days     RESOLVED → CLOSED when nobody reopened it
    |   no_response_after_days  WAITING_FOR_CUSTOMER / WAITING_FOR_RESTAURANT → RESOLVED (NO_ACTION_REQUIRED) when the
    |                           requester did not answer
    */
    'auto_close' => [
        'resolved_after_days' => $optionalInt('SUPPORT_AUTO_CLOSE_RESOLVED_DAYS'),
        'no_response_after_days' => $optionalInt('SUPPORT_AUTO_CLOSE_NO_RESPONSE_DAYS'),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | SLA — INTERNAL operational targets, not a contract. The targets themselves are rows of support_sla_policies
    | (per market / category / priority / team); a case without a matching policy has no SLA (N/A).
    |   at_risk_ratio   the share of the time to a target after which a case is AT_RISK
    |   auto_escalate   when true, a breached case is escalated by the system (SLA_RISK)
    */
    'sla' => [
        'at_risk_ratio' => (float) env('SUPPORT_SLA_AT_RISK_RATIO', 0.8),
        'auto_escalate' => (bool) env('SUPPORT_SLA_AUTO_ESCALATE', false),
        'contractual' => false,
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Cases the system opens by itself, once per condition (a dedupe key while the case is not closed).
    |   payment_order_recovery  a verified payment that could not become an order and needs a person
    |   refund_review           a refund that needs a person
    |   finance_difference      a reconciliation difference of at least this severity (null = off)
    |   dispute                 a provider dispute that is open (finance-restricted)
    |   security                readiness only: nothing opens one automatically yet
    */
    'system_cases' => [
        'payment_order_recovery' => (bool) env('SUPPORT_SYSTEM_CASE_PAYMENT_RECOVERY', true),
        'refund_review' => (bool) env('SUPPORT_SYSTEM_CASE_REFUND_REVIEW', true),
        'finance_difference_min_severity' => env('SUPPORT_SYSTEM_CASE_DIFFERENCE_SEVERITY', 'HIGH') ?: null,
        'dispute' => (bool) env('SUPPORT_SYSTEM_CASE_DISPUTE', true),
        'sweep_batch' => (int) env('SUPPORT_SWEEP_BATCH', 200),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Attachments — FOUNDATION, DISABLED. Private files need a malware scanner before a customer may send one; none is
    | configured ("scanner" => "none"), which is a PRODUCTION BLOCKER for switching this on. No upload endpoint exists
    | (Module 39: the schema and these settings only) and no client offers one.
    */
    'attachments' => [
        // NOT IMPLEMENTED: there is no upload endpoint, no private store and no scanner behind this. It is a constant —
        // not an environment switch — so that nothing can announce attachments before they exist.
        'enabled' => false,
        'disk' => env('SUPPORT_ATTACHMENTS_DISK', 'local'),
        'directory' => 'support',
        'mime_types' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'],
        'max_bytes' => (int) env('SUPPORT_ATTACHMENT_MAX_BYTES', 5 * 1024 * 1024),
        'max_per_case' => (int) env('SUPPORT_ATTACHMENTS_PER_CASE', 10),
        'scanner' => env('SUPPORT_ATTACHMENT_SCANNER', 'none'),
        'retention' => 'PENDING POLICY',
    ],

    'retention' => [
        // No case, message, note or event is deleted by the application. Retention is a legal / privacy decision.
        'policy' => 'PENDING LEGAL / PRIVACY APPROVAL',
    ],

    'metrics' => [
        'retention_hours' => (int) env('SUPPORT_METRICS_RETENTION_HOURS', 48),
    ],
];
