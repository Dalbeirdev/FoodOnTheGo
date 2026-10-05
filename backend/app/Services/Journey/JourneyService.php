<?php

namespace App\Services\Journey;

use App\Enums\AvailabilityReason;
use App\Enums\RouteCoverage;
use App\Exceptions\ApiException;
use App\Exceptions\ProviderException;
use App\Models\Customer;
use App\Models\Journey;
use App\Models\Market;
use App\Models\RouteCorridor;
use App\Services\Customer\RecentLocationService;
use App\Services\Journey\Data\RouteGeometry;
use App\Services\Journey\Data\RouteOptions;
use App\Services\Journey\Data\RoutePlan;
use App\Services\Journey\Providers\FakePlacesProvider;
use App\Services\Journey\Providers\ProviderManager;
use App\Services\Market\Availability;
use App\Services\Market\JourneyCoverage;
use App\Services\Market\MarketContext;
use App\Support\Geo\Geo;
use App\Support\Geo\GeoJsonGeometry;
use App\Support\Geo\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Plans journeys (Module 26 pipeline, steps 1–7): validates both ends, resolves the market, gets the base
 * route from the routing provider (cached under a privacy-safe hash), normalizes and validates the geometry
 * in PostGIS, decides the coverage along the route and stores an EXPIRING journey context owned by the
 * customer or by the holder of a guest token.
 *
 * Nothing about a journey is logged beyond identifiers, the market, the provider's outcome, timings and counts.
 */
