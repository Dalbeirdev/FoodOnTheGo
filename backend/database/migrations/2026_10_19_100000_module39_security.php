<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 39 — security and privacy hardening.
 *
 * 1. idempotency_keys.response_body becomes TEXT holding an ENCRYPTED copy of the replayed answer (it may carry a
 *    pickup code, a QR secret or a payer's contact details). Rows written before are encrypted in place.
 * 2. audit_events and security_events become append-only in the database itself: an UPDATE is always refused; a DELETE
 *    only inside a session that declared a purge (`set local fotg.audit_purge = 'on'` — a retention job; a local
 *    development database may allow it for its test clean-ups with ALTER DATABASE … SET fotg.audit_purge = 'on').
 * 3. payment_webhook_events: unique per provider + signed body (received from 2026-10-14 on).
 * 4. customer_devices: the push binding ends with the session that registered it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function ($table): void {
            $table->text('response_payload')->nullable();
        });
        DB::table('idempotency_keys')->whereNotNull('response_body')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('idempotency_keys')->where('id', $row->id)->update(['response_payload' => Crypt::encryptString((string) $row->response_body)]);
            }
        });
        Schema::table('idempotency_keys', function ($table): void {
            $table->dropColumn('response_body');
        });
        Schema::table('idempotency_keys', function ($table): void {
            $table->renameColumn('response_payload', 'response_body');
        });

        // The append-only triggers exist in every environment except the LOCAL development database, whose browser suites
        // remove the audit rows they create (and whose database user may not set the purge switch at database level).
        // The test database and every deployed environment have them; security:check fails where they are missing.
        if (! app()->environment('local')) {
            self::createAppendOnlyTriggers();
        }

        // 3. A payment webhook is counted once per SIGNED BODY too, not only per event-id header (which the provider does not
        //    sign): a replayed body under a new header is a duplicate. Events stored before this migration are left as they are
        //    (the index starts after the highest id at migration time — on a new database, every row).
        $after = (int) DB::table('payment_webhook_events')->max('id');
        DB::statement("create unique index payment_webhook_events_signed_body_unique on payment_webhook_events (provider, payload_hash) where id > {$after}");

        // 4. A push binding remembers the session (access token) that registered it; deleting the token (sign-out,
        //    revocation, pruning of expired tokens) unbinds it. Rows registered before stay as they are (session_bound false).
        Schema::table('customer_devices', function ($table): void {
            $table->boolean('session_bound')->default(false);
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists audit_events_append_only on audit_events;
            drop trigger if exists security_events_append_only on security_events;
            drop function if exists security_append_only();
        SQL);
        DB::statement('drop index if exists payment_webhook_events_signed_body_unique');
        Schema::table('customer_devices', function ($table): void {
            $table->dropConstrainedForeignId('access_token_id');
            $table->dropColumn('session_bound');
        });

        Schema::table('idempotency_keys', function ($table): void {
            $table->jsonb('response_plain')->nullable();
        });
        DB::table('idempotency_keys')->whereNotNull('response_body')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                try {
                    $plain = Crypt::decryptString((string) $row->response_body);
                } catch (Throwable) {
                    $plain = null;
                }
                DB::table('idempotency_keys')->where('id', $row->id)->update(['response_plain' => $plain]);
            }
        });
        Schema::table('idempotency_keys', function ($table): void {
            $table->dropColumn('response_body');
        });
        Schema::table('idempotency_keys', function ($table): void {
            $table->renameColumn('response_plain', 'response_body');
        });
    }

    /**
     * Module 39: audit_events and security_events refuse UPDATE always and DELETE unless the transaction sets
     * fotg.audit_purge = 'on' (a future retention job); public so a test or a later migration can install them.
     */
    public static function createAppendOnlyTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function security_append_only() returns trigger as $$
            begin
                if tg_op = 'DELETE' and coalesce(current_setting('fotg.audit_purge', true), '') = 'on' then
                    return old;
                end if;
                raise exception '% is append-only: % refused', tg_table_name, tg_op using errcode = 'P0001';
            end;
            $$ language plpgsql;
            create trigger audit_events_append_only before update or delete on audit_events for each row execute function security_append_only();
            create trigger security_events_append_only before update or delete on security_events for each row execute function security_append_only();
        SQL);
    }
};
