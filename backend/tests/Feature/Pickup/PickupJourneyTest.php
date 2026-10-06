<?php

namespace Tests\Feature\Pickup;

use App\Models\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * Arrival-aware pickup: with a journey, the recommended time is the first valid one at or after the later of
 * "the kitchen can have it ready" and "the traveller is expected to arrive". Without a journey it is simply the
 * earliest time. The journey is optional, and a stale one is never relied on.
 */
class PickupJourneyTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:40');
        $this->fakeRouting();
        $this->fakePlaces();
    }

    public function test_without_a_journey_the_earliest_option_is_recommended(): void
    {
        $guest = $this->guestWithBurger();

        $pickup = $this->pickup($guest)->assertOk();

        // 12:40 + 20 min: the kitchen is ready at 13:00.
        $pickup->assertJsonPath('pickup.journey', null)
            ->assertJsonPath('pickup.recommended', ['mode' => 'ASAP', 'slot_key' => null, 'pickup_at' => '2026-10-07T07:30:00+00:00', 'local_pickup_at' => '2026-10-07T13:00:00+05:30', 'basis' => 'KITCHEN', 'arrival_vs_pickup_delta_seconds' => null])
            ->assertJsonPath('pickup.asap.recommended', true)->assertJsonPath('pickup.asap.arrival_vs_pickup_delta_seconds', null);
        $this->assertSame([], array_filter(array_column($pickup->json('pickup.slots'), 'recommended')), 'no slot carries the badge when ASAP is the recommendation');
        $this->assertPickupIssues([], $pickup);

        // With ASAP switched off the first slot is the recommendation — and only that one.
        $this->settings(['asap_enabled' => false]);
        $slots = $this->pickup($guest)->assertOk();
        $slots->assertJsonPath('pickup.recommended.mode', 'SCHEDULED')->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T13:00:00+05:30')->assertJsonPath('pickup.recommended.basis', 'KITCHEN');
        $this->assertSame(['13:00'], collect($slots->json('pickup.slots'))->where('recommended', true)->map(fn (array $s): string => substr($s['local_starts_at'], 11, 5))->values()->all());
    }

    public function test_a_traveller_who_arrives_after_the_food_could_be_ready_is_recommended_a_time_at_their_arrival(): void
    {
        $guest = $this->guestWithBurger();
        // Kitchen ready 13:00, traveller expected 13:25.
        $this->arriveAt($guest, '13:25');

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.journey.status', 'ACTIVE')->assertJsonPath('pickup.journey.requires_refresh', false)
            ->assertJsonPath('pickup.journey.traveler_arrival_at', '2026-10-07T07:55:00+00:00')->assertJsonPath('pickup.journey.local_traveler_arrival_at', '2026-10-07T13:25:00+05:30')
            ->assertJsonPath('pickup.journey.estimate_source', 'development')
            // The first slot at or after 13:25 is 13:30 — not 13:00, which is when the kitchen could be ready.
            ->assertJsonPath('pickup.recommended.mode', 'SCHEDULED')->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T13:30:00+05:30')
            ->assertJsonPath('pickup.recommended.basis', 'ARRIVAL')->assertJsonPath('pickup.recommended.arrival_vs_pickup_delta_seconds', 300)
            ->assertJsonPath('pickup.asap.recommended', false)->assertJsonPath('pickup.asap.available', true)
            // ASAP stays possible; the difference tells the client the food may be ready 25 minutes before the traveller.
            ->assertJsonPath('pickup.asap.arrival_vs_pickup_delta_seconds', -1500);
        $recommended = collect($pickup->json('pickup.slots'))->where('recommended', true)->values();
        $this->assertCount(1, $recommended);
        $this->assertSame('2026-10-07T13:30:00+05:30', $recommended[0]['local_starts_at']);
        // Earlier valid slots are still offered: the customer decides.
        $this->assertSame(['13:00', '13:15', '13:30'], array_slice($this->slotTimes($pickup), 0, 3));
        $this->selectSlot($guest, '13:15')->assertOk()->assertJsonPath('selection.valid', true)->assertJsonPath('selection.arrival_vs_pickup_delta_seconds', -600)
            ->assertJsonPath('selection.traveler_arrival_at', '2026-10-07T07:55:00+00:00');
    }

    public function test_a_traveller_who_arrives_before_the_food_can_be_ready_is_recommended_the_earliest_time(): void
    {
        $guest = $this->guestWithBurger();
        // Traveller expected 12:50, kitchen ready 13:00.
        $this->arriveAt($guest, '12:50');

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.recommended.mode', 'ASAP')->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T13:00:00+05:30')
            ->assertJsonPath('pickup.recommended.basis', 'KITCHEN')
            // Positive: the traveller is there 10 minutes before the estimated ready time.
            ->assertJsonPath('pickup.recommended.arrival_vs_pickup_delta_seconds', 600);
        $this->assertNotContains('12:45', $this->slotTimes($pickup), 'a time before the order can be ready is never offered, whatever the arrival');
    }

    public function test_when_arrival_and_readiness_coincide_that_time_is_recommended(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['asap_enabled' => false]);
        $this->arriveAt($guest, '13:15');
        $this->clock('2026-10-07 12:55');   // kitchen ready 13:15

        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T13:15:00+05:30')
            ->assertJsonPath('pickup.recommended.arrival_vs_pickup_delta_seconds', 0)->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T13:15:00+05:30')->assertJsonPath('pickup.slots.0.recommended', true);
    }

    public function test_a_configured_arrival_buffer_moves_the_recommendation(): void
    {
        $guest = $this->guestWithBurger();
        $this->arriveAt($guest, '13:25');
        config(['pickup.arrival_buffer_minutes' => 10]);

        // 13:25 + 10 = 13:35 → the first slot at or after it is 13:45.
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T13:45:00+05:30')->assertJsonPath('pickup.journey.arrival_buffer_minutes', 10);
    }

    public function test_an_arrival_after_the_last_possible_time_recommends_the_latest_one_and_says_how_much_earlier_it_is(): void
    {
        $guest = $this->guestWithBurger();
        $this->settings(['schedule_horizon_minutes' => 120]);
        $this->arriveAt($guest, '18:00');

        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T14:30:00+05:30')->assertJsonPath('pickup.recommended.basis', 'ARRIVAL')
            ->assertJsonPath('pickup.recommended.arrival_vs_pickup_delta_seconds', -12600);
    }

    public function test_a_stale_journey_is_reported_and_never_used_for_a_recommendation(): void
    {
        $guest = $this->guestWithBurger();
        $journeyId = $this->arriveAt($guest, '13:25');

        // The estimated arrival now lies behind us: the route timing is out of date.
        $this->clock('2026-10-07 13:40');
        $past = $this->pickup($guest)->assertOk();
        $past->assertJsonPath('pickup.journey.status', 'STALE')->assertJsonPath('pickup.journey.requires_refresh', true)->assertJsonPath('pickup.journey.traveler_arrival_at', null)
            ->assertJsonPath('pickup.recommended.basis', 'KITCHEN')->assertJsonPath('pickup.recommended.mode', 'ASAP')->assertJsonPath('pickup.asap.arrival_vs_pickup_delta_seconds', null);
        $this->assertPickupIssues(['JOURNEY_STALE'], $past);
        $past->assertJsonPath('pickup.issues.0.severity', 'INFO')->assertJsonPath('pickup.can_select', true);

        // The journey itself has ended (expired, then pruned): the same answer — and pickup keeps working.
        $this->clock('2026-10-07 12:40');
        Journey::query()->where('public_id', $journeyId)->update(['expires_at' => now()->subMinute()]);
        $expired = $this->pickup($guest)->assertOk();
        $expired->assertJsonPath('pickup.journey.status', 'STALE')->assertJsonPath('pickup.journey.requires_refresh', true)->assertJsonPath('pickup.recommended.basis', 'KITCHEN');
        Journey::query()->delete();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.journey.status', 'STALE')->assertJsonPath('pickup.asap.available', true);
        $this->selectAsap($guest)->assertOk()->assertJsonPath('selection.valid', true)->assertJsonPath('selection.traveler_arrival_at', null);
    }

    public function test_a_journey_without_a_calculated_arrival_is_not_stale_and_invents_nothing(): void
    {
        $guest = $this->guestWithBurger();
        $this->arriveAt($guest, '13:25');
        $cart = $this->cartModel($guest);
        $cart->forceFill(['journey_summary' => [...$cart->journey_summary, 'estimated_arrival_at' => null, 'detour' => null]])->save();

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.journey.status', 'NO_ESTIMATE')->assertJsonPath('pickup.journey.requires_refresh', false)->assertJsonPath('pickup.journey.traveler_arrival_at', null)
            ->assertJsonPath('pickup.recommended.basis', 'KITCHEN');
        $this->assertPickupIssues([], $pickup);
    }

    public function test_a_journey_planned_for_a_later_departure_recommends_a_time_at_that_arrival(): void
    {
        $guest = $this->guestWithBurger();
        // Leaving in the evening: the traveller is expected at 19:10.
        $this->arriveAt($guest, '19:10');

        $pickup = $this->pickup($guest)->assertOk();

        $pickup->assertJsonPath('pickup.recommended.mode', 'SCHEDULED')->assertJsonPath('pickup.recommended.local_pickup_at', '2026-10-07T19:15:00+05:30')
            ->assertJsonPath('pickup.recommended.arrival_vs_pickup_delta_seconds', 300);
        // Nothing about the route itself is part of a pickup answer: no place names, no coordinates, no geometry.
        $body = $pickup->getContent();
        foreach (['West gate', 'East gate', '28.5', '77.3', 'polyline', 'geometry', 'detour', 'origin', 'destination'] as $private) {
            $this->assertStringNotContainsString($private, $body, $private);
        }
    }
}
