<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 36 — the platform's financial side: ledger, commission, restaurant earnings, settlements, payouts and
 * reconciliation.
 *
 *  - ledger_accounts / ledger_transactions / ledger_entries: a double-entry ledger. One transaction is one financial
 *    event (a verified payment, a recognized order, a completed refund, a payout, a provider fee, an adjustment) with
 *    a posting key that makes it happen once. Its entries debit and credit accounts in ONE currency and must balance:
 *    the database itself refuses a transaction whose debits and credits differ, and refuses every UPDATE and DELETE
 *    on a posted transaction or entry. A correction is a new transaction.
 *  - commission_plans: the commercial terms — a percentage in basis points with an explicit base, for a market, an
 *    organization or a location, with the dates it applies. Never edited: a new plan follows the old one.
 *  - order_financial_allocations: what each order's money is made of, frozen when the order is created with the
 *    terms of that moment. Never recalculated from menus, promotions or plans as they are today.
 *  - restaurant_payable_items: the restaurant's sub-ledger — one line per earning or adjustment, settled exactly once.
 *  - financial_holds, financial_adjustments: explicit holds and the decisions that move money between the restaurant
 *    and the platform (who bears a refund, a dispute, a manual correction).
 *  - restaurant_settlements / restaurant_settlement_lines: what the platform owes a restaurant for a period, line by
 *    line. A settlement is a calculation — NOT money sent.
 *  - restaurant_payouts / restaurant_payout_profiles / payout_webhook_events: the disbursement of one settlement.
 *    PAID only on trusted evidence. No bank account number, IFSC, UPI id or KYC document has a column here: a profile
 *    is a provider's token and a mask.
 *  - provider_settlements / provider_settlement_items: what the PAYMENT PROVIDER says it settled to the platform —
 *    a different thing from a restaurant settlement. Stored as the provider reported it.
 *  - reconciliation_differences: every place where two records that should agree do not. Visible until resolved.
 *  - finance_runs: each run of a finance job (integrity check, reconciliation, import, settlement generation,
 *    backfill) with what it found.
 *
 * Money is integer minor units with an ISO currency. No commission rate, tax rate or fee formula is seeded.
 */
