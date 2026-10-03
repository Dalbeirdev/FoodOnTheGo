<?php

namespace Tests\Feature\Menu;

use App\Enums\MenuItemStatus;
use App\Enums\MenuOptionStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use App\Services\Menu\MenuCatalog;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsMenus;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * The menu as customers read it (Module 24): only what they may see, the backend's availability answer on
 * every item, the item page with its options, and the price quote — the only price a client ever gets.
 */
class CustomerMenuApiTest extends TestCase
{
    use BuildsMenus, BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    private Menu $menu;

    private MenuCategory $burgers;

    private MenuItem $classic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        $this->organization = $this->organization('Riverside');
        $this->burger = $this->hours($this->location($this->organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '00:00', '00:00']]);
        $this->menu = $this->menuOf($this->burger);
        $this->burgers = $this->category($this->menu, 'Burgers');
        $this->classic = $this->item($this->burgers, 'Classic Burger', 20000, $this->standardGroups(), ['dietary_tags' => ['NON_VEGETARIAN'], 'preparation_minutes' => 12, 'featured' => true, 'allergen_information' => 'Contains gluten.']);
    }

    private function owner(): RestaurantUser
    {
        return $this->member($this->organization, 'OWNER');
    }

    /**
     * @return array<string, mixed>
     */
    private function menuDocument(string $slug = 'burger-hub'): array
    {
        return $this->getJson('/api/v1/restaurants/'.$slug.'/menu')->assertOk()->json();
    }

    /**
     * @return list<string>
     */
    private function visibleItemNames(string $slug = 'burger-hub'): array
    {
        $names = [];
        foreach ($this->menuDocument($slug)['categories'] as $category) {
            foreach ($category['items'] as $item) {
                $names[] = $item['name'];
            }
        }

        return $names;
    }

    public function test_the_menu_document_carries_the_restaurant_its_availability_categories_items_and_the_tags_in_use(): void
    {
        $this->item($this->category($this->menu, 'Sides'), 'Fries', 9900, [], ['dietary_tags' => ['VEGETARIAN', 'VEGAN']]);
        $doc = $this->menuDocument();

        $this->assertSame(['id' => $this->burger->public_id, 'slug' => 'burger-hub', 'name' => 'Burger Hub', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata'], $doc['restaurant']);
        $this->assertTrue($doc['availability']['orderable']);
        $this->assertSame(['Menu', 'INR'], [$doc['menu']['name'], $doc['menu']['currency']]);
        $this->assertSame(['Burgers', 'Sides'], array_column($doc['categories'], 'name'));
        $item = $doc['categories'][0]['items'][0];
        $this->assertSame(['Classic Burger', 'classic-burger', 20000, 'INR', 'ACTIVE', true, 12, true], [$item['name'], $item['slug'], $item['base_price_minor'], $item['currency'], $item['status'], $item['customizable'], $item['preparation_minutes'], $item['featured']]);
        $this->assertSame([['code' => 'NON_VEGETARIAN', 'name' => 'Non-vegetarian']], $item['dietary_tags']);
        $this->assertSame(['visible' => true, 'orderable' => true, 'reason' => null, 'restaurant_reason' => null], $item['availability']);
        $this->assertFalse($doc['categories'][1]['items'][0]['customizable']);
        $this->assertSame(['NON_VEGETARIAN', 'VEGETARIAN', 'VEGAN'], array_column($doc['dietary_tags'], 'code'));
        // nothing internal leaks: no numeric ids, versions of groups or staff fields
        $this->assertArrayNotHasKey('_status_reason', $item);
        $this->assertArrayNotHasKey('menu_id', $item);
        $this->assertArrayNotHasKey('location', $doc);
    }

    public function test_a_restaurant_customers_may_not_see_has_no_menu_and_an_unknown_slug_is_the_same_404(): void
    {
        $hidden = $this->location($this->organization, 'Hidden Kitchen', ['slug' => 'hidden-kitchen', 'status' => 'SUSPENDED']);
        $this->item($this->category($this->menuOf($hidden)), 'Secret');
        $this->getJson('/api/v1/restaurants/hidden-kitchen/menu')->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found');
        $this->getJson('/api/v1/restaurants/hidden-kitchen/items/secret')->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found');
        $this->getJson('/api/v1/restaurants/no-such-place/menu')->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found');
    }

    public function test_an_inactive_menu_gives_an_empty_answer_and_the_item_pages_disappear(): void
    {
        app(MenuService::class)->updateMenu($this->menu, ['version' => 1, 'status' => 'INACTIVE'], $this->owner());
        $doc = $this->menuDocument();
        $this->assertNull($doc['menu']);
        $this->assertSame([], $doc['categories']);
        $this->assertTrue($doc['availability']['orderable'], 'the restaurant itself is still open');
        $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->assertNotFound()->assertJsonPath('error.code', 'item_not_found');
    }

    public function test_inactive_categories_disabled_and_archived_items_are_hidden_while_sold_out_and_unavailable_items_stay_visible_but_not_orderable(): void
    {
        $service = app(MenuService::class);
        $owner = $this->owner();
        $seasonal = $this->category($this->menu, 'Seasonal');
        $this->item($seasonal, 'Winter Special');
        $service->updateCategory($seasonal, ['version' => 1, 'status' => 'INACTIVE'], $owner);
        $service->setItemStatus($this->item($this->burgers, 'Smoky BBQ'), MenuItemStatus::SoldOut, $owner);
        $service->setItemStatus($this->item($this->burgers, 'Paneer Burger'), MenuItemStatus::TemporarilyUnavailable, $owner);
        $service->setItemStatus($this->item($this->burgers, 'Draft Burger'), MenuItemStatus::Disabled, $owner);
        $service->archiveItem($this->item($this->burgers, 'Old Wrap'), $owner);

        $this->assertSame(['Classic Burger', 'Smoky BBQ', 'Paneer Burger'], $this->visibleItemNames());
        $items = collect($this->menuDocument()['categories'][0]['items'])->keyBy('name');
        $this->assertSame(['visible' => true, 'orderable' => false, 'reason' => 'ITEM_SOLD_OUT', 'restaurant_reason' => null], $items['Smoky BBQ']['availability']);
        $this->assertSame('ITEM_TEMPORARILY_UNAVAILABLE', $items['Paneer Burger']['availability']['reason']);
        $this->assertSame('SOLD_OUT', $items['Smoky BBQ']['status']);

        foreach (['winter-special', 'draft-burger', 'old-wrap'] as $slug) {
            $this->getJson('/api/v1/restaurants/burger-hub/items/'.$slug)->assertNotFound()->assertJsonPath('error.code', 'item_not_found');
        }
        // a sold-out item still has a page (customers see why they cannot add it)
        $this->getJson('/api/v1/restaurants/burger-hub/items/smoky-bbq')->assertOk()->assertJsonPath('data.availability.reason', 'ITEM_SOLD_OUT')->assertJsonPath('data.availability.orderable', false);
    }

    public function test_the_item_page_shows_the_groups_with_only_the_options_a_customer_may_see(): void
    {
        $service = app(MenuService::class);
        $detail = $this->getJson('/api/v1/restaurant/menu/items/'.$this->classic->public_id, [])->json(); // unauthenticated: 401, used below only for ids
        $this->assertNull($detail['variant_groups'] ?? null);

        $groups = $this->standardGroups();
        $groups[1]['options'][] = ['name' => 'Hidden Sauce', 'price_adjustment_minor' => 500, 'status' => 'DISABLED'];
        $groups[] = ['kind' => 'MODIFIER', 'name' => 'Old group', 'status' => 'INACTIVE', 'options' => [['name' => 'Old', 'price_adjustment_minor' => 0]]];
        $service->updateItem($this->classic, ['version' => 1, 'groups' => $groups], $this->owner());

        $page = $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->assertOk()->json();
        $data = $page['data'];
        $this->assertSame(['id' => $this->burger->public_id, 'slug' => 'burger-hub', 'name' => 'Burger Hub'], $data['restaurant']);
        $this->assertSame('Burgers', $data['category']['name']);
        $this->assertSame([1, 20, 200, 'Contains gluten.'], [$data['min_quantity'], $data['max_quantity'], $data['instructions_max_length'], $data['allergen_information']]);
        $this->assertSame(['Size'], array_column($data['variant_groups'], 'name'));
        $this->assertSame(['Add-ons'], array_column($data['modifier_groups'], 'name'), 'the INACTIVE group is not shown');
        $size = $data['variant_groups'][0];
        $this->assertSame([true, 1, 1, 'VARIANT'], [$size['required'], $size['min_selections'], $size['max_selections'], $size['kind']]);
        $this->assertSame([['Regular', 0, true, true], ['Large', 5000, true, false]], array_map(fn (array $o): array => [$o['name'], $o['price_adjustment_minor'], $o['available'], $o['default_selected']], $size['options']));
        $addons = collect($data['modifier_groups'][0]['options'])->keyBy('name');
        $this->assertSame(['Extra Cheese', 'Jalapeños', 'Olives', 'Fried Egg'], $addons->keys()->all(), 'the DISABLED option is not shown; the temporarily unavailable one is');
        $this->assertFalse($addons['Fried Egg']['available']);
        $this->assertTrue($addons['Extra Cheese']['available']);
        $this->assertArrayNotHasKey('status', $addons['Fried Egg']);
        $this->assertTrue($page['restaurant_availability']['orderable']);
    }

    public function test_an_item_whose_required_group_has_no_available_option_is_visible_but_not_orderable(): void
    {
        MenuOption::query()->whereIn('name', ['Regular', 'Large'])->update(['status' => MenuOptionStatus::TemporarilyUnavailable->value]);
        app(MenuCatalog::class)->bump($this->menu);

        $item = $this->menuDocument()['categories'][0]['items'][0];
        $this->assertSame(['visible' => true, 'orderable' => false, 'reason' => 'REQUIRED_GROUP_UNAVAILABLE', 'restaurant_reason' => null], $item['availability']);
    }

    public function test_when_the_restaurant_is_not_orderable_every_item_says_so_without_a_catalog_change(): void
    {
        $before = $this->menuDocument();
        $this->burger->forceFill(['accepting_orders' => false, 'paused_at' => now(), 'pause_reason' => 'Rush hour'])->save();

        $doc = $this->menuDocument();
        $this->assertSame($before['menu']['catalog_version'], $doc['menu']['catalog_version'], 'the cached catalog is reused');
        $this->assertFalse($doc['availability']['orderable']);
        $this->assertSame('NOT_ACCEPTING_ORDERS', $doc['availability']['reason']);
        $this->assertSame(['visible' => true, 'orderable' => false, 'reason' => 'RESTAURANT_UNAVAILABLE', 'restaurant_reason' => 'NOT_ACCEPTING_ORDERS'], $doc['categories'][0]['items'][0]['availability']);
        $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->assertOk()->assertJsonPath('data.availability.reason', 'RESTAURANT_UNAVAILABLE')->assertJsonPath('restaurant_availability.reason', 'NOT_ACCEPTING_ORDERS');
    }

    public function test_a_price_or_status_change_is_visible_to_customers_at_once_despite_the_cache(): void
    {
        $service = app(MenuService::class);
        $owner = $this->owner();
        $this->assertSame(20000, $this->menuDocument()['categories'][0]['items'][0]['base_price_minor']);
        $this->assertSame(20000, $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->json('data.base_price_minor'));

        $service->updateItem($this->classic, ['version' => 1, 'base_price_minor' => 21000], $owner);
        $this->assertSame(21000, $this->menuDocument()['categories'][0]['items'][0]['base_price_minor']);
        $this->assertSame(21000, $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->json('data.base_price_minor'));

        $service->setItemStatus($this->classic, MenuItemStatus::SoldOut, $owner);
        $this->assertSame('ITEM_SOLD_OUT', $this->menuDocument()['categories'][0]['items'][0]['availability']['reason']);

        $service->setItemStatus($this->classic, MenuItemStatus::Disabled, $owner);
        $this->assertSame([], $this->visibleItemNames());
        $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->assertNotFound();
    }

    public function test_the_menu_costs_the_same_number_of_queries_whatever_its_size(): void
    {
        $small = $this->hours($this->location($this->organization, 'Small Cafe', ['slug' => 'small-cafe']), [[null, '00:00', '00:00']]);
        $large = $this->hours($this->location($this->organization, 'Large Cafe', ['slug' => 'large-cafe']), [[null, '00:00', '00:00']]);
        $this->item($this->category($this->menuOf($small), 'Only'), 'Single', 1000, $this->standardGroups(), ['dietary_tags' => ['VEGETARIAN']]);
        foreach (['Starters', 'Mains', 'Breads', 'Desserts'] as $name) {
            $category = $this->category($this->menuOf($large), $name);
            for ($i = 1; $i <= 10; $i++) {
                $this->item($category, "$name $i", 1000 * $i, $i % 2 === 0 ? $this->standardGroups() : [], ['dietary_tags' => $i % 3 === 0 ? ['VEGETARIAN', 'JAIN'] : ['NON_VEGETARIAN']]);
            }
        }

        $count = function (string $slug): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/restaurants/'.$slug.'/menu')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->assertSame($count('small-cafe'), $count('large-cafe'), 'no query per item or per category');
        $this->assertCount(40, array_merge(...array_column($this->menuDocument('large-cafe')['categories'], 'items')));
    }

    public function test_the_backend_prices_a_selection_and_a_client_price_is_refused(): void
    {
        $this->classic->load('optionGroups.options');
        $size = $this->groupAndOption($this->classic, 'Size', 'Large');
        $cheese = $this->groupAndOption($this->classic, 'Add-ons', 'Extra Cheese');
        $url = '/api/v1/restaurants/burger-hub/items/classic-burger/price-quote';

        // ₹200 + ₹50 + ₹20 = ₹270, twice
        $quote = $this->postJson($url, ['selections' => [
            ['group_id' => $size['group']->public_id, 'option_ids' => [$size['option']->public_id]],
            ['group_id' => $cheese['group']->public_id, 'option_ids' => [$cheese['option']->public_id]],
        ], 'quantity' => 2])->assertOk()->json();
        $this->assertSame([20000, 7000, 27000, 2, 54000, 'INR'], [$quote['data']['base_price_minor'], $quote['data']['adjustments_minor'], $quote['data']['unit_price_minor'], $quote['data']['quantity'], $quote['data']['line_total_minor'], $quote['data']['currency']]);
        $this->assertSame($this->classic->public_id, $quote['data']['item_id']);
        $this->assertSame((int) $this->menu->fresh()->catalog_version, $quote['data']['catalog_version']);
        $this->assertTrue($quote['availability']['orderable']);
        $this->assertSame('Large', $quote['data']['selections'][0]['options'][0]['name']);

        // the client's idea of a price is ignored — refused, not silently dropped
        $this->postJson($url, ['selections' => [['group_id' => $size['group']->public_id, 'option_ids' => [$size['option']->public_id]]], 'unit_price_minor' => 1])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.fields.unit_price_minor.0', 'Prices are calculated by FoodOnTheGo.');
        // a required group left out
        $this->postJson($url, ['selections' => [], 'quantity' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_selection')->assertJsonPath('error.details.issues.0.code', 'required');
        // too many of a thing
        $this->postJson($url, ['selections' => [['group_id' => $size['group']->public_id, 'option_ids' => [$size['option']->public_id]]], 'quantity' => 21])->assertUnprocessable()->assertJsonPath('error.details.issues.0.code', 'quantity');
        $this->postJson($url, ['selections' => [['group_id' => $size['group']->public_id, 'option_ids' => [$size['option']->public_id]]], 'quantity' => 0])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        // an option of another item
        $other = $this->item($this->burgers, 'Other', 1000, $this->standardGroups());
        $foreign = $this->groupAndOption($other, 'Size', 'Large');
        $this->postJson($url, ['selections' => [['group_id' => $foreign['group']->public_id, 'option_ids' => [$foreign['option']->public_id]]]])->assertUnprocessable()->assertJsonPath('error.details.issues.0.code', 'unknown_group');
    }

    public function test_a_quote_for_a_sold_out_item_is_priced_but_says_it_cannot_be_ordered(): void
    {
        app(MenuService::class)->setItemStatus($this->classic, MenuItemStatus::SoldOut, $this->owner());
        $this->classic->load('optionGroups.options');
        $size = $this->groupAndOption($this->classic, 'Size', 'Regular');
        $quote = $this->postJson('/api/v1/restaurants/burger-hub/items/classic-burger/price-quote', ['selections' => [['group_id' => $size['group']->public_id, 'option_ids' => [$size['option']->public_id]]]])->assertOk()->json();
        $this->assertSame(20000, $quote['data']['unit_price_minor']);
        $this->assertSame(['visible' => true, 'orderable' => false, 'reason' => 'ITEM_SOLD_OUT', 'restaurant_reason' => null], $quote['availability']);
    }

    public function test_an_item_slug_of_another_restaurant_is_not_found_here(): void
    {
        $pizza = $this->hours($this->location($this->organization('Second Kitchen'), 'Pizza Point', ['slug' => 'pizza-point']), [[null, '00:00', '00:00']]);
        $this->item($this->category($this->menuOf($pizza)), 'Margherita');
        $this->getJson('/api/v1/restaurants/pizza-point/items/margherita')->assertOk();
        $this->getJson('/api/v1/restaurants/burger-hub/items/margherita')->assertNotFound()->assertJsonPath('error.code', 'item_not_found');
        $this->postJson('/api/v1/restaurants/burger-hub/items/margherita/price-quote', [])->assertNotFound();
        $this->assertSame(['Classic Burger'], $this->visibleItemNames());
        $this->assertSame(['Margherita'], $this->visibleItemNames('pizza-point'));
    }
}
