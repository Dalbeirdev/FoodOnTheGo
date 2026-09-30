<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CityStatus;
use App\Enums\CoverageStatus;
use App\Enums\Permission;
use App\Enums\RegionStatus;
use App\Enums\RegionType;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\AvailabilityResource;
use App\Http\Resources\Geo\CityResource;
use App\Http\Resources\Geo\RegionResource;
use App\Http\Resources\Geo\RouteCorridorResource;
use App\Http\Resources\Geo\ServiceAreaResource;
use App\Http\Support\ListQuery;
use App\Models\City;
use App\Models\Market;
use App\Models\MarketRegion;
use App\Models\RouteCorridor;
use App\Models\ServiceArea;
use App\Services\Geo\RouteCorridorQuery;
use App\Services\Market\GeographyAdminService;
use App\Services\Market\MarketAvailabilityService;
use App\Services\Market\MarketLocationResolver;
use App\Support\Geo\GeoJsonGeometry;
use App\Support\Geo\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regions, cities, service areas and route corridors of a market. Lists are nested under the market; a
 * single record is addressed by its own id and authorised through the market it belongs to. Records are
 * never deleted — they are taken out of service with a status (DISABLED / UNAVAILABLE), which keeps history.
 */
class GeographyController extends Controller
{
    use AuthorizesMarketScope;

    private const VERSION = ['required', 'integer', 'min:1'];

    private const REASON = ['sometimes', 'nullable', 'string', 'max:500'];

    public function __construct(private readonly GeographyAdminService $geography) {}

    // ───────────────────────────── Regions ─────────────────────────────

    public function regions(Request $request, Market $market): AnonymousResourceCollection
    {
        $this->authorizeMarket(Permission::AdminMarketsView, $market);
        $list = new ListQuery($request, filterable: ['status', 'type'], sortable: ['name', 'code', 'status'], defaultSort: 'name');

        return RegionResource::collection($list->paginate(MarketRegion::query()->where('market_id', $market->getKey())));
    }

    public function storeRegion(Request $request, Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketsManage, $market);

