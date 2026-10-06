<?php

namespace Tests\Feature\Pickup;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use App\Services\Pickup\PickupAvailabilityService;
use App\Services\Pickup\PickupMetrics;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalPickupFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * The development pickup fixture (a capacity-limited restaurant with used slots) and the administrators' counters.
 */
class PickupFixtureAndMetricsTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    public function test_the_seeded_restaurants_cover_the_pickup_situations_and_pizza_point_has_limited_capacity(): void
    {
        // Wednesday 12:00 in India.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Kolkata'));
        $this->seed(DatabaseSeeder::class);
        $asha = $this->sessionOf(Customer::query()->where('phone_e164', '+919876543212')->firstOrFail());

        // Asha's fixture cart is at Pizza Point: capacity 2 per slot, 13:00 full, 13:15 one place left.
        $pickup = $this->pickup($asha)->assertOk();
        $pickup->assertJsonPath('pickup.restaurant.slug', 'pizza-point')->assertJsonPath('pickup.timezone', 'Asia/Kolkata')->assertJsonPath('pickup.can_select', true)->assertJsonPath('pickup.asap.available', true);
        $by = collect($pickup->json('pickup.slots'))->keyBy(fn (array $s): string => substr($s['local_starts_at'], 0, 16));
        $this->assertSame(['FULL', 'LIMITED', 'AVAILABLE'], [$by['2026-10-07T13:00']['capacity_state'], $by['2026-10-07T13:15']['capacity_state'], $by['2026-10-07T13:30']['capacity_state']]);
        $this->assertSame(['FULL', 'LIMITED'], [$by['2026-10-07T19:00']['capacity_state'], $by['2026-10-07T19:30']['capacity_state']]);
        $this->assertFalse($by['2026-10-07T13:00']['available']);
        $this->selectSlot($asha, '13:15')->assertOk()->assertJsonPath('selection.valid', true)->assertJsonPath('selection.reserved', false);
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $by['2026-10-07T13:00']['slot_key']], $asha)->assertStatus(409)->assertJsonPath('error.code', 'slot_full');

        // The used capacity is fixture data — never an order, never a hold.
        $pizzaPoint = RestaurantLocation::query()->where('slug', 'pizza-point')->firstOrFail();
        $this->assertSame(8, PickupSlotReservation::query()->where('location_id', $pizzaPoint->id)->count());
        $this->assertSame(['FIXTURE'], PickupSlotReservation::query()->distinct()->pluck('source')->all());
        $this->assertSame(2, (int) $pizzaPoint->pickupSettings->capacity_per_slot);

        // Seeding again on the same day changes nothing; on the next day the fixture moves along (today and tomorrow).
        $this->seed(LocalPickupFixtureSeeder::class);
        $this->assertSame(8, PickupSlotReservation::query()->count());
        $version = (int) $pizzaPoint->pickupSettings->refresh()->version;
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 09:00', 'Asia/Kolkata'));
        $this->seed(LocalPickupFixtureSeeder::class);
        $this->assertSame(8, PickupSlotReservation::query()->count());
        $this->assertSame(['2026-10-08', '2026-10-09'], PickupSlotReservation::query()->orderBy('slot_starts_at')->get()->map(fn ($r) => $r->slot_starts_at->setTimezone('Asia/Kolkata')->format('Y-m-d'))->unique()->values()->all());
        $this->assertSame($version, (int) $pizzaPoint->pickupSettings->refresh()->version, 'the settings are not touched again');

        // The other situations come from the restaurant fixtures as they are.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Kolkata'));
        $state = function (string $slug): array {
            $location = RestaurantLocation::query()->where('slug', $slug)->with(['organization', 'market', 'pickupSettings', 'pickupMethods', 'hours', 'specialHours.periods'])->firstOrFail();
            $preview = app(PickupAvailabilityService::class)->preview($location);

            return [$preview->isBlocked() ? $preview->blockingIssue()['code'] : 'OK', $preview->asap['available'], $preview->asap['reason']?->value];
        };
        $this->assertSame(['OK', true, null], $state('burger-hub'));
        $this->assertSame('RESTAURANT_NOT_ACCEPTING', $state('hoshiarpur-sweets-and-snacks')[0]);
        $this->assertSame('RESTAURANT_UNAVAILABLE', $state('wok-express')[0]);
        // Night Owl Kitchen (23:00–04:00) is closed at noon: no ASAP, but tonight can be scheduled.
        $this->assertSame(['OK', false, 'RESTAURANT_CLOSED'], $state('night-owl-kitchen'));
    }

    public function test_the_counters_follow_what_happens_and_hold_no_private_data(): void
    {
        $this->setUpPickupWorld('2026-10-07 12:00');
        $this->fakeRouting();
        $this->fakePlaces();
        $guest = $this->guestWithBurger();
        $this->settings(['capacity_per_slot' => 1]);
        $this->reserve('13:00');

        $this->pickup($guest)->assertOk();                                   // request 1: ASAP possible
        $this->arriveAt($guest, '13:25');
        $this->pickup($guest)->assertOk();                                   // request 2: journey-aware
        $this->selectAsap($guest)->assertOk();
        $this->selectSlot($guest, '13:30')->assertOk();                      // (reads availability through the service, not the endpoint)
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $this->slotKey($guest, '13:00')], $guest)->assertStatus(409);
        $this->reserve('13:30');                                             // somebody else's order fills the chosen slot
        $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection.status', 'INVALID');
        $this->deleteJson('/api/v1/cart/pickup-selection', [], $guest)->assertOk();
        $this->burgerHub->forceFill(['accepting_orders' => false])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false);   // request: blocked

        $report = app(PickupMetrics::class)->report();

        $this->assertSame(1, $report['events']['selected_asap']);
        $this->assertSame(1, $report['events']['selected_scheduled']);
        $this->assertSame(1, $report['events']['refused']);
        // Told "that time is full" twice: once when choosing a full slot, once when the chosen slot filled up.
        $this->assertSame(2, $report['events']['slot_full']);
        $this->assertSame(1, $report['events']['invalidated']);
        $this->assertSame(1, $report['events']['cleared']);
        $this->assertSame(1, $report['events']['availability_blocked']);
        $this->assertGreaterThanOrEqual(3, $report['events']['availability_requests']);
        $this->assertGreaterThanOrEqual(2, $report['events']['asap_available']);
        $this->assertGreaterThanOrEqual(1, $report['events']['journey_aware']);
        $this->assertGreaterThan(50, $report['events']['slots_generated']);
        $this->assertSame(['fixture' => 2, 'hold' => 0, 'order' => 0], $report['upcoming_capacity_units']);
        $this->assertSame('NOT_IMPLEMENTED', $report['slot_hold']);
        $this->assertIsFloat($report['rates']['asap_available']);

        // Through the admin endpoint: the same numbers, and nothing that identifies a cart, a customer or a time.
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $body = $this->getJson('/api/v1/admin/system/pickup')->assertOk()->assertJsonPath('events.selected_asap', 1)->getContent();
        foreach (['13:30', 'ps1_', 'West gate', 'Classic Burger', 'gc1.', 'Burger Hub', '+91'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }

        // An empty window reports rates as unknown, not as zero.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 12:00', 'Asia/Kolkata'));
        $this->assertNull(app(PickupMetrics::class)->report(1)['rates']['asap_available']);
    }
}
