<?php

namespace Database\Factories;

use App\Enums\DistanceUnit;
use App\Enums\MarketStatus;
use App\Models\Market;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Market>
 */
class MarketFactory extends Factory
{
    /**
     * A DRAFT market by default: tests opt in to serving customers with active().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $country = strtoupper(fake()->unique()->lexify('??'));

        return [
            'country_code' => $country,
            'slug' => 'market-'.strtolower($country),
            'name' => 'Market '.$country,
            'status' => MarketStatus::Draft,
            'default_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'default_locale' => 'en-US',
            'supported_locales' => ['en-US'],
            'timezone_strategy' => 'per-location',
            'default_timezone' => 'America/New_York',
            'distance_unit' => DistanceUnit::Imperial,
            'phone_country_code' => '+1',
            'features' => [],
        ];
    }

    public function india(): static
    {
        return $this->state(fn (): array => [
            'country_code' => 'IN',
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
            'features' => ['scheduled_pickup' => true, 'reviews' => true, 'cash_at_pickup' => false],
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => MarketStatus::Active]);
    }
}
