<?php

namespace App\Services\Order;

use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Counts for operators (Module 31): what happened to orders in the last hours (cache, hour buckets) and where
 * they stand now (the database). Never a customer, an item or an amount of anybody.
 */
final class OrderMetrics
{
    private const PREFIX = 'orders:metrics:';

    public const EVENTS = ['created', 'creation_failed', 'recovery_required', 'review_required', 'accepted', 'rejected', 'preparing', 'ready', 'cancelled', 'refund_required', 'zero_total',
        // Module 32
        'pickup_verified', 'pickup_verified_qr', 'pickup_verified_manual_code', 'pickup_refused', 'pickup_refused_invalid_credential', 'pickup_refused_not_ready', 'pickup_refused_not_eligible', 'pickup_refused_wrong_location', 'pickup_refused_expired', 'pickup_refused_revoked', 'pickup_refused_already_used', 'pickup_refused_locked', 'pickup_rate_limited', 'pickup_locked',
        'completed', 'completed_auto_after_pickup', 'completed_sweep', 'completion_deferred',
        'broadcast_sent', 'broadcast_failed', 'broadcast_ms_total', 'channel_auth_granted', 'channel_auth_denied', 'events_resync'];

    public function record(string $event, int $by = 1): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.CarbonImmutable::now()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('orders.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    public function recordCreationLatency(int $ms): void
    {
        foreach (['creation_ms_total' => $ms, 'creation_count' => 1] as $name => $by) {
            $key = self::PREFIX.$name.':'.CarbonImmutable::now()->format('YmdH');
            Cache::add($key, 0, max(1, (int) config('orders.metrics.retention_hours')) * 3600);
            Cache::increment($key, $by);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $hours = 24): array
    {
        $now = CarbonImmutable::now();
        $sum = function (string $name) use ($now, $hours): int {
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
        $count = $sum('creation_count');
        $byStatus = Order::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $orders = [];
        foreach (OrderStatus::cases() as $status) {
            if ($status !== OrderStatus::Created) {
                $orders[strtolower($status->value)] = (int) ($byStatus[$status->value] ?? 0);
            }
        }

        return [
            'checked_at' => $now->toIso8601String(),
            'window_hours' => $hours,
            'events' => $events,
            'orders' => $orders,
            'active' => Order::query()->whereIn('status', OrderStatus::activeValues())->count(),
            'waiting_for_restaurant' => (int) ($byStatus[OrderStatus::WaitingForRestaurant->value] ?? 0),
            'refund_required' => Order::query()->where('refund_status', OrderRefundStatus::Required->value)->count(),
            // Money that waits for an order: the operational condition that must never go unseen.
            'verified_payments_without_order' => PaymentAttempt::query()->where('status', 'VERIFIED')->whereIn('fulfillment_status', ['ORDER_PENDING', 'RECOVERY_REQUIRED'])->count(),
            'fulfillment' => [
                'retrying' => OrderFulfillment::query()->where('status', OrderFulfillmentStatus::Retrying->value)->count(),
                'review_required' => OrderFulfillment::query()->where('status', OrderFulfillmentStatus::ReviewRequired->value)->count(),
                'failed' => OrderFulfillment::query()->where('status', OrderFulfillmentStatus::Failed->value)->count(),
            ],
            'latency_ms' => ['creation_avg' => $count > 0 ? (int) round($sum('creation_ms_total') / $count) : null],
            'realtime' => [
                'broadcast_sent' => $events['broadcast_sent'],
                'broadcast_failed' => $events['broadcast_failed'],
                'broadcast_avg_ms' => $events['broadcast_sent'] > 0 ? (int) round($events['broadcast_ms_total'] / max(1, (int) ($events['broadcast_sent'] / 2))) : null,
                'channel_auth_granted' => $events['channel_auth_granted'],
                'channel_auth_denied' => $events['channel_auth_denied'],
                'events_resync' => $events['events_resync'],
            ],
            'pickup' => [
                'verified' => $events['pickup_verified'],
                'verified_qr' => $events['pickup_verified_qr'],
                'verified_manual_code' => $events['pickup_verified_manual_code'],
                'refused' => $events['pickup_refused'],
                'refused_invalid' => $events['pickup_refused_invalid_credential'],
                'refused_not_ready' => $events['pickup_refused_not_ready'],
                'refused_wrong_location' => $events['pickup_refused_wrong_location'],
                'replay' => $events['pickup_refused_already_used'],
                'rate_limited' => $events['pickup_rate_limited'] + $events['pickup_refused_locked'],
                'locked' => $events['pickup_locked'],
                'picked_up_not_completed' => (int) ($byStatus[OrderStatus::PickedUp->value] ?? 0),
            ],
            'completion' => [
                'completed' => $events['completed'],
                'after_pickup' => $events['completed_auto_after_pickup'],
                'by_sweep' => $events['completed_sweep'],
                'deferred' => $events['completion_deferred'],
            ],
            'realtime_tracking' => 'IMPLEMENTED',
            'pickup_verification' => 'IMPLEMENTED',
            'order_completion' => 'IMPLEMENTED',
            // Refunds (Module 35) are their own domain; their numbers are at /admin/system/refunds.
            'refund_processing' => 'IMPLEMENTED',
            // Notifications (Module 33) are their own domain; their numbers are at /admin/system/notifications.
            'notification_delivery' => 'IMPLEMENTED',
        ];
    }
}
