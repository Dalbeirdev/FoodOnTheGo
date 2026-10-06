<?php

namespace Tests\Feature\Pickup;

use App\Models\CartPickupSelection;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * Time zones: every pickup calculation is done on the RESTAURANT's clock — not the server's, not the device's.
 * India runs on Asia/Kolkata; a fixture restaurant in a zone with daylight saving (never a customer market)
 * proves that a missing hour produces no slot and a repeated hour produces two distinguishable ones.
 */
class PickupTimezoneTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    /**
     * A night diner on New York time (open 22:00–06:00) with one item, and a guest with it in the cart.
     *
     * @return array{0: RestaurantLocation, 1: array{X-Cart-Token: string}}
     */
    private function nightDiner(): array
    {
        $diner = $this->eligible('Night Diner', 28.551, 77.351, ['timezone' => 'America/New_York'], hours: [[null, '22:00', '06:00']]);
        $this->settings(['scheduled_enabled' => true, 'default_prep_minutes' => 20, 'minimum_lead_minutes' => 15, 'slot_interval_minutes' => 15, 'schedule_horizon_minutes' => 360], $diner);
        /** @var MenuItem $coffee */
        $coffee = $this->item($this->category($this->menuOf($diner), 'Drinks'), 'Coffee', 9000);
        $guest = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($coffee), $guest)->assertCreated();

        return [$diner, $guest];
    }

    public function test_india_slots_are_calculated_on_the_restaurant_clock_whatever_the_server_zone_is(): void
    {
        $guest = $this->guestWithBurger();
        $expected = $this->pickup($guest)->assertOk()->json('pickup');

        // The server process may run in any zone: the opening hours 09:00–22:00 stay 09:00–22:00 in Asia/Kolkata.
        foreach (['UTC', 'America/Los_Angeles', 'Pacific/Auckland'] as $serverZone) {
            date_default_timezone_set($serverZone);
            config(['app.timezone' => $serverZone]);
            $answer = $this->pickup($guest)->assertOk()->json('pickup');
            $this->assertSame($expected['asap']['local_pickup_at'], $answer['asap']['local_pickup_at'], $serverZone);
            $this->assertSame(array_column($expected['slots'], 'local_starts_at'), array_column($answer['slots'], 'local_starts_at'), $serverZone);
        }
        date_default_timezone_set('UTC');

        $this->assertSame('2026-10-07T12:20:00+05:30', $expected['asap']['local_pickup_at']);
        // 21:45 IST is 16:15 UTC — still the 7th in UTC; tomorrow's 09:00 IST is 03:30 UTC. Grouping uses the restaurant's date.
        $last = collect($expected['slots'])->where('local_date', '2026-10-07')->last();
        $this->assertSame(['2026-10-07T21:45:00+05:30', '2026-10-07T16:15:00+00:00'], [$last['local_starts_at'], $last['starts_at']]);
        $early = collect($expected['slots'])->firstWhere('local_starts_at', '2026-10-08T09:00:00+05:30');
        $this->assertSame(['2026-10-08T03:30:00+00:00', '2026-10-08'], [$early['starts_at'], $early['local_date']]);
    }

    public function test_the_restaurant_local_date_groups_slots_across_utc_midnight(): void
    {
        $guest = $this->guestWithBurger();
        // 04:30 IST on the 8th is 23:00 UTC on the 7th: the restaurant is closed, and its first slot (09:00 IST) belongs to the 8th.
        $this->clock('2026-10-08 04:30');

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.generated_at', '2026-10-07T23:00:00+00:00')->assertJsonPath('pickup.slots.0.local_date', '2026-10-08')->assertJsonPath('pickup.slots.0.starts_at', '2026-10-08T03:30:00+00:00');
        // The 24-hour horizon ends at 04:30 on the 9th, before that day's opening: every slot belongs to the 8th.
        $this->assertSame(['2026-10-08'], array_values(array_unique(array_column($pickup->json('pickup.slots'), 'local_date'))));
        $this->assertSame('21:45', last($this->slotTimes($pickup)));
    }

    public function test_a_selection_keeps_the_zone_it_was_made_in(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk()->assertJsonPath('selection.timezone', 'Asia/Kolkata')->assertJsonPath('selection.local_date', '2026-10-07');

        $stored = CartPickupSelection::query()->firstOrFail();
        // Stored as an instant (UTC) next to the zone it belongs to — never as a local wall-clock string.
        $this->assertSame(['2026-10-07 07:45:00', 'Asia/Kolkata'], [$stored->getRawOriginal('pickup_at') === null ? null : substr((string) $stored->getRawOriginal('pickup_at'), 0, 19), $stored->restaurant_timezone]);
    }

    public function test_when_clocks_go_forward_the_missing_hour_has_no_slots(): void
    {
        // 8 March 2026, New York: 02:00 does not exist — the clock jumps from 01:59:59 EST to 03:00 EDT.
        $this->clock('2026-03-08 00:30', 'America/New_York');
        [$diner, $guest] = $this->nightDiner();

        $pickup = $this->pickup($guest)->assertOk();
        $slots = $pickup->json('pickup.slots');
        $local = array_map(fn (array $s): string => substr($s['local_starts_at'], 11, 14), $slots);

        $pickup->assertJsonPath('pickup.timezone', 'America/New_York')->assertJsonPath('pickup.asap.local_pickup_at', '2026-03-08T00:50:00-05:00');
        $this->assertSame(['01:00:00-05:00', '01:15:00-05:00', '01:30:00-05:00', '01:45:00-05:00', '03:00:00-04:00', '03:15:00-04:00'], array_slice($local, 0, 6));
        $this->assertSame([], array_values(array_filter($local, fn (string $t): bool => str_starts_with($t, '02:'))), 'a local time that does not exist is never offered');
        // In real time the slots are evenly 15 minutes apart, with no gap and no duplicate.
        $instants = array_map(fn (array $s): int => CarbonImmutable::parse($s['starts_at'])->getTimestamp(), $slots);
        $this->assertSame(count($instants), count(array_unique($instants)));
        foreach (array_slice($instants, 1) as $i => $instant) {
            $this->assertSame(900, $instant - $instants[$i]);
        }
        // Closing at 06:00 EDT: 05:45 is the last slot.
        $this->assertSame('05:45:00-04:00', last($local));

        $three = collect($slots)->firstWhere('local_starts_at', '2026-03-08T03:00:00-04:00')['slot_key'];
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $three], $guest)->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-03-08T03:00:00-04:00')->assertJsonPath('selection.pickup_at', '2026-03-08T07:00:00+00:00');
        $this->assertSame($diner->id, CartPickupSelection::query()->firstOrFail()->cart->restaurant_location_id);
    }

    public function test_when_clocks_go_back_the_repeated_hour_gives_two_distinguishable_slots(): void
    {
        // 1 November 2026, New York: 01:00–02:00 happens twice — first on EDT (-04:00), then on EST (-05:00).
        $this->clock('2026-11-01 00:30', 'America/New_York');
        [, $guest] = $this->nightDiner();

        $pickup = $this->pickup($guest)->assertOk();
        $slots = collect($pickup->json('pickup.slots'));
        $local = $slots->map(fn (array $s): string => substr($s['local_starts_at'], 11, 14))->all();

        $this->assertSame(['01:00:00-04:00', '01:15:00-04:00', '01:30:00-04:00', '01:45:00-04:00', '01:00:00-05:00', '01:15:00-05:00', '01:30:00-05:00', '01:45:00-05:00', '02:00:00-05:00'], array_slice($local, 0, 9));
        // Unambiguous: different instants, different keys, same wall-clock reading.
        $first = $slots->firstWhere('local_starts_at', '2026-11-01T01:15:00-04:00');
        $second = $slots->firstWhere('local_starts_at', '2026-11-01T01:15:00-05:00');
        $this->assertSame(['2026-11-01T05:15:00+00:00', '2026-11-01T06:15:00+00:00'], [$first['starts_at'], $second['starts_at']]);
        $this->assertNotSame($first['slot_key'], $second['slot_key']);
        $this->assertSame($slots->count(), $slots->pluck('starts_at')->unique()->count());

        // The customer chooses the SECOND 01:15: exactly that instant is stored and returned.
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $second['slot_key']], $guest)->assertOk()
            ->assertJsonPath('selection.pickup_at', '2026-11-01T06:15:00+00:00')->assertJsonPath('selection.local_pickup_at', '2026-11-01T01:15:00-05:00')->assertJsonPath('selection.valid', true);
        $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-11-01T01:15:00-05:00')->assertJsonPath('selection.status', 'SELECTED');
    }

    public function test_opening_hours_stay_on_the_wall_clock_across_a_clock_change(): void
    {
        // A day restaurant on New York time, 09:00–17:00: the day after clocks went forward it still opens at 09:00 local.
        $cafe = $this->eligible('Day Cafe', 28.552, 77.352, ['timezone' => 'America/New_York'], hours: [[null, '09:00', '17:00']]);
        $this->settings(['scheduled_enabled' => true, 'slot_interval_minutes' => 30, 'schedule_horizon_minutes' => 2880], $cafe);
        $tea = $this->item($this->category($this->menuOf($cafe), 'Drinks'), 'Tea', 6000);
        $guest = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($tea), $guest)->assertCreated();
        $this->clock('2026-03-07 20:00', 'America/New_York');

        $slots = collect($this->pickup($guest)->assertOk()->json('pickup.slots'));

        // Sunday (EDT, -04:00) and Monday both start at 09:00 local; in UTC that is one hour earlier than on Saturday.
        $this->assertSame('2026-03-08T09:00:00-04:00', $slots->firstWhere('local_date', '2026-03-08')['local_starts_at']);
        $this->assertSame('2026-03-08T13:00:00+00:00', $slots->firstWhere('local_date', '2026-03-08')['starts_at']);
        $this->assertSame('2026-03-08T16:30:00-04:00', $slots->where('local_date', '2026-03-08')->last()['local_starts_at']);
        $this->assertSame('2026-03-09T09:00:00-04:00', $slots->firstWhere('local_date', '2026-03-09')['local_starts_at']);
    }
}
