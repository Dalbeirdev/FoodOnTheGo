<?php

namespace Tests\Feature\Pickup;

use App\Models\RestaurantLocationHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * Scheduled slots: generated from the restaurant's opening hours in the restaurant's time zone — on a grid, after
 * the order can be ready, inside the scheduling horizon, never in a closed gap and never at or after closing.
 */
class PickupSlotGenerationTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    /**
     * Replaces the weekly hours of the standard restaurant.
     *
     * @param  list<array{0: int|list<int>|null, 1: string, 2: string}>  $periods
     */
    private function open(array $periods): void
    {
        RestaurantLocationHour::query()->where('location_id', $this->burgerHub->id)->delete();
        $this->hours($this->burgerHub, $periods);
    }

    public function test_slots_follow_the_configured_interval_from_the_earliest_ready_time_to_the_last_time_before_closing(): void
    {
        $guest = $this->guestWithBurger();

        $pickup = $this->pickup($guest)->assertOk();
        $today = $this->slotTimes($pickup, '2026-10-07');

        // Ready at 12:20 → the first slot on the 15-minute grid is 12:30; closing at 22:00 is not a slot, 21:45 is the last.
        $this->assertSame(['12:30', '12:45', '13:00', '13:15'], array_slice($today, 0, 4));
        $this->assertSame('21:45', last($today));
        $this->assertCount(38, $today);
        $pickup->assertJsonPath('pickup.scheduled.enabled', true)->assertJsonPath('pickup.scheduled.interval_minutes', 15)->assertJsonPath('pickup.scheduled.horizon_minutes', 1440)
            ->assertJsonPath('pickup.scheduled.horizon_ends_at', '2026-10-08T06:30:00+00:00')->assertJsonPath('pickup.scheduled.truncated', false);

        // One slot as a client sees it: instants, the restaurant-local reading with its offset, a key — no label, no count.
        $slot = $pickup->json('pickup.slots.0');
        $this->assertSame(['slot_key', 'starts_at', 'ends_at', 'local_starts_at', 'local_date', 'available', 'capacity_state', 'recommended', 'reason', 'arrival_vs_pickup_delta_seconds'], array_keys($slot));
        $this->assertSame(['2026-10-07T07:00:00+00:00', '2026-10-07T07:15:00+00:00', '2026-10-07T12:30:00+05:30', '2026-10-07', true, 'AVAILABLE', null], [$slot['starts_at'], $slot['ends_at'], $slot['local_starts_at'], $slot['local_date'], $slot['available'], $slot['capacity_state'], $slot['reason']]);
        $this->assertMatchesRegularExpression('/^ps1_[0-9a-z]+_15_[0-9a-f]{12}$/', $slot['slot_key']);

        // Another interval, another grid.
        $this->settings(['slot_interval_minutes' => 30]);
        $half = $this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07');
        $this->assertSame(['12:30', '13:00', '13:30'], array_slice($half, 0, 3));
        $this->assertSame('21:30', last($half));

        $this->settings(['slot_interval_minutes' => 5]);
        $this->assertSame(['12:20', '12:25', '12:30'], array_slice($this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07'), 0, 3));
    }

    public function test_slots_start_on_the_opening_time_when_it_lies_on_the_grid(): void
    {
        $guest = $this->guestWithBurger();
        $this->open([[null, '10:00', '22:00']]);
        $this->clock('2026-10-07 08:00');

        $this->assertSame(['10:00', '10:15', '10:30', '10:45'], array_slice($this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07'), 0, 4));
    }

    public function test_an_opening_time_off_the_grid_rounds_up_to_the_next_grid_time(): void
    {
        $guest = $this->guestWithBurger();
        $this->open([[null, '10:07', '22:00']]);
        $this->clock('2026-10-07 08:00');

        // The grid counts from local midnight: 10:07 → 10:15 (never 10:07, never 10:22).
        $this->assertSame(['10:15', '10:30', '10:45'], array_slice($this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07'), 0, 3));

        $this->settings(['slot_interval_minutes' => 30]);
        $this->assertSame(['10:30', '11:00'], array_slice($this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07'), 0, 2));
    }

    public function test_nothing_is_offered_beyond_the_scheduling_horizon(): void
    {
        $guest = $this->guestWithBurger();

        // 24 hours: until 12:00 tomorrow, and not a minute later.
        $day = $this->pickup($guest)->assertOk();
        $this->assertSame('12:00', last($this->slotTimes($day, '2026-10-08')));
        $this->assertSame([], $this->slotTimes($day, '2026-10-09'));

        // Three hours: until 15:00 today.
        $this->settings(['schedule_horizon_minutes' => 180]);
        $short = $this->pickup($guest)->assertOk();
        $this->assertSame(['12:30', '12:45', '13:00', '13:15', '13:30', '13:45', '14:00', '14:15', '14:30', '14:45', '15:00'], $this->slotTimes($short));

        // A slot beyond the horizon cannot be chosen either, even with a key this backend issued earlier.
        $this->settings(['schedule_horizon_minutes' => 1440]);
        $far = $this->slotKey($guest, '2026-10-08 11:00');
        $this->settings(['schedule_horizon_minutes' => 180]);
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $far], $guest)->assertStatus(422)->assertJsonPath('error.code', 'slot_outside_horizon');
    }

    public function test_several_opening_periods_a_day_leave_the_gap_without_slots(): void
    {
        $guest = $this->guestWithBurger();
        $this->open([[null, '11:00', '14:00'], [null, '17:00', '22:00']]);
        $this->clock('2026-10-07 10:00');

        $times = $this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07');

        $this->assertSame('11:00', $times[0]);
        $this->assertContains('13:45', $times);
        $this->assertContains('17:00', $times);
        foreach (['14:00', '14:15', '15:30', '16:45'] as $closed) {
            $this->assertNotContains($closed, $times, "{$closed} lies in the closed gap");
        }
        $this->assertSame(['13:45', '17:00'], [$times[array_search('17:00', $times, true) - 1], '17:00']);

        // In the gap the restaurant is closed: no ASAP, the afternoon opening is next.
        $this->clock('2026-10-07 15:00');
        $gap = $this->pickup($guest)->assertOk();
        $gap->assertJsonPath('pickup.asap.reason', 'RESTAURANT_CLOSED')->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T17:00:00+05:30');

        // Shortly before the lunch closing ASAP no longer fits, but the evening does.
        $this->clock('2026-10-07 13:45');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.reason', 'CLOSES_TOO_SOON')->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T17:00:00+05:30');
    }

    public function test_overnight_hours_continue_past_midnight_into_the_next_calendar_day(): void
    {
        $guest = $this->guestWithBurger();
        $this->open([[null, '18:00', '02:00']]);
        $this->clock('2026-10-07 23:00');

        $pickup = $this->pickup($guest)->assertOk();

        // Open now (the period that started at 18:00), ASAP at 23:20.
        $pickup->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T23:20:00+05:30');
        $this->assertSame(['23:30', '23:45'], $this->slotTimes($pickup, '2026-10-07'));
        // After midnight the same service continues on the next calendar date, until 01:45 — then nothing until 18:00.
        $next = $this->slotTimes($pickup, '2026-10-08');
        $this->assertSame(['00:00', '00:15', '00:30', '00:45', '01:00', '01:15', '01:30', '01:45', '18:00'], array_slice($next, 0, 9));
        $this->assertNotContains('02:00', $next);
        $this->assertNotContains('09:00', $next);

        // After midnight the restaurant is still open on yesterday's period.
        $this->clock('2026-10-08 01:10');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-08T01:30:00+05:30');
        $this->clock('2026-10-08 01:45');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.asap.reason', 'CLOSES_TOO_SOON');
    }

    public function test_special_hours_replace_the_regular_hours_of_their_date(): void
    {
        $guest = $this->guestWithBurger();
        // Today: a late opening and a late close instead of 09:00–22:00.
        $this->special($this->burgerHub, '2026-10-07', [['14:00', '23:30']], 'Festival hours');

        $pickup = $this->pickup($guest)->assertOk();

        // 12:00 is before the special opening: closed now, first slot at 14:00, last at 23:15.
        $pickup->assertJsonPath('pickup.asap.reason', 'RESTAURANT_CLOSED');
        $today = $this->slotTimes($pickup, '2026-10-07');
        $this->assertSame('14:00', $today[0]);
        $this->assertSame('23:15', last($today));
        // Tomorrow is regular again.
        $this->assertSame('09:00', $this->slotTimes($pickup, '2026-10-08')[0]);
    }

    public function test_a_closed_weekday_has_no_slots(): void
    {
        $guest = $this->guestWithBurger();
        // Closed on Thursdays (2026-10-08).
        $this->open([[[0, 1, 2, 3, 5, 6], '09:00', '22:00']]);
        $this->settings(['schedule_horizon_minutes' => 2880]);

        $pickup = $this->pickup($guest)->assertOk();

        $this->assertNotSame([], $this->slotTimes($pickup, '2026-10-07'));
        $this->assertSame([], $this->slotTimes($pickup, '2026-10-08'));
        $this->assertSame('09:00', $this->slotTimes($pickup, '2026-10-09')[0]);
    }

    public function test_the_cut_off_before_closing_removes_the_last_slots(): void
    {
        $guest = $this->guestWithBurger();

        $this->settings(['order_cutoff_minutes' => 30]);
        $this->assertSame('21:30', last($this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07')));

        $this->settings(['order_cutoff_minutes' => 20]);
        $this->assertSame('21:30', last($this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07')), 'the last slot is the last grid time at or before 21:40');
    }

    public function test_the_number_of_slots_in_one_answer_is_bounded(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['schedule_horizon_minutes' => 10080, 'slot_interval_minutes' => 5]);
        config(['pickup.limits.max_slots' => 100]);

        $pickup = $this->pickup($guest)->assertOk();

        $this->assertCount(100, $pickup->json('pickup.slots'));
        $pickup->assertJsonPath('pickup.scheduled.truncated', true)->assertJsonPath('pickup.scheduled.slot_count', 100)->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T12:20:00+05:30');
    }

    public function test_scheduled_pickup_follows_the_restaurant_setting(): void
    {
        $guest = $this->guestWithBurger();
        $key = $this->slotKey($guest, '13:00');

        $this->settings(['scheduled_enabled' => false]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.scheduled.enabled', false)->assertJsonPath('pickup.slots', [])->assertJsonPath('pickup.asap.available', true);
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $key], $guest)->assertStatus(422)->assertJsonPath('error.code', 'pickup_mode_unavailable');
    }
}
