<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Restaurant\PublicRestaurantResource;
use App\Http\Support\ListQuery;
use App\Models\Cuisine;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Services\Market\MarketContext;
use App\Services\Restaurant\RestaurantCatalog;
use App\Services\Restaurant\RestaurantVisibility;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restaurants as customers see them. Public (a customer browses before signing in) and rate limited.
 *
 * Every query here starts from RestaurantVisibility: an unapproved, suspended or out-of-coverage restaurant
 * is not in the list and its page is a 404 — the same 404 as a slug that never existed. A closed or paused
 * restaurant stays visible and says so in `availability`.
 *
 * Distances are straight lines from the point the client sends. Route distance, detour and "restaurants along
 * my journey" belong to route discovery, not to this endpoint.
 */
class RestaurantController extends Controller
{
    private const POINT = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';

    public function __construct(
        private readonly RestaurantVisibility $visibility,
        private readonly MarketContext $markets,
    ) {}

    /**
     * Filters: filter[city] (slug), filter[region] (code), filter[service_area] (slug), filter[cuisine] (codes,
     * any of), filter[feature] (codes, all of), filter[open_now]=true, filter[price_level].
     * ?q= searches names and cuisines. ?lat=&lng= adds the straight-line distance, sorts by it and allows
     * ?radius_meters=. Sort: name, distance.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $input = $request->validate([
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'lat' => ['required_with:lng,radius_meters', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat,radius_meters', 'numeric', 'between:-180,180'],
            'radius_meters' => ['sometimes', 'integer', 'between:100,200000'],
        ]);
        $market = $this->markets->current($input['country'] ?? null);
        $now = now();
        $near = isset($input['lat'], $input['lng']);

        $query = $this->visible($market, $now);

        if ($near) {
            $point = [(float) $input['lng'], (float) $input['lat']];
            $query->selectRaw('ST_Distance(restaurant_locations.location, '.self::POINT.') as distance', $point);
            if (isset($input['radius_meters'])) {
                $query->whereRaw('ST_DWithin(restaurant_locations.location, '.self::POINT.', ?)', [...$point, (int) $input['radius_meters']]);
            }
        } elseif (str_contains((string) $request->query('sort', ''), 'distance')) {
            throw ValidationException::withMessages(['sort' => ['Sorting by distance needs lat and lng.']]);
        }

        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            // Bound parameter; LIKE wildcards are escaped so the text can only match literally.
            $like = '%'.addcslashes($text, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('restaurant_locations.name', 'ilike', $like)
                ->orWhere('restaurant_locations.branch_label', 'ilike', $like)
                ->orWhereExists(fn ($cuisine) => $cuisine->selectRaw('1')->from('restaurant_location_cuisines as lc')->join('cuisines as c', 'c.id', '=', 'lc.cuisine_id')
                    ->whereColumn('lc.location_id', 'restaurant_locations.id')->where('c.name', 'ilike', $like)));
        }

        $list = new ListQuery(
            $request,
            filterable: ['price_level'],
            sortable: $near ? ['name', 'distance'] : ['name'],
            defaultSort: $near ? 'distance' : 'name',
            handlers: $this->filters($market, $now),
        );

        return PublicRestaurantResource::collection($list->paginate($query->with($this->relations())));
    }

    /**
     * One restaurant by its slug (unique within the market). ?lat=&lng= adds the straight-line distance.
     */
    public function show(Request $request, string $restaurantSlug): PublicRestaurantResource
    {
        $input = $request->validate([
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'lat' => ['required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'numeric', 'between:-180,180'],
        ]);
        $market = $this->markets->current($input['country'] ?? null);

        $query = $this->visible($market, now())->where('restaurant_locations.slug', $restaurantSlug);
        if (isset($input['lat'], $input['lng'])) {
            $query->selectRaw('ST_Distance(restaurant_locations.location, '.self::POINT.') as distance', [(float) $input['lng'], (float) $input['lat']]);
        }

        $location = $query->with($this->relations())->first()
            ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant is not available on FoodOnTheGo.');

        return new PublicRestaurantResource($location);
    }

    /**
     * The cuisine taxonomy with the number of restaurants a customer can currently see for each cuisine.
     * Cached: it changes only when restaurant or market data is saved, and every such write invalidates it.
     */
    public function cuisines(Request $request, RestaurantCatalog $catalog): JsonResponse
    {
        $input = $request->validate(['country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/']]);
        $market = $this->markets->current($input['country'] ?? null);

        return response()->json(['data' => $catalog->remember('cuisines:'.$market->getKey(), function () use ($market): array {
            $visible = RestaurantLocation::query()->withoutGlobalScope('coordinates')->where('restaurant_locations.market_id', $market->getKey())->select('restaurant_locations.id');
            $this->visibility->scopeVisible($visible);
            $counts = DB::table('restaurant_location_cuisines')->whereIn('location_id', $visible)->groupBy('cuisine_id')->selectRaw('cuisine_id, count(*) as restaurants')->pluck('restaurants', 'cuisine_id');

            return Cuisine::query()->active()->orderBy('display_order')->orderBy('name')->get()
                ->map(fn (Cuisine $c): array => ['code' => $c->code, 'name' => $c->name, 'slug' => $c->slug, 'restaurants' => (int) ($counts[$c->getKey()] ?? 0)])->all();
        })]);
    }

