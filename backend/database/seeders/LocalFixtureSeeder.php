<?php

namespace Database\Seeders;

use App\Enums\MarketStatus;
use App\Models\Market;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development fixtures — local and testing only, never production. Future markets exist as DRAFT rows so
 * the multi-market paths can be exercised; none of them serves customers.
 */
class LocalFixtureSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['US', 'united-states', 'United States', 'USD', 'en-US', 'America/New_York', 'imperial', '+1'],
            ['GB', 'united-kingdom', 'United Kingdom', 'GBP', 'en-GB', 'Europe/London', 'imperial', '+44'],
            ['AE', 'united-arab-emirates', 'United Arab Emirates', 'AED', 'ar-AE', 'Asia/Dubai', 'metric', '+971'],
        ] as [$country, $slug, $name, $currency, $locale, $timezone, $unit, $phone]) {
            Market::query()->firstOrCreate(['country_code' => $country], [
                'slug' => $slug,
                'name' => $name,
                'status' => MarketStatus::Draft,
                'default_currency' => $currency,
                'supported_currencies' => [$currency],
                'default_locale' => $locale,
                'supported_locales' => [$locale],
                'timezone_strategy' => 'per-location',
                'default_timezone' => $timezone,
                'distance_unit' => $unit,
                'phone_country_code' => $phone,
                'features' => [],
            ]);
        }

        if (! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);
        }
    }
}
