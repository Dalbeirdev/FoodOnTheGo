<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 35 — refunds, the cancellation's financial side and the dispute foundation.
 *
 *  - refunds: money that goes back to a customer, as a record of its own. A refund belongs to ONE verified payment
 *    attempt (always) and to the order that payment became (when there is one). It never rewrites the payment or
 *    the order: the payment stays VERIFIED, the order keeps its state and its prices. Replaces Module 31's
 *    order_refunds foundation (its rows are carried over and the table is dropped).
 *  - payment_refund_balances: one row per payment that has (or had) a refund — what was paid, what is reserved by
 *    refunds that are not settled, what the provider confirmed as refunded and what a lost dispute took. The row is
 *    locked for every refund change and the database itself refuses reserved + refunded > paid: an over-refund is
 *    impossible whatever the code is asked, however many requests arrive at once.
 *  - refund_attempts: every question asked of the provider for a refund (create, look up), with its idempotency key
 *    and what came back. Rows are added and completed once, never removed.
 *  - refund_events: the refund's own timeline — every change of state with who or what caused it. Append-only.
 *  - payment_disputes: what the provider told us about a dispute / chargeback on a payment. Read-only for people:
 *    only a verified provider event writes here. A dispute is NOT a refund.
 *
 * Money is integer minor units with an ISO currency. No card number, CVV, UPI PIN, bank credential or raw provider
 * payload has a column here.
 */
