<?php

namespace App\Services\Refund;

use App\Enums\DisputeStatus;
use App\Enums\RefundStatus;
use App\Events\Refund\RefundEvent;
use App\Services\Journey\ProviderMetrics;
use App\Services\Payment\PaymentProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Counts of what happens to refunds — numbers only (Module 35).
 *
 * Events are counted in hour buckets in the cache (short-lived, for the administrators' system page); the standing
 * figures — how many refunds are in which state, how much is owed, on its way and confirmed — are read from the
 * database, which is the truth. These are operational numbers: they are NOT settlement or accounting figures.
 */
final class RefundMetrics
{
    private const PREFIX = 'refunds:metrics:';

    public const EVENTS = [
        RefundEvent::REQUIRED, RefundEvent::REQUESTED, RefundEvent::APPROVED, RefundEvent::SUBMITTED, RefundEvent::SUBMISSION_UNKNOWN, RefundEvent::PROCESSING,
        RefundEvent::COMPLETED, RefundEvent::FAILED, RefundEvent::CANCELLED, RefundEvent::REVIEW_REQUIRED, RefundEvent::RECONCILED, RefundEvent::OVER_REFUND_BLOCKED,
        RefundEvent::EXTERNAL_REFUND_SEEN, RefundEvent::DISPUTE_RECORDED,
    ];

    private const SUMS = ['provider_ms_total', 'provider_calls'];

    public function record(string $event, int $by = 1): void
    {
        if ($by < 1 || ! in_array($event, [...self::EVENTS, ...self::SUMS], true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('refunds.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(PaymentProviderManager $providers, ProviderMetrics $providerMetrics, int $hours = 24): array
    {
        $hours = max(1, min($hours, (int) config('refunds.metrics.retention_hours')));
        $now = CarbonImmutable::now('UTC');
        $sum = function (string $name) use ($hours, $now): int {
            $total = 0;
            for ($i = 0; $i < $hours; $i++) {
                $total += (int) Cache::get(self::PREFIX.$name.':'.$now->subHours($i)->format('YmdH'), 0);
            }

            return $total;
        };
        $events = [];
        foreach (self::EVENTS as $event) {
            $events[$event] = $sum($event);
        }
        $rows = DB::table('refunds')->selectRaw('status, currency, count(*) as n, coalesce(sum(coalesce(approved_amount_minor, requested_amount_minor)), 0) as amount')->groupBy('status', 'currency')->get();
        $byStatus = [];
        $amounts = [];
        foreach (RefundStatus::cases() as $status) {
            $byStatus[strtolower($status->value)] = (int) $rows->where('status', $status->value)->sum('n');
        }
        foreach ($rows as $row) {
            $amounts[(string) $row->currency][strtolower((string) $row->status)] = (int) $row->amount;
        }
        $disputes = DB::table('payment_disputes')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $calls = $sum('provider_calls');
        $summary = $providers->summary();

        return [
            'checked_at' => $now->toIso8601String(),
            'window_hours' => $hours,
            'provider' => [
                'name' => $summary['name'],
                'mode' => $summary['mode'],
                'status' => $summary['status'],
                // The refund operations of the provider, counted apart from its payment operations.
                'health' => $summary['name'] === null ? null : $providerMetrics->health('refund', (string) $summary['name']),
            ],
            'refunds' => $byStatus,
            'queues' => [
                'required' => $byStatus['required'],
                'awaiting_approval' => $byStatus['required'] + $byStatus['requested'],
                'approved' => $byStatus['approved'],
                'processing' => $byStatus['submitting'] + $byStatus['submission_unknown'] + $byStatus['processing'],
                'failed' => $byStatus['failed'],
                'review_required' => $byStatus['review_required'],
                'completed' => $byStatus['refunded'],
            ],
            // Minor units per currency and state. Operational totals, not settlement truth.
            'amounts_minor' => $amounts,
            'events' => $events,
            'latency_ms' => [
                // The provider's own answer time for refund calls, apart from FoodOnTheGo's.
                'provider_avg' => $calls === 0 ? null : intdiv($sum('provider_ms_total'), $calls),
            ],
            'disputes' => [
                'open' => (int) collect(DisputeStatus::openValues())->sum(fn (string $s): int => (int) ($disputes[$s] ?? 0)),
                'won' => (int) ($disputes[DisputeStatus::Won->value] ?? 0),
                'lost' => (int) ($disputes[DisputeStatus::Lost->value] ?? 0),
                'closed' => (int) ($disputes[DisputeStatus::Closed->value] ?? 0),
            ],
            'policy' => [
                'auto_approve_reasons' => array_values((array) config('refunds.policy.auto_approve_reasons')),
                'four_eyes' => (bool) config('refunds.policy.four_eyes'),
                'customer_cancellation' => (bool) config('refunds.customer_cancellation.enabled') ? 'ENABLED' : 'DISABLED_PENDING_POLICY_APPROVAL',
            ],
            'settlement' => 'NOT_IMPLEMENTED',
        ];
    }
}
