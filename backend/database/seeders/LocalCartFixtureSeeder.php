<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Services\Cart\CartOwner;
use App\Services\Cart\CartService;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT CART FIXTURE — local and testing only, never production.
 *
 * Asha Verma (+91 98765 43212, the second fixture customer) gets one cart at Pizza Point, built through
 * CartService like any other cart — so it is priced and validated by the backend:
 *
 *   2 × Margherita           Medium (10") +₹150 · Thin crust · Extra Cheese +₹60 · "Well done, please"   ₹459.00 each
 *   1 × Cheese Garlic Bread                                                                              ₹149.00
 *                                                                                              subtotal ₹1,067.00
 *
 * Rahul (the account most review flows sign in with) deliberately has NO fixture cart: his cart starts empty.
 * Re-seeding leaves an existing cart of Asha untouched.
 */
class LocalCartFixtureSeeder extends Seeder
{
    public function run(CartService $carts): void
    {
        $asha = Customer::query()->where('phone_e164', '+919876543212')->first();
        $pizzaPoint = RestaurantLocation::query()->where('slug', 'pizza-point')->first();
        if ($asha === null || $pizzaPoint === null || Cart::query()->where('customer_id', $asha->getKey())->exists()) {
            return;
        }
        $item = fn (string $name): ?MenuItem => MenuItem::query()->where('name', $name)->whereHas('menu', fn ($q) => $q->where('location_id', $pizzaPoint->getKey()))->with('optionGroups.options')->first();
        $margherita = $item('Margherita');
        $garlicBread = $item('Cheese Garlic Bread');
        if ($margherita === null || $garlicBread === null) {
            return;
        }
        $choose = function (MenuItem $menuItem, array $chosen): array {
            $selections = [];
            foreach ($chosen as $groupName => $optionNames) {
                $group = $menuItem->optionGroups->firstWhere('name', $groupName);
                $selections[] = ['group_id' => $group->public_id, 'option_ids' => $group->options->whereIn('name', $optionNames)->pluck('public_id')->values()->all()];
            }

            return $selections;
        };

        $owner = CartOwner::customer($asha);
        $carts->add($owner, [
            'restaurant_id' => $pizzaPoint->public_id, 'item_id' => $margherita->public_id, 'quantity' => 2, 'special_instructions' => 'Well done, please',
            'selections' => $choose($margherita, ['Size' => ['Medium (10")'], 'Crust' => ['Thin crust'], 'Extra toppings' => ['Extra Cheese']]),
        ]);
        $carts->add($owner, ['restaurant_id' => $pizzaPoint->public_id, 'item_id' => $garlicBread->public_id, 'quantity' => 1]);
    }
}
