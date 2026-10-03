<?php

namespace Tests\Feature\Menu;

use App\Auth\AccessControl;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Market;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Restaurant\RestaurantStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsMenus;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Who may read and change a menu (Module 24): tenant isolation (another restaurant's menu does not exist for
 * you — 404 even with a known id), location scope, the view / manage permissions, and the administrators'
 * read-only oversight inside their market.
 */
class MenuAuthorizationTest extends TestCase
{
    use BuildsMenus, BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $riverside;

    private RestaurantOrganization $other;

    private RestaurantLocation $burger;

    private RestaurantLocation $brew;

    private RestaurantLocation $pizza;

    private MenuCategory $burgerCategory;

    private MenuItem $burgerItem;

    private MenuItem $pizzaItem;

    private MenuCategory $pizzaCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        $this->riverside = $this->organization('Riverside');
        $this->other = $this->organization('Second Kitchen');
        $this->burger = $this->location($this->riverside, 'Burger Hub', ['slug' => 'burger-hub']);
        $this->brew = $this->location($this->riverside, 'Brew & Bites', ['slug' => 'brew-bites']);
        $this->pizza = $this->location($this->other, 'Pizza Point', ['slug' => 'pizza-point']);
        $this->burgerCategory = $this->category($this->menuOf($this->burger));
        $this->burgerItem = $this->item($this->burgerCategory, 'Classic Burger', 24900, $this->standardGroups());
        $this->pizzaCategory = $this->category($this->menuOf($this->pizza));
        $this->pizzaItem = $this->item($this->pizzaCategory, 'Margherita', 24900);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function patchItem(MenuItem $item, array $body = ['version' => 1, 'name' => 'Taken over']): TestResponse
    {
        return $this->patchJson('/api/v1/restaurant/menu/items/'.$item->public_id, $body);
    }

    public function test_restaurant_a_cannot_see_or_change_restaurant_b_menu_even_with_known_ids(): void
    {
        $this->actingAsPrincipal($this->member($this->riverside, 'OWNER'));

        $this->getJson('/api/v1/restaurant/locations/'.$this->pizza->public_id.'/menu')->assertNotFound();
        $this->getJson('/api/v1/restaurant/menu/items/'.$this->pizzaItem->public_id)->assertNotFound();
        $this->patchItem($this->pizzaItem)->assertNotFound();
        $this->patchJson('/api/v1/restaurant/menu/items/'.$this->pizzaItem->public_id.'/status', ['status' => 'SOLD_OUT'])->assertNotFound();
        $this->deleteJson('/api/v1/restaurant/menu/items/'.$this->pizzaItem->public_id)->assertNotFound();
        $this->postJson('/api/v1/restaurant/menu/items/'.$this->pizzaItem->public_id.'/duplicate')->assertNotFound();
        $this->patchJson('/api/v1/restaurant/menu/categories/'.$this->pizzaCategory->public_id, ['version' => 1, 'name' => 'X'])->assertNotFound();
        $this->postJson('/api/v1/restaurant/locations/'.$this->pizza->public_id.'/menu/items', ['name' => 'X', 'category_id' => $this->pizzaCategory->public_id, 'base_price_minor' => 100])->assertNotFound();
        // an item of their own menu cannot be moved into the other restaurant's category either
        $this->patchItem($this->burgerItem, ['version' => 1, 'category_id' => $this->pizzaCategory->public_id])->assertUnprocessable();
        $this->assertSame('Margherita', $this->pizzaItem->fresh()->name);
        $this->assertSame('ACTIVE', $this->pizzaItem->fresh()->status->value);
    }

    public function test_staff_limited_to_one_location_cannot_manage_the_menu_of_another_location_of_the_same_restaurant(): void
    {
        $this->actingAsPrincipal($this->member($this->riverside, 'MENU_MANAGER', [$this->brew]));
        $this->getJson('/api/v1/restaurant/locations/'.$this->brew->public_id.'/menu')->assertOk();
        $this->getJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu')->assertNotFound();
        $this->patchItem($this->burgerItem)->assertNotFound();
        $this->postJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu/categories', ['name' => 'X'])->assertNotFound();
        $this->assertSame('Classic Burger', $this->burgerItem->fresh()->name);
    }

