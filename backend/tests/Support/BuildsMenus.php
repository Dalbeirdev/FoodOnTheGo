<?php

namespace Tests\Support;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Services\Menu\MenuService;
use Database\Seeders\DietaryTagSeeder;

/**
 * Menus for tests, built through MenuService (slugs, order, invariants and audit as in real use).
 * Used together with BuildsRestaurants.
 */
trait BuildsMenus
{
    protected function setUpMenuWorld(): void
    {
        $this->seed(DietaryTagSeeder::class);
    }

    protected function menuOf(RestaurantLocation $location): Menu
    {
        return app(MenuService::class)->menuFor($location);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function category(Menu $menu, string $name = 'Main Course', array $attributes = []): MenuCategory
    {
        return app(MenuService::class)->createCategory($menu, ['name' => $name, ...$attributes], null);
    }

    /**
     * An ACTIVE item with a base price (paise) and, optionally, option groups in the API's document form.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>  $attributes
     */
    protected function item(MenuCategory $category, string $name = 'Paneer Tikka', int $priceMinor = 20000, array $groups = [], array $attributes = []): MenuItem
    {
        return app(MenuService::class)->createItem($category->menu, [
            'name' => $name, 'description' => 'Development fixture.', 'category_id' => $category->public_id, 'base_price_minor' => $priceMinor, 'groups' => $groups, ...$attributes,
        ], null);
    }

    /**
     * A required single-choice variant group (+₹50 for Large) and an optional modifier group (max 3, +₹20 cheese).
     *
     * @return list<array<string, mixed>>
     */
    protected function standardGroups(): array
    {
        return [
            ['kind' => 'VARIANT', 'name' => 'Size', 'required' => true, 'min_selections' => 1, 'max_selections' => 1, 'options' => [
                ['name' => 'Regular', 'price_adjustment_minor' => 0, 'default_selected' => true], ['name' => 'Large', 'price_adjustment_minor' => 5000],
            ]],
            ['kind' => 'MODIFIER', 'name' => 'Add-ons', 'required' => false, 'min_selections' => 0, 'max_selections' => 3, 'options' => [
                ['name' => 'Extra Cheese', 'price_adjustment_minor' => 2000], ['name' => 'Jalapeños', 'price_adjustment_minor' => 1000], ['name' => 'Olives', 'price_adjustment_minor' => 1500], ['name' => 'Fried Egg', 'price_adjustment_minor' => 3000, 'status' => 'TEMPORARILY_UNAVAILABLE'],
            ]],
        ];
    }

    /**
     * @return array{group: array<string, mixed>, option: array<string, mixed>}
     */
    protected function groupAndOption(MenuItem $item, string $groupName, string $optionName): array
    {
        $item->load('optionGroups.options');
        $group = $item->optionGroups->firstWhere('name', $groupName);
        $option = $group->options->firstWhere('name', $optionName);

        return ['group' => $group, 'option' => $option];
    }
}
