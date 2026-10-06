<?php

namespace Tests\Feature\Pickup;

use App\Models\PickupSlotReservation;
use App\Services\Pickup\PickupCapacityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * Capacity: ORDERS per slot, decided by the backend. A full slot is never offered as available; customers see
 * AVAILABLE / LIMITED / FULL and never a count. Choosing a time reserves nothing — the atomic reservation that
 * checkout and orders will use exists and is tested here, but no customer flow calls it yet.
 */
class PickupCapacityTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    public function test_without_a_configured_capacity_there_is_no_limit(): void
    {
        $guest = $this->guestWithBurger();
        $this->reserve('13:00', 250);

        $pickup = $this->pickup($guest)->assertOk();

        $this->assertNotContains('13:00!', $this->slotTimes($pickup));
        $this->assertSame(['AVAILABLE'], array_values(array_unique(array_column($pickup->json('pickup.slots'), 'capacity_state'))));
    }

    public function test_a_full_slot_is_listed_as_unavailable_and_cannot_be_chosen(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['capacity_per_slot' => 4]);
        $this->reserve('13:00', 4);   // full
        $this->reserve('13:15', 3);   // one place left
        $this->reserve('13:30', 1);   // plenty left

        $pickup = $this->pickup($guest)->assertOk();
        $by = collect($pickup->json('pickup.slots'))->keyBy(fn (array $s): string => substr($s['local_starts_at'], 11, 5));

        $this->assertSame([false, 'FULL', 'SLOT_FULL'], [$by['13:00']['available'], $by['13:00']['capacity_state'], $by['13:00']['reason']]);
        $this->assertSame([true, 'LIMITED', null], [$by['13:15']['available'], $by['13:15']['capacity_state'], $by['13:15']['reason']]);
        $this->assertSame([true, 'AVAILABLE'], [$by['13:30']['available'], $by['13:30']['capacity_state']]);
        $this->assertSame([true, 'AVAILABLE'], [$by['12:45']['available'], $by['12:45']['capacity_state']]);
        // Counts stay with the restaurant: nothing in the answer says "4", "3 left" or how many orders exist.
        $this->assertStringNotContainsString('capacity_per_slot', $pickup->getContent());
        $this->assertStringNotContainsString('remaining', $pickup->getContent());
        $this->assertStringNotContainsString('units', $pickup->getContent());
        $pickup->assertJsonPath('pickup.scheduled.slot_count', count($by))->assertJsonPath('pickup.scheduled.available_slot_count', count($by) - 1);

        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $by['13:00']['slot_key']], $guest)
            ->assertStatus(409)->assertJsonPath('error.code', 'slot_full')->assertJsonPath('error.message', 'That pickup time is no longer available. Please select another time.');
        $this->selectSlot($guest, '13:15')->assertOk()->assertJsonPath('selection.valid', true);
    }

    public function test_a_slot_that_fills_up_after_it_was_chosen_makes_the_selection_invalid(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['capacity_per_slot' => 2]);
        $this->selectSlot($guest, '13:15')->assertOk()->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.reserved', false);

        // Other customers' orders take the slot: choosing it held nothing.
        $this->reserve('13:15', 2);

        $after = $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk();
        $after->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.valid', false)->assertJsonPath('selection.issues.0.code', 'SLOT_FULL')
            ->assertJsonPath('selection.issues.0.message', 'That pickup time is no longer available. Please select another time.');
        // The page shows the same thing in one call.
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.selection.status', 'INVALID');
        // Capacity frees up again (an order is cancelled): the same choice is valid again without choosing anew.
        PickupSlotReservation::query()->update(['status' => PickupSlotReservation::STATUS_RELEASED]);
        $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection.status', 'SELECTED');
    }

    public function test_asap_moves_past_a_full_slot_and_is_gone_when_everything_until_closing_is_full(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['capacity_per_slot' => 1]);

        // ASAP would be 12:20, in the 12:15 slot — which is full, so the earliest valid pickup is 12:30.
        $this->reserve('12:15');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:30:00+05:30')
            ->assertJsonPath('pickup.asap.estimated_ready_in_minutes', 30);

        // Everything until closing is taken: no ASAP, and the slots of today are all full.
        $this->clock('2026-10-07 21:00');
        foreach (['21:15', '21:30', '21:45'] as $time) {
            $this->reserve($time);
        }
        $full = $this->pickup($guest)->assertOk();
        $full->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.asap.reason', 'FULLY_BOOKED');
        $this->assertSame(['21:30!', '21:45!'], $this->slotTimes($full, '2026-10-07'));
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'asap_unavailable')->assertJsonPath('error.details.reason', 'FULLY_BOOKED');
    }

    public function test_fully_booked_is_told_apart_from_closed(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['capacity_per_slot' => 1, 'schedule_horizon_minutes' => 60, 'asap_enabled' => false]);
        foreach (['12:30', '12:45', '13:00'] as $time) {
            $this->reserve($time);
        }

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.scheduled.available_slot_count', 0);
        $this->assertPickupIssues(['NO_PICKUP_SLOTS'], $pickup);
        $pickup->assertJsonPath('pickup.issues.0.details.cause', 'FULLY_BOOKED')->assertJsonPath('pickup.issues.0.message', 'All pickup times are fully booked right now.');
    }

    public function test_capacity_counts_reservations_inside_the_current_slot_grid_and_ignores_released_and_run_out_ones(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['capacity_per_slot' => 2, 'slot_interval_minutes' => 30]);
        // Reserved when slots were 15 minutes long: 13:00 and 13:15 both fall into today's 13:00–13:30 slot.
        $this->reserve('13:00');
        $this->reserve('13:15');
        // A released reservation and a hold that has run out do not count.
        $this->reserve('14:00', 2)->forceFill(['status' => PickupSlotReservation::STATUS_RELEASED])->save();
        $this->reserve('14:30', 2)->forceFill(['source' => PickupSlotReservation::SOURCE_HOLD, 'expires_at' => now()->subMinute()])->save();
        // A hold that is still running does.
        $this->reserve('15:00', 2)->forceFill(['source' => PickupSlotReservation::SOURCE_HOLD, 'expires_at' => now()->addMinutes(5)])->save();

        $times = $this->slotTimes($this->pickup($guest)->assertOk(), '2026-10-07');

        $this->assertContains('13:00!', $times);
        $this->assertContains('14:00', $times);
        $this->assertContains('14:30', $times);
        $this->assertContains('15:00!', $times);
    }

    public function test_the_last_place_of_a_slot_can_be_reserved_only_once(): void
    {
        $capacity = app(PickupCapacityRepository::class);
        $start = $this->localInstant('13:15');
        $end = $start->addMinutes(15);
        $this->reserve('13:15', 2);

        // Capacity 3, two taken: exactly one more reservation succeeds, whoever asks.
        $first = $capacity->reserve($this->burgerHub, $start, $end, 3, 1, PickupSlotReservation::SOURCE_HOLD, 'cart-a', now()->addMinutes(10));
        $second = $capacity->reserve($this->burgerHub, $start, $end, 3, 1, PickupSlotReservation::SOURCE_HOLD, 'cart-b', now()->addMinutes(10));

        $this->assertNotNull($first);
        $this->assertNull($second, 'the slot is full: the second caller gets nothing');
        $this->assertSame(3, (int) PickupSlotReservation::query()->where('status', 'ACTIVE')->sum('units'));

        // Releasing gives the place back; an unlimited slot (no capacity) always has room.
        $capacity->release($first);
        $this->assertNotNull($capacity->reserve($this->burgerHub, $start, $end, 3, 1, PickupSlotReservation::SOURCE_HOLD, 'cart-b', now()->addMinutes(10)));
        $this->assertNotNull($capacity->reserve($this->burgerHub, $start, $end, null, 50, PickupSlotReservation::SOURCE_FIXTURE));
    }

    public function test_two_callers_competing_for_one_slot_are_serialized_by_the_database(): void
    {
        // What makes "count, then insert" one step: a transaction-scoped advisory lock on (location, slot). While one
        // transaction holds it, a second connection cannot get it — it would wait, then count the first one's row.
        $start = $this->localInstant('13:15');
        $key = [(int) $this->burgerHub->id, intdiv($start->getTimestamp(), 60)];
        config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_second');

        try {
            DB::transaction(function () use ($key, $other): void {
                DB::select('select pg_advisory_xact_lock(?, ?)', $key);
                $this->assertFalse((bool) $other->selectOne('select pg_try_advisory_lock(?, ?) as got', $key)->got, 'another connection cannot enter the same slot meanwhile');
                // A different slot of the same restaurant is not blocked.
                $free = [$key[0], $key[1] + 15];
                $this->assertTrue((bool) $other->selectOne('select pg_try_advisory_lock(?, ?) as got', $free)->got);
                $other->select('select pg_advisory_unlock(?, ?)', $free);
            });
        } finally {
            $other->disconnect();
        }
    }

    public function test_choosing_a_time_reserves_nothing(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['capacity_per_slot' => 1]);

        $this->selectSlot($guest, '13:15')->assertOk()->assertJsonPath('selection.reserved', false);

        $this->assertSame(0, PickupSlotReservation::query()->count(), 'a selection is a choice, not a hold');
        // A second customer is still offered — and may choose — the very same last place.
        $second = $this->guestWithBurger();
        $this->selectSlot($second, '13:15')->assertOk()->assertJsonPath('selection.valid', true)->assertJsonPath('selection.reserved', false);
        $this->assertSame(0, PickupSlotReservation::query()->count());
    }
}
