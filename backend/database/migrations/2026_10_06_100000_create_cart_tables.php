<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 27 — the cart domain.
 *
 *   customers / guest token ─< carts >─ restaurant_locations
 *                               └─< cart_items >─ menu_items
 *                                      └─< cart_item_options >─ menu_option_groups / menu_options
 *
 * One ACTIVE cart per owner, and a cart belongs to exactly one restaurant location (the V1 rule) — both are
 * enforced here, not only in the application. A cart is owned by a customer or by whoever holds the guest token
 * (only its SHA-256 hash is stored). Money is integer minor units in the cart's currency, which is the location's.
 *
 * The prices on a line are a SNAPSHOT of what the customer saw; the backend recalculates from the menu on every
 * read and reports the difference. Menu rows are never hard-deleted (Module 24), so the foreign keys restrict:
 * archiving an item leaves the line in place and the cart reports it as unavailable.
 *
 * The journey is a reference (the journey's public id) plus a small display summary: journeys are ephemeral and
 * pruned, and a cart must survive that. No coordinates and no route geometry are stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->char('guest_token_hash', 64)->nullable();
            // The guest token a cart was claimed from at sign-in: that token never addresses a cart again.
            $table->char('spent_token_hash', 64)->nullable()->index();
            $table->foreignId('restaurant_location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->uuid('journey_public_id')->nullable();
            $table->jsonb('journey_summary')->nullable();
            $table->string('status', 12)->default('ACTIVE');
            $table->string('closed_reason', 24)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->char('currency', 3);
            $table->unsignedInteger('version')->default(1);
            // The menu's catalog_version when the cart was last written: a different value means "revalidate".
            $table->unsignedInteger('menu_catalog_version')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_activity_at');
            $table->timestampTz('converted_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index(['status', 'closed_at']);
            $table->index(['restaurant_location_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['guest_token_hash', 'status']);
        });
        DB::statement("alter table carts add constraint carts_status_check check (status in ('ACTIVE', 'CONVERTED', 'EXPIRED', 'ABANDONED', 'INVALIDATED'))");
        DB::statement("alter table carts add constraint carts_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table carts add constraint carts_owner_check check (not (customer_id is not null and guest_token_hash is not null))');
        DB::statement("alter table carts add constraint carts_active_owner_check check (status <> 'ACTIVE' or customer_id is not null or guest_token_hash is not null)");
        DB::statement("alter table carts add constraint carts_closed_check check ((status = 'ACTIVE') = (closed_at is null))");
        // ONE ACTIVE CART per owner: two concurrent first "add" requests cannot both create one.
        DB::statement("create unique index carts_one_active_per_customer on carts (customer_id) where status = 'ACTIVE' and customer_id is not null");
        DB::statement("create unique index carts_one_active_per_guest on carts (guest_token_hash) where status = 'ACTIVE' and guest_token_hash is not null");

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('item_name', 120);
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('unit_price_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('item_version');
            $table->string('special_instructions', 500)->nullable();
            // Canonical configuration: item + sorted option ids + normalized instructions. Equal signature = same line.
            $table->char('signature', 64);
            $table->timestampsTz();

            $table->unique(['cart_id', 'signature']);
            $table->index('menu_item_id');
        });
        DB::statement('alter table cart_items add constraint cart_items_quantity_check check (quantity >= 1 and quantity <= 1000)');
        DB::statement("alter table cart_items add constraint cart_items_currency_check check (currency ~ '^[A-Z]{3}$')");

        Schema::create('cart_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_item_id')->constrained('cart_items')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('menu_option_groups')->restrictOnDelete();
            $table->foreignId('option_id')->constrained('menu_options')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('group_name', 120);
            $table->string('option_name', 120);
            $table->bigInteger('price_adjustment_minor');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampsTz();

            $table->unique(['cart_item_id', 'option_id']);
            $table->index('group_id');
            $table->index('option_id');
        });
        DB::statement("alter table cart_item_options add constraint cart_item_options_kind_check check (kind in ('VARIANT', 'MODIFIER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_options');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
