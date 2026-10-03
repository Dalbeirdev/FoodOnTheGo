<?php

namespace Tests\Feature\Menu;

use App\Enums\MenuItemStatus;
use App\Enums\Permission;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsMenus;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Administrators see a restaurant's menu as customers do, plus the counts behind it — and nothing else
 * (Module 24: read-only oversight with admin.restaurants.view in the location's market).
 */
class AdminMenuOversightTest extends TestCase
{
    use BuildsMenus, BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        $this->organization = $this->organization('Riverside');
        $this->burger = $this->hours($this->location($this->organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '00:00', '00:00']]);
        $this->actingAsPrincipal($this->adminWith([Permission::AdminRestaurantsView], $this->market));
    }

    public function test_the_summary_counts_every_state_and_the_document_is_the_customers_view_with_the_hidden_categories_named(): void
    {
        $service = app(MenuService::class);
        $owner = $this->member($this->organization, 'OWNER');
        $menu = $this->menuOf($this->burger);
        $burgers = $this->category($menu, 'Burgers');
        $seasonal = $this->category($menu, 'Seasonal');
        $this->item($burgers, 'Classic Burger', 24900, $this->standardGroups());
        $service->setItemStatus($this->item($burgers, 'Smoky BBQ', 29900), MenuItemStatus::SoldOut, $owner);
        $service->setItemStatus($this->item($burgers, 'Paneer Burger', 21900), MenuItemStatus::TemporarilyUnavailable, $owner);
        $service->setItemStatus($this->item($burgers, 'Draft Burger', 19900), MenuItemStatus::Disabled, $owner);
        $service->archiveItem($this->item($burgers, 'Old Wrap', 15900), $owner);
        $this->item($seasonal, 'Winter Special', 34900);
        $service->updateCategory($seasonal, ['version' => 1, 'status' => 'INACTIVE'], $owner);

        $doc = $this->getJson('/api/v1/admin/restaurants/'.$this->burger->public_id.'/menu')->assertOk()->json();

        $this->assertSame([
            'categories' => 2, 'inactive_categories' => 1, 'items' => 5, 'active_items' => 2, 'sold_out_items' => 1, 'unavailable_items' => 1, 'disabled_items' => 1, 'archived_items' => 1, 'customizable_items' => 1,
        ], array_diff_key($doc['summary'], ['last_changed_at' => 1]));
        $this->assertNotNull($doc['summary']['last_changed_at']);
        $this->assertSame(['ACTIVE', 'INR'], [$doc['menu']['status'], $doc['menu']['currency']]);
        $this->assertSame(['Burgers'], array_column($doc['categories'], 'name'), 'as customers see it');
        $this->assertSame(['Classic Burger', 'Smoky BBQ', 'Paneer Burger'], array_column($doc['categories'][0]['items'], 'name'));
        $this->assertSame(24900, $doc['categories'][0]['items'][0]['base_price_minor']);
        $this->assertSame('ITEM_SOLD_OUT', $doc['categories'][0]['items'][1]['availability']['reason']);
        $this->assertSame([['id' => $seasonal->public_id, 'name' => 'Seasonal']], $doc['inactive_categories']);
        $this->assertTrue($doc['availability']['visible_to_customers']);
        $this->assertTrue($doc['availability']['orderable']);
    }

    public function test_a_restaurant_without_a_menu_yet_gives_zero_counts(): void
    {
        $doc = $this->getJson('/api/v1/admin/restaurants/'.$this->burger->public_id.'/menu')->assertOk()->json();
        $this->assertNull($doc['menu']);
        $this->assertSame(0, $doc['summary']['items']);
        $this->assertSame([], $doc['categories']);
        $this->assertDatabaseCount('menus', 0); // oversight never creates a menu
    }

    public function test_an_unpublished_menu_is_still_shown_to_administrators_with_its_status(): void
    {
        $menu = $this->menuOf($this->burger);
        $this->item($this->category($menu), 'Chai', 2000);
        app(MenuService::class)->updateMenu($menu, ['version' => 1, 'status' => 'INACTIVE'], $this->member($this->organization, 'OWNER'));

        $doc = $this->getJson('/api/v1/admin/restaurants/'.$this->burger->public_id.'/menu')->assertOk()->json();
        $this->assertSame('INACTIVE', $doc['menu']['status']);
        $this->assertSame(1, $doc['summary']['items']);
        $this->assertSame('MENU_INACTIVE', $doc['categories'][0]['items'][0]['availability']['reason'] ?? null);
    }

    public function test_the_route_is_read_only_and_needs_the_view_permission(): void
    {
        $this->menuOf($this->burger);
        $url = '/api/v1/admin/restaurants/'.$this->burger->public_id.'/menu';
        $this->patchJson($url, ['name' => 'X'])->assertStatus(405);
        $this->postJson($url, ['name' => 'X'])->assertStatus(405);
        $this->deleteJson($url)->assertStatus(405);

        $this->actingAsPrincipal($this->adminWith([Permission::AdminOrdersView], $this->market));
        $this->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }
}
