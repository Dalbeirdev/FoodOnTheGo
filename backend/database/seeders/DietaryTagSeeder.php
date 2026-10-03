<?php

namespace Database\Seeders;

use App\Models\DietaryTag;
use Illuminate\Database\Seeder;

/**
 * Reference data (every environment): the dietary labels a restaurant may attach to a menu item. Information
 * the restaurant provides — never a food-safety, allergen or religious-compliance guarantee by FoodOnTheGo.
 * Codes are stable; names can change. Re-running updates names and order, never removes a code.
 */
class DietaryTagSeeder extends Seeder
{
    /** code => [name, kind] — kind: DIET (how it is prepared), CONTAINS (what it contains), PREFERENCE (free of …) */
    private const TAGS = [
        'VEGETARIAN' => ['Vegetarian', 'DIET'],
        'NON_VEGETARIAN' => ['Non-vegetarian', 'DIET'],
        'VEGAN' => ['Vegan', 'DIET'],
        'JAIN' => ['Jain', 'DIET'],
        'EGG' => ['Contains egg', 'CONTAINS'],
        'CONTAINS_NUTS' => ['Contains nuts', 'CONTAINS'],
        'CONTAINS_DAIRY' => ['Contains dairy', 'CONTAINS'],
        'GLUTEN_FREE' => ['Gluten-free', 'PREFERENCE'],
        'DAIRY_FREE' => ['Dairy-free', 'PREFERENCE'],
        'HALAL' => ['Halal', 'PREFERENCE'],
    ];

    public function run(): void
    {
        $order = 0;
        foreach (self::TAGS as $code => [$name, $kind]) {
            DietaryTag::query()->updateOrCreate(['code' => $code], ['name' => $name, 'kind' => $kind, 'status' => 'ACTIVE', 'display_order' => $order++]);
        }
    }
}
