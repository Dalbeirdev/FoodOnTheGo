<?php

namespace Tests\Feature\Journey;

use App\Enums\ProviderFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * Places for the journey planner: GET /places/search and POST /places/resolve on the development provider.
 */
class PlacesApiTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
    }

    public function test_search_returns_places_of_the_market_country_only(): void
    {
        $response = $this->getJson('/api/v1/places/search?q=chand')->assertOk();

        $this->assertContains('Chandigarh', $response->json('data.*.name'));
        $this->assertSame(['IN'], array_values(array_unique($response->json('data.*.country_code'))));
        $this->assertSame('IN', $response->json('country_code'));
        $this->assertSame(['name' => 'fake', 'mode' => 'development'], $response->json('provider'));
        $this->assertSame('fake:chandigarh', $response->json('data.0.place_id'));
        $this->assertSame('city', $response->json('data.0.kind'));

        // Dubai exists in the development list but lies outside the market: a hard boundary, never a result.
        $this->getJson('/api/v1/places/search?q=dubai')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/places/search?q=nowhere')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/places/search?q=c')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/places/search')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->getJson('/api/v1/places/search?q=chand&country=FR')->assertNotFound()->assertJsonPath('error.code', 'market_unavailable');
    }

    public function test_searches_are_cached_briefly_per_normalized_query(): void
    {
        $this->getJson('/api/v1/places/search?q=Noida')->assertOk();
        $this->getJson('/api/v1/places/search?q=noida')->assertOk();
        $this->getJson('/api/v1/places/search?q=%20NOIDA%20')->assertOk();

        $this->assertSame(1, $this->fakePlaces()->searches);

        $this->getJson('/api/v1/places/search?q=delhi')->assertOk();
        $this->assertSame(2, $this->fakePlaces()->searches);
    }

    public function test_resolve_answers_coordinates_time_zone_and_coverage_for_the_place(): void
    {
        $response = $this->postJson('/api/v1/places/resolve', ['place_id' => 'fake:noida-18'])->assertOk();

        $this->assertSame('Sector 18, Noida', $response->json('place.name'));
        $this->assertSame(28.5708, $response->json('location.latitude'));
        $this->assertSame(77.3261, $response->json('location.longitude'));
        $this->assertSame('Asia/Kolkata', $response->json('location.timezone'));
        $this->assertSame('IN', $response->json('location.country_code'));
        $this->assertTrue($response->json('coverage.supported'));
        $this->assertSame('Noida', $response->json('coverage.city'));
        $this->assertSame('Noida Central', $response->json('coverage.service_area'));

        // A place the market does not serve is still resolved — coverage says so; it is not an error here.
        $this->postJson('/api/v1/places/resolve', ['place_id' => 'fake:jaipur'])->assertOk()->assertJsonPath('coverage.supported', false)->assertJsonPath('coverage.reason', 'OUTSIDE_SERVICE_AREA');

        $this->postJson('/api/v1/places/resolve', ['place_id' => 'fake:atlantis'])->assertNotFound()->assertJsonPath('error.code', 'place_not_found');
        $this->postJson('/api/v1/places/resolve', [])->assertUnprocessable();
    }

    public function test_provider_failures_are_reported_never_fabricated(): void
    {
        $this->fakePlaces()->failNext(ProviderFailure::Unavailable, 2);
        $this->getJson('/api/v1/places/search?q=chandigarh')->assertStatus(503)->assertJsonPath('error.code', 'places_temporarily_unavailable');

        $this->fakePlaces()->failNext(ProviderFailure::RateLimited);
        $this->postJson('/api/v1/places/resolve', ['place_id' => 'fake:delhi'])->assertStatus(503)->assertJsonPath('error.code', 'provider_rate_limited')->assertJsonPath('error.details.retry_after_seconds', 30);

        // A transient failure is retried once: the second attempt answers.
        $this->fakePlaces()->failNext(ProviderFailure::Timeout, 1);
        $this->getJson('/api/v1/places/search?q=ambala')->assertOk()->assertJsonPath('data.0.name', 'Ambala');
    }

    public function test_guests_are_rate_limited_tighter_than_customers(): void
    {
        config(['rate_limits.places_search_guest' => 2, 'rate_limits.places_search' => 4]);

        $this->getJson('/api/v1/places/search?q=karnal')->assertOk();
        $this->getJson('/api/v1/places/search?q=panipat')->assertOk();
        $this->getJson('/api/v1/places/search?q=agra')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        $this->actingAsPrincipal($this->customer($this->market));
        foreach (['karnal', 'panipat', 'agra', 'jaipur'] as $q) {
            $this->getJson('/api/v1/places/search?q='.$q)->assertOk();
        }
        $this->getJson('/api/v1/places/search?q=surat')->assertStatus(429);
    }
}
