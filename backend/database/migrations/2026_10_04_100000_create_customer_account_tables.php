<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 25 — the customer's own account data. The customer identity (customers: phone, status, market) stays
 * the Module 21 row; this adds profile fields to it and five customer-owned tables. Every child row belongs to
 * exactly one customer; nothing here is reachable through another customer's ids.
 *
 *  - customers ..................... profile and preference fields, deletion request, marketing consent, version
 *  - customer_favorite_locations ... favorite restaurant locations (preference state: unique per pair)
 *  - customer_saved_locations ...... journey shortcuts with an optional PostGIS point (not delivery addresses)
 *  - customer_recent_locations ..... bounded, deduplicated recent selections (privacy policy in config/customer.php)
 *  - customer_payment_methods ...... provider references + safe display metadata only (never credentials)
 *  - customer_notification_preferences  category × channel matrix (rows only where the customer chose)
 *  - customer_phone_changes ........ pending / completed phone changes verified by a code on the new number
 *
 * Deleting a customer row (a privacy process, never an API) cascades to these preference tables only; orders,
 * payments and reviews of later modules will reference customers with RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->jsonb('favorite_cuisines')->nullable();
            $table->boolean('vegetarian_only')->default(false);
            $table->unsignedSmallInteger('search_radius_km')->nullable();
            $table->string('avatar_path', 200)->nullable();
            $table->string('avatar_mime', 40)->nullable();
            $table->timestampTz('avatar_updated_at')->nullable();
            $table->timestampTz('deletion_requested_at')->nullable();
            $table->string('deletion_reason', 300)->nullable();
            $table->timestampTz('promotions_consented_at')->nullable();
            $table->timestampTz('promotions_withdrawn_at')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        DB::statement("alter table customers add constraint customers_gender_check check (gender is null or gender in ('MALE', 'FEMALE', 'OTHER'))");

        Schema::create('customer_favorite_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['customer_id', 'restaurant_location_id']);
            $table->index('restaurant_location_id');
        });

        Schema::create('customer_saved_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('label', 40);
            $table->string('line1', 200)->nullable();
            $table->string('line2', 200)->nullable();
            $table->string('locality', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('formatted_address', 300)->nullable();
            $table->string('place_provider', 32)->nullable();
            $table->string('place_id', 200)->nullable();
            $table->foreignId('market_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('coverage_resolved_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index('customer_id');
        });
        DB::statement('alter table customer_saved_locations add column location geography(Point, 4326) null');
        DB::statement("alter table customer_saved_locations add constraint customer_saved_locations_kind_check check (kind in ('HOME', 'WORK', 'OTHER'))");
        // At most one default per customer, guaranteed by the database (two simultaneous "make default" calls cannot both win).
        DB::statement('create unique index customer_saved_locations_one_default on customer_saved_locations (customer_id) where is_default');

        Schema::create('customer_recent_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 64);
            $table->string('label', 120);
            $table->string('formatted_address', 300)->nullable();
            $table->string('place_provider', 32)->nullable();
            $table->string('place_id', 200)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->unsignedSmallInteger('times_used')->default(1);
            $table->timestampTz('last_used_at');
            $table->timestampsTz();
            $table->unique(['customer_id', 'dedupe_key']);
            $table->index(['customer_id', 'last_used_at']);
        });
        DB::statement('alter table customer_recent_locations add column location geography(Point, 4326) not null');

        Schema::create('customer_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('provider_customer_reference')->nullable();
            $table->text('provider_payment_method_reference');
            $table->string('reference_hash', 64);
            $table->string('type', 24);
            $table->string('brand', 40)->nullable();
            $table->string('display_label', 80);
            $table->char('last4', 4)->nullable();
            $table->unsignedSmallInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->string('upi_handle_masked', 80)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('ACTIVE');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['customer_id', 'status']);
            $table->unique(['provider', 'reference_hash']);
        });
        DB::statement("alter table customer_payment_methods add constraint customer_payment_methods_type_check check (type in ('CARD', 'UPI', 'WALLET', 'NET_BANKING', 'OTHER'))");
        DB::statement("alter table customer_payment_methods add constraint customer_payment_methods_status_check check (status in ('ACTIVE', 'EXPIRED', 'REVOKED', 'UNAVAILABLE'))");
        DB::statement('create unique index customer_payment_methods_one_default on customer_payment_methods (customer_id) where is_default');

        Schema::create('customer_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestampsTz();
            $table->unique(['customer_id', 'category', 'channel']);
        });

        Schema::create('customer_phone_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('new_phone_e164', 16);
            $table->uuid('challenge_public_id')->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['customer_id', 'status']);
        });
        DB::statement("alter table customer_phone_changes add constraint customer_phone_changes_status_check check (status in ('PENDING', 'COMPLETED', 'EXPIRED', 'CANCELLED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_phone_changes');
        Schema::dropIfExists('customer_notification_preferences');
        Schema::dropIfExists('customer_payment_methods');
        Schema::dropIfExists('customer_recent_locations');
        Schema::dropIfExists('customer_saved_locations');
        Schema::dropIfExists('customer_favorite_locations');
        DB::statement('alter table customers drop constraint if exists customers_gender_check');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['date_of_birth', 'gender', 'favorite_cuisines', 'vegetarian_only', 'search_radius_km', 'avatar_path', 'avatar_mime', 'avatar_updated_at', 'deletion_requested_at', 'deletion_reason', 'promotions_consented_at', 'promotions_withdrawn_at', 'version']);
        });
    }
};
