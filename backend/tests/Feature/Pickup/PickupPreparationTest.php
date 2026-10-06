<?php

namespace Tests\Feature\Pickup;

use App\Models\RestaurantPickupSettings;
use App\Services\Pickup\PreparationTimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * The preparation estimate: the restaurant's default or the slowest item (never the sum of the items), plus a
 * configurable step for large orders. An estimate, not a promise.
 */
class PickupPreparationTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    public function test_the_default_preparation_time_comes_from_the_restaurant_settings(): void
    {
        $guest = $this->guestWithBurger();

        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 20)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:20:00+05:30');

        $this->settings(['default_prep_minutes' => 35]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 35)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:35:00+05:30')
            ->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T12:45:00+05:30');
    }

    public function test_the_slowest_item_raises_the_estimate_and_item_times_are_never_summed(): void
    {
        $biryani = $this->item($this->mains, 'Dum Biryani', 32000, [], ['preparation_minutes' => 40]);
        $kebab = $this->item($this->mains, 'Seekh Kebab', 26000, [], ['preparation_minutes' => 25]);
        $quick = $this->item($this->mains, 'Lassi', 8000, [], ['preparation_minutes' => 5]);
        $guest = $this->guest();

        // A quick item alone: the restaurant's default (20) is the floor.
        $this->postJson('/api/v1/cart/items', $this->addBody($quick), $guest)->assertCreated();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 20);

        // 25-minute item: 25. Then a 40-minute item as well: 40 — not 25 + 40 + 5, the kitchen works in parallel.
        $this->postJson('/api/v1/cart/items', $this->addBody($kebab), $guest)->assertCreated();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 25);
        $this->postJson('/api/v1/cart/items', $this->addBody($biryani), $guest)->assertCreated();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 40)
            ->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:40:00+05:30')->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T12:45:00+05:30');
    }

    public function test_a_large_order_adds_time_in_configured_steps(): void
    {
        config(['pickup.preparation.quantity' => ['free_items' => 10, 'items_per_step' => 10, 'minutes_per_step' => 5, 'max_extra_minutes' => 30]]);
        $service = app(PreparationTimeService::class);
        $settings = RestaurantPickupSettings::query()->where('location_id', $this->burgerHub->id)->firstOrFail();
        $extra = fn (int $quantity): int => $service->estimate($settings, [[null, $quantity]])->quantityExtraMinutes;

        $this->assertSame([0, 0, 5, 5, 10, 30, 30], [$extra(1), $extra(10), $extra(11), $extra(20), $extra(21), $extra(70), $extra(500)]);

        // Through the API: 12 burgers = one step above the 10 free units.
        $guest = $this->guestWithBurger(12);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 25)->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:25:00+05:30');

        // Switched off by configuration: the quantity no longer matters.
        config(['pickup.preparation.quantity.minutes_per_step' => 0]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 20);
    }

    public function test_the_estimate_is_bounded_and_explains_itself_to_staff_only(): void
    {
        $service = app(PreparationTimeService::class);
        $settings = RestaurantPickupSettings::query()->where('location_id', $this->burgerHub->id)->firstOrFail();
        config(['pickup.preparation.max_minutes' => 120]);

        $estimate = $service->estimate($settings, [[400, 1], [30, 2]]);

        $this->assertSame([120, 400, 20, 3], [$estimate->minutes, $estimate->itemMinutes, $estimate->defaultMinutes, $estimate->totalQuantity]);
        // Customers get the estimate and its bounds; which item is slow and how quantity counts is the restaurant's business.
        $this->assertSame(['estimated_minutes', 'buffer_minutes', 'minimum_lead_minutes', 'ready_in_minutes', 'is_estimate'], array_keys($estimate->forCustomers()));
        $this->assertArrayHasKey('slowest_item_minutes', $estimate->forStaff());
    }
}
