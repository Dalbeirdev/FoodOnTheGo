<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 24 — the menu domain: one domain for the Restaurant Dashboard, the customer apps and administrators.
 *
 *   restaurant_locations ─< menus ─< menu_categories ─< menu_items ─< menu_option_groups ─< menu_options
 *                                                                   ├< menu_item_images
 *                                                                   └>< dietary_tags
 *
 * Money is integer minor units with an explicit ISO 4217 code; the menu's currency is the location's and every
 * item and option inherits it. Nothing is hard-deleted once it may have been seen by a customer: rows move to
 * ARCHIVED (orders will snapshot what they sold — Module 31). Foreign keys restrict deletes for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->string('status', 12)->default('ACTIVE');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('display_order')->default(0);
            // Bumped on every customer-visible change anywhere in the menu tree: cache key and stale-detection token.
            $table->unsignedInteger('catalog_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['location_id', 'name']);
            $table->index(['location_id', 'status', 'display_order']);
        });
        DB::statement("alter table menus add constraint menus_status_check check (status in ('DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED'))");

        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('status', 12)->default('ACTIVE');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['menu_id', 'status', 'display_order']);
        });
        DB::statement("alter table menu_categories add constraint menu_categories_status_check check (status in ('ACTIVE', 'INACTIVE', 'ARCHIVED'))");

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // menu_id is derivable from the category; it is kept so that the slug can be unique per menu and an item
            // can move between categories of the same menu without touching its address.
            $table->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('menu_categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('description', 1000)->nullable();
            $table->unsignedBigInteger('base_price_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('ACTIVE');
            $table->unsignedSmallInteger('preparation_minutes')->nullable();
            $table->boolean('featured')->default(false);
            $table->unsignedSmallInteger('min_quantity')->default(1);
            $table->unsignedSmallInteger('max_quantity')->default(20);
            $table->string('allergen_information', 500)->nullable();
            $table->string('ingredients', 500)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['menu_id', 'slug']);
            $table->index(['category_id', 'status', 'display_order']);
            $table->index(['menu_id', 'status']);
        });
        DB::statement("alter table menu_items add constraint menu_items_status_check check (status in ('ACTIVE', 'SOLD_OUT', 'TEMPORARILY_UNAVAILABLE', 'DISABLED', 'ARCHIVED'))");
        DB::statement('alter table menu_items add constraint menu_items_quantity_check check (min_quantity >= 1 and max_quantity >= min_quantity)');

        /**
         * One table for variant groups (Size, Portion, Crust …) and modifier groups (Add-ons, Spice level, Remove …):
         * the rules are the same (required, min / max selections); `kind` only tells a client where to show it.
         */
        Schema::create('menu_option_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('item_id')->constrained('menu_items')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('name', 120);
            $table->string('description', 200)->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('min_selections')->default(0);
            $table->unsignedSmallInteger('max_selections')->default(1);
            $table->string('status', 12)->default('ACTIVE');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampsTz();

            $table->index(['item_id', 'status', 'display_order']);
        });
        DB::statement("alter table menu_option_groups add constraint menu_option_groups_kind_check check (kind in ('VARIANT', 'MODIFIER'))");
        DB::statement("alter table menu_option_groups add constraint menu_option_groups_status_check check (status in ('ACTIVE', 'INACTIVE', 'ARCHIVED'))");
        DB::statement('alter table menu_option_groups add constraint menu_option_groups_selection_check check (max_selections >= min_selections and max_selections >= 1)');

        Schema::create('menu_options', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('group_id')->constrained('menu_option_groups')->restrictOnDelete();
            $table->string('name', 120);
            $table->bigInteger('price_adjustment_minor')->default(0);
            $table->string('status', 24)->default('ACTIVE');
            $table->boolean('default_selected')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampsTz();

            $table->index(['group_id', 'status', 'display_order']);
        });
        DB::statement("alter table menu_options add constraint menu_options_status_check check (status in ('ACTIVE', 'TEMPORARILY_UNAVAILABLE', 'DISABLED', 'ARCHIVED'))");

        Schema::create('menu_item_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('item_id')->constrained('menu_items')->restrictOnDelete();
            $table->string('path', 500);
            $table->string('mime_type', 60);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->string('alt_text', 200)->nullable();
            $table->string('status', 12)->default('ACTIVE');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampsTz();

            $table->index(['item_id', 'status', 'display_order']);
        });
        DB::statement("alter table menu_item_images add constraint menu_item_images_status_check check (status in ('ACTIVE', 'ARCHIVED'))");

        // Reference data (every environment): controlled dietary labels a restaurant may attach — information, never a safety guarantee.
        Schema::create('dietary_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('kind', 12);
            $table->string('status', 12)->default('ACTIVE');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement("alter table dietary_tags add constraint dietary_tags_kind_check check (kind in ('DIET', 'CONTAINS', 'PREFERENCE'))");

        Schema::create('menu_item_dietary_tags', function (Blueprint $table): void {
            $table->foreignId('item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('dietary_tag_id')->constrained('dietary_tags')->restrictOnDelete();
            $table->primary(['item_id', 'dietary_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_dietary_tags');
        Schema::dropIfExists('dietary_tags');
        Schema::dropIfExists('menu_item_images');
        Schema::dropIfExists('menu_options');
        Schema::dropIfExists('menu_option_groups');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
        Schema::dropIfExists('menus');
    }
};
