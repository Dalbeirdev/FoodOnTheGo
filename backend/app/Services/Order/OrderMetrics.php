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

    public const EVENTS = ['created', 'creation_failed', 'recovery_required', 'review_required', 'accepted', 'rejected', 'preparing', 'ready', 'cancelled', 'refund_required', 'zero_total'];

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
            'realtime_tracking' => 'NOT_IMPLEMENTED',
            'pickup_verification' => 'NOT_IMPLEMENTED',
            'refund_processing' => 'NOT_IMPLEMENTED',
        ];
    }
}
