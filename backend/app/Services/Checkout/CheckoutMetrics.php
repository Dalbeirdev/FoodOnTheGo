<?php

namespace App\Services\Checkout;

use App\Enums\CheckoutStatus;
use App\Services\Payment\PaymentProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numbers about checkout for the platform administrators (Module 29): how many checkouts were created, confirmed,
 * went stale or expired, how promotions fared, how often a pickup time could not be held. Counters in hourly cache
 * buckets plus a few counts from the database — NUMBERS only: no cart, no customer, no code, no amount of anybody.
 */
final class CheckoutMetrics
{
    private const PREFIX = 'checkout:metrics:';

    public const EVENTS = [
        'created', 'reused', 'blocked', 'confirmed', 'stale', 'expired', 'cancelled', 'promotion_applied', 'promotion_refused', 'promotion_removed',
        'hold_failed', 'payment_started', 'payment_ended', 'price_changed', 'pickup_invalid', 'discount_minor_total',
    ];

    public function record(string $event, int $by = 1): void
    {
        if ($by < 1 || ! in_array($event, self::EVENTS, true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('checkout.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $hours = 24): array
    {
        $hours = max(1, min($hours, (int) config('checkout.metrics.retention_hours')));
        $now = CarbonImmutable::now('UTC');
        $events = [];
        foreach (self::EVENTS as $event) {
            $events[$event] = 0;
            for ($i = 0; $i < $hours; $i++) {
                $events[$event] += (int) Cache::get(self::PREFIX.$event.':'.$now->subHours($i)->format('YmdH'), 0);
            }
        }
        $byStatus = DB::table('checkout_sessions')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $holds = (int) DB::table('pickup_slot_reservations')->where('source', 'HOLD')->where('status', 'ACTIVE')->where('expires_at', '>', $now)->count();
        $promotions = DB::table('promotions')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $applied = $events['promotion_applied'];
        $attempts = $applied + $events['promotion_refused'];
        $discountTotal = $events['discount_minor_total'];
        unset($events['discount_minor_total']);

        return [
            'checked_at' => $now->toIso8601String(),
            'window_hours' => $hours,
            'checkouts' => array_combine(
                array_map(fn (CheckoutStatus $s): string => strtolower($s->value), CheckoutStatus::cases()),
                array_map(fn (CheckoutStatus $s): int => (int) ($byStatus[$s->value] ?? 0), CheckoutStatus::cases()),
            ),
            'events' => $events,
            'rates' => [
                // Of the checkouts created in the window, how many were confirmed.
                'ready' => $events['created'] === 0 ? null : round($events['confirmed'] / $events['created'], 4),
                'promotion_success' => $attempts === 0 ? null : round($applied / $attempts, 4),
            ],
            // The mean discount of the promotions applied in the window, in minor units. One number for all
            // currencies: right while one market is live, to be split per currency when a second one is.
            'average_discount_minor' => $applied === 0 ? null : intdiv($discountTotal, max(1, $applied)),
            'active_pickup_holds' => $holds,
            'promotions' => ['draft' => (int) ($promotions['DRAFT'] ?? 0), 'active' => (int) ($promotions['ACTIVE'] ?? 0), 'paused' => (int) ($promotions['PAUSED'] ?? 0), 'disabled' => (int) ($promotions['DISABLED'] ?? 0)],
            'slot_hold' => 'IMPLEMENTED',
            'payment_provider' => app(PaymentProviderManager::class)->summary()['status'],
        ];
    }
}
