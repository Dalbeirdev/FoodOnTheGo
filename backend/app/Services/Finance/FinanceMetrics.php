<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Counts of what happens in the finance domain — numbers only (Module 36), in hour buckets in the cache, for the
 * administrators' system page. They are operational counters, short-lived and never a balance: every figure about
 * money is read from the ledger in the database.
 */
final class FinanceMetrics
{
    private const PREFIX = 'finance:metrics:';

    public const EVENTS = [
        'posting', 'posting_repeated', 'ledger_refused', 'ledger_imbalance',
        'allocation', 'allocation_review_required', 'order_recognized', 'order_voided', 'refund_posted', 'refund_adjustment', 'dispute_posted',
        'commission_minor', 'gross_minor',
        'hold_placed', 'hold_released',
        'settlement_generated', 'settlement_approved', 'settlement_cancelled', 'settlement_negative_carried',
        'payout_started', 'payout_submitted', 'payout_paid', 'payout_failed', 'payout_unknown', 'payout_review_required', 'payout_duplicate_blocked', 'payout_external', 'payout_reversed',
        'payout_webhook', 'payout_webhook_duplicate', 'payout_webhook_refused',
        'provider_import', 'provider_item_imported', 'provider_item_repeated', 'provider_fee_posted', 'provider_settlement_minor',
        'difference_opened', 'difference_resolved', 'difference_auto_resolved',
        'adjustment_posted', 'export',
    ];

    public function record(string $event, int $by = 1): void
    {
        // "posting.payment_verified" and the like are counted under their own name too.
        if ($by < 1 || (! in_array($event, self::EVENTS, true) && ! str_starts_with($event, 'posting.'))) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('finance.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    public function sum(string $event, int $hours = 24): int
    {
        $hours = max(1, min($hours, (int) config('finance.metrics.retention_hours')));
        $now = CarbonImmutable::now('UTC');
        $total = 0;
        for ($i = 0; $i < $hours; $i++) {
            $total += (int) Cache::get(self::PREFIX.$event.':'.$now->subHours($i)->format('YmdH'), 0);
        }

        return $total;
    }

    /**
     * @return array<string, int>
     */
    public function events(int $hours = 24): array
    {
        $events = [];
        foreach (self::EVENTS as $event) {
            $events[$event] = $this->sum($event, $hours);
        }

        return $events;
    }
}
