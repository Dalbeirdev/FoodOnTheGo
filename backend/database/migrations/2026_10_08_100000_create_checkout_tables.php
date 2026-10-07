<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 29 — checkout.
 *
 *   carts ─< checkout_sessions ─< checkout_legal_acceptances      a short-lived, server-priced quote for one cart
 *                     │──1:1── pickup_slot_reservations (HOLD)    the pickup time held while the customer pays
 *                     └──1:1── promotion_reservations             a use of a limited promotion held for this checkout
 *   promotions                                                    promotion codes (fixed amount / percentage)
 *   markets ─< tax_rules ─< tax_rule_components                   configured tax — no rate lives in code
 *   markets ─< fee_rules                                          configured fees — none is seeded
 *
 * A checkout session is NOT an order: it expires, it goes stale when anything it was priced from changes, and it is
 * recreated rather than edited. Every amount is an integer in the currency's minor unit; every rate is in basis
 * points (1 % = 100). Nothing here charges money (Module 30) or creates an order (Module 31).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // Stored trimmed and upper-cased: codes are case-insensitive and unique across the platform.
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('type', 16);
            // Lifecycle only. SCHEDULED and EXPIRED are not stored: they follow from starts_at / ends_at and the clock.
            $table->string('status', 12)->default('DRAFT');
            $table->string('scope', 28);
            $table->foreignId('market_id')->nullable()->constrained('markets')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->restrictOnDelete();
            // The currency of every amount of this promotion. A percentage without amounts needs none.
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedInteger('percent_basis_points')->nullable();
            $table->unsignedBigInteger('maximum_discount_minor')->nullable();
            $table->unsignedBigInteger('minimum_spend_minor')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            // Completed redemptions. An order adds to it (Module 31); checkout only reserves.
            $table->unsignedInteger('redeemed_count')->default(0);
            // Who pays for the discount — for later reporting; it does not change what the customer pays.
            $table->string('funding_source', 12)->default('PLATFORM');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index('market_id');
            $table->index('organization_id');
            $table->index('location_id');
        });
        DB::statement("alter table promotions add constraint promotions_type_check check (type in ('FIXED_AMOUNT', 'PERCENTAGE'))");
        DB::statement("alter table promotions add constraint promotions_status_check check (status in ('DRAFT', 'ACTIVE', 'PAUSED', 'DISABLED'))");
        DB::statement("alter table promotions add constraint promotions_scope_check check (scope in ('PLATFORM', 'MARKET', 'RESTAURANT_ORGANIZATION', 'RESTAURANT_LOCATION'))");
        DB::statement("alter table promotions add constraint promotions_funding_check check (funding_source in ('PLATFORM', 'RESTAURANT', 'SHARED'))");
        DB::statement('alter table promotions add constraint promotions_code_check check (code = upper(btrim(code)) and char_length(code) >= 3)');
        // A fixed amount has an amount and a currency and no percentage; a percentage is 0.01 % … 100 % and has no amount.
        DB::statement("alter table promotions add constraint promotions_value_check check ((type = 'FIXED_AMOUNT' and amount_minor is not null and amount_minor > 0 and percent_basis_points is null and maximum_discount_minor is null) or (type = 'PERCENTAGE' and percent_basis_points between 1 and 10000 and amount_minor is null))");
        // Any amount needs its currency.
        DB::statement('alter table promotions add constraint promotions_currency_check check ((amount_minor is null and maximum_discount_minor is null and minimum_spend_minor is null) or currency is not null)');
        DB::statement("alter table promotions add constraint promotions_currency_format_check check (currency is null or currency ~ '^[A-Z]{3}$')");
        // The scope names exactly what it needs.
        DB::statement("alter table promotions add constraint promotions_scope_target_check check ((scope = 'PLATFORM' and market_id is null and organization_id is null and location_id is null) or (scope = 'MARKET' and market_id is not null and organization_id is null and location_id is null) or (scope = 'RESTAURANT_ORGANIZATION' and organization_id is not null and location_id is null) or (scope = 'RESTAURANT_LOCATION' and location_id is not null))");
        DB::statement('alter table promotions add constraint promotions_window_check check (starts_at is null or ends_at is null or ends_at > starts_at)');
        DB::statement('alter table promotions add constraint promotions_limits_check check ((usage_limit is null or usage_limit > 0) and (per_customer_limit is null or per_customer_limit > 0))');

        /**
         * Configured tax. A rule belongs to a market (optionally to one restaurant location), says WHAT it taxes, HOW the
         * price relates to the tax (added at checkout, or already inside the price) and whether the promotion discount
         * comes off before the tax. Its components carry the rates. A rule can be changed only while it is a DRAFT;
         * an ACTIVE rule is ended or disabled and a new one is created — so a checkout or an order can always name the
         * exact rule it was priced with.
         */
        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('applies_to', 12);
            $table->string('price_treatment', 12);
            $table->string('discount_treatment', 16)->default('AFTER_DISCOUNT');
            $table->string('status', 12)->default('DRAFT');
            // TEST = a development / test configuration, never applied where test rules are switched off (production).
            $table->string('approval', 12)->default('TEST');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['market_id', 'status', 'effective_from', 'effective_until'], 'tax_rules_scope_index');
            $table->index('location_id');
        });
        DB::statement("alter table tax_rules add constraint tax_rules_applies_check check (applies_to in ('MERCHANDISE', 'FEES'))");
        DB::statement("alter table tax_rules add constraint tax_rules_treatment_check check (price_treatment in ('EXCLUSIVE', 'INCLUSIVE'))");
        DB::statement("alter table tax_rules add constraint tax_rules_discount_check check (discount_treatment in ('AFTER_DISCOUNT', 'BEFORE_DISCOUNT'))");
        DB::statement("alter table tax_rules add constraint tax_rules_status_check check (status in ('DRAFT', 'ACTIVE', 'DISABLED'))");
        DB::statement("alter table tax_rules add constraint tax_rules_approval_check check (approval in ('TEST', 'APPROVED'))");
        DB::statement('alter table tax_rules add constraint tax_rules_window_check check (effective_until is null or effective_until > effective_from)');

        Schema::create('tax_rule_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_rule_id')->constrained('tax_rules')->cascadeOnDelete();
            $table->string('code', 24);
            // What the customer reads on the tax line.
            $table->string('label', 60);
            $table->unsignedInteger('rate_basis_points');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestampsTz();

            $table->unique(['tax_rule_id', 'code']);
        });
        DB::statement('alter table tax_rule_components add constraint tax_rule_components_rate_check check (rate_basis_points between 0 and 10000)');

        /**
         * Configured fees. None is seeded: a fee exists only when an administrator configures it. There is no delivery
         * fee in a pickup product, and a restaurant's commission is a settlement matter, never a customer fee.
         */
        Schema::create('fee_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('label', 60);
            $table->string('type', 12);
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedInteger('percent_basis_points')->nullable();
            // What a percentage is taken of. A promotion never reduces a fee unless the fee is configured that way.
            $table->string('percent_of', 20)->default('SUBTOTAL');
            $table->unsignedBigInteger('minimum_minor')->nullable();
            $table->unsignedBigInteger('maximum_minor')->nullable();
            $table->string('pickup_method', 16)->nullable();
            $table->boolean('taxable')->default(false);
            $table->string('status', 12)->default('DRAFT');
            $table->string('approval', 12)->default('TEST');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['market_id', 'status', 'effective_from', 'effective_until'], 'fee_rules_scope_index');
            $table->index('location_id');
        });
        DB::statement("alter table fee_rules add constraint fee_rules_type_check check (type in ('FIXED', 'PERCENTAGE'))");
        DB::statement("alter table fee_rules add constraint fee_rules_code_check check (code ~ '^[A-Z][A-Z0-9_]{2,23}$' and code not like '%DELIVERY%' and code not like '%COMMISSION%')");
        DB::statement("alter table fee_rules add constraint fee_rules_value_check check ((type = 'FIXED' and amount_minor is not null and amount_minor > 0 and currency is not null and percent_basis_points is null) or (type = 'PERCENTAGE' and percent_basis_points between 1 and 10000 and amount_minor is null))");
        DB::statement('alter table fee_rules add constraint fee_rules_bounds_check check ((minimum_minor is null and maximum_minor is null) or currency is not null)');
        DB::statement('alter table fee_rules add constraint fee_rules_range_check check (minimum_minor is null or maximum_minor is null or maximum_minor >= minimum_minor)');
        DB::statement("alter table fee_rules add constraint fee_rules_percent_of_check check (percent_of in ('SUBTOTAL', 'DISCOUNTED_SUBTOTAL'))");
        DB::statement("alter table fee_rules add constraint fee_rules_method_check check (pickup_method is null or pickup_method in ('COUNTER', 'CURBSIDE', 'DRIVE_THROUGH'))");
        DB::statement("alter table fee_rules add constraint fee_rules_status_check check (status in ('DRAFT', 'ACTIVE', 'DISABLED'))");
        DB::statement("alter table fee_rules add constraint fee_rules_approval_check check (approval in ('TEST', 'APPROVED'))");
        DB::statement('alter table fee_rules add constraint fee_rules_window_check check (effective_until is null or effective_until > effective_from)');

        Schema::create('checkout_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            // What this checkout was priced from. A different cart version or pickup selection makes it stale.
            $table->unsignedInteger('cart_version');
            $table->foreignId('pickup_selection_id')->nullable()->constrained('cart_pickup_selections')->nullOnDelete();
            $table->unsignedInteger('pickup_selection_version');
            $table->string('pickup_mode', 12);
            $table->string('pickup_method', 16);
            $table->timestampTz('pickup_at');
            $table->timestampTz('pickup_slot_starts_at');
            $table->timestampTz('pickup_slot_ends_at');
            $table->string('restaurant_timezone', 64);
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('status', 16)->default('DRAFT');
            $table->string('status_reason', 40)->nullable();
            // The authoritative amounts, all in the minor unit of `currency`.
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            // Tax added on top of the prices, and tax that is already inside them (reported, not added).
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('tax_included_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->boolean('payment_required')->default(true);
            // sha256 over everything the total follows from. Payment is bound to it.
            $table->char('pricing_fingerprint', 64);
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('promotion_code', 40)->nullable();
            // The validated lines, the pickup, the breakdown and the configuration versions — as shown to the customer.
            $table->jsonb('snapshot');
            $table->string('order_note', 300)->nullable();
            $table->string('payment_method_type', 16)->nullable();
            $table->foreignId('customer_payment_method_id')->nullable()->constrained('customer_payment_methods')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('payment_started_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('location_id');
            $table->index('market_id');
            $table->index('promotion_id');
        });
        DB::statement("alter table checkout_sessions add constraint checkout_sessions_status_check check (status in ('DRAFT', 'READY', 'STALE', 'EXPIRED', 'PAYMENT_PENDING', 'CONVERTED', 'CANCELLED'))");
        DB::statement("alter table checkout_sessions add constraint checkout_sessions_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement("alter table checkout_sessions add constraint checkout_sessions_mode_check check (pickup_mode in ('ASAP', 'SCHEDULED'))");
        // The total is what the parts add up to; a discount never exceeds the merchandise; nothing is negative (unsigned).
        DB::statement('alter table checkout_sessions add constraint checkout_sessions_total_check check (discount_minor <= subtotal_minor and total_minor = subtotal_minor - discount_minor + tax_minor + fee_minor)');
        DB::statement('alter table checkout_sessions add constraint checkout_sessions_payment_check check (payment_required = (total_minor > 0))');
        DB::statement("alter table checkout_sessions add constraint checkout_sessions_ready_check check (status not in ('READY', 'PAYMENT_PENDING', 'CONVERTED') or confirmed_at is not null)");
        // One live checkout per cart: a second device gets the same one, a new one replaces the old.
        DB::statement("create unique index checkout_sessions_one_live_per_cart on checkout_sessions (cart_id) where status in ('DRAFT', 'READY', 'PAYMENT_PENDING')");

        /**
         * What a customer accepted when confirming a checkout: which document, in which version and state. No IP
         * address and no device fingerprint — the request id is enough to find the request.
         */
        Schema::create('checkout_legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_session_id')->constrained('checkout_sessions')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('document_type', 16);
            $table->string('document_version', 40);
            $table->date('document_effective_date')->nullable();
            // The approval state of the text at that moment (e.g. DRAFT_PENDING_APPROVAL).
            $table->string('document_status', 32);
            $table->timestampTz('accepted_at');
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['checkout_session_id', 'document_type']);
            $table->index(['customer_id', 'document_type']);
        });
        DB::statement("alter table checkout_legal_acceptances add constraint checkout_legal_acceptances_type_check check (document_type in ('TERMS', 'PRIVACY', 'REFUND'))");

        /**
         * A use of a limited promotion held for one checkout, so that two customers cannot both take the last one.
         * ACTIVE while the checkout is confirmed, RELEASED when it ends without an order, REDEEMED when an order uses it
         * (Module 31, which also raises promotions.redeemed_count).
         */
        Schema::create('promotion_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('checkout_session_id')->nullable()->unique()->constrained('checkout_sessions')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('status', 12)->default('ACTIVE');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['promotion_id', 'status', 'expires_at']);
            $table->index(['promotion_id', 'customer_id', 'status']);
        });
        DB::statement("alter table promotion_reservations add constraint promotion_reservations_status_check check (status in ('ACTIVE', 'RELEASED', 'REDEEMED'))");

        // The pickup hold of a checkout is a capacity row of Module 28 (source HOLD) that knows its checkout.
        Schema::table('pickup_slot_reservations', function (Blueprint $table): void {
            $table->foreignId('checkout_session_id')->nullable()->constrained('checkout_sessions')->nullOnDelete();
        });
        DB::statement('alter table pickup_slot_reservations drop constraint pickup_slot_reservations_status_check');
        DB::statement("alter table pickup_slot_reservations add constraint pickup_slot_reservations_status_check check (status in ('ACTIVE', 'RELEASED', 'EXPIRED'))");
        DB::statement("create unique index pickup_slot_reservations_one_hold_per_checkout on pickup_slot_reservations (checkout_session_id) where status = 'ACTIVE' and checkout_session_id is not null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists pickup_slot_reservations_one_hold_per_checkout');
        DB::statement("update pickup_slot_reservations set status = 'RELEASED' where status = 'EXPIRED'");
        DB::statement('alter table pickup_slot_reservations drop constraint pickup_slot_reservations_status_check');
        DB::statement("alter table pickup_slot_reservations add constraint pickup_slot_reservations_status_check check (status in ('ACTIVE', 'RELEASED'))");
        Schema::table('pickup_slot_reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('checkout_session_id');
        });
        Schema::dropIfExists('promotion_reservations');
        Schema::dropIfExists('checkout_legal_acceptances');
        Schema::dropIfExists('checkout_sessions');
        Schema::dropIfExists('fee_rules');
        Schema::dropIfExists('tax_rule_components');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('promotions');
    }
};
