<?php

namespace App\Services\Market;

use App\Enums\CityStatus;
use App\Enums\CoverageStatus;
use App\Enums\RegionStatus;
use App\Models\City;
use App\Models\Market;
use App\Models\MarketRegion;
use App\Models\RouteCorridor;
use App\Models\ServiceArea;
use Illuminate\Support\Facades\Cache;

/**
 * The customer-safe picture of where a market operates, for maps, city pickers and "coming soon" lists.
 * It is display data: whether a location can actually be served is always decided by
 * MarketAvailabilityService on the server.
 *
 * What is public:
 *   regions / cities   PILOT, ACTIVE, PAUSED and PLANNED (announced, "coming soon") — never DISABLED / UNAVAILABLE
 *   service areas      ACTIVE and in effect, plus PAUSED — never PLANNED, TESTING or DISABLED
 *   route corridors    ACTIVE and in effect only
 * Internal fields (launch stage, priority, versions, effective dates, audit data) are never included.
 */
final class MarketCoverage
{
    private const PUBLIC_REGION = [RegionStatus::Planned, RegionStatus::Pilot, RegionStatus::Active, RegionStatus::Paused];

    private const PUBLIC_CITY = [CityStatus::Planned, CityStatus::Pilot, CityStatus::Active, CityStatus::Paused];

    public function __construct(private readonly MarketContext $markets) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Market $market): array
    {
        return Cache::remember($this->markets->key('coverage:'.$market->getKey()), (int) config('geo.market_cache_seconds'), fn (): array => $this->build($market));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Market $market): array
    {
        $regions = MarketRegion::query()->where('market_id', $market->getKey())->whereIn('status', array_column(self::PUBLIC_REGION, 'value'))->orderBy('name')->get();
        $cities = City::query()->where('cities.market_id', $market->getKey())->whereIn('cities.region_id', $regions->modelKeys())
            ->whereIn('cities.status', array_column(self::PUBLIC_CITY, 'value'))->orderBy('cities.name')->get();
        $cityIds = $cities->pluck('public_id', 'id');
        $regionIds = $regions->pluck('public_id', 'id');

        $areas = ServiceArea::query()->withGeometry()->where('service_areas.market_id', $market->getKey())->whereIn('service_areas.city_id', $cities->modelKeys())
            ->where(fn ($q) => $q->where(fn ($active) => $active->inEffect())->orWhere('service_areas.status', CoverageStatus::Paused->value))
            ->orderBy('service_areas.name')->get();
        $corridors = RouteCorridor::query()->withGeometry()->inEffect()->where('route_corridors.market_id', $market->getKey())->orderBy('route_corridors.name')->get();

        return [
            'market_id' => $market->public_id,
            'country_code' => $market->country_code,
            'regions' => $regions->map(fn (MarketRegion $r): array => ['id' => $r->public_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type->value, 'status' => $r->status->value])->all(),
            'cities' => $cities->map(fn (City $c): array => [
                'id' => $c->public_id, 'region_id' => $regionIds[$c->region_id], 'name' => $c->name, 'slug' => $c->slug, 'aliases' => $c->aliases ?? [],
                'latitude' => $c->latitude, 'longitude' => $c->longitude, 'timezone' => $c->timezone, 'status' => $c->status->value,
            ])->all(),
            'service_areas' => $areas->map(fn (ServiceArea $a): array => [
                'id' => $a->public_id, 'city_id' => $cityIds[$a->city_id], 'name' => $a->name, 'slug' => $a->slug, 'status' => $a->status->value,
                'geometry' => json_decode((string) $a->geojson, true),
            ])->all(),
            'route_corridors' => $corridors->map(fn (RouteCorridor $r): array => [
                'id' => $r->public_id, 'name' => $r->name, 'slug' => $r->slug, 'highway' => $r->highway, 'status' => $r->status->value,
                'origin_city_id' => $cityIds[$r->origin_city_id] ?? null, 'destination_city_id' => $cityIds[$r->destination_city_id] ?? null,
                'via_city_ids' => array_values(array_intersect($r->via_city_ids ?? [], $cityIds->all())),
                'corridor_width_meters' => (int) $r->corridor_width_meters, 'length_meters' => (int) round($r->length_m),
                'geometry' => json_decode((string) $r->geojson, true),
            ])->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
