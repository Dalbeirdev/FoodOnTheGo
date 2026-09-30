<?php

namespace Database\Seeders;

use App\Enums\DistanceUnit;
use App\Enums\MarketStatus;
use App\Models\Market;
use Illuminate\Database\Seeder;

/**
 * Reference data (safe for every environment): the launch market. India is the only ACTIVE market.
 * Re-running never duplicates the row and never overwrites later administrative changes to it.
 */
class MarketSeeder extends Seeder
{
    public function run(): void
    {
        Market::query()->firstOrCreate(['country_code' => 'IN'], [
            'slug' => 'india',
            'name' => 'India',
            'status' => MarketStatus::Active,
            'default_currency' => 'INR',
            'supported_currencies' => ['INR'],
            'default_locale' => 'en-IN',
            'supported_locales' => ['en-IN'],
            'timezone_strategy' => 'single',
            'default_timezone' => 'Asia/Kolkata',
            'distance_unit' => DistanceUnit::Metric,
            'phone_country_code' => '+91',
            'phone_national_pattern' => '^[6-9][0-9]{9}$',
            'phone_trunk_prefix' => '0',
            'features' => [
                'journey_ordering' => true,
                'asap_pickup' => true,
                'scheduled_pickup' => true,
                'reviews' => true,
                'restaurant_responses' => true,
                'promotions' => true,
                'customer_notifications' => true,
                'curbside_pickup' => false,
                'cross_border_ordering' => false,
                'cash_at_pickup' => false,
            ],
            'launched_at' => null,
        ]);
    }
}
