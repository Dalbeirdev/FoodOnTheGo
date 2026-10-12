<?php

/*
|--------------------------------------------------------------------------
| Platform finance: ledger, commission, restaurant earnings, settlements, payouts, reconciliation (Module 36)
|--------------------------------------------------------------------------
|
| NOTHING HERE IS AN APPROVED COMMERCIAL, TAX OR LEGAL POLICY.
|
| A commission rate is never in this file (or anywhere in code): it lives in commission_plans, with its dates, and the
| only plans on this machine are TEST FIXTURES. What IS here is the SHAPE of the decisions the business has not made
| yet — each with the most careful default and the mark
|
|     PENDING BUSINESS / FINANCE / TAX / LEGAL APPROVAL
|
| No GST rate, no TDS / TCS rate, no withholding rule, no payout minimum, no settlement schedule and no provider fee
| formula is defined: provider fees are only ever the amounts the provider itself reported.
|
*/

$upper = fn (string $key, string $default): string => strtoupper(trim((string) env($key, $default)));
$optionalInt = fn (string $key): ?int => env($key) === null || env($key) === '' ? null : (int) env($key);

/** "REASON_A:RESTAURANT,REASON_B:PLATFORM,REASON_C:SHARED:5000" → ['REASON_A' => ['RESTAURANT', null], …] */
$liabilities = function (?string $value): array {
    $map = [];
    foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $pair) {
        $parts = array_map(fn (string $p): string => strtoupper(trim($p)), explode(':', $pair));
        if (count($parts) >= 2) {
            $map[$parts[0]] = [$parts[1], isset($parts[2]) ? (int) $parts[2] : null];
        }
    }

    return $map;
};

