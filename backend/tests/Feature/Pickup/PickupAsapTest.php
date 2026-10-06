<?php

namespace Tests\Feature\Pickup;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * ASAP = the earliest currently valid pickup time — not "immediately". It needs a restaurant that is open now,
 * accepts orders and can have the order ready before it stops handing orders over.
 */
class PickupAsapTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    public function test_asap_is_now_plus_the_preparation_time_on_the_restaurant_clock(): void
    {
        $guest = $this->guestWithBurger();

        $pickup = $this->pickup($guest)->assertOk();

        // Open, preparation 20 min, minimum lead 15 min, 12:00 → 12:20.
        $pickup->assertJsonPath('pickup.asap.enabled', true)->assertJsonPath('pickup.asap.available', true)
            ->assertJsonPath('pickup.asap.pickup_at', '2026-10-07T06:50:00+00:00')
            ->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:20:00+05:30')
            ->assertJsonPath('pickup.asap.estimated_ready_in_minutes', 20)
            ->assertJsonPath('pickup.asap.reason', null)
            ->assertJsonPath('pickup.earliest_pickup_at', '2026-10-07T06:50:00+00:00')
            ->assertJsonPath('pickup.timezone', 'Asia/Kolkata')->assertJsonPath('pickup.restaurant.timezone', 'Asia/Kolkata')
            ->assertJsonPath('pickup.restaurant.id', $this->burgerHub->public_id)
            ->assertJsonPath('pickup.pickup_enabled', true)->assertJsonPath('pickup.can_select', true)
            ->assertJsonPath('pickup.preparation', ['estimated_minutes' => 20, 'buffer_minutes' => 0, 'minimum_lead_minutes' => 15, 'ready_in_minutes' => 20, 'is_estimate' => true])
            ->assertJsonPath('pickup.generated_at', '2026-10-07T06:30:00+00:00')->assertJsonPath('pickup.revalidate_after', '2026-10-07T06:31:00+00:00');
        $this->assertPickupIssues([], $pickup);
    }

    public function test_the_minimum_lead_time_is_a_floor_and_is_never_added_on_top_of_the_preparation_time(): void
    {
        $guest = $this->guestWithBurger();

        // Lead longer than preparation: the lead decides (12:45) — not 20 + 45.
        $this->settings(['minimum_lead_minutes' => 45]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:45:00+05:30')->assertJsonPath('pickup.preparation.ready_in_minutes', 45);

        // Preparation plus buffer longer than the lead: they decide (20 + 10 = 30 → 12:30).
        $this->settings(['minimum_lead_minutes' => 15, 'buffer_minutes' => 10]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:30:00+05:30')
            ->assertJsonPath('pickup.preparation.estimated_minutes', 20)->assertJsonPath('pickup.preparation.buffer_minutes', 10)->assertJsonPath('pickup.preparation.ready_in_minutes', 30);
    }

    public function test_a_time_between_two_minutes_is_rounded_up_never_down(): void
    {
        $guest = $this->guestWithBurger();
        $this->clock('2026-10-07 12:00:25');

        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:21:00+05:30');
    }

    public function test_a_closed_restaurant_has_no_asap_but_still_offers_scheduled_times_from_its_next_opening(): void
    {
        $guest = $this->guestWithBurger();
        $this->clock('2026-10-07 07:30');

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.asap.enabled', true)->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.asap.reason', 'RESTAURANT_CLOSED')
            ->assertJsonPath('pickup.asap.pickup_at', null)->assertJsonPath('pickup.can_select', true)
            // The first slot is the opening time; nothing before it, nothing from the device.
            ->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T09:00:00+05:30')
            ->assertJsonPath('pickup.earliest_pickup_at', '2026-10-07T03:30:00+00:00')
            ->assertJsonPath('pickup.recommended.mode', 'SCHEDULED')->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T09:00:00+05:30');
        $this->assertPickupIssues(['RESTAURANT_CLOSED'], $pickup);
        $pickup->assertJsonPath('pickup.issues.0.severity', 'INFO')->assertJsonPath('pickup.issues.0.details.opens_next_at', '2026-10-07T03:30:00+00:00');

        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'asap_unavailable')->assertJsonPath('error.details.reason', 'RESTAURANT_CLOSED');
    }

    public function test_near_closing_asap_is_gone_when_the_order_cannot_be_ready_before_the_restaurant_stops_handing_over(): void
    {
        $guest = $this->guestWithBurger();

        // 21:39 + 20 min = 21:59 — still before closing at 22:00.
        $this->clock('2026-10-07 21:39');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T21:59:00+05:30');

        // 21:40 + 20 min = 22:00 — closing time itself is not a pickup time.
        $this->clock('2026-10-07 21:40');
        $late = $this->pickup($guest)->assertOk();
        $late->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.asap.reason', 'CLOSES_TOO_SOON')
            // The engine offers the next valid time instead: tomorrow's opening.
            ->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-08T09:00:00+05:30')->assertJsonPath('pickup.can_select', true);
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'asap_unavailable')->assertJsonPath('error.details.reason', 'CLOSES_TOO_SOON');

        // A cut-off before closing moves the last possible time forward: with 30 minutes, 21:10 + 20 = 21:30 is the last.
        $this->settings(['order_cutoff_minutes' => 30]);
        $this->clock('2026-10-07 21:10');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T21:30:00+05:30');
        $this->clock('2026-10-07 21:11');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.asap.reason', 'CLOSES_TOO_SOON');
    }

    public function test_a_special_closure_means_no_asap_and_no_slots_that_day(): void
    {
        $guest = $this->guestWithBurger();
        $this->special($this->burgerHub, '2026-10-07', [], 'Closed for staff training');

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.asap.reason', 'RESTAURANT_CLOSED');
        $this->assertSame([], $this->slotTimes($pickup, '2026-10-07'));
        // Regular hours apply again the next day, inside the 24-hour horizon: 09:00 … 12:00.
        $this->assertSame('09:00', $this->slotTimes($pickup, '2026-10-08')[0]);
        $this->assertSame('12:00', last($this->slotTimes($pickup, '2026-10-08')));
    }

    public function test_asap_follows_the_restaurant_setting_and_the_market(): void
    {
        $guest = $this->guestWithBurger();

        $this->settings(['asap_enabled' => false]);
        $off = $this->pickup($guest)->assertOk();
        $off->assertJsonPath('pickup.asap.enabled', false)->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.asap.reason', 'ASAP_DISABLED')
            ->assertJsonPath('pickup.recommended.mode', 'SCHEDULED')->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T12:30:00+05:30');
        // Not offered at all is an unsupported mode (422), not a temporary state.
        $this->selectAsap($guest)->assertStatus(422)->assertJsonPath('error.code', 'pickup_mode_unavailable');

        // The market switches ASAP off for everybody: the restaurant's own switch no longer matters.
        $this->settings(['asap_enabled' => true]);
        $this->market->forceFill(['features' => [...$this->market->features, 'asap_pickup' => false]])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.enabled', false)->assertJsonPath('pickup.asap.reason', 'ASAP_DISABLED');
    }

    public function test_only_asap_and_closed_or_closing_means_no_pickup_time_at_all_and_says_why(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['scheduled_enabled' => false]);

        $open = $this->pickup($guest)->assertOk();
        $open->assertJsonPath('pickup.scheduled.enabled', false)->assertJsonPath('pickup.slots', [])->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.can_select', true);

        $this->clock('2026-10-07 23:00');
        $closed = $this->pickup($guest)->assertOk();
        $closed->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.asap.reason', 'RESTAURANT_CLOSED');
        $this->assertPickupIssues(['RESTAURANT_CLOSED', 'NO_PICKUP_SLOTS'], $closed);
        $this->assertSame('RESTAURANT_CLOSED', collect($closed->json('pickup.issues'))->firstWhere('code', 'NO_PICKUP_SLOTS')['details']['cause']);

        $this->clock('2026-10-07 21:50');
        $closing = $this->pickup($guest)->assertOk();
        $this->assertSame('CLOSES_TOO_SOON', collect($closing->json('pickup.issues'))->firstWhere('code', 'NO_PICKUP_SLOTS')['details']['cause']);
    }
}