    public function test_a_viewer_may_read_the_menu_but_not_change_it(): void
    {
        $this->actingAsPrincipal($this->member($this->riverside, 'VIEWER'));
        $this->getJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu')->assertOk()->assertJsonPath('items.0.name', 'Classic Burger');
        $this->getJson('/api/v1/restaurant/menu/items/'.$this->burgerItem->public_id)->assertOk();
        $this->patchItem($this->burgerItem)->assertForbidden();
        $this->patchJson('/api/v1/restaurant/menu/items/'.$this->burgerItem->public_id.'/status', ['status' => 'SOLD_OUT'])->assertForbidden();
        $this->postJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu/categories', ['name' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu/items/status', ['items' => [$this->burgerItem->public_id], 'status' => 'SOLD_OUT'])->assertForbidden();
        $this->deleteJson('/api/v1/restaurant/menu/categories/'.$this->burgerCategory->public_id)->assertForbidden();
        $this->assertSame('ACTIVE', $this->burgerItem->fresh()->status->value);
    }

    public function test_a_menu_manager_manages_the_menu_only_in_their_scope(): void
    {
        $this->actingAsPrincipal($this->member($this->riverside, 'MENU_MANAGER'));
        $this->patchItem($this->burgerItem, ['version' => 1, 'name' => 'Classic Burger Deluxe'])->assertOk();
        $this->patchJson('/api/v1/restaurant/menu/items/'.$this->burgerItem->public_id.'/status', ['status' => 'SOLD_OUT'])->assertOk();
        $this->postJson('/api/v1/restaurant/locations/'.$this->brew->public_id.'/menu/categories', ['name' => 'Coffee'])->assertCreated();
        $this->patchItem($this->pizzaItem)->assertNotFound();
        // the menu permission does not open the profile or staff
        $this->patchJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/profile', ['version' => 1, 'name' => 'X'])->assertForbidden();
    }

    public function test_order_staff_have_the_menu_view_permission_only(): void
    {
        $this->actingAsPrincipal($this->member($this->riverside, 'ORDER_STAFF', [$this->burger]));
        $this->getJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu')->assertOk();
        $this->patchItem($this->burgerItem)->assertForbidden();
    }

    public function test_without_a_token_or_with_a_customer_or_admin_token_the_restaurant_menu_api_is_401(): void
    {
        $this->getJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu')->assertUnauthorized();
        $this->actingAsPrincipal($this->adminWith([Permission::AdminRestaurantsView]));
        $this->getJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu')->assertUnauthorized();
    }

    public function test_administrators_read_a_menu_inside_their_market_and_never_write(): void
    {
        $admin = $this->adminWith([Permission::AdminRestaurantsView], $this->market);
        $this->actingAsPrincipal($admin);
        $doc = $this->getJson('/api/v1/admin/restaurants/'.$this->burger->public_id.'/menu')->assertOk()->json();
        $this->assertSame(['categories' => 1, 'items' => 1, 'active_items' => 1], array_intersect_key($doc['summary'], ['categories' => 1, 'items' => 1, 'active_items' => 1]));
        $this->assertSame('Classic Burger', $doc['categories'][0]['items'][0]['name']);
        $this->assertSame(24900, $doc['categories'][0]['items'][0]['base_price_minor']);
        $this->assertArrayNotHasKey('cost_price_minor', $doc['categories'][0]['items'][0]);

        // administrators have no write routes: the restaurant routes refuse an admin token
        $this->patchItem($this->burgerItem)->assertUnauthorized();

        // the permission held for another market does not reach this one; no permission at all: forbidden
        $this->actingAsPrincipal($this->adminWith([Permission::AdminRestaurantsView], Market::factory()->create()));
        $this->getJson('/api/v1/admin/restaurants/'.$this->burger->public_id.'/menu')->assertForbidden();
        $this->actingAsPrincipal($this->adminWith([Permission::AdminCustomersView]));
        $this->getJson('/api/v1/admin/restaurants/'.$this->burger->public_id.'/menu')->assertForbidden();
    }

    public function test_a_restaurant_user_who_lost_their_membership_loses_the_menu_at_once(): void
    {
        $user = $this->member($this->riverside, 'MENU_MANAGER');
        $this->actingAsPrincipal($user);
        $this->getJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu')->assertOk();
        $membership = $this->membershipOf($user, $this->riverside);
        $membership->forceFill(['status' => MembershipStatus::Suspended])->save();
        app(RestaurantStaffService::class)->sync($membership);
        app(AccessControl::class)->flush();
        $this->getJson('/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu')->assertNotFound();
        $this->patchItem($this->burgerItem)->assertNotFound();
    }
}