final class JourneyService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly ProviderCall $call,
        private readonly JourneyEndpointResolver $endpoints,
        private readonly JourneyCoverage $coverage,
        private readonly JourneyPolicy $policy,
        private readonly MarketContext $markets,
        private readonly RecentLocationService $recent,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request
     * @return array{journey: Journey, guest_token: string|null}
     */
    public function plan(array $input, ?Customer $customer): array
    {
        $started = hrtime(true);
        $requestMarket = $this->requestMarket($input['country'] ?? null);

        $origin = $this->endpoints->resolve((array) $input['origin'], $requestMarket, $customer, 'origin');
        $destination = $this->endpoints->resolve((array) $input['destination'], $requestMarket, $customer, 'destination');
        $this->assertDistinct($origin, $destination);
        $departure = $this->departure($input['departure_at'] ?? null);

        $ends = $this->coverage->endpoints($origin->location, $destination->location);
        $market = $this->marketOf($ends);
        $policy = $this->policy->forMarket($market);
        $options = RouteOptions::fromPreferences((array) ($input['preferences'] ?? []), $departure, strtolower(explode('-', (string) ($market->default_locale ?: 'en'))[0]) ?: 'en');

        [$plan, $providerMode, $routeCached, $providerMs] = $this->route($origin->location, $destination->location, $options);
        $geometry = $this->geometry($plan);
        $corridor = $this->policy->clampCorridor((int) ($input['corridor_width_meters'] ?? $policy['corridor_width_meters']));
        $coverage = $this->coverageAlong($geometry['search_json'], $market, $ends, $corridor, $departure ?? CarbonImmutable::now());

        $token = $customer === null ? bin2hex(random_bytes(32)) : null;
        $journey = (new Journey)->forceFill([
            'customer_id' => $customer?->getKey(),
            'guest_token_hash' => $token === null ? null : hash('sha256', $token),
            'market_id' => $market->getKey(),
            'origin' => $origin->toStored(),
            'destination' => $destination->toStored(),
            'departure_at' => $departure?->utc(),
            'departure_given' => $departure !== null,
            'route_hash' => $this->routeHash($plan->provider, $origin->location, $destination->location, $options),
            'distance_meters' => $plan->distanceMeters,
            'duration_seconds' => $plan->durationSeconds,
            'encoded_polyline' => $geometry['encoded_polyline'],
            'bounds' => $plan->bounds(),
            'legs' => $plan->legs,
            'flags' => $plan->flags,
            'warnings' => $plan->warnings,
            'provider' => $plan->provider,
            'provider_mode' => $providerMode,
            'traffic_aware' => $plan->trafficAware,
            'route_calculated_at' => $plan->calculatedAt->utc(),
            'preferences' => $options->preferences(),
            'coverage' => $coverage,
            'corridor_width_meters' => $corridor,
            'route_points' => $geometry['route_points'],
            'search_points' => $geometry['search_points'],
            'provider_latency_ms' => $providerMs,
            'expires_at' => now()->addMinutes((int) config('journey.ttl_minutes')),
        ])->insertWithSpatial([
            'origin_point' => ['ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography', [$origin->location->longitude, $origin->location->latitude]],
            'destination_point' => ['ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography', [$destination->location->longitude, $destination->location->latitude]],
            'route_geometry' => ['ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)', [$geometry['route_json']]],
            'search_geometry' => ['ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)', [$geometry['search_json']]],
        ]);

        $this->rememberRecent($customer, $origin, $destination);

        Log::info('journey.planned', [
            'request_id' => Context::get('request_id'), 'journey' => $journey->public_id, 'owner' => $customer === null ? 'guest' : 'customer', 'market' => $market->country_code,
            'provider' => $plan->provider, 'provider_mode' => $providerMode, 'route_cached' => $routeCached, 'provider_ms' => $providerMs,
            'distance_m' => $plan->distanceMeters, 'duration_s' => $plan->durationSeconds, 'route_points' => $geometry['route_points'], 'search_points' => $geometry['search_points'],
            'coverage' => $coverage['along_route'], 'corridor_m' => $corridor, 'departure_given' => $departure !== null, 'total_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);

        return ['journey' => $journey, 'guest_token' => $token];
    }

    /**
     * The journey for its owner: the signed-in customer who planned it, or whoever presents the guest token it
     * was issued with. Anyone else gets "not found" — existence is never revealed. Expired journeys answer 410.
     */
    public function find(string $publicId, ?Customer $customer, ?string $guestToken): Journey
    {
        $journey = Journey::query()->where('journeys.public_id', $publicId)->first();

        if ($journey === null || ! ($journey->ownedBy($customer) || $journey->acceptsGuestToken($guestToken))) {
            throw ApiException::notFound('journey_not_found', 'This journey was not found or has ended.');
        }
        if ($journey->isExpired()) {
            throw new ApiException(410, 'journey_expired', 'This journey has expired. Plan it again to continue.', ['expired_at' => $journey->expires_at?->utc()->toIso8601String()]);
        }

        return $journey;
    }

    /**
     * Removes journeys expired longer ago than the grace period. Their cached results die with their TTL.
     */
    public function prune(): int
    {
        return Journey::query()->expired(now()->subMinutes((int) config('journey.prune_grace_minutes')))->delete();
    }

    /**
     * The market a request is addressed to (for place resolution): by country code, whatever its status — a paused
     * market must answer "paused", not "not found". Only a country FoodOnTheGo has never heard of is a 404.
     */
    private function requestMarket(?string $countryCode): Market
    {
        $countryCode ??= (string) config('market.default_country');

        return Market::query()->where('country_code', $countryCode)->first()
            ?? throw ApiException::notFound('market_unavailable', 'FoodOnTheGo is not available in this country yet.');
    }

    private function assertDistinct(JourneyEndpoint $origin, JourneyEndpoint $destination): void
    {
        $samePlace = $origin->placeId !== null && $origin->placeId === $destination->placeId && $origin->placeProvider === $destination->placeProvider;
        $distance = Geo::distanceMeters($origin->location->latitude, $origin->location->longitude, $destination->location->latitude, $destination->location->longitude);

        if ($samePlace || $distance < (int) config('journey.geometry.same_point_meters')) {
            throw ValidationException::withMessages(['destination' => ['The destination must be a different place from the starting point.']]);
        }
    }

    /**
     * ISO 8601 with an explicit offset (or Z). Absent = now. Slightly in the past (clock skew) = now; further in
     * the past or too far ahead is refused.
     */
    private function departure(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value)) !== 1) {
            throw ValidationException::withMessages(['departure_at' => ['Give the departure time with its time zone offset, e.g. 2026-10-05T09:00:00+05:30.']]);
        }
        try {
            $at = CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            throw ValidationException::withMessages(['departure_at' => ['The departure time is not a valid ISO 8601 date-time.']]);
        }
        $now = CarbonImmutable::now();
        if ($at->lessThan($now->subMinutes((int) config('journey.departure.past_tolerance_minutes')))) {
            throw ValidationException::withMessages(['departure_at' => ['The departure time is in the past.']]);
        }
        $maxDays = (int) config('journey.departure.max_days_ahead');
        if ($at->greaterThan($now->addDays($maxDays))) {
            throw ValidationException::withMessages(['departure_at' => ["The departure time can be at most {$maxDays} days ahead."]]);
        }

        return $at->lessThan($now) ? $now : $at;
    }

    /**
     * Both ends must lie in one market that serves customers. A journey across two markets is not approved.
     *
     * @param  array{origin: Availability, destination: Availability, same_market: bool}  $ends
     */
    private function marketOf(array $ends): Market
    {
        foreach (['origin', 'destination'] as $end) {
            $availability = $ends[$end];
            $market = $availability->market;
            if ($market === null || ! $market->status->isServingCustomers()) {
                throw new ApiException(422, 'market_unsupported', 'FoodOnTheGo is not available where this journey '.($end === 'origin' ? 'starts' : 'ends').' yet.', [
                    'end' => $end, 'reason' => $market === null ? AvailabilityReason::MarketUnsupported->value : ($availability->reason?->value ?? AvailabilityReason::MarketUnsupported->value),
                ]);
            }
        }
        if (! $ends['same_market']) {
            throw new ApiException(422, 'cross_border_unsupported', 'Journeys between two markets are not supported yet.', ['origin_market' => $ends['origin']->market->country_code, 'destination_market' => $ends['destination']->market->country_code]);
        }

        return $ends['origin']->market;
    }

    /**
     * The base route: from the short-lived route cache when the same rounded ends, preferences and departure
     * bucket were planned recently, otherwise from the provider (measured, retried for transient failures).
     *
     * @return array{0: RoutePlan, 1: string, 2: bool, 3: int} plan, provider mode, served from cache, provider ms
     */
    private function route(Location $origin, Location $destination, RouteOptions $options): array
    {
        try {
            $provider = $this->providers->routing();
            $mode = $provider->isFake() ? Journey::MODE_DEVELOPMENT : Journey::MODE_LIVE;
            $key = 'journey:route:'.$this->routeHash($provider->name(), $origin, $destination, $options);
            $cached = Cache::get($key);
            $known = is_array($cached) ? RoutePlan::fromCache($cached, $origin, $destination) : null;
            if ($known !== null) {
                return [$known, $mode, true, 0];
            }
            $started = hrtime(true);
            $plan = $this->call->run($provider->name(), 'routing.route', fn (): RoutePlan => $provider->route($origin, $destination, $options));
            $ms = (int) round((hrtime(true) - $started) / 1_000_000);
            Cache::put($key, $plan->toCache(), (int) config('journey.cache.route_seconds'));

            return [$plan, $mode, false, $ms];
        } catch (ProviderException $e) {
            if ($e->failure->value === 'MISCONFIGURED') {
                Log::error('journey.provider_misconfigured', ['provider' => $e->provider, 'request_id' => Context::get('request_id')]);
            }
            throw $e->toApi('routing');
        }
    }

    /**
     * Privacy-safe identity of a route request: rounded coordinates (~11 m), provider, preferences and the
     * five-minute departure bucket when the provider is traffic aware — hashed, never the places themselves.
     */
    private function routeHash(string $provider, Location $origin, Location $destination, RouteOptions $options): string
    {
        $bucket = 'static';
        if ($options->departureAt !== null && $this->providers->routing()->supportsTrafficAwareDeparture()) {
            $bucket = (string) (intdiv($options->departureAt->getTimestamp(), 300) * 300);
        }

        return hash('sha256', implode('|', [
            $provider, round($origin->latitude, 4), round($origin->longitude, 4), round($destination->latitude, 4), round($destination->longitude, 4),
            $bucket, json_encode($options->preferences()), RouteOptions::TRAVEL_MODE,
        ]));
    }

    /**
     * PostGIS is the authority on the geometry: a valid line with a positive length, bounded in size. Lines above
     * the point cap are simplified (topology preserving) for the corridor search; a line too detailed even for
     * display is stored simplified too. A provider answer PostGIS rejects is a provider failure, never patched.
     *
     * @return array{route_json: string, search_json: string, encoded_polyline: string, route_points: int, search_points: int, length_m: float}
     */
    private function geometry(RoutePlan $plan): array
    {
        $routeJson = $plan->geometry->toGeoJsonString();
        $maxPoints = max(2, (int) config('journey.geometry.max_points'));
        $toleranceDeg = max(1, (int) config('journey.geometry.simplify_tolerance_meters')) / 111320;

        $row = null;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $row = DB::selectOne(
                'select ST_IsValid(g) as valid, ST_NPoints(g) as points, ST_Length(g::geography) as length_m, ST_AsGeoJSON(s) as search_json, ST_NPoints(s) as search_points '
                .'from (select g, ST_SimplifyPreserveTopology(g, ?) as s from (select ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) as g) t) u',
                [$toleranceDeg, $routeJson],
            );
            if (! $row->valid || (int) $row->points < 2 || (float) $row->length_m <= 0) {
                Log::warning('journey.route_geometry_rejected', ['provider' => $plan->provider, 'points' => (int) $row->points, 'request_id' => Context::get('request_id')]);
                throw new ApiException(503, 'routing_temporarily_unavailable', 'Route planning returned an unusable route. Please try again shortly.', ['retry_after_seconds' => 15]);
            }
            if ((int) $row->search_points <= $maxPoints) {
                break;
            }
            $toleranceDeg *= 2;
        }
        if ((int) $row->search_points > $maxPoints) {
            throw new ApiException(422, 'route_too_complex', 'This route is too detailed to search along. Try a shorter journey.');
        }

        $displayJson = (int) $row->points <= (int) config('geo.max_positions') ? $routeJson : (string) $row->search_json;
        $encoded = $displayJson === $routeJson ? $plan->geometry->encodedPolyline : RouteGeometry::fromPositions((array) json_decode((string) $row->search_json, true)['coordinates'])->encodedPolyline;

        return [
            'route_json' => $displayJson,
            'search_json' => (string) $row->search_json,
            'encoded_polyline' => $encoded,
            'route_points' => min((int) $row->points, (int) config('geo.max_positions')),
            'search_points' => (int) $row->search_points,
            'length_m' => (float) $row->length_m,
        ];
    }

    /**
     * Coverage summary stored with the journey: the two ends' availability, whether any serviceable area lies
     * within the corridor of the route (NO_SERVICE_COVERAGE is an answer, not an error) and the operational
     * corridors of Module 22 the route runs through.
     *
     * @param  array{origin: Availability, destination: Availability, same_market: bool}  $ends
     * @return array<string, mixed>
     */
    private function coverageAlong(string $searchJson, Market $market, array $ends, int $corridor, CarbonImmutable $at): array
    {
        $instant = $at->utc()->format('Y-m-d H:i:sP');
        $areas = (int) DB::selectOne(
            'select count(*) as n from service_areas sa join cities c on c.id = sa.city_id join market_regions r on r.id = c.region_id '
            ."where sa.market_id = ? and sa.status = 'ACTIVE' and c.status in ('PILOT', 'ACTIVE') and r.status in ('PILOT', 'ACTIVE') "
            .'and (sa.effective_from is null or sa.effective_from <= ?) and (sa.effective_until is null or sa.effective_until > ?) '
            .'and ST_DWithin(sa.geometry::geography, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)::geography, ?)',
            [$market->getKey(), $instant, $instant, $searchJson, $corridor],
        )->n;

        $corridors = $this->coverage->corridorsAlong(GeoJsonGeometry::fromInput(json_decode($searchJson, true), ['LineString']), $ends['origin'])
            ->map(fn (RouteCorridor $c): array => ['id' => $c->public_id, 'name' => $c->name, 'highway' => $c->highway])->values()->all();

        return [
            'origin' => $this->endCoverage($ends['origin']),
            'destination' => $this->endCoverage($ends['destination']),
            'along_route' => ($areas > 0 ? RouteCoverage::Covered : RouteCoverage::NoServiceCoverage)->value,
            'serviceable_areas' => $areas,
            'corridor_width_meters' => $corridor,
            'corridors' => $corridors,
        ];
    }

    /**
     * @return array{supported: bool, reason: string|null, city: string|null, service_area: string|null}
     */
    private function endCoverage(Availability $availability): array
    {
        return ['supported' => $availability->supported, 'reason' => $availability->reason?->value, 'city' => $availability->city?->name, 'service_area' => $availability->serviceArea?->name];
    }

    /**
     * The PLACES a signed-in customer searched for and chose go to their recent places (Module 25: bounded,
     * deduplicated, clearable). A raw position — the device's "current location" or a pin — is used for this journey
     * and never kept as a recent place (no location trail); a saved place is already the customer's own.
     * Never fatal for planning; guests leave no trace beyond the expiring journey.
     */
    private function rememberRecent(?Customer $customer, JourneyEndpoint ...$ends): void
    {
        if ($customer === null || ! config('journey.record_recent_locations')) {
            return;
        }
        foreach ($ends as $end) {
            if ($end->source !== 'place') {
                continue;
            }
            if ($end->placeProvider !== FakePlacesProvider::NAME && ! config('journey.record_recent_live_places')) {
                continue; // provider content: not stored beyond the journey until its storage terms are confirmed
            }
            try {
                $this->recent->record($customer, [
                    'label' => $end->label, 'lat' => $end->location->latitude, 'lng' => $end->location->longitude, 'formatted_address' => $end->location->formattedAddress,
                    'place_provider' => $end->placeProvider, 'place_id' => $end->placeId, 'country_code' => $end->location->countryCode, 'timezone' => $end->location->timezone,
                ]);
            } catch (Throwable $e) {
                Log::warning('journey.recent_location_not_recorded', ['request_id' => Context::get('request_id'), 'error' => $e::class]);
            }
        }
    }
}
