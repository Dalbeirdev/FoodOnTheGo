<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 37 — support cases: ONE case domain for customers, restaurants, administrators and the system.
 *
 *  - support_cases: the case. It describes and coordinates work on an order, a payment, a refund, a settlement or a
 *    payout; it holds NO copy of their state and nothing here can change them. The case number is a human reference
 *    (unique, random — not the key and not an authorization). The status moves only through the state machine.
 *  - support_case_messages: the conversation with the requester — the ONLY rows a customer or a restaurant ever reads.
 *  - support_case_internal_notes: what administrators write for each other. A separate table on purpose: no requester
 *    query can select a note by forgetting a filter.
 *  - support_case_events: the timeline, one numbered line per thing that happened to the case. Append-only.
 *  - support_case_links: typed references to the records a case is about (one real foreign key per kind). A link is
 *    removed with the record it points at (a record of a test run that is cleaned up); the case stays.
 *  - support_case_assignments / support_case_escalations: who had the case, who was asked for more attention, why.
 *  - support_case_attachments: private files of a case — a FOUNDATION that is switched off (no malware scanner).
 *  - support_sla_policies: INTERNAL response / resolution targets. Not a contract; nothing here is shown to a
 *    requester.
 *
 * Messages, notes and events are never rewritten: the database refuses every UPDATE on them.
 */
