<?php

namespace Tests\Feature\Pickup;

use App\Enums\Permission;
use App\Models\CartPickupSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * Pickup belongs to the cart, and the cart to its owner: another guest, another customer, restaurant staff and
 * administrators never see or change somebody's pickup time. A guest's choice survives signing in when it is
 * still valid for the cart the customer ends up with.
 */
class PickupOwnershipTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    public function test_one_guest_cannot_reach_another_guests_pickup(): void
    {
        $alice = $this->guestWithBurger();
        $this->selectSlot($alice, '13:15')->assertOk();
        $selectionId = CartPickupSelection::query()->value('public_id');
        $cartId = $this->cartOf($alice)->json('cart.id');

        // Bob has his own token — and no cart: he sees nothing of Alice's, whatever ids he knows.
        $bob = $this->guest();
        $this->getJson('/api/v1/cart/pickup-selection', $bob)->assertOk()->assertJsonPath('selection', null);
        $this->pickup($bob)->assertStatus(404);
        $this->getJson("/api/v1/cart/pickup-selection?cart_id={$cartId}", $bob)->assertOk()->assertJsonPath('selection', null);
        $this->getJson("/api/v1/cart/pickup-selection/{$selectionId}", $bob)->assertStatus(404);
        $this->deleteJson('/api/v1/cart/pickup-selection', [], $bob)->assertOk();
        $this->selectAsap($bob)->assertStatus(404);

        // With a cart of his own he gets his own answer, and Alice's choice is untouched.
        $this->addBurger($bob)->assertCreated();
        $this->pickup($bob)->assertOk()->assertJsonPath('pickup.selection', null);
        $this->selectAsap($bob)->assertOk();
        $this->getJson('/api/v1/cart/pickup-selection', $alice)->assertOk()->assertJsonPath('selection.mode', 'SCHEDULED')->assertJsonPath('selection.local_pickup_at', '2026-10-07T13:15:00+05:30');
        $this->assertSame(2, CartPickupSelection::query()->count());

        // A forged or missing token is nobody.
        $this->getJson('/api/v1/cart/pickup-selection', ['X-Cart-Token' => 'gc1.zz.'.str_repeat('a', 64).'.'.str_repeat('b', 32)])->assertOk()->assertJsonPath('selection', null);
        $this->pickup(['X-Cart-Token' => 'not-a-token'])->assertStatus(404);
    }

    public function test_one_customer_cannot_reach_another_customers_pickup(): void
    {
        [, $asha] = $this->signedIn('+919876543212');
        $this->addBurger($asha)->assertCreated();
        $this->selectSlot($asha, '13:15')->assertOk();
        $ashaToken = $this->guest();

        [, $rahul] = $this->signedIn('+919876543210');
        $this->getJson('/api/v1/cart/pickup-selection', $rahul)->assertOk()->assertJsonPath('selection', null);
        $this->pickup($rahul)->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found');
        $this->selectAsap($rahul)->assertStatus(404);
        $this->deleteJson('/api/v1/cart/pickup-selection', [], $rahul)->assertOk();
        // A signed-in customer is the customer: somebody else's guest token next to the session changes nothing.
        $this->getJson('/api/v1/cart/pickup-selection', [...$rahul, ...$ashaToken])->assertOk()->assertJsonPath('selection', null);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cart/pickup-selection', $asha)->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-10-07T13:15:00+05:30');
        $this->assertSame(1, CartPickupSelection::query()->count());
    }

    public function test_restaurant_staff_and_administrators_have_no_access_to_a_customers_pickup(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk();
        $cartId = $this->cartOf($guest)->json('cart.id');

        // The owner of the very restaurant: their token is not a customer's, so they are "nobody" for a cart.
        $this->actingAsPrincipal($this->staff());
        $this->getJson('/api/v1/cart/pickup-selection')->assertOk()->assertJsonPath('selection', null);
        $this->getJson('/api/v1/cart/pickup-availability')->assertStatus(404);
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'ASAP'])->assertStatus(404);
        // There is no staff or admin route that lists or returns pickup selections.
        $this->getJson('/api/v1/restaurant/locations/'.$this->burgerHub->public_id.'/pickup-selections')->assertStatus(404);
        $this->getJson('/api/v1/admin/pickup-selections')->assertStatus(404);
        $this->getJson('/api/v1/admin/system/pickup')->assertStatus(401);

        // An administrator sees numbers, never contents — and needs the system permission for those.
        $this->actingAsPrincipal($this->adminWith([]));
        $this->getJson('/api/v1/admin/system/pickup')->assertForbidden();
        $this->getJson('/api/v1/cart/pickup-selection')->assertOk()->assertJsonPath('selection', null);

        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $report = $this->getJson('/api/v1/admin/system/pickup')->assertOk();
        $report->assertJsonPath('selections.selected', 1)->assertJsonPath('selections.selected_scheduled', 1)->assertJsonPath('slot_hold', 'AT_CHECKOUT')
            ->assertJsonPath('events.selected_scheduled', 1)->assertJsonPath('events.availability_requests', 1);
        foreach (['13:15', '07:45', $cartId, 'ps1_', 'slot_key', 'pickup_at', 'Burger Hub'] as $private) {
            $this->assertStringNotContainsString((string) $private, $report->getContent());
        }
    }

    public function test_a_guests_pickup_time_survives_signing_in(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk();
        $id = CartPickupSelection::query()->value('public_id');

        [, $session] = $this->signedIn();
        $this->postJson('/api/v1/cart/claim', [], [...$session, ...$guest])->assertOk()->assertJsonPath('claim.outcome', 'claimed');

        // The same cart is now the customer's, and so is its pickup time.
        $mine = $this->getJson('/api/v1/cart/pickup-selection', $session)->assertOk();
        $mine->assertJsonPath('selection.id', $id)->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.local_pickup_at', '2026-10-07T13:15:00+05:30');
        // The guest token is spent: it addresses nothing any more.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection', null);
        // After signing out the pickup stays with the account, out of reach of the next guest on the device.
        $this->pickup($this->guest())->assertStatus(404);
    }

    public function test_a_merge_at_sign_in_keeps_the_guests_time_only_when_the_account_has_none_and_it_still_fits(): void
    {
        $slow = $this->item($this->mains, 'Dum Biryani', 32000, [], ['preparation_minutes' => 90]);

        // The account already has a cart at the same restaurant without a pickup time; the guest chose 13:15.
        [, $session] = $this->signedIn();
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $session)->assertCreated();
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk();

        $this->postJson('/api/v1/cart/claim', [], [...$session, ...$guest])->assertOk()->assertJsonPath('claim.outcome', 'merged');

        // The guest's choice moved to the merged cart and was validated against it.
        $merged = $this->getJson('/api/v1/cart/pickup-selection', $session)->assertOk();
        $merged->assertJsonPath('selection.status', 'SELECTED')->assertJsonPath('selection.local_pickup_at', '2026-10-07T13:15:00+05:30')
            ->assertJsonPath('selection.cart_version', $this->cartOf($session)->json('cart.version'));
        $this->assertSame(1, CartPickupSelection::query()->count());

        // A second guest cart with a slow item is merged in: the time no longer fits the merged cart.
        $this->app['auth']->forgetGuards();
        $second = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($slow), $second)->assertCreated();
        $this->selectSlot($second, '14:00')->assertOk();
        $this->postJson('/api/v1/cart/claim', [], [...$session, ...$second])->assertOk()->assertJsonPath('claim.outcome', 'merged');
        // The account's own choice (13:15) stays the selection — the second guest's does not replace it — and is now stale.
        $this->getJson('/api/v1/cart/pickup-selection', $session)->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-10-07T13:15:00+05:30')
            ->assertJsonPath('selection.status', 'STALE')->assertJsonPath('selection.issues.0.code', 'CART_CHANGED');
        $this->assertSame(1, CartPickupSelection::query()->whereHas('cart', fn ($q) => $q->where('status', 'ACTIVE'))->count());
    }

    public function test_choosing_between_two_restaurants_at_sign_in_never_carries_a_time_across(): void
    {
        [$pizza, $margherita] = $this->otherRestaurant();
        $this->settings(['scheduled_enabled' => true], $pizza);

        [, $session] = $this->signedIn();
        $this->postJson('/api/v1/cart/items', $this->addBody($margherita), $session)->assertCreated();
        $this->selectSlot($session, '18:00')->assertOk();
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk();

        $this->postJson('/api/v1/cart/claim', [], [...$session, ...$guest])->assertStatus(409)->assertJsonPath('error.code', 'cart_merge_conflict');
        // Nothing moved while the customer has not chosen.
        $this->getJson('/api/v1/cart/pickup-selection', $session)->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-10-07T18:00:00+05:30');

        // Keep the account's cart: its own time stays, the guest's is gone with the guest cart.
        $this->postJson('/api/v1/cart/claim', ['resolution' => 'keep_account'], [...$session, ...$guest])->assertOk()->assertJsonPath('claim.outcome', 'kept_account');
        $this->getJson('/api/v1/cart/pickup-selection', $session)->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-10-07T18:00:00+05:30');
        $this->pickup($session)->assertOk()->assertJsonPath('pickup.restaurant.id', $pizza->public_id);

        // The other way round: the guest cart replaces the account's — with the guest's time, never the pizza time.
        $this->app['auth']->forgetGuards();
        $again = $this->guestWithBurger();
        $this->selectSlot($again, '14:00')->assertOk();
        $this->postJson('/api/v1/cart/claim', ['resolution' => 'keep_guest'], [...$session, ...$again])->assertOk()->assertJsonPath('claim.outcome', 'kept_guest');
        $this->getJson('/api/v1/cart/pickup-selection', $session)->assertOk()->assertJsonPath('selection.local_pickup_at', '2026-10-07T14:00:00+05:30')->assertJsonPath('selection.status', 'SELECTED');
        $this->pickup($session)->assertOk()->assertJsonPath('pickup.restaurant.id', $this->burgerHub->public_id);
    }

    public function test_pickup_events_are_logged_without_the_cart_the_time_or_the_journey(): void
    {
        Log::spy();
        $guest = $this->guestWithBurger();
        $this->fakeRouting();
        $this->fakePlaces();
        $this->arriveAt($guest, '13:25');

        $this->selectSlot($guest, '13:30')->assertOk();
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => 'ps1_zzzzzz_15_0123456789ab'], $guest)->assertStatus(422);
        $this->deleteJson('/api/v1/cart/pickup-selection', [], $guest)->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            if (! str_starts_with($message, 'pickup.')) {
                return true;
            }
            $line = json_encode($context);
            foreach (['13:30', '08:00', 'ps1_', 'West gate', 'East gate', 'Classic Burger', 'gc1.', 'arrival'] as $private) {
                $this->assertStringNotContainsString($private, $line, "{$message} must not log {$private}");
            }

            return isset($context['cart'], $context['owner']);
        });
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c): bool => $m === 'pickup.selected' && $c['mode'] === 'SCHEDULED' && $c['journey_aware'] === true)->once();
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c): bool => $m === 'pickup.refused' && $c['reason'] === 'SLOT_INVALID')->once();
        Log::shouldHaveReceived('info')->withArgs(fn (string $m): bool => $m === 'pickup.cleared')->once();
    }
}