return [
    'queue' => env('FINANCE_QUEUE', 'finance'),

    /*
    | When money becomes somebody's. CODE INVARIANTS: a verified payment is customer money held in clearing; an order's
    | allocation is frozen when the order is created. POLICY (PENDING BUSINESS APPROVAL): the restaurant's earning and
    | the platform's commission are recognized — and the earning becomes settleable — when the order is COMPLETED.
    | "ORDER_COMPLETED" is the only trigger built.
    */
    'recognition' => [
        'trigger' => 'ORDER_COMPLETED',
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Who an amount on the order belongs to. PENDING BUSINESS / TAX / LEGAL APPROVAL.
    |   tax.owner   UNASSIGNED (default: the tax collected is held in a liability account — nobody's revenue, nobody's
    |               earning — until the legal structure is decided), PLATFORM (the platform remits it) or RESTAURANT
    |               (passed through to the restaurant's payable).
    |   fees.owner  PLATFORM (default: a customer fee is the platform's) or RESTAURANT.
    */
    'tax' => [
        'owner' => $upper('FINANCE_TAX_OWNER', 'UNASSIGNED'),
        'approval' => 'PENDING TAX / LEGAL APPROVAL',
    ],
    'fees' => [
        'owner' => $upper('FINANCE_FEE_OWNER', 'PLATFORM'),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Promotions: who pays for a discount is the promotion's own funding source (PLATFORM / RESTAURANT / SHARED), frozen
    | on the order's allocation. SHARED has no split until the business defines one: null means an order with a SHARED
    | promotion waits for a finance decision instead of being split by a guess.
    */
    'promotions' => [
        'shared_restaurant_share_bps' => $optionalInt('FINANCE_SHARED_PROMOTION_RESTAURANT_BPS'),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Provider processing fees. PLATFORM_BORNE is the only policy built: the provider's fee and the tax on it are the
    | platform's expense and never reduce a restaurant's earning. RESTAURANT_BORNE / SHARED need a business decision
    | and are not implemented. Fees are posted ONLY from the provider's own settlement records.
    */
    'provider_fees' => [
        'policy' => 'PLATFORM_BORNE',
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Who bears a completed refund of an order that had already been recognized. Per refund reason:
    | RESTAURANT (the whole refunded amount is taken from the restaurant's payable), PLATFORM, SHARED:<restaurant bps>
    | or UNASSIGNED. The default for EVERY reason is UNASSIGNED: the refund waits in a clearing account until a person
    | with admin.finance.adjustments.create decides the split. Commission is never given back automatically.
    | FINANCE_REFUND_LIABILITY="RESTAURANT_CANCELLATION:RESTAURANT,OTHER:SHARED:5000"
    */
    'refund_liability' => [
        'by_reason' => $liabilities(env('FINANCE_REFUND_LIABILITY')),
        'default' => 'UNASSIGNED',
        'approval' => 'PENDING BUSINESS / LEGAL APPROVAL',
    ],

    /*
    | Holds placed by the system (a person can always place one with a reason). PENDING BUSINESS APPROVAL.
    |   refund_pending  an unsettled earning whose order has an open refund is not settled until the refund is over
    |   dispute         the same while a dispute on the payment is open
    | No earning is held for a number of days: no "refund window" exists.
    */
    'holds' => [
        'refund_pending' => (bool) env('FINANCE_HOLD_ON_PENDING_REFUND', true),
        'dispute' => (bool) env('FINANCE_HOLD_ON_DISPUTE', true),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Restaurant settlements. DEVELOPMENT DEFAULTS — no production schedule exists:
    |   cadence         WEEKLY is a fixture so that periods can be tried; DAILY / WEEKLY / MONTHLY are built
    |   scope           one settlement per LOCATION and currency
    |   timezone        MARKET: periods end at midnight of the market's own time zone (India: Asia/Kolkata); stored UTC
    |   negative        CARRY_FORWARD: a negative total never becomes a settlement, a payout or a bank debit — the
    |                   lines stay for the next period and finance is told
    |   minimum_payout  null: no threshold was approved, none is invented
    |   four_eyes       when true, who generated a settlement cannot approve it
    */
    'settlements' => [
        'cadence' => $upper('FINANCE_SETTLEMENT_CADENCE', 'WEEKLY'),
        'cadences' => ['DAILY', 'WEEKLY', 'MONTHLY'],
        'week_starts_on' => 1,   // ISO Monday
        'scope' => 'LOCATION',
        'timezone' => 'MARKET',
        'negative' => 'CARRY_FORWARD',
        'minimum_payout_minor' => $optionalInt('FINANCE_MINIMUM_PAYOUT_MINOR'),
        'four_eyes' => (bool) env('FINANCE_SETTLEMENT_FOUR_EYES', false),
        'scheduled' => (bool) env('FINANCE_SETTLEMENTS_SCHEDULED', false),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    /*
    | Restaurant payouts. LIVE PAYOUT = DISABLED.
    |
    | PAYOUT_DRIVER is "fake" (development, refused in production) or nothing. There is NO real payout adapter: moving
    | a restaurant's money needs a provider product this project does not have (PENDING PROVIDER ACCOUNT ENABLEMENT),
    | restaurant KYC / bank onboarding, and a finance / legal decision. live_enabled is false and nothing reads an
    | environment variable to change it — enabling live payouts is a code change that has to be reviewed.
    */
    'payouts' => [
        'driver' => strtolower(trim((string) env('PAYOUT_DRIVER', ''))),
        'live_enabled' => false,
        // When true, who approved a settlement cannot be the one who pays it.
        'four_eyes' => (bool) env('FINANCE_PAYOUT_FOUR_EYES', false),
        // A submission is "in flight" for this long (seconds) before an unanswered one is looked up.
        'settle_margin_seconds' => 30,
        // The sweep asks the provider about an unsettled payout after these waits (seconds); then a person looks.
        'reconcile_backoff_seconds' => [60, 300, 900, 3600, 14400, 43200, 86400, 86400, 86400],
        'batch' => 50,
        'webhook_tolerance_seconds' => 300,
        'fake' => [
            // What the fake provider answers to a new payout: processing (default), processed or failed.
            'outcome' => strtolower((string) env('PAYOUT_FAKE_OUTCOME', 'processing')),
        ],
        'approval' => 'DISABLED / NOT APPROVED',
    ],

    /*
    | Manual adjustments of a restaurant's payable: permission + reason + supporting reference + audit + a balanced
    | posting. four_eyes: the adjustment waits for a second administrator before it is posted.
    */
    'adjustments' => [
        'four_eyes' => (bool) env('FINANCE_ADJUSTMENT_FOUR_EYES', false),
        'reasons' => ['CORRECTION', 'GOODWILL', 'RECOVERY', 'COMMERCIAL_AGREEMENT', 'OTHER'],
    ],

    /*
    | Provider settlement reconciliation: what the payment provider says it settled against what the ledger holds.
    |   expected_within_days  a verified payment or completed refund that no provider settlement record mentions after
    |                         this many days is reported as missing (a development default, not a provider promise)
    |   page_size             records asked for per request (the provider's documented maximum is 1000)
    |   max_pages             a single import never asks for more pages than this
    */
    'provider_reconciliation' => [
        'expected_within_days' => (int) env('FINANCE_PROVIDER_SETTLEMENT_EXPECTED_DAYS', 5),
        'page_size' => (int) env('FINANCE_PROVIDER_SETTLEMENT_PAGE_SIZE', 200),
        'max_pages' => 50,
        'lookback_days' => (int) env('FINANCE_PROVIDER_SETTLEMENT_LOOKBACK_DAYS', 3),
        'scheduled' => (bool) env('FINANCE_PROVIDER_RECONCILIATION_SCHEDULED', false),
    ],

    /*
    | What a restaurant may see of its own commercial terms (never another restaurant's). PENDING BUSINESS APPROVAL:
    | shown by default so that a statement can be explained; switch off to show the commission amounts only.
    */
    'restaurant' => [
        'terms_visible' => (bool) env('FINANCE_RESTAURANT_TERMS_VISIBLE', true),
        'approval' => 'PENDING BUSINESS APPROVAL',
    ],

    'internal_reconciliation' => [
        // A verified payment without an order or a refund is "orphan" only after the order recovery had its time.
        'orphan_after_minutes' => (int) env('FINANCE_ORPHAN_PAYMENT_MINUTES', 30),
        // A financial event without its ledger posting is "missing" after this long.
        'posting_after_minutes' => (int) env('FINANCE_POSTING_GRACE_MINUTES', 10),
    ],

    'sweep' => [
        'batch' => (int) env('FINANCE_SWEEP_BATCH', 200),
    ],

    'reports' => [
        // A list, a report or an export never spans more than this many days.
        'max_range_days' => 366,
        'export_max_range_days' => 92,
        'export_max_rows' => 5000,
        'page_size' => 25,
        'max_page_size' => 100,
        'cache_seconds' => 30,
    ],

    /*
    | Tax and accounting documents: none is produced. A settlement statement is NOT a tax invoice.
    */
    'tax_documents' => [
        'tax_invoice' => 'PENDING TAX / ACCOUNTING DESIGN',
        'commission_invoice' => 'PENDING TAX / ACCOUNTING DESIGN',
        'credit_note' => 'PENDING TAX / ACCOUNTING DESIGN',
        'withholding' => 'PENDING PROFESSIONAL TAX REVIEW',
    ],

    'metrics' => [
        'retention_hours' => (int) env('FINANCE_METRICS_RETENTION_HOURS', 48),
    ],
];
