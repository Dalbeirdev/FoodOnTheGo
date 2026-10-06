<?php

namespace App\Services\Cart;

use App\Enums\CartStatus;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemOption;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\MenuOptionGroup;
use App\Models\RestaurantLocation;
use App\Services\Menu\PriceQuote;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The few ways a cart row is ever written — shared by CartService (the owner's own changes) and CartClaimService
 * (sign-in). Everything runs inside the caller's transaction with the owner's ACTIVE cart row locked, so two
 * requests for the same cart are applied one after the other, never interleaved.
 */
final class CartStore
{
    /**
     * Runs the work in a transaction. Two concurrent "first add" requests of one owner both try to create the
     * ACTIVE cart; the database lets one win (partial unique index) and the other simply runs again and finds it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($work);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw ApiException::conflict('cart_busy', 'Your cart is being changed from somewhere else. Please try again.');
                }
            }
        }
    }

    /**
     * The owner's ACTIVE cart, locked for the rest of the transaction.
     */
    public function lock(CartOwner $owner): ?Cart
    {
        $query = Cart::query()->active()->lockForUpdate();
        $owner->constrain($query);

        return $query->first();
    }

    public function start(CartOwner $owner, RestaurantLocation $location): Cart
    {
        $now = now();
        $cart = (new Cart)->forceFill([
            'customer_id' => $owner->customer?->getKey(),
            'guest_token_hash' => $owner->isGuest() ? $owner->tokenHash : null,
            'restaurant_location_id' => $location->getKey(),
            'status' => CartStatus::Active,
            'currency' => (string) $location->currency,
            'version' => 0,
            'expires_at' => $now->copy()->addHours(self::ttlHours($owner->isGuest())),
            'last_activity_at' => $now,
        ]);
        $cart->save();
        $cart->setRelation('location', $location);

        return $cart;
    }

    /**
     * Every change ends here: a new version, a renewed lifetime and the menu version the cart was written against.
     */
    public function touch(Cart $cart, ?Menu $menu = null): void
    {
        $now = now();
        $cart->version = (int) $cart->version + 1;
        $cart->last_activity_at = $now;
        $cart->expires_at = $now->copy()->addHours(self::ttlHours($cart->isGuest()));
        if ($menu !== null) {
            $cart->menu_catalog_version = (int) $menu->catalog_version;
        }
        $cart->save();
    }

    /**
     * Writes a line — a new one, or an existing one being changed — from the backend's own quote: quantity, the
     * snapshot of names and prices, and the selected options as rows. Nothing here comes from a client's numbers.
     */
    public function writeLine(Cart $cart, ?CartItem $line, MenuItem $item, PriceQuote $quote, ?string $instructions, string $signature): CartItem
    {
        $line ??= new CartItem;
        $line->forceFill([
            'cart_id' => $cart->getKey(),
            'menu_item_id' => $item->getKey(),
            'quantity' => $quote->quantity,
            'item_name' => (string) $item->name,
            'base_price_minor' => $quote->basePriceMinor,
            'unit_price_minor' => $quote->unitPriceMinor,
            'currency' => $quote->currency,
            'item_version' => (int) $item->version,
            'special_instructions' => $instructions,
            'signature' => $signature,
        ])->save();

        $groups = $item->optionGroups->keyBy('public_id');
        $rows = [];
        $order = 0;
        foreach ($quote->selections as $selection) {
            /** @var MenuOptionGroup $group */
            $group = $groups->get($selection['group_id']);
            foreach ($selection['options'] as $option) {
                $rows[] = [
                    'cart_item_id' => $line->getKey(),
                    'group_id' => $group->getKey(),
                    'option_id' => $group->options->firstWhere('public_id', $option['id'])->getKey(),
                    'kind' => $selection['kind'],
                    'group_name' => $selection['group_name'],
                    'option_name' => $option['name'],
                    'price_adjustment_minor' => $option['price_adjustment_minor'],
                    'display_order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        CartItemOption::query()->where('cart_item_id', $line->getKey())->delete();
        if ($rows !== []) {
            CartItemOption::query()->insert($rows);
        }
        $line->unsetRelation('options');

        return $line;
    }

    public static function ttlHours(bool $guest): int
    {
        return max(1, (int) config($guest ? 'cart.ttl.guest_hours' : 'cart.ttl.customer_hours'));
    }
}
