<?php

namespace Tests\Feature\Restaurant;

use App\Enums\OpenState;
use App\Enums\RestaurantAvailabilityReason as Reason;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Restaurant\RestaurantAvailability;
use App\Services\Restaurant\RestaurantAvailabilityService;
use App\Services\Restaurant\RestaurantHoursService;
use App\Services\Restaurant\RestaurantVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * "Can a customer see this restaurant, and can they order from it right now?" — decided by the backend
 * against real rows. The clock is Monday 2026-10-05 12:00 in India unless a test moves it.
 */
class RestaurantAvailabilityTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private RestaurantAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        $this->service = app(RestaurantAvailabilityService::class);
        $this->clock('2026-10-05 12:00');
    }

    private function clock(string $local): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($local, 'Asia/Kolkata'));
    }

    /**
     * An approved location that is open 09:00 – 22:00 every day.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function restaurant(array $attributes = [], ?RestaurantOrganization $organization = null, float $lat = 28.55, float $lng = 77.35): RestaurantLocation
    {
        return $this->hours($this->location($organization ?? $this->organization(), attributes: $attributes, lat: $lat, lng: $lng), [[null, '09:00', '22:00']]);
    }

    private function evaluate(RestaurantLocation $location): RestaurantAvailability
    {
        return $this->service->evaluate(RestaurantLocation::query()->findOrFail($location->id));
    }

    public function test_approved_open_and_accepting_is_visible_and_orderable(): void
    {
        $result = $this->evaluate($this->restaurant());

        $this->assertTrue($result->visible);
        $this->assertTrue($result->orderable);
        $this->assertNull($result->reason);
        $this->assertSame(OpenState::Open, $result->openState);
        $this->assertTrue($result->acceptingOrders);
        $this->assertEquals(CarbonImmutable::parse('2026-10-05 22:00', 'Asia/Kolkata'), $result->closesAt);
        $this->assertNull($result->opensNextAt);

        $this->assertSame([
            'open_now' => true, 'open_state' => 'OPEN', 'accepting_orders' => true, 'orderable' => true, 'reason' => null,
            'closes_at' => '2026-10-05T16:30:00+00:00', 'opens_next_at' => null, 'checked_at' => '2026-10-05T06:30:00+00:00',
        ], $result->forCustomers());
    }

    public function test_approved_but_closed_stays_visible_and_says_when_it_opens(): void
    {
        $location = $this->restaurant();
        $this->clock('2026-10-05 23:00');

        $result = $this->evaluate($location);

        $this->assertTrue($result->visible, 'a closed restaurant is still shown');
        $this->assertFalse($result->orderable);
        $this->assertSame(Reason::ClosedNow, $result->reason);
        $this->assertSame(OpenState::Closed, $result->openState);
        $this->assertTrue($result->acceptingOrders, 'closed is not the same as not accepting');
        $this->assertNull($result->closesAt);
        $this->assertEquals(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Kolkata'), $result->opensNextAt);
    }

    public function test_open_but_not_accepting_orders_is_visible_and_not_orderable(): void
    {
        $result = $this->evaluate($this->restaurant(['accepting_orders' => false, 'pause_reason' => 'Kitchen at capacity', 'paused_at' => now()]));

        $this->assertTrue($result->visible);
        $this->assertFalse($result->orderable);
        $this->assertSame(Reason::NotAcceptingOrders, $result->reason);
        $this->assertSame(OpenState::Open, $result->openState, 'open and accepting are separate facts');
        $this->assertFalse($result->acceptingOrders);
    }

    public function test_a_timed_pause_ends_by_itself_when_its_time_passes(): void
    {
        $location = $this->restaurant(['accepting_orders' => false, 'paused_at' => now(), 'paused_until' => now()->addMinutes(30)]);

        $this->assertSame(Reason::NotAcceptingOrders, $this->evaluate($location)->reason);

        $this->clock('2026-10-05 12:29');
        $this->assertFalse($this->evaluate($location)->orderable);

        $this->clock('2026-10-05 12:30');
        $this->assertTrue($this->evaluate($location)->orderable, 'nobody had to switch it back on');
        $this->assertTrue($this->service->isAcceptingOrders(RestaurantLocation::query()->findOrFail($location->id)));
    }

    public function test_temporarily_closed_overrides_the_opening_hours(): void
    {
        $result = $this->evaluate($this->restaurant(['operational_status' => 'TEMPORARILY_CLOSED']));

        $this->assertTrue($result->visible);
        $this->assertFalse($result->orderable);
        $this->assertSame(Reason::TemporarilyClosed, $result->reason);
        $this->assertSame(OpenState::TemporarilyClosed, $result->openState);
        $this->assertFalse($result->forCustomers()['open_now']);
    }

    public function test_a_suspended_location_is_neither_visible_nor_orderable(): void
    {
        $result = $this->evaluate($this->restaurant(['status' => 'SUSPENDED']));

        $this->assertFalse($result->visible);
        $this->assertFalse($result->orderable);
        $this->assertSame(Reason::LocationSuspended, $result->reason);
    }

    public function test_a_suspended_organization_takes_all_its_locations_away_without_changing_them(): void
    {
        $organization = $this->organization();
        $first = $this->restaurant(organization: $organization);
        $second = $this->restaurant(['name' => 'Second outlet'], $organization);

        $organization->forceFill(['status' => 'SUSPENDED'])->save();

        foreach ([$first, $second] as $location) {
            $result = $this->evaluate($location);
            $this->assertFalse($result->visible);
            $this->assertSame(Reason::RestaurantSuspended, $result->reason);
        }
        $this->assertSame(['APPROVED', 'APPROVED'], DB::table('restaurant_locations')->orderBy('id')->pluck('status')->all(), 'the locations keep their own status');

        $organization->forceFill(['status' => 'APPROVED'])->save();
        $this->assertTrue($this->evaluate($first)->orderable, 'reactivating the organization brings them back');
    }

    public function test_anything_that_is_not_approved_is_invisible(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REJECTED', 'INACTIVE'] as $status) {
            $result = $this->evaluate($this->restaurant(['status' => $status]));
            $this->assertFalse($result->visible, $status);
            $this->assertSame(Reason::LocationNotApproved, $result->reason, $status);

            $result = $this->evaluate($this->restaurant(organization: $this->organization('Org '.$status, $status)));
            $this->assertFalse($result->visible, 'organization '.$status);
            $this->assertSame(Reason::RestaurantNotApproved, $result->reason, 'organization '.$status);
        }
    }

    public function test_a_location_outside_every_service_area_is_invisible(): void
    {
        $result = $this->evaluate($this->restaurant(lat: 28.65, lng: 77.35));

        $this->assertFalse($result->visible);
        $this->assertSame(Reason::OutsideServiceArea, $result->reason);
    }

    public function test_a_paused_area_city_or_region_keeps_the_restaurant_visible_but_not_orderable(): void
    {
        $location = $this->restaurant();

        foreach ([
            ['service_areas', $this->serviceArea->id, Reason::ServiceAreaUnavailable],
            ['cities', $this->noida->id, Reason::CityUnavailable],
            ['market_regions', $this->noida->region_id, Reason::RegionUnavailable],
        ] as [$table, $id, $reason]) {
            DB::table($table)->where('id', $id)->update(['status' => 'PAUSED']);

            $result = $this->evaluate($location);
            $this->assertTrue($result->visible, $table);
            $this->assertFalse($result->orderable, $table);
            $this->assertSame($reason, $result->reason, $table);
            $this->assertSame('AREA_UNAVAILABLE', $result->forCustomers()['reason'], 'customers get one code for all three');
            $this->assertSame($reason->value, $result->forStaff()['reason'], 'staff see the exact one');

            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }

        $this->assertTrue($this->evaluate($location)->orderable);
    }

    public function test_coverage_that_is_not_public_hides_the_restaurant(): void
    {
        $location = $this->restaurant();

        foreach ([['service_areas', $this->serviceArea->id, ['PLANNED', 'TESTING', 'DISABLED']], ['cities', $this->noida->id, ['PLANNED', 'UNAVAILABLE']], ['market_regions', $this->noida->region_id, ['PLANNED', 'DISABLED']]] as [$table, $id, $statuses]) {
            foreach ($statuses as $status) {
                DB::table($table)->where('id', $id)->update(['status' => $status]);

                $result = $this->evaluate($location);
                $this->assertFalse($result->visible, "{$table} {$status}");
                $this->assertSame(Reason::OutsideServiceArea, $result->reason, "{$table} {$status}");
            }
            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }
    }

    public function test_an_area_outside_its_effective_dates_is_shown_but_not_served(): void
    {
        $location = $this->restaurant();
        DB::table('service_areas')->where('id', $this->serviceArea->id)->update(['effective_from' => now()->addDay()]);

        $result = $this->evaluate($location);

        $this->assertTrue($result->visible);
        $this->assertSame(Reason::ServiceAreaUnavailable, $result->reason);

        $this->clock('2026-10-06 12:00');
        $this->assertTrue($this->evaluate($location)->orderable, 'served from its effective date');
    }

    public function test_a_market_that_does_not_serve_customers_makes_everything_unavailable(): void
    {
        $location = $this->restaurant();
        $this->market->forceFill(['status' => 'PAUSED'])->save();

        $result = $this->evaluate($location);

        $this->assertFalse($result->visible);
        $this->assertSame(Reason::MarketUnavailable, $result->reason);
    }

    public function test_pickup_must_be_offered_and_allowed_by_the_market(): void
    {
        $location = $this->restaurant();

        DB::table('restaurant_pickup_settings')->where('location_id', $location->id)->update(['pickup_enabled' => false]);
        $this->assertSame(Reason::PickupUnavailable, $this->evaluate($location)->reason);
        DB::table('restaurant_pickup_settings')->where('location_id', $location->id)->update(['pickup_enabled' => true]);

        // The location only offers ASAP; the market switches ASAP pickup off.
        $this->market->forceFill(['features' => ['asap_pickup' => false, 'scheduled_pickup' => true]])->save();
        $result = $this->evaluate($location);
        $this->assertTrue($result->visible);
        $this->assertSame(Reason::PickupUnavailable, $result->reason);

        // A method the market has not enabled does not count, whatever the location saved.
        $this->market->forceFill(['features' => ['asap_pickup' => true, 'curbside_pickup' => false]])->save();
        DB::table('restaurant_location_pickup_methods')->where('location_id', $location->id)->update(['method' => 'CURBSIDE']);
        $this->assertSame(Reason::PickupUnavailable, $this->evaluate($location)->reason);

        $this->market->forceFill(['features' => ['asap_pickup' => true, 'curbside_pickup' => true]])->save();
        $this->assertTrue($this->evaluate($location)->orderable);
    }

    public function test_the_first_failing_condition_is_the_reason(): void
    {
        $this->clock('2026-10-05 23:30');   // closed

        $closedAndPaused = $this->restaurant(['accepting_orders' => false, 'paused_at' => now()]);
        $this->assertSame(Reason::NotAcceptingOrders, $this->evaluate($closedAndPaused)->reason, 'not accepting is reported before closed');

        $everything = $this->restaurant(['status' => 'SUSPENDED', 'accepting_orders' => false, 'paused_at' => now(), 'operational_status' => 'TEMPORARILY_CLOSED'], lat: 28.65);
        $this->assertSame(Reason::LocationSuspended, $this->evaluate($everything)->reason, 'approval comes before geography, hours and switches');

        $closedTemporarily = $this->restaurant(['accepting_orders' => false, 'paused_at' => now(), 'operational_status' => 'TEMPORARILY_CLOSED']);
        $this->assertSame(Reason::TemporarilyClosed, $this->evaluate($closedTemporarily)->reason);
    }

    public function test_geography_is_decided_from_the_geometry_not_from_the_stored_service_area(): void
    {
        $location = $this->restaurant();
        $this->assertSame($this->serviceArea->id, DB::table('restaurant_locations')->where('id', $location->id)->value('service_area_id'));

        // The area is redrawn so that it no longer covers the restaurant. The stored id is untouched on purpose.
        DB::update("update service_areas set geometry = ST_Multi(ST_GeomFromText('POLYGON((77.50 28.50, 77.60 28.50, 77.60 28.60, 77.50 28.60, 77.50 28.50))', 4326)) where id = ?", [$this->serviceArea->id]);

        $result = $this->evaluate($location);
        $this->assertFalse($result->visible);
        $this->assertSame(Reason::OutsideServiceArea, $result->reason);
        $this->assertNotNull(DB::table('restaurant_locations')->where('id', $location->id)->value('service_area_id'));
    }

    public function test_special_hours_decide_before_weekly_hours(): void
    {
        $location = $this->restaurant();
        $this->special($location, '2026-10-05', note: 'Closed for a private event');

        $result = $this->evaluate($location);
        $this->assertSame(Reason::ClosedNow, $result->reason);
        $this->assertEquals(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Kolkata'), $result->opensNextAt);

        $this->special($location, '2026-10-06', [['14:00', '18:00']]);
        $this->assertEquals(CarbonImmutable::parse('2026-10-06 14:00', 'Asia/Kolkata'), $this->evaluate($location)->opensNextAt, 'tomorrow opens at its special time');
    }

    public function test_the_single_question_helpers_agree_with_the_evaluation(): void
    {
        $open = RestaurantLocation::query()->findOrFail($this->restaurant()->id);
        $paused = RestaurantLocation::query()->findOrFail($this->restaurant(['accepting_orders' => false, 'paused_at' => now()])->id);
        $draft = RestaurantLocation::query()->findOrFail($this->restaurant(['status' => 'DRAFT'])->id);

        $this->assertTrue($this->service->isVisibleToCustomer($open));
        $this->assertTrue($this->service->isOrderable($open));
        $this->assertTrue($this->service->isCurrentlyOpen($open));
        $this->assertTrue($this->service->isAcceptingOrders($open));
        $this->assertNull($this->service->availabilityReason($open));

        $this->assertTrue($this->service->isCurrentlyOpen($paused));
        $this->assertFalse($this->service->isAcceptingOrders($paused));
        $this->assertFalse($this->service->isOrderable($paused));

        $this->assertFalse($this->service->isVisibleToCustomer($draft));
        $this->assertSame(Reason::LocationNotApproved, $this->service->availabilityReason($draft));

        $hours = app(RestaurantHoursService::class);
        $this->assertEquals(CarbonImmutable::parse('2026-10-05 22:00', 'Asia/Kolkata'), $hours->getCurrentPeriod($open));
        $this->assertEquals(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Kolkata'), $hours->getNextOpenTime($open));
        $this->assertSame([['opens_at' => '09:00', 'closes_at' => '22:00']], $hours->getHoursForDate($open, '2026-10-07'));
    }

    /**
     * The customer queries filter in SQL (RestaurantVisibility); a single restaurant is judged in PHP. They must
     * be the same rule — this builds one restaurant per situation and compares both answers.
     */
    public function test_the_sql_visibility_rule_matches_the_evaluation_for_every_situation(): void
    {
        $pausedCity = $this->city($this->region($this->market, 'IN-GJ', name: 'Gujarat'), 'Vapi', 20.3893, 72.9106, 'PAUSED');
        $this->area($pausedCity, [72.86, 20.34, 72.96, 20.44], 'PAUSED', 'Vapi');
        $plannedCity = $this->city($this->region($this->market, 'IN-RJ', name: 'Rajasthan'), 'Udaipur', 24.5854, 73.7125, 'PLANNED');
        $this->area($plannedCity, [73.66, 24.53, 73.76, 24.63], 'ACTIVE', 'Udaipur');
        $testing = $this->area($this->noida, [77.50, 28.50, 77.60, 28.60], 'TESTING', 'Noida East (testing)');

        $cases = [
            'open' => $this->restaurant(),
            'paused orders' => $this->restaurant(['accepting_orders' => false, 'paused_at' => now()]),
            'temporarily closed' => $this->restaurant(['operational_status' => 'TEMPORARILY_CLOSED']),
            'suspended' => $this->restaurant(['status' => 'SUSPENDED']),
            'under review' => $this->restaurant(['status' => 'UNDER_REVIEW']),
            'organization suspended' => $this->restaurant(organization: $this->organization('Suspended Org', 'SUSPENDED')),
            'organization draft' => $this->restaurant(organization: $this->organization('Draft Org', 'DRAFT')),
            'outside every area' => $this->restaurant(lat: 28.65, lng: 77.35),
            'paused area and city' => $this->restaurant(lat: 20.39, lng: 72.91, attributes: ['city_id' => $pausedCity->id, 'region_id' => $pausedCity->region_id]),
            'planned city' => $this->restaurant(lat: 24.58, lng: 73.71, attributes: ['city_id' => $plannedCity->id, 'region_id' => $plannedCity->region_id]),
            'testing area only' => $this->restaurant(lat: 28.55, lng: 77.55),
        ];
        $this->assertNotNull($testing);

        $query = RestaurantLocation::query();
        app(RestaurantVisibility::class)->scopeVisible($query);
        app(RestaurantVisibility::class)->selectServiceable($query, now());
        $visible = $query->get()->keyBy('id');

        foreach ($cases as $name => $location) {
            $result = $this->evaluate($location);
            $this->assertSame($result->visible, $visible->has($location->id), "visibility of [{$name}]");

            if ($result->visible) {
                $geographyServes = ! in_array($result->reason, [Reason::RegionUnavailable, Reason::CityUnavailable, Reason::ServiceAreaUnavailable], true);
                $this->assertSame($geographyServes, (bool) $visible[$location->id]->geo_serviceable, "serviceable flag of [{$name}]");
                // A list row carries the flag, so evaluating it must not look at the geometry again.
                $this->assertSame($result->orderable, $this->service->evaluate($visible[$location->id])->orderable, "list evaluation of [{$name}]");
            }
        }

        $this->assertSame(['open', 'paused orders', 'temporarily closed', 'paused area and city'], array_keys(array_filter($cases, fn (RestaurantLocation $l): bool => $visible->has($l->id))));
    }

    /**
     * "Open now" as a list filter is SQL; the answer shown on each restaurant is Schedule. Compared for every
     * schedule shape at instants spread over nine days, in each location's own time zone.
     */
    public function test_the_sql_open_now_filter_matches_the_schedule_at_every_instant(): void
    {
        $organization = $this->organization();
        $make = fn (string $name, array $attributes = []) => $this->location($organization, $name, $attributes);

        $this->hours($make('Daily'), [[null, '09:00', '17:00']]);
        $this->hours($make('Split'), [[null, '10:00', '15:00'], [null, '18:00', '22:30']]);
        $this->hours($make('Weekend nights'), [[[5, 6], '20:00', '02:00']]);
        $this->hours($make('Round the clock'), [[null, '00:00', '00:00']]);
        $this->hours($make('Until midnight'), [[null, '06:00', '00:00']]);
        $specials = $this->hours($make('Specials'), [[[0, 2, 3, 4, 5, 6], '10:30', '22:00']]);
        $this->special($specials, '2026-10-06');
        $this->special($specials, '2026-10-07', [['11:00', '16:00']]);
        $this->special($specials, '2026-10-12', [['08:00', '09:00'], ['21:00', '03:00']]);
        $tail = $this->hours($make('Overnight with a closure'), [[null, '18:00', '02:00']]);
        $this->special($tail, '2026-10-06');
        $this->hours($make('New York', ['timezone' => 'America/New_York']), [[null, '09:00', '17:00']]);
        $this->hours($make('Tokyo', ['timezone' => 'Asia/Tokyo']), [[[1, 2, 3, 4, 5], '11:00', '23:00']]);
        $this->hours($make('Switched off', ['operational_status' => 'TEMPORARILY_CLOSED']), [[null, '00:00', '00:00']]);
        $make('No hours');

        $locations = RestaurantLocation::query()->with(['hours', 'specialHours.periods'])->get();
        $hours = app(RestaurantHoursService::class);
        $visibility = app(RestaurantVisibility::class);
        $compared = 0;

        for ($instant = CarbonImmutable::parse('2026-10-03 18:30', 'UTC'); $instant->lessThan(CarbonImmutable::parse('2026-10-13 00:00', 'UTC')); $instant = $instant->addMinutes(47)) {
            $query = RestaurantLocation::query();
            $visibility->whereOpenAt($query, $instant);
            $sql = $query->pluck('name')->sort()->values()->all();

            $php = $locations->filter(fn (RestaurantLocation $l): bool => $hours->isOpenAt($l, $instant))->pluck('name')->sort()->values()->all();

            $this->assertSame($php, $sql, 'at '.$instant->toIso8601String());
            $compared++;
        }

        $this->assertGreaterThan(250, $compared);
    }
}
