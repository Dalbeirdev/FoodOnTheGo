<?php

namespace App\Http\Resources\Restaurant;

use App\Enums\PickupMethod;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationPickupMethod;
use App\Services\Restaurant\PickupRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pickup configuration of a location for the people who manage it: what is saved, what the market allows,
 * and the platform limits a form must respect. Built from a location with market, pickupSettings and
 * pickupMethods loaded.
 *
 * @mixin RestaurantLocation
 */
class PickupSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RestaurantLocation $location */
        $location = $this->resource;
        $settings = $location->pickupSettings;
        $saved = $location->pickupMethods->keyBy(fn (RestaurantLocationPickupMethod $m): string => $m->method->value);

        return [
            'location_id' => $location->public_id,
            'pickup_enabled' => (bool) $settings?->pickup_enabled,
            'asap_enabled' => (bool) $settings?->asap_enabled,
            'scheduled_enabled' => (bool) $settings?->scheduled_enabled,
            'default_prep_minutes' => $settings?->default_prep_minutes,
            'minimum_lead_minutes' => $settings?->minimum_lead_minutes,
            'buffer_minutes' => $settings?->buffer_minutes,
            'schedule_horizon_minutes' => $settings?->schedule_horizon_minutes,
            'slot_interval_minutes' => $settings?->slot_interval_minutes,
            'order_cutoff_minutes' => $settings?->order_cutoff_minutes,
            'capacity_per_slot' => $settings?->capacity_per_slot,
            // Every method the platform knows: whether this location has it on, and whether its market allows it.
            'methods' => array_map(fn (PickupMethod $method): array => [
                'method' => $method->value,
                'enabled' => (bool) ($saved[$method->value]->enabled ?? false),
                'instructions' => $saved[$method->value]->instructions ?? null,
                'requires_vehicle_info' => (bool) ($saved[$method->value]->requires_vehicle_info ?? ($method !== PickupMethod::Counter)),
                'available_in_market' => PickupRules::methodAllowed($location->market, $method),
            ], PickupMethod::cases()),
            'market' => ['asap_allowed' => PickupRules::modeAllowed($location->market, 'asap'), 'scheduled_allowed' => PickupRules::modeAllowed($location->market, 'scheduled')],
            'limits' => [
                'default_prep_minutes' => config('restaurant.limits.prep_minutes'),
                'minimum_lead_minutes' => config('restaurant.limits.lead_minutes'),
                'buffer_minutes' => config('restaurant.limits.buffer_minutes'),
                'order_cutoff_minutes' => config('restaurant.limits.cutoff_minutes'),
                'schedule_horizon_minutes' => config('restaurant.limits.schedule_horizon_minutes'),
                'slot_interval_minutes' => config('restaurant.limits.slot_intervals'),
                'capacity_per_slot' => config('restaurant.limits.capacity_per_slot'),
            ],
            'version' => (int) ($settings?->version ?? 0),
            'updated_at' => $settings?->updated_at?->toIso8601String(),
        ];
    }
}
