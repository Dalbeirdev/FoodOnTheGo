<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Market geography (Module 22):
     *
     *   Market → Region → City → Service Area          Market → Route Corridor
     *
     * Spatial types, all WGS84 / SRID 4326:
     *   - city centre          geography(Point)       distances on the earth in metres
     *   - service area         geometry(MultiPolygon) containment / topology
     *   - corridor centreline  geometry(LineString)   topology; cast to geography for metric distances
     *   - market bounds        geometry(Polygon)      coarse envelope used to decide which market a point is in
     * Every spatial column has a GiST index. Every editable row has a `version` for optimistic concurrency.
     *
     * A row existing here is reference geography; whether FoodOnTheGo operates there is its status.
     */
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->geometry('bounds', subtype: 'polygon', srid: 4326)->nullable();
        });
        DB::statement('create index markets_bounds_gist on markets using gist (bounds)');
        // India: coarse bounding envelope (not the legal border) — see docs/backend/README.md.
        DB::statement("update markets set bounds = ST_MakeEnvelope(68.0, 6.5, 97.5, 37.1, 4326) where country_code = 'IN' and bounds is null");

        Schema::create('market_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('payment')->default('{}');
            $table->jsonb('tax')->default('{}');
            $table->jsonb('legal')->default('{}');
            $table->jsonb('address')->default('{}');
            $table->jsonb('ordering')->default('{}');
            $table->jsonb('locked_features')->default('[]');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('market_regions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('market_id')->constrained()->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 120);
            $table->string('type', 20);
            $table->string('status', 16)->default('PLANNED')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['market_id', 'code']);
        });
        DB::statement("alter table market_regions add constraint market_regions_type_check check (type in ('STATE', 'UNION_TERRITORY', 'PROVINCE', 'REGION'))");
        DB::statement("alter table market_regions add constraint market_regions_status_check check (status in ('PLANNED', 'PILOT', 'ACTIVE', 'PAUSED', 'DISABLED'))");

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('market_id')->constrained()->restrictOnDelete();
            $table->foreignId('region_id')->constrained('market_regions')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->jsonb('aliases')->default('[]');
            $table->geography('center', subtype: 'point', srid: 4326);
            $table->string('timezone', 64);
            $table->string('status', 16)->default('PLANNED')->index();
            $table->string('launch_stage', 120)->nullable();
            $table->timestampTz('launched_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['market_id', 'slug']);
            $table->index('region_id');
            $table->spatialIndex('center', 'cities_center_gist');
        });
        DB::statement("alter table cities add constraint cities_status_check check (status in ('PLANNED', 'PILOT', 'ACTIVE', 'PAUSED', 'UNAVAILABLE'))");

        Schema::create('service_areas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('market_id')->constrained()->restrictOnDelete();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->string('status', 16)->default('PLANNED')->index();
            $table->geometry('geometry', subtype: 'multipolygon', srid: 4326);
            // Overlap is allowed; where areas overlap the highest priority wins (then the smaller area).
            $table->integer('priority')->default(0);
            $table->string('launch_stage', 120)->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['city_id', 'slug']);
            $table->index('market_id');
            $table->spatialIndex('geometry', 'service_areas_geometry_gist');
        });
        DB::statement("alter table service_areas add constraint service_areas_status_check check (status in ('PLANNED', 'TESTING', 'ACTIVE', 'PAUSED', 'DISABLED'))");
        DB::statement('alter table service_areas add constraint service_areas_geometry_valid_check check (ST_IsValid(geometry) and not ST_IsEmpty(geometry))');
        DB::statement('alter table service_areas add constraint service_areas_effective_check check (effective_from is null or effective_until is null or effective_from < effective_until)');

        Schema::create('route_corridors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('market_id')->constrained()->restrictOnDelete();
            $table->foreignId('origin_city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('destination_city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->jsonb('via_city_ids')->default('[]');
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->string('highway', 80)->nullable();
            $table->string('status', 16)->default('PLANNED')->index();
            $table->geometry('centerline', subtype: 'linestring', srid: 4326);
            $table->unsignedInteger('corridor_width_meters');
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['market_id', 'slug']);
            $table->spatialIndex('centerline', 'route_corridors_centerline_gist');
        });
        DB::statement("alter table route_corridors add constraint route_corridors_status_check check (status in ('PLANNED', 'TESTING', 'ACTIVE', 'PAUSED', 'DISABLED'))");
        DB::statement('alter table route_corridors add constraint route_corridors_width_check check (corridor_width_meters between 100 and 100000)');
        DB::statement('alter table route_corridors add constraint route_corridors_centerline_valid_check check (ST_IsValid(centerline) and ST_NPoints(centerline) >= 2)');

        /**
         * Business / administrative audit trail (who changed what, when, why). Append-only by convention:
         * the application never updates or deletes a row. Separate from security_events (authentication).
         */
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('action', 80)->index();
            $table->string('actor_type', 20)->nullable();
            $table->uuid('actor_public_id')->nullable();
            $table->string('target_type', 40);
            $table->uuid('target_public_id');
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('changes')->default('{}');
            $table->string('request_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['target_type', 'target_public_id', 'occurred_at']);
            $table->index(['market_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('route_corridors');
        Schema::dropIfExists('service_areas');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('market_regions');
        Schema::dropIfExists('market_configurations');
        DB::statement('drop index if exists markets_bounds_gist');
        Schema::table('markets', function (Blueprint $table): void {
            $table->dropColumn(['version', 'bounds']);
        });
    }
};