return new class extends Migration
{
    private const ACCOUNT_CODES = "'PAYMENT_CLEARING', 'PROVIDER_CLEARING', 'RESTAURANT_PAYABLE', 'PLATFORM_COMMISSION_REVENUE', 'PLATFORM_FEE_REVENUE', 'PROMOTION_EXPENSE', 'TAX_LIABILITY', 'REFUND_CLEARING', 'PLATFORM_REFUND_EXPENSE', 'PROVIDER_FEE_EXPENSE', 'PROVIDER_TAX_EXPENSE', 'DISPUTE_CLEARING', 'PLATFORM_DISPUTE_EXPENSE', 'PAYOUT_CLEARING', 'PLATFORM_BANK', 'PLATFORM_ADJUSTMENTS'";

    private const TRANSACTION_TYPES = "'PAYMENT_VERIFIED', 'ORDER_RECOGNIZED', 'REFUND_COMPLETED', 'REFUND_LIABILITY', 'DISPUTE_LOST', 'DISPUTE_LIABILITY', 'PAYOUT_PAID', 'PAYOUT_REVERSED', 'PROVIDER_FEE', 'PROVIDER_SETTLEMENT_RECEIVED', 'MANUAL_ADJUSTMENT', 'REVERSAL'";

    public function up(): void
    {
        /* ------------------------------------------------------------------ ledger */

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 40);
            // Which side grows the account: assets and expenses are debit accounts, liabilities and revenue credit ones.
            $table->char('normal_side', 1);
            $table->char('currency', 3);
            // PLATFORM (one per currency), LOCATION (a restaurant location's payable) or PROVIDER (a provider's clearing).
            $table->string('owner_type', 12);
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->restrictOnDelete();
            $table->string('provider', 24)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'currency']);
            $table->index(['code', 'currency']);
            $table->index('organization_id');
        });
        DB::statement('alter table ledger_accounts add constraint ledger_accounts_code_check check (code in ('.self::ACCOUNT_CODES.'))');
        DB::statement("alter table ledger_accounts add constraint ledger_accounts_side_check check (normal_side in ('D', 'C'))");
        DB::statement("alter table ledger_accounts add constraint ledger_accounts_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement("alter table ledger_accounts add constraint ledger_accounts_owner_check check ((owner_type = 'PLATFORM' and location_id is null and provider is null) or (owner_type = 'LOCATION' and location_id is not null and organization_id is not null and provider is null) or (owner_type = 'PROVIDER' and provider is not null and location_id is null))");
        // One account per code, currency and owner: a balance is never split over two rows and never mixes currencies.
        DB::statement("create unique index ledger_accounts_identity_unique on ledger_accounts (code, currency, owner_type, coalesce(location_id, 0), coalesce(provider, ''))");

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // The business event, once: "payment:{id}:verified", "order:{id}:recognized", "refund:{id}:completed" …
            $table->string('posting_key', 160)->unique();
            $table->string('type', 32);
            // The record the event belongs to. Every posting has one: there is no unexplained entry.
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->uuid('source_public_id')->nullable();
            $table->string('source_reference', 80)->nullable();
            $table->char('currency', 3);
            // The sum of the debits (= the sum of the credits).
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->nullOnDelete();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('memo', 500)->nullable();
            $table->jsonb('detail')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('posted_at');

            $table->unique(['id', 'currency']);
            $table->index(['source_type', 'source_id', 'type']);
            $table->index(['type', 'occurred_at']);
            $table->index(['location_id', 'occurred_at']);
            $table->index(['market_id', 'occurred_at']);
            $table->index('occurred_at');
        });
        DB::statement('alter table ledger_transactions add constraint ledger_transactions_type_check check (type in ('.self::TRANSACTION_TYPES.'))');
        DB::statement("alter table ledger_transactions add constraint ledger_transactions_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table ledger_transactions add constraint ledger_transactions_amount_check check (amount_minor > 0)');
        DB::statement('create unique index ledger_transactions_one_reversal on ledger_transactions (reverses_transaction_id) where reverses_transaction_id is not null');

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ledger_transaction_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedSmallInteger('line_no');
            $table->char('direction', 1);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('description_code', 48);
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->unique(['ledger_transaction_id', 'line_no']);
            $table->index(['account_id', 'occurred_at']);
            $table->index(['location_id', 'occurred_at']);
            $table->index(['market_id', 'occurred_at']);
        });
        DB::statement("alter table ledger_entries add constraint ledger_entries_direction_check check (direction in ('D', 'C'))");
        DB::statement('alter table ledger_entries add constraint ledger_entries_amount_check check (amount_minor > 0)');
        // An entry is in the currency of its transaction AND of its account: currencies cannot meet in one transaction
        // or in one balance, whatever the code is asked.
        DB::statement('alter table ledger_entries add constraint ledger_entries_transaction_fk foreign key (ledger_transaction_id, currency) references ledger_transactions (id, currency) on delete restrict');
        DB::statement('alter table ledger_entries add constraint ledger_entries_account_fk foreign key (account_id, currency) references ledger_accounts (id, currency) on delete restrict');

        // Posted is posted: no UPDATE and no DELETE, for the application and for a person with a database console alike.
        DB::unprepared(<<<'SQL'
            create or replace function ledger_refuse_change() returns trigger language plpgsql as $$
            begin
                raise exception 'ledger rows are immutable: % on % is refused (post a reversal or an adjustment instead)', tg_op, tg_table_name
                    using errcode = 'restrict_violation';
            end;
            $$;
            create trigger ledger_transactions_immutable before update or delete on ledger_transactions
                for each row execute function ledger_refuse_change();
            create trigger ledger_entries_immutable before update or delete on ledger_entries
                for each row execute function ledger_refuse_change();

            create or replace function ledger_assert_balanced() returns trigger language plpgsql as $$
            declare
                difference bigint;
                lines integer;
            begin
                select coalesce(sum(case direction when 'D' then amount_minor else -amount_minor end), 0), count(*)
                    into difference, lines
                    from ledger_entries where ledger_transaction_id = new.ledger_transaction_id;
                if difference <> 0 or lines < 2 then
                    raise exception 'ledger transaction % does not balance (debits - credits = %, % lines)', new.ledger_transaction_id, difference, lines
                        using errcode = 'check_violation';
                end if;
                return null;
            end;
            $$;
            -- Checked when the posting is complete (the posting service makes it immediate before it commits), and in
            -- any case no later than the commit: an unbalanced transaction cannot be committed.
            create constraint trigger ledger_entries_balanced after insert on ledger_entries
                deferrable initially deferred for each row execute function ledger_assert_balanced();

            create or replace function ledger_assert_has_entries() returns trigger language plpgsql as $$
            begin
                if not exists (select 1 from ledger_entries where ledger_transaction_id = new.id) then
                    raise exception 'ledger transaction % has no entries', new.id using errcode = 'check_violation';
                end if;
                return null;
            end;
            $$;
            create constraint trigger ledger_transactions_has_entries after insert on ledger_transactions
                deferrable initially deferred for each row execute function ledger_assert_has_entries();
            SQL);

        /* ------------------------------------------------------------------ commercial terms */

        Schema::create('commission_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            // MARKET (the default of a market), ORGANIZATION or LOCATION (negotiated terms): the most specific one wins.
            $table->string('scope_type', 12);
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->restrictOnDelete();
            $table->string('type', 12)->default('PERCENTAGE');
            // 1 % = 100 basis points. Exact integer arithmetic; no float ever holds a rate.
            $table->unsignedInteger('rate_bps');
            $table->string('base_policy', 48);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            // TEST = a development fixture, never a production term. APPROVED needs the reference of the approval.
            $table->string('approval', 12)->default('TEST');
            $table->string('approval_reference', 160)->nullable();
            $table->string('source', 12)->default('ADMIN');
            $table->string('reason', 500);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('supersedes_plan_id')->nullable()->constrained('commission_plans')->nullOnDelete();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('withdrawn_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['market_id', 'scope_type', 'effective_from']);
            $table->index(['organization_id', 'effective_from']);
            $table->index(['location_id', 'effective_from']);
        });
        DB::statement("alter table commission_plans add constraint commission_plans_scope_check check ((scope_type = 'MARKET' and organization_id is null and location_id is null) or (scope_type = 'ORGANIZATION' and organization_id is not null and location_id is null) or (scope_type = 'LOCATION' and organization_id is not null and location_id is not null))");
        DB::statement("alter table commission_plans add constraint commission_plans_type_check check (type in ('PERCENTAGE'))");
        DB::statement('alter table commission_plans add constraint commission_plans_rate_check check (rate_bps between 0 and 10000)');
        DB::statement("alter table commission_plans add constraint commission_plans_base_check check (base_policy in ('MERCHANDISE_GROSS', 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 'MERCHANDISE_NET_OF_ALL_DISCOUNTS'))");
        DB::statement('alter table commission_plans add constraint commission_plans_dates_check check (effective_until is null or effective_until > effective_from)');
        DB::statement("alter table commission_plans add constraint commission_plans_approval_check check (approval in ('TEST', 'APPROVED') and (approval <> 'APPROVED' or approval_reference is not null))");
        DB::statement("alter table commission_plans add constraint commission_plans_source_check check (source in ('ADMIN', 'FIXTURE'))");
        DB::statement('alter table commission_plans add constraint commission_plans_version_check check (version >= 1)');

        Schema::create('order_financial_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->uuid('order_public_id');
            $table->string('order_number', 24);
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->string('review_reason', 40)->nullable();

            // The order's money as the order recorded it (never recalculated).
            $table->unsignedBigInteger('customer_total_minor');
            $table->unsignedBigInteger('merchandise_gross_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('tax_included_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0);

            // Who pays for the discount — the promotion's funding source at the moment the allocation was made.
            $table->string('promotion_code', 40)->nullable();
            $table->string('promotion_funding', 12)->default('NONE');
            $table->string('promotion_funding_basis', 24)->default('NONE');
            $table->unsignedBigInteger('restaurant_discount_minor')->default(0);
            $table->unsignedBigInteger('platform_discount_minor')->default(0);

            // Who the tax and the customer fees belong to (the policy in force when the allocation was made).
            $table->string('tax_owner', 12)->default('UNASSIGNED');
            $table->unsignedBigInteger('tax_restaurant_minor')->default(0);
            $table->unsignedBigInteger('tax_held_minor')->default(0);
            $table->string('fee_owner', 12)->default('PLATFORM');
            $table->unsignedBigInteger('fee_restaurant_minor')->default(0);
            $table->unsignedBigInteger('fee_platform_minor')->default(0);

            // The commission terms applied, frozen: a later plan never changes these.
            $table->foreignId('commission_plan_id')->nullable()->constrained('commission_plans')->restrictOnDelete();
            $table->uuid('commission_plan_public_id')->nullable();
            $table->unsignedInteger('commission_plan_version')->nullable();
            $table->string('commission_scope', 12)->nullable();
            $table->string('commission_source', 16)->nullable();
            $table->unsignedInteger('commission_rate_bps')->nullable();
            $table->string('commission_base_policy', 48)->nullable();
            $table->unsignedBigInteger('commission_base_minor')->nullable();
            $table->unsignedBigInteger('commission_minor')->nullable();

            // What follows from the lines above. Signed: an unusual order can cost a restaurant more than it earns.
            $table->bigInteger('restaurant_net_minor')->nullable();
            $table->bigInteger('platform_net_minor')->nullable();

            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->unsignedBigInteger('ledger_transaction_id')->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('recognized_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['location_id', 'status', 'placed_at']);
            $table->index(['location_id', 'recognized_at']);
            $table->index(['market_id', 'recognized_at']);
            $table->index(['organization_id', 'recognized_at']);
            $table->index(['status', 'placed_at']);
            $table->index('commission_plan_id');
            $table->index('order_number');
        });
        DB::statement("alter table order_financial_allocations add constraint ofa_status_check check (status in ('PENDING', 'RECOGNIZED', 'VOID', 'REVIEW_REQUIRED'))");
        DB::statement("alter table order_financial_allocations add constraint ofa_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement("alter table order_financial_allocations add constraint ofa_funding_check check (promotion_funding in ('NONE', 'PLATFORM', 'RESTAURANT', 'SHARED', 'UNKNOWN'))");
        DB::statement("alter table order_financial_allocations add constraint ofa_owner_check check (tax_owner in ('UNASSIGNED', 'PLATFORM', 'RESTAURANT') and fee_owner in ('PLATFORM', 'RESTAURANT'))");
        // The order's own arithmetic, and every split adds up to what it splits.
        DB::statement('alter table order_financial_allocations add constraint ofa_total_check check (customer_total_minor = merchandise_gross_minor - discount_minor + tax_minor + fee_minor)');
        DB::statement('alter table order_financial_allocations add constraint ofa_tax_split_check check (tax_restaurant_minor + tax_held_minor = tax_minor)');
        DB::statement('alter table order_financial_allocations add constraint ofa_fee_split_check check (fee_restaurant_minor + fee_platform_minor = fee_minor)');
        // An allocation that may be recognized has its discount split and its commission; one that waits for a person may not.
        DB::statement("alter table order_financial_allocations add constraint ofa_complete_check check (status = 'REVIEW_REQUIRED' or status = 'VOID' or (commission_minor is not null and commission_base_minor is not null and commission_rate_bps is not null and restaurant_net_minor is not null and platform_net_minor is not null and restaurant_discount_minor + platform_discount_minor = discount_minor))");
        DB::statement('alter table order_financial_allocations add constraint ofa_net_check check (restaurant_net_minor is null or (restaurant_net_minor = merchandise_gross_minor - restaurant_discount_minor - commission_minor + tax_restaurant_minor + fee_restaurant_minor and platform_net_minor = commission_minor + fee_platform_minor - platform_discount_minor))');
        DB::statement("alter table order_financial_allocations add constraint ofa_recognized_check check ((status = 'RECOGNIZED') = (recognized_at is not null and ledger_transaction_id is not null))");
        DB::statement('alter table order_financial_allocations add constraint ofa_ledger_fk foreign key (ledger_transaction_id) references ledger_transactions (id) on delete restrict');

        /* ------------------------------------------------------------------ settlements (declared before the lines that point at them) */

        Schema::create('restaurant_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('reference', 24)->unique();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->string('restaurant_name', 160);
            $table->char('currency', 3);
            $table->string('cadence', 12);
            $table->string('timezone', 64);
            // [period_start, period_end) in UTC; the end is the cutoff: nothing that became available after it is inside.
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->string('status', 16);
            $table->string('review_reason', 40)->nullable();

            // The totals are the sums of the lines — checked when the settlement is approved, never typed in.
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->bigInteger('gross_sales_minor')->default(0);
            $table->bigInteger('restaurant_discount_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('tax_passed_minor')->default(0);
            $table->bigInteger('fee_passed_minor')->default(0);
            $table->bigInteger('refund_adjustments_minor')->default(0);
            $table->bigInteger('other_adjustments_minor')->default(0);
            $table->bigInteger('net_payable_minor');
            $table->bigInteger('paid_minor')->default(0);

            $table->string('generated_by_type', 12)->default('SYSTEM');
            $table->foreignId('generated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('approval_note', 500)->nullable();
            $table->foreignId('cancelled_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_reason', 500)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['location_id', 'period_end']);
            $table->index(['organization_id', 'period_end']);
            $table->index(['market_id', 'status', 'period_end']);
            $table->index(['status', 'generated_at']);
        });
        DB::statement("alter table restaurant_settlements add constraint restaurant_settlements_status_check check (status in ('CALCULATED', 'REVIEW_REQUIRED', 'APPROVED', 'PAYMENT_PENDING', 'PAID', 'FAILED', 'CANCELLED', 'CLOSED'))");
        DB::statement("alter table restaurant_settlements add constraint restaurant_settlements_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table restaurant_settlements add constraint restaurant_settlements_period_check check (period_end > period_start)');
        // A negative total is never a settlement: it is carried forward. A zero one is a closed statement, never paid.
        DB::statement('alter table restaurant_settlements add constraint restaurant_settlements_net_check check (net_payable_minor >= 0)');
        DB::statement("alter table restaurant_settlements add constraint restaurant_settlements_zero_check check ((net_payable_minor = 0) = (status in ('CLOSED')) or status = 'CANCELLED')");
        DB::statement('alter table restaurant_settlements add constraint restaurant_settlements_sum_check check (net_payable_minor = gross_sales_minor - restaurant_discount_minor - commission_minor + tax_passed_minor + fee_passed_minor + refund_adjustments_minor + other_adjustments_minor)');
        // PAID is evidence, not a decision: it has the moment and the whole amount.
        DB::statement("alter table restaurant_settlements add constraint restaurant_settlements_paid_check check ((status = 'PAID') = (paid_at is not null) and (status <> 'PAID' or paid_minor = net_payable_minor))");
        DB::statement("alter table restaurant_settlements add constraint restaurant_settlements_approved_check check (status not in ('APPROVED', 'PAYMENT_PENDING', 'PAID', 'FAILED') or (approved_at is not null and approved_by_admin_id is not null))");
        DB::statement('alter table restaurant_settlements add constraint restaurant_settlements_version_check check (version >= 1)');
        // No uniqueness per period: a line released from a hold after its period was settled is settled by a second
        // statement with the same cutoff. What cannot happen twice is a LINE (restaurant_payable_items.settlement_id).

        /* ------------------------------------------------------------------ adjustments, payable items, holds */

        Schema::create('financial_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // REFUND_LIABILITY / DISPUTE_LIABILITY: who bears money that went back to a customer. MANUAL: a correction.
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->foreignId('refund_id')->nullable()->unique()->constrained('refunds')->restrictOnDelete();
            $table->foreignId('dispute_id')->nullable()->unique()->constrained('payment_disputes')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('order_number', 24)->nullable();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->char('currency', 3);
            // The whole amount in question (a refund's amount, a dispute's deduction, a correction's size). Always positive.
            $table->unsignedBigInteger('amount_minor');
            // MANUAL only: CREDIT_RESTAURANT raises what the platform owes the restaurant, DEBIT_RESTAURANT lowers it.
            $table->string('direction', 20)->nullable();
            $table->string('liability', 12);
            $table->unsignedBigInteger('restaurant_share_minor')->nullable();
            $table->unsignedBigInteger('platform_share_minor')->nullable();
            $table->string('policy_source', 12)->nullable();
            $table->string('reason_code', 40);
            $table->string('reason', 500)->nullable();
            $table->string('supporting_reference', 160)->nullable();
            $table->string('request_key', 120)->nullable()->unique();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->unsignedBigInteger('ledger_transaction_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['market_id', 'status']);
            $table->index('order_id');
        });
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_kind_check check (kind in ('REFUND_LIABILITY', 'DISPUTE_LIABILITY', 'MANUAL'))");
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_status_check check (status in ('PENDING_DECISION', 'PENDING_APPROVAL', 'POSTED', 'CANCELLED'))");
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_liability_check check (liability in ('UNASSIGNED', 'RESTAURANT', 'PLATFORM', 'SHARED'))");
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_direction_check check ((kind = 'MANUAL') = (direction is not null) and (direction is null or direction in ('CREDIT_RESTAURANT', 'DEBIT_RESTAURANT')))");
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_source_check check ((kind = 'REFUND_LIABILITY') = (refund_id is not null) and (kind = 'DISPUTE_LIABILITY') = (dispute_id is not null))");
        DB::statement('alter table financial_adjustments add constraint financial_adjustments_amount_check check (amount_minor > 0)');
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_currency_check check (currency ~ '^[A-Z]{3}$')");
        // A posted adjustment has its split, its posting and — a manual one — its reason and supporting reference.
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_posted_check check (status <> 'POSTED' or (restaurant_share_minor is not null and platform_share_minor is not null and restaurant_share_minor + platform_share_minor = amount_minor and ledger_transaction_id is not null and posted_at is not null))");
        DB::statement("alter table financial_adjustments add constraint financial_adjustments_manual_check check (kind <> 'MANUAL' or (reason is not null and supporting_reference is not null and created_by_admin_id is not null))");
        DB::statement('alter table financial_adjustments add constraint financial_adjustments_ledger_fk foreign key (ledger_transaction_id) references ledger_transactions (id) on delete restrict');

        Schema::create('restaurant_payable_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // The event behind the line, once: "order:{id}:earning", "refund:{id}:restaurant-share", "adjustment:{id}".
            $table->string('source_key', 160)->unique();
            $table->string('type', 24);
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->char('currency', 3);
            // Signed: an earning is positive, a refund the restaurant bears is negative.
            $table->bigInteger('amount_minor');
            // What an ORDER_EARNING is made of (zero for the other types), so that a statement can explain its total.
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('restaurant_discount_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('tax_passed_minor')->default(0);
            $table->bigInteger('fee_passed_minor')->default(0);
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('order_number', 24)->nullable();
            $table->foreignId('allocation_id')->nullable()->constrained('order_financial_allocations')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->foreignId('adjustment_id')->nullable()->constrained('financial_adjustments')->restrictOnDelete();
            $table->unsignedBigInteger('ledger_transaction_id');
            $table->string('description_code', 48);
            $table->string('status', 12)->default('AVAILABLE');
            // The settlement that consumed the line. A line is settled once: this column and the row lock are the guard.
            $table->foreignId('settlement_id')->nullable()->constrained('restaurant_settlements')->restrictOnDelete();
            $table->timestampTz('available_at');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();

            $table->index(['location_id', 'currency', 'status', 'available_at']);
            $table->index(['organization_id', 'available_at']);
            $table->index(['market_id', 'available_at']);
            $table->index('settlement_id');
            $table->index('order_id');
            $table->index('refund_id');
        });
        DB::statement("alter table restaurant_payable_items add constraint restaurant_payable_items_type_check check (type in ('ORDER_EARNING', 'REFUND_ADJUSTMENT', 'DISPUTE_ADJUSTMENT', 'MANUAL_ADJUSTMENT'))");
        DB::statement("alter table restaurant_payable_items add constraint restaurant_payable_items_status_check check (status in ('AVAILABLE', 'SETTLED'))");
        DB::statement("alter table restaurant_payable_items add constraint restaurant_payable_items_settled_check check ((status = 'SETTLED') = (settlement_id is not null) and (status = 'SETTLED') = (settled_at is not null))");
        DB::statement("alter table restaurant_payable_items add constraint restaurant_payable_items_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement("alter table restaurant_payable_items add constraint restaurant_payable_items_parts_check check ((type = 'ORDER_EARNING' and amount_minor = gross_minor - restaurant_discount_minor - commission_minor + tax_passed_minor + fee_passed_minor) or (type <> 'ORDER_EARNING' and gross_minor = 0 and restaurant_discount_minor = 0 and commission_minor = 0 and tax_passed_minor = 0 and fee_passed_minor = 0))");
        DB::statement('alter table restaurant_payable_items add constraint restaurant_payable_items_ledger_fk foreign key (ledger_transaction_id) references ledger_transactions (id) on delete restrict');

        Schema::create('restaurant_settlement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained('restaurant_settlements')->restrictOnDelete();
            $table->foreignId('payable_item_id')->constrained('restaurant_payable_items')->restrictOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('type', 24);
            $table->string('description_code', 48);
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('order_number', 24)->nullable();
            $table->uuid('source_public_id')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('restaurant_discount_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('tax_passed_minor')->default(0);
            $table->bigInteger('fee_passed_minor')->default(0);
            $table->bigInteger('amount_minor');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->unique(['settlement_id', 'payable_item_id']);
            $table->unique(['settlement_id', 'line_no']);
            $table->index('payable_item_id');
            $table->index('order_id');
        });
        DB::statement("alter table restaurant_settlement_lines add constraint restaurant_settlement_lines_type_check check (type in ('ORDER_EARNING', 'REFUND_ADJUSTMENT', 'DISPUTE_ADJUSTMENT', 'MANUAL_ADJUSTMENT'))");
        // A statement's lines are written once, with the settlement: what was approved is what stays.
        DB::unprepared(<<<'SQL'
            create or replace function settlement_lines_refuse_change() returns trigger language plpgsql as $$
            begin
                raise exception 'settlement lines are immutable: % is refused (cancel the settlement and generate a new one instead)', tg_op
                    using errcode = 'restrict_violation';
            end;
            $$;
            create trigger restaurant_settlement_lines_immutable before update or delete on restaurant_settlement_lines
                for each row execute function settlement_lines_refuse_change();
            SQL);

        Schema::create('financial_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // LOCATION: nothing of the location is settled or paid. ITEM: one payable line is left out.
            $table->string('scope', 12);
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('payable_item_id')->nullable()->constrained('restaurant_payable_items')->restrictOnDelete();
            $table->string('reason', 24);
            $table->string('status', 12)->default('ACTIVE');
            $table->string('source', 12);
            // What a system hold watches, once: "refund:{id}", "dispute:{id}".
            $table->string('source_key', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('placed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('placed_at');
            $table->foreignId('released_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['location_id', 'status']);
            $table->index(['payable_item_id', 'status']);
            $table->index(['market_id', 'status']);
        });
        DB::statement("alter table financial_holds add constraint financial_holds_scope_check check ((scope = 'LOCATION' and payable_item_id is null) or (scope = 'ITEM' and payable_item_id is not null))");
        DB::statement("alter table financial_holds add constraint financial_holds_reason_check check (reason in ('REFUND_PENDING', 'DISPUTE', 'COMPLIANCE', 'RECONCILIATION', 'MANUAL_REVIEW'))");
        DB::statement("alter table financial_holds add constraint financial_holds_status_check check (status in ('ACTIVE', 'RELEASED') and (status = 'RELEASED') = (released_at is not null))");
        DB::statement("alter table financial_holds add constraint financial_holds_source_check check (source in ('SYSTEM', 'ADMIN') and (source <> 'ADMIN' or (placed_by_admin_id is not null and note is not null)))");
        DB::statement("create unique index financial_holds_system_unique on financial_holds (source_key, payable_item_id) where source = 'SYSTEM' and status = 'ACTIVE'");

        /* ------------------------------------------------------------------ payouts */

        Schema::create('restaurant_payout_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->string('provider', 24);
            // The provider's token for the destination. Never a bank account number.
            $table->string('provider_account_reference', 80)->nullable();
            // What may be shown: the provider's own mask of the destination ("•••• 4321").
            $table->string('destination_mask', 24)->nullable();
            $table->string('status', 24)->default('NOT_CONFIGURED');
            $table->string('provider_status', 24)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['location_id', 'provider']);
            $table->index('organization_id');
        });
        DB::statement("alter table restaurant_payout_profiles add constraint restaurant_payout_profiles_status_check check (status in ('NOT_CONFIGURED', 'PENDING_VERIFICATION', 'VERIFIED', 'SUSPENDED'))");
        DB::statement("alter table restaurant_payout_profiles add constraint restaurant_payout_profiles_verified_check check (status <> 'VERIFIED' or (provider_account_reference is not null and verified_at is not null))");

        Schema::create('restaurant_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('settlement_id')->constrained('restaurant_settlements')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->string('restaurant_name', 160);
            // PROVIDER: sent through a payout provider. EXTERNAL: paid outside, recorded with its evidence.
            $table->string('method', 12);
            $table->string('provider', 24);
            $table->string('provider_mode', 8)->nullable();
            $table->string('provider_payout_reference', 64)->nullable();
            $table->string('provider_status', 24)->nullable();
            // Sent with every try of this payout: the provider answers a repeated key with the same payout.
            $table->string('idempotency_key', 64)->unique();
            $table->string('destination_mask', 24)->nullable();
            // The settlement's net payable — derived on the server, never taken from a request.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->string('external_reference', 80)->nullable();
            $table->date('external_paid_on')->nullable();
            $table->string('evidence_reference', 160)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->string('failure_message', 240)->nullable();
            $table->string('review_reason', 40)->nullable();
            $table->foreignId('requested_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->unsignedSmallInteger('reconcile_runs')->default(0);
            $table->timestampTz('next_action_at')->nullable();
            $table->timestampTz('last_reconciled_at')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedBigInteger('ledger_transaction_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'next_action_at']);
            $table->index(['location_id', 'requested_at']);
            $table->index(['organization_id', 'requested_at']);
            $table->index(['market_id', 'status', 'requested_at']);
            $table->index('settlement_id');
        });
        DB::statement("alter table restaurant_payouts add constraint restaurant_payouts_status_check check (status in ('REQUIRED', 'SUBMITTING', 'PROCESSING', 'PAID', 'FAILED', 'UNKNOWN', 'REVIEW_REQUIRED', 'CANCELLED'))");
        DB::statement("alter table restaurant_payouts add constraint restaurant_payouts_method_check check (method in ('PROVIDER', 'EXTERNAL'))");
        DB::statement('alter table restaurant_payouts add constraint restaurant_payouts_amount_check check (amount_minor > 0)');
        DB::statement("alter table restaurant_payouts add constraint restaurant_payouts_currency_check check (currency ~ '^[A-Z]{3}$')");
        // PAID is evidence: the provider's own reference of the payout, or — paid outside — the bank's reference, the day
        // and what proves it. And it has its ledger posting.
        DB::statement("alter table restaurant_payouts add constraint restaurant_payouts_paid_check check (status <> 'PAID' or (completed_at is not null and ledger_transaction_id is not null and ((method = 'PROVIDER' and provider_payout_reference is not null) or (method = 'EXTERNAL' and external_reference is not null and external_paid_on is not null and evidence_reference is not null and note is not null))))");
        DB::statement('alter table restaurant_payouts add constraint restaurant_payouts_ledger_fk foreign key (ledger_transaction_id) references ledger_transactions (id) on delete restrict');
        // ONE payout that is alive per settlement: two administrators, two workers or a retry cannot make a second one.
        DB::statement("create unique index restaurant_payouts_one_active on restaurant_payouts (settlement_id) where status not in ('FAILED', 'CANCELLED')");
        DB::statement('create unique index restaurant_payouts_provider_reference_unique on restaurant_payouts (provider, provider_payout_reference) where provider_payout_reference is not null');
        DB::statement('create unique index restaurant_payouts_external_reference_unique on restaurant_payouts (lower(external_reference)) where external_reference is not null');

        Schema::create('payout_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 24);
            $table->string('event_id', 80);
            $table->string('event_type', 48);
            $table->string('provider_payout_reference', 64)->nullable();
            $table->string('result', 24);
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('received_at');

            $table->unique(['provider', 'event_id']);
            $table->index('provider_payout_reference');
        });

        /* ------------------------------------------------------------------ provider settlements and reconciliation */

        Schema::create('provider_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('provider', 24);
            $table->string('provider_mode', 8)->nullable();
            $table->string('provider_settlement_reference', 64);
            $table->char('currency', 3);
            // The provider's own words and numbers, as reported.
            $table->string('status', 16);
            $table->string('provider_status', 24)->nullable();
            $table->bigInteger('amount_minor');
            $table->bigInteger('fees_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->string('bank_reference', 64)->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            // What its imported items add up to (ours, for comparison).
            $table->unsignedInteger('item_count')->default(0);
            $table->bigInteger('items_credit_minor')->default(0);
            $table->bigInteger('items_debit_minor')->default(0);
            $table->bigInteger('items_fee_minor')->default(0);
            $table->bigInteger('items_tax_minor')->default(0);
            $table->string('reconciliation_status', 16)->default('PENDING');
            $table->timestampTz('reconciled_at')->nullable();
            $table->unsignedBigInteger('ledger_transaction_id')->nullable();
            $table->jsonb('snapshot');
            $table->timestampTz('imported_at');
            $table->timestampsTz();

            $table->unique(['provider', 'provider_settlement_reference']);
            $table->index(['reconciliation_status', 'settled_at']);
            $table->index('settled_at');
        });
        DB::statement("alter table provider_settlements add constraint provider_settlements_status_check check (status in ('CREATED', 'PROCESSED', 'FAILED', 'UNKNOWN'))");
        DB::statement("alter table provider_settlements add constraint provider_settlements_recon_check check (reconciliation_status in ('PENDING', 'MATCHED', 'DIFFERENCES'))");
        DB::statement("alter table provider_settlements add constraint provider_settlements_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table provider_settlements add constraint provider_settlements_ledger_fk foreign key (ledger_transaction_id) references ledger_transactions (id) on delete restrict');

        Schema::create('provider_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('provider', 24);
            $table->foreignId('provider_settlement_id')->nullable()->constrained('provider_settlements')->restrictOnDelete();
            $table->string('provider_settlement_reference', 64)->nullable();
            // The provider's id of the payment, refund, transfer or adjustment the line is about.
            $table->string('entity_reference', 64);
            $table->string('type', 16);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('credit_minor')->default(0);
            $table->bigInteger('debit_minor')->default(0);
            // The provider's fee for the line and the tax inside that fee — the ONLY source of provider fees anywhere.
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->boolean('settled')->default(false);
            $table->boolean('on_hold')->default(false);
            $table->string('payment_reference', 64)->nullable();
            $table->string('bank_reference', 64)->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            // What it was matched with here.
            $table->string('match_status', 16)->default('PENDING');
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->unsignedBigInteger('fee_transaction_id')->nullable();
            $table->jsonb('snapshot');
            $table->timestampTz('imported_at');
            $table->timestampsTz();

            $table->unique(['provider', 'type', 'entity_reference']);
            $table->index('provider_settlement_id');
            $table->index(['provider', 'provider_settlement_reference']);
            $table->index(['match_status', 'provider_created_at']);
            $table->index('payment_attempt_id');
            $table->index('refund_id');
        });
        DB::statement("alter table provider_settlement_items add constraint provider_settlement_items_type_check check (type in ('PAYMENT', 'REFUND', 'TRANSFER', 'ADJUSTMENT', 'OTHER'))");
        DB::statement("alter table provider_settlement_items add constraint provider_settlement_items_match_check check (match_status in ('PENDING', 'MATCHED', 'DIFFERENCE', 'UNMATCHED'))");
        DB::statement("alter table provider_settlement_items add constraint provider_settlement_items_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table provider_settlement_items add constraint provider_settlement_items_ledger_fk foreign key (fee_transaction_id) references ledger_transactions (id) on delete restrict');

        Schema::create('reconciliation_differences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // The same finding, once: found again it is the same row, not a new one.
            $table->string('dedupe_key', 200)->unique();
            // PROVIDER: our records against the provider's. INTERNAL: our records against each other.
            $table->string('source', 12);
            $table->string('type', 40);
            $table->string('severity', 8);
            $table->string('status', 24)->default('OPEN');
            $table->string('provider', 24)->nullable();
            $table->string('provider_reference', 80)->nullable();
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('subject_public_id')->nullable();
            $table->string('subject_reference', 80)->nullable();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->nullOnDelete();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('expected_minor')->nullable();
            $table->bigInteger('actual_minor')->nullable();
            $table->bigInteger('difference_minor')->nullable();
            $table->jsonb('detail')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unsignedInteger('seen_count')->default(1);
            $table->string('resolution', 24)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'severity', 'first_seen_at']);
            $table->index(['type', 'status']);
            $table->index(['market_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
        DB::statement("alter table reconciliation_differences add constraint reconciliation_differences_source_check check (source in ('PROVIDER', 'INTERNAL'))");
        DB::statement("alter table reconciliation_differences add constraint reconciliation_differences_status_check check (status in ('OPEN', 'INVESTIGATING', 'RESOLVED', 'IGNORED_WITH_REASON'))");
        DB::statement("alter table reconciliation_differences add constraint reconciliation_differences_severity_check check (severity in ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL'))");
        // Closed means somebody — or a deterministic rule — said why.
        DB::statement("alter table reconciliation_differences add constraint reconciliation_differences_closed_check check (status in ('OPEN', 'INVESTIGATING') or (resolved_at is not null and resolution is not null and resolution_note is not null))");
        DB::statement("alter table reconciliation_differences add constraint reconciliation_differences_resolution_check check (resolution is null or resolution in ('AUTO_MATCHED', 'CORRECTED', 'ADJUSTMENT_POSTED', 'PROVIDER_CORRECTED', 'EXPLAINED', 'IGNORED'))");

        Schema::create('finance_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('kind', 32);
            $table->string('status', 12);
            $table->string('triggered_by', 12);
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->boolean('dry_run')->default(false);
            $table->jsonb('parameters')->nullable();
            $table->jsonb('result')->nullable();
            $table->unsignedInteger('problems')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['kind', 'started_at']);
        });
        DB::statement("alter table finance_runs add constraint finance_runs_kind_check check (kind in ('VERIFY_LEDGER', 'RECONCILE_INTERNAL', 'PROVIDER_IMPORT', 'SETTLEMENT_GENERATION', 'POST_PENDING', 'BACKFILL'))");
        DB::statement("alter table finance_runs add constraint finance_runs_status_check check (status in ('RUNNING', 'OK', 'PROBLEMS', 'FAILED'))");
    }

    public function down(): void
    {
        foreach (['finance_runs', 'reconciliation_differences', 'provider_settlement_items', 'provider_settlements', 'payout_webhook_events', 'restaurant_payouts', 'restaurant_payout_profiles', 'financial_holds', 'restaurant_settlement_lines', 'restaurant_payable_items', 'financial_adjustments', 'restaurant_settlements', 'order_financial_allocations', 'commission_plans', 'ledger_entries', 'ledger_transactions', 'ledger_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('drop function if exists settlement_lines_refuse_change(); drop function if exists ledger_assert_has_entries(); drop function if exists ledger_assert_balanced(); drop function if exists ledger_refuse_change();');
    }
};
