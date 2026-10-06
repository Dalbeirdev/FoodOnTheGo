<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use Illuminate\Testing\TestResponse;

/**
 * Carts for the Module 27 tests, on top of the journey world (restaurants, menus, customers, journeys).
 *
 * The standard restaurant is "Burger Hub" (open 09:00–22:00, ACTIVE menu) with:
 *   Classic Burger  ₹200  · Size (required, one of): Regular +₹0 / Large +₹50
 *                           Add-ons (optional, up to 3): Extra Cheese +₹20 / Jalapeños +₹10 / Olives +₹15 / Fried Egg +₹30 (temporarily unavailable)
 *   Masala Fries    ₹120  · no options
 * Amounts are paise: ₹200 = 20000.
 */
trait BuildsCarts
{
    use BuildsJourneys;

    protected RestaurantLocation $burgerHub;

    protected MenuCategory $mains;

    protected MenuItem $burger;

    protected MenuItem $fries;

    private ?RestaurantUser $staffUser = null;

    protected function setUpCartWorld(): void
    {
        $this->setUpRestaurantWorld();
        $this->burgerHub = $this->eligible('Burger Hub', 28.55, 77.35);
        $this->mains = $this->category($this->menuOf($this->burgerHub), 'Mains');
        $this->burger = $this->item($this->mains, 'Classic Burger', 20000, $this->standardGroups());
        $this->fries = $this->item($this->mains, 'Masala Fries', 12000);
    }

    /**
     * A second restaurant with one plain item (₹150), for the one-restaurant rule.
     *
     * @return array{0: RestaurantLocation, 1: MenuItem}
     */
    protected function otherRestaurant(string $name = 'Pizza Point', float $lat = 28.56, float $lng = 77.36): array
    {
        $location = $this->eligible($name, $lat, $lng);

        return [$location, $this->item($this->category($this->menuOf($location), 'Pizzas'), 'Margherita', 15000)];
    }

    /**
     * A guest as a client holds it: the token GET /cart hands out, as the header to send.
     *
     * @return array{X-Cart-Token: string}
     */
    protected function guest(): array
    {
        $this->app['auth']->forgetGuards();

        return ['X-Cart-Token' => (string) $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('cart', null)->json('guest_token')];
    }

    /**
     * Selections in the API's form from names: ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']].
     *
     * @param  array<string, list<string>>  $chosen
     * @return list<array{group_id: string, option_ids: list<string>}>
     */
    protected function choose(MenuItem $item, array $chosen): array
    {
        $item->load('optionGroups.options');
        $out = [];
        foreach ($chosen as $groupName => $optionNames) {
            $group = $item->optionGroups->firstWhere('name', $groupName);
            $out[] = ['group_id' => $group->public_id, 'option_ids' => array_map(fn (string $name): string => $group->options->firstWhere('name', $name)->public_id, $optionNames)];
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $chosen
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function addBody(MenuItem $item, array $chosen = [], int $quantity = 1, array $extra = [], ?RestaurantLocation $location = null): array
    {
        $location ??= $item->menu->location;

        return ['restaurant_id' => $location->public_id, 'item_id' => $item->public_id, 'selections' => $this->choose($item, $chosen), 'quantity' => $quantity, ...$extra];
    }

    /**
     * The usual burger: Regular, nothing else.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    protected function addBurger(array $headers, int $quantity = 1, array $chosen = ['Size' => ['Regular']], array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/cart/items', $this->addBody($this->burger, $chosen, $quantity, $extra), $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function cartOf(array $headers = []): TestResponse
    {
        return $this->getJson('/api/v1/cart', $headers)->assertOk();
    }

    /**
     * A signed-in customer as a client holds them: a real session header.
     *
     * @return array{0: Customer, 1: array{Authorization: string}}
     */
    protected function signedIn(string $phone = '+919876543210'): array
    {
        $customer = $this->customer($this->market, ['phone_e164' => $phone]);

        return [$customer, $this->sessionOf($customer)];
    }

    /**
     * @param  list<string>  $codes
     */
    protected function assertIssueCodes(array $codes, TestResponse $response): void
    {
        $this->assertEqualsCanonicalizing($codes, array_column((array) $response->json('cart.validation.issues'), 'code'));
    }

    /**
     * The owner of the standard restaurant's organization — the actor of menu changes "from the dashboard".
     */
    protected function staff(): RestaurantUser
    {
        return $this->staffUser ??= $this->member($this->organization);
    }

    protected function activeCarts(): int
    {
        return Cart::query()->active()->count();
    }
}
