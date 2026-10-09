<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Module 32 — realtime order tracking and secure pickup verification.
 *
 *  - order_events.sequence: a deterministic per-order number (1, 2, 3 …) that clients use to ignore a duplicate or a
 *    stale event and to notice a gap. Unique per order. Existing lines are numbered in the order they were written.
 *  - order_pickup_credentials: becomes a history (one ACTIVE per order, older ones REVOKED / CONSUMED / EXPIRED) with
 *    a public reference, the QR secret kept ENCRYPTED (the customer must be able to show the same QR on every visit
 *    and on every device; only the hash is used to look it up and compare), a format version, an expiry, who consumed
 *    it and how, a bounded failed-attempt counter with a temporary lock, and why it was revoked.
 *    Credentials issued by Module 31 never stored their secret, so their QR could not be verified: every ACTIVE one of
 *    an active order is rotated here (revoked, a new one issued). Local data only.
 *  - order_pickup_verification_attempts: every attempt a restaurant user makes, successful or not — never the
 *    submitted secret or code, only the outcome, the staff member, the location, the credential and the order when known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table): void {
            $table->unsignedInteger('sequence')->nullable()->after('order_id');
        });
        DB::statement('update order_events e set sequence = n.rn from (select id, row_number() over (partition by order_id order by id) as rn from order_events) n where n.id = e.id');
        DB::statement('alter table order_events alter column sequence set not null');
        Schema::table('order_events', function (Blueprint $table): void {
            $table->unique(['order_id', 'sequence']);
        });
        DB::statement('alter table order_events add constraint order_events_sequence_check check (sequence >= 1)');

        Schema::table('order_pickup_credentials', function (Blueprint $table): void {
            $table->dropUnique(['order_id']);
            $table->index('order_id');
            $table->uuid('public_id')->nullable()->after('id');
            $table->text('token_secret')->nullable()->after('token_hash');
            $table->unsignedSmallInteger('format_version')->default(1)->after('token_secret');
            $table->timestampTz('issued_at')->nullable()->after('status');
            $table->string('consumed_method', 16)->nullable()->after('consumed_at');
            $table->foreignId('consumed_by_restaurant_user_id')->nullable()->after('consumed_method')->constrained('restaurant_users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable()->after('consumed_by_restaurant_user_id');
            $table->string('revoke_reason', 24)->nullable()->after('revoked_at');
            $table->unsignedSmallInteger('failed_attempts')->default(0)->after('revoke_reason');
            $table->timestampTz('locked_until')->nullable()->after('failed_attempts');
        });
        foreach (DB::table('order_pickup_credentials')->select('id')->orderBy('id')->cursor() as $row) {
            DB::table('order_pickup_credentials')->where('id', $row->id)->update(['public_id' => (string) Str::uuid()]);
        }
        DB::statement('update order_pickup_credentials set issued_at = created_at where issued_at is null');
        DB::statement('alter table order_pickup_credentials alter column public_id set not null');
        DB::statement('alter table order_pickup_credentials alter column issued_at set not null');
        Schema::table('order_pickup_credentials', function (Blueprint $table): void {
            $table->unique('public_id');
        });
        DB::statement("create unique index order_pickup_credentials_one_active_per_order on order_pickup_credentials (order_id) where status = 'ACTIVE'");
        DB::statement("alter table order_pickup_credentials add constraint order_pickup_credentials_consumed_check check ((status = 'CONSUMED') = (consumed_at is not null))");
        DB::statement("alter table order_pickup_credentials add constraint order_pickup_credentials_method_check check (consumed_method is null or consumed_method in ('QR', 'MANUAL_CODE'))");
        DB::statement("alter table order_pickup_credentials add constraint order_pickup_credentials_revoked_check check ((status = 'REVOKED') = (revoked_at is not null))");
        // The credentials of Module 31 (no secret kept): the ACTIVE ones of orders still on their way are rotated by
        // the application (PickupCredentials::rotate) when the order is next read or when the sweep runs; the ones of
        // finished orders are closed here so that nothing without a secret stays ACTIVE for long.
        DB::statement("update order_pickup_credentials c set status = 'REVOKED', revoked_at = now(), revoke_reason = 'ORDER_CLOSED', updated_at = now() from orders o where o.id = c.order_id and c.status = 'ACTIVE' and c.token_secret is null and o.status in ('REJECTED', 'CANCELLED', 'COMPLETED', 'PICKED_UP')");

        Schema::create('order_pickup_verification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('order_pickup_credentials')->nullOnDelete();
            $table->foreignId('restaurant_user_id')->constrained('restaurant_users')->restrictOnDelete();
            $table->foreignId('restaurant_location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->string('method', 16);
            $table->string('result', 32);
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('attempted_at');

            $table->index(['restaurant_user_id', 'attempted_at']);
            $table->index(['restaurant_location_id', 'attempted_at']);
            $table->index(['order_id', 'attempted_at']);
            $table->index(['result', 'attempted_at']);
        });
        DB::statement("alter table order_pickup_verification_attempts add constraint order_pickup_verification_attempts_method_check check (method in ('QR', 'MANUAL_CODE'))");
        DB::statement("alter table order_pickup_verification_attempts add constraint order_pickup_verification_attempts_result_check check (result in ('SUCCESS', 'INVALID_CREDENTIAL', 'NOT_READY', 'NOT_ELIGIBLE', 'WRONG_LOCATION', 'EXPIRED', 'REVOKED', 'ALREADY_USED', 'RATE_LIMITED', 'LOCKED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_pickup_verification_attempts');
        DB::statement('alter table order_pickup_credentials drop constraint if exists order_pickup_credentials_revoked_check');
        DB::statement('alter table order_pickup_credentials drop constraint if exists order_pickup_credentials_method_check');
        DB::statement('alter table order_pickup_credentials drop constraint if exists order_pickup_credentials_consumed_check');
        DB::statement('drop index if exists order_pickup_credentials_one_active_per_order');
        // Back to one credential per order: keep the newest row of each order.
        DB::statement('delete from order_pickup_credentials c using order_pickup_credentials newer where newer.order_id = c.order_id and newer.id > c.id');
        Schema::table('order_pickup_credentials', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropConstrainedForeignId('consumed_by_restaurant_user_id');
            $table->dropColumn(['public_id', 'token_secret', 'format_version', 'issued_at', 'consumed_method', 'revoked_at', 'revoke_reason', 'failed_attempts', 'locked_until']);
            $table->dropIndex(['order_id']);
            $table->unique('order_id');
        });
        DB::statement('alter table order_events drop constraint if exists order_events_sequence_check');
        Schema::table('order_events', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'sequence']);
            $table->dropColumn('sequence');
        });
    }
};
