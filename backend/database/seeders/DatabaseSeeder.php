<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data runs everywhere; development fixtures only in local / testing.
     */
    public function run(): void
    {
        $this->call([MarketSeeder::class, IndiaGeographySeeder::class, RoleSeeder::class, RestaurantTaxonomySeeder::class, DietaryTagSeeder::class]);

        if (app()->environment(['local', 'testing'])) {
            $this->call([LocalFixtureSeeder::class, LocalGeographyFixtureSeeder::class, LocalRestaurantFixtureSeeder::class, LocalMenuFixtureSeeder::class, LocalCustomerFixtureSeeder::class]);
        }
    }
}
