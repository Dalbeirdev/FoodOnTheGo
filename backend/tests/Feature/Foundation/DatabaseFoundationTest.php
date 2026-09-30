<?php

namespace Tests\Feature\Foundation;

use App\Models\Market;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_on_postgresql_in_a_dedicated_database_with_utf8_and_utc_sessions(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('foodonthego_test', DB::getDatabaseName());
        $this->assertSame('UTF8', DB::selectOne('show server_encoding')->server_encoding);
        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
        $this->assertGreaterThanOrEqual(160000, (int) DB::selectOne('show server_version_num')->server_version_num);
    }

    public function test_unicode_text_in_indian_scripts_round_trips_unchanged(): void
    {
        $names = [
            'hi' => 'स्वादिष्ट भोजनालय',
            'pa' => 'ਸੁਆਦੀ ਢਾਬਾ',
            'ta' => 'சுவையான உணவகம்',
            'te' => 'రుచికరమైన భోజనశాల',
            'bn' => 'সুস্বাদু রেস্তোরাঁ',
            'gu' => 'સ્વાદિષ્ટ ભોજનાલય',
            'mr' => 'चविष्ट खानावळ',
            'ml' => 'രുചികരമായ ഭക്ഷണശാല',
            'kn' => 'ರುಚಿಕರ ಉಪಾಹಾರ ಗೃಹ',
            'emoji' => 'Café Crème 🍛',
        ];

        foreach ($names as $name) {
            $user = User::factory()->create(['name' => $name]);

            $this->assertSame($name, $user->fresh()->name);
            $this->assertSame(mb_strlen($name), (int) DB::selectOne('select char_length(name) as n from users where id = ?', [$user->id])->n);
        }
    }

    public function test_timestamps_are_stored_with_a_time_zone_and_read_back_as_the_same_instant(): void
    {
        $launch = now('Asia/Kolkata')->setTime(9, 30)->startOfMinute();
        $market = Market::factory()->create(['launched_at' => $launch]);

        $this->assertSame('timestamp with time zone', DB::selectOne(
            "select data_type from information_schema.columns where table_name = 'markets' and column_name = 'launched_at'"
        )->data_type);
        $this->assertTrue($launch->equalTo($market->fresh()->launched_at));
        $this->assertSame(0, $market->fresh()->launched_at->utcOffset());
    }

    public function test_database_constraints_reject_invalid_market_rows_even_when_application_checks_are_bypassed(): void
    {
        $valid = Market::factory()->make()->getAttributes();

        foreach ([
            ['country_code' => 'in'],
            ['default_currency' => '₹'],
            ['status' => 'LIVE'],
            ['distance_unit' => 'furlongs'],
            ['phone_country_code' => '91'],
        ] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('markets')->insert(array_merge($valid, ['public_id' => fake()->uuid()], $invalid)));
                $this->fail('Constraint did not reject '.json_encode($invalid));
            } catch (QueryException $e) {
                $this->assertStringContainsString('check constraint', $e->getMessage());
            }
        }
    }

    public function test_unique_and_foreign_key_constraints_exist_at_database_level(): void
    {
        Market::factory()->india()->create();

        try {
            DB::transaction(fn () => Market::factory()->india()->create(['slug' => 'india-2']));
            $this->fail('Duplicate country code was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('markets_country_code_unique', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('permission_grants')->insert(['user_id' => 999999, 'permission' => 'admin.markets.view']));
            $this->fail('Orphan permission grant was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint', $e->getMessage());
        }
    }

    public function test_foundation_tables_exist_after_migrating(): void
    {
        foreach (['users', 'markets', 'permission_grants', 'idempotency_keys', 'personal_access_tokens', 'jobs', 'failed_jobs', 'cache'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertTrue(Schema::hasColumn('users', 'principal_type'));
        $this->assertFalse(Schema::hasColumn('users', 'role'));
    }
}
