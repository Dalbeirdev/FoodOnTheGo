<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A market is a country-level operating configuration. Country, currency, locale, time zone, units and
     * features are data on this row — business code never hardcodes them. Regions, cities, service areas and
     * route corridors arrive with the dedicated Market / Geo module.
     */
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('country_code', 2)->unique();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('status', 16)->default('DRAFT')->index();
            $table->char('default_currency', 3);
            $table->jsonb('supported_currencies');
            $table->string('default_locale', 15);
            $table->jsonb('supported_locales');
            $table->string('timezone_strategy', 16)->default('per-location');
            $table->string('default_timezone', 64);
            $table->string('distance_unit', 10)->default('metric');
            $table->string('phone_country_code', 6);
            $table->jsonb('features')->default('{}');
            $table->timestampTz('launched_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table markets add constraint markets_country_code_check check (country_code ~ '^[A-Z]{2}$')");
        DB::statement("alter table markets add constraint markets_default_currency_check check (default_currency ~ '^[A-Z]{3}$')");
        DB::statement("alter table markets add constraint markets_status_check check (status in ('DRAFT', 'PILOT', 'ACTIVE', 'PAUSED', 'CLOSED'))");
        DB::statement("alter table markets add constraint markets_distance_unit_check check (distance_unit in ('metric', 'imperial'))");
        DB::statement("alter table markets add constraint markets_timezone_strategy_check check (timezone_strategy in ('single', 'per-location'))");
        DB::statement("alter table markets add constraint markets_phone_country_code_check check (phone_country_code ~ '^\\+[1-9][0-9]{0,3}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('markets');
    }
};