return new class extends Migration
{
    private const STATUSES = "'OPEN', 'ASSIGNED', 'IN_PROGRESS', 'WAITING_FOR_CUSTOMER', 'WAITING_FOR_RESTAURANT', 'WAITING_FOR_PROVIDER', 'WAITING_FOR_FINANCE', 'WAITING_FOR_INTERNAL', 'RESOLVED', 'CLOSED', 'REOPENED', 'CANCELLED'";

    private const TEAMS = "'CUSTOMER_SUPPORT', 'RESTAURANT_SUPPORT', 'PAYMENTS', 'FINANCE', 'SECURITY', 'TECHNICAL_OPERATIONS'";

    public function up(): void
    {
        Schema::create('support_sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 160);
            // Each of the four is optional: the most specific ACTIVE policy that matches a case applies.
            $table->foreignId('market_id')->nullable()->constrained('markets')->cascadeOnDelete();
            $table->string('category', 24)->nullable();
            $table->string('priority', 8)->nullable();
            $table->string('team', 24)->nullable();
            // Elapsed wall-clock minutes. Null = that target is not measured.
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            // The clock stands still while support waits for the requester's answer.
            $table->boolean('pause_while_waiting_for_requester')->default(true);
            $table->string('status', 12)->default('ACTIVE');
            // TEST = a development fixture. APPROVED = the business approved it. Neither is a promise to a requester.
            $table->string('approval', 12)->default('TEST');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'market_id']);
        });
        DB::statement("alter table support_sla_policies add constraint support_sla_policies_status_check check (status in ('ACTIVE', 'RETIRED'))");
        DB::statement("alter table support_sla_policies add constraint support_sla_policies_approval_check check (approval in ('TEST', 'APPROVED'))");
        DB::statement("alter table support_sla_policies add constraint support_sla_policies_priority_check check (priority is null or priority in ('LOW', 'NORMAL', 'HIGH', 'URGENT'))");
        DB::statement('alter table support_sla_policies add constraint support_sla_policies_team_check check (team is null or team in ('.self::TEAMS.'))');
        DB::statement('alter table support_sla_policies add constraint support_sla_policies_target_check check ((first_response_minutes is not null or resolution_minutes is not null) and coalesce(first_response_minutes, 1) > 0 and coalesce(resolution_minutes, 1) > 0)');

        Schema::create('support_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // "CS-7K2M9QXB": what a person reads out. Random, unique, never the key and never an authorization.
            $table->string('case_number', 16)->unique();
            // Who opened it — derived from the caller, never sent.
            $table->string('requester_type', 12);
            // The customer the case belongs to (requester CUSTOMER) or is about (a system case on their payment).
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('requester_restaurant_user_id')->nullable()->constrained('restaurant_users')->restrictOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            // A restaurant's case is its organization's; with a location it is that location's only.
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->restrictOnDelete();
            // Administrators are scoped by market. Without one the case needs the permission for every market.
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->string('category', 24);
            $table->string('subcategory', 40)->nullable();
            $table->string('priority', 8);
            $table->string('status', 24)->default('OPEN');
            // RESTRICTED: only administrators holding the permission of the class (FINANCE / SECURITY) open the case.
            $table->string('classification', 12)->default('STANDARD');
            $table->string('restricted_class', 12)->nullable();
            $table->string('assigned_team', 24)->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('subject', 160);
            // A summary written by an administrator for administrators. Never shown to the requester.
            $table->text('summary')->nullable();
            $table->string('source', 24);
            $table->string('locale', 12)->nullable();
            // What a system case watches ("payment-recovery:{payment}"): one case that is not closed per key.
            $table->string('system_key', 160)->nullable();
            $table->foreignId('duplicate_of_case_id')->nullable()->constrained('support_cases')->nullOnDelete();
            // A statement about the CASE. It changes nothing in any other domain.
            $table->string('resolution_code', 24)->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            // The level of the escalation that is open on the case (null = none). Not a priority.
            $table->string('escalation_level', 4)->nullable();
            $table->timestampTz('escalated_at')->nullable();
            // Internal targets (support_sla_policies). Never shown to the requester.
            $table->foreignId('sla_policy_id')->nullable()->constrained('support_sla_policies')->nullOnDelete();
            $table->timestampTz('first_response_due_at')->nullable();
            $table->timestampTz('resolution_due_at')->nullable();
            // The first reply written by a person of support — not a system message.
            $table->timestampTz('first_response_at')->nullable();
            $table->string('sla_status', 10)->default('N/A');
            $table->timestampTz('sla_paused_at')->nullable();
            $table->unsignedInteger('sla_paused_seconds')->default(0);
            $table->timestampTz('sla_at_risk_at')->nullable();
            $table->timestampTz('sla_breached_at')->nullable();
            $table->timestampTz('next_action_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('last_requester_message_at')->nullable();
            $table->timestampTz('last_support_message_at')->nullable();
            // The newest message of support, and the newest message the requester has seen: a support message after it is
            // "unread" for them. Message ids, not times — two things can happen in the same instant.
            $table->unsignedBigInteger('last_support_message_id')->nullable();
            $table->unsignedBigInteger('requester_read_message_id')->nullable();
            $table->timestampTz('requester_read_at')->nullable();
            // The last number given to a line of the timeline.
            $table->unsignedInteger('event_sequence')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'priority', 'created_at']);
            $table->index(['assigned_team', 'status']);
            $table->index(['assigned_admin_id', 'status']);
            $table->index(['market_id', 'status', 'created_at']);
            $table->index(['customer_id', 'requester_type', 'created_at']);
            $table->index(['organization_id', 'location_id', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['requester_restaurant_user_id', 'created_at']);
            $table->index(['category', 'status']);
            $table->index(['sla_status', 'status']);
            $table->index(['status', 'resolved_at']);
        });
        DB::statement("alter table support_cases add constraint support_cases_number_check check (case_number ~ '^CS-[A-HJ-NP-Z2-9]{8}$')");
        DB::statement("alter table support_cases add constraint support_cases_requester_type_check check (requester_type in ('CUSTOMER', 'RESTAURANT', 'ADMIN', 'SYSTEM'))");
        // The requester is exactly the kind the type says: a customer's case has its customer, a restaurant's its member
        // and organization, an administrator's its author; a system case has no author at all.
        DB::statement("alter table support_cases add constraint support_cases_requester_check check (
            (requester_type = 'CUSTOMER' and customer_id is not null and requester_restaurant_user_id is null and created_by_admin_id is null)
            or (requester_type = 'RESTAURANT' and requester_restaurant_user_id is not null and organization_id is not null and created_by_admin_id is null)
            or (requester_type = 'ADMIN' and requester_restaurant_user_id is null)
            or (requester_type = 'SYSTEM' and requester_restaurant_user_id is null and created_by_admin_id is null))");
        DB::statement('alter table support_cases add constraint support_cases_status_check check (status in ('.self::STATUSES.'))');
        DB::statement("alter table support_cases add constraint support_cases_priority_check check (priority in ('LOW', 'NORMAL', 'HIGH', 'URGENT'))");
        DB::statement("alter table support_cases add constraint support_cases_classification_check check ((classification = 'STANDARD' and restricted_class is null) or (classification = 'RESTRICTED' and restricted_class is not null and restricted_class in ('FINANCE', 'SECURITY')))");
        DB::statement('alter table support_cases add constraint support_cases_team_check check (assigned_team is null or assigned_team in ('.self::TEAMS.'))');
        DB::statement("alter table support_cases add constraint support_cases_source_check check (source in ('CUSTOMER_WEB', 'ANDROID', 'RESTAURANT_DASHBOARD', 'ADMIN', 'SYSTEM_ALERT', 'PAYMENT_RECOVERY', 'REFUND_RECOVERY', 'FINANCE_RECONCILIATION', 'SECURITY'))");
        DB::statement("alter table support_cases add constraint support_cases_resolution_code_check check (resolution_code is null or resolution_code in ('ANSWERED', 'ORDER_RESOLVED', 'REFUND_COMPLETED', 'PAYMENT_RECONCILED', 'RESTAURANT_RESOLVED', 'DUPLICATE_CASE', 'NO_ACTION_REQUIRED', 'USER_WITHDREW', 'ESCALATED_EXTERNALLY', 'OTHER'))");
        // A case that is over says how it ended; a case that is not over carries no ending.
        DB::statement("alter table support_cases add constraint support_cases_resolution_check check ((status in ('RESOLVED', 'CLOSED', 'CANCELLED')) = (resolution_code is not null))");
        DB::statement("alter table support_cases add constraint support_cases_stamps_check check ((status <> 'RESOLVED' or resolved_at is not null) and (status <> 'CLOSED' or closed_at is not null) and (status <> 'CANCELLED' or cancelled_at is not null))");
        DB::statement("alter table support_cases add constraint support_cases_escalation_check check ((escalation_level is null) = (escalated_at is null) and (escalation_level is null or escalation_level in ('L2', 'L3')))");
        DB::statement("alter table support_cases add constraint support_cases_sla_status_check check (sla_status in ('ON_TRACK', 'AT_RISK', 'BREACHED', 'PAUSED', 'N/A'))");
        DB::statement('alter table support_cases add constraint support_cases_duplicate_check check (duplicate_of_case_id is null or duplicate_of_case_id <> id)');
        // ONE system case per watched condition, whatever became of it: a case that was closed while its condition still
        // holds is not opened a second time by the clock, and a condition that is reported again reopens its case.
        DB::statement('create unique index support_cases_system_key_unique on support_cases (system_key) where system_key is not null');

        Schema::create('support_case_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->string('author_type', 12);
            $table->foreignId('author_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('author_restaurant_user_id')->nullable()->constrained('restaurant_users')->restrictOnDelete();
            $table->foreignId('author_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            // Untrusted plain text. Rendered as text by every client, never as markup.
            $table->text('body');
            // The sender's own id of the message: a retry after a lost answer writes nothing twice.
            $table->string('client_message_id', 64)->nullable();
            $table->timestampTz('created_at');

            $table->index(['case_id', 'id']);
        });
        DB::statement("alter table support_case_messages add constraint support_case_messages_author_check check (
            (author_type = 'CUSTOMER' and author_customer_id is not null and author_restaurant_user_id is null and author_admin_id is null)
            or (author_type = 'RESTAURANT' and author_restaurant_user_id is not null and author_customer_id is null and author_admin_id is null)
            or (author_type = 'SUPPORT' and author_customer_id is null and author_restaurant_user_id is null)
            or (author_type = 'SYSTEM' and author_customer_id is null and author_restaurant_user_id is null and author_admin_id is null))");
        DB::statement('alter table support_case_messages add constraint support_case_messages_body_check check (char_length(body) between 1 and 4000)');
        DB::statement('create unique index support_case_messages_client_id on support_case_messages (case_id, author_type, client_message_id) where client_message_id is not null');

        Schema::create('support_case_internal_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            // Null = written by the system (what it saw when it opened the case).
            $table->foreignId('author_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('body');
            $table->string('client_note_id', 64)->nullable();
            $table->timestampTz('created_at');

            $table->index(['case_id', 'id']);
        });
        DB::statement('alter table support_case_internal_notes add constraint support_case_internal_notes_body_check check (char_length(body) between 1 and 4000)');
        DB::statement('create unique index support_case_internal_notes_client_id on support_case_internal_notes (case_id, author_admin_id, client_note_id) where client_note_id is not null');

        Schema::create('support_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            // 1, 2, 3 … per case: the order of the timeline, whatever the clock says.
            $table->unsignedInteger('sequence');
            $table->string('type', 32);
            $table->string('actor_type', 12);
            $table->uuid('actor_public_id')->nullable();
            $table->foreignId('actor_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            // EXTERNAL lines are part of what the requester may read; INTERNAL lines never leave the service desk.
            $table->string('audience', 8)->default('INTERNAL');
            // References and codes only — never message text, never personal data.
            $table->jsonb('detail')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');

            $table->unique(['case_id', 'sequence']);
        });
        DB::statement("alter table support_case_events add constraint support_case_events_actor_check check (actor_type in ('CUSTOMER', 'RESTAURANT', 'ADMIN', 'SYSTEM'))");
        DB::statement("alter table support_case_events add constraint support_case_events_audience_check check (audience in ('EXTERNAL', 'INTERNAL'))");

        Schema::create('support_case_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->string('link_type', 16);
            // One real foreign key per kind of record — exactly the one the type names is set.
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->cascadeOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('restaurant_settlements')->cascadeOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained('restaurant_payouts')->cascadeOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained('payment_disputes')->cascadeOnDelete();
            $table->foreignId('review_id')->nullable()->constrained('restaurant_reviews')->cascadeOnDelete();
            $table->foreignId('difference_id')->nullable()->constrained('reconciliation_differences')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('restaurant_organizations')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->uuid('entity_public_id');
            // What the record was called when it was linked (an order number, "RFD-…", "STL-…"): the search key.
            // Its state is NOT copied: it is read from the record whenever the case is shown.
            $table->string('reference', 80);
            $table->string('added_by_type', 12);
            $table->foreignId('added_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['case_id', 'link_type', 'entity_public_id']);
            $table->index(['link_type', 'entity_public_id']);
            $table->index('entity_public_id');
            $table->index('reference');
        });
        DB::statement("alter table support_case_links add constraint support_case_links_type_check check (link_type in ('ORDER', 'PAYMENT', 'REFUND', 'SETTLEMENT', 'PAYOUT', 'DISPUTE', 'REVIEW', 'DIFFERENCE', 'LOCATION', 'ORGANIZATION', 'CUSTOMER'))");
        DB::statement("alter table support_case_links add constraint support_case_links_one_check check (
            num_nonnulls(order_id, payment_attempt_id, refund_id, settlement_id, payout_id, dispute_id, review_id, difference_id, location_id, organization_id, customer_id) = 1
            and (link_type <> 'ORDER' or order_id is not null) and (link_type <> 'PAYMENT' or payment_attempt_id is not null) and (link_type <> 'REFUND' or refund_id is not null)
            and (link_type <> 'SETTLEMENT' or settlement_id is not null) and (link_type <> 'PAYOUT' or payout_id is not null) and (link_type <> 'DISPUTE' or dispute_id is not null)
            and (link_type <> 'REVIEW' or review_id is not null) and (link_type <> 'DIFFERENCE' or difference_id is not null) and (link_type <> 'LOCATION' or location_id is not null)
            and (link_type <> 'ORGANIZATION' or organization_id is not null) and (link_type <> 'CUSTOMER' or customer_id is not null))");

        Schema::create('support_case_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->string('team', 24)->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            // ROUTING = the category's queue when the case was opened; ADMIN = a person; ESCALATION = an escalation.
            $table->string('source', 12);
            $table->foreignId('assigned_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestampTz('created_at');

            $table->index(['case_id', 'id']);
        });
        DB::statement("alter table support_case_assignments add constraint support_case_assignments_source_check check (source in ('ROUTING', 'ADMIN', 'ESCALATION'))");
        DB::statement('alter table support_case_assignments add constraint support_case_assignments_team_check check (team is null or team in ('.self::TEAMS.'))');

        Schema::create('support_case_escalations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->string('reason', 24);
            $table->string('level', 4);
            $table->string('from_team', 24)->nullable();
            $table->string('to_team', 24)->nullable();
            $table->foreignId('to_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('note', 1000);
            // Null = the system (an SLA breach with automatic escalation switched on).
            $table->foreignId('escalated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('status', 8)->default('OPEN');
            $table->timestampTz('created_at');
            $table->timestampTz('closed_at')->nullable();

            $table->index(['case_id', 'status']);
            $table->index(['status', 'created_at']);
        });
        DB::statement("alter table support_case_escalations add constraint support_case_escalations_reason_check check (reason in ('FINANCIAL_RISK', 'CUSTOMER_IMPACT', 'RESTAURANT_IMPACT', 'SECURITY', 'PROVIDER_ISSUE', 'SLA_RISK', 'MANAGEMENT_REVIEW', 'OTHER'))");
        DB::statement("alter table support_case_escalations add constraint support_case_escalations_level_check check (level in ('L2', 'L3'))");
        DB::statement("alter table support_case_escalations add constraint support_case_escalations_status_check check (status in ('OPEN', 'CLOSED') and (status = 'CLOSED') = (closed_at is not null))");

        Schema::create('support_case_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('support_case_messages')->nullOnDelete();
            $table->string('uploaded_by_type', 12);
            $table->uuid('uploaded_by_public_id')->nullable();
            // Shown to people only; the stored file has a name the server chose.
            $table->string('display_name', 160);
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('disk', 24);
            $table->string('path', 255)->unique();
            // NOT_SCANNED while no scanner is configured — a production blocker for enabling attachments.
            $table->string('scan_status', 12)->default('NOT_SCANNED');
            $table->timestampTz('created_at');

            $table->index(['case_id', 'id']);
        });
        DB::statement("alter table support_case_attachments add constraint support_case_attachments_scan_check check (scan_status in ('NOT_SCANNED', 'CLEAN', 'QUARANTINED'))");
        DB::statement("alter table support_case_attachments add constraint support_case_attachments_uploader_check check (uploaded_by_type in ('CUSTOMER', 'RESTAURANT', 'ADMIN'))");

        // What was said and what happened is never rewritten.
        DB::unprepared(<<<'SQL'
            create or replace function support_refuse_update() returns trigger as $$
            begin
                -- The only change allowed is the database's own: an administrator's account is removed and the
                -- reference to it becomes null. What was written stays.
                if (to_jsonb(new) - 'author_admin_id' - 'actor_admin_id') = (to_jsonb(old) - 'author_admin_id' - 'actor_admin_id')
                    and coalesce(to_jsonb(new) ->> 'author_admin_id', to_jsonb(new) ->> 'actor_admin_id') is null then
                    return new;
                end if;
                raise exception '% is append-only: a row is never changed (Module 37)', tg_table_name using errcode = 'restrict_violation';
            end;
            $$ language plpgsql;
            create trigger support_case_messages_immutable before update on support_case_messages for each row execute function support_refuse_update();
            create trigger support_case_internal_notes_immutable before update on support_case_internal_notes for each row execute function support_refuse_update();
            create trigger support_case_events_immutable before update on support_case_events for each row execute function support_refuse_update();
        SQL);
    }

    public function down(): void
    {
        foreach (['support_case_attachments', 'support_case_escalations', 'support_case_assignments', 'support_case_links', 'support_case_events', 'support_case_internal_notes', 'support_case_messages', 'support_cases', 'support_sla_policies'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('drop function if exists support_refuse_update();');
    }
};
