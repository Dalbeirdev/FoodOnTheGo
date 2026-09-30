<?php

namespace App\Services\Market;

use App\Exceptions\ApiException;
use App\Http\Resources\MarketResource;
use App\Models\Market;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the market a request is served by. The default market is configuration (config/market.php), and
 * a market only serves customers while it is ACTIVE or PILOT.
 *
 * The public market payload is read on almost every screen, so it is cached under a global version; any
 * administrative change to markets or their geography bumps the version (flush()), which makes every cached
 * entry unreachable at once. Decisions (availability, sign-in) always read the database.
 */
final class MarketContext
{
    private const VERSION_KEY = 'market:version';

    public function current(?string $countryCode = null): Market
    {
        $countryCode ??= (string) config('market.default_country');

        $market = Market::query()->servingCustomers()->where('country_code', $countryCode)->first();

        if ($market === null) {
            throw ApiException::notFound('market_unavailable', 'FoodOnTheGo is not available in this country yet.');
        }

        return $market;
    }

    /**
     * Customer-safe market payload, cached.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(?string $countryCode = null): array
    {
        $countryCode ??= (string) config('market.default_country');

        return Cache::remember($this->key('public:'.$countryCode), (int) config('geo.market_cache_seconds'), fn (): array => (new MarketResource($this->current($countryCode)))->resolve());
    }

    /**
     * Every market customers may be served by (never DRAFT / PAUSED / CLOSED ones), cached.
     *
     * @return list<array<string, mixed>>
     */
    public function publicMarkets(): array
    {
        return Cache::remember($this->key('public:list'), (int) config('geo.market_cache_seconds'), fn (): array => Market::query()->servingCustomers()->orderBy('name')->get()
            ->map(fn (Market $market): array => (new MarketResource($market))->resolve())->all());
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(8)));
    }

    public function key(string $suffix): string
    {
        return 'market:'.Cache::rememberForever(self::VERSION_KEY, fn (): string => bin2hex(random_bytes(8))).':'.$suffix;
    }
}
