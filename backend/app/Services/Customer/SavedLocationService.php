<?php

namespace App\Services\Customer;

use App\Auth\Principal;
use App\Enums\SavedLocationKind;
use App\Models\Customer;
use App\Models\CustomerSavedLocation;
use App\Services\Audit\AuditRecorder;
use App\Services\Market\Availability;
use App\Services\Market\MarketAvailabilityService;
use App\Support\Geo\Location;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saved journey locations of a customer (Module 25): Home, Work and other shortcuts for planning a journey.
 * Not delivery addresses — FoodOnTheGo is pickup-only and nothing here assumes delivery.
 *
 *  - Address fields are flexible (a place may have no street or postal code); a label is the only requirement.
 *  - A point (WGS84, SRID 4326) is optional until the places provider exists: without one the location is
 *    stored as text and its coverage is "unknown". With one, the market, city and service area are resolved by
 *    PostGIS (Module 22) on every save; whether FoodOnTheGo serves the place is decided per request from the
 *    geometry and the current statuses — saving a place never makes it serviceable, and a place outside every
 *    market (abroad) is kept and marked unsupported.
 *  - One default per customer, guaranteed by the database; the first saved location becomes the default.
 *  - Edits carry a version (409 stale_update); the count is limited by configuration.
 */
final class SavedLocationService
{
    private const TEXT = ['label' => 40, 'line1' => 200, 'line2' => 200, 'locality' => 120, 'city' => 120, 'region' => 120, 'postal_code' => 20, 'formatted_address' => 300];

    public function __construct(private readonly MarketAvailabilityService $availability, private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Customer $customer, array $input, Principal $actor): CustomerSavedLocation
    {
        return DB::transaction(function () use ($customer, $input, $actor): CustomerSavedLocation {
            // the customer row is the lock for "how many saved locations" (PostgreSQL refuses FOR UPDATE on aggregates)
            Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $count = CustomerSavedLocation::query()->where('customer_id', $customer->getKey())->count();
            if ($count >= (int) config('customer.limits.saved_locations')) {
                throw ValidationException::withMessages(['label' => ['You have reached the maximum number of saved locations ('.config('customer.limits.saved_locations').').']]);
            }
            $location = (new CustomerSavedLocation)->forceFill([
                'customer_id' => $customer->getKey(),
                'kind' => SavedLocationKind::from((string) ($input['kind'] ?? 'OTHER')),
                'is_default' => $count === 0 || (bool) ($input['is_default'] ?? false),
                'place_provider' => $input['place_provider'] ?? null, 'place_id' => $input['place_id'] ?? null,
                'country_code' => $input['country_code'] ?? null, 'timezone' => $input['timezone'] ?? null,
            ]);
            $this->applyText($location, $input, true);
            if ($location->is_default) {
                CustomerSavedLocation::query()->where('customer_id', $customer->getKey())->update(['is_default' => false]);
            }
            $point = $this->pointFrom($input);
            if ($point !== null) {
                $this->applyResolution($location, $point);
                $location = $location->insertWithSpatial(['location' => CustomerSavedLocation::pointExpression($point->latitude, $point->longitude)]);
            } else {
                $location->save();
                $location = $location->reload();
            }
            $this->audit->record('customer.saved_location_added', $location, $actor, ['kind' => ['from' => null, 'to' => $location->kind->value], 'has_point' => ['from' => null, 'to' => $point !== null]], null, $location->market_id === null ? null : (int) $location->market_id);

            return $location;
        });
    }

