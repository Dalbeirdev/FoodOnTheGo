<?php

namespace Tests\Feature\Pickup;

use App\Models\RestaurantLocationHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * The restaurant's side: what staff save in the Restaurant Dashboard (pickup settings, pause) is what the
 * customer's pickup engine uses on the very next request; and the dashboard's preview is that same engine,
 * with warnings when a configuration would offer customers nothing.
 */
class PickupDashboardTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    private function url(string $path): string
    {
        return '/api/v1/restaurant/locations/'.$this->burgerHub->public_id.$path;
    }

    /**
     * Saves pickup settings through the dashboard API, as the restaurant's owner.
     *
     * @param  array<string, mixed>  $changes
     */
    private function save(array $changes): TestResponse
    {
        $this->actingAsPrincipal($this->staff());
        $version = $this->getJson($this->url('/pickup-settings'))->assertOk()->json('version');
        $response = $this->patchJson($this->url('/pickup-settings'), ['version' => $version, ...$changes]);
        $this->assertSame(200, $response->status(), json_encode($changes).' → '.$response->getContent());
        $this->app['auth']->forgetGuards();
        $this->burgerHub->unsetRelation('pickupSettings');

        return $response;
    }

    private function preview(): TestResponse
    {
        $this->actingAsPrincipal($this->staff());
        $response = $this->getJson($this->url('/pickup-preview'));
        $this->app['auth']->forgetGuards();

        return $response;
    }

    public function test_settings_saved_in_the_dashboard_change_what_the_customer_is_offered(): void
    {
        $guest = $this->guestWithBurger();
        $this->assertSame(['12:30', '12:45', '13:00'], array_slice($this->slotTimes($this->pickup($guest)->assertOk()), 0, 3));

        // Slot interval 15 → 30.
        $this->save(['slot_interval_minutes' => 30]);
        $this->assertSame(['12:30', '13:00', '13:30'], array_slice($this->slotTimes($this->pickup($guest)->assertOk()), 0, 3));

        // Preparation time 20 → 45: ASAP and the first slot move.
        $this->save(['default_prep_minutes' => 45]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T12:45:00+05:30')->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T13:00:00+05:30')
            ->assertJsonPath('pickup.preparation.estimated_minutes', 45);

        // Lead time, buffer and the cut-off before closing.
        $this->save(['default_prep_minutes' => 20, 'minimum_lead_minutes' => 60, 'buffer_minutes' => 5, 'order_cutoff_minutes' => 60]);
        $timing = $this->pickup($guest)->assertOk();
        $timing->assertJsonPath('pickup.asap.local_pickup_at', '2026-10-07T13:00:00+05:30')->assertJsonPath('pickup.preparation.ready_in_minutes', 60);
        $this->assertSame('21:00', last($this->slotTimes($timing, '2026-10-07')));

        // ASAP off: the customer no longer sees ASAP. Scheduled off instead: no slots.
        $this->save(['minimum_lead_minutes' => 15, 'buffer_minutes' => 0, 'order_cutoff_minutes' => 0, 'asap_enabled' => false]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.enabled', false)->assertJsonPath('pickup.scheduled.enabled', true);
        $this->save(['asap_enabled' => true, 'scheduled_enabled' => false]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.scheduled.enabled', false)->assertJsonPath('pickup.slots', []);

        // Scheduling horizon and capacity.
        $this->save(['scheduled_enabled' => true, 'schedule_horizon_minutes' => 120, 'capacity_per_slot' => 1]);
        $this->reserve('13:00');
        $limited = $this->pickup($guest)->assertOk();
        $this->assertSame(['12:30', '13:00!', '13:30', '14:00'], $this->slotTimes($limited));
        // Capacity removed again: no limit.
        $this->save(['capacity_per_slot' => null]);
        $this->assertSame(['12:30', '13:00', '13:30', '14:00'], $this->slotTimes($this->pickup($guest)->assertOk()));

        // Pickup switched off in the dashboard.
        $this->save(['pickup_enabled' => false]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'PICKUP_DISABLED');
    }

    public function test_pausing_orders_in_the_dashboard_blocks_pickup_and_resuming_restores_it(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectAsap($guest)->assertOk();

        $this->actingAsPrincipal($this->staff());
        $this->patchJson($this->url('/availability'), ['accepting_orders' => false, 'pause_reason' => 'Short of staff', 'paused_until' => now()->addMinutes(30)->toIso8601String()])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'RESTAURANT_NOT_ACCEPTING')
            ->assertJsonPath('pickup.issues.0.details.resumes_at', '2026-10-07T07:00:00+00:00')->assertJsonPath('pickup.selection.status', 'INVALID');

        $this->actingAsPrincipal($this->staff());
        $this->patchJson($this->url('/availability'), ['accepting_orders' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', true)->assertJsonPath('pickup.selection.status', 'SELECTED');
    }

    public function test_the_preview_is_the_customer_engine_for_an_order_with_the_default_preparation_time(): void
    {
        $guest = $this->guestWithBurger();
        $customer = $this->pickup($guest)->assertOk();

        $preview = $this->preview()->assertOk();

        $preview->assertJsonPath('preview.timezone', 'Asia/Kolkata')->assertJsonPath('preview.pickup_enabled', true)->assertJsonPath('preview.customers_can_order', true)
            ->assertJsonPath('preview.asap.available', true)->assertJsonPath('preview.asap.pickup_at', $customer->json('pickup.asap.pickup_at'))
            ->assertJsonPath('preview.earliest_pickup_at', $customer->json('pickup.earliest_pickup_at'))
            ->assertJsonPath('preview.scheduled.slot_count', $customer->json('pickup.scheduled.slot_count'))->assertJsonPath('preview.scheduled.interval_minutes', 15)
            ->assertJsonPath('preview.capacity_per_slot', null)->assertJsonPath('preview.capacity_unit', 'ORDERS')->assertJsonPath('preview.warnings', [])
            // Staff see how the estimate is put together; customers do not.
            ->assertJsonPath('preview.preparation.default_minutes', 20)->assertJsonPath('preview.preparation.slowest_item_minutes', 0)->assertJsonPath('preview.preparation.quantity_extra_minutes', 0);
        // Per restaurant-local day: how many slots, the first and the last.
        $preview->assertJsonPath('preview.scheduled.days.0', ['local_date' => '2026-10-07', 'slots' => 38, 'available' => 38, 'first_slot_at' => '2026-10-07T07:00:00+00:00', 'last_slot_at' => '2026-10-07T16:15:00+00:00'])
            ->assertJsonPath('preview.scheduled.days.1.local_date', '2026-10-08');
        $this->assertArrayNotHasKey('slots', $preview->json('preview'));
    }

    public function test_the_preview_warns_about_a_configuration_that_offers_customers_nothing(): void
    {
        // Preparation and lead time longer than the opening period: ASAP can never be offered.
        RestaurantLocationHour::query()->where('location_id', $this->burgerHub->id)->delete();
        $this->hours($this->burgerHub, [[null, '12:00', '13:00']]);
        $this->settings(['default_prep_minutes' => 90]);
        $never = $this->preview()->assertOk();
        $this->assertSame(['ASAP_NEVER_POSSIBLE'], array_column($never->json('preview.warnings'), 'code'));
        $this->assertStringContainsString('ASAP pickup can never be offered', $never->json('preview.warnings.0.message'));

        // A cut-off longer than the opening period: nothing at all.
        $this->settings(['default_prep_minutes' => 20, 'order_cutoff_minutes' => 90]);
        $cutoff = $this->preview()->assertOk();
        $this->assertSame(['CUTOFF_LONGER_THAN_OPENING', 'NO_PICKUP_TIMES'], array_column($cutoff->json('preview.warnings'), 'code'));
        $cutoff->assertJsonPath('preview.customers_can_order', false);

        // No opening hours at all.
        RestaurantLocationHour::query()->where('location_id', $this->burgerHub->id)->delete();
        $this->burgerHub->unsetRelation('hours');
        $this->settings(['order_cutoff_minutes' => 0]);
        $this->assertContains('NO_OPENING_HOURS', array_column($this->preview()->assertOk()->json('preview.warnings'), 'code'));

        // Every slot at capacity.
        $this->hours($this->burgerHub, [[null, '09:00', '22:00']]);
        $this->settings(['capacity_per_slot' => 1, 'schedule_horizon_minutes' => 45, 'asap_enabled' => false]);
        foreach (['12:30', '12:45'] as $time) {
            $this->reserve($time);
        }
        $this->assertSame(['ALL_SLOTS_FULL'], array_column($this->preview()->assertOk()->json('preview.warnings'), 'code'));

        // Paused: the preview says customers cannot order and why, without inventing a warning about the settings.
        $this->settings(['capacity_per_slot' => null, 'asap_enabled' => true]);
        $this->burgerHub->forceFill(['accepting_orders' => false])->save();
        $paused = $this->preview()->assertOk();
        $paused->assertJsonPath('preview.customers_can_order', false)->assertJsonPath('preview.issues.0.code', 'RESTAURANT_NOT_ACCEPTING')->assertJsonPath('preview.warnings', []);
    }

    public function test_the_preview_is_only_for_the_restaurants_own_staff(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url('/pickup-preview'))->assertStatus(401);

        // A customer's session is not a staff session.
        [, $session] = $this->signedIn();
        $this->getJson($this->url('/pickup-preview'), $session)->assertStatus(401);

        // Staff of another organization.
        $other = $this->member($this->organization('Other Kitchens'));
        $this->actingAsPrincipal($other);
        $this->assertContains($this->getJson($this->url('/pickup-preview'))->status(), [403, 404]);

        $this->actingAsPrincipal($this->staff());
        $this->getJson($this->url('/pickup-preview'))->assertOk();
        // Read-only: nothing can be changed through it.
        $this->postJson($this->url('/pickup-preview'), ['capacity_per_slot' => 1])->assertStatus(405);
    }
}
