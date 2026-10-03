<?php

namespace App\Services\Restaurant;

use App\Services\Market\MarketContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for customer-facing restaurant data that changes only when somebody saves something — today the
 * cuisine taxonomy with its restaurant counts, which every discovery screen reads.
 *
 * Deliberately NOT cached: the restaurant list and detail. They carry the answer to "is it open / can I order
 * right now?", which depends on the clock, and a distance that depends on the caller; both are calculated on
 * every request from indexed queries whose number does not grow with the page size. So no cached entry can
 * carry an open / closed state past a boundary.
 *
 * Keys live under a restaurant version AND the market version: any change to a restaurant (flush() — called by
 * every service that writes, and by model events as a safety net) or to market geography (MarketContext::flush())
 * makes every cached entry unreachable at once. The lifetime is only an upper bound.
 */
final class RestaurantCatalog
{
    private const VERSION_KEY = 'restaurant:version';

    public function __construct(private readonly MarketContext $markets) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public function remember(string $suffix, Closure $build): mixed
    {
        return Cache::remember($this->key($suffix), (int) config('restaurant.public_cache_seconds'), $build);
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(8)));
    }

    public function key(string $suffix): string
    {
        return $this->markets->key('restaurant:'.Cache::rememberForever(self::VERSION_KEY, fn (): string => bin2hex(random_bytes(8))).':'.$suffix);
    }
}