    /**
     * @param  array<string, mixed>  $input  version + any field; `location: null` removes the point, `lat` + `lng` set it
     */
    public function update(CustomerSavedLocation $location, array $input, Principal $actor): CustomerSavedLocation
    {
        return DB::transaction(function () use ($location, $input, $actor): CustomerSavedLocation {
            $locked = CustomerSavedLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);
            if (array_key_exists('kind', $input)) {
                $locked->kind = SavedLocationKind::from((string) $input['kind']);
            }
            foreach (['place_provider', 'place_id', 'country_code', 'timezone'] as $field) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, $input[$field]);
                }
            }
            $this->applyText($locked, $input, false);

            $pointChanged = false;
            if (array_key_exists('location', $input) && $input['location'] === null) {
                $locked->forceFill(['market_id' => null, 'city_id' => null, 'service_area_id' => null, 'coverage_resolved_at' => null]);
                $pointChanged = true;
            }
            $point = $this->pointFrom($input);
            if ($point !== null) {
                $this->applyResolution($locked, $point);
                $pointChanged = true;
            }
            $locked->version = (int) $locked->version + 1;
            $locked->save();
            if ($pointChanged) {
                $locked->updateSpatial('location', $point === null ? ['NULL', []] : CustomerSavedLocation::pointExpression($point->latitude, $point->longitude));
            }
            if (($input['is_default'] ?? false) === true && ! $locked->is_default) {
                $this->makeDefault($locked->reload(), $actor);
            }
            $this->audit->record('customer.saved_location_updated', $locked, $actor, ['fields' => ['from' => null, 'to' => array_values(array_diff(array_keys($input), ['version']))]], null, $locked->market_id === null ? null : (int) $locked->market_id);

            return $locked->reload();
        });
    }

    public function makeDefault(CustomerSavedLocation $location, Principal $actor): CustomerSavedLocation
    {
        return DB::transaction(function () use ($location, $actor): CustomerSavedLocation {
            CustomerSavedLocation::query()->where('customer_id', $location->customer_id)->lockForUpdate()->get();
            CustomerSavedLocation::query()->where('customer_id', $location->customer_id)->where('is_default', true)->update(['is_default' => false]);
            CustomerSavedLocation::query()->whereKey($location->getKey())->update(['is_default' => true]);
            $this->audit->record('customer.saved_location_default', $location, $actor, ['default' => ['from' => null, 'to' => $location->public_id]], null, $location->market_id === null ? null : (int) $location->market_id);

            return $location->reload();
        });
    }

    /**
     * Deleting a shortcut never touches journeys or orders that referenced the place (they keep their own copy).
     */
    public function delete(CustomerSavedLocation $location, Principal $actor): void
    {
        DB::transaction(function () use ($location, $actor): void {
            $wasDefault = $location->is_default;
            $customerId = $location->customer_id;
            $marketId = $location->market_id;
            $location->delete();
            if ($wasDefault) {
                $next = CustomerSavedLocation::query()->where('customer_id', $customerId)->orderBy('created_at')->orderBy('id')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
            $this->audit->record('customer.saved_location_deleted', $location, $actor, [], null, $marketId === null ? null : (int) $marketId);
        });
    }

    /**
     * Whether FoodOnTheGo serves the saved place right now — decided from the geometry and the current market,
     * region, city and service-area statuses, never from what was stored when it was saved.
     *
     * @return array<string, mixed>
     */
    public function coverage(CustomerSavedLocation $location): array
    {
        $point = $location->point();
        if ($point === null) {
            return ['status' => 'unknown', 'reason' => 'NO_COORDINATES', 'market' => null, 'city' => null, 'service_area' => null];
        }
        $availability = $this->availability->check($point);

        return [
            'status' => $availability->supported ? 'supported' : 'unsupported',
            'reason' => $availability->reason?->value,
            'market' => $availability->market?->country_code,
            'city' => $availability->city?->name,
            'service_area' => $availability->serviceArea?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyText(CustomerSavedLocation $location, array $input, bool $create): void
    {
        foreach (self::TEXT as $field => $max) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = PlainText::clean($input[$field] === null ? null : (string) $input[$field], $field);
            if ($value !== null && mb_strlen($value) > $max) {
                throw ValidationException::withMessages([$field => ['This text is too long.']]);
            }
            $location->setAttribute($field, $value);
        }
        if (($create || array_key_exists('label', $input)) && $location->label === null) {
            throw ValidationException::withMessages(['label' => ['Give this place a name, such as Home or Work.']]);
        }
        if ($create && $location->formatted_address === null && ! isset($input['lat'])) {
            throw ValidationException::withMessages(['formatted_address' => ['Enter the address or choose the place on the map.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function pointFrom(array $input): ?Location
    {
        if (! isset($input['lat'], $input['lng'])) {
            return null;
        }

        return new Location((float) $input['lat'], (float) $input['lng'], isset($input['country_code']) ? (string) $input['country_code'] : null);
    }

    private function applyResolution(CustomerSavedLocation $location, Location $point): void
    {
        $availability = $this->availability->check($point);
        $location->forceFill([
            'market_id' => $availability->market?->getKey(),
            'city_id' => $availability->city?->getKey(),
            'service_area_id' => $availability->serviceArea?->getKey(),
            'coverage_resolved_at' => now(),
            'country_code' => $location->country_code ?? $availability->market?->country_code,
            'timezone' => $location->timezone ?? $availability->market?->default_timezone,
        ]);
        unset($availability);
    }

    /**
     * Customer-safe description of an availability answer (shared with the recent-location list).
     *
     * @return array<string, mixed>
     */
    public static function describe(Availability $availability): array
    {
        return ['status' => $availability->supported ? 'supported' : 'unsupported', 'reason' => $availability->reason?->value, 'market' => $availability->market?->country_code];
    }
}
