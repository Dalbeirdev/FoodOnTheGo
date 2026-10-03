<?php

namespace Tests\Support;

use App\Enums\MembershipStatus;
use App\Enums\PickupMethod;
use App\Enums\PrincipalType;
use App\Models\City;
use App\Models\Cuisine;
use App\Models\Market;
use App\Models\RestaurantImage;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationHour;
use App\Models\RestaurantLocationPickupMethod;
use App\Models\RestaurantMembership;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantPickupSettings;
use App\Models\RestaurantSpecialHour;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\ServiceArea;
use App\Services\Restaurant\RestaurantStaffService;
use Database\Seeders\RestaurantTaxonomySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

/**
 * Small, explicit restaurants for tests: real rows in PostgreSQL / PostGIS. Everything is built in the open
 * (no hidden defaults beyond what is written here), on top of BuildsGeography.
 *
 * The standard setting is one ACTIVE square service area around Noida ([77.30, 28.50] – [77.40, 28.60]);
 * a location is placed at its centre unless a test says otherwise.
 */
trait BuildsRestaurants
{
    use BuildsGeography;

    /** [west, south, east, north] of the standard service area. */
    protected const AREA = [77.30, 28.50, 77.40, 28.60];

    protected Market $market;

    protected City $noida;

    protected ServiceArea $serviceArea;

    /**
     * India as launched (ASAP and scheduled pickup on, curbside off), roles, taxonomies, Noida and its area.
     */
    protected function setUpRestaurantWorld(): void
    {
        $this->seed([RoleSeeder::class, RestaurantTaxonomySeeder::class]);

        $this->market = $this->india();
        $this->market->forceFill(['features' => ['asap_pickup' => true, 'scheduled_pickup' => true, 'curbside_pickup' => false, 'reviews' => true, 'cash_at_pickup' => false]])->save();
        $this->noida = $this->city($this->region($this->market));
        $this->serviceArea = $this->area($this->noida, self::AREA, name: 'Noida Central');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function organization(string $name = 'Riverside Hospitality', string $status = 'APPROVED', array $attributes = []): RestaurantOrganization
    {
        return tap((new RestaurantOrganization)->forceFill([
            'legal_name' => $name.' Pvt. Ltd.', 'display_name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => $status, 'primary_market_id' => $this->market->id, ...$attributes,
        ]))->save();
    }

    /**
     * An APPROVED, operating location that accepts orders, with counter pickup — and no opening hours until a
     * test gives it some. Geography ids are written directly (tests of the resolver create locations through
     * the API instead).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function location(RestaurantOrganization $organization, string $name = 'Burger Hub', array $attributes = [], float $lat = 28.55, float $lng = 77.35, ?City $city = null): RestaurantLocation
    {
        $city ??= $this->noida;
        $covering = ServiceArea::query()->where('service_areas.market_id', $city->market_id)
            ->whereRaw('ST_Covers(service_areas.geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$lng, $lat])->orderByDesc('service_areas.priority')->first();

        $location = (new RestaurantLocation)->forceFill([
            'organization_id' => $organization->id, 'market_id' => $city->market_id, 'region_id' => $city->region_id, 'city_id' => $city->id,
            'service_area_id' => $covering?->id, 'service_area_resolved_at' => now(),
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'status' => 'APPROVED',
            'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'locale' => 'en-IN', 'formatted_address' => 'Sector 62, Noida, Uttar Pradesh 201309, India',
            ...$attributes,
        ])->insertWithSpatial(['location' => RestaurantLocation::pointExpression($lat, $lng)]);

        (new RestaurantPickupSettings)->forceFill(['location_id' => $location->id, 'pickup_enabled' => true, 'asap_enabled' => true, 'scheduled_enabled' => false])->save();
        RestaurantLocationPickupMethod::query()->create(['location_id' => $location->id, 'method' => PickupMethod::Counter, 'enabled' => true]);

        return $location;
    }

    /**
     * Weekly hours: [days, opens, closes] with days = null (every day), a day number or a list (0 = Sunday).
     *
     * @param  list<array{0: int|list<int>|null, 1: string, 2: string}>  $periods
     */
    protected function hours(RestaurantLocation $location, array $periods): RestaurantLocation
    {
        $sequence = [];
        foreach ($periods as [$days, $opens, $closes]) {
            foreach ($days === null ? range(0, 6) : (array) $days as $day) {
                RestaurantLocationHour::query()->create([
                    'location_id' => $location->id, 'kind' => RestaurantLocationHour::OPENING, 'day_of_week' => $day,
                    'opens_at' => $opens, 'closes_at' => $closes, 'sequence' => $sequence[$day] = ($sequence[$day] ?? -1) + 1,
                ]);
            }
        }

        return $location->unsetRelation('hours');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $periods  empty = closed all day
     */
    protected function special(RestaurantLocation $location, string $date, array $periods = [], ?string $note = null): RestaurantSpecialHour
    {
        $special = (new RestaurantSpecialHour)->forceFill(['location_id' => $location->id, 'date' => $date, 'is_closed' => $periods === [], 'public_note' => $note]);
        $special->save();
        foreach ($periods as $index => [$opens, $closes]) {
            $special->periods()->create(['opens_at' => $opens, 'closes_at' => $closes, 'sequence' => $index]);
        }
        $location->unsetRelation('specialHours');

        return $special;
    }

    /**
     * @param  list<string>  $codes
     */
    protected function cuisines(RestaurantLocation $location, array $codes): void
    {
        $ids = Cuisine::query()->whereIn('code', $codes)->pluck('id', 'code');
        $location->cuisines()->sync(collect($codes)->values()->mapWithKeys(fn (string $code, int $position): array => [$ids[$code] => ['position' => $position]])->all());
    }

    protected function image(RestaurantLocation $location, string $type = 'COVER', string $path = '/images/food-burger.jpg'): RestaurantImage
    {
        return tap((new RestaurantImage)->forceFill(['organization_id' => $location->organization_id, 'location_id' => $location->id, 'type' => $type, 'path' => $path, 'alt_text' => $location->name]))->save();
    }

    /**
     * A restaurant user who belongs to the organization: an ACTIVE account with a membership of the given
     * status, for all locations (null) or the listed ones. Role assignments are derived from the membership,
     * exactly as in the application.
     *
     * @param  list<RestaurantLocation>|null  $locations
     */
    protected function member(RestaurantOrganization $organization, string $role = 'OWNER', ?array $locations = null, MembershipStatus $status = MembershipStatus::Active, ?RestaurantUser $user = null): RestaurantUser
    {
        $user ??= RestaurantUser::factory()->create();

        $membership = (new RestaurantMembership)->forceFill([
            'restaurant_user_id' => $user->id, 'organization_id' => $organization->id,
            'role_id' => Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->where('code', $role)->firstOrFail()->id,
            'status' => $status, 'all_locations' => $locations === null, 'invited_name' => $user->name,
            'accepted_at' => $status === MembershipStatus::Invited ? null : now(),
        ]);
        $membership->save();
        $membership->locations()->sync(array_map(fn (RestaurantLocation $l): int => $l->id, $locations ?? []));
        app(RestaurantStaffService::class)->sync($membership);

        return $user;
    }

    protected function membershipOf(RestaurantUser $user, RestaurantOrganization $organization): RestaurantMembership
    {
        return RestaurantMembership::query()->where('restaurant_user_id', $user->id)->where('organization_id', $organization->id)->firstOrFail();
    }
}
