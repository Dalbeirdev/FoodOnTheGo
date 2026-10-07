<?php

namespace App\Services\Cart;

use App\Enums\CartCloseReason;
use App\Enums\CartStatus;
use App\Enums\MenuCategoryStatus;
use App\Events\Cart\CartCleared;
use App\Events\Cart\CartCreated;
use App\Events\Cart\CartExpired;
use App\Events\Cart\CartItemAdded;
use App\Events\Cart\CartItemRemoved;
use App\Events\Cart\CartItemUpdated;
use App\Events\Cart\CartRestaurantConflict;
use App\Events\Cart\CartRestaurantSwitched;
use App\Events\Cart\CartRevalidated;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Services\Menu\MenuAvailabilityService;
use App\Services\Restaurant\RestaurantVisibility;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The cart of one owner (Module 27).
 *
 *   ONE ACTIVE CART = ONE RESTAURANT LOCATION. An item of another restaurant is refused with
 *   cart_restaurant_conflict; the cart is replaced only by switchRestaurant(), which the customer confirmed —
 *   nothing destructive ever happens on its own.
 *
 * Every change runs in a transaction on the locked cart row, raises `version`, renews the lifetime and is priced
 * and validated by the backend from the current menu: the item must be on the active menu of the cart's
 * restaurant, visible and orderable; the selection must satisfy the item's groups (CartPricingService →
 * MenuPricingService). A client sends ids, a quantity and text — never a price, a currency, an owner or a status.
 *
 * Adding is allowed while the restaurant is closed or paused (people order ahead of a journey); what is not
 * orderable at that moment is reported by CartValidationService and decided at pickup / checkout.
 */
final class CartService
{
    public function __construct(
        private readonly CartStore $store,
        private readonly CartPricingService $pricing,
        private readonly CartValidationService $validation,
        private readonly CartJourneyLink $journeys,
        private readonly RestaurantVisibility $visibility,
        private readonly MenuAvailabilityService $menuAvailability,
    ) {}

    /**
     * The owner's cart — also when it has expired (it then says so) — or null.
     */
    public function current(CartOwner $owner): ?Cart
    {
        if (! $owner->hasIdentity()) {
            return null;
        }
        $query = Cart::query()->active();
        $owner->constrain($query);

        return $query->first();
    }

