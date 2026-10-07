<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (Module 30).
 *
 *   checkout_sessions ──< payment_attempts ──< payment_attempt_events      (the timeline of one attempt)
 *                              ▲
 *   payment_webhook_events ────┘                                          (what the provider told us, deduplicated)
 *
 * A payment attempt is ONE try to pay ONE confirmed checkout through ONE provider order. A checkout can have several
 * attempts (failed, cancelled, expired … then one that succeeds) but at most one that is open and at most one that is
 * VERIFIED — both are rules of the database, not only of the code.
 *
 * A payment is a financial record: it outlives the checkout and the cart it was made for. The links to them become
 * null when those are cleaned up, and the attempt keeps what it needs to stand alone (the checkout's public id, its
 * version and pricing fingerprint, the amount and currency, the restaurant's name).
 *
 * Money is integer minor units with an ISO currency. No card number, CVV, UPI PIN or bank credential has a column
 * here or anywhere else; a provider's raw webhook payload is not stored — only the fields needed to reconcile.
 */
return new class extends Migration
{
    private const STATUSES = "'CREATED', 'PROVIDER_READY', 'PROCESSING', 'PENDING', 'AUTHORIZED', 'VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED', 'UNKNOWN', 'REVIEW_REQUIRED'";

    private const OPEN = "'CREATED', 'PROVIDER_READY', 'PROCESSING', 'PENDING', 'AUTHORIZED', 'UNKNOWN'";

    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();

            // What is being paid. The foreign keys may go; the public id, version and fingerprint stay.
            $table->foreignId('checkout_session_id')->nullable()->constrained('checkout_sessions')->nullOnDelete();
            $table->uuid('checkout_public_id');
            $table->unsignedInteger('checkout_version');
            $table->char('pricing_fingerprint', 64);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->uuid('customer_public_id');
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->nullOnDelete();
            $table->string('restaurant_name', 160);
            $table->unsignedSmallInteger('attempt_number');

            // The amount is the checkout's total at the moment the attempt was created and never changes.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            // The provider and its references. Neutral names: no provider's vocabulary is a business concept.
            $table->string('provider', 24);
            $table->string('provider_mode', 8);
            $table->string('provider_receipt', 40)->unique();
            $table->string('provider_order_reference', 64)->nullable();
            $table->string('provider_payment_reference', 64)->nullable();
            $table->string('provider_order_status', 24)->nullable();
            $table->string('provider_payment_status', 24)->nullable();

            $table->string('status', 20)->default('CREATED');
            $table->string('status_reason', 48)->nullable();

            // Why it did not succeed — a normalized category for the customer and the provider's own code for support.
            $table->string('failure_category', 32)->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->string('provider_error_code', 64)->nullable();
            $table->string('provider_error_reason', 64)->nullable();

            // How the customer meant to pay (context from checkout) and how they did (from the provider; safe fields only).
            $table->string('requested_method_type', 16)->nullable();
            $table->string('method_type', 16)->nullable();
            $table->jsonb('method_summary')->nullable();

            // What the client told us. A signal to verify, never proof.
            $table->string('client_outcome', 12)->nullable();
            $table->timestampTz('client_reported_at')->nullable();
            $table->timestampTz('signature_verified_at')->nullable();
            $table->unsignedSmallInteger('signature_failures')->default(0);

            // Fulfilment is not payment: a VERIFIED payment may have no order yet, or one that cannot be created as is.
            $table->string('fulfillment_status', 20)->default('NOT_APPLICABLE');
            $table->string('fulfillment_reason', 48)->nullable();
            $table->timestampTz('fulfillment_changed_at')->nullable();

            $table->unsignedSmallInteger('reconcile_runs')->default(0);
            $table->timestampTz('last_reconciled_at')->nullable();
            $table->timestampTz('next_reconcile_at')->nullable();

            $table->string('preparation_key', 48)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('provider_ready_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['checkout_public_id', 'attempt_number']);
            $table->index('checkout_session_id');
            $table->index(['customer_id', 'created_at']);
            $table->index(['market_id', 'created_at']);
            $table->index('location_id');
            $table->index(['status', 'next_reconcile_at']);
            $table->index(['fulfillment_status', 'verified_at']);
            $table->index('created_at');
            $table->index('verified_at');
        });
        DB::statement('alter table payment_attempts add constraint payment_attempts_status_check check (status in ('.self::STATUSES.'))');
        DB::statement("alter table payment_attempts add constraint payment_attempts_fulfillment_check check (fulfillment_status in ('NOT_APPLICABLE', 'ORDER_PENDING', 'RECOVERY_REQUIRED', 'ORDER_CREATED'))");
        DB::statement("alter table payment_attempts add constraint payment_attempts_mode_check check (provider_mode in ('TEST', 'LIVE', 'FAKE'))");
        DB::statement("alter table payment_attempts add constraint payment_attempts_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table payment_attempts add constraint payment_attempts_amount_check check (amount_minor > 0)');
        DB::statement('alter table payment_attempts add constraint payment_attempts_number_check check (attempt_number >= 1)');
        // VERIFIED means a provider payment is known and the moment is recorded; and only a VERIFIED payment has a fulfilment state.
        DB::statement("alter table payment_attempts add constraint payment_attempts_verified_check check (status <> 'VERIFIED' or (provider_payment_reference is not null and provider_order_reference is not null and verified_at is not null))");
        DB::statement("alter table payment_attempts add constraint payment_attempts_fulfillment_state_check check ((status = 'VERIFIED') = (fulfillment_status <> 'NOT_APPLICABLE'))");
        // One open attempt and one verified payment per checkout — whatever two devices or two workers do at the same moment.
        DB::statement('create unique index payment_attempts_one_open_per_checkout on payment_attempts (checkout_public_id) where status in ('.self::OPEN.')');
        DB::statement("create unique index payment_attempts_one_verified_per_checkout on payment_attempts (checkout_public_id) where status = 'VERIFIED'");
        // A provider transaction belongs to exactly one attempt, and so does a provider order.
        DB::statement('create unique index payment_attempts_provider_payment_unique on payment_attempts (provider, provider_payment_reference) where provider_payment_reference is not null');
        DB::statement('create unique index payment_attempts_provider_order_unique on payment_attempts (provider, provider_order_reference) where provider_order_reference is not null');

        Schema::create('payment_attempt_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained('payment_attempts')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('source', 12);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->jsonb('detail')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['payment_attempt_id', 'id']);
        });
        DB::statement("alter table payment_attempt_events add constraint payment_attempt_events_source_check check (source in ('SYSTEM', 'CLIENT', 'PROVIDER', 'WEBHOOK', 'JOB', 'ADMIN'))");

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('provider', 24);
            // The provider's own event id when it sends one; otherwise "sha256:<hash of the body>".
            $table->string('provider_event_reference', 120);
            $table->string('event_type', 64);
            $table->string('normalized_type', 32)->nullable();
            $table->char('payload_hash', 64);
            $table->string('provider_order_reference', 64)->nullable();
            $table->string('provider_payment_reference', 64)->nullable();
            // The fields needed to reconcile and to support a customer — never the raw payload.
            $table->jsonb('summary')->nullable();
            $table->string('processing_status', 12)->default('RECEIVED');
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->unsignedSmallInteger('deliveries')->default(1);
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->string('outcome', 48)->nullable();
            $table->string('failure_reason', 160)->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_event_reference']);
            $table->index(['processing_status', 'received_at']);
            $table->index('provider_order_reference');
            $table->index('provider_payment_reference');
            $table->index('payment_attempt_id');
        });
        DB::statement("alter table payment_webhook_events add constraint payment_webhook_events_status_check check (processing_status in ('RECEIVED', 'PROCESSED', 'IGNORED', 'FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_attempt_events');
        Schema::dropIfExists('payment_attempts');
    }
};
