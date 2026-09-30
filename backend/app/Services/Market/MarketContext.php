<?php

namespace App\Services\Market;

use App\Exceptions\ApiException;
use App\Models\Market;

/**
 * Resolves the market a request is served by. The default market is configuration
 * (config/market.php), and a market only serves customers while it is ACTIVE or PILOT.
 */
final class MarketContext
{
    public function current(?string $countryCode = null): Market
    {
        $countryCode ??= (string) config('market.default_country');

        $market = Market::query()->servingCustomers()->where('country_code', $countryCode)->first();

        if ($market === null) {
            throw ApiException::notFound('market_unavailable', 'FoodOnTheGo is not available in this country yet.');
        }

        return $market;
    }
}