    /**
     * Adds an item. The same configuration as an existing line raises that line's quantity.
     *
     * @param  array<string, mixed>  $input  restaurant_id, item_id, selections, quantity, special_instructions, journey_id, cart_version
     *
     * @throws ApiException 404 restaurant_not_found / item_not_found, 409 cart_restaurant_conflict / item_unavailable /
     *                      option_unavailable / cart_version_conflict, 410 cart_expired, 422 invalid_customization / quantity_limit
     */
    public function add(CartOwner $owner, array $input, #[SensitiveParameter] ?string $journeyToken = null): CartChange
    {
        return $this->store->transaction(function () use ($owner, $input, $journeyToken): CartChange {
            $location = $this->visibleLocation((string) $input['restaurant_id']) ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant is not available on FoodOnTheGo.');
            $cart = $this->store->lock($owner);
            $this->assertUsable($cart);

            $otherRestaurant = $cart !== null && (int) $cart->restaurant_location_id !== (int) $location->getKey();
            if ($otherRestaurant && $cart->items()->exists()) {
                throw $this->restaurantConflict($cart, $location);
            }
            $this->assertVersion($cart, $input['cart_version'] ?? null);

            $created = $cart === null;
            $cart ??= $this->store->start($owner, $location);
            $cart->setRelation('location', $location);
            if ($otherRestaurant) {
                // An empty cart has nothing to lose: it simply becomes a cart of this restaurant.
                $this->repoint($cart, $location);
            }

            [$line, $merged, $menu] = $this->put($cart, $location, $input);
            $this->linkJourneyIfFree($cart, $owner, $location, $input['journey_id'] ?? null, $journeyToken);
            $this->store->touch($cart, $menu);

            if ($created) {
                $this->after(new CartCreated($cart));
            }
            $this->after(new CartItemAdded($cart, ['merged' => $merged, 'quantity' => (int) $line->quantity]));

            return new CartChange($cart, (string) $line->public_id, $merged, $created);
        });
    }

    /**
     * Changes the quantity, the selected options and / or the special instructions of a line. When the new
     * configuration equals another line of the cart, the two become one.
     *
     * @param  array<string, mixed>  $input  quantity, selections, special_instructions, cart_version — each optional
     */
    public function update(CartOwner $owner, string $lineId, array $input): CartChange
    {
        return $this->store->transaction(function () use ($owner, $lineId, $input): CartChange {
            $cart = $this->lockOrFail($owner);
            $this->assertUsable($cart);
            $this->assertVersion($cart, $input['cart_version'] ?? null);
            $line = $this->line($cart, $lineId);

            $location = $this->visibleLocation((string) $cart->location()->value('public_id')) ?? throw ApiException::conflict('restaurant_unavailable', 'This restaurant is not available right now. The cart cannot be changed.');
            $cart->setRelation('location', $location);
            $menu = $this->activeMenu($location);
            $item = $this->itemOfLine($line, $menu);

            $selections = array_key_exists('selections', $input) ? array_values((array) $input['selections']) : $this->validation->selectionsOf($line->setRelation('menuItem', $item));
            $instructions = array_key_exists('special_instructions', $input) ? CartSignature::cleanInstructions($input['special_instructions']) : $line->special_instructions;
            $quantity = (int) ($input['quantity'] ?? $line->quantity);
            $signature = CartSignature::of((string) $item->public_id, self::optionIds($selections), $instructions);

            $twin = CartItem::query()->where('cart_id', $cart->getKey())->where('signature', $signature)->where('id', '!=', $line->getKey())->first();
            $total = $quantity + (int) ($twin?->quantity ?? 0);
            if ($twin !== null && $total > (int) $item->max_quantity) {
                throw self::quantityLimit($item, (int) $twin->quantity, $quantity);
            }
            $this->assertTotalQuantity($cart, $total, [$line->getKey(), $twin?->getKey()]);
            $quote = $this->pricing->quote($item, $selections, $total);

            if ($twin !== null) {
                $line->delete();
            }
            $written = $this->store->writeLine($cart, $twin ?? $line, $item, $quote, $twin === null ? $instructions : $twin->special_instructions, $signature);
            $this->store->touch($cart, $menu);
            $this->after(new CartItemUpdated($cart, ['merged' => $twin !== null, 'quantity' => (int) $written->quantity]));

            return new CartChange($cart, (string) $written->public_id, $twin !== null);
        });
    }

    public function remove(CartOwner $owner, string $lineId, ?int $expectedVersion = null): CartChange
    {
        return $this->store->transaction(function () use ($owner, $lineId, $expectedVersion): CartChange {
            $cart = $this->lockOrFail($owner);
            $this->assertUsable($cart);
            $this->assertVersion($cart, $expectedVersion);
            $this->line($cart, $lineId)->delete();
            $this->store->touch($cart);
            $this->after(new CartItemRemoved($cart));

            return new CartChange($cart);
        });
    }

    /**
     * Ends the cart — the only way out of an expired one. Clearing twice is not an error.
     */
    public function clear(CartOwner $owner): bool
    {
        return $this->store->transaction(function () use ($owner): bool {
            $cart = $this->store->lock($owner);
            if ($cart === null) {
                return false;
            }
            $this->assertNotBeingPaid($cart);
            $cart->close($cart->isExpired() ? CartCloseReason::Expired : CartCloseReason::Cleared);
            $cart->save();
            $this->after(new CartCleared($cart));

            return true;
        });
    }

    /**
     * "Update my cart to the current menu": every line that can be priced today gets today's names and prices as
     * its new snapshot — the customer has seen the changed prices and goes on. Lines that cannot be ordered keep
     * their issue; nothing is removed.
     */
    public function refresh(CartOwner $owner, ?int $expectedVersion = null): CartChange
    {
        return $this->store->transaction(function () use ($owner, $expectedVersion): CartChange {
            $cart = $this->lockOrFail($owner);
            $this->assertUsable($cart);
            $this->assertVersion($cart, $expectedVersion);

            $inspection = $this->validation->inspect($cart);
            $changes = 0;
            foreach ($inspection->lines as $line) {
                if ($line->quote === null || $line->isBlocked()) {
                    continue;
                }
                $changes += $line->priceChanged() ? 1 : 0;
                $this->store->writeLine($cart, $line->line, $line->item, $line->quote, $line->line->special_instructions, (string) $line->line->signature);
            }
            $cart->unsetRelation('items');
            $this->store->touch($cart, $inspection->menu);
            $this->after(new CartRevalidated($cart, ['price_changes' => $changes]));

            return new CartChange($cart, extra: ['price_changes_accepted' => $changes]);
        });
    }

    /**
     * The confirmed switch: the current cart is closed and a cart of the other restaurant starts — together with
     * the item that prompted the question, when one is given — in ONE transaction. If anything about the new item
     * is invalid the whole switch is undone and the old cart is untouched.
     *
     * @param  array<string, mixed>  $input  restaurant_id, item (item_id, selections, quantity, special_instructions), journey_id, cart_version
     */
    public function switchRestaurant(CartOwner $owner, array $input, #[SensitiveParameter] ?string $journeyToken = null): CartChange
    {
        return $this->store->transaction(function () use ($owner, $input, $journeyToken): CartChange {
            $location = $this->visibleLocation((string) $input['restaurant_id']) ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant is not available on FoodOnTheGo.');
            $current = $this->store->lock($owner);
            $this->assertVersion($current, $input['cart_version'] ?? null);

            $replaced = null;
            $cart = $current;
            $sameRestaurant = $current !== null && ! $current->isExpired() && (int) $current->restaurant_location_id === (int) $location->getKey();
            if ($current !== null && ! $sameRestaurant) {
                $replaced = $this->validation->inspect($current)->summary();
                $current->close($current->isExpired() ? CartCloseReason::Expired : CartCloseReason::RestaurantSwitch);
                $current->save();
                $cart = null;
            }
            $created = $cart === null;
            $cart ??= $this->store->start($owner, $location);

            $line = null;
            $merged = false;
            $menu = null;
            if (isset($input['item'])) {
                [$line, $merged, $menu] = $this->put($cart, $location, (array) $input['item']);
            }
            $this->linkJourneyIfFree($cart, $owner, $location, $input['journey_id'] ?? null, $journeyToken);
            $this->store->touch($cart, $menu);

            if ($replaced !== null) {
                $this->after(new CartRestaurantSwitched($cart, ['replaced_items' => (int) $replaced['item_count']]));
            }
            if ($created) {
                $this->after(new CartCreated($cart));
            }
            if ($line !== null) {
                $this->after(new CartItemAdded($cart, ['merged' => $merged, 'quantity' => (int) $line->quantity]));
            }

            return new CartChange($cart, $line === null ? null : (string) $line->public_id, $merged, $created, $replaced);
        });
    }

    /**
     * Links the cart to a journey on purpose (the customer chose "use my current journey"). The journey must be
     * the caller's own and alive.
     */
    public function linkJourney(CartOwner $owner, string $journeyId, #[SensitiveParameter] ?string $journeyToken, ?int $expectedVersion = null): CartChange
    {
        return $this->store->transaction(function () use ($owner, $journeyId, $journeyToken, $expectedVersion): CartChange {
            $cart = $this->lockOrFail($owner);
            $this->assertUsable($cart);
            $this->assertVersion($cart, $expectedVersion);
            $journey = $this->journeys->resolve($owner, $journeyId, $journeyToken) ?? throw ApiException::notFound('journey_not_found', 'This journey was not found or has ended.');
            $this->journeys->attach($cart, $journey, $cart->location);
            $this->store->touch($cart);

            return new CartChange($cart);
        });
    }

    public function unlinkJourney(CartOwner $owner, ?int $expectedVersion = null): CartChange
    {
        return $this->store->transaction(function () use ($owner, $expectedVersion): CartChange {
            $cart = $this->lockOrFail($owner);
            $this->assertUsable($cart);
            $this->assertVersion($cart, $expectedVersion);
            $this->journeys->detach($cart);
            $this->store->touch($cart);

            return new CartChange($cart);
        });
    }

    /**
     * The cleanup: carts past their lifetime AND the grace period are closed as EXPIRED; closed carts older than the
     * retention period are deleted with their lines. A CONVERTED cart is the record an order was made from and is
     * never touched here.
     *
     * @return array{expired: int, deleted: int}
     */
    public function prune(?CarbonInterface $at = null): array
    {
        $now = $at ?? now();
        $expired = 0;
        Cart::query()->active()->where('expires_at', '<', $now->copy()->subHours(max(0, (int) config('cart.ttl.expired_grace_hours'))))
            ->chunkById(200, function ($carts) use (&$expired, $now): void {
                foreach ($carts as $cart) {
                    $cart->close(CartCloseReason::Expired, $now);
                    $cart->save();
                    event(new CartExpired($cart));
                    $expired++;
                }
            });

        $deleted = Cart::query()->whereIn('status', [CartStatus::Expired->value, CartStatus::Abandoned->value, CartStatus::Invalidated->value])
            ->where('closed_at', '<', $now->copy()->subDays(max(0, (int) config('cart.ttl.closed_retention_days'))))->delete();

        return ['expired' => $expired, 'deleted' => (int) $deleted];
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * Validates and writes one item into the cart.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: CartItem, 1: bool, 2: Menu} the line, whether an existing line was raised, the menu
     */
    private function put(Cart $cart, RestaurantLocation $location, array $input): array
    {
        $menu = $this->activeMenu($location);
        $item = $menu === null ? null : MenuItem::query()->where('menu_id', $menu->getKey())->where('public_id', (string) $input['item_id'])->customerVisible()
            ->with(['category', 'optionGroups.options'])->first();
        if ($menu === null || $item === null || $item->category?->status !== MenuCategoryStatus::Active) {
            // Also the answer for an item of another restaurant: this restaurant has no such item.
            throw ApiException::notFound('item_not_found', 'This item is not on the menu.');
        }
        $this->assertOrderable($item, $menu);
        $this->pricing->assertCurrency($location, $menu, $item, (string) $cart->currency);

        $selections = array_values((array) ($input['selections'] ?? []));
        $instructions = CartSignature::cleanInstructions($input['special_instructions'] ?? null);
        $quantity = (int) ($input['quantity'] ?? 1);
        $signature = CartSignature::of((string) $item->public_id, self::optionIds($selections), $instructions);

        $existing = CartItem::query()->where('cart_id', $cart->getKey())->where('signature', $signature)->first();
        $total = $quantity + (int) ($existing?->quantity ?? 0);
        if ($existing !== null && $total > (int) $item->max_quantity) {
            throw self::quantityLimit($item, (int) $existing->quantity, $quantity);
        }
        if ($existing === null && $cart->items()->count() >= (int) config('cart.limits.lines')) {
            throw new ApiException(422, 'cart_line_limit', 'This cart cannot hold more different items.', ['max_lines' => (int) config('cart.limits.lines')]);
        }
        $this->assertTotalQuantity($cart, $total, [$existing?->getKey()]);
        $quote = $this->pricing->quote($item, $selections, $total);

        // An equal configuration keeps the note as it was first written (the two only differed in spelling).
        return [$this->store->writeLine($cart, $existing, $item, $quote, $existing === null ? $instructions : $existing->special_instructions, $signature), $existing !== null, $menu];
    }

    private function visibleLocation(string $publicId): ?RestaurantLocation
    {
        $query = RestaurantLocation::query()->where('restaurant_locations.public_id', $publicId);
        $this->visibility->scopeVisible($query);

        return $query->first();
    }

    private function activeMenu(RestaurantLocation $location): ?Menu
    {
        return Menu::query()->active()->where('location_id', $location->getKey())->orderBy('display_order')->orderBy('id')->first();
    }

    /**
     * The menu item of an existing line, as it may be changed today: still on the active menu and orderable.
     */
    private function itemOfLine(CartItem $line, ?Menu $menu): MenuItem
    {
        $item = MenuItem::query()->whereKey($line->menu_item_id)->customerVisible()->with(['category', 'optionGroups.options'])->first();
        if ($menu === null || $item === null || (int) $item->menu_id !== (int) $menu->getKey() || $item->category?->status !== MenuCategoryStatus::Active) {
            throw ApiException::conflict('item_unavailable', 'This item is no longer on the menu. Remove it from your cart.', ['reason' => 'ITEM_REMOVED']);
        }
        $this->assertOrderable($item, $menu);

        return $item;
    }

    private function assertOrderable(MenuItem $item, Menu $menu): void
    {
        // The restaurant's open / paused state is not asked here (order ahead); the item's own state is.
        $availability = $this->menuAvailability->evaluate($item, $menu, $item->category, null);
        if (! $availability->orderable) {
            throw ApiException::conflict('item_unavailable', 'This item is not available right now.', ['reason' => $availability->reason?->value]);
        }
    }

    private function assertUsable(?Cart $cart): void
    {
        if ($cart !== null && $cart->isExpired()) {
            throw new ApiException(410, 'cart_expired', 'This cart has expired. Clear it to start a new one.', ['expired_at' => $cart->expires_at?->utc()->toIso8601String()]);
        }
        $this->assertNotBeingPaid($cart);
    }

    /**
     * Once a payment for the cart has been started (Module 29 / 30) nothing about it may change until the payment ends.
     */
    private function assertNotBeingPaid(?Cart $cart): void
    {
        if ($cart !== null && $cart->paymentInProgress()) {
            throw ApiException::conflict('cart_locked_for_payment', 'A payment for this cart is in progress. It cannot be changed until the payment has finished.');
        }
    }

    private function assertVersion(?Cart $cart, mixed $expected): void
    {
        if ($cart !== null && $expected !== null && (int) $expected !== (int) $cart->version) {
            throw ApiException::conflict('cart_version_conflict', 'Your cart changed somewhere else. Reload it and try again.', ['current_version' => (int) $cart->version]);
        }
    }

    /**
     * @param  list<int|null>  $exceptLineIds  lines whose quantity is replaced by $lineQuantity
     */
    private function assertTotalQuantity(Cart $cart, int $lineQuantity, array $exceptLineIds): void
    {
        $max = (int) config('cart.limits.total_quantity');
        if ($max <= 0) {
            return;
        }
        $others = (int) CartItem::query()->where('cart_id', $cart->getKey())->whereNotIn('id', array_values(array_filter($exceptLineIds)))->sum('quantity');
        if ($others + $lineQuantity > $max) {
            throw new ApiException(422, 'quantity_limit', 'This cart cannot hold that many items.', ['scope' => 'cart', 'max' => $max, 'in_cart' => $others]);
        }
    }

    private function lockOrFail(CartOwner $owner): Cart
    {
        return $this->store->lock($owner) ?? throw ApiException::notFound('cart_not_found', 'There is no cart to change.');
    }

    /**
     * A line of THIS cart. A line id of anybody else's cart is simply not found.
     */
    private function line(Cart $cart, string $lineId): CartItem
    {
        return CartItem::query()->where('cart_id', $cart->getKey())->where('public_id', $lineId)->with('options')->first()
            ?? throw ApiException::notFound('cart_line_not_found', 'This item is not in your cart.');
    }

    private function repoint(Cart $cart, RestaurantLocation $location): void
    {
        $cart->restaurant_location_id = $location->getKey();
        $cart->currency = (string) $location->currency;
        $cart->menu_catalog_version = null;
        $this->journeys->detach($cart);
        $cart->setRelation('location', $location);
    }

    /**
     * A journey sent along with an item is linked when the cart has no living link yet. A cart already linked to
     * another living journey keeps it: changing that is the customer's decision (linkJourney()).
     */
    private function linkJourneyIfFree(Cart $cart, CartOwner $owner, RestaurantLocation $location, mixed $journeyId, #[SensitiveParameter] ?string $journeyToken): void
    {
        if (! is_string($journeyId) || $journeyId === '') {
            return;
        }
        $journey = $this->journeys->resolve($owner, $journeyId, $journeyToken);
        if ($journey === null) {
            return;
        }
        if ($cart->journey_public_id === null || $cart->journey_public_id === $journey->public_id || ! $this->journeys->isLive($cart)) {
            $this->journeys->attach($cart, $journey, $location);
        }
    }

    private function restaurantConflict(Cart $cart, RestaurantLocation $requested): ApiException
    {
        $current = $this->validation->inspect($cart)->summary();
        event(new CartRestaurantConflict($cart));

        return ApiException::conflict('cart_restaurant_conflict', 'Your cart has items from another restaurant. A cart can only hold items from one restaurant.', [
            'current' => $current,
            'requested' => ['restaurant' => ['id' => $requested->public_id, 'slug' => $requested->slug, 'name' => $requested->name]],
            'cart_version' => (int) $cart->version,
        ]);
    }

    private static function quantityLimit(MenuItem $item, int $inCart, int $requested): ApiException
    {
        return new ApiException(422, 'quantity_limit', 'You already have this item in your cart; that many more would exceed the limit for it.', [
            'min' => (int) $item->min_quantity, 'max' => (int) $item->max_quantity, 'in_cart' => $inCart, 'requested' => $requested,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $selections
     * @return list<string>
     */
    private static function optionIds(array $selections): array
    {
        $ids = [];
        foreach ($selections as $selection) {
            foreach ((array) ($selection['option_ids'] ?? []) as $id) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Events tell the truth only about changes that were committed.
     */
    private function after(object $event): void
    {
        DB::afterCommit(fn () => event($event));
    }
}
