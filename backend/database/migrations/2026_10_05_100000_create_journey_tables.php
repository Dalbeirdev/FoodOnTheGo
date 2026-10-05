<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 26: journey contexts (ephemeral, expiring) and the per-market journey policy.
 *
 * A journey row is a point-in-time search context: the two ends, the departure, the provider's route (full
 * geometry for display, a simplified line for the corridor query), its provenance and the coverage answer. It
 * expires (expires_at) and is removed by journeys:prune — there is deliberately no permanent journey history.
 * Spatial columns are WGS84 / SRID 4326; the route lines are geometry, the end points geography (metres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journeys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->char('guest_token_hash', 64)->nullable()->unique();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->jsonb('origin');
            $table->jsonb('destination');
            $table->timestampTz('departure_at')->nullable();
            $table->boolean('departure_given')->default(false);
            $table->char('route_hash', 64);
            $table->unsignedInteger('distance_meters');
            $table->unsignedInteger('duration_seconds');
            $table->text('encoded_polyline');
            $table->jsonb('bounds');
            $table->jsonb('legs')->default('[]');
            $table->jsonb('flags')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->string('provider', 40);
            $table->string('provider_mode', 12);
            $table->boolean('traffic_aware')->default(false);
            $table->timestampTz('route_calculated_at');
            $table->jsonb('preferences')->default('{}');
            $table->jsonb('coverage')->default('{}');
            $table->unsignedInteger('corridor_width_meters');
            $table->unsignedSmallInteger('route_points');
            $table->unsignedSmallInteger('search_points');
            $table->unsignedSmallInteger('shortlist_count')->nullable();
            $table->unsignedSmallInteger('detour_candidates_count')->nullable();
            $table->unsignedInteger('provider_latency_ms')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('last_accessed_at')->nullable();
            $table->timestampsTz();

            $table->index(['customer_id', 'created_at']);
        });

        DB::statement('alter table journeys add column origin_point geography(Point, 4326) not null');
        DB::statement('alter table journeys add column destination_point geography(Point, 4326) not null');
        DB::statement('alter table journeys add column route_geometry geometry(LineString, 4326) not null');
        DB::statement('alter table journeys add column search_geometry geometry(LineString, 4326) not null');
        DB::statement("alter table journeys add constraint journeys_provider_mode_check check (provider_mode in ('development', 'live'))");
        DB::statement('alter table journeys add constraint journeys_owner_check check ((customer_id is not null and guest_token_hash is null) or (customer_id is null and guest_token_hash is not null))');

        // Per-market journey policy (corridor width, detour limit, candidate cap) within the platform bounds of config/journey.php.
        Schema::table('market_configurations', function (Blueprint $table): void {
            $table->jsonb('journey')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('market_configurations', function (Blueprint $table): void {
            $table->dropColumn('journey');
        });
        Schema::dropIfExists('journeys');
    }
};