    /**
     * Locations of the market that customers may see, with whether their area is being served right now.
     *
     * @return Builder<RestaurantLocation>
     */
    private function visible(Market $market, CarbonInterface $now): Builder
    {
        $query = RestaurantLocation::query()->where('restaurant_locations.market_id', $market->getKey());
        $this->visibility->scopeVisible($query);
        $this->visibility->selectServiceable($query, $now);

        return $query;
    }

    /**
     * Everything the resource and the availability decision read — loaded once per page, never per row.
     * Special hours older than the day before yesterday can no longer affect anything.
     *
     * @return array<int|string, mixed>
     */
    private function relations(): array
    {
        return [
            'organization', 'market', 'city', 'region', 'cuisines', 'features', 'images', 'hours', 'pickupSettings', 'pickupMethods',
            'specialHours' => fn ($query) => $query->where('date', '>=', now()->subDays(2)->toDateString()),
            'specialHours.periods',
        ];
    }

    /**
     * Filters that are not a plain column. Values are bound parameters; unknown values simply match nothing.
     *
     * @return array<string, Closure(Builder<RestaurantLocation>, string): void>
     */
    private function filters(Market $market, CarbonInterface $now): array
    {
        $codes = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));

        return [
            'city' => fn (Builder $query, string $value) => $query->whereIn('restaurant_locations.city_id', DB::table('cities')->where('market_id', $market->getKey())->where('slug', $value)->select('id')),
            'region' => fn (Builder $query, string $value) => $query->whereIn('restaurant_locations.region_id', DB::table('market_regions')->where('market_id', $market->getKey())->where('code', $value)->select('id')),
            // By geometry, like every availability decision: the restaurants that lie inside that area.
            'service_area' => fn (Builder $query, string $value) => $query->whereRaw(
                'exists (select 1 from service_areas f where f.market_id = ? and f.slug = ? and ST_Covers(f.geometry, restaurant_locations.location::geometry))', [$market->getKey(), $value],
            ),
            'cuisine' => fn (Builder $query, string $value) => $query->whereExists(fn ($cuisine) => $cuisine->selectRaw('1')->from('restaurant_location_cuisines as fc')->join('cuisines as c', 'c.id', '=', 'fc.cuisine_id')
                ->whereColumn('fc.location_id', 'restaurant_locations.id')->whereIn('c.code', $codes($value))),
            'feature' => function (Builder $query, string $value) use ($codes): void {
                foreach ($codes($value) as $code) {
                    $query->whereExists(fn ($feature) => $feature->selectRaw('1')->from('restaurant_location_features as ff')->join('restaurant_features as f', 'f.id', '=', 'ff.feature_id')
                        ->whereColumn('ff.location_id', 'restaurant_locations.id')->where('f.code', $code));
                }
            },
            'open_now' => function (Builder $query, string $value) use ($now): void {
                if (! in_array($value, ['true', '1', 'false', '0'], true)) {
                    throw ValidationException::withMessages(['filter.open_now' => ['Use true or false.']]);
                }
                if (in_array($value, ['true', '1'], true)) {
                    $this->visibility->whereOpenAt($query, $now);
                }
            },
        ];
    }
}
