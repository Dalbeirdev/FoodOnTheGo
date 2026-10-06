<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 28 — pickup availability.
 *
 *   carts ──1:1── cart_pickup_selections          the time a customer CHOSE for a cart (a choice, not a reservation)
 *   restaurant_locations ─< pickup_slot_reservations   capacity units used in a slot (orders later; fixtures / holds now)
 *
 * Pickup availability itself — ASAP, the slot list, the recommendation — is CALCULATED on every request from the
 * restaurant's hours and pickup settings, the cart and the clock. No row exists for a possible future time.
 *
 * Instants are stored in UTC; the time zone a selection was made in (the restaurant's) is kept next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_pickup_selections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // One selection per cart: choosing again replaces it. It goes when the cart goes.
            $table->foreignId('cart_id')->unique()->constrained('carts')->cascadeOnDelete();
            $table->string('mode', 12);
            $table->string('pickup_method', 16);
            // SCHEDULED: the start of the chosen slot. ASAP: the earliest pickup estimated when it was last checked.
            $table->timestampTz('pickup_at');
            $table->timestampTz('slot_ends_at')->nullable();
            $table->string('slot_key', 64)->nullable();
            $table->unsignedSmallInteger('slot_interval_minutes')->nullable();
            $table->string('restaurant_timezone', 64);
            // What the choice was last validated against.
            $table->unsignedInteger('cart_version');
            $table->unsignedInteger('settings_version');
            $table->unsignedSmallInteger('estimated_prep_minutes');
            $table->timestampTz('traveler_arrival_at')->nullable();
            $table->string('status', 12)->default('SELECTED');
            $table->string('status_reason', 40)->nullable();
            $table->timestampTz('validated_at');
            $table->timestampTz('expires_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index('pickup_at');
        });
        DB::statement("alter table cart_pickup_selections add constraint cart_pickup_selections_mode_check check (mode in ('ASAP', 'SCHEDULED'))");
        DB::statement("alter table cart_pickup_selections add constraint cart_pickup_selections_method_check check (pickup_method in ('COUNTER', 'CURBSIDE', 'DRIVE_THROUGH'))");
        DB::statement("alter table cart_pickup_selections add constraint cart_pickup_selections_status_check check (status in ('SELECTED', 'STALE', 'INVALID', 'EXPIRED', 'CONVERTED'))");
        // A scheduled selection names its slot; an ASAP selection never does.
        DB::statement("alter table cart_pickup_selections add constraint cart_pickup_selections_slot_check check ((mode = 'SCHEDULED') = (slot_key is not null and slot_interval_minutes is not null and slot_ends_at is not null))");

        /**
         * Capacity units used in one slot of one location. The unit is ORDERS. Module 31 will write a row per order
         * (source ORDER) and checkout may hold one for a short time (source HOLD, with expires_at); until then the only
         * rows are deterministic fixtures and test records (source FIXTURE) — nothing here pretends an order exists.
         */
        Schema::create('pickup_slot_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->timestampTz('slot_starts_at');
            $table->unsignedSmallInteger('units')->default(1);
            $table->string('source', 12);
            $table->string('reference', 64)->nullable();
            $table->string('status', 12)->default('ACTIVE');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            // Named by hand: the generated name would be longer than PostgreSQL's 63 characters.
            $table->index(['location_id', 'slot_starts_at', 'status'], 'pickup_slot_reservations_slot_index');
            $table->index(['status', 'expires_at']);
        });
        DB::statement('alter table pickup_slot_reservations add constraint pickup_slot_reservations_units_check check (units between 1 and 500)');
        DB::statement("alter table pickup_slot_reservations add constraint pickup_slot_reservations_source_check check (source in ('FIXTURE', 'HOLD', 'ORDER'))");
        DB::statement("alter table pickup_slot_reservations add constraint pickup_slot_reservations_status_check check (status in ('ACTIVE', 'RELEASED'))");
        // A hold always ends by itself; an order or a fixture does not.
        DB::statement("alter table pickup_slot_reservations add constraint pickup_slot_reservations_hold_check check (source <> 'HOLD' or expires_at is not null)");
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_slot_reservations');
        Schema::dropIfExists('cart_pickup_selections');
    }
};
