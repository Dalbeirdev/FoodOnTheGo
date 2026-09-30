<?php

namespace Tests\Feature\Foundation;

use App\Enums\MarketStatus;
use App\Models\Market;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_india_market_is_returned_as_the_current_market(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->getJson('/api/v1/markets/current')
            ->assertOk()
            ->assertJson([
                'country_code' => 'IN',
                'name' => 'India',
                'status' => 'ACTIVE',
                'default_currency' => 'INR',
                'default_locale' => 'en-IN',
                'default_timezone' => 'Asia/Kolkata',
                'distance_unit' => 'metric',
                'phone_country_code' => '+91',
                'features' => ['scheduled_pickup' => true, 'cash_at_pickup' => false],
            ])
            ->assertJsonMissingPath('created_at')
            ->assertJsonPath('id', Market::query()->where('country_code', 'IN')->value('public_id'));
    }

    public function test_india_is_the_only_market_serving_customers_after_seeding_and_reseeding_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['IN'], Market::query()->servingCustomers()->pluck('country_code')->all());
        $this->assertSame(1, Market::query()->where('country_code', 'IN')->count());
        $this->assertGreaterThan(0, Market::query()->where('status', MarketStatus::Draft->value)->count());
        $this->assertSame(0, Market::query()->where('country_code', '!=', 'IN')->whereNot('status', MarketStatus::Draft->value)->count());
    }

    public function test_market_response_never_exposes_the_internal_numeric_id(): void
    {
        $market = Market::factory()->india()->create();

        $response = $this->getJson('/api/v1/markets/current')->assertOk();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('id'));
        $this->assertNotEquals($market->id, $response->json('id'));
    }

    public function test_a_draft_or_unknown_market_is_not_available_to_customers(): void
    {
        Market::factory()->india()->create();
        Market::factory()->create(['country_code' => 'US']);

        $this->getJson('/api/v1/markets/current?country=US')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'market_unavailable');

        $this->getJson('/api/v1/markets/current?country=ZZ')->assertNotFound();
        $this->getJson('/api/v1/markets/current?country=IN')->assertOk()->assertJsonPath('default_currency', 'INR');
    }

    public function test_another_market_becomes_available_purely_through_data(): void
    {
        Market::factory()->india()->create();
        Market::factory()->active()->create([
            'country_code' => 'AE', 'default_currency' => 'AED', 'default_locale' => 'ar-AE',
            'default_timezone' => 'Asia/Dubai', 'distance_unit' => 'metric', 'phone_country_code' => '+971',
        ]);

        $this->getJson('/api/v1/markets/current?country=AE')
            ->assertOk()
            ->assertJson(['default_currency' => 'AED', 'default_timezone' => 'Asia/Dubai', 'phone_country_code' => '+971']);

        config(['market.default_country' => 'AE']);
        $this->getJson('/api/v1/markets/current')->assertOk()->assertJsonPath('country_code', 'AE');
    }

    public function test_invalid_country_parameter_is_rejected_with_the_standard_validation_error(): void
    {
        Market::factory()->india()->create();

        foreach (['in', 'IND', '91', 'India'] as $invalid) {
            $this->getJson('/api/v1/markets/current?country='.$invalid)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonValidationErrors(['country'], 'error.details.fields');
        }
    }

    public function test_client_bootstrap_config_returns_safe_market_configuration_only(): void
    {
        Market::factory()->india()->create();

        $response = $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('api.version', 'v1')
            ->assertJsonPath('market.country_code', 'IN')
            ->assertJsonPath('market.default_currency', 'INR')
            ->assertJsonPath('market.features.cash_at_pickup', false);

        $this->assertSame(['api', 'app', 'market', 'auth'], array_keys($response->json()));
        foreach (['secret', 'password', 'key', 'DB_', 'redis'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $response->getContent());
        }
    }
}
