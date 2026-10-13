<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 38 — analytics: a READ / AGGREGATION layer. Nothing here is a source of truth for an order, a payment, a
 * refund or money; every table except the first can be emptied and rebuilt from the domains that own the facts.
 *
 *  - analytics_events: BEHAVIOURAL telemetry — what a session looked at (sent by the web and Android applications)
 *    and what the service answered to a journey (recorded by the backend). No phone, e-mail, name, address,
 *    coordinate or route: a customer is a keyed hash, a journey is a keyed hash plus bands and corridors. The one
 *    table that cannot be rebuilt — what was never recorded does not exist.
 *  - analytics_session_facts: one row per session — how far it came in the canonical funnel. Stages 1–8 from its
 *    events; stages 9–11 from the payment and order domains through the checkout it opened.
 *  - analytics_order_facts: one row per order, copied from the order, its financial allocation and its refunds.
 *  - analytics_daily_*: per (market or location, local day). A run DELETES a day and writes it again, so a rerun
 *    never counts twice and a late event or a correction changes the past.
 *  - analytics_runs / analytics_dirty_days: what the aggregation did and which days must be done again.
 *  - analytics_quality_results: what the integrity checks found. They report; they never repair a source.
 *  - report_exports: a CSV somebody asked for — private file, expiring, downloadable by the requester only.
 */
