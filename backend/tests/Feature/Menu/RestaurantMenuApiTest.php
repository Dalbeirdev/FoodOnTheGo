<?php

namespace Tests\Feature\Menu;

use App\Enums\MenuOptionGroupStatus;
use App\Models\AuditEvent;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsMenus;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Menu management as a restaurant owner uses it (Module 24): the document, categories, items with their option
 * groups, statuses, duplication, reordering, bulk actions, versions and the audit trail. Who may do what is
 * MenuAuthorizationTest; what customers see is CustomerMenuApiTest.
 */
class RestaurantMenuApiTest extends TestCase
{
    use BuildsMenus, BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    private RestaurantUser $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        $this->organization = $this->organization('Riverside');
        $this->burger = $this->hours($this->location($this->organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '00:00', '00:00']]);
        $this->owner = $this->member($this->organization, 'OWNER');
        $this->actingAsPrincipal($this->owner);
    }

    private function menuUrl(string $path = ''): string
    {
        return '/api/v1/restaurant/locations/'.$this->burger->public_id.'/menu'.$path;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return $this->getJson($this->menuUrl())->assertOk()->json();
    }

    public function test_the_menu_is_created_on_first_use_in_the_locations_currency(): void
    {
        $this->assertDatabaseCount('menus', 0);
        $doc = $this->document();
        $this->assertSame(['Menu', 'ACTIVE', 'INR', 1, 1, [], []], [$doc['name'], $doc['status'], $doc['currency'], $doc['catalog_version'], $doc['version'], $doc['categories'], $doc['items']]);
        $this->assertSame($this->burger->public_id, $doc['location']['id']);
        $this->assertArrayHasKey('limits', $doc);
        $this->document();
        $this->assertDatabaseCount('menus', 1);
        $this->assertSame('menu.created', AuditEvent::query()->latest('id')->value('action'));
    }

    public function test_categories_are_created_edited_reordered_and_archived_with_their_order_kept_explicit(): void
    {
        $starters = $this->postJson($this->menuUrl('/categories'), ['name' => ' Starters ', 'description' => 'Small plates'])->assertCreated()->json();
        $mains = $this->postJson($this->menuUrl('/categories'), ['name' => 'Main Course'])->assertCreated()->json();
        $breads = $this->postJson($this->menuUrl('/categories'), ['name' => 'रोटी और नान'])->assertCreated()->json();
        $this->assertSame(['Starters', 'Main Course', 'रोटी और नान'], array_column($this->document()['categories'], 'name'));
        $this->assertSame([0, 1, 2], array_column($this->document()['categories'], 'display_order'));

        $this->postJson($this->menuUrl('/categories/reorder'), ['categories' => [$breads['id'], $starters['id'], $mains['id']]])->assertOk();
        $this->assertSame(['रोटी और नान', 'Starters', 'Main Course'], array_column($this->document()['categories'], 'name'));
        // every category exactly once, or nothing changes
        $this->postJson($this->menuUrl('/categories/reorder'), ['categories' => [$breads['id'], $starters['id']]])->assertUnprocessable();
        $this->assertSame(['रोटी और नान', 'Starters', 'Main Course'], array_column($this->document()['categories'], 'name'));

        $this->patchJson('/api/v1/restaurant/menu/categories/'.$mains['id'], ['version' => 1, 'name' => 'Mains', 'status' => 'INACTIVE'])->assertOk()->assertJsonPath('name', 'Mains')->assertJsonPath('status', 'INACTIVE')->assertJsonPath('version', 2);
        $this->patchJson('/api/v1/restaurant/menu/categories/'.$mains['id'], ['version' => 1, 'name' => 'Stale'])->assertStatus(409)->assertJsonPath('error.code', 'stale_update');

        $this->deleteJson('/api/v1/restaurant/menu/categories/'.$breads['id'])->assertOk()->assertJsonPath('status', 'ARCHIVED');
        $this->assertSame(['Starters', 'Mains'], array_column($this->document()['categories'], 'name'));
        $this->assertCount(3, $this->getJson($this->menuUrl('?include=archived'))->json('categories'));
        $this->assertSame(['menu_category.created', 'menu_category.reordered', 'menu_category.status_changed', 'menu_category.archived'], array_values(array_unique(AuditEvent::query()->where('action', 'like', 'menu_category.%')->orderBy('id')->pluck('action')->all())));
    }

    public function test_a_category_with_items_cannot_be_archived(): void
    {
        $category = $this->category($this->menuOf($this->burger));
        $item = $this->item($category);
        $this->deleteJson('/api/v1/restaurant/menu/categories/'.$category->public_id)->assertStatus(409)->assertJsonPath('error.code', 'category_not_empty');
        $this->deleteJson('/api/v1/restaurant/menu/items/'.$item->public_id)->assertOk();
        $this->deleteJson('/api/v1/restaurant/menu/categories/'.$category->public_id)->assertOk();
    }

    public function test_an_item_is_created_with_its_groups_and_options_in_one_request_and_everything_is_returned(): void
    {
        $category = $this->category($this->menuOf($this->burger), 'Burgers');
        $response = $this->postJson($this->menuUrl('/items'), [
            'name' => 'Classic Burger', 'description' => 'Juicy grilled patty.', 'category_id' => $category->public_id, 'base_price_minor' => 24900,
            'dietary_tags' => ['NON_VEGETARIAN'], 'preparation_minutes' => 12, 'featured' => true, 'allergen_information' => 'Contains gluten and dairy.',
            'groups' => $this->standardGroups(),
        ])->assertCreated();

        $item = $response->json();
        $this->assertSame(['classic-burger', 24900, 'INR', 'ACTIVE', 12, true, 1, 20, 1], [$item['slug'], $item['base_price_minor'], $item['currency'], $item['status'], $item['preparation_minutes'], $item['featured'], $item['min_quantity'], $item['max_quantity'], $item['version']]);
        $this->assertSame([['code' => 'NON_VEGETARIAN', 'name' => 'Non-vegetarian']], $item['dietary_tags']);
        $this->assertSame('Size', $item['variant_groups'][0]['name']);
        $this->assertSame(['Regular', 'Large'], array_column($item['variant_groups'][0]['options'], 'name'));
        $this->assertSame([0, 5000], array_column($item['variant_groups'][0]['options'], 'price_adjustment_minor'));
        $this->assertSame('TEMPORARILY_UNAVAILABLE', $item['modifier_groups'][0]['options'][3]['status']);
        $this->assertTrue($item['availability']['orderable']);
        $this->assertDatabaseCount('menu_option_groups', 2);
        $this->assertDatabaseCount('menu_options', 6);
        // a second item with the same name gets its own address
        $this->assertSame('classic-burger-2', $this->postJson($this->menuUrl('/items'), ['name' => 'Classic Burger', 'category_id' => $category->public_id, 'base_price_minor' => 1000])->assertCreated()->json('slug'));
    }

    public function test_an_invalid_group_rule_refuses_the_whole_item(): void
    {
        $category = $this->category($this->menuOf($this->burger));
        $base = ['name' => 'Broken', 'category_id' => $category->public_id, 'base_price_minor' => 1000];
        $group = fn (array $patch): array => [array_merge(['kind' => 'VARIANT', 'name' => 'Size', 'required' => true, 'min_selections' => 1, 'max_selections' => 1, 'options' => [['name' => 'Regular', 'price_adjustment_minor' => 0]]], $patch)];

        $fields = fn (array $groups): array => $this->postJson($this->menuUrl('/items'), $base + ['groups' => $groups])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->json('error.details.fields');

        $this->assertSame(['The minimum cannot exceed the maximum.'], $fields($group(['min_selections' => 2, 'max_selections' => 1]))['groups.0.min_selections']);
        $this->assertSame(['A required group needs a minimum of at least one selection.'], $fields($group(['required' => true, 'min_selections' => 0]))['groups.0.min_selections']);
        $this->assertSame(['A required group needs at least one available option.'], $fields($group(['options' => [['name' => 'Only', 'price_adjustment_minor' => 0, 'status' => 'TEMPORARILY_UNAVAILABLE']]]))['groups.0.options']);
        $this->assertSame(['The maximum number of selections cannot exceed the number of options.'], $fields($group(['max_selections' => 3]))['groups.0.max_selections']);
        $this->assertSame(['A reduction cannot be larger than the base price of the item.'], $fields($group(['options' => [['name' => 'Half', 'price_adjustment_minor' => -2000]]]))['groups.0.options.0.price_adjustment_minor']);
        $this->assertArrayHasKey('groups.0.kind', $fields([['name' => 'No kind', 'options' => [['name' => 'a']]]]));
        $this->assertArrayHasKey('groups.0.options', $fields([['kind' => 'VARIANT', 'name' => 'Empty', 'options' => []]]));
        $this->assertDatabaseCount('menu_items', 0);
        $this->assertDatabaseCount('menu_option_groups', 0);
    }

    public function test_validation_refuses_bad_money_markup_long_text_unknown_tags_and_protected_fields(): void
    {
        $category = $this->category($this->menuOf($this->burger));
        $base = ['name' => 'Item', 'category_id' => $category->public_id, 'base_price_minor' => 1000];

        $this->postJson($this->menuUrl('/items'), ['base_price_minor' => -1] + $base)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['base_price_minor']]]]);
        $this->postJson($this->menuUrl('/items'), ['base_price_minor' => 249.5] + $base)->assertUnprocessable();
        $this->postJson($this->menuUrl('/items'), ['base_price_minor' => 10000001] + $base)->assertUnprocessable();
        $this->postJson($this->menuUrl('/items'), ['description' => 'Tasty <b>bold</b>'] + $base)->assertUnprocessable()->assertJsonPath('error.details.fields.description.0', 'Formatting and HTML are not allowed here — plain text only.');
        $this->postJson($this->menuUrl('/items'), ['description' => str_repeat('x', 1001)] + $base)->assertUnprocessable();
        $this->postJson($this->menuUrl('/items'), ['dietary_tags' => ['KETO']] + $base)->assertUnprocessable()->assertJsonPath('error.details.fields.dietary_tags.0', 'Unknown dietary tag: KETO');
        $this->postJson($this->menuUrl('/items'), ['currency' => 'USD'] + $base)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['currency']]]]);
        $this->postJson($this->menuUrl('/items'), ['menu_id' => 1] + $base)->assertUnprocessable();
        $this->postJson($this->menuUrl('/items'), ['category_id' => $this->category($this->menuOf($this->location($this->organization('Other'))))->public_id] + $base)->assertUnprocessable()->assertJsonPath('error.details.fields.category_id.0', 'Choose a category of this menu.');
        $this->assertDatabaseCount('menu_items', 0);
    }

    public function test_unicode_names_are_stored_and_searched_unchanged(): void
    {
        $category = $this->category($this->menuOf($this->burger), 'मुख्य व्यंजन');
        foreach (['पनीर टिक्का मसाला', 'ਮੱਖਣ ਚਿਕਨ', 'சாம்பார் இட்லி', 'Café Crème'] as $name) {
            $this->postJson($this->menuUrl('/items'), ['name' => $name, 'category_id' => $category->public_id, 'base_price_minor' => 1000])->assertCreated()->assertJsonPath('name', $name);
        }
        $this->assertSame(['पनीर टिक्का मसाला', 'ਮੱਖਣ ਚਿਕਨ', 'சாம்பார் இட்லி', 'Café Crème'], array_column($this->document()['items'], 'name'));
        // Devanagari transliterates; Gurmukhi and Tamil have no ASCII form and get the neutral "item" address
        $this->assertSame(['panara-takaka-masal', 'item', 'item-2', 'cafe-creme'], array_column($this->document()['items'], 'slug'));
    }

    public function test_updating_an_item_keeps_its_slug_moves_it_between_categories_and_records_a_price_change(): void
    {
        $menu = $this->menuOf($this->burger);
        $category = $this->category($menu, 'Burgers');
        $other = $this->category($menu, 'Specials');
        $item = $this->item($category, 'Classic Burger', 24900, $this->standardGroups());

        $updated = $this->patchJson('/api/v1/restaurant/menu/items/'.$item->public_id, ['version' => 1, 'name' => 'Classic Beef Burger', 'base_price_minor' => 26900, 'category_id' => $other->public_id, 'featured' => true])->assertOk()->json();
        $this->assertSame(['classic-burger', 'Classic Beef Burger', 26900, $other->public_id, 2, true], [$updated['slug'], $updated['name'], $updated['base_price_minor'], $updated['category_id'], $updated['version'], $updated['featured']]);
        $price = AuditEvent::query()->where('action', 'menu_item.price_changed')->latest('id')->first();
        $this->assertEquals(['from' => 24900, 'to' => 26900], $price->changes['base_price_minor']);
        $this->assertSame($this->owner->public_id, $price->actor_public_id);

        // a stale version changes nothing
        $this->patchJson('/api/v1/restaurant/menu/items/'.$item->public_id, ['version' => 1, 'name' => 'Stale'])->assertStatus(409)->assertJsonPath('error.code', 'stale_update')->assertJsonPath('error.details.current_version', 2);
        $this->assertSame('Classic Beef Burger', $item->fresh()->name);

        // the slug changes only when asked, and must be free
        $this->patchJson('/api/v1/restaurant/menu/items/'.$item->public_id, ['version' => 2, 'slug' => 'beef-burger'])->assertOk()->assertJsonPath('slug', 'beef-burger');
        $second = $this->item($category, 'Second');
        $this->patchJson('/api/v1/restaurant/menu/items/'.$second->public_id, ['version' => 1, 'slug' => 'beef-burger'])->assertUnprocessable()->assertJsonPath('error.details.fields.slug.0', 'This address is already used by another item of the menu.');
        $this->patchJson('/api/v1/restaurant/menu/items/'.$second->public_id, ['version' => 1, 'slug' => 'Not A Slug'])->assertUnprocessable();
    }

    public function test_replacing_the_option_document_keeps_ids_of_kept_entries_archives_the_rest_and_audits_option_prices(): void
    {
        $item = $this->item($this->category($this->menuOf($this->burger)), 'Classic Burger', 24900, $this->standardGroups());
        $before = $this->getJson('/api/v1/restaurant/menu/items/'.$item->public_id)->json();
        $size = $before['variant_groups'][0];
        $large = $size['options'][1];

        $groups = [
            ['id' => $size['id'], 'kind' => 'VARIANT', 'name' => 'Size', 'required' => true, 'min_selections' => 1, 'max_selections' => 1, 'options' => [
                ['id' => $size['options'][0]['id'], 'name' => 'Regular', 'price_adjustment_minor' => 0, 'default_selected' => true],
                ['id' => $large['id'], 'name' => 'Large', 'price_adjustment_minor' => 7000],
                ['name' => 'Jumbo', 'price_adjustment_minor' => 13000],
            ]],
            ['kind' => 'MODIFIER', 'name' => 'Spice level', 'required' => true, 'min_selections' => 1, 'max_selections' => 1, 'options' => [['name' => 'Mild', 'price_adjustment_minor' => 0], ['name' => 'Hot', 'price_adjustment_minor' => 0]]],
        ];
        $after = $this->patchJson('/api/v1/restaurant/menu/items/'.$item->public_id, ['version' => 1, 'groups' => $groups])->assertOk()->json();

        $this->assertSame($size['id'], $after['variant_groups'][0]['id'], 'the kept group keeps its id');
        $this->assertSame($large['id'], $after['variant_groups'][0]['options'][1]['id']);
        $this->assertSame(['Regular', 'Large', 'Jumbo'], array_column($after['variant_groups'][0]['options'], 'name'));
        $this->assertSame(['Spice level'], array_column($after['modifier_groups'], 'name'));
        // the removed Add-ons group and its options are archived, not deleted
        $this->assertSame(MenuOptionGroupStatus::Archived, MenuOptionGroup::query()->where('name', 'Add-ons')->value('status'));
        $this->assertSame(4, MenuOption::query()->whereHas('group', fn ($q) => $q->where('name', 'Add-ons'))->where('status', 'ARCHIVED')->count());
        $audit = AuditEvent::query()->where('action', 'menu_option.price_changed')->latest('id')->first();
        $this->assertSame([$large['id'] => 5000], $audit->changes['options']['from']);
        $this->assertSame([$large['id'] => 7000], $audit->changes['options']['to']);
        // an id of another item's group is refused
        $foreign = $this->item($item->category, 'Other', 1000, $this->standardGroups())->optionGroups()->first();
        $this->patchJson('/api/v1/restaurant/menu/items/'.$item->public_id, ['version' => 2, 'groups' => [['id' => $foreign->public_id, 'kind' => 'VARIANT', 'name' => 'X', 'options' => [['name' => 'a']]]]])->assertUnprocessable();
    }

    public function test_status_switches_sold_out_back_disabled_and_archive_are_audited_and_final_where_they_should_be(): void
    {
        $item = $this->item($this->category($this->menuOf($this->burger)));
        $url = '/api/v1/restaurant/menu/items/'.$item->public_id;

        $this->patchJson($url.'/status', ['status' => 'SOLD_OUT'])->assertOk()->assertJsonPath('status', 'SOLD_OUT')->assertJsonPath('availability.reason', 'ITEM_SOLD_OUT');
        $this->patchJson($url.'/status', ['status' => 'TEMPORARILY_UNAVAILABLE', 'reason' => 'Supplier late'])->assertOk()->assertJsonPath('availability.reason', 'ITEM_TEMPORARILY_UNAVAILABLE');
        $this->patchJson($url.'/status', ['status' => 'DISABLED'])->assertOk()->assertJsonPath('availability.visible', false);
        $this->patchJson($url.'/status', ['status' => 'ACTIVE'])->assertOk()->assertJsonPath('availability.orderable', true);
        $this->patchJson($url.'/status', ['status' => 'ARCHIVED'])->assertUnprocessable();
        $this->assertSame(['SOLD_OUT', 'TEMPORARILY_UNAVAILABLE', 'DISABLED', 'ACTIVE'], AuditEvent::query()->where('action', 'menu_item.status_changed')->orderBy('id')->get()->map(fn (AuditEvent $e) => $e->changes['status']['to'])->all());
        $this->assertSame('Supplier late', AuditEvent::query()->where('action', 'menu_item.status_changed')->orderBy('id')->skip(1)->value('reason'));

        $this->deleteJson($url)->assertOk()->assertJsonPath('status', 'ARCHIVED');
        $this->assertNotNull($item->fresh()->archived_at);
        $this->patchJson($url.'/status', ['status' => 'ACTIVE'])->assertStatus(409)->assertJsonPath('error.code', 'item_archived');
        $this->patchJson($url, ['version' => 6, 'name' => 'Back'])->assertStatus(409);
        $this->assertDatabaseCount('menu_items', 1); // archived, never deleted
        $this->assertCount(0, $this->document()['items']);
        $this->assertCount(1, $this->getJson($this->menuUrl('?include=archived'))->json('items'));
    }

    public function test_bulk_status_changes_all_listed_items_or_none(): void
    {
        $category = $this->category($this->menuOf($this->burger));
        $a = $this->item($category, 'A');
        $b = $this->item($category, 'B');
        $foreign = $this->item($this->category($this->menuOf($this->location($this->organization, 'Brew & Bites'))), 'C');

        $this->postJson($this->menuUrl('/items/status'), ['items' => [$a->public_id, $b->public_id], 'status' => 'SOLD_OUT'])->assertOk()->assertJsonPath('changed', 2);
        $this->assertSame(['SOLD_OUT', 'SOLD_OUT'], [$a->fresh()->status->value, $b->fresh()->status->value]);
        $this->postJson($this->menuUrl('/items/status'), ['items' => [$a->public_id, $foreign->public_id], 'status' => 'ACTIVE'])->assertUnprocessable();
        $this->assertSame(['SOLD_OUT', 'ACTIVE'], [$a->fresh()->status->value, $foreign->fresh()->status->value]);
    }

    public function test_duplicating_an_item_copies_its_configuration_independently_and_starts_disabled(): void
    {
        $item = $this->item($this->category($this->menuOf($this->burger)), 'Classic Burger', 24900, $this->standardGroups(), ['dietary_tags' => ['VEGETARIAN']]);
        $copy = $this->postJson('/api/v1/restaurant/menu/items/'.$item->public_id.'/duplicate')->assertCreated()->json();

        $this->assertSame(['Classic Burger (copy)', 'classic-burger-copy', 'DISABLED', 24900], [$copy['name'], $copy['slug'], $copy['status'], $copy['base_price_minor']]);
        $this->assertSame([['code' => 'VEGETARIAN', 'name' => 'Vegetarian']], $copy['dietary_tags']);
        $this->assertSame(['Size'], array_column($copy['variant_groups'], 'name'));
        $this->assertNotSame($item->optionGroups()->first()->public_id, $copy['variant_groups'][0]['id']);
        $this->assertSame((int) $item->display_order + 1, $copy['display_order']);

        // editing the copy leaves the original untouched
        $this->patchJson('/api/v1/restaurant/menu/items/'.$copy['id'], ['version' => 1, 'groups' => []])->assertOk();
        $this->assertSame(2, MenuOptionGroup::query()->where('item_id', $item->getKey())->where('status', 'ACTIVE')->count());
        $this->assertSame(0, MenuOptionGroup::query()->where('item_id', MenuItem::query()->where('public_id', $copy['id'])->value('id'))->where('status', 'ACTIVE')->count());
    }

    public function test_items_are_reordered_within_their_category_atomically(): void
    {
        $category = $this->category($this->menuOf($this->burger));
        $ids = array_map(fn (string $n): string => $this->item($category, $n)->public_id, ['A', 'B', 'C']);
        $this->postJson('/api/v1/restaurant/menu/categories/'.$category->public_id.'/items/reorder', ['items' => [$ids[2], $ids[0], $ids[1]]])->assertOk();
        $this->assertSame(['C', 'A', 'B'], array_column($this->document()['items'], 'name'));
        $this->postJson('/api/v1/restaurant/menu/categories/'.$category->public_id.'/items/reorder', ['items' => [$ids[2], $ids[0]]])->assertUnprocessable();
        $this->assertSame(['C', 'A', 'B'], array_column($this->document()['items'], 'name'));
    }

    public function test_the_menu_itself_can_be_renamed_and_switched_off_with_a_version(): void
    {
        $menu = $this->menuOf($this->burger);
        $this->patchJson($this->menuUrl(), ['version' => 1, 'name' => 'Dinner menu', 'status' => 'INACTIVE'])->assertOk()->assertJsonPath('name', 'Dinner menu')->assertJsonPath('status', 'INACTIVE')->assertJsonPath('version', 2);
        $this->patchJson($this->menuUrl(), ['version' => 1, 'status' => 'ACTIVE'])->assertStatus(409);
        $this->patchJson($this->menuUrl(), ['version' => 2, 'currency' => 'USD'])->assertUnprocessable();
        $this->assertSame('INR', $menu->fresh()->currency);
        $this->assertSame('menu.status_changed', AuditEvent::query()->where('target_type', 'menus')->latest('id')->value('action'));
    }

    public function test_the_taxonomy_lists_the_dietary_tags_and_menu_limits(): void
    {
        $taxonomy = $this->getJson('/api/v1/restaurant/taxonomy')->assertOk()->json();
        $this->assertContains('VEGETARIAN', array_column($taxonomy['dietary_tags'], 'code'));
        $this->assertSame(6, $taxonomy['menu']['dietary_tags_per_item']);
        $this->assertSame([0, 10000000], $taxonomy['menu']['price_minor']);
    }
}
