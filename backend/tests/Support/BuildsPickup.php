<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPickupSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Pickup availability for the Module 28 tests, on top of the cart world.
 *
 * The standard restaurant is "Burger Hub" in Asia/Kolkata, open 09:00–22:00 every day, with ASAP and scheduled
 * pickup, counter pickup, preparation 20 min, minimum lead 15 min, no buffer, 15-minute slots, a 24-hour
 * scheduling horizon, no cut-off before closing and no capacity limit. Its menu: Classic Burger and Masala Fries
 * without a preparation time of their own.
 *
 * The clock is always fixed (`clock()`): 2026-10-07 is a Wednesday.
 */
trait BuildsPickup
{
    use BuildsCarts;

    protected const ZONE = 'Asia/Kolkata';

    protected function setUpPickupWorld(string $now = '2026-10-07 12:00'): void
    {
        $this->clock($now);
        $this->setUpCartWorld();
        $this->settings(['scheduled_enabled' => true, 'default_prep_minutes' => 20, 'minimum_lead_minutes' => 15, 'buffer_minutes' => 0, 'slot_interval_minutes' => 15, 'schedule_horizon_minutes' => 1440, 'order_cutoff_minutes' => 0, 'capacity_per_slot' => null]);
    }

    /**
     * Freezes the server clock at a wall-clock time of the given zone.
     */
    protected function clock(string $local, string $zone = self::ZONE): CarbonImmutable
    {
        $now = CarbonImmutable::parse($local, $zone);
        Carbon::setTestNow($now);

        return $now;
    }

    /**
     * Changes pickup settings the way a saved change does: the version goes up.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function settings(array $attributes, ?RestaurantLocation $location = null): RestaurantPickupSettings
    {
        $location ??= $this->burgerHub;
        $settings = RestaurantPickupSettings::query()->where('location_id', $location->id)->firstOrFail();
        $settings->forceFill([...$attributes, 'version' => (int) $settings->version + 1])->save();
        $location->unsetRelation('pickupSettings');

        return $settings;
    }

    /**
     * A guest with one Regular burger (or more) in the cart.
     *
     * @return array{X-Cart-Token: string}
     */
    protected function guestWithBurger(int $quantity = 1): array
    {
        $guest = $this->guest();
        $this->addBurger($guest, $quantity)->assertCreated();

        return $guest;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $query
     */
    protected function pickup(array $headers, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/cart/pickup-availability'.($query === [] ? '' : '?'.http_build_query($query)), $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    protected function selectAsap(array $headers, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'ASAP', ...$extra], $headers);
    }

    /**
     * Chooses the slot that starts at the restaurant-local time ("13:15", optionally with a date "2026-10-08 09:00").
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    protected function selectSlot(array $headers, string $local, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $this->slotKey($headers, $local), ...$extra], $headers);
    }

    /**
     * The key of the slot at a restaurant-local time, as the availability answer offers it.
     *
     * @param  array<string, string>  $headers
     */
    protected function slotKey(array $headers, string $local): string
    {
        $wanted = $this->localInstant($local);
        foreach ((array) $this->pickup($headers)->assertOk()->json('pickup.slots') as $slot) {
            if (CarbonImmutable::parse($slot['starts_at'])->equalTo($wanted)) {
                return $slot['slot_key'];
            }
        }
        $this->fail("No slot at {$local} is offered.");
    }

    /**
     * Restaurant-local start times of the offered slots ("H:i"), optionally of one local date; a full slot is
     * suffixed with "!" unless only available ones are asked for.
     *
     * @return list<string>
     */
    protected function slotTimes(TestResponse $response, ?string $date = null, bool $onlyAvailable = false): array
    {
        $times = [];
        foreach ((array) $response->json('pickup.slots') as $slot) {
            if (($date !== null && $slot['local_date'] !== $date) || ($onlyAvailable && ! $slot['available'])) {
                continue;
            }
            $times[] = substr($slot['local_starts_at'], 11, 5).($slot['available'] || $onlyAvailable ? '' : '!');
        }

        return $times;
    }

    /**
     * Uses capacity of a slot the way an order will (development / test record).
     */
    protected function reserve(string $local, int $units = 1, ?RestaurantLocation $location = null): PickupSlotReservation
    {
        $reservation = (new PickupSlotReservation)->forceFill([
            'location_id' => ($location ?? $this->burgerHub)->id, 'slot_starts_at' => $this->localInstant($local)->utc(),
            'units' => $units, 'source' => PickupSlotReservation::SOURCE_FIXTURE, 'status' => PickupSlotReservation::STATUS_ACTIVE,
        ]);
        $reservation->save();

        return $reservation;
    }

    /**
     * Links the caller's cart to a living journey whose traveller is expected at the restaurant at the given
     * restaurant-local time — the figure route discovery would have calculated.
     *
     * @param  array<string, string>  $headers  the cart owner's headers
     */
    protected function arriveAt(array $headers, string $local): string
    {
        $journey = $this->planGuest();
        $cart = $this->cartModel($headers);
        $cart->forceFill([
            'journey_public_id' => $journey['id'],
            'journey_summary' => [
                'origin' => 'West gate', 'destination' => 'East gate', 'restaurant_on_route' => true, 'distance_from_route_meters' => 40,
                'detour' => ['duration_seconds' => 240, 'distance_meters' => 1800],
                'estimated_arrival_at' => $this->localInstant($local)->utc()->toIso8601String(),
                'provider_mode' => 'development', 'linked_at' => now()->utc()->toIso8601String(),
            ],
        ])->save();

        return $journey['id'];
    }

    /**
     * The cart a set of headers addresses.
     *
     * @param  array<string, string>  $headers
     */
    protected function cartModel(array $headers): Cart
    {
        return Cart::query()->where('public_id', $this->cartOf($headers)->json('cart.id'))->firstOrFail();
    }

    /**
     * "13:15" today (on the frozen clock) or "2026-10-08 09:00", on the restaurant's clock.
     */
    protected function localInstant(string $local, string $zone = self::ZONE): CarbonImmutable
    {
        return strlen($local) <= 5
            ? CarbonImmutable::now($zone)->setTimeFromTimeString($local)->startOfMinute()
            : CarbonImmutable::parse($local, $zone);
    }

    /**
     * An instant as the API prints it on the restaurant's clock.
     */
    protected function localIso(string $local, string $zone = self::ZONE): string
    {
        return $this->localInstant($local, $zone)->setTimezone($zone)->toIso8601String();
    }

    /**
     * @param  list<string>  $codes
     */
    protected function assertPickupIssues(array $codes, TestResponse $response, string $path = 'pickup.issues'): void
    {
        $this->assertEqualsCanonicalizing($codes, array_column((array) $response->json($path), 'code'));
    }
}
