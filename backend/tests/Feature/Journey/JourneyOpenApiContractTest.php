<?php

namespace Tests\Feature\Journey;

use App\Enums\Permission;
use App\Enums\ProviderFailure;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsJourneys;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every journey endpoint (Module 26) answers with the shape documented in openapi/openapi.json — successes and
 * every documented error. A property that is not documented fails the test.
 */
class JourneyOpenApiContractTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase, ValidatesOpenApi;

    public function test_places_journeys_discovery_and_provider_health_match_their_documented_schemas(): void
    {
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
        $this->eligible('Along A', 28.552, 77.33, cuisines: ['burgers']);
        $this->eligible('Evening Only', 28.552, 77.36, hours: [[null, '18:00', '22:00']]);
        $this->corridor($this->market, [[77.25, 28.55], [77.45, 28.55]], name: 'Noida East–West', attributes: ['highway' => 'NH24']);

        // ── Places
        $this->assertMatchesSchema('PlaceSearchResult', $this->getJson('/api/v1/places/search?q=noida')->assertOk()->assertJsonCount(3, 'data')->json());
        $this->assertMatchesSchema('PlaceSearchResult', $this->getJson('/api/v1/places/search?q=nowhere')->assertOk()->assertJsonCount(0, 'data')->json());
        $this->assertMatchesSchema('ResolvedPlace', $this->postJson('/api/v1/places/resolve', ['place_id' => 'fake:noida-18'])->assertOk()->assertJsonPath('coverage.supported', true)->json());
        $this->assertMatchesSchema('ResolvedPlace', $this->postJson('/api/v1/places/resolve', ['place_id' => 'fake:jaipur'])->assertOk()->assertJsonPath('coverage.supported', false)->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/places/resolve', ['place_id' => 'fake:atlantis'])->assertNotFound()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/places/search')->assertUnprocessable()->json());
        $this->fakePlaces()->failNext(ProviderFailure::RateLimited);
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/places/search?q=agra')->assertStatus(503)->json());

        // ── Planning: guest (token once), customer, place ids, a departure, a route without coverage
        $guest = $this->postJson('/api/v1/journeys/plan', $this->planBody() + ['departure_at' => '2026-10-05T17:55:00+05:30', 'preferences' => ['avoid_tolls' => true]])->assertCreated();
        $this->assertMatchesSchema('PlannedJourney', $guest->json());
        $this->assertNotEmpty($guest->json('coverage.corridors'));
        $token = ['X-Journey-Token' => $guest->json('guest_token')];
        $this->assertMatchesSchema('PlannedJourney', $this->postJson('/api/v1/journeys/plan', ['origin' => ['place_id' => 'fake:noida-18'], 'destination' => ['place_id' => 'fake:cp-delhi']])->assertCreated()->json());
        $uncovered = $this->postJson('/api/v1/journeys/plan', ['origin' => ['lat' => 26.0, 'lng' => 76.0], 'destination' => ['lat' => 26.1, 'lng' => 76.1]])->assertCreated();
        $this->assertMatchesSchema('PlannedJourney', $uncovered->assertJsonPath('coverage.along_route', 'NO_SERVICE_COVERAGE')->json());

        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/journeys/plan', ['origin' => ['lat' => 95, 'lng' => 77.3], 'destination' => ['lat' => 28.55, 'lng' => 77.4]])->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/journeys/plan', $this->planBody() + ['customer_id' => 1])->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 48.8566, 'lng' => 2.3522]]))->assertUnprocessable()->assertJsonPath('error.code', 'market_unsupported')->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 11.6234, 'lng' => 92.7265]]))->assertUnprocessable()->assertJsonPath('error.code', 'route_not_found')->json());
        $this->fakeRouting()->failNext('route', ProviderFailure::Unavailable, 2);
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 28.57, 'lng' => 77.4]]))->assertStatus(503)->assertJsonPath('error.code', 'routing_temporarily_unavailable')->json());

        // ── Reading and discovery
        $id = $guest->json('id');
        $this->assertMatchesSchema('Journey', $this->getJson("/api/v1/journeys/{$id}", $token)->assertOk()->json());
        $page = $this->getJson("/api/v1/journeys/{$id}/restaurants?sort=route_order&page[size]=1", $token)->assertOk()->assertJsonCount(1, 'data');
        $this->assertMatchesSchema('JourneyRestaurantPage', $page->json());
        $this->assertMatchesSchema('JourneyRestaurantPage', $this->getJson("/api/v1/journeys/{$id}/restaurants?sort=route_order&page[size]=1&page[number]=2", $token)->assertOk()->assertJsonPath('meta.cached', true)->json());
        $this->assertMatchesSchema('JourneyRestaurantPage', $this->getJson("/api/v1/journeys/{$id}/restaurants?cuisine=pizza&pickup_method=COUNTER&q=zzz&open_at_arrival=true&max_detour_minutes=5&corridor_width_meters=2000", $token)->assertOk()->assertJsonPath('meta.empty_reason', 'FILTERS')->json());
        $this->assertMatchesSchema('JourneyRestaurantPage', $this->getJson('/api/v1/journeys/'.$uncovered->json('id').'/restaurants', ['X-Journey-Token' => $uncovered->json('guest_token')])->assertOk()->assertJsonPath('meta.empty_reason', 'NO_SERVICE_COVERAGE')->json());
        $this->assertMatchesSchema('Error', $this->getJson("/api/v1/journeys/{$id}/restaurants?sort=by_rating", $token)->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->getJson("/api/v1/journeys/{$id}")->assertNotFound()->assertJsonPath('error.code', 'journey_not_found')->json());

        // ── A customer's journey (no guest token in the answer)
        $this->actingAsPrincipal($this->customer($this->market));
        $owned = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->assertMatchesSchema('PlannedJourney', $owned->json());
        $this->assertArrayNotHasKey('guest_token', $owned->json());
        $this->assertMatchesSchema('Journey', $this->getJson('/api/v1/journeys/'.$owned->json('id'))->assertOk()->assertJsonPath('owner', 'customer')->json());

        // ── Provider health and the per-market journey policy (administrators)
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView, Permission::AdminMarketConfigurationView, Permission::AdminMarketConfigurationManage]));
        $this->assertMatchesSchema('ProviderHealthReport', $this->getJson('/api/v1/admin/system/providers')->assertOk()->json());
        $configuration = '/api/v1/admin/markets/'.$this->market->public_id.'/configuration';
        $this->assertMatchesSchema('MarketConfiguration', $this->getJson($configuration)->assertOk()->json());
        $this->assertMatchesSchema('MarketConfiguration', $this->patchJson($configuration, ['version' => 1, 'reason' => 'Wider corridor on highways', 'journey' => ['corridor_width_meters' => 5000, 'max_detour_minutes' => 20]])->assertOk()->assertJsonPath('journey.corridor_width_meters', 5000)->json());
        $this->assertMatchesSchema('Error', $this->patchJson($configuration, ['version' => 2, 'reason' => 'Out of bounds', 'journey' => ['corridor_width_meters' => 50000]])->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->patchJson($configuration, ['version' => 2, 'reason' => 'Unknown key', 'journey' => ['api_key' => 'x']])->assertUnprocessable()->json());

        // ── Expiry
        $this->app['auth']->forgetGuards();
        $this->travel(31)->minutes();
        $this->assertMatchesSchema('Error', $this->getJson("/api/v1/journeys/{$id}", $token)->assertStatus(410)->assertJsonPath('error.code', 'journey_expired')->json());
    }

    public function test_every_documented_journey_error_status_is_a_documented_response(): void
    {
        $paths = $this->openApi()['paths'];

        foreach (['/places/search' => 'get', '/places/resolve' => 'post', '/journeys/plan' => 'post', '/journeys/{journey}' => 'get', '/journeys/{journey}/restaurants' => 'get'] as $path => $method) {
            $responses = array_map('strval', array_keys($paths[$path][$method]['responses']));
            $this->assertContains('429', $responses, "{$path} documents its rate limit");
            $this->assertSame([[], ['customer' => []]], json_decode(json_encode($paths[$path][$method]['security']), true), "{$path} is open to guests and customers");
        }
        foreach (['/journeys/{journey}', '/journeys/{journey}/restaurants'] as $path) {
            $this->assertContains('410', array_map('strval', array_keys($paths[$path]['get']['responses'])));
            $this->assertContains('404', array_map('strval', array_keys($paths[$path]['get']['responses'])));
            $this->assertSame(['#/components/parameters/JourneyId', '#/components/parameters/JourneyToken'], array_slice(array_column($paths[$path]['get']['parameters'], '$ref'), 0, 2));
        }
        foreach (['/places/search' => 'get', '/journeys/plan' => 'post', '/journeys/{journey}/restaurants' => 'get'] as $path => $method) {
            $this->assertContains('503', array_map('strval', array_keys($paths[$path][$method]['responses'])), "{$path} documents the provider outage");
        }
        $this->assertSame([['admin' => []]], $paths['/admin/system/providers']['get']['security']);
    }
}
