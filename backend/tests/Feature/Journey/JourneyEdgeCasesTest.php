<?php

namespace Tests\Feature\Journey;

use App\Enums\Permission;
use App\Models\Journey;
use App\Models\RestaurantLocation;
use App\Services\Journey\DiscoveryFilters;
use App\Services\Journey\JourneyPolicy;
use App\Services\Journey\RouteDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * Edge cases of journeys and route discovery: very short and long journeys, time zones, other principals,
 * hostile input, duplicates across pages, and concurrent first reads of the same journey.
 * The clock is Monday 2026-10-05 12:00 in India.
 */
class JourneyEdgeCasesTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    }

    public function test_a_very_short_journey_is_planned_and_searched_like_any_other(): void
    {
        $this->eligible('Round The Corner', 28.5502, 77.3505);
        $plan = $this->planGuest(['origin' => ['lat' => 28.55, 'lng' => 77.35], 'destination' => ['lat' => 28.5503, 'lng' => 77.353]]);

        $this->assertLessThan(600, $plan['response']->json('route.distance_meters'));
        $this->assertGreaterThan(0, $plan['response']->json('route.duration_seconds'));
        $this->assertSame(['Round The Corner'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'])->assertOk()));
    }

    public function test_route_flags_such_as_tolls_are_preserved_on_the_journey(): void
    {
        $long = $this->planGuest(['destination' => ['lat' => 27.18, 'lng' => 78.0, 'label' => 'Agra']]);
        $this->assertSame(['TOLLS'], $long['response']->json('route.flags'));
        $this->assertNotEmpty($long['response']->json('route.warnings'));
        $this->assertSame([], $this->planGuest()['response']->json('route.flags'));
    }

    public function test_opening_hours_are_evaluated_in_the_restaurants_own_time_zone(): void
    {
        // Open 09:00–12:15 local. At 12:00 in India it is 12:30 in Dhaka (UTC+6): the Dhaka-time restaurant has closed.
        $this->eligible('India Time', 28.552, 77.33, hours: [[null, '09:00', '12:15']]);
        $this->eligible('Dhaka Time', 28.552, 77.36, ['timezone' => 'Asia/Dhaka'], hours: [[null, '09:00', '12:15']]);

        $plan = $this->planGuest();
        $response = $this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'route_order'])->assertOk();

        $this->assertSame(['India Time', 'Dhaka Time'], $this->namesOf($response));
        $this->assertSame([true, false], $response->json('data.*.route_context.open_at_arrival'));
        $this->assertSame(['Asia/Kolkata', 'Asia/Dhaka'], $response->json('data.*.route_context.arrival.timezone'));
        $this->assertStringStartsWith('12:0', $response->json('data.0.route_context.arrival.local_time'));
        $this->assertStringStartsWith('12:', $response->json('data.1.route_context.arrival.local_time'));
        $this->assertGreaterThan('12:30', $response->json('data.1.route_context.arrival.local_time'), 'the same instant, read on the Dhaka clock');
    }

    public function test_restaurant_staff_and_administrators_cannot_read_a_customers_or_guests_journey(): void
    {
        $guest = $this->planGuest();
        $this->actingAsPrincipal($this->customer($this->market));
        $owned = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated()->json('id');

        foreach ([$this->member($this->organization()), $this->adminWith(array_values(array_filter(Permission::cases(), fn (Permission $p): bool => str_starts_with($p->value, 'admin.'))))] as $principal) {
            $this->actingAsPrincipal($principal);
            $this->getJson('/api/v1/journeys/'.$owned)->assertNotFound();
            $this->getJson('/api/v1/journeys/'.$owned.'/restaurants')->assertNotFound();
            $this->getJson('/api/v1/journeys/'.$guest['id'])->assertNotFound();
        }

        // Nothing restaurant-facing carries a journey: the ends live on the journey row only.
        $this->assertSame(2, Journey::query()->count());
    }

    public function test_hostile_filter_input_is_bound_as_data_never_as_sql(): void
    {
        $this->eligible("O'Reilly's Kitchen", 28.552, 77.33);
        $this->eligible('100% Veg_Place', 28.552, 77.36);
        $plan = $this->planGuest();

        $this->assertSame(["O'Reilly's Kitchen"], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['q' => "o'reilly"])->assertOk()));
        $this->assertSame([], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['q' => "'; drop table restaurant_locations; --"])->assertOk()));
        $this->assertSame(['100% Veg_Place'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['q' => '100% veg_'])->assertOk()), 'LIKE wildcards in the text match literally');
        $this->assertSame(['100% Veg_Place'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['q' => '%'])->assertOk()), 'a percent sign matches a literal percent sign, not everything');
        $this->assertSame([], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['q' => 'zzz'])->assertOk()->assertJsonPath('meta.empty_reason', 'FILTERS')));
        $this->assertSame(2, RestaurantLocation::query()->count());
    }

    public function test_a_restaurant_is_listed_once_across_all_pages(): void
    {
        for ($i = 0; $i < 33; $i++) {
            $this->eligible('Unique '.$i, 28.551 + ($i % 3) * 0.001, 77.302 + $i * 0.0029);
        }
        $plan = $this->planGuest();

        $ids = [];
        for ($page = 1; $page <= 4; $page++) {
            array_push($ids, ...$this->restaurantsOf($plan['id'], $plan['headers'], ['page' => ['size' => 10, 'number' => $page], 'sort' => 'route_order'])->assertOk()->json('data.*.restaurant.id'));
        }
        $this->assertCount(33, $ids);
        $this->assertCount(33, array_unique($ids));
        $this->assertSame([], $this->restaurantsOf($plan['id'], $plan['headers'], ['page' => ['size' => 10, 'number' => 9]])->assertOk()->json('data'));
    }

    public function test_one_computation_runs_at_a_time_for_a_journey_and_a_stuck_lock_never_fails_a_search(): void
    {
        $this->eligible('Locked', 28.552, 77.33);
        $plan = $this->planGuest();
        $journey = Journey::query()->where('public_id', $plan['id'])->firstOrFail()->load('market');
        $key = RouteDiscoveryService::cacheKey($journey, DiscoveryFilters::fromInput([], app(JourneyPolicy::class)->forMarket($journey->market)), 3000).':lock';

        // While the shortlist is with the routing provider the lock is held: a concurrent first read would wait for it.
        $heldDuringComputation = null;
        $this->fakeRouting()->onDetours = function () use ($key, &$heldDuringComputation): void {
            $heldDuringComputation = ! Cache::lock($key, 5)->get();
        };
        $this->restaurantsOf($plan['id'], $plan['headers'])->assertOk()->assertJsonPath('meta.cached', false);
        $this->assertTrue($heldDuringComputation);
        $this->fakeRouting()->onDetours = null;

        $released = Cache::lock($key, 5);
        $this->assertTrue($released->get(), 'the lock is released when the computation ends');
        $released->release();

        // A lock somebody else holds (and never releases in time) must not fail the search: it computes on its own.
        $stuck = Cache::lock(RouteDiscoveryService::cacheKey($journey, DiscoveryFilters::fromInput([], app(JourneyPolicy::class)->forMarket($journey->market)), 4000).':lock', 60);
        $this->assertTrue($stuck->get());
        config(['journey.cache.compute_wait_seconds' => 0]);
        $this->assertSame(['Locked'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['corridor_width_meters' => 4000])->assertOk()));
        $stuck->release();
    }

    public function test_sending_shortlists_to_the_routing_provider_is_limited_per_journey_while_reads_are_not(): void
    {
        config(['rate_limits.journey_detours' => 2]);
        $this->eligible('Burger Stop', 28.552, 77.32, cuisines: ['burgers']);
        $this->eligible('Pizza Stop', 28.552, 77.35, cuisines: ['pizza']);
        $this->eligible('Cafe Stop', 28.552, 77.38, cuisines: ['cafe']);
        $plan = $this->planGuest();

        // Two searches that each need a detour the journey does not have yet …
        $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'burgers'])->assertOk()->assertJsonPath('meta.counts.detour_requested', 1);
        $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'pizza'])->assertOk()->assertJsonPath('meta.counts.detour_requested', 1);
        // … the third is refused: nothing is sent to the provider.
        $refused = $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'cafe'])->assertStatus(429);
        $this->assertSame('rate_limited', $refused->json('error.code'));
        $this->assertGreaterThan(0, $refused->json('error.details.retry_after_seconds'));
        $this->assertSame(2, array_sum(array_column($this->fakeRouting()->detourCalls(), 'waypoints')));

        // Calculated results stay readable, and so does any search that needs no new detour.
        $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'burgers'])->assertOk()->assertJsonPath('meta.cached', true);
        $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'burgers,pizza'])->assertOk()->assertJsonPath('meta.counts.detour_requested', 0);
        $this->getJson('/api/v1/journeys/'.$plan['id'], $plan['headers'])->assertOk();

        // Another journey has its own allowance; a minute later this one may ask again.
        $other = $this->planGuest();
        $this->restaurantsOf($other['id'], $other['headers'], ['cuisine' => 'cafe'])->assertOk()->assertJsonPath('meta.counts.detour_requested', 1);
        $this->travel(61)->seconds();
        $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'cafe'])->assertOk()->assertJsonPath('meta.counts.detour_requested', 1);
    }
}
