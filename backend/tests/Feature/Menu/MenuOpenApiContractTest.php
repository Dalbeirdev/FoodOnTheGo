<?php

namespace Tests\Feature\Menu;

use App\Enums\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsMenus;
use Tests\Support\BuildsRestaurants;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every menu endpoint answers with the shape documented in openapi/openapi.json — for customers, for restaurant
 * staff and for administrators. A property that is not documented fails the test.
 */
class MenuOpenApiContractTest extends TestCase
{
    use BuildsMenus, BuildsRestaurants, RefreshDatabase, ValidatesOpenApi;

    public function test_menu_responses_match_their_documented_schemas(): void
    {
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        Storage::fake('public');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

        $organization = $this->organization('Riverside');
        $burger = $this->hours($this->location($organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '09:00', '22:00']]);
        $brew = $this->hours($this->location($organization, 'Brew & Bites', ['slug' => 'brew-bites']), [[null, '09:00', '22:00']]);
        $menu = $this->menuOf($burger);
        $burgers = $this->category($menu, 'Burgers');
        $empty = $this->category($menu, 'Empty for now');
        $classic = $this->item($burgers, 'Classic Burger', 24900, $this->standardGroups(), ['dietary_tags' => ['NON_VEGETARIAN'], 'preparation_minutes' => 12, 'allergen_information' => 'Contains gluten.']);
        $classic->load('optionGroups.options');
        $size = $this->groupAndOption($classic, 'Size', 'Large');
        $owner = $this->member($organization, 'OWNER');

        // ── Customers
        $this->assertMatchesSchema('PublicMenu', $this->getJson('/api/v1/restaurants/burger-hub/menu')->assertOk()->assertJsonCount(1, 'categories')->json());
        $this->assertMatchesSchema('PublicMenu', $this->getJson('/api/v1/restaurants/brew-bites/menu')->assertOk()->assertJsonPath('menu', null)->json());
        $this->assertMatchesSchema('PublicMenuItemDetail', $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->assertOk()->json());
        $quote = '/api/v1/restaurants/burger-hub/items/classic-burger/price-quote';
        $this->assertMatchesSchema('PriceQuote', $this->postJson($quote, ['selections' => [['group_id' => $size['group']->public_id, 'option_ids' => [$size['option']->public_id]]], 'quantity' => 2])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->postJson($quote, ['selections' => []])->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->postJson($quote, ['selections' => [['group_id' => $size['group']->public_id, 'option_ids' => [$size['option']->public_id]]], 'unit_price_minor' => 1])->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/restaurants/burger-hub/items/no-such-item')->assertNotFound()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/restaurants/no-such-restaurant/menu')->assertNotFound()->json());

        // ── Restaurant staff
        $this->actingAsPrincipal($owner);
        $l = '/api/v1/restaurant/locations/'.$burger->public_id.'/menu';
        $i = '/api/v1/restaurant/menu/items/';
        $c = '/api/v1/restaurant/menu/categories/';

        $this->assertMatchesSchema('RestaurantTaxonomy', $this->getJson('/api/v1/restaurant/taxonomy')->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenu', $this->getJson($l)->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenu', $this->getJson($l.'?include=archived')->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenu', $this->patchJson($l, ['version' => 1, 'name' => 'All day menu', 'description' => 'Served 9 to 22'])->assertOk()->json());
        $created = $this->postJson($l.'/categories', ['name' => 'Sides', 'description' => 'Fries and more'])->assertCreated();
        $this->assertMatchesSchema('ManagedMenuCategory', $created->json());
        $this->assertMatchesSchema('ManagedMenuCategory', $this->patchJson($c.$created->json('id'), ['version' => 1, 'status' => 'INACTIVE'])->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenu', $this->postJson($l.'/categories/reorder', ['categories' => [$created->json('id'), $burgers->public_id, $empty->public_id]])->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenuCategory', $this->deleteJson($c.$empty->public_id)->assertOk()->assertJsonPath('status', 'ARCHIVED')->json());

        $item = $this->postJson($l.'/items', [
            'name' => 'Paneer Burger', 'description' => 'Grilled paneer, mint chutney.', 'category_id' => $burgers->public_id, 'base_price_minor' => 21900, 'dietary_tags' => ['VEGETARIAN'],
            'groups' => $this->standardGroups(),
        ])->assertCreated();
        $this->assertMatchesSchema('ManagedMenuItem', $item->json());
        $id = $item->json('id');
        $this->assertMatchesSchema('ManagedMenuItem', $this->getJson($i.$id)->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenuItem', $this->patchJson($i.$id, ['version' => 1, 'base_price_minor' => 22900, 'featured' => true, 'slug' => 'paneer-burger-special'])->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenuItem', $this->patchJson($i.$id.'/status', ['status' => 'SOLD_OUT', 'reason' => 'Paneer finished'])->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenuItem', $this->postJson($i.$id.'/duplicate')->assertCreated()->json());
        $this->assertMatchesSchema('ManagedMenu', $this->postJson($c.$burgers->public_id.'/items/reorder', ['items' => array_reverse(array_column($this->getJson($l)->json('items'), 'id'))])->assertOk()->json());
        $this->assertMatchesSchema('ManagedMenuBulkResult', $this->postJson($l.'/items/status', ['items' => [$id, $classic->public_id], 'status' => 'ACTIVE'])->assertOk()->json());

        $image = $this->post($i.$id.'/images', ['image' => UploadedFile::fake()->image('paneer.jpg', 640, 480), 'alt_text' => 'Paneer burger'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertMatchesSchema('MenuImage', $image->json());
        $this->assertMatchesSchema('MenuImage', $this->patchJson($i.$id.'/images/'.$image->json('id'), ['alt_text' => 'Close-up'])->assertOk()->json());
        $this->get(substr($image->json('url'), strpos($image->json('url'), '/api/v1/media/')))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertMatchesSchema('PublicMenuItemDetail', $this->getJson('/api/v1/restaurants/burger-hub/items/paneer-burger-special')->assertOk()->assertJsonCount(1, 'data.images')->json());
        $this->deleteJson($i.$id.'/images/'.$image->json('id'))->assertNoContent();
        $this->assertMatchesSchema('ManagedMenuItem', $this->deleteJson($i.$id)->assertOk()->assertJsonPath('status', 'ARCHIVED')->json());

        $this->assertMatchesSchema('Error', $this->patchJson($i.$classic->public_id, ['version' => 99, 'name' => 'Stale'])->assertConflict()->json());
        $this->assertMatchesSchema('Error', $this->postJson($l.'/items', ['name' => 'Bad', 'category_id' => $burgers->public_id, 'base_price_minor' => 100, 'currency' => 'USD'])->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->deleteJson($c.$burgers->public_id)->assertConflict()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/restaurant/locations/'.$this->location($this->organization('Other'), 'Other')->public_id.'/menu')->assertNotFound()->json());

        // ── Administrators
        $this->actingAsPrincipal($this->adminWith([Permission::AdminRestaurantsView], $this->market));
        $this->assertMatchesSchema('AdminMenuOversight', $this->getJson('/api/v1/admin/restaurants/'.$burger->public_id.'/menu')->assertOk()->json());
        $this->assertMatchesSchema('AdminMenuOversight', $this->getJson('/api/v1/admin/restaurants/'.$brew->public_id.'/menu')->assertOk()->assertJsonPath('menu', null)->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/admin/restaurants/'.$burger->public_id.'/menu/items')->assertNotFound()->json());
    }
}
