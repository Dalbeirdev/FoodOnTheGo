<?php

namespace App\Services\Cart;

use App\Enums\CartStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numbers about carts for platform administrators — how many are active, how many belong to guests, how many
 * wait for the cleanup, and how often the notable things happened in the last hours (restaurant conflicts,
 * confirmed switches, sign-in merges, accepted price changes). Counts only: never a cart's contents, its owner
 * or its journey.
 *
 * Event counters live in the cache in one-hour buckets and simply age out; the cart counts are read from the
 * database when asked.
 */
final class CartMetrics
{
    private const PREFIX = 'cart:metrics:';

    public const EVENTS = ['created', 'item_added', 'item_updated', 'item_removed', 'cleared', 'restaurant_conflict', 'restaurant_switched', 'claimed', 'merged', 'merge_conflict', 'revalidated', 'price_changes_accepted', 'expired'];

    public function record(string $event, int $by = 1): void
    {
        if ($by < 1 || ! in_array($event, self::EVENTS, true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('cart.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $hours = 24): array
    {
        $hours = max(1, min($hours, (int) config('cart.metrics.retention_hours')));
        $now = CarbonImmutable::now('UTC');
        $events = [];
        foreach (self::EVENTS as $event) {
            $events[$event] = 0;
            for ($i = 0; $i < $hours; $i++) {
                $events[$event] += (int) Cache::get(self::PREFIX.$event.':'.$now->subHours($i)->format('YmdH'), 0);
            }
        }

        $active = DB::table('carts')->where('status', CartStatus::Active->value)
            ->selectRaw('count(*) filter (where customer_id is not null and expires_at > ?) as customers', [$now])
            ->selectRaw('count(*) filter (where customer_id is null and expires_at > ?) as guests', [$now])
            ->selectRaw('count(*) filter (where expires_at <= ?) as expired_awaiting_cleanup', [$now])
            ->first();
        $closed = DB::table('carts')->where('status', '!=', CartStatus::Active->value)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'checked_at' => $now->toIso8601String(),
            'carts' => [
                'active' => (int) $active->customers + (int) $active->guests,
                'active_customer' => (int) $active->customers,
                'active_guest' => (int) $active->guests,
                'expired_awaiting_cleanup' => (int) $active->expired_awaiting_cleanup,
                'closed' => [
                    'expired' => (int) ($closed[CartStatus::Expired->value] ?? 0),
                    'abandoned' => (int) ($closed[CartStatus::Abandoned->value] ?? 0),
                    'invalidated' => (int) ($closed[CartStatus::Invalidated->value] ?? 0),
                    'converted' => (int) ($closed[CartStatus::Converted->value] ?? 0),
                ],
            ],
            'window_hours' => $hours,
            'events' => $events,
            'lifetimes' => [
                'guest_hours' => (int) config('cart.ttl.guest_hours'),
                'customer_hours' => (int) config('cart.ttl.customer_hours'),
                'expired_grace_hours' => (int) config('cart.ttl.expired_grace_hours'),
                'closed_retention_days' => (int) config('cart.ttl.closed_retention_days'),
            ],
        ];
    }
}
