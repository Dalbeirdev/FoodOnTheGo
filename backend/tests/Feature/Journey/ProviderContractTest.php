<?php

namespace Tests\Feature\Journey;

use App\Contracts\Journey\RoutingProvider;
use App\Enums\DetourStatus;
use App\Enums\ProviderFailure;
use App\Exceptions\ProviderException;
use App\Services\Journey\Data\PlaceSearchScope;
use App\Services\Journey\Data\RouteOptions;
use App\Services\Journey\Providers\FakeRoutingProvider;
use App\Services\Journey\Providers\GooglePlacesProvider;
use App\Services\Journey\Providers\GoogleRoutingProvider;
use App\Services\Journey\Providers\ProviderManager;
use App\Support\Geo\Location;
use App\Support\Geo\Polyline;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * The provider abstraction: the Google adapters against recorded response shapes (no live calls — live use is
 * PENDING PROVIDER CONFIGURATION), the development providers' rules, and the manager's refusal to run the fake
 * providers where they are not allowed.
 */
class ProviderContractTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    private const KEY = 'test-key-never-logged';

    /**
     * Registers stubs on a FRESH HTTP factory: stubs registered earlier would otherwise keep answering first.
     *
     * @param  array<string, mixed>  $stubs
     */
    private function fakeHttp(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    public function test_polyline_encoding_matches_the_reference_algorithm(): void
    {
        // Google's documented example, in [lng, lat] order.
        $positions = [[-120.2, 38.5], [-120.95, 40.7], [-126.453, 43.252]];
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', Polyline::encode($positions));
        $this->assertSame($positions, Polyline::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@'));

        $indian = [[77.3, 28.55], [77.35, 28.552], [77.4, 28.55]];
        $this->assertSame($indian, Polyline::decode(Polyline::encode($indian)));
    }

    public function test_google_places_search_is_restricted_to_the_market_country_and_details_resolve_coordinates(): void
    {
        $this->fakeHttp([
            'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
                ['id' => 'ChIJ-noida', 'displayName' => ['text' => 'Noida', 'languageCode' => 'en'], 'formattedAddress' => 'Noida, Uttar Pradesh, India', 'location' => ['latitude' => 28.5355, 'longitude' => 77.391],
                    'addressComponents' => [['longText' => 'Uttar Pradesh', 'shortText' => 'UP', 'types' => ['administrative_area_level_1']], ['longText' => 'India', 'shortText' => 'IN', 'types' => ['country']]], 'types' => ['locality', 'political']],
                ['id' => 'ChIJ-lahore', 'displayName' => ['text' => 'Lahore'], 'formattedAddress' => 'Lahore, Pakistan', 'location' => ['latitude' => 31.52, 'longitude' => 74.35],
                    'addressComponents' => [['longText' => 'Pakistan', 'shortText' => 'PK', 'types' => ['country']]], 'types' => ['locality']],
                ['id' => 'ChIJ-igi', 'displayName' => ['text' => 'Indira Gandhi International Airport'], 'formattedAddress' => 'New Delhi, Delhi 110037, India', 'location' => ['latitude' => 28.5562, 'longitude' => 77.1],
                    'addressComponents' => [['longText' => 'India', 'shortText' => 'IN', 'types' => ['country']], ['longText' => 'New Delhi', 'shortText' => 'New Delhi', 'types' => ['locality']]], 'types' => ['airport', 'point_of_interest']],
            ]]),
            'places.googleapis.com/v1/places/ChIJ-igi*' => Http::response(['id' => 'ChIJ-igi', 'displayName' => ['text' => 'Indira Gandhi International Airport'], 'formattedAddress' => 'New Delhi, Delhi 110037, India', 'location' => ['latitude' => 28.5562, 'longitude' => 77.1],
                'addressComponents' => [['longText' => 'India', 'shortText' => 'IN', 'types' => ['country']], ['longText' => 'Delhi', 'shortText' => 'DL', 'types' => ['administrative_area_level_1']], ['longText' => '110037', 'shortText' => '110037', 'types' => ['postal_code']]], 'types' => ['airport']]),
            'places.googleapis.com/v1/places/ChIJ-gone*' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404),
        ]);
        $provider = new GooglePlacesProvider(['key' => self::KEY, 'timeout' => 5]);
        $scope = new PlaceSearchScope('IN', 'en', null, 6, 'Asia/Kolkata');

        $results = $provider->search('noida', $scope);
        $this->assertSame(['ChIJ-noida', 'ChIJ-igi'], array_map(fn ($s) => $s->placeId, $results), 'the Pakistani result is filtered out: the market country is a hard boundary');
        $this->assertSame(['city', 'airport'], array_map(fn ($s) => $s->kind, $results));
        $this->assertSame('Uttar Pradesh', $results[0]->region);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'places:searchText')
            && $request->hasHeader('X-Goog-Api-Key', self::KEY) && $request->hasHeader('X-Goog-FieldMask')
            && $request['regionCode'] === 'in' && $request['textQuery'] === 'noida' && $request['languageCode'] === 'en');

        $place = $provider->resolve('ChIJ-igi', $scope);
        $this->assertSame(28.5562, $place->location->latitude);
        $this->assertSame(77.1, $place->location->longitude);
        $this->assertSame('IN', $place->location->countryCode);
        $this->assertSame('Delhi', $place->location->region);
        $this->assertSame('110037', $place->location->postalCode);
        $this->assertSame('Asia/Kolkata', $place->location->timezone, 'the time zone comes from the market scope, not from the provider');
        $this->assertSame('airport', $place->kind);

        try {
            $provider->resolve('ChIJ-gone', $scope);
            $this->fail('expected PLACE_NOT_FOUND');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::PlaceNotFound, $e->failure);
        }
        try {
            $provider->resolve('../../etc/passwd', $scope);
            $this->fail('expected PLACE_NOT_FOUND for an id that is not a place id');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::PlaceNotFound, $e->failure);
        }
    }

    public function test_google_http_outcomes_map_onto_provider_failures_without_leaking_the_key(): void
    {
        $provider = new GooglePlacesProvider(['key' => self::KEY, 'timeout' => 5]);
        $scope = new PlaceSearchScope('IN');
        $cases = [
            [Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429), ProviderFailure::RateLimited],
            [Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403), ProviderFailure::Misconfigured],
            [Http::response('', 503), ProviderFailure::Unavailable],
            [Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT']], 400), ProviderFailure::InvalidLocation],
            [Http::failedConnection('cURL error 28: Operation timed out'), ProviderFailure::Timeout],
        ];
        foreach ($cases as [$response, $expected]) {
            $this->fakeHttp(['places.googleapis.com/*' => $response]);
            try {
                $provider->search('delhi', $scope);
                $this->fail('expected '.$expected->value);
            } catch (ProviderException $e) {
                $this->assertSame($expected, $e->failure);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }

        try {
            (new GooglePlacesProvider(['key' => '', 'timeout' => 5]))->search('delhi', $scope);
            $this->fail('expected MISCONFIGURED');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::Misconfigured, $e->failure);
        }
    }

    public function test_google_routes_computes_the_base_route_and_detours_in_bounded_matrix_batches(): void
    {
        $positions = [[77.30, 28.55], [77.33, 28.552], [77.36, 28.549], [77.40, 28.55]];
        $this->fakeHttp([
            'routes.googleapis.com/directions/v2:computeRoutes' => Http::response(['routes' => [[
                'distanceMeters' => 12345, 'duration' => '1500s', 'polyline' => ['encodedPolyline' => Polyline::encode($positions)],
                'legs' => [['distanceMeters' => 12345, 'duration' => '1500s']], 'travelAdvisory' => ['tollInfo' => ['estimatedPrice' => []]], 'warnings' => ['This route includes a ferry.'],
            ]]]),
            'routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix' => function (Request $request) {
                $origins = count($request['origins']);
                $destinations = count($request['destinations']);
                $elements = [];
                for ($o = 0; $o < $origins; $o++) {
                    for ($d = 0; $d < $destinations; $d++) {
                        $index = $origins === 1 ? $d : $o;
                        $elements[] = $index === 3
                            ? ['originIndex' => $o, 'destinationIndex' => $d, 'status' => ['code' => 0], 'condition' => 'ROUTE_NOT_FOUND']
                            : ['originIndex' => $o, 'destinationIndex' => $d, 'status' => ['code' => 0], 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 7000 + $index, 'duration' => (800 + $index * 10).'s'];
                    }
                }

                return Http::response($elements);
            },
        ]);
        $provider = new GoogleRoutingProvider(['key' => self::KEY, 'timeout' => 8]);
        $origin = new Location(28.55, 77.30);
        $destination = new Location(28.55, 77.40);
        $departure = CarbonImmutable::now()->addHours(2);

        $plan = $provider->route($origin, $destination, new RouteOptions($departure, avoidTolls: true));
        $this->assertSame(12345, $plan->distanceMeters);
        $this->assertSame(1500, $plan->durationSeconds);
        $this->assertSame($positions, $plan->geometry->positions);
        $this->assertSame(['TOLLS', 'FERRY'], $plan->flags);
        $this->assertTrue($plan->trafficAware);
        $this->assertSame('google', $plan->provider);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'computeRoutes')
            && $request['travelMode'] === 'DRIVE' && $request['routingPreference'] === 'TRAFFIC_AWARE' && $request['departureTime'] === $departure->utc()->toRfc3339String()
            && $request['routeModifiers']['avoidTolls'] === true && $request['computeAlternativeRoutes'] === false && $request->hasHeader('X-Goog-FieldMask'));

        $waypoints = [];
        for ($i = 0; $i < 26; $i++) {
            $waypoints['r'.$i] = new Location(28.551, 77.301 + $i * 0.003);
        }
        $results = $provider->detours($plan, array_slice($waypoints, 0, 25, true), new RouteOptions);
        $this->assertCount(25, $results);
        $this->assertSame(DetourStatus::Ok, $results['r0']->status);
        $this->assertSame(800 + 800 - 1500, $results['r0']->detourSeconds);
        $this->assertSame(7000 + 7000 - 12345, $results['r0']->detourDistanceMeters);
        $this->assertSame(800, $results['r0']->legToWaypointSeconds);
        $this->assertSame(DetourStatus::NoRoute, $results['r3']->status, 'a waypoint without a route stays unreachable, it is not estimated');
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'computeRouteMatrix'))->count(), 'one batch = two matrix calls (origin → waypoints, waypoints → destination)');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'computeRouteMatrix') && count($request['origins']) === 1 && count($request['destinations']) === 25);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'computeRouteMatrix') && count($request['origins']) === 25 && count($request['destinations']) === 1);

        $this->fakeHttp(['routes.googleapis.com/directions/v2:computeRoutes' => Http::response('{}')]);
        try {
            $provider->route($origin, new Location(11.6234, 92.7265), new RouteOptions);
            $this->fail('expected ROUTE_NOT_FOUND');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::RouteNotFound, $e->failure);
        }
    }

    public function test_the_fake_routing_model_is_deterministic_and_honest_about_islands(): void
    {
        $fake = new FakeRoutingProvider;
        $plan = $fake->route(new Location(28.6139, 77.209), new Location(30.7333, 76.7794), new RouteOptions);
        $again = $fake->route(new Location(28.6139, 77.209), new Location(30.7333, 76.7794), new RouteOptions);

        $this->assertSame($plan->geometry->encodedPolyline, $again->geometry->encodedPolyline);
        $this->assertSame(4, count($plan->legs), 'Delhi → Chandigarh follows the known corridor via Panipat, Karnal and Ambala');
        $this->assertGreaterThan(230000, $plan->distanceMeters);
        $this->assertLessThan(320000, $plan->distanceMeters);
        $this->assertFalse($plan->trafficAware);
        $this->assertFalse($fake->supportsTrafficAwareDeparture());
        $this->assertEqualsWithDelta([77.209, 28.6139], $plan->geometry->positions[0], 1e-6);
        $this->assertEqualsWithDelta([76.7794, 30.7333], $plan->geometry->positions[count($plan->geometry->positions) - 1], 1e-6);

        try {
            $fake->route(new Location(13.0827, 80.2707), new Location(11.6234, 92.7265), new RouteOptions);
            $this->fail('expected ROUTE_NOT_FOUND');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::RouteNotFound, $e->failure);
        }

        $detours = $fake->detours($plan, ['karnal' => new Location(29.6857, 76.9905), 'island' => new Location(11.6234, 92.7265)], new RouteOptions);
        $this->assertSame(DetourStatus::Ok, $detours['karnal']->status);
        $this->assertLessThan(600, $detours['karnal']->detourSeconds, 'a town on the corridor costs almost no detour');
        $this->assertSame(DetourStatus::NoRoute, $detours['island']->status);
    }

    public function test_the_fake_providers_are_refused_where_they_are_not_allowed(): void
    {
        config(['journey.providers.allow_fake' => false]);
        $this->app->forgetInstance(ProviderManager::class);

        try {
            app(RoutingProvider::class);
            $this->fail('expected MISCONFIGURED');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::Misconfigured, $e->failure);
        }

        $this->setUpRestaurantWorld();
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertStatus(503)->assertJsonPath('error.code', 'routing_temporarily_unavailable');
        $this->getJson('/api/v1/places/search?q=delhi')->assertStatus(503)->assertJsonPath('error.code', 'places_temporarily_unavailable');

        $report = app(ProviderManager::class)->report();
        $this->assertFalse($report[0]['configured']);
        $this->assertStringContainsString('not allowed', $report[0]['missing'][0]);

        config(['services.routing.driver' => 'google', 'services.routing.key' => '', 'services.places.driver' => 'google']);
        $this->app->forgetInstance(ProviderManager::class);
        $report = app(ProviderManager::class)->report();
        $this->assertSame(['live', 'live'], array_column($report, 'mode'));
        $this->assertSame(['PLACES_API_KEY'], $report[0]['missing']);
    }
}
