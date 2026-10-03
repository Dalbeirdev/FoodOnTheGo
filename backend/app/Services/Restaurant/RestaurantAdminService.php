<?php

namespace App\Services\Restaurant;

use App\Enums\RestaurantStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\City;
use App\Models\Market;
use App\Models\RestaurantAdminNote;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Audit\AuditRecorder;
use App\Support\PhoneNumber;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Administrator-side creation and maintenance of restaurant organizations and locations.
 *
 * There is no public way to create an operating restaurant: an administrator creates a DRAFT, and it only
 * reaches customers through the approval lifecycle. Everything a restaurant must not decide for itself is
 * set here and nowhere else — the market, the geography (validated against the market and resolved by
 * PostGIS), the currency and the time zone.
 *
 *  - currency: the market's default, or another currency the market supports;
 *  - time zone: the city's zone unless a valid IANA zone is given;
 *  - a SINGLE_MARKET organization has every location in its primary market;
 *  - slugs are generated, unique (organization: globally, location: per market) and never change afterwards.
 */
final class RestaurantAdminService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly RestaurantCatalog $catalog,
        private readonly LocationGeography $geography,
        private readonly PickupSettingsService $pickup,
    ) {}

    /**
     * @param  array{legal_name: string, display_name: string, market_scope?: string}  $input
     */
    public function createOrganization(Market $market, array $input, AdminUser $actor): RestaurantOrganization
    {
        return DB::transaction(function () use ($market, $input, $actor): RestaurantOrganization {
            $organization = (new RestaurantOrganization)->forceFill([
                'legal_name' => PlainText::clean($input['legal_name'], 'legal_name'),
                'display_name' => PlainText::clean($input['display_name'], 'display_name'),
                'slug' => $this->uniqueSlug((string) $input['display_name'], fn (string $slug): bool => RestaurantOrganization::query()->where('slug', $slug)->exists()),
                'status' => RestaurantStatus::Draft,
                'market_scope' => $input['market_scope'] ?? RestaurantOrganization::SINGLE_MARKET,
                'primary_market_id' => $market->getKey(),
            ]);
            $organization->save();

            $this->audit->record('restaurant.created', $organization, $actor, ['display_name' => ['from' => null, 'to' => $organization->display_name], 'status' => ['from' => null, 'to' => 'DRAFT']], null, (int) $market->getKey());
            $this->catalog->flush();

            return $organization;
        });
    }

    /**
     * @param  array{version: int, legal_name?: string, display_name?: string, market_scope?: string}  $input
     */
    public function updateOrganization(RestaurantOrganization $organization, array $input, AdminUser $actor): RestaurantOrganization
    {
        return DB::transaction(function () use ($organization, $input, $actor): RestaurantOrganization {
            $locked = RestaurantOrganization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);

            foreach (['legal_name', 'display_name'] as $field) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, PlainText::clean($input[$field], $field));
                }
            }
            if (isset($input['market_scope'])) {
                $locked->setAttribute('market_scope', $input['market_scope']);
            }

            return $this->saveWithAudit($locked, 'restaurant.updated', $actor, (int) $locked->primary_market_id);
        });
    }

    /**
     * @param  array<string, mixed>  $input  name, latitude, longitude, formatted_address and optional placement / contact fields
     */
    public function createLocation(RestaurantOrganization $organization, Market $market, ?City $city, array $input, AdminUser $actor): RestaurantLocation
    {
        if ($organization->market_scope === RestaurantOrganization::SINGLE_MARKET && (int) $organization->primary_market_id !== (int) $market->getKey()) {
            throw ApiException::conflict('market_not_allowed', 'This organization operates in one market only. Its locations must be in that market.');
        }

        $place = $this->geography->resolve($market, (float) $input['latitude'], (float) $input['longitude'], $city);
        $name = (string) PlainText::clean($input['name'], 'name');

        return DB::transaction(function () use ($organization, $market, $place, $name, $input, $actor): RestaurantLocation {
            $location = (new RestaurantLocation)->forceFill([
                'organization_id' => $organization->getKey(),
                'market_id' => $market->getKey(),
                'region_id' => $place['region_id'],
                'city_id' => $place['city']->getKey(),
                'service_area_id' => $place['service_area']?->getKey(),
                'service_area_resolved_at' => now(),
                'name' => $name,
                'branch_label' => PlainText::clean($input['branch_label'] ?? null, 'branch_label'),
                'slug' => $this->uniqueSlug($name, fn (string $slug): bool => RestaurantLocation::query()->where('restaurant_locations.market_id', $market->getKey())->where('restaurant_locations.slug', $slug)->exists(), $place['city']->slug),
                'status' => RestaurantStatus::Draft,
                'timezone' => $input['timezone'] ?? $place['city']->timezone,
                'currency' => $this->currency($market, $input['currency'] ?? null),
                'locale' => $market->default_locale,
                'phone_e164' => $this->phone($market, $input['phone'] ?? null),
                'public_email' => isset($input['public_email']) ? mb_strtolower(trim((string) $input['public_email'])) : null,
                'website' => $input['website'] ?? null,
                'formatted_address' => PlainText::clean($input['formatted_address'], 'formatted_address'),
                'address_line1' => PlainText::clean($input['address_line1'] ?? null, 'address_line1'),
                'postal_code' => PlainText::clean($input['postal_code'] ?? null, 'postal_code'),
            ])->insertWithSpatial(['location' => RestaurantLocation::pointExpression((float) $input['latitude'], (float) $input['longitude'])]);

            $this->pickup->createDefaults($location, $market);

            $this->audit->record('restaurant_location.created', $location, $actor, [
                'name' => ['from' => null, 'to' => $location->name], 'status' => ['from' => null, 'to' => 'DRAFT'],
                'city' => ['from' => null, 'to' => $place['city']->name], 'service_area' => ['from' => null, 'to' => $place['service_area']?->name],
            ], null, (int) $market->getKey());
            $this->catalog->flush();

            return $location;
        });
    }

    /**
     * Placement and identity of a location — administrator only. New coordinates are validated against the
     * market and the service area is resolved again.
     *
     * @param  array<string, mixed>  $input  version and any of: name, branch_label, latitude + longitude, city (resolved), address, timezone, currency, phone
     */
    public function updateLocation(RestaurantLocation $location, ?City $city, array $input, AdminUser $actor): RestaurantLocation
    {
        $location->loadMissing(['market', 'city', 'serviceArea']);
        $market = $location->market;
        $moved = isset($input['latitude'], $input['longitude']);
        $place = $moved || $city !== null
            ? $this->geography->resolve($market, (float) ($input['latitude'] ?? $location->latitude), (float) ($input['longitude'] ?? $location->longitude), $city)
            : null;

        return DB::transaction(function () use ($location, $market, $moved, $place, $input, $actor): RestaurantLocation {
            $locked = RestaurantLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);
            $extra = [];

            foreach (['name', 'branch_label', 'formatted_address', 'address_line1', 'postal_code'] as $field) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, PlainText::clean($input[$field], $field));
                }
            }
            if (array_key_exists('timezone', $input)) {
                $locked->setAttribute('timezone', $input['timezone']);
            }
            if (array_key_exists('currency', $input)) {
                $locked->setAttribute('currency', $this->currency($market, $input['currency']));
            }
            if (array_key_exists('phone', $input)) {
                $locked->setAttribute('phone_e164', $this->phone($market, $input['phone']));
            }
            if ($place !== null) {
                $locked->forceFill(['region_id' => $place['region_id'], 'city_id' => $place['city']->getKey(), 'service_area_id' => $place['service_area']?->getKey(), 'service_area_resolved_at' => now()]);
                if (! $place['city']->is($location->city)) {
                    $extra['city'] = ['from' => $location->city->name, 'to' => $place['city']->name];
                }
                if ($place['service_area']?->getKey() !== $location->serviceArea?->getKey()) {
                    $extra['service_area'] = ['from' => $location->serviceArea?->name, 'to' => $place['service_area']?->name];
                }
            }
            if ($moved) {
                $extra['location'] = ['from' => [(float) $location->latitude, (float) $location->longitude], 'to' => [(float) $input['latitude'], (float) $input['longitude']]];
            }

            $saved = $this->saveWithAudit($locked, 'restaurant_location.updated', $actor, (int) $market->getKey(), $extra);
            if ($moved) {
                $saved->updateSpatial('location', RestaurantLocation::pointExpression((float) $input['latitude'], (float) $input['longitude']));
                $this->catalog->flush();
            }

            return RestaurantLocation::query()->whereKey($saved->getKey())->firstOrFail();
        });
    }

    public function addNote(RestaurantOrganization $organization, ?RestaurantLocation $location, string $note, AdminUser $actor): RestaurantAdminNote
    {
        return RestaurantAdminNote::query()->create([
            'organization_id' => $organization->getKey(),
            'location_id' => $location?->getKey(),
            'admin_user_id' => $actor->getKey(),
            'note' => PlainText::clean($note, 'note', multiline: true),
            'created_at' => now(),
        ]);
    }

    /**
     * Saves dirty attributes, bumps the version and writes one audit event with the before / after values.
     *
     * @template T of RestaurantOrganization|RestaurantLocation
     *
     * @param  T  $record
     * @param  array<string, array{from: mixed, to: mixed}>  $extra
     * @return T
     */
    private function saveWithAudit(RestaurantOrganization|RestaurantLocation $record, string $action, AdminUser $actor, int $marketId, array $extra = []): RestaurantOrganization|RestaurantLocation
    {
        $changes = $extra;
        foreach (array_keys($record->getDirty()) as $key) {
            // Internal ids never go into the audit trail: geography is recorded by name in $extra.
            if (! in_array($key, ['region_id', 'city_id', 'service_area_id', 'service_area_resolved_at'], true)) {
                $changes[$key] = ['from' => $record->getRawOriginal($key), 'to' => $record->getAttributes()[$key]];
            }
        }

        if (! $record->isDirty() && $changes === []) {
            return $record;
        }

        $record->setAttribute('version', (int) $record->version + 1);
        $record->save();
        if ($changes !== []) {
            $this->audit->record($action, $record, $actor, $changes, null, $marketId);
        }
        $this->catalog->flush();

        return $record;
    }

    private function currency(Market $market, ?string $currency): string
    {
        $currency ??= $market->default_currency;

        if (! in_array($currency, $market->supported_currencies ?? [$market->default_currency], true)) {
            throw ValidationException::withMessages(['currency' => ["The currency must be one the market supports ({$market->default_currency})."]]);
        }

        return $currency;
    }

    private function phone(Market $market, ?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        try {
            return PhoneNumber::parseBusiness($phone, $market)->e164;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => [$e->getMessage()]]);
        }
    }

    /**
     * "burger-hub", then "burger-hub-noida", then "burger-hub-noida-2" … — the first one that is free.
     *
     * @param  callable(string): bool  $taken
     */
    private function uniqueSlug(string $name, callable $taken, ?string $qualifier = null): string
    {
        $base = Str::slug($name) ?: 'restaurant';
        $candidates = [$base];
        if ($qualifier !== null && $qualifier !== '') {
            $candidates[] = $base.'-'.Str::slug($qualifier);
        }

        foreach ($candidates as $candidate) {
            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        $stem = end($candidates);
        for ($i = 2; $i < 1000; $i++) {
            if (! $taken("{$stem}-{$i}")) {
                return "{$stem}-{$i}";
            }
        }

        return $stem.'-'.Str::lower(Str::random(8));
    }
}
