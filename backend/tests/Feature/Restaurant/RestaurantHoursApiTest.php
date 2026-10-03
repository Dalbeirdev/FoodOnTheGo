<?php

namespace Tests\Feature\Restaurant;

use App\Models\AuditEvent;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Opening hours through the restaurant API: the weekly schedule (replaced as a whole) and special dates.
 * The clock is Monday 2026-10-05 12:00 in India.
 */
class RestaurantHoursApiTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

        $this->organization = $this->organization('Riverside');
        $this->burger = $this->location($this->organization, 'Burger Hub', ['slug' => 'burger-hub']);
        $this->actingAsPrincipal($this->member($this->organization, 'OWNER'));
    }

    private function url(string $path = '/hours', ?RestaurantLocation $location = null): string
    {
        return '/api/v1/restaurant/locations/'.($location ?? $this->burger)->public_id.$path;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $periods
     * @return list<array{day_of_week: int, opens_at: string, closes_at: string}>
     */
    private function periods(array $periods): array
    {
        return array_map(fn (array $p): array => ['day_of_week' => $p[0], 'opens_at' => $p[1], 'closes_at' => $p[2]], $periods);
    }

    /**
     * @return list<string> "day opens-closes" rows as stored
     */
    private function stored(): array
    {
        return DB::table('restaurant_location_hours')->where('location_id', $this->burger->id)->orderBy('day_of_week')->orderBy('sequence')->get()
            ->map(fn (object $h): string => $h->day_of_week.' '.substr($h->opens_at, 0, 5).'-'.substr($h->closes_at, 0, 5).' #'.$h->sequence)->all();
    }

    public function test_a_new_location_has_no_hours_and_is_closed(): void
    {
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('location_id', $this->burger->public_id)
            ->assertJsonPath('version', 1)
            ->assertJsonPath('timezone', 'Asia/Kolkata')
            ->assertJsonCount(7, 'weekly')
            ->assertJsonPath('weekly.0', ['day_of_week' => 0, 'periods' => []])
            ->assertJsonPath('special', [])
            ->assertJsonPath('availability.open_state', 'CLOSED')
            ->assertJsonPath('availability.opens_next_at', null);
    }

    public function test_the_week_is_replaced_as_a_whole_with_several_periods_and_overnight_hours(): void
    {
        $response = $this->putJson($this->url(), ['version' => 1, 'periods' => $this->periods([
            [1, '18:00', '22:30'], [1, '10:00', '15:00'],      // Monday: two periods, sent out of order
            [5, '20:00', '02:00'],                             // Friday: past midnight
            [3, '00:00', '00:00'],                             // Wednesday: 24 hours
        ])])->assertOk();

        $response->assertJsonPath('version', 2)
            ->assertJsonPath('weekly.1.periods', [['opens_at' => '10:00', 'closes_at' => '15:00'], ['opens_at' => '18:00', 'closes_at' => '22:30']])
            ->assertJsonPath('weekly.5.periods', [['opens_at' => '20:00', 'closes_at' => '02:00']])
            ->assertJsonPath('weekly.3.periods', [['opens_at' => '00:00', 'closes_at' => '00:00']])
            ->assertJsonPath('weekly.2.periods', [])
            ->assertJsonPath('availability.open_state', 'OPEN')
            ->assertJsonPath('availability.closes_at', '2026-10-05T09:30:00+00:00');
        $this->assertSame(['1 10:00-15:00 #0', '1 18:00-22:30 #1', '3 00:00-00:00 #0', '5 20:00-02:00 #0'], $this->stored());

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_location.hours_changed', $event->action);
        $this->assertEquals(['from' => [], 'to' => ['1 10:00-15:00', '1 18:00-22:30', '3 00:00-00:00', '5 20:00-02:00']], $event->changes['weekly_hours']);

        // Replacing again removes what is not sent: the week is exactly the request.
        $this->putJson($this->url(), ['version' => 2, 'periods' => $this->periods([[2, '09:00', '17:00']])])->assertOk()->assertJsonPath('version', 3)->assertJsonPath('weekly.1.periods', []);
        $this->assertSame(['2 09:00-17:00 #0'], $this->stored());

        // An empty week is a valid answer: closed every day.
        $this->putJson($this->url(), ['version' => 3, 'periods' => []])->assertOk()->assertJsonPath('version', 4)->assertJsonPath('availability.open_state', 'CLOSED');
        $this->assertSame([], $this->stored());

        // Customers read the same hours.
        $this->putJson($this->url(), ['version' => 4, 'periods' => $this->periods([[1, '09:00', '22:00']])])->assertOk();
        $this->getJson('/api/v1/restaurants/burger-hub')->assertOk()->assertJsonPath('hours.weekly.1.periods', [['opens_at' => '09:00', 'closes_at' => '22:00']])->assertJsonPath('availability.open_now', true);
    }

    public function test_overlapping_periods_are_refused_including_across_midnight_and_the_end_of_the_week(): void
    {
        $refused = fn (array $periods, string $field) => $this->assertSame(
            [$field => ['Opening periods must not overlap.']],
            $this->putJson($this->url(), ['version' => 1, 'periods' => $this->periods($periods)])->assertUnprocessable()->json('error.details.fields'),
        );

        $refused([[1, '09:00', '14:00'], [1, '13:00', '18:00']], 'periods.1');
        $refused([[1, '09:00', '18:00'], [1, '10:00', '12:00']], 'periods.1');
        $refused([[1, '09:00', '14:00'], [1, '09:00', '14:00']], 'periods.1');
        $refused([[1, '20:00', '02:00'], [2, '01:00', '10:00']], 'periods.1');           // Monday night runs into Tuesday morning
        $refused([[0, '01:00', '09:00'], [6, '20:00', '02:00']], 'periods.0');           // Saturday night runs into Sunday morning
        $refused([[3, '00:00', '00:00'], [4, '00:00', '06:00'], [3, '12:00', '13:00']], 'periods.2'); // inside a 24-hour day

        // Touching periods, and an overnight period that ends exactly when the next day opens, are fine.
        $this->putJson($this->url(), ['version' => 1, 'periods' => $this->periods([[1, '09:00', '14:00'], [1, '14:00', '18:00'], [1, '20:00', '02:00'], [2, '02:00', '10:00'], [6, '20:00', '01:00'], [0, '01:00', '09:00']])])->assertOk();
    }

    public function test_the_shape_of_a_schedule_is_validated(): void
    {
        $refused = fn (array $body, string $field) => $this->putJson($this->url(), ['version' => 1, ...$body])->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details.fields');

        $refused([], 'periods');
        $refused(['periods' => 'always'], 'periods');
        $refused(['periods' => [['day_of_week' => 7, 'opens_at' => '09:00', 'closes_at' => '17:00']]], 'periods.0.day_of_week');
        $refused(['periods' => [['day_of_week' => 1, 'opens_at' => '9am', 'closes_at' => '17:00']]], 'periods.0.opens_at');
        $refused(['periods' => [['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '24:00']]], 'periods.0.closes_at');
        $refused(['periods' => [['day_of_week' => 1, 'opens_at' => '09:00']]], 'periods.0.closes_at');
        $refused(['periods' => [['opens_at' => '09:00', 'closes_at' => '17:00']]], 'periods.0.day_of_week');
        $refused(['periods' => $this->periods([[1, '06:00', '07:00'], [1, '08:00', '09:00'], [1, '10:00', '11:00'], [1, '12:00', '13:00'], [1, '14:00', '15:00']])], 'periods.4');
        $this->putJson($this->url(), ['periods' => []])->assertUnprocessable()->assertJsonValidationErrorFor('version', 'error.details.fields');

        $this->assertSame([], $this->stored());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_a_failed_or_stale_replacement_leaves_the_old_week_untouched(): void
    {
        $this->putJson($this->url(), ['version' => 1, 'periods' => $this->periods([[1, '09:00', '17:00'], [2, '09:00', '17:00']])])->assertOk();
        $before = $this->stored();

        // Stale: somebody else saved in between.
        $this->putJson($this->url(), ['version' => 1, 'periods' => $this->periods([[3, '10:00', '11:00']])])
            ->assertConflict()->assertJsonPath('error.code', 'stale_update')->assertJsonPath('error.details.current_version', 2);
        // Invalid in the last period: not even the valid first ones are written.
        $this->putJson($this->url(), ['version' => 2, 'periods' => $this->periods([[3, '10:00', '11:00'], [4, '10:00', '11:00'], [4, '10:30', '12:00']])])->assertUnprocessable();

        $this->assertSame($before, $this->stored());
        $this->assertSame(2, (int) DB::table('restaurant_locations')->where('id', $this->burger->id)->value('hours_version'));
        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_editing_hours_does_not_conflict_with_editing_the_profile(): void
    {
        $this->patchJson($this->url('/profile'), ['version' => 1, 'name' => 'Burger Hub Two'])->assertOk()->assertJsonPath('version', 2);

        $this->putJson($this->url(), ['version' => 1, 'periods' => $this->periods([[1, '09:00', '17:00']])])->assertOk()->assertJsonPath('version', 2);
        $this->patchJson($this->url('/profile'), ['version' => 2, 'name' => 'Burger Hub Three'])->assertOk();
    }

    public function test_special_hours_close_a_date_or_give_it_other_hours(): void
    {
        $this->putJson($this->url(), ['version' => 1, 'periods' => $this->periods(array_map(fn (int $d): array => [$d, '09:00', '22:00'], range(0, 6)))])->assertOk();

        $closed = $this->postJson($this->url('/special-hours'), ['date' => '2026-10-05', 'is_closed' => true, 'public_note' => 'Closed for a private event', 'internal_note' => 'Booked by Sharma family'])
            ->assertCreated()
            ->assertJsonPath('date', '2026-10-05')->assertJsonPath('is_closed', true)->assertJsonPath('periods', [])
            ->assertJsonPath('public_note', 'Closed for a private event')->assertJsonPath('internal_note', 'Booked by Sharma family')->json();

        $festival = $this->postJson($this->url('/special-hours'), ['date' => '2026-11-08', 'is_closed' => false, 'periods' => [['opens_at' => '17:00', 'closes_at' => '23:00'], ['opens_at' => '11:00', 'closes_at' => '15:00']], 'public_note' => 'Diwali hours'])
            ->assertCreated()->assertJsonPath('periods', [['opens_at' => '11:00', 'closes_at' => '15:00'], ['opens_at' => '17:00', 'closes_at' => '23:00']])->json();

        // Today's closure wins over the regular hours — for staff and for customers.
        $this->getJson($this->url())->assertJsonPath('availability.open_state', 'CLOSED')->assertJsonPath('availability.opens_next_at', '2026-10-06T03:30:00+00:00')
            ->assertJsonPath('special.*.date', ['2026-10-05', '2026-11-08']);
        $public = $this->getJson('/api/v1/restaurants/burger-hub')->assertOk()->assertJsonPath('availability.reason', 'CLOSED_NOW');
        $this->assertSame([['date' => '2026-10-05', 'is_closed' => true, 'periods' => [], 'note' => 'Closed for a private event']], $public->json('hours.special'), 'customers get the coming 30 days and the public note only');
        $this->assertStringNotContainsString('Sharma', $public->getContent());

        $this->getJson($this->url('/special-hours'))->assertOk()->assertJsonCount(2)->assertJsonPath('0.id', $closed['id'])->assertJsonPath('1.id', $festival['id']);

        // Change the closure into short hours, then remove it: regular hours apply again.
        $this->patchJson($this->url('/special-hours/'.$closed['id']), ['is_closed' => false, 'periods' => [['opens_at' => '11:00', 'closes_at' => '13:00']]])->assertOk()
            ->assertJsonPath('is_closed', false)->assertJsonPath('date', '2026-10-05')->assertJsonPath('public_note', 'Closed for a private event');
        $this->getJson($this->url())->assertJsonPath('availability.open_state', 'OPEN')->assertJsonPath('availability.closes_at', '2026-10-05T07:30:00+00:00');

        $this->deleteJson($this->url('/special-hours/'.$closed['id']))->assertNoContent();
        $this->getJson($this->url())->assertJsonPath('availability.closes_at', '2026-10-05T16:30:00+00:00')->assertJsonPath('special.*.date', ['2026-11-08']);

        $this->assertSame(
            ['restaurant_location.hours_changed', 'restaurant_location.special_hours_added', 'restaurant_location.special_hours_added', 'restaurant_location.special_hours_changed', 'restaurant_location.special_hours_removed'],
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_special_hours_are_validated(): void
    {
        $this->postJson($this->url('/special-hours'), ['date' => '2026-10-20', 'is_closed' => true])->assertCreated();
        $refused = fn (array $body, string $field) => $this->postJson($this->url('/special-hours'), $body)->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details.fields');

        $refused(['date' => '2026-10-20', 'is_closed' => true], 'date');                                          // one exception per date
        $refused(['date' => '2026-10-04', 'is_closed' => true], 'date');                                          // yesterday
        $refused(['date' => '2027-10-20', 'is_closed' => true], 'date');                                          // more than a year ahead
        $refused(['date' => '20-10-2026', 'is_closed' => true], 'date');
        $refused(['date' => '2026-10-21'], 'is_closed');
        $refused(['date' => '2026-10-21', 'is_closed' => true, 'periods' => [['opens_at' => '10:00', 'closes_at' => '12:00']]], 'periods');
        $refused(['date' => '2026-10-21', 'is_closed' => false], 'periods');
        $refused(['date' => '2026-10-21', 'is_closed' => false, 'periods' => [['opens_at' => '10:00', 'closes_at' => '14:00'], ['opens_at' => '13:00', 'closes_at' => '16:00']]], 'periods.1');
        $refused(['date' => '2026-10-21', 'is_closed' => false, 'periods' => [['opens_at' => '10:00']]], 'periods.0.closes_at');
        $refused(['date' => '2026-10-21', 'is_closed' => true, 'public_note' => '<i>Closed</i>'], 'public_note');
        $refused(['date' => '2026-10-21', 'is_closed' => true, 'public_note' => str_repeat('x', 161)], 'public_note');

        $this->assertSame(1, DB::table('restaurant_special_hours')->count());
    }

    public function test_today_is_the_restaurants_own_today(): void
    {
        // 2026-10-05 20:30 UTC is already the 6th in India (02:00): the 5th is the past there.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 20:30', 'UTC'));

        $this->postJson($this->url('/special-hours'), ['date' => '2026-10-05', 'is_closed' => true])->assertUnprocessable();
        $this->postJson($this->url('/special-hours'), ['date' => '2026-10-06', 'is_closed' => true])->assertCreated();
    }

    public function test_a_special_date_of_another_location_does_not_exist_here(): void
    {
        $brew = $this->location($this->organization, 'Brew & Bites');
        $other = $this->special($brew, '2026-10-20');

        $this->patchJson($this->url('/special-hours/'.$other->public_id), ['is_closed' => false, 'periods' => [['opens_at' => '10:00', 'closes_at' => '12:00']]])->assertNotFound();
        $this->deleteJson($this->url('/special-hours/'.$other->public_id))->assertNotFound();
        $this->assertSame(1, DB::table('restaurant_special_hours')->where('is_closed', true)->count());

        // Moving a date onto one that already has an exception is refused.
        $first = $this->postJson($this->url('/special-hours'), ['date' => '2026-10-21', 'is_closed' => true])->json('id');
        $this->postJson($this->url('/special-hours'), ['date' => '2026-10-22', 'is_closed' => true])->assertCreated();
        $this->patchJson($this->url('/special-hours/'.$first), ['date' => '2026-10-22', 'is_closed' => true])->assertUnprocessable()->assertJsonValidationErrorFor('date', 'error.details.fields');
    }
}
