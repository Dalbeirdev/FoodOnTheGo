<?php

namespace Tests\Feature\Journey;

use App\Enums\DistanceUnit;
use App\Enums\MarketStatus;
use App\Enums\ProviderFailure;
use App\Models\CustomerRecentLocation;
use App\Models\Journey;
use App\Models\Market;
use App\Models\MarketConfiguration;
use App\Services\Journey\JourneyEndpoint;
use App\Services\Journey\JourneyService;
use App\Services\Journey\ProviderMetrics;
use App\Support\Geo\Location;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * POST /journeys/plan: validation, market rules, the routing provider, geometry, coverage, caching, privacy.
 * The clock is Monday 2026-10-05 12:00 in India.
 */
class JourneyPlanApiTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    }

    public function test_a_guest_plans_a_journey_and_receives_the_guest_token_once(): void
    {
        $response = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();

        $this->assertSame('guest', $response->json('owner'));
        $this->assertSame('ACTIVE', $response->json('status'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('guest_token'));
        $this->assertSame('IN', $response->json('market'));
        $this->assertSame(['name' => 'fake', 'mode' => 'development'], $response->json('route.provider'));
        $this->assertFalse($response->json('route.traffic_aware'));
        $this->assertSame('LineString', $response->json('route.geometry.type'));
        $this->assertGreaterThanOrEqual(2, count($response->json('route.geometry.coordinates')));
        $this->assertNotEmpty($response->json('route.encoded_polyline'));
        $this->assertGreaterThan(9000, $response->json('route.distance_meters'));
        $this->assertLessThan(20000, $response->json('route.distance_meters'));
        $this->assertGreaterThan(0, $response->json('route.duration_seconds'));
        $this->assertFalse($response->json('departure.given'));
        $this->assertNull($response->json('departure.at'));
        $this->assertSame('COVERED', $response->json('coverage.along_route'));
        $this->assertTrue($response->json('coverage.origin.supported'));
        $this->assertSame(3000, $response->json('search.corridor_width_meters'));
        $this->assertSame(15, $response->json('search.max_detour_minutes'));
        $this->assertSame('West gate', $response->json('origin.label'));
        $this->assertSame(28.55, $response->json('origin.location.latitude'));
        $this->assertSame('Asia/Kolkata', $response->json('origin.timezone'));
        $this->assertSame('coordinates', $response->json('destination.source'));
        $this->assertSame(now()->addMinutes(30)->utc()->toIso8601String(), $response->json('expires_at'));

        $journey = Journey::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertNull($journey->customer_id);
        $this->assertSame(hash('sha256', $response->json('guest_token')), $journey->guest_token_hash);
        $this->assertSame('development', $journey->provider_mode);

        // Reading it back needs the token and never returns the token again.
        $read = $this->getJson('/api/v1/journeys/'.$response->json('id'), ['X-Journey-Token' => $response->json('guest_token')])->assertOk();
        $this->assertSame($response->json('id'), $read->json('id'));
        $this->assertArrayNotHasKey('guest_token', $read->json());
        $this->assertArrayNotHasKey('guest_token_hash', $read->json());
    }

    public function test_a_customer_owns_the_journey_and_only_searched_places_become_recent_places(): void
    {
        $rahul = $this->customer($this->market, ['phone_e164' => '+919876543210']);
        $this->actingAsPrincipal($rahul);
        $recent = fn (): array => CustomerRecentLocation::query()->where('customer_id', $rahul->getKey())->orderBy('label')->pluck('label')->all();

        $response = $this->postJson('/api/v1/journeys/plan', $this->planBody(['origin' => ['label' => 'Home gate']]))->assertCreated();

        $this->assertSame('customer', $response->json('owner'));
        $this->assertArrayNotHasKey('guest_token', $response->json());
        $this->assertSame($rahul->getKey(), Journey::query()->where('public_id', $response->json('id'))->value('customer_id'));
        $this->getJson('/api/v1/journeys/'.$response->json('id'))->assertOk()->assertJsonPath('origin.label', 'Home gate');

        // Raw positions (the device's "current location", a pin) are used for the journey and leave no location trail.
        $this->assertSame([], $recent());
        $this->postJson('/api/v1/journeys/plan', ['origin' => ['lat' => 28.6139, 'lng' => 77.209, 'label' => 'Current location'], 'destination' => ['place_id' => 'fake:noida-18']])->assertCreated();
        $this->assertSame(['Sector 18, Noida'], $recent());

        // A saved place is already the customer's own: it is not copied into the recent places either.
        $saved = $this->postJson('/api/v1/customer/saved-locations', ['kind' => 'WORK', 'label' => 'Office', 'formatted_address' => 'Tower B, Sector 62, Noida', 'country_code' => 'IN', 'lat' => 28.56, 'lng' => 77.33])->assertCreated()->json('id');
        $this->postJson('/api/v1/journeys/plan', ['origin' => ['saved_location_id' => $saved], 'destination' => ['place_id' => 'fake:cp-delhi']])->assertCreated();
        $this->assertSame(['Connaught Place', 'Sector 18, Noida'], $recent());

        // Choosing the same place again does not duplicate it.
        $this->postJson('/api/v1/journeys/plan', ['origin' => ['place_id' => 'fake:noida-18'], 'destination' => ['place_id' => 'fake:cp-delhi']])->assertCreated();
        $this->assertSame(['Connaught Place', 'Sector 18, Noida'], $recent());
        $this->assertSame(2, (int) CustomerRecentLocation::query()->where('customer_id', $rahul->getKey())->where('label', 'Sector 18, Noida')->value('times_used'));
    }

    public function test_places_of_a_live_provider_are_not_copied_into_recent_places_until_its_storage_terms_are_confirmed(): void
    {
        $customer = $this->customer($this->market);
        $this->actingAsPrincipal($customer);
        $recent = fn (): array => CustomerRecentLocation::query()->where('customer_id', $customer->getKey())->orderBy('label')->pluck('label')->all();

        // The development provider's places are development data: recorded like any other chosen place.
        $this->postJson('/api/v1/journeys/plan', ['origin' => ['place_id' => 'fake:noida-18'], 'destination' => ['place_id' => 'fake:cp-delhi']])->assertCreated();
        $this->assertSame(['Connaught Place', 'Sector 18, Noida'], $recent());

        // A place resolved by a live provider is provider content: it lives with the expiring journey only.
        $live = new JourneyEndpoint(new Location(28.5562, 77.1, 'IN', 'New Delhi, Delhi 110037, India'), 'Indira Gandhi International Airport', 'place', 'airport', 'google', 'ChIJ-igi');
        $own = new JourneyEndpoint(new Location(28.56, 77.39, 'IN'), 'Pinned location', 'coordinates', 'point');
        $remember = new ReflectionMethod(JourneyService::class, 'rememberRecent');

        $remember->invoke(app(JourneyService::class), $customer, $live, $own);
        $this->assertSame(['Connaught Place', 'Sector 18, Noida'], $recent());

        config(['journey.record_recent_live_places' => true]);
        $remember->invoke(app(JourneyService::class), $customer, $live);
        $this->assertContains('Indira Gandhi International Airport', $recent());

        config(['journey.record_recent_locations' => false]);
        CustomerRecentLocation::query()->where('customer_id', $customer->getKey())->delete();
        $this->postJson('/api/v1/journeys/plan', ['origin' => ['place_id' => 'fake:noida-18'], 'destination' => ['place_id' => 'fake:cp-delhi']])->assertCreated();
        $this->assertSame([], $recent());
    }

    public function test_place_ids_and_saved_places_are_resolved_on_the_server(): void
    {
        $response = $this->postJson('/api/v1/journeys/plan', ['origin' => ['place_id' => 'fake:noida-18'], 'destination' => ['place_id' => 'fake:cp-delhi']])->assertCreated();

        $this->assertSame('place', $response->json('origin.source'));
        $this->assertEquals(['provider' => 'fake', 'id' => 'fake:noida-18'], $response->json('origin.place'));
        $this->assertSame('Sector 18, Noida', $response->json('origin.label'));
        $this->assertSame(28.5708, $response->json('origin.location.latitude'));
        $this->assertSame('Connaught Place', $response->json('destination.label'));
        $this->assertSame('landmark', $response->json('destination.kind'));

        $customer = $this->customer($this->market);
        $this->actingAsPrincipal($customer);
        $saved = $this->postJson('/api/v1/customer/saved-locations', ['kind' => 'WORK', 'label' => 'Office', 'formatted_address' => 'Tower B, Sector 62, Noida', 'country_code' => 'IN', 'lat' => 28.56, 'lng' => 77.33])->assertCreated()->json('id');
        $planned = $this->postJson('/api/v1/journeys/plan', ['origin' => ['saved_location_id' => $saved], 'destination' => ['lat' => 28.55, 'lng' => 77.40]])->assertCreated();
        $this->assertSame('saved_location', $planned->json('origin.source'));
        $this->assertSame('Office', $planned->json('origin.label'));
        $this->assertSame(28.56, $planned->json('origin.location.latitude'));

        // A guest has no saved places; a saved place without a pin cannot start a journey.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/journeys/plan', ['origin' => ['saved_location_id' => $saved], 'destination' => ['lat' => 28.55, 'lng' => 77.40]])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->actingAsPrincipal($customer);
        $noPin = $this->postJson('/api/v1/customer/saved-locations', ['label' => 'Grandma', 'formatted_address' => '14, Model Town, Ludhiana'])->assertCreated()->json('id');
        $this->postJson('/api/v1/journeys/plan', ['origin' => ['saved_location_id' => $noPin], 'destination' => ['lat' => 28.55, 'lng' => 77.40]])->assertUnprocessable();
    }

    public function test_validation_refuses_ambiguous_ends_same_point_bad_coordinates_and_fields_a_client_may_never_set(): void
    {
        $fields = fn (array $body): array => array_keys($this->postJson('/api/v1/journeys/plan', $body)->assertUnprocessable()->json('error.details.fields'));

        $this->assertSame(['origin'], $fields($this->planBody(['origin' => ['place_id' => 'fake:delhi']])));          // two forms at once
        $this->assertSame(['origin'], $fields(['origin' => ['label' => 'nothing'], 'destination' => ['lat' => 28.55, 'lng' => 77.40]]));
        $this->assertSame(['destination'], $fields($this->planBody(['destination' => ['lat' => 28.55, 'lng' => 77.30]])));
        $this->assertSame(['destination'], $fields(['origin' => ['place_id' => 'fake:delhi'], 'destination' => ['place_id' => 'fake:delhi']]));
        $this->assertSame(['origin.lat'], $fields($this->planBody(['origin' => ['lat' => 95]])));
        $this->assertSame(['destination.lng'], $fields($this->planBody(['destination' => ['lng' => 190]])));
        $this->assertSame(['customer_id'], $fields($this->planBody() + ['customer_id' => 5]));
        $this->assertSame(['market_status', 'provider'], $fields($this->planBody() + ['market_status' => 'ACTIVE', 'provider' => 'google']));
        $this->assertSame(['expires_at', 'guest_token'], $fields($this->planBody() + ['expires_at' => '2030-01-01T00:00:00Z', 'guest_token' => 'abc']));
        $this->assertSame(['origin.label'], $fields($this->planBody(['origin' => ['label' => '<b>Home</b>']])));
        $this->assertSame(['corridor_width_meters'], $fields($this->planBody() + ['corridor_width_meters' => 50]));
        $this->assertSame(0, Journey::query()->count());
    }

    public function test_departure_time_needs_an_offset_and_stays_within_the_window(): void
    {
        $field = fn (string $value): string => $this->postJson('/api/v1/journeys/plan', $this->planBody() + ['departure_at' => $value])->assertUnprocessable()->json('error.details.fields.departure_at.0');

        $this->assertStringContainsString('offset', $field('2026-10-05T14:00:00'));
        $this->assertStringContainsString('past', $field('2026-10-05T10:00:00+05:30'));
        $this->assertStringContainsString('days ahead', $field('2026-10-25T10:00:00+05:30'));
        $this->assertStringContainsString('valid', $field('not-a-date+05:30'));

        $given = $this->postJson('/api/v1/journeys/plan', $this->planBody() + ['departure_at' => '2026-10-05T14:00:00+05:30'])->assertCreated();
        $this->assertTrue($given->json('departure.given'));
        $this->assertSame('2026-10-05T08:30:00+00:00', $given->json('departure.at'));

        // Two minutes ago (clock skew) means "now"; absent means now as well, decided at search time.
        $skew = $this->postJson('/api/v1/journeys/plan', $this->planBody() + ['departure_at' => now()->subMinutes(2)->toIso8601String()])->assertCreated();
        $this->assertSame(now()->utc()->toIso8601String(), $skew->json('departure.at'));
        $this->assertNull($this->postJson('/api/v1/journeys/plan', $this->planBody() + ['departure_at' => null])->assertCreated()->json('departure.at'));
    }

    public function test_both_ends_must_lie_in_one_market_that_serves_customers(): void
    {
        $paris = $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 48.8566, 'lng' => 2.3522, 'label' => 'Paris']]))->assertUnprocessable();
        $this->assertSame('market_unsupported', $paris->json('error.code'));
        $this->assertSame(['end' => 'destination', 'reason' => 'MARKET_UNSUPPORTED'], $paris->json('error.details'));

        $this->postJson('/api/v1/journeys/plan', $this->planBody(['origin' => ['lat' => 25.2048, 'lng' => 55.2708]]))->assertUnprocessable()->assertJsonPath('error.details.end', 'origin');

        $uae = Market::factory()->create(['country_code' => 'AE', 'slug' => 'uae', 'name' => 'United Arab Emirates', 'status' => MarketStatus::Active, 'default_currency' => 'AED', 'supported_currencies' => ['AED'], 'default_locale' => 'en-AE', 'supported_locales' => ['en-AE'], 'timezone_strategy' => 'single', 'default_timezone' => 'Asia/Dubai', 'distance_unit' => DistanceUnit::Metric, 'phone_country_code' => '+971', 'features' => []]);
        $uae->setBounds(51.0, 22.0, 57.0, 27.0);
        $crossBorder = $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 25.2048, 'lng' => 55.2708, 'label' => 'Dubai']]))->assertUnprocessable();
        $this->assertSame('cross_border_unsupported', $crossBorder->json('error.code'));
        $this->assertSame(['origin_market' => 'IN', 'destination_market' => 'AE'], $crossBorder->json('error.details'));

        $this->market->forceFill(['status' => MarketStatus::Paused])->save();
        $paused = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertUnprocessable();
        $this->assertSame('market_unsupported', $paused->json('error.code'));
        $this->assertSame('MARKET_PAUSED', $paused->json('error.details.reason'));
    }

    public function test_no_route_is_reported_and_never_replaced_by_a_straight_line(): void
    {
        $response = $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 11.6234, 'lng' => 92.7265, 'label' => 'Port Blair']]))->assertUnprocessable();

        $this->assertSame('route_not_found', $response->json('error.code'));
        $this->assertSame(0, Journey::query()->count());
    }

    public function test_provider_failures_are_mapped_and_only_transient_ones_are_retried(): void
    {
        $metrics = app(ProviderMetrics::class);

        $this->fakeRouting()->failNext('route', ProviderFailure::Timeout, 2);
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertStatus(503)->assertJsonPath('error.code', 'routing_temporarily_unavailable')->assertJsonPath('error.details.retry_after_seconds', 15);
        $health = $metrics->health('routing', 'fake');
        $this->assertSame(2, $health['calls']);
        $this->assertSame(2, $health['timeouts']);
        $this->assertSame('OUTAGE', $health['state']);

        $this->fakeRouting()->failNext('route', ProviderFailure::Timeout, 1);
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->assertSame(4, $metrics->health('routing', 'fake')['calls']);

        $this->fakeRouting()->failNext('route', ProviderFailure::RateLimited, 1);
        $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 28.56, 'lng' => 77.40]]))->assertStatus(503)->assertJsonPath('error.code', 'provider_rate_limited');
        $this->assertSame(5, $metrics->health('routing', 'fake')['calls'], 'a rate-limited call is not retried');
        $this->assertSame(1, $metrics->health('routing', 'fake')['rate_limited']);

        $this->fakeRouting()->failNext('route', ProviderFailure::Unavailable, 2);
        $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 28.57, 'lng' => 77.40]]))->assertStatus(503)->assertJsonPath('error.code', 'routing_temporarily_unavailable');
        $this->assertSame(1, Journey::query()->count(), 'only the successful plan was stored');
    }

    public function test_the_route_of_the_same_ends_is_cached_under_a_privacy_safe_hash(): void
    {
        $first = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $second = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->assertCount(1, array_filter($this->fakeRouting()->calls, fn (array $c): bool => $c['operation'] === 'route'));
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->json('route.encoded_polyline'), $second->json('route.encoded_polyline'));

        $this->postJson('/api/v1/journeys/plan', $this->planBody() + ['preferences' => ['avoid_tolls' => true]])->assertCreated()->assertJsonPath('route.preferences.avoid_tolls', true);
        $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 28.58]]))->assertCreated();
        $this->assertCount(3, array_filter($this->fakeRouting()->calls, fn (array $c): bool => $c['operation'] === 'route'));

        $hash = Journey::query()->where('public_id', $first->json('id'))->value('route_hash');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_a_route_without_any_serviceable_area_is_an_answer_with_zero_restaurants(): void
    {
        $this->eligible('Noida Kitchen', 28.552, 77.33);
        $plan = $this->planGuest(['origin' => ['lat' => 26.0, 'lng' => 76.0, 'label' => 'Rajasthan A'], 'destination' => ['lat' => 26.1, 'lng' => 76.1, 'label' => 'Rajasthan B']]);

        $this->assertSame('NO_SERVICE_COVERAGE', $plan['response']->json('coverage.along_route'));
        $this->assertSame(0, $plan['response']->json('coverage.serviceable_areas'));
        $this->assertFalse($plan['response']->json('coverage.origin.supported'));

        $restaurants = $this->restaurantsOf($plan['id'], $plan['headers'])->assertOk();
        $this->assertSame([], $restaurants->json('data'));
        $this->assertSame(0, $restaurants->json('meta.total'));
        $this->assertSame('NO_SERVICE_COVERAGE', $restaurants->json('meta.empty_reason'));
        $this->assertSame(0, $restaurants->json('meta.counts.detour_requested'), 'nothing is sent to the provider without candidates');
    }

    public function test_geometry_is_validated_and_bounded_in_postgis(): void
    {
        $response = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $journey = Journey::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertGreaterThanOrEqual(2, $journey->route_points);
        $this->assertLessThanOrEqual($journey->route_points, $journey->search_points);
        $this->assertSame($journey->route_points, count($response->json('route.geometry.coordinates')));

        config(['journey.geometry.max_points' => 4]);
        $bounded = Journey::query()->where('public_id', $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 28.56]]))->assertCreated()->json('id'))->firstOrFail();
        $this->assertLessThanOrEqual(4, $bounded->search_points);
        $this->assertGreaterThanOrEqual(2, $bounded->search_points);
    }

    public function test_the_market_configuration_may_set_the_corridor_width_within_the_platform_bounds(): void
    {
        (new MarketConfiguration)->forceFill(['market_id' => $this->market->getKey(), 'journey' => ['corridor_width_meters' => 5000, 'max_detour_minutes' => 20, 'candidate_cap' => 500]])->save();

        $response = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->assertSame(5000, $response->json('search.corridor_width_meters'));
        $this->assertSame(20, $response->json('search.max_detour_minutes'));
        $this->assertSame(100, $response->json('search.candidate_cap'), 'clamped to the platform maximum');

        $this->postJson('/api/v1/journeys/plan', $this->planBody() + ['corridor_width_meters' => 1500])->assertCreated()->assertJsonPath('search.corridor_width_meters', 1500);
    }

    public function test_logs_carry_identifiers_and_counts_but_never_places_or_geometry(): void
    {
        $events = [];
        Log::listen(function (MessageLogged $event) use (&$events): void {
            $events[] = $event;
        });

        $this->postJson('/api/v1/journeys/plan', $this->planBody(['origin' => ['lat' => 28.5512, 'lng' => 77.3012], 'destination' => ['lat' => 28.5588, 'lng' => 77.3988]]))->assertCreated();

        $planned = array_values(array_filter($events, fn (MessageLogged $e): bool => $e->message === 'journey.planned'));
        $this->assertCount(1, $planned);
        $context = $planned[0]->context;
        foreach (['journey', 'market', 'provider', 'provider_mode', 'route_cached', 'provider_ms', 'total_ms', 'coverage'] as $key) {
            $this->assertArrayHasKey($key, $context);
        }
        $serialized = json_encode(array_map(fn (MessageLogged $e): array => [$e->message, $e->context], $events));
        foreach (['28.5512', '77.3012', '28.5588', '77.3988', 'polyline', 'West gate', 'East gate', '"origin"', '"destination"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized, "log output must not contain {$forbidden}");
        }
    }

    public function test_guest_planning_is_rate_limited_tighter_than_customers(): void
    {
        config(['rate_limits.journey_plan_guest' => 2, 'rate_limits.journey_plan' => 3]);

        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        $this->actingAsPrincipal($this->customer($this->market));
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertStatus(429);
    }
}
