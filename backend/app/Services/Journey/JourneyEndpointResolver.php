<?php

namespace App\Services\Journey;

use App\Models\Customer;
use App\Models\CustomerSavedLocation;
use App\Models\Market;
use App\Support\Geo\Location;
use App\Support\PlainText;
use Illuminate\Validation\ValidationException;

/**
 * Turns what a client sends for one end of a journey into a JourneyEndpoint. Exactly one of three forms is
 * accepted — the same three for origin and destination:
 *
 *   place_id            resolved through the PlacesProvider (the text a client typed is never trusted)
 *   lat + lng           manual / device coordinates, validated with the Module 22 rules
 *   saved_location_id   one of the signed-in customer's own Module 25 saved places
 */
final class JourneyEndpointResolver
{
    public function __construct(private readonly PlaceService $places) {}

    /**
     * @param  array<string, mixed>  $input  validated request block
     */
    public function resolve(array $input, Market $market, ?Customer $customer, string $field): JourneyEndpoint
    {
        $forms = array_filter([
            'place_id' => isset($input['place_id']) && $input['place_id'] !== '',
            'coordinates' => isset($input['lat'], $input['lng']),
            'saved_location_id' => isset($input['saved_location_id']) && $input['saved_location_id'] !== '',
        ]);
        if (count($forms) !== 1) {
            throw ValidationException::withMessages([$field => ['Give exactly one of place_id, lat + lng, or saved_location_id.']]);
        }
        $label = PlainText::clean(isset($input['label']) ? (string) $input['label'] : null, "{$field}.label");

        if (isset($forms['place_id'])) {
            $place = $this->places->resolve((string) $input['place_id'], $market);

            return new JourneyEndpoint($place->location, $label ?? $place->name, 'place', $place->kind, $place->provider, $place->placeId);
        }

        if (isset($forms['saved_location_id'])) {
            if ($customer === null) {
                throw ValidationException::withMessages(["{$field}.saved_location_id" => ['Sign in to use a saved place.']]);
            }
            $saved = CustomerSavedLocation::query()->where('customer_id', $customer->getKey())->where('public_id', (string) $input['saved_location_id'])->first();
            if ($saved === null) {
                throw ValidationException::withMessages(["{$field}.saved_location_id" => ['This saved place was not found.']]);
            }
            if (! $saved->hasPoint()) {
                throw ValidationException::withMessages(["{$field}.saved_location_id" => ['This saved place has no map pin yet — add one, or choose another place.']]);
            }
            $location = $this->places->withTimezone(new Location(
                (float) $saved->latitude, (float) $saved->longitude, $saved->country_code, $saved->formatted_address, $saved->place_id,
                $saved->region, $saved->city ?? $saved->locality, $saved->postal_code, $saved->timezone,
            ), $market);

            return new JourneyEndpoint($location, $label ?? (string) $saved->label, 'saved_location', 'saved', $saved->place_id === null ? null : (string) $saved->place_provider, $saved->place_id);
        }

        $location = $this->places->withTimezone(Location::fromArray([
            'lat' => $input['lat'], 'lng' => $input['lng'], 'country_code' => $input['country_code'] ?? null,
            'formatted_address' => PlainText::clean(isset($input['formatted_address']) ? (string) $input['formatted_address'] : null, "{$field}.formatted_address"),
            'timezone' => $input['timezone'] ?? null,
        ]), $market);

        return new JourneyEndpoint($location, $label ?? 'Pinned location', 'coordinates', 'point');
    }
}