return new class extends Migration
{
    /** Source table => the column the aggregation filters "changed since" on. */
    private const SOURCE_INDEXES = [
        'orders' => 'updated_at', 'order_financial_allocations' => 'updated_at', 'refunds' => 'updated_at', 'payment_attempts' => 'updated_at', 'support_cases' => 'updated_at',
        'restaurant_reviews' => 'updated_at', 'notification_deliveries' => 'updated_at', 'ledger_entries' => 'created_at', 'customers' => 'created_at',
    ];

    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            // The sender's id of the event (random) or, for a server-recorded one, derived from what it describes.
            $table->uuid('event_id')->unique();
            $table->string('event_name', 48);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('source', 8);
            $table->string('platform', 8)->nullable();
            $table->string('app_version', 24)->nullable();
            // When it happened (the sender's clock, bounded by the server) and when the server stored it.
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            // The application's own random session identifier. Not a device id.
            $table->uuid('session_id')->nullable();
            // Keyed hash of the customer's internal id. Never the id, the phone number or the e-mail address.
            $table->char('customer_key', 32)->nullable();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->cascadeOnDelete();
            // Keyed hash of the journey's public id: counts journeys, cannot be followed back to one.
            $table->char('journey_key', 32)->nullable();
            // A checkout's public id — kept only when the server saw that the checkout is the caller's own.
            $table->uuid('checkout_ref')->nullable();
            // A menu item's public id (the menu is public).
            $table->uuid('item_ref')->nullable();
            $table->jsonb('properties')->default('{}');

            $table->index(['event_name', 'occurred_at']);
            $table->index(['market_id', 'occurred_at']);
            $table->index(['session_id', 'occurred_at']);
            $table->index(['location_id', 'event_name', 'occurred_at']);
            $table->index(['journey_key', 'event_name']);
            $table->index('received_at');
        });
        DB::statement("alter table analytics_events add constraint analytics_events_source_check check (source in ('CLIENT', 'SERVER'))");
        DB::statement("alter table analytics_events add constraint analytics_events_platform_check check (platform is null or platform in ('WEB', 'ANDROID'))");
        DB::statement('alter table analytics_events add constraint analytics_events_properties_check check (jsonb_typeof(properties) = \'object\' and pg_column_size(properties) <= 2048)');
        DB::statement('create index analytics_events_checkout_ref_index on analytics_events (checkout_ref) where checkout_ref is not null');
        DB::statement('create index analytics_events_customer_key_index on analytics_events (customer_key) where customer_key is not null');

        Schema::create('analytics_session_facts', function (Blueprint $table): void {
            $table->uuid('session_id')->primary();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->string('platform', 8)->nullable();
            $table->char('customer_key', 32)->nullable();
            $table->timestampTz('first_event_at');
            $table->timestampTz('last_event_at');
            // The day of the first event in the market's reporting time zone: the session's cohort day.
            $table->date('local_date');
            $table->string('timezone', 64);
            $table->unsignedInteger('events_count')->default(0);
            // The furthest stage of the canonical funnel reached IN ORDER (0 = the session never searched a journey).
            $table->unsignedSmallInteger('stage')->default(0);
            // Bit n set = the session has an event of stage n at all (in whatever order).
            $table->unsignedInteger('seen_stages')->default(0);
            $table->boolean('out_of_order')->default(false);
            $table->uuid('checkout_ref')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestampTz('payment_verified_at')->nullable();
            $table->timestampTz('order_created_at')->nullable();
            $table->timestampTz('order_completed_at')->nullable();
            $table->timestampTz('computed_at');

            $table->index(['market_id', 'local_date']);
            $table->index(['stage', 'first_event_at']);
        });
        DB::statement('alter table analytics_session_facts add constraint analytics_session_facts_stage_check check (stage between 0 and 11)');

        Schema::create('analytics_order_facts', function (Blueprint $table): void {
            $table->foreignId('order_id')->primary()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->char('customer_key', 32);
            $table->string('status', 24);
            $table->string('refund_status', 20);
            $table->string('pickup_mode', 12)->nullable();
            $table->string('pickup_method', 16)->nullable();
            $table->string('rejection_reason', 24)->nullable();
            $table->string('cancelled_by_type', 16)->nullable();
            $table->string('restaurant_timezone', 64);
            $table->timestampTz('placed_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('preparing_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('pickup_at')->nullable();
            // The order's own amounts (minor units), as the order has them.
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('fee_minor');
            $table->bigInteger('total_minor');
            $table->unsignedSmallInteger('item_count');
            $table->smallInteger('estimated_prep_minutes')->nullable();
            // Durations between the order's own timestamps, in seconds. Null = that step did not happen.
            $table->integer('accept_seconds')->nullable();
            $table->integer('prep_seconds')->nullable();
            $table->integer('pickup_wait_seconds')->nullable();
            $table->integer('total_seconds')->nullable();
            // From the order's financial allocation (Module 36). Null = no allocation was recognized.
            $table->string('allocation_status', 16)->nullable();
            $table->timestampTz('recognized_at')->nullable();
            $table->bigInteger('merchandise_gross_minor')->nullable();
            $table->bigInteger('commission_minor')->nullable();
            $table->bigInteger('restaurant_net_minor')->nullable();
            // From the refund domain (Module 35): what was actually refunded, and how many refunds completed.
            $table->bigInteger('refunded_minor')->default(0);
            $table->unsignedSmallInteger('refunds_completed')->default(0);
            // The journey the order came from — a keyed hash and bands. Null = the cart was not linked to a journey.
            $table->char('journey_key', 32)->nullable();
            $table->string('detour_band', 12)->nullable();
            $table->integer('detour_seconds')->nullable();
            $table->jsonb('corridors')->default('[]');
            $table->timestampTz('source_updated_at');
            $table->timestampTz('computed_at');

            $table->index(['market_id', 'placed_at']);
            $table->index(['market_id', 'completed_at']);
            $table->index(['location_id', 'placed_at']);
            $table->index(['location_id', 'completed_at']);
            $table->index(['market_id', 'customer_key', 'completed_at']);
        });

        Schema::create('analytics_daily_market', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->date('local_date');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            // Orders PLACED on the day, and what became of that day's orders so far.
            $table->unsignedInteger('orders_placed')->default(0);
            $table->bigInteger('placed_total_minor')->default(0);
            $table->unsignedInteger('cohort_completed')->default(0);
            $table->unsignedInteger('cohort_rejected')->default(0);
            $table->unsignedInteger('cohort_cancelled')->default(0);
            $table->unsignedInteger('cohort_active')->default(0);
            $table->unsignedInteger('cohort_refunded')->default(0);
            $table->unsignedInteger('cohort_accepted')->default(0);
            // Orders COMPLETED on the day and their totals: gross merchandise value.
            $table->unsignedInteger('orders_completed')->default(0);
            $table->bigInteger('gmv_minor')->default(0);
            $table->bigInteger('accept_seconds_sum')->default(0);
            $table->unsignedInteger('accept_count')->default(0);
            $table->bigInteger('prep_seconds_sum')->default(0);
            $table->unsignedInteger('prep_count')->default(0);
            $table->bigInteger('pickup_wait_seconds_sum')->default(0);
            $table->unsignedInteger('pickup_wait_count')->default(0);
            $table->bigInteger('total_seconds_sum')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['market_id', 'local_date', 'currency']);
        });

        Schema::create('analytics_daily_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->date('local_date');
            $table->string('timezone', 64);
            $table->unsignedInteger('new_accounts')->default(0);
            $table->unsignedInteger('first_time_ordering')->default(0);
            $table->unsignedInteger('ordering_customers')->default(0);
            $table->unsignedInteger('repeat_ordering')->default(0);
            $table->unsignedInteger('active_customers')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['market_id', 'local_date']);
        });

        Schema::create('analytics_daily_restaurant', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            // The day in the RESTAURANT's own time zone.
            $table->date('local_date');
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->unsignedInteger('orders_placed')->default(0);
            $table->bigInteger('placed_total_minor')->default(0);
            $table->unsignedInteger('cohort_completed')->default(0);
            $table->unsignedInteger('cohort_rejected')->default(0);
            $table->unsignedInteger('cohort_cancelled')->default(0);
            $table->unsignedInteger('cohort_active')->default(0);
            $table->unsignedInteger('cohort_refunded')->default(0);
            $table->unsignedInteger('cohort_accepted')->default(0);
            $table->unsignedInteger('orders_completed')->default(0);
            // Totals of the orders completed on the day, as the customer paid them.
            $table->bigInteger('completed_total_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->unsignedInteger('journey_orders')->default(0);
            $table->bigInteger('accept_seconds_sum')->default(0);
            $table->unsignedInteger('accept_count')->default(0);
            $table->bigInteger('prep_seconds_sum')->default(0);
            $table->unsignedInteger('prep_count')->default(0);
            $table->bigInteger('pickup_wait_seconds_sum')->default(0);
            $table->unsignedInteger('pickup_wait_count')->default(0);
            // Behavioural: discovery impressions (server-recorded) and what sessions opened.
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('restaurant_views')->default(0);
            $table->unsignedInteger('menu_views')->default(0);
            $table->unsignedInteger('cart_adds')->default(0);
            // Reviews PUBLISHED on the day (hidden, rejected and removed reviews are not counted).
            $table->unsignedInteger('reviews_published')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);
            $table->unsignedInteger('star_1')->default(0);
            $table->unsignedInteger('star_2')->default(0);
            $table->unsignedInteger('star_3')->default(0);
            $table->unsignedInteger('star_4')->default(0);
            $table->unsignedInteger('star_5')->default(0);
            $table->unsignedInteger('review_responses')->default(0);
            $table->unsignedInteger('review_reports')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['location_id', 'local_date']);
            $table->index(['market_id', 'local_date']);
            $table->index(['organization_id', 'local_date']);
        });

        Schema::create('analytics_daily_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('restaurant_locations')->cascadeOnDelete();
            $table->date('local_date');
            // The item as the ORDER recorded it (a snapshot): its public id when it had one, else its name.
            $table->string('item_key', 64);
            $table->uuid('item_public_id')->nullable();
            $table->string('name', 200);
            $table->char('currency', 3);
            // From order items of completed orders — what was actually sold.
            $table->unsignedInteger('units_sold')->default(0);
            $table->bigInteger('revenue_minor')->default(0);
            $table->unsignedInteger('orders')->default(0);
            // From behavioural events — what sessions looked at and put in a cart.
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('cart_adds')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['location_id', 'local_date', 'item_key']);
        });

        Schema::create('analytics_daily_funnel', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->date('local_date');
            $table->string('platform', 8);
            $table->unsignedSmallInteger('stage');
            // Sessions of the day's cohort that reached the stage in order; customers = distinct signed-in ones.
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('customers')->default(0);
            // Every event of the stage's name on the day, repeats included. Zero for stages 9–11.
            $table->unsignedInteger('events')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['market_id', 'local_date', 'platform', 'stage']);
        });

        Schema::create('analytics_daily_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->date('local_date');
            // ALL = every journey of the market; CORRIDOR = journeys along one static corridor (a journey along two
            // corridors counts in both); NO_CORRIDOR = journeys along none.
            $table->string('bucket', 12);
            $table->foreignId('corridor_id')->nullable()->constrained('route_corridors')->cascadeOnDelete();
            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('no_result_searches')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('restaurant_views')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('orders_completed')->default(0);
            // Detour of the ORDERS that came from a journey (seconds), for an average.
            $table->bigInteger('detour_seconds_sum')->default(0);
            $table->unsignedInteger('detour_count')->default(0);
            // Restaurants returned per journey, for an average coverage.
            $table->unsignedInteger('restaurants_returned')->default(0);
            $table->timestampTz('computed_at');
        });
        DB::statement("alter table analytics_daily_routes add constraint analytics_daily_routes_bucket_check check (bucket in ('ALL', 'CORRIDOR', 'NO_CORRIDOR') and ((bucket = 'CORRIDOR') = (corridor_id is not null)))");
        DB::statement('create unique index analytics_daily_routes_unique on analytics_daily_routes (market_id, local_date, bucket, coalesce(corridor_id, 0))');

        Schema::create('analytics_daily_finance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained('markets')->cascadeOnDelete();
            $table->date('local_date');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            // A measure of the financial ledger / the allocations — the same words Module 36 uses.
            $table->string('measure', 40);
            $table->bigInteger('amount_minor')->default(0);
            $table->unsignedInteger('count')->default(0);
            $table->timestampTz('computed_at');
        });
        DB::statement('create unique index analytics_daily_finance_unique on analytics_daily_finance (coalesce(market_id, 0), local_date, currency, measure)');

        Schema::create('analytics_daily_support', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->nullable()->constrained('markets')->cascadeOnDelete();
            $table->date('local_date');
            $table->string('category', 24);
            $table->string('priority', 8);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('resolved')->default(0);
            $table->unsignedInteger('closed')->default(0);
            $table->unsignedInteger('reopened')->default(0);
            $table->unsignedInteger('escalated')->default(0);
            $table->unsignedInteger('sla_breached')->default(0);
            $table->bigInteger('first_response_seconds_sum')->default(0);
            $table->unsignedInteger('first_response_count')->default(0);
            $table->bigInteger('resolution_seconds_sum')->default(0);
            $table->unsignedInteger('resolution_count')->default(0);
            $table->timestampTz('computed_at');
        });
        DB::statement('create unique index analytics_daily_support_unique on analytics_daily_support (coalesce(market_id, 0), local_date, category, priority)');

        Schema::create('analytics_daily_breakdowns', function (Blueprint $table): void {
            $table->id();
            // payments | refunds | notifications | pickup | routes | orders
            $table->string('grp', 16);
            $table->date('local_date');
            $table->foreignId('market_id')->nullable()->constrained('markets')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->string('currency', 3)->default('');
            $table->string('dimension', 32);
            $table->string('value', 64);
            $table->unsignedInteger('count')->default(0);
            $table->bigInteger('amount_minor')->default(0);
            $table->bigInteger('seconds_sum')->default(0);
            $table->unsignedInteger('seconds_count')->default(0);
            $table->timestampTz('computed_at');

            $table->index(['grp', 'market_id', 'local_date']);
            $table->index(['grp', 'location_id', 'local_date']);
        });
        DB::statement('create unique index analytics_daily_breakdowns_unique on analytics_daily_breakdowns (grp, local_date, coalesce(market_id, 0), coalesce(location_id, 0), currency, dimension, value)');

        Schema::create('analytics_dirty_days', function (Blueprint $table): void {
            $table->id();
            $table->string('grp', 16);
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->date('local_date');
            $table->timestampTz('marked_at');

            $table->unique(['grp', 'market_id', 'local_date']);
        });

        Schema::create('analytics_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // SCHEDULED = the recurring run; REBUILD = somebody asked for a range again; BACKFILL = history from the sources.
            $table->string('trigger', 12);
            $table->boolean('dry_run')->default(false);
            $table->string('status', 8)->default('RUNNING');
            $table->jsonb('groups')->default('[]');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->jsonb('result')->default('{}');
            $table->unsignedInteger('rows_written')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 300)->nullable();
            // Source rows changed before this instant were seen by the run.
            $table->timestampTz('watermark_at')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['status', 'started_at']);
        });
        DB::statement("alter table analytics_runs add constraint analytics_runs_trigger_check check (trigger in ('SCHEDULED', 'REBUILD', 'BACKFILL'))");
        DB::statement("alter table analytics_runs add constraint analytics_runs_status_check check (status in ('RUNNING', 'OK', 'FAILED'))");

        Schema::create('analytics_quality_results', function (Blueprint $table): void {
            $table->id();
            $table->string('check_key', 48);
            $table->string('status', 8);
            $table->string('message', 300);
            $table->jsonb('observed')->default('{}');
            $table->timestampTz('checked_at');

            $table->index(['check_key', 'checked_at']);
        });
        DB::statement("alter table analytics_quality_results add constraint analytics_quality_results_status_check check (status in ('HEALTHY', 'WARNING', 'ERROR'))");

        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // Who asked — exactly one of the two. Only this person can read or download the export.
            $table->foreignId('requested_by_admin_id')->nullable()->constrained('admin_users')->cascadeOnDelete();
            $table->foreignId('requested_by_restaurant_user_id')->nullable()->constrained('restaurant_users')->cascadeOnDelete();
            $table->string('audience', 12);
            $table->string('report_type', 32);
            // What was asked for, as the server understood it: period, market, locations. Never a free query.
            $table->jsonb('filters')->default('{}');
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->cascadeOnDelete();
            $table->string('status', 12)->default('QUEUED');
            $table->string('failure_code', 40)->nullable();
            // Where the file is on the PRIVATE disk. Never a public address; never sent to a client.
            $table->string('storage_reference', 200)->nullable();
            $table->string('filename', 120)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->timestampsTz();

            $table->index(['requested_by_admin_id', 'created_at']);
            $table->index(['requested_by_restaurant_user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("alter table report_exports add constraint report_exports_status_check check (status in ('QUEUED', 'PROCESSING', 'READY', 'FAILED', 'EXPIRED'))");
        DB::statement("alter table report_exports add constraint report_exports_audience_check check (audience in ('ADMIN', 'RESTAURANT'))");
        DB::statement('alter table report_exports add constraint report_exports_requester_check check ((requested_by_admin_id is not null)::int + (requested_by_restaurant_user_id is not null)::int = 1 and ((audience = \'ADMIN\') = (requested_by_admin_id is not null)))');

        // The incremental aggregation asks every source "what changed since the last run?". Without these that question
        // walks the whole table every five minutes.
        foreach (self::SOURCE_INDEXES as $table => $column) {
            DB::statement("create index if not exists {$table}_analytics_{$column}_index on {$table} ({$column})");
        }
    }

    public function down(): void
    {
        foreach (self::SOURCE_INDEXES as $table => $column) {
            DB::statement("drop index if exists {$table}_analytics_{$column}_index");
        }
        foreach ([
            'report_exports', 'analytics_quality_results', 'analytics_runs', 'analytics_dirty_days', 'analytics_daily_breakdowns', 'analytics_daily_support', 'analytics_daily_finance',
            'analytics_daily_routes', 'analytics_daily_funnel', 'analytics_daily_items', 'analytics_daily_restaurant', 'analytics_daily_customers', 'analytics_daily_market',
            'analytics_order_facts', 'analytics_session_facts', 'analytics_events',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
