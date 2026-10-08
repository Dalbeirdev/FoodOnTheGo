<?php

namespace App\Services\Payment;

use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Events\Payment\PaymentEvent;
use App\Models\PaymentWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Counts of what happens to payments — numbers only, never a payment's content (Module 30).
 *
 * Events are counted in hour buckets in the cache (short-lived, for the administrators' system page); the standing
 * figures — how many attempts are in which state, how many verified payments have no order — are read from the
 * database, which is the truth. A customer who cancels is counted as cancelled, not as a provider failure.
 */
final class PaymentMetrics
{
    private const PREFIX = 'payments:metrics:';

    public const EVENTS = [
        PaymentEvent::ATTEMPT_CREATED, PaymentEvent::PROVIDER_READY, PaymentEvent::INITIALIZATION_FAILED, PaymentEvent::INITIALIZATION_UNKNOWN,
        PaymentEvent::CLIENT_CALLBACK, PaymentEvent::SIGNATURE_INVALID,
        PaymentEvent::WEBHOOK_RECEIVED, PaymentEvent::WEBHOOK_DUPLICATE, PaymentEvent::WEBHOOK_REJECTED, PaymentEvent::WEBHOOK_IGNORED, PaymentEvent::WEBHOOK_FAILED, PaymentEvent::WEBHOOK_PROCESSED,
        PaymentEvent::PROCESSING, PaymentEvent::PENDING, PaymentEvent::AUTHORIZED, PaymentEvent::VERIFIED, PaymentEvent::LATE_SUCCESS,
        PaymentEvent::FAILED, PaymentEvent::CANCELLED, PaymentEvent::EXPIRED, PaymentEvent::UNKNOWN, PaymentEvent::REVIEW_REQUIRED,
        PaymentEvent::MISMATCH, PaymentEvent::DUPLICATE_PAYMENT, PaymentEvent::RECONCILED, PaymentEvent::RECOVERY_REQUIRED,
    ];

    private const SUMS = ['verification_ms_total', 'webhook_processing_ms_total'];

    private const METHODS = ['UPI', 'CARD', 'NET_BANKING', 'WALLET', 'EMI', 'PAY_LATER'];

    public function record(string $event, int $by = 1): void
    {
        if ($by < 1 || ! in_array($event, [...self::EVENTS, ...self::SUMS, ...array_map(fn (string $m): string => 'verified_method_'.$m, self::METHODS)], true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('payments.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    public function recordVerifiedMethod(?string $method): void
    {
        if (in_array($method, self::METHODS, true)) {
            $this->record('verified_method_'.$method);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function report(PaymentProviderManager $providers, int $hours = 24): array
    {
        $hours = max(1, min($hours, (int) config('payments.metrics.retention_hours')));
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
        $byStatus = DB::table('payment_attempts')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $byFulfillment = DB::table('payment_attempts')->where('status', PaymentStatus::Verified->value)->selectRaw('fulfillment_status, count(*) as n')->groupBy('fulfillment_status')->pluck('n', 'fulfillment_status');
        $webhooks = DB::table('payment_webhook_events')->selectRaw('processing_status, count(*) as n')->groupBy('processing_status')->pluck('n', 'processing_status');
        $settled = $events[PaymentEvent::VERIFIED] + $events[PaymentEvent::FAILED] + $events[PaymentEvent::CANCELLED] + $events[PaymentEvent::EXPIRED];

        return [
            'checked_at' => $now->toIso8601String(),
            'window_hours' => $hours,
            'provider' => $providers->report(),
            'attempts' => array_combine(
                array_map(fn (PaymentStatus $s): string => strtolower($s->value), PaymentStatus::cases()),
                array_map(fn (PaymentStatus $s): int => (int) ($byStatus[$s->value] ?? 0), PaymentStatus::cases()),
            ),
            // Verified payments by what became of them. Until the order module exists every one of them waits for its order.
            'verified_payments' => [
                'order_pending' => (int) ($byFulfillment[PaymentFulfillmentStatus::OrderPending->value] ?? 0),
                'recovery_required' => (int) ($byFulfillment[PaymentFulfillmentStatus::RecoveryRequired->value] ?? 0),
                'order_created' => (int) ($byFulfillment[PaymentFulfillmentStatus::OrderCreated->value] ?? 0),
            ],
            'needs_review' => (int) ($byStatus[PaymentStatus::ReviewRequired->value] ?? 0),
            'events' => $events,
            'rates' => [
                // Of the attempts that ended in the window, how many were verified. A customer's cancellation is not a failure.
                'verified_of_settled' => $settled === 0 ? null : round($events[PaymentEvent::VERIFIED] / $settled, 4),
                'failed_of_settled' => $settled === 0 ? null : round($events[PaymentEvent::FAILED] / $settled, 4),
            ],
            'latency_ms' => [
                // From the customer's report (or the moment the provider was ready) to VERIFIED.
                'verification_avg' => $events[PaymentEvent::VERIFIED] === 0 ? null : intdiv($sum('verification_ms_total'), $events[PaymentEvent::VERIFIED]),
                // From accepting a webhook to having handled it.
                'webhook_processing_avg' => $events[PaymentEvent::WEBHOOK_PROCESSED] === 0 ? null : intdiv($sum('webhook_processing_ms_total'), $events[PaymentEvent::WEBHOOK_PROCESSED]),
            ],
            'verified_by_method' => array_filter(array_combine(self::METHODS, array_map(fn (string $m): int => $sum('verified_method_'.$m), self::METHODS))),
            'webhook_events' => [
                'received' => (int) ($webhooks[PaymentWebhookEvent::RECEIVED] ?? 0),
                'processed' => (int) ($webhooks[PaymentWebhookEvent::PROCESSED] ?? 0),
                'ignored' => (int) ($webhooks[PaymentWebhookEvent::IGNORED] ?? 0),
                'failed' => (int) ($webhooks[PaymentWebhookEvent::FAILED] ?? 0),
            ],
            // Orders (Module 31) are created from verified payments; the order numbers are at /admin/system/orders.
            'order_creation' => 'IMPLEMENTED',
            'refunds' => 'NOT_IMPLEMENTED',
        ];
    }
}
