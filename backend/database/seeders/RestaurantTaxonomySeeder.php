<?php

namespace Database\Seeders;

use App\Enums\TaxonomyStatus;
use App\Models\Cuisine;
use App\Models\RestaurantFeature;
use App\Services\Restaurant\RestaurantCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Reference data (every environment): the starting cuisine and feature taxonomies. They are rows, not code —
 * a market can get more by adding rows, and an entry that is no longer offered becomes INACTIVE.
 *
 * `code` is the stable identifier. Re-running adds what is missing and never overwrites a name, an order or a
 * status that was changed afterwards.
 */
class RestaurantTaxonomySeeder extends Seeder
{
    /** code => name, in display order. The first nineteen are the India launch list. */
    public const CUISINES = [
        'north_indian' => 'North Indian', 'south_indian' => 'South Indian', 'punjabi' => 'Punjabi', 'biryani' => 'Biryani', 'chinese' => 'Chinese',
        'street_food' => 'Street Food', 'pizza' => 'Pizza', 'burgers' => 'Burgers', 'cafe' => 'Cafe', 'desserts' => 'Desserts', 'beverages' => 'Beverages',
        'gujarati' => 'Gujarati', 'rajasthani' => 'Rajasthani', 'bengali' => 'Bengali', 'maharashtrian' => 'Maharashtrian', 'kerala' => 'Kerala',
        'andhra_telangana' => 'Andhra / Telangana', 'healthy' => 'Healthy', 'continental' => 'Continental',
        'fast_food' => 'Fast Food', 'italian' => 'Italian', 'snacks' => 'Snacks', 'sweets' => 'Sweets', 'thali' => 'Thali', 'tandoor' => 'Tandoor',
        'asian' => 'Asian', 'salads' => 'Salads', 'grill' => 'Grill', 'american' => 'American',
    ];

    /** code => [name, category], in display order. */
    public const FEATURES = [
        'quick_pickup' => ['Quick Pickup', 'SERVICE'],
        'parking' => ['Parking', 'FACILITY'], 'highway_access' => ['Highway Access', 'FACILITY'], 'outdoor_seating' => ['Outdoor Seating', 'FACILITY'],
        'family_friendly' => ['Family Friendly', 'FACILITY'], 'restrooms' => ['Restrooms', 'FACILITY'], 'wifi' => ['Wi-Fi', 'FACILITY'],
        'wheelchair_accessible' => ['Wheelchair Accessible', 'FACILITY'], 'ev_charging' => ['EV Charging', 'FACILITY'],
        'pure_veg' => ['Pure Veg', 'DIETARY'], 'vegetarian' => ['Vegetarian', 'DIETARY'], 'veg_options' => ['Veg Options', 'DIETARY'],
        'vegan' => ['Vegan', 'DIETARY'], 'vegan_options' => ['Vegan Options', 'DIETARY'], 'jain_options' => ['Jain Options', 'DIETARY'], 'halal' => ['Halal', 'DIETARY'],
    ];

    public function run(RestaurantCatalog $catalog): void
    {
        $order = 0;
        foreach (self::CUISINES as $code => $name) {
            Cuisine::query()->firstOrCreate(['code' => $code], ['name' => $name, 'slug' => Str::slug($name), 'status' => TaxonomyStatus::Active, 'display_order' => $order += 10]);
        }

        $order = 0;
        foreach (self::FEATURES as $code => [$name, $category]) {
            RestaurantFeature::query()->firstOrCreate(['code' => $code], ['name' => $name, 'slug' => Str::slug($name), 'category' => $category, 'status' => TaxonomyStatus::Active, 'display_order' => $order += 10]);
        }

        $catalog->flush();
    }
}
