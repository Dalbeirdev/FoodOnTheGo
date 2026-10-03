<?php

namespace App\Http\Controllers\Api\Restaurant\Concerns;

use App\Models\RestaurantLocation;

/**
 * The relations a full location representation reads (staff and administrator resources, availability).
 * Loaded once per request so no resource triggers a query per row.
 */
trait LoadsRestaurantLocations
{
    /**
     * @return list<string>
     */
    protected function locationRelations(): array
    {
        return ['organization', 'market', 'city', 'region', 'serviceArea', 'cuisines', 'features', 'images', 'hours', 'specialHours.periods', 'pickupSettings', 'pickupMethods'];
    }

    /**
     * The location as stored now, with everything its representation needs.
     */
    protected function fresh(RestaurantLocation $location): RestaurantLocation
    {
        return RestaurantLocation::query()->with($this->locationRelations())->whereKey($location->getKey())->firstOrFail();
    }
}
