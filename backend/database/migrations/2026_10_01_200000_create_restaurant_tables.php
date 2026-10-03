<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restaurant domain (Module 23):
     *
     *   Restaurant Organization ── the business (legal entity / brand owner)
     *        └─ Restaurant Location ── one physical outlet: its own geography, hours, pickup settings, staff access
     *               ├─ Market → Region → City (→ Service Area, resolved with PostGIS)
     *               ├─ cuisines, features, images
     *               ├─ weekly hours, special hours (with periods)
     *               └─ pickup settings, pickup methods
     *
     *   Restaurant User ── Membership ── Organization
     *                          └─ location access (all locations, or the listed ones)
     *
     * An organization and a location are never the same row: one business can run many outlets.
     * The outlet position is geography(Point, 4326) with a GiST index — WGS84 everywhere, like Module 22.
     * Every editable row has a `version` for optimistic concurrency. Nothing here is deleted by the application:
     * records leave service through a status.
     */
    public function up(): void
    {
        $lifecycle = "('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'SUSPENDED', 'INACTIVE')";
        $rejection = "('INCOMPLETE_DOCUMENTS', 'INVALID_BUSINESS', 'DUPLICATE', 'POLICY', 'OTHER')";

        Schema::create('restaurant_organizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('legal_name', 200);
            $table->string('display_name', 160);
            $table->string('slug', 180)->unique();
            $table->string('status', 16)->default('DRAFT')->index();
            // SINGLE_MARKET: every location is in the primary market. MULTI_MARKET is an explicit administrator decision.
            $table->string('market_scope', 16)->default('SINGLE_MARKET');
            $table->foreignId('primary_market_id')->constrained('markets')->restrictOnDelete();
            $table->string('rejection_category', 32)->nullable();
            // Explanation of the current status that the restaurant may read (rejection / suspension). Internal notes live elsewhere.
            $table->string('status_note', 500)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::statement("alter table restaurant_organizations add constraint restaurant_organizations_status_check check (status in {$lifecycle})");
        DB::statement("alter table restaurant_organizations add constraint restaurant_organizations_market_scope_check check (market_scope in ('SINGLE_MARKET', 'MULTI_MARKET'))");
        DB::statement("alter table restaurant_organizations add constraint restaurant_organizations_slug_check check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("alter table restaurant_organizations add constraint restaurant_organizations_rejection_check check (rejection_category is null or rejection_category in {$rejection})");

        Schema::create('restaurant_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('market_id')->constrained()->restrictOnDelete();
            $table->foreignId('region_id')->constrained('market_regions')->restrictOnDelete();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            // The covering service area with the highest priority, resolved by PostGIS — never taken from a client.
            // It is an association for lists and administration; availability is always decided from the geometry.
            $table->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('service_area_resolved_at')->nullable();
            $table->string('name', 160);
            $table->string('branch_label', 160)->nullable();
            $table->string('slug', 180);
            // Administrative status (approval). Separate from the operational status and from accepting orders.
            $table->string('status', 16)->default('DRAFT')->index();
            $table->string('operational_status', 20)->default('OPERATING');
            $table->boolean('accepting_orders')->default(true);
            $table->string('pause_reason', 200)->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('paused_until')->nullable();
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('locale', 15)->nullable();
            $table->string('phone_e164', 16)->nullable();
            $table->string('public_email')->nullable();
            $table->string('website')->nullable();
            $table->string('formatted_address', 300);
            $table->string('address_line1', 200)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->string('short_description', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('pickup_instructions', 500)->nullable();
            $table->unsignedTinyInteger('price_level')->nullable();
            $table->string('rejection_category', 32)->nullable();
            $table->string('status_note', 500)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('hours_version')->default(1);
            $table->timestampsTz();

            $table->unique(['market_id', 'slug']);
            $table->index('organization_id');
            $table->index(['city_id', 'status']);
            $table->index(['market_id', 'status']);
            $table->index('service_area_id');
            $table->spatialIndex('location', 'restaurant_locations_location_gist');
        });
        DB::statement("alter table restaurant_locations add constraint restaurant_locations_status_check check (status in {$lifecycle})");
        DB::statement("alter table restaurant_locations add constraint restaurant_locations_operational_status_check check (operational_status in ('OPERATING', 'TEMPORARILY_CLOSED'))");
        DB::statement("alter table restaurant_locations add constraint restaurant_locations_slug_check check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("alter table restaurant_locations add constraint restaurant_locations_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table restaurant_locations add constraint restaurant_locations_price_level_check check (price_level is null or price_level between 1 and 4)');
        DB::statement("alter table restaurant_locations add constraint restaurant_locations_phone_check check (phone_e164 is null or phone_e164 ~ '^\\+[1-9][0-9]{7,14}$')");
        DB::statement("alter table restaurant_locations add constraint restaurant_locations_rejection_check check (rejection_category is null or rejection_category in {$rejection})");
        // A pause only exists while orders are not accepted.
        DB::statement('alter table restaurant_locations add constraint restaurant_locations_pause_check check (accepting_orders = false or (pause_reason is null and paused_at is null and paused_until is null))');

        foreach (['cuisines', 'restaurant_features'] as $taxonomy) {
            Schema::create($taxonomy, function (Blueprint $table) use ($taxonomy): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('code', 60)->unique();
                $table->string('name', 120);
                $table->string('slug', 140)->unique();
                if ($taxonomy === 'restaurant_features') {
                    $table->string('category', 20);
                }
                $table->string('status', 12)->default('ACTIVE')->index();
                $table->integer('display_order')->default(0);
                $table->timestampsTz();
            });
            DB::statement("alter table {$taxonomy} add constraint {$taxonomy}_status_check check (status in ('ACTIVE', 'INACTIVE'))");
            DB::statement("alter table {$taxonomy} add constraint {$taxonomy}_code_check check (code ~ '^[a-z][a-z0-9_]*$')");
        }
        DB::statement("alter table restaurant_features add constraint restaurant_features_category_check check (category in ('FACILITY', 'DIETARY', 'SERVICE'))");

        Schema::create('restaurant_location_cuisines', function (Blueprint $table): void {
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            // A cuisine that is in use cannot be deleted — it is made INACTIVE instead.
            $table->foreignId('cuisine_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['location_id', 'cuisine_id']);
            $table->index('cuisine_id');
        });

        Schema::create('restaurant_location_features', function (Blueprint $table): void {
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('restaurant_features')->restrictOnDelete();
            $table->primary(['location_id', 'feature_id']);
            $table->index('feature_id');
        });

        /**
         * Image METADATA and ownership. No file is stored by this module: `path` is a storage key / site-relative
         * path, and uploads arrive with the object-storage integration.
         */
        Schema::create('restaurant_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->string('type', 12);
            $table->string('status', 12)->default('ACTIVE');
            $table->string('path', 500);
            $table->string('alt_text', 200)->nullable();
            $table->string('mime_type', 60)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampsTz();

            $table->index(['location_id', 'type', 'status', 'display_order']);
            $table->index('organization_id');
        });
        DB::statement("alter table restaurant_images add constraint restaurant_images_type_check check (type in ('LOGO', 'COVER', 'GALLERY'))");
        DB::statement("alter table restaurant_images add constraint restaurant_images_status_check check (status in ('ACTIVE', 'ARCHIVED'))");

        /**
         * Weekly hours: one row per period, several per day allowed. Times are wall-clock times in the location's
         * own time zone. `closes_at` at or before `opens_at` means the period runs past midnight into the next day
         * (equal = 24 hours). A period belongs to the day on which it opens. day_of_week: 0 = Sunday … 6 = Saturday.
         * `kind` separates the outlet's opening hours from separate FoodOnTheGo ordering hours (reserved: only
         * OPENING is written and read today).
         */
        Schema::create('restaurant_location_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->string('kind', 10)->default('OPENING');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->unsignedTinyInteger('sequence')->default(0);
            $table->timestampsTz();

            $table->unique(['location_id', 'kind', 'day_of_week', 'sequence'], 'restaurant_location_hours_unique');
        });
        DB::statement('alter table restaurant_location_hours add constraint restaurant_location_hours_day_check check (day_of_week between 0 and 6)');
        DB::statement("alter table restaurant_location_hours add constraint restaurant_location_hours_kind_check check (kind in ('OPENING', 'ORDERING'))");

        /**
         * Exceptions for one local date: a closure, or different opening periods. They replace the periods that
         * would open on that date; a period that started the evening before still runs to its end.
         */
        Schema::create('restaurant_special_hours', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_closed');
            $table->string('public_note', 160)->nullable();
            $table->string('internal_note', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['location_id', 'date']);
        });

        Schema::create('restaurant_special_hour_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('special_hour_id')->constrained('restaurant_special_hours')->cascadeOnDelete();
            $table->time('opens_at');
            $table->time('closes_at');
            $table->unsignedTinyInteger('sequence')->default(0);

            $table->unique(['special_hour_id', 'sequence']);
        });

        /**
         * Pickup configuration of one location. These are settings: generating pickup slots, real capacity and
         * the dynamic preparation estimate belong to the pickup-availability module.
         */
        Schema::create('restaurant_pickup_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->unique()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('asap_enabled')->default(true);
            $table->boolean('scheduled_enabled')->default(false);
            $table->unsignedSmallInteger('default_prep_minutes')->default(15);
            $table->unsignedSmallInteger('minimum_lead_minutes')->default(15);
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->unsignedInteger('schedule_horizon_minutes')->default(1440);
            $table->unsignedSmallInteger('slot_interval_minutes')->default(15);
            $table->unsignedSmallInteger('order_cutoff_minutes')->default(0);
            $table->unsignedSmallInteger('capacity_per_slot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::statement('alter table restaurant_pickup_settings add constraint restaurant_pickup_settings_interval_check check (slot_interval_minutes between 1 and 240)');
        DB::statement('alter table restaurant_pickup_settings add constraint restaurant_pickup_settings_prep_check check (default_prep_minutes between 1 and 480)');
        DB::statement('alter table restaurant_pickup_settings add constraint restaurant_pickup_settings_horizon_check check (schedule_horizon_minutes >= minimum_lead_minutes)');

        Schema::create('restaurant_location_pickup_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->string('method', 16);
            $table->boolean('enabled')->default(false);
            $table->string('instructions', 300)->nullable();
            $table->boolean('requires_vehicle_info')->default(false);
            $table->timestampsTz();

            $table->unique(['location_id', 'method']);
        });
        DB::statement("alter table restaurant_location_pickup_methods add constraint restaurant_location_pickup_methods_method_check check (method in ('COUNTER', 'CURBSIDE', 'DRIVE_THROUGH'))");

        /**
         * A restaurant user's membership of one organization: the role held there and the locations it applies
         * to. Role assignments (Module 21) are derived from ACTIVE memberships, so authorization always needs
         * permission + resource scope and a membership that is not active authorises nothing.
         */
        Schema::create('restaurant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('restaurant_user_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('INVITED')->index();
            // true = every location of the organization, including ones added later.
            $table->boolean('all_locations')->default(false);
            // The name the inviter typed. Shown while the invitation is open, so inviting an e-mail that already has
            // an account never reveals that account's own name.
            $table->string('invited_name', 120)->nullable();
            $table->foreignId('invited_by_restaurant_user_id')->nullable()->constrained('restaurant_users')->nullOnDelete();
            $table->foreignId('invited_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['restaurant_user_id', 'organization_id']);
            $table->index(['organization_id', 'status']);
        });
        DB::statement("alter table restaurant_memberships add constraint restaurant_memberships_status_check check (status in ('INVITED', 'ACTIVE', 'SUSPENDED', 'REVOKED'))");

        Schema::create('restaurant_membership_locations', function (Blueprint $table): void {
            $table->foreignId('membership_id')->constrained('restaurant_memberships')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->primary(['membership_id', 'location_id']);
            $table->index('location_id');
        });

        /**
         * Staff invitation links: random, single use, expiring. Only the SHA-256 of the token is stored.
         */
        Schema::create('restaurant_staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained('restaurant_memberships')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
        });

        /**
         * Internal notes of administrators about a restaurant — never shown to the restaurant or to customers.
         */
        Schema::create('restaurant_admin_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('note', 2000);
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'restaurant_admin_notes', 'restaurant_staff_invitations', 'restaurant_membership_locations', 'restaurant_memberships',
            'restaurant_location_pickup_methods', 'restaurant_pickup_settings', 'restaurant_special_hour_periods', 'restaurant_special_hours',
            'restaurant_location_hours', 'restaurant_images', 'restaurant_location_features', 'restaurant_location_cuisines',
            'restaurant_features', 'cuisines', 'restaurant_locations', 'restaurant_organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
