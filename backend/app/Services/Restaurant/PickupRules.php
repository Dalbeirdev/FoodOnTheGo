<?php

namespace App\Services\Restaurant;

use App\Enums\PickupMethod;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationPickupMethod;

/**
 * What a location actually offers for pickup: its own settings limited by what its market allows.
 * A method or mode the market has switched off is not offered, whatever the location has saved — so nothing
 * is enabled "globally" by a restaurant, and switching a market feature off takes effect at once.
 *
 * Expects the location's market, pickupSettings and pickupMethods to be loaded.
 */
final class PickupRules
{
    public static function methodAllowed(Market $market, PickupMethod $method): bool
    {
        $feature = config('restaurant.pickup_methods')[$method->value] ?? null;

        return $feature === null || $market->featureEnabled($feature);
    }

    /**
     * @param  'asap'|'scheduled'  $mode
     */
    public static function modeAllowed(Market $market, string $mode): bool
    {
        return $market->featureEnabled((string) config("restaurant.pickup_modes.{$mode}"));
    }

    /**
     * @return list<RestaurantLocationPickupMethod>
     */
    public static function offeredMethods(RestaurantLocation $location): array
    {
        return array_values($location->pickupMethods->filter(
            fn (RestaurantLocationPickupMethod $m): bool => $m->enabled && self::methodAllowed($location->market, $m->method),
        )->all());
    }

    /**
     * @return array{asap: bool, scheduled: bool}
     */
    public static function offeredModes(RestaurantLocation $location): array
    {
        $settings = $location->pickupSettings;

        return [
            'asap' => $settings !== null && $settings->pickup_enabled && $settings->asap_enabled && self::modeAllowed($location->market, 'asap'),
            'scheduled' => $settings !== null && $settings->pickup_enabled && $settings->scheduled_enabled && self::modeAllowed($location->market, 'scheduled'),
        ];
    }

    /**
     * Pickup can be offered at all: enabled, at least one mode, at least one method.
     */
    public static function available(RestaurantLocation $location): bool
    {
        $modes = self::offeredModes($location);

        return ($modes['asap'] || $modes['scheduled']) && self::offeredMethods($location) !== [];
    }
}