return new class extends Migration
{
    private const STATUSES = "'REQUIRED', 'REQUESTED', 'APPROVED', 'SUBMITTING', 'SUBMISSION_UNKNOWN', 'PROCESSING', 'REFUNDED', 'FAILED', 'CANCELLED', 'REVIEW_REQUIRED'";

    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();

            // What is refunded. The payment can never be deleted while a refund points at it.
            $table->foreignId('payment_attempt_id')->constrained('payment_attempts')->restrictOnDelete();
            $table->uuid('payment_public_id');
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('order_number', 24)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->uuid('customer_public_id')->nullable();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->nullOnDelete();
            $table->string('restaurant_name', 160)->nullable();

            // The provider's side, in provider-neutral columns.
            $table->string('provider', 24);
            $table->string('provider_mode', 8)->nullable();
            $table->string('provider_payment_reference', 64)->nullable();
            $table->string('provider_refund_reference', 64)->nullable();
            $table->string('provider_status', 24)->nullable();
            // Our own reference of ONE provider refund: receipt and idempotency key. A retry after a definitive failure
            // takes a new one (the next generation); a retry after an unknown outcome reuses it.
            $table->string('provider_receipt', 40)->nullable()->unique();
            $table->string('provider_trace_reference', 64)->nullable();

            $table->string('type', 8);
            $table->unsignedBigInteger('requested_amount_minor');
            $table->unsignedBigInteger('approved_amount_minor')->nullable();
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(1);

            $table->string('reason_code', 32);
            $table->string('reason_detail', 48)->nullable();
            $table->string('reason_note', 500)->nullable();
            // What asked for it, when a system condition did: the same condition never creates a second refund.
            $table->string('request_key', 120)->nullable()->unique();
            $table->string('requested_by_type', 16);
            $table->uuid('requested_by_public_id')->nullable();

            // Who asked is not who approved.
            $table->string('policy_decision', 16)->nullable();
            $table->string('policy_reason', 64)->nullable();
            $table->string('approval_mode', 8)->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('approval_note', 500)->nullable();
            $table->foreignId('closed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('closed_note', 500)->nullable();

            $table->string('failure_category', 32)->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->string('failure_message', 200)->nullable();
            $table->boolean('retryable')->default(false);
            $table->string('review_reason', 48)->nullable();

            $table->unsignedSmallInteger('generation')->default(0);
            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->unsignedSmallInteger('unknown_resubmits')->default(0);
            $table->unsignedSmallInteger('reconcile_runs')->default(0);
            $table->timestampTz('next_action_at')->nullable();
            $table->timestampTz('last_reconciled_at')->nullable();

            $table->timestampTz('requested_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index('payment_attempt_id');
            $table->index('order_id');
            $table->index(['customer_id', 'requested_at']);
            $table->index(['status', 'next_action_at']);
            $table->index(['status', 'requested_at']);
            $table->index(['market_id', 'requested_at']);
            $table->index(['location_id', 'requested_at']);
            $table->index('completed_at');
            $table->index('reason_code');
            $table->index('payment_public_id');
            $table->index('order_number');
        });
        DB::statement('alter table refunds add constraint refunds_status_check check (status in ('.self::STATUSES.'))');
        DB::statement("alter table refunds add constraint refunds_type_check check (type in ('FULL', 'PARTIAL'))");
        DB::statement('alter table refunds add constraint refunds_amount_check check (requested_amount_minor > 0 and (approved_amount_minor is null or (approved_amount_minor > 0 and approved_amount_minor <= requested_amount_minor)))');
        DB::statement("alter table refunds add constraint refunds_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table refunds add constraint refunds_version_check check (version >= 1)');
        // REFUNDED is the provider's word: it has the provider's refund reference and the moment it was confirmed.
        DB::statement("alter table refunds add constraint refunds_refunded_check check (status <> 'REFUNDED' or (provider_refund_reference is not null and completed_at is not null and approved_amount_minor is not null))");
        // Nothing is sent to a provider that was not approved.
        DB::statement("alter table refunds add constraint refunds_approved_check check (status not in ('SUBMITTING', 'SUBMISSION_UNKNOWN', 'PROCESSING', 'REFUNDED') or (approved_amount_minor is not null and approved_at is not null))");
        DB::statement("alter table refunds add constraint refunds_requester_check check (requested_by_type in ('SYSTEM', 'CUSTOMER', 'RESTAURANT_USER', 'ADMIN'))");
        // One provider refund is one refund here: nobody can attach the same provider refund to two records.
        DB::statement('create unique index refunds_provider_reference_unique on refunds (provider, provider_refund_reference) where provider_refund_reference is not null');

        Schema::create('payment_refund_balances', function (Blueprint $table): void {
            $table->foreignId('payment_attempt_id')->primary()->constrained('payment_attempts')->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('paid_minor');
            // Held by refunds that are asked for, approved, on their way or not understood yet.
            $table->unsignedBigInteger('reserved_minor')->default(0);
            // Confirmed by the provider.
            $table->unsignedBigInteger('refunded_minor')->default(0);
            // Taken by a lost dispute (the provider's deduction). It lowers what may still be refunded.
            $table->unsignedBigInteger('charged_back_minor')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::statement('alter table payment_refund_balances add constraint payment_refund_balances_limit_check check (reserved_minor + refunded_minor <= paid_minor)');
        DB::statement("alter table payment_refund_balances add constraint payment_refund_balances_currency_check check (currency ~ '^[A-Z]{3}$')");

        Schema::create('refund_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->restrictOnDelete();
            $table->unsignedSmallInteger('generation')->default(0);
            $table->string('operation', 12);
            $table->string('idempotency_key', 40)->nullable();
            $table->string('source', 16);
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('outcome', 16)->default('STARTED');
            $table->string('provider_status', 24)->nullable();
            $table->string('provider_refund_reference', 64)->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['refund_id', 'id']);
        });
        DB::statement("alter table refund_attempts add constraint refund_attempts_operation_check check (operation in ('CREATE', 'LOOKUP', 'LIST'))");
        DB::statement("alter table refund_attempts add constraint refund_attempts_outcome_check check (outcome in ('STARTED', 'PROCESSED', 'PENDING', 'FAILED', 'REJECTED', 'UNKNOWN', 'NOT_SENT', 'NOT_FOUND', 'UNAVAILABLE', 'MISMATCH'))");

        Schema::create('refund_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('source', 16);
            $table->uuid('actor_public_id')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->jsonb('detail')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['refund_id', 'id']);
        });

        Schema::create('payment_disputes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_attempt_id')->constrained('payment_attempts')->restrictOnDelete();
            $table->uuid('payment_public_id');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('order_number', 24)->nullable();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->string('restaurant_name', 160)->nullable();
            $table->string('provider', 24);
            $table->string('provider_dispute_reference', 64);
            $table->string('provider_payment_reference', 64)->nullable();
            $table->string('status', 16);
            $table->string('provider_status', 24)->nullable();
            $table->string('phase', 24)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('amount_deducted_minor')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('respond_by_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['provider', 'provider_dispute_reference']);
            $table->index(['payment_attempt_id', 'status']);
            $table->index(['status', 'respond_by_at']);
            $table->index(['market_id', 'created_at']);
        });
        DB::statement("alter table payment_disputes add constraint payment_disputes_status_check check (status in ('OPEN', 'ACTION_REQUIRED', 'UNDER_REVIEW', 'WON', 'LOST', 'CLOSED', 'UNKNOWN'))");
        DB::statement("alter table payment_disputes add constraint payment_disputes_currency_check check (currency ~ '^[A-Z]{3}$')");

        // What a provider event was about, for the refund's and the dispute's webhook history. Never the payload.
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->string('provider_refund_reference', 64)->nullable();
            $table->string('provider_dispute_reference', 64)->nullable();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->index('provider_refund_reference');
            $table->index('refund_id');
        });

        // The order's refund status is derived from its refunds; the names follow the refund domain.
        DB::statement('alter table orders drop constraint orders_refund_check');
        DB::statement('alter table orders alter column refund_status type varchar(20)');
        DB::statement("update orders set refund_status = 'PROCESSING' where refund_status = 'PENDING'");
        DB::statement("update orders set refund_status = 'PARTIALLY_REFUNDED' where refund_status = 'PARTIAL'");
        DB::statement("alter table orders add constraint orders_refund_check check (refund_status in ('NONE', 'REQUIRED', 'PROCESSING', 'PARTIALLY_REFUNDED', 'REFUNDED', 'FAILED', 'REVIEW_REQUIRED'))");

        // Who ended an order, and why in a controlled word (the free text stays in cancellation_reason).
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('cancelled_by_type', 16)->nullable();
            $table->string('cancellation_reason_code', 40)->nullable();
        });
        DB::statement("update orders set cancelled_by_type = 'ADMIN', cancellation_reason_code = 'ADMIN_CANCELLATION' where status = 'CANCELLED'");
        DB::statement("alter table orders add constraint orders_cancelled_by_check check (cancelled_by_type is null or cancelled_by_type in ('CUSTOMER', 'RESTAURANT_USER', 'ADMIN', 'SYSTEM'))");

        $this->carryOver();
        Schema::dropIfExists('order_refunds');
    }

    public function down(): void
    {
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
        DB::statement("create unique index order_refunds_one_open_per_order on order_refunds (order_id) where status in ('REQUIRED', 'PENDING')");

        DB::statement('alter table orders drop constraint orders_cancelled_by_check');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_by_type', 'cancellation_reason_code']);
        });
        DB::statement('alter table orders drop constraint orders_refund_check');
        DB::statement("update orders set refund_status = 'PENDING' where refund_status in ('PROCESSING', 'REVIEW_REQUIRED')");
        DB::statement("update orders set refund_status = 'PARTIAL' where refund_status = 'PARTIALLY_REFUNDED'");
        DB::statement('alter table orders alter column refund_status type varchar(12)');
        DB::statement("alter table orders add constraint orders_refund_check check (refund_status in ('NONE', 'REQUIRED', 'PENDING', 'PARTIAL', 'REFUNDED', 'FAILED'))");

        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refund_id');
            $table->dropIndex(['provider_refund_reference']);
            $table->dropColumn(['provider_refund_reference', 'provider_dispute_reference']);
        });
        Schema::dropIfExists('payment_disputes');
        Schema::dropIfExists('refund_events');
        Schema::dropIfExists('refund_attempts');
        Schema::dropIfExists('payment_refund_balances');
        Schema::dropIfExists('refunds');
    }

    /**
     * Module 31's refund requirements become refunds. A requirement that was never sent to a provider is REQUIRED
     * here as well; anything else Module 31 could not have written by itself and is handed to a person.
     */
    private function carryOver(): void
    {
        DB::statement("
            insert into refunds (public_id, payment_attempt_id, payment_public_id, order_id, order_number, customer_id, customer_public_id, market_id, location_id, restaurant_name,
                provider, provider_mode, provider_payment_reference, type, requested_amount_minor, currency, status, reason_code, reason_detail, request_key,
                requested_by_type, requested_by_public_id, review_reason, requested_at, created_at, updated_at)
            select r.public_id, p.id, p.public_id, o.id, o.order_number, o.customer_id, p.customer_public_id, o.market_id, o.location_id, p.restaurant_name,
                p.provider, p.provider_mode, p.provider_payment_reference, 'FULL', r.amount_minor, r.currency,
                case when r.status = 'REQUIRED' then 'REQUIRED' else 'REVIEW_REQUIRED' end,
                case when r.reason like 'RESTAURANT_REJECTED%' then 'RESTAURANT_REJECTED' else 'ADMIN_CANCELLATION' end,
                case when r.reason like 'RESTAURANT_REJECTED:%' then substr(r.reason, 21, 48) else null end,
                'order:' || o.public_id || ':closeout',
                case when r.requested_by_type in ('SYSTEM', 'CUSTOMER', 'RESTAURANT_USER', 'ADMIN') then r.requested_by_type else 'SYSTEM' end,
                r.requested_by_public_id,
                case when r.status = 'REQUIRED' then null else 'CARRIED_OVER' end,
                r.requested_at, r.created_at, r.updated_at
            from order_refunds r
            join orders o on o.id = r.order_id
            join payment_attempts p on p.id = coalesce(r.payment_attempt_id, o.payment_attempt_id)
        ");
        DB::statement('
            insert into payment_refund_balances (payment_attempt_id, currency, paid_minor, reserved_minor, created_at, updated_at)
            select p.id, p.currency, p.amount_minor, least(p.amount_minor, sum(f.requested_amount_minor)), now(), now()
            from refunds f join payment_attempts p on p.id = f.payment_attempt_id
            group by p.id, p.currency, p.amount_minor
        ');
        DB::statement("update orders set refund_status = 'REVIEW_REQUIRED' where id in (select order_id from refunds where status = 'REVIEW_REQUIRED')");
    }
};
