<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders (Module 31).
 *
 *   checkout_sessions ─1─ order_fulfillments      one attempt record per checkout: the lock row and the exactly-once guard
 *   checkout_sessions ─1─ orders ─< order_items ─< order_item_options
 *   payment_attempts  ─1─ orders ─< order_tax_lines
 *                               ─< order_fee_lines
 *                               ─< order_events               append-only timeline
 *                               ─1─ order_pickup_credentials  the opaque token behind the QR code + the short code (hashed)
 *                               ─< order_refunds              foundation: what has to go back, and why (no provider call yet)
 *
 * An order is the immutable commercial record of what was placed: every amount, name, option and time is copied
 * from the confirmed checkout and never read again from the menu, the tax rules or the restaurant. Money is integer
 * minor units with an ISO currency. Orders are retained: nothing here cascades from a customer, a restaurant or a
 * checkout being removed (the links are set to null; the public ids stay), and no column holds a card number, a
 * credential, a provider secret or a customer's route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('checkout_session_id')->nullable()->constrained('checkout_sessions')->nullOnDelete();
            // One row per checkout, whatever happens to the checkout: the row other workers wait on.
            $table->uuid('checkout_public_id')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->uuid('payment_public_id')->nullable();
            $table->string('trigger', 20);
            $table->string('status', 16)->default('PENDING');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 48)->nullable();
            $table->string('last_error_detail', 300)->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->foreignId('order_id')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'next_attempt_at']);
            $table->index('payment_attempt_id');
        });
        DB::statement("alter table order_fulfillments add constraint order_fulfillments_trigger_check check (\"trigger\" in ('PAYMENT_VERIFIED', 'ZERO_TOTAL', 'RECOVERY'))");
        DB::statement("alter table order_fulfillments add constraint order_fulfillments_status_check check (status in ('PENDING', 'PROCESSING', 'SUCCEEDED', 'RETRYING', 'FAILED', 'REVIEW_REQUIRED'))");

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('order_number', 24)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            // One order per checkout and one per payment: the database says so, whatever the code is asked twice.
            $table->foreignId('checkout_session_id')->nullable()->unique()->constrained('checkout_sessions')->nullOnDelete();
            $table->uuid('checkout_public_id')->unique();
            $table->foreignId('payment_attempt_id')->nullable()->unique()->constrained('payment_attempts')->nullOnDelete();
            $table->uuid('payment_public_id')->nullable();
            $table->string('payment_state', 24);
            $table->string('refund_status', 12)->default('NONE');
            $table->string('status', 24);
            $table->string('status_reason', 40)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('tax_included_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedSmallInteger('item_count');
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('promotion_code', 40)->nullable();
            $table->string('pickup_mode', 12);
            $table->string('pickup_method', 16);
            $table->timestampTz('pickup_at');
            $table->timestampTz('pickup_slot_starts_at');
            $table->timestampTz('pickup_slot_ends_at');
            $table->string('restaurant_timezone', 64);
            $table->unsignedSmallInteger('estimated_prep_minutes')->nullable();
            $table->timestampTz('traveler_arrival_at')->nullable();
            $table->foreignId('pickup_reservation_id')->nullable()->constrained('pickup_slot_reservations')->nullOnDelete();
            $table->string('customer_note', 300)->nullable();
            $table->string('rejection_reason', 24)->nullable();
            $table->string('rejection_note', 300)->nullable();
            $table->string('cancellation_reason', 300)->nullable();
            // What was placed, as it was placed: the customer, the restaurant, the legal documents accepted, the payment,
            // the promotion, the configuration that priced it, the journey context — safe fields only.
            $table->jsonb('snapshot');
            $table->timestampTz('placed_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('preparing_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['customer_id', 'placed_at']);
            $table->index(['location_id', 'status', 'pickup_at']);
            $table->index(['location_id', 'placed_at']);
            $table->index(['organization_id', 'placed_at']);
            $table->index(['market_id', 'placed_at']);
            $table->index(['status', 'pickup_at']);
            $table->index('refund_status');
            $table->index('payment_public_id');
        });
        DB::statement("alter table orders add constraint orders_status_check check (status in ('WAITING_FOR_RESTAURANT', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP', 'COMPLETED', 'REJECTED', 'CANCELLED'))");
        DB::statement("alter table orders add constraint orders_refund_check check (refund_status in ('NONE', 'REQUIRED', 'PENDING', 'PARTIAL', 'REFUNDED', 'FAILED'))");
        DB::statement("alter table orders add constraint orders_payment_state_check check (payment_state in ('PAID', 'PAYMENT_NOT_REQUIRED'))");
        DB::statement("alter table orders add constraint orders_paid_check check ((payment_state = 'PAID') = (payment_public_id is not null))");
        DB::statement("alter table orders add constraint orders_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement("alter table orders add constraint orders_mode_check check (pickup_mode in ('ASAP', 'SCHEDULED'))");
        DB::statement('alter table orders add constraint orders_total_check check (discount_minor <= subtotal_minor and total_minor = subtotal_minor - discount_minor + tax_minor + fee_minor)');
        DB::statement("alter table orders add constraint orders_paid_total_check check (payment_state <> 'PAYMENT_NOT_REQUIRED' or total_minor = 0)");
        DB::statement("alter table orders add constraint orders_rejection_check check ((status = 'REJECTED') = (rejected_at is not null) and (status = 'REJECTED') = (rejection_reason is not null))");
        DB::statement("alter table orders add constraint orders_rejection_reason_check check (rejection_reason is null or rejection_reason in ('ITEM_UNAVAILABLE', 'KITCHEN_CAPACITY', 'CLOSING', 'TECHNICAL_ISSUE', 'OTHER'))");
        DB::statement("alter table orders add constraint orders_cancellation_check check ((status = 'CANCELLED') = (cancelled_at is not null))");
        DB::statement('alter table orders add constraint orders_version_check check (version >= 1)');
        DB::statement("alter table orders add constraint orders_number_check check (order_number ~ '^FOTG-[0-9]{8}-[A-HJ-NP-Z2-9]{6}$')");

        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->uuid('item_public_id')->nullable();
            $table->string('item_slug', 120)->nullable();
            $table->string('name', 160);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_subtotal_minor');
            $table->char('currency', 3);
            $table->string('special_instructions', 300)->nullable();
            $table->timestampsTz();

            $table->index(['order_id', 'position']);
        });
        DB::statement('alter table order_items add constraint order_items_quantity_check check (quantity between 1 and 500)');
        DB::statement('alter table order_items add constraint order_items_line_check check (line_subtotal_minor = unit_price_minor * quantity)');

        Schema::create('order_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 10);
            $table->string('group_name', 120);
            $table->string('option_name', 120);
            $table->bigInteger('price_adjustment_minor')->default(0);
            $table->uuid('group_public_id')->nullable();
            $table->uuid('option_public_id')->nullable();

            $table->index(['order_item_id', 'position']);
        });
        DB::statement("alter table order_item_options add constraint order_item_options_kind_check check (kind in ('VARIANT', 'MODIFIER'))");

        Schema::create('order_tax_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('code', 40);
            $table->string('label', 120);
            $table->string('applies_to', 16);
            $table->unsignedSmallInteger('rate_basis_points');
            $table->unsignedBigInteger('taxable_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('included_in_prices')->default(false);

            $table->index(['order_id', 'position']);
        });

        Schema::create('order_fee_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('code', 40);
            $table->string('label', 120);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('taxable')->default(false);

            $table->index(['order_id', 'position']);
        });
        DB::statement("alter table order_fee_lines add constraint order_fee_lines_code_check check (code not like '%DELIVERY%' and code not like '%COMMISSION%')");

        Schema::create('order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('actor_type', 20);
            $table->uuid('actor_public_id')->nullable();
            $table->jsonb('detail')->nullable();
            $table->boolean('customer_visible')->default(true);
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });
        DB::statement("alter table order_events add constraint order_events_actor_check check (actor_type in ('SYSTEM', 'CUSTOMER', 'RESTAURANT_USER', 'ADMIN'))");

        Schema::create('order_pickup_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            // The QR code carries an opaque random token; only its hash is stored. The short code the customer reads
            // out is stored encrypted (it is shown again on every visit) with a hash for lookup.
            $table->char('token_hash', 64)->unique();
            $table->text('display_code');
            $table->char('display_code_hash', 64)->index();
            $table->string('status', 12)->default('ACTIVE');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table order_pickup_credentials add constraint order_pickup_credentials_status_check check (status in ('ACTIVE', 'CONSUMED', 'REVOKED', 'EXPIRED'))");

        Schema::create('order_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 40);
            $table->string('status', 12)->default('REQUIRED');
            $table->string('requested_by_type', 20);
            $table->uuid('requested_by_public_id')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampsTz();

            $table->index(['status', 'requested_at']);
        });
        DB::statement("alter table order_refunds add constraint order_refunds_status_check check (status in ('REQUIRED', 'PENDING', 'PROCESSED', 'FAILED'))");
        DB::statement('alter table order_refunds add constraint order_refunds_amount_check check (amount_minor > 0)');
        // One open refund requirement per order: a rejection repeated by two people asks for the money back once.
        DB::statement("create unique index order_refunds_one_open_per_order on order_refunds (order_id) where status in ('REQUIRED', 'PENDING')");
    }

    public function down(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('order_pickup_credentials');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_fee_lines');
        Schema::dropIfExists('order_tax_lines');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_fulfillments');
    }
};
