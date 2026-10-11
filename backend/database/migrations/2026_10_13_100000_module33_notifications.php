<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 33 — the notification domain.
 *
 *  - customer_devices: one row per app installation bound to a customer (never an authentication). The push token is
 *    kept ENCRYPTED with a SHA-256 hash for lookup / uniqueness; one ACTIVE row per installation and per token. A row is
 *    REVOKED (never deleted) on logout, when another customer signs in on the same installation, when the same token
 *    appears on another installation, when the provider reports the token unregistered, or by the customer / an admin.
 *  - notifications: the logical customer notification (also the in-app record): one per dedupe key (domain event id +
 *    customer + type), with the rendered safe title / body snapshot, the action target (an allow-listed name, never a
 *    URL) and the read state. Business history lives in order_events; this table is bounded by retention.
 *  - notification_deliveries: one row per channel (and per device for PUSH) with the lifecycle QUEUED → SENDING → SENT /
 *    RETRY_SCHEDULED / FAILED / INVALID_DESTINATION / SKIPPED / CANCELLED. SENT means the provider accepted the message;
 *    DELIVERED is used only when a provider gives a trustworthy receipt (none does today).
 *  - notification_delivery_attempts: append-only, one row per provider call (result, provider code, safe error, latency).
 *    Never the token, never the raw provider body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('platform', 16);
            $table->string('installation_id', 64);
            $table->string('push_provider', 16);
            $table->text('push_token')->nullable();
            $table->string('push_token_hash', 64)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('device_locale', 16)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('notifications_permission_state', 16)->default('UNKNOWN');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('token_updated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 24)->nullable();
            $table->timestampsTz();

            $table->index(['customer_id', 'revoked_at']);
            $table->index('installation_id');
            $table->index('push_token_hash');
        });
        DB::statement("alter table customer_devices add constraint customer_devices_platform_check check (platform in ('ANDROID'))");
        DB::statement("alter table customer_devices add constraint customer_devices_permission_check check (notifications_permission_state in ('UNKNOWN', 'GRANTED', 'DENIED'))");
        DB::statement('create unique index customer_devices_one_active_per_installation on customer_devices (installation_id) where revoked_at is null');
        DB::statement('create unique index customer_devices_one_active_per_token on customer_devices (push_token_hash) where revoked_at is null and push_token_hash is not null');

        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('category', 32);
            $table->string('event_type', 64);
            $table->string('template_key', 64);
            $table->unsignedSmallInteger('template_version')->default(1);
            $table->string('subject_type', 16)->nullable();
            $table->string('subject_public_id', 64)->nullable();
            $table->string('subject_reference', 64)->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->string('locale', 16);
            $table->string('priority', 8)->default('NORMAL');
            $table->boolean('transactional')->default(true);
            $table->string('title', 160);
            $table->string('body', 600);
            $table->string('action_target', 24)->default('NONE');
            $table->string('action_reference', 64)->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['customer_id', 'created_at']);
            $table->index(['customer_id', 'read_at']);
            $table->index(['subject_type', 'subject_public_id']);
            $table->index('category');
            $table->index('expires_at');
        });
        DB::statement("alter table notifications add constraint notifications_priority_check check (priority in ('NORMAL', 'HIGH'))");

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('channel', 8);
            $table->foreignId('customer_device_id')->nullable()->constrained('customer_devices')->restrictOnDelete();
            $table->string('provider', 24);
            $table->string('provider_message_reference', 191)->nullable();
            $table->string('status', 20);
            $table->string('policy_result', 32)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('sending_started_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_category', 24)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->index('notification_id');
            $table->index(['status', 'next_retry_at']);
            $table->index(['channel', 'status']);
            $table->index('customer_device_id');
        });
        DB::statement("alter table notification_deliveries add constraint notification_deliveries_channel_check check (channel in ('IN_APP', 'PUSH', 'EMAIL', 'SMS'))");
        DB::statement("alter table notification_deliveries add constraint notification_deliveries_status_check check (status in ('QUEUED', 'SENDING', 'SENT', 'DELIVERED', 'FAILED', 'RETRY_SCHEDULED', 'CANCELLED', 'SKIPPED', 'INVALID_DESTINATION'))");
        DB::statement('create unique index notification_deliveries_one_per_device on notification_deliveries (notification_id, channel, customer_device_id) where customer_device_id is not null');
        DB::statement('create unique index notification_deliveries_one_per_channel on notification_deliveries (notification_id, channel) where customer_device_id is null');

        Schema::create('notification_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('notification_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('result', 20);
            $table->string('provider_code', 64)->nullable();
            $table->string('error_category', 24)->nullable();
            $table->string('safe_error', 200)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('payload_bytes')->nullable();

            $table->unique(['delivery_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('customer_devices');
    }
};
