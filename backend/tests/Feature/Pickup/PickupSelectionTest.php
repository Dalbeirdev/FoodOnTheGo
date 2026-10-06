<?php

namespace Tests\Feature\Pickup;

use App\Enums\MenuItemStatus;
use App\Models\Cart;
use App\Models\CartPickupSelection;
use App\Models\RestaurantLocationHour;
use App\Models\RestaurantLocationPickupMethod;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * The pickup time a customer chooses for their cart: explicit mode, one per cart, validated by the backend when
 * it is chosen and again on every read — after the cart changed, after the restaurant changed, after time passed.
 */
class PickupSelectionTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    private function selection(array $headers): TestResponse
    {
        return $this->getJson('/api/v1/cart/pickup-selection', $headers)->assertOk();
    }

    public function test_asap_can_be_chosen_and_follows_the_clock(): void
    {
        $guest = $this->guestWithBurger();
        $this->selection($guest)->assertJsonPath('selection', null);

        $chosen = $this->selectAsap($guest)->assertOk();

        $chosen->assertJsonPath('selection.mode', 'ASAP')->assertJsonPath('selection.method', 'COUNTER')->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.valid', true)
            ->assertJsonPath('selection.reserved', false)->assertJsonPath('selection.slot_key', null)->assertJsonPath('selection.slot_ends_at', null)
            ->assertJsonPath('selection.pickup_at', '2026-10-07T06:50:00+00:00')->assertJsonPath('selection.local_pickup_at', '2026-10-07T12:20:00+05:30')->assertJsonPath('selection.local_date', '2026-10-07')
            ->assertJsonPath('selection.timezone', 'Asia/Kolkata')->assertJsonPath('selection.cart_version', 1)->assertJsonPath('selection.estimated_prep_minutes', 20)
            ->assertJsonPath('selection.issues', [])->assertJsonPath('selection.version', 1);
        $this->assertSame(['id', 'mode', 'method', 'pickup_at', 'local_pickup_at', 'local_date', 'slot_key', 'slot_ends_at', 'timezone', 'cart_version', 'status', 'valid', 'reserved', 'issues', 'estimated_prep_minutes', 'traveler_arrival_at', 'arrival_vs_pickup_delta_seconds', 'selected_at', 'validated_at', 'expires_at', 'revalidate_after', 'version'], array_keys($chosen->json('selection')));

        // Ten minutes later the same ASAP choice means ten minutes later: it is "the earliest valid time", not a fixed minute.
        $this->clock('2026-10-07 12:10');
        $this->selection($guest)->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.local_pickup_at', '2026-10-07T12:30:00+05:30');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.selection.mode', 'ASAP')->assertJsonPath('pickup.selection.local_pickup_at', '2026-10-07T12:30:00+05:30');
    }

    public function test_a_scheduled_slot_can_be_chosen_and_choosing_again_replaces_it(): void
    {
        $guest = $this->guestWithBurger();

        $first = $this->selectSlot($guest, '13:15')->assertOk();
        $first->assertJsonPath('selection.mode', 'SCHEDULED')->assertJsonPath('selection.local_pickup_at', '2026-10-07T13:15:00+05:30')->assertJsonPath('selection.slot_ends_at', '2026-10-07T08:00:00+00:00')
            ->assertJsonPath('selection.valid', true)->assertJsonPath('selection.reserved', false)->assertJsonPath('selection.expires_at', '2026-10-07T07:45:00+00:00')->assertJsonPath('selection.version', 1);
        $this->assertMatchesRegularExpression('/^ps1_/', $first->json('selection.slot_key'));

        // Another slot, then ASAP: always the same single selection of this cart.
        $second = $this->selectSlot($guest, '14:00')->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-10-07T14:00:00+05:30')->assertJsonPath('selection.version', 2);
        $this->assertSame($first->json('selection.id'), $second->json('selection.id'));
        $this->selectAsap($guest)->assertOk()->assertJsonPath('selection.mode', 'ASAP')->assertJsonPath('selection.slot_key', null)->assertJsonPath('selection.version', 3);
        $this->assertSame(1, CartPickupSelection::query()->count());

        // And it can be forgotten — as often as the client likes.
        $this->deleteJson('/api/v1/cart/pickup-selection', [], $guest)->assertOk()->assertJsonPath('selection', null);
        $this->deleteJson('/api/v1/cart/pickup-selection', [], $guest)->assertOk()->assertJsonPath('selection', null);
        $this->selection($guest)->assertJsonPath('selection', null);
        $this->assertSame(0, CartPickupSelection::query()->count());
    }

    public function test_the_mode_is_always_explicit_and_the_request_is_validated(): void
    {
        $guest = $this->guestWithBurger();

        $this->postJson('/api/v1/cart/pickup-selection', [], $guest)->assertStatus(422)->assertJsonPath('error.details.fields.mode.0', 'The mode field is required.');
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'DELIVERY'], $guest)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['mode']]]]);
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED'], $guest)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['slot_key']]]]);
        // An ASAP pickup has no slot: a slot key next to ASAP is a contradiction, not something to ignore.
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'ASAP', 'slot_key' => $this->slotKey($guest, '13:15')], $guest)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['slot_key']]]]);
        $this->assertSame(0, CartPickupSelection::query()->count());
    }

    public function test_a_client_cannot_decide_the_time_the_restaurant_the_owner_or_the_validity(): void
    {
        $guest = $this->guestWithBurger();
        [$other] = $this->otherRestaurant();
        $key = $this->slotKey($guest, '13:15');

        foreach ([
            'restaurant_id' => $other->public_id, 'restaurant_location_id' => $other->id, 'location_id' => $other->id, 'customer_id' => 1, 'cart_id' => 99, 'owner' => 'customer',
            'pickup_at' => '2026-10-07T06:35:00Z', 'selected_at' => '2026-10-07T06:35:00Z', 'time' => '12:05', 'starts_at' => '2026-10-07T06:35:00Z', 'timezone' => 'America/New_York', 'restaurant_timezone' => 'UTC',
            'status' => 'CONVERTED', 'valid' => true, 'reserved' => true, 'capacity' => 999, 'capacity_state' => 'AVAILABLE', 'capacity_per_slot' => 999, 'expires_at' => '2030-01-01T00:00:00Z', 'version' => 7,
            'estimated_prep_minutes' => 0, 'traveler_arrival_at' => '2026-10-07T06:35:00Z', 'now' => '2026-10-07T00:00:00Z', 'client_time' => '2026-10-07T00:00:00Z', 'currency' => 'USD',
        ] as $field => $value) {
            $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $key, $field => $value], $guest)
                ->assertStatus(422)->assertJsonPath("error.details.fields.{$field}.0", 'The '.str_replace('_', ' ', $field).' field is decided by the server and cannot be sent.');
        }
        $this->getJson('/api/v1/cart/pickup-availability?restaurant_id='.$other->public_id, $guest)->assertStatus(422);
        $this->getJson('/api/v1/cart/pickup-availability?now=2026-10-07T00:00:00Z', $guest)->assertStatus(422);
        $this->assertSame(0, CartPickupSelection::query()->count());

        // What is stored comes from the cart and the engine alone.
        $this->selectSlot($guest, '13:15')->assertOk();
        $stored = CartPickupSelection::query()->firstOrFail();
        $this->assertSame([Cart::query()->value('id'), 'Asia/Kolkata', 'SELECTED', 1], [$stored->cart_id, $stored->restaurant_timezone, $stored->status->value, $stored->cart_version]);
        $this->assertSame($this->burgerHub->id, $stored->cart->restaurant_location_id);
    }

    public function test_a_tampered_or_foreign_slot_key_is_refused(): void
    {
        $guest = $this->guestWithBurger();
        $real = $this->slotKey($guest, '13:15');
        [$prefix, $start, $interval, $tag] = explode('_', $real);
        $select = fn (string $key) => $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $key], $guest);

        // A time that was never offered (13:07), with the tag of a real slot: the key no longer names anything.
        $offGrid = base_convert((string) ((int) base_convert($start, 36, 10) - 8 * 60), 10, 36);
        $select("{$prefix}_{$offGrid}_{$interval}_{$tag}")->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid');
        // Another interval, a made-up tag, rubbish.
        $select("{$prefix}_{$start}_5_{$tag}")->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid');
        $select("{$prefix}_{$start}_{$interval}_".str_repeat('0', 12))->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid');
        $select('2026-10-07T07:45:00Z')->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid');
        $select('ps1_zzzzzz_15_0123456789ab')->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid');

        // A genuine key of ANOTHER restaurant names nothing in this cart's restaurant.
        [$pizza, $margherita] = $this->otherRestaurant();
        $this->settings(['scheduled_enabled' => true], $pizza);
        $elsewhere = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($margherita), $elsewhere)->assertCreated();
        $foreign = $this->slotKey($elsewhere, '13:15');
        $this->assertNotSame($real, $foreign);
        $select($foreign)->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid');

        // A genuine key for a time that is too early for this cart, or already gone.
        $soon = $this->slotKey($guest, '12:30');
        $this->clock('2026-10-07 12:25');
        $select($soon)->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid')->assertJsonPath('error.details.reason', 'SLOT_TOO_EARLY')->assertJsonPath('error.details.earliest_pickup_at', '2026-10-07T07:15:00+00:00');
        $this->clock('2026-10-07 12:31');
        $select($soon)->assertStatus(409)->assertJsonPath('error.code', 'slot_expired');
        $this->assertSame(0, CartPickupSelection::query()->count());
    }

    public function test_the_device_clock_and_time_zone_cannot_produce_an_earlier_time(): void
    {
        $guest = $this->guestWithBurger();
        $before = $this->pickup($guest)->assertOk()->json('pickup');

        // Whatever a device claims about the time — headers, or a different zone — the answer is the same.
        $claimed = $this->getJson('/api/v1/cart/pickup-availability', [...$guest, 'Date' => 'Wed, 07 Oct 2026 02:00:00 GMT', 'X-Client-Time' => '2026-10-07T02:00:00Z', 'X-Timezone' => 'America/Los_Angeles', 'Time-Zone' => 'Pacific/Auckland'])->assertOk()->json('pickup');

        $this->assertSame($before['asap'], $claimed['asap']);
        $this->assertSame(array_column($before['slots'], 'starts_at'), array_column($claimed['slots'], 'starts_at'));
        $this->assertSame('2026-10-07T12:20:00+05:30', $claimed['asap']['local_pickup_at']);
        $this->assertSame('Asia/Kolkata', $claimed['timezone']);
    }

    public function test_only_a_pickup_method_the_restaurant_offers_can_be_chosen(): void
    {
        $guest = $this->guestWithBurger();

        // Counter only: curbside is refused, on both endpoints.
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.methods', [['method' => 'COUNTER', 'instructions' => null, 'requires_vehicle_info' => false]])->assertJsonPath('pickup.method', 'COUNTER');
        $this->selectAsap($guest, ['method' => 'CURBSIDE'])->assertStatus(422)->assertJsonPath('error.code', 'pickup_method_unavailable');
        $this->pickup($guest, ['method' => 'CURBSIDE'])->assertStatus(422)->assertJsonPath('error.code', 'pickup_method_unavailable');
        $this->selectAsap($guest, ['method' => 'TELEPORT'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['method']]]]);

        // The restaurant enables drive-through with its own instructions: now it is offered and can be chosen.
        RestaurantLocationPickupMethod::query()->create(['location_id' => $this->burgerHub->id, 'method' => 'DRIVE_THROUGH', 'enabled' => true, 'instructions' => 'Use lane 2.', 'requires_vehicle_info' => true]);
        $this->market->forceFill(['features' => [...$this->market->features, 'drive_through_pickup' => true]])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.methods.1', ['method' => 'DRIVE_THROUGH', 'instructions' => 'Use lane 2.', 'requires_vehicle_info' => true])->assertJsonPath('pickup.method', 'COUNTER');
        $this->selectAsap($guest, ['method' => 'DRIVE_THROUGH'])->assertOk()->assertJsonPath('selection.method', 'DRIVE_THROUGH');

        // Curbside is enabled by the restaurant but not allowed in this market: still not offered.
        RestaurantLocationPickupMethod::query()->create(['location_id' => $this->burgerHub->id, 'method' => 'CURBSIDE', 'enabled' => true]);
        $this->assertSame(['COUNTER', 'DRIVE_THROUGH'], array_column($this->pickup($guest)->assertOk()->json('pickup.methods'), 'method'));
        $this->selectAsap($guest, ['method' => 'CURBSIDE'])->assertStatus(422)->assertJsonPath('error.code', 'pickup_method_unavailable');

        // The chosen method is switched off again: the selection says so.
        RestaurantLocationPickupMethod::query()->where('method', 'DRIVE_THROUGH')->update(['enabled' => false]);
        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'PICKUP_METHOD_UNAVAILABLE');
    }

    public function test_pickup_needs_a_cart_with_something_in_it(): void
    {
        // Nobody: no token, no session.
        $this->getJson('/api/v1/cart/pickup-availability')->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found');
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'ASAP'])->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found');
        $this->getJson('/api/v1/cart/pickup-selection')->assertOk()->assertJsonPath('selection', null);

        // A guest without a cart yet.
        $guest = $this->guest();
        $this->pickup($guest)->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found');
        $this->selectAsap($guest)->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found');

        // An emptied cart: no restaurant slots are generated for a cart with nothing to prepare.
        $line = $this->addBurger($guest)->assertCreated()->json('line.id');
        $this->deleteJson("/api/v1/cart/items/{$line}", [], $guest)->assertOk();
        $this->pickup($guest)->assertStatus(409)->assertJsonPath('error.code', 'cart_empty');
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'cart_empty');
    }

    public function test_a_cart_that_needs_attention_stops_at_the_pickup_step(): void
    {
        $guest = $this->guestWithBurger();
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest)->assertCreated();
        $this->selectAsap($guest)->assertOk();

        // The restaurant marks an item of the cart as sold out.
        app(MenuService::class)->setItemStatus($this->fries, MenuItemStatus::SoldOut, $this->staff());

        $pickup = $this->pickup($guest)->assertOk();
        $pickup->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.slots', [])->assertJsonPath('pickup.recommended', null)
            ->assertJsonPath('pickup.issues.0.code', 'CART_INVALID')->assertJsonPath('pickup.issues.0.severity', 'BLOCKING')->assertJsonPath('pickup.issues.0.details.cart_validation', 'INVALID')
            // The earlier choice is not thrown away, but it cannot be used until the cart is put right.
            ->assertJsonPath('pickup.selection.status', 'STALE')->assertJsonPath('pickup.selection.valid', false)->assertJsonPath('pickup.selection.issues.0.code', 'CART_INVALID');
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'cart_invalid');

        // The customer removes the item: the same choice is usable again.
        $fries = collect($this->cartOf($guest)->json('cart.items'))->firstWhere('name', 'Masala Fries')['id'];
        $this->deleteJson("/api/v1/cart/items/{$fries}", [], $guest)->assertOk();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', true)->assertJsonPath('pickup.selection.status', 'SELECTED');

        // A changed price has to be acknowledged in the cart before the pickup step goes on.
        $this->burger->forceFill(['base_price_minor' => 22000])->save();
        $review = $this->pickup($guest)->assertOk();
        $review->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'CART_INVALID')->assertJsonPath('pickup.issues.0.details.cart_validation', 'NEEDS_REVIEW');
        $this->postJson('/api/v1/cart/refresh', [], $guest)->assertOk();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', true);
    }

    public function test_a_cart_change_makes_a_selection_stale_only_when_the_time_no_longer_fits(): void
    {
        $slow = $this->item($this->mains, 'Dum Biryani', 32000, [], ['preparation_minutes' => 90]);
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk()->assertJsonPath('selection.cart_version', 1);

        // More of the same: the cart changed, the time still fits — the choice stays, validated against the new cart.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest)->assertCreated();
        $this->selection($guest)->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.cart_version', 2);

        // A slow item: ready at 13:30 at the earliest, so 13:15 can no longer be met.
        $this->postJson('/api/v1/cart/items', $this->addBody($slow), $guest)->assertCreated();
        $stale = $this->selection($guest);
        $stale->assertJsonPath('selection.status', 'STALE')->assertJsonPath('selection.valid', false)->assertJsonPath('selection.issues.0.code', 'CART_CHANGED')
            ->assertJsonPath('selection.issues.0.message', 'Your cart changed and this pickup time can no longer be met. Please choose another time.')
            ->assertJsonPath('selection.local_pickup_at', '2026-10-07T13:15:00+05:30');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.selection.status', 'STALE')->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T13:30:00+05:30')->assertJsonPath('pickup.cart_version', 3);
        $this->assertSame('STALE', CartPickupSelection::query()->firstOrFail()->status->value);

        // Removing the slow item makes the old time fit again; choosing a new one works either way.
        $biryani = collect($this->cartOf($guest)->json('cart.items'))->firstWhere('name', 'Dum Biryani')['id'];
        $this->deleteJson("/api/v1/cart/items/{$biryani}", [], $guest)->assertOk();
        $this->selection($guest)->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.cart_version', 4);
    }

    public function test_a_larger_quantity_can_push_the_order_past_the_chosen_time(): void
    {
        config(['pickup.preparation.quantity' => ['free_items' => 2, 'items_per_step' => 2, 'minutes_per_step' => 15, 'max_extra_minutes' => 60]]);
        $guest = $this->guest();
        $line = $this->addBurger($guest, 2)->assertCreated()->json('line.id');
        $this->selectSlot($guest, '12:30')->assertOk()->assertJsonPath('selection.estimated_prep_minutes', 20);

        // Eight burgers instead of two: three steps of 15 minutes → 65 minutes, ready at 13:05.
        $this->patchJson("/api/v1/cart/items/{$line}", ['quantity' => 8], $guest)->assertOk();

        $this->selection($guest)->assertJsonPath('selection.status', 'STALE')->assertJsonPath('selection.issues.0.code', 'CART_CHANGED');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.preparation.estimated_minutes', 65)->assertJsonPath('pickup.slots.0.local_starts_at', '2026-10-07T13:15:00+05:30');
    }

    public function test_a_stale_cart_version_is_refused(): void
    {
        $guest = $this->guestWithBurger();
        $version = $this->pickup($guest)->assertOk()->json('pickup.cart_version');
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest)->assertCreated();

        $this->selectAsap($guest, ['cart_version' => $version])->assertStatus(409)->assertJsonPath('error.code', 'cart_version_conflict')->assertJsonPath('error.details.current_version', $version + 1);
        $this->selectAsap($guest, ['cart_version' => $version + 1])->assertOk();
    }

    public function test_pausing_orders_invalidates_the_selection_until_the_pause_ends(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk();

        // The restaurant pauses orders for 45 minutes.
        $this->burgerHub->forceFill(['accepting_orders' => false, 'pause_reason' => 'Kitchen is overloaded', 'paused_at' => now(), 'paused_until' => now()->addMinutes(45)])->save();

        $paused = $this->pickup($guest)->assertOk();
        $paused->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.asap.available', false)->assertJsonPath('pickup.slots', [])
            ->assertJsonPath('pickup.issues.0.code', 'RESTAURANT_NOT_ACCEPTING')->assertJsonPath('pickup.issues.0.details.resumes_at', '2026-10-07T07:15:00+00:00')
            ->assertJsonPath('pickup.selection.status', 'INVALID')->assertJsonPath('pickup.selection.issues.0.code', 'RESTAURANT_NOT_ACCEPTING');
        // The reason for the pause is the restaurant's business.
        $this->assertStringNotContainsString('overloaded', $paused->getContent());
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'restaurant_not_accepting');

        // The pause runs out on the server clock: nothing has to be switched back on.
        $this->clock('2026-10-07 12:46');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', true)->assertJsonPath('pickup.asap.available', true)->assertJsonPath('pickup.selection.status', 'SELECTED');
    }

    public function test_shorter_hours_invalidate_a_time_that_is_no_longer_inside_them(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '20:30')->assertOk();

        // The restaurant now closes at 18:00.
        RestaurantLocationHour::query()->where('location_id', $this->burgerHub->id)->delete();
        $this->hours($this->burgerHub, [[null, '09:00', '18:00']]);

        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'SLOT_INVALID')
            ->assertJsonPath('selection.issues.0.message', 'That pickup time is no longer offered by the restaurant. Please select another time.');

        // A special closure on the chosen day does the same.
        RestaurantLocationHour::query()->where('location_id', $this->burgerHub->id)->delete();
        $this->hours($this->burgerHub, [[null, '09:00', '22:00']]);
        $this->selection($guest)->assertJsonPath('selection.status', 'SELECTED');
        $this->special($this->burgerHub, '2026-10-07');
        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'SLOT_INVALID');
    }

    public function test_changed_pickup_settings_are_applied_to_an_existing_selection(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '12:45')->assertOk();

        // A longer preparation time: 12:45 is now too early.
        $this->settings(['default_prep_minutes' => 60]);
        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'SLOT_TOO_EARLY');

        // Back to 20 minutes, but 30-minute slots: 12:45 is not a slot any more, 13:00 is.
        $this->settings(['default_prep_minutes' => 20, 'slot_interval_minutes' => 30]);
        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'SLOT_INVALID');
        $this->selectSlot($guest, '13:00')->assertOk();
        // Back on a 15-minute grid 13:00 is still a slot: the choice stays and takes the slot's new identity.
        $this->settings(['slot_interval_minutes' => 15]);
        $this->selection($guest)->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.slot_ends_at', '2026-10-07T07:45:00+00:00');

        // Scheduled pickup switched off / a shorter horizon / ASAP switched off.
        $this->settings(['scheduled_enabled' => false]);
        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'SCHEDULED_DISABLED');
        $this->settings(['scheduled_enabled' => true]);
        $this->selectSlot($guest, '18:00')->assertOk();
        $this->settings(['schedule_horizon_minutes' => 120]);
        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'SLOT_OUTSIDE_HORIZON');
        $this->selectAsap($guest)->assertOk();
        $this->settings(['asap_enabled' => false]);
        $this->selection($guest)->assertJsonPath('selection.status', 'INVALID')->assertJsonPath('selection.issues.0.code', 'ASAP_DISABLED');
        // Pickup switched off altogether.
        $this->settings(['pickup_enabled' => false]);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.pickup_enabled', false)->assertJsonPath('pickup.issues.0.code', 'PICKUP_DISABLED');
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'pickup_disabled');
    }

    public function test_a_suspended_restaurant_or_a_paused_area_has_no_pickup_availability(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectAsap($guest)->assertOk();

        // An administrator suspends the restaurant.
        $this->burgerHub->forceFill(['status' => 'SUSPENDED'])->save();
        $suspended = $this->pickup($guest)->assertOk();
        $suspended->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'RESTAURANT_UNAVAILABLE')->assertJsonPath('pickup.slots', [])->assertJsonPath('pickup.selection.status', 'INVALID');
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'restaurant_unavailable');
        $this->burgerHub->forceFill(['status' => 'APPROVED'])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', true)->assertJsonPath('pickup.selection.status', 'SELECTED');

        // The organization is suspended.
        $this->organization->forceFill(['status' => 'SUSPENDED'])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'RESTAURANT_UNAVAILABLE');
        $this->organization->forceFill(['status' => 'APPROVED'])->save();

        // Temporarily closed by the restaurant itself: nothing at all, also nothing for later.
        $this->burgerHub->forceFill(['operational_status' => 'TEMPORARILY_CLOSED'])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'RESTAURANT_UNAVAILABLE')->assertJsonPath('pickup.issues.0.details.reason', 'TEMPORARILY_CLOSED');
        $this->burgerHub->forceFill(['operational_status' => 'OPERATING'])->save();

        // The service area is paused, then the whole market.
        $this->serviceArea->forceFill(['status' => 'PAUSED'])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'MARKET_UNAVAILABLE');
        $this->selectAsap($guest)->assertStatus(409)->assertJsonPath('error.code', 'market_unavailable');
        $this->serviceArea->forceFill(['status' => 'ACTIVE'])->save();
        $this->market->forceFill(['status' => 'PAUSED'])->save();
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false);
        $this->assertContains($this->pickup($guest)->json('pickup.issues.0.code'), ['MARKET_UNAVAILABLE', 'RESTAURANT_UNAVAILABLE']);
    }

    public function test_a_selection_expires_when_its_time_comes_or_can_no_longer_be_met(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk();

        // 13:00: the order would be ready at 13:20 — 13:15 can no longer be met, although nothing else changed.
        $this->clock('2026-10-07 13:00');
        $this->selection($guest)->assertJsonPath('selection.status', 'EXPIRED')->assertJsonPath('selection.issues.0.code', 'SLOT_EXPIRED');
        // After the time itself: still expired.
        $this->clock('2026-10-07 13:20');
        $this->selection($guest)->assertJsonPath('selection.status', 'EXPIRED')->assertJsonPath('selection.valid', false);
        $this->assertSame('EXPIRED', CartPickupSelection::query()->firstOrFail()->status->value);
        // Choosing a new time simply replaces it.
        $this->selectSlot($guest, '14:00')->assertOk()->assertJsonPath('selection.status', 'SELECTED');
    }

    public function test_the_selection_goes_when_the_cart_is_cleared_switched_or_expires(): void
    {
        [$pizza, $margherita] = $this->otherRestaurant();

        // Cleared.
        $guest = $this->guestWithBurger();
        $this->selectAsap($guest)->assertOk();
        $this->deleteJson('/api/v1/cart', [], $guest)->assertOk();
        $this->selection($guest)->assertJsonPath('selection', null);
        $this->pickup($guest)->assertStatus(404);

        // A new cart starts without a pickup time.
        $this->addBurger($guest)->assertCreated();
        $this->selection($guest)->assertJsonPath('selection', null);
        $this->selectSlot($guest, '13:15')->assertOk();

        // Restaurant switch: the new cart has no selection — the old restaurant's time never carries across.
        $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $pizza->public_id, 'item' => ['item_id' => $margherita->public_id, 'quantity' => 1]], $guest)->assertCreated();
        $this->selection($guest)->assertJsonPath('selection', null);
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.restaurant.id', $pizza->public_id)->assertJsonPath('pickup.selection', null);

        // Expiry: the cart says so, and so does its selection.
        $this->selectAsap($guest)->assertOk();
        Cart::query()->active()->update(['expires_at' => now()->subMinute()]);
        $this->selection($guest)->assertJsonPath('selection.status', 'EXPIRED')->assertJsonPath('selection.issues.0.code', 'CART_EXPIRED');
        $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->assertJsonPath('pickup.issues.0.code', 'CART_EXPIRED');
        $this->selectAsap($guest)->assertStatus(410)->assertJsonPath('error.code', 'cart_expired');
        // The cleanup removes the selection together with its cart.
        Cart::query()->delete();
        $this->assertSame(0, CartPickupSelection::query()->count());
    }

    public function test_a_repeated_selection_request_is_applied_once(): void
    {
        $guest = $this->guestWithBurger();
        $key = $this->slotKey($guest, '13:15');
        $body = ['mode' => 'SCHEDULED', 'slot_key' => $key];
        $retry = [...$guest, 'Idempotency-Key' => 'pickup-select-0001-0001'];

        $first = $this->postJson('/api/v1/cart/pickup-selection', $body, $retry)->assertOk();
        $again = $this->postJson('/api/v1/cart/pickup-selection', $body, $retry)->assertOk();

        $again->assertHeader('Idempotency-Replayed', 'true');
        $this->assertEquals($first->json(), $again->json());
        $this->assertSame([1, 1], [CartPickupSelection::query()->count(), (int) CartPickupSelection::query()->value('version')]);
        // The same key with another request is a client error, not a second choice.
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'ASAP'], $retry)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        // Without a key the operation is still safe to repeat: one selection per cart, replaced in place.
        $this->postJson('/api/v1/cart/pickup-selection', $body, $guest)->assertOk();
        $this->postJson('/api/v1/cart/pickup-selection', $body, $guest)->assertOk();
        $this->assertSame(1, CartPickupSelection::query()->count());
    }
}
