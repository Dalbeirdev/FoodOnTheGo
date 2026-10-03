<?php

namespace Tests\Feature\Menu;

use App\Models\RestaurantUser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalMenuFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The development menu fixtures (Module 24): every outlet gets a menu built through MenuService, the edge cases
 * the frontends need exist, and seeding again changes nothing. Wednesday 2026-10-07 13:00 in India.
 */
class MenuFixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsBySlug(string $restaurant): array
    {
        $items = [];
        foreach ($this->getJson('/api/v1/restaurants/'.$restaurant.'/menu')->assertOk()->json('categories') as $category) {
            foreach ($category['items'] as $item) {
                $items[$item['slug']] = $item + ['category' => $category['name']];
            }
        }

        return $items;
    }

    public function test_every_outlet_has_a_menu_in_its_own_currency_and_the_tags_are_data(): void
    {
        $this->assertSame(DB::table('restaurant_locations')->count(), DB::table('menus')->count());
        $this->assertSame([], DB::table('menus')->join('restaurant_locations', 'restaurant_locations.id', '=', 'menus.location_id')->whereColumn('menus.currency', '!=', 'restaurant_locations.currency')->pluck('menus.id')->all());
        $this->assertSame(['INR'], DB::table('menus')->distinct()->pluck('currency')->all());
        $this->assertGreaterThan(150, DB::table('menu_items')->count());
        $this->assertGreaterThan(400, DB::table('menu_options')->count());
        $this->assertSame(10, DB::table('dietary_tags')->where('status', 'ACTIVE')->count());
        $this->assertSame(['VEGETARIAN', 'NON_VEGETARIAN', 'VEGAN', 'JAIN', 'EGG'], DB::table('dietary_tags')->orderBy('display_order')->limit(5)->pluck('code')->all());
        $this->assertSame(0, DB::table('menu_items')->where('base_price_minor', '<', 0)->count());
        $this->assertSame(0, DB::table('menu_items')->whereRaw('slug !~ ?', ['^[a-z0-9]+(-[a-z0-9]+)*$'])->count());
    }

    public function test_the_fixtures_cover_every_customer_facing_edge_case(): void
    {
        $burger = $this->itemsBySlug('burger-hub');
        $this->assertSame('ITEM_SOLD_OUT', $burger['smoky-bbq-burger']['availability']['reason']);
        $this->assertArrayNotHasKey('old-veggie-wrap', $burger, 'archived items are not shown');
        $this->assertTrue($burger['classic-burger']['customizable']);
        $this->assertNull($burger['classic-burger']['availability']['reason']);

        $spice = $this->itemsBySlug('spice-nest');
        $this->assertSame('ITEM_TEMPORARILY_UNAVAILABLE', $spice['dal-makhani']['availability']['reason']);
        $this->assertNotContains('Seasonal specials', array_column($spice, 'category'), 'an INACTIVE category is hidden');
        $this->assertArrayNotHasKey('sarson-da-saag', $spice);

        $pizza = $this->itemsBySlug('pizza-point');
        $this->assertArrayNotHasKey('seasonal-special', $pizza, 'a DISABLED item is hidden');
        $build = $this->getJson('/api/v1/restaurants/pizza-point/items/build-your-own-pizza')->assertOk()->json('data');
        $this->assertSame(5, count($build['variant_groups']) + count($build['modifier_groups']));
        $this->assertGreaterThan(20, array_sum(array_map(fn (array $g): int => count($g['options']), [...$build['variant_groups'], ...$build['modifier_groups']])));

        // a Half portion costs less: the negative adjustment is real and bounded by the base price
        $dal = $this->getJson('/api/v1/restaurants/spice-nest/items/dal-makhani')->assertOk()->json('data');
        $portion = collect($dal['variant_groups'])->firstWhere('name', 'Portion');
        $half = collect($portion['options'])->firstWhere('name', 'Half');
        $this->assertLessThan(0, $half['price_adjustment_minor']);
        $this->assertGreaterThanOrEqual(0, $dal['base_price_minor'] + $half['price_adjustment_minor']);
        $raita = collect(collect($dal['modifier_groups'])->firstWhere('name', 'Extras')['options'])->firstWhere('name', 'Raita');
        $this->assertFalse($raita['available']);

        // a DRAFT menu is not published
        $chai = $this->getJson('/api/v1/restaurants/ambala-chai-point/menu')->assertOk()->json();
        $this->assertNull($chai['menu']);
        $this->assertSame([], $chai['categories']);

        // the large menu: 8 categories, 64 items, for paging and search on the clients
        $thali = $this->getJson('/api/v1/restaurants/jaipur-rajwada-thali/menu')->assertOk()->json();
        $this->assertCount(8, $thali['categories']);
        $this->assertSame(64, array_sum(array_map(fn (array $c): int => count($c['items']), $thali['categories'])));

        // dietary labels: Jain and vegan exist somewhere, and every label in use is a known tag
        $codes = collect($burger + $spice + $pizza)->flatMap(fn (array $i): array => array_column($i['dietary_tags'], 'code'))->unique()->values()->all();
        $this->assertContains('VEGETARIAN', $codes);
        $this->assertContains('NON_VEGETARIAN', $codes);
        $this->assertSame([], array_diff($codes, DB::table('dietary_tags')->pluck('code')->all()));
    }

    public function test_the_fixture_owner_manages_their_own_menu_only(): void
    {
        $john = RestaurantUser::query()->where('email', 'john@riverside.example')->firstOrFail();
        $this->actingAsPrincipal($john);
        $burger = DB::table('restaurant_locations')->where('slug', 'burger-hub')->value('public_id');
        $pizza = DB::table('restaurant_locations')->where('slug', 'pizza-point')->value('public_id');

        $doc = $this->getJson('/api/v1/restaurant/locations/'.$burger.'/menu')->assertOk()->json();
        $this->assertSame('INR', $doc['currency']);
        $this->assertContains('Smoky BBQ Burger', array_column($doc['items'], 'name'));
        $this->assertNotContains('Old Veggie Wrap', array_column($doc['items'], 'name'));
        $this->assertContains('Old Veggie Wrap', array_column($this->getJson('/api/v1/restaurant/locations/'.$burger.'/menu?include=archived')->json('items'), 'name'));
        $this->getJson('/api/v1/restaurant/locations/'.$pizza.'/menu')->assertNotFound();
    }

    public function test_seeding_again_changes_nothing(): void
    {
        $before = ['menus' => DB::table('menus')->count(), 'categories' => DB::table('menu_categories')->count(), 'items' => DB::table('menu_items')->count(), 'options' => DB::table('menu_options')->count(), 'versions' => DB::table('menus')->sum('catalog_version')];
        $this->seed(LocalMenuFixtureSeeder::class);
        $after = ['menus' => DB::table('menus')->count(), 'categories' => DB::table('menu_categories')->count(), 'items' => DB::table('menu_items')->count(), 'options' => DB::table('menu_options')->count(), 'versions' => DB::table('menus')->sum('catalog_version')];
        $this->assertSame($before, $after);
    }
}