        $input = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{2,12}$/'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(RegionType::class)],
        ]);

        return (new RegionResource($this->geography->createRegion($market, $input, $request->user())))->response()->setStatusCode(201);
    }

    public function updateRegion(Request $request, MarketRegion $region): RegionResource
    {
        $market = $this->marketOf($region, Permission::AdminMarketsManage);

        $input = $request->validate([
            'version' => self::VERSION,
            'reason' => self::REASON,
            'status' => ['sometimes', Rule::enum(RegionStatus::class)],
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::enum(RegionType::class)],
        ]);

        return new RegionResource($this->geography->updateRegion($region, $market, $input, $request->user()));
    }

    // ───────────────────────────── Cities ─────────────────────────────

    public function cities(Request $request, Market $market): AnonymousResourceCollection
    {
        $this->authorizeMarket(Permission::AdminCitiesView, $market);
        $list = new ListQuery($request, filterable: ['status'], sortable: ['name', 'status', 'created_at'], defaultSort: 'name');

        return CityResource::collection($list->paginate(City::query()->with('region')->where('cities.market_id', $market->getKey())));
    }

    public function storeCity(Request $request, Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminCitiesManage, $market);

        $input = $request->validate([
            'region_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'aliases' => ['sometimes', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'timezone' => ['sometimes', 'timezone:all'],
            'launch_stage' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $region = MarketRegion::query()->where('market_id', $market->getKey())->where('public_id', $input['region_id'])->first()
            ?? throw ValidationException::withMessages(['region_id' => ['The region does not belong to this market.']]);

        $city = $this->geography->createCity($market, $region, $input, $request->user());

        return (new CityResource($city->load('region')))->response()->setStatusCode(201);
    }

    public function showCity(City $city): CityResource
    {
        $this->marketOf($city, Permission::AdminCitiesView);

        return new CityResource($city->load('region'));
    }

    public function updateCity(Request $request, City $city): CityResource
    {
        $market = $this->marketOf($city, Permission::AdminCitiesManage);

        $input = $request->validate([
            'version' => self::VERSION,
            'reason' => self::REASON,
            'status' => ['sometimes', Rule::enum(CityStatus::class)],
            'name' => ['sometimes', 'string', 'max:120'],
            'aliases' => ['sometimes', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:120'],
            'latitude' => ['sometimes', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'timezone' => ['sometimes', 'timezone:all'],
            'launch_stage' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return new CityResource($this->geography->updateCity($city, $market, $input, $request->user())->load('region'));
    }

    // ───────────────────────────── Service areas ─────────────────────────────

    public function serviceAreas(Request $request, Market $market): AnonymousResourceCollection
    {
        $this->authorizeMarket(Permission::AdminServiceAreasView, $market);
        $list = new ListQuery($request, filterable: ['status'], sortable: ['name', 'status', 'priority', 'created_at'], defaultSort: 'name');

        return ServiceAreaResource::collection($list->paginate(ServiceArea::query()->with('city')->where('service_areas.market_id', $market->getKey())));
    }

    public function storeServiceArea(Request $request, Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminServiceAreasManage, $market);

        $input = $request->validate([
            'city_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'geometry' => ['required', 'array'],
            'priority' => ['sometimes', 'integer', 'between:-1000,1000'],
            'launch_stage' => ['sometimes', 'nullable', 'string', 'max:120'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
        ]);

        $city = City::query()->where('cities.market_id', $market->getKey())->where('cities.public_id', $input['city_id'])->first()
            ?? throw ValidationException::withMessages(['city_id' => ['The city does not belong to this market.']]);
        $geometry = GeoJsonGeometry::fromInput($request->input('geometry'), ['Polygon', 'MultiPolygon']);

        $area = $this->geography->createServiceArea($market, $city, $input, $geometry, $request->user());

        return (new ServiceAreaResource($this->serviceArea($area)))->response()->setStatusCode(201);
    }

    public function showServiceArea(ServiceArea $serviceArea): ServiceAreaResource
    {
        $this->marketOf($serviceArea, Permission::AdminServiceAreasView);

        return new ServiceAreaResource($this->serviceArea($serviceArea));
    }

    public function updateServiceArea(Request $request, ServiceArea $serviceArea): ServiceAreaResource
    {
        $market = $this->marketOf($serviceArea, Permission::AdminServiceAreasManage);

        $input = $request->validate([
            'version' => self::VERSION,
            'reason' => self::REASON,
            'status' => ['sometimes', Rule::enum(CoverageStatus::class)],
            'name' => ['sometimes', 'string', 'max:160'],
            'geometry' => ['sometimes', 'array'],
            'priority' => ['sometimes', 'integer', 'between:-1000,1000'],
            'launch_stage' => ['sometimes', 'nullable', 'string', 'max:120'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
        ]);
        $geometry = $request->has('geometry') ? GeoJsonGeometry::fromInput($request->input('geometry'), ['Polygon', 'MultiPolygon']) : null;

        return new ServiceAreaResource($this->serviceArea($this->geography->updateServiceArea($serviceArea, $market, $input, $geometry, $request->user())));
    }

    // ───────────────────────────── Route corridors ─────────────────────────────

    public function routeCorridors(Request $request, Market $market): AnonymousResourceCollection
    {
        $this->authorizeMarket(Permission::AdminServiceAreasView, $market);
        $list = new ListQuery($request, filterable: ['status'], sortable: ['name', 'status', 'created_at'], defaultSort: 'name');

        return RouteCorridorResource::collection($list->paginate(RouteCorridor::query()->with(['originCity', 'destinationCity'])->where('route_corridors.market_id', $market->getKey())));
    }

    public function storeRouteCorridor(Request $request, Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminServiceAreasManage, $market);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'highway' => ['sometimes', 'nullable', 'string', 'max:80'],
            'origin_city_id' => ['sometimes', 'nullable', 'uuid'],
            'destination_city_id' => ['sometimes', 'nullable', 'uuid'],
            'via_city_ids' => ['sometimes', 'array', 'max:30'],
            'via_city_ids.*' => ['uuid'],
            'geometry' => ['required', 'array'],
            'corridor_width_meters' => ['required', 'integer', 'between:100,100000'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
        ]);

        // City references are public ids of cities of this market; the endpoints are stored as internal keys.
        $referenced = array_values(array_filter([$input['origin_city_id'] ?? null, $input['destination_city_id'] ?? null, ...($input['via_city_ids'] ?? [])]));
        $known = City::query()->where('cities.market_id', $market->getKey())->whereIn('cities.public_id', $referenced)->pluck('cities.id', 'cities.public_id');
        if ($known->count() !== count(array_unique($referenced))) {
            throw ValidationException::withMessages(['origin_city_id' => ['Every referenced city must belong to this market.']]);
        }
        $input['origin_city_id'] = isset($input['origin_city_id']) ? $known[$input['origin_city_id']] : null;
        $input['destination_city_id'] = isset($input['destination_city_id']) ? $known[$input['destination_city_id']] : null;

        $geometry = GeoJsonGeometry::fromInput($request->input('geometry'), ['LineString']);
        $corridor = $this->geography->createRouteCorridor($market, $input, $geometry, $request->user());

        return (new RouteCorridorResource($this->routeCorridor($corridor)))->response()->setStatusCode(201);
    }

    public function showRouteCorridor(RouteCorridor $routeCorridor): RouteCorridorResource
    {
        $this->marketOf($routeCorridor, Permission::AdminServiceAreasView);

        return new RouteCorridorResource($this->routeCorridor($routeCorridor));
    }

    public function updateRouteCorridor(Request $request, RouteCorridor $routeCorridor): RouteCorridorResource
    {
        $market = $this->marketOf($routeCorridor, Permission::AdminServiceAreasManage);

        $input = $request->validate([
            'version' => self::VERSION,
            'reason' => self::REASON,
            'status' => ['sometimes', Rule::enum(CoverageStatus::class)],
            'name' => ['sometimes', 'string', 'max:160'],
            'highway' => ['sometimes', 'nullable', 'string', 'max:80'],
            'geometry' => ['sometimes', 'array'],
            'corridor_width_meters' => ['sometimes', 'integer', 'between:100,100000'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
        ]);
        $geometry = $request->has('geometry') ? GeoJsonGeometry::fromInput($request->input('geometry'), ['LineString']) : null;

        return new RouteCorridorResource($this->routeCorridor($this->geography->updateRouteCorridor($routeCorridor, $market, $input, $geometry, $request->user())));
    }

    // ───────────────────────────── Map and diagnostics ─────────────────────────────

    /**
     * Everything the coverage map draws, in every status, with geometry — one request.
     */
    public function map(Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminServiceAreasView, $market);

        return response()->json([
            'regions' => RegionResource::collection(MarketRegion::query()->where('market_id', $market->getKey())->orderBy('name')->get()),
            'cities' => CityResource::collection(City::query()->with('region')->where('cities.market_id', $market->getKey())->orderBy('cities.name')->get()),
            'service_areas' => ServiceAreaResource::collection(ServiceArea::query()->withGeometry()->with('city')->where('service_areas.market_id', $market->getKey())->orderBy('service_areas.name')->get()),
            'route_corridors' => RouteCorridorResource::collection(RouteCorridor::query()->withGeometry()->with(['originCity', 'destinationCity'])->where('route_corridors.market_id', $market->getKey())->orderBy('route_corridors.name')->get()),
        ]);
    }

    /**
     * Support diagnostic: the customer-facing answer for a point, plus what an administrator may know —
     * every covering area in any status, corridors, and the distance to the nearest live coverage.
     */
    public function checkAvailability(Request $request, Market $market, MarketAvailabilityService $availability, MarketLocationResolver $resolver, RouteCorridorQuery $corridors): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminServiceAreasView, $market);

        $location = Location::fromArray($request->validate(Location::rules()));
        $nearest = $resolver->nearestActiveServiceArea($location, $market);

        return response()->json([
            'availability' => new AvailabilityResource($availability->check($location)),
            'covering_service_areas' => $resolver->serviceAreas($location, $market)->map(fn (ServiceArea $a): array => [
                'id' => $a->public_id, 'name' => $a->name, 'status' => $a->status->value, 'priority' => (int) $a->priority, 'city' => $a->city->name, 'city_status' => $a->city->status->value,
            ])->all(),
            'corridors' => $corridors->containing($location, $market)->map(fn (RouteCorridor $c): array => [
                'id' => $c->public_id, 'name' => $c->name, 'highway' => $c->highway, 'distance_meters' => (int) round((float) $c->getAttribute('distance_m')),
            ])->all(),
            'nearest_active_service_area' => $nearest === null ? null : ['id' => $nearest['service_area']->public_id, 'name' => $nearest['service_area']->name, 'distance_meters' => (int) round($nearest['distance_m'])],
        ]);
    }

    /**
     * The market a record belongs to, after checking the caller holds the permission for that market.
     */
    private function marketOf(MarketRegion|City|ServiceArea|RouteCorridor $record, Permission $permission): Market
    {
        $market = Market::query()->findOrFail($record->market_id);
        $this->authorizeMarket($permission, $market);

        return $market;
    }

    private function serviceArea(ServiceArea $area): ServiceArea
    {
        return ServiceArea::query()->withGeometry()->with('city')->whereKey($area->getKey())->firstOrFail();
    }

    private function routeCorridor(RouteCorridor $corridor): RouteCorridor
    {
        return RouteCorridor::query()->withGeometry()->with(['originCity', 'destinationCity'])->whereKey($corridor->getKey())->firstOrFail();
    }
}
