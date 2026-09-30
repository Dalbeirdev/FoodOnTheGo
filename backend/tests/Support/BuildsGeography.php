<?php

namespace Tests\Support;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\City;
use App\Models\Market;
use App\Models\MarketRegion;
use App\Models\RouteCorridor;
use App\Models\ServiceArea;
use App\Services\Rbac\RoleService;
use Illuminate\Support\Str;

/**
 * Small, explicit geography for tests: real rows in PostgreSQL / PostGIS, shapes simple enough to reason
 * about by hand (axis-aligned squares and straight lines).
 */
trait BuildsGeography
{
    protected function india(): Market
    {
        return Market::query()->where('country_code', 'IN')->first() ?? Market::factory()->india()->create();
    }

    protected function region(Market $market, string $code = 'IN-UP', string $status = 'ACTIVE', string $name = 'Uttar Pradesh'): MarketRegion
    {
        return tap((new MarketRegion)->forceFill(['market_id' => $market->id, 'code' => $code, 'name' => $name, 'type' => 'STATE', 'status' => $status]))->save();
    }

    protected function city(MarketRegion $region, string $name = 'Noida', float $lat = 28.5355, float $lng = 77.391, string $status = 'ACTIVE'): City
    {
        return (new City)->forceFill([
            'market_id' => $region->market_id, 'region_id' => $region->id, 'name' => $name, 'slug' => Str::slug($name), 'aliases' => [], 'timezone' => 'Asia/Kolkata', 'status' => $status,
        ])->insertWithSpatial(['center' => City::centerExpression($lat, $lng)]);
    }

    /**
     * A square service area: [west, south, east, north] in degrees.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @param  array<string, mixed>  $attributes
     */
    protected function area(City $city, array $box, string $status = 'ACTIVE', string $name = 'Area', array $attributes = []): ServiceArea
    {
        return (new ServiceArea)->forceFill([
            'market_id' => $city->market_id, 'city_id' => $city->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'status' => $status, ...$attributes,
        ])->insertWithSpatial(['geometry' => ServiceArea::geometryExpression((string) json_encode($this->square($box)))]);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $lngLat
     * @param  array<string, mixed>  $attributes
     */
    protected function corridor(Market $market, array $lngLat, string $status = 'ACTIVE', int $width = 5000, string $name = 'Corridor', array $attributes = []): RouteCorridor
    {
        return (new RouteCorridor)->forceFill([
            'market_id' => $market->id, 'via_city_ids' => [], 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'status' => $status, 'corridor_width_meters' => $width, ...$attributes,
        ])->insertWithSpatial(['centerline' => RouteCorridor::centerlineExpression((string) json_encode(['type' => 'LineString', 'coordinates' => $lngLat]))]);
    }

    /**
     * GeoJSON Polygon for a box [west, south, east, north].
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @return array{type: string, coordinates: list<list<array{0: float, 1: float}>>}
     */
    protected function square(array $box): array
    {
        [$w, $s, $e, $n] = $box;

        return ['type' => 'Polygon', 'coordinates' => [[[$w, $s], [$e, $s], [$e, $n], [$w, $n], [$w, $s]]]];
    }

    /**
     * An active administrator holding exactly these permissions, platform-wide or for one market.
     *
     * @param  list<Permission>  $permissions
     */
    protected function adminWith(array $permissions, ?Market $scope = null): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $roles = app(RoleService::class);
        $roles->assign($admin, $roles->define(PrincipalType::AdminUser, 'T_'.Str::upper(Str::random(8)), 'Test role', $permissions), $scope === null ? null : Scope::market($scope->public_id));

        return $admin;
    }
}
