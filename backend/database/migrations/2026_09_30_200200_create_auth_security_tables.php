<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One row per one-time-code challenge. The code itself is never stored — only a keyed hash.
         * PostgreSQL (not Redis) holds challenges: verification must be atomic (row lock), single-use and
         * auditable; Redis only supplies the rate limiters in front of it.
         */
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('purpose', 30);
            $table->string('phone_e164', 16);
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('sent_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->timestampsTz();

            $table->index(['phone_e164', 'created_at']);
            $table->index('expires_at');
        });

        /**
         * Authentication / security occurrences (not business audit). Append-only by convention:
         * the application never updates or deletes a row. No secret material is ever written here.
         */
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 40);
            $table->string('principal_type', 20)->nullable();
            $table->unsignedBigInteger('principal_id')->nullable();
            $table->char('identifier_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');

            $table->index(['event', 'occurred_at']);
            $table->index(['principal_type', 'principal_id', 'occurred_at']);
            $table->index(['identifier_hash', 'occurred_at']);
        });

        /**
         * Password reset tokens for restaurant and admin users: stored hashed, expiring, single use.
         */
        Schema::create('credential_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('principal_type', 20);
            $table->unsignedBigInteger('principal_id');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['principal_type', 'principal_id']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->string('ip', 45)->nullable()->after('abilities');
            $table->string('user_agent')->nullable()->after('ip');
        });

        Schema::table('markets', function (Blueprint $table): void {
            $table->string('phone_national_pattern', 120)->nullable()->after('phone_country_code');
            $table->string('phone_trunk_prefix', 4)->nullable()->after('phone_national_pattern');
        });
        // India: ten digits starting 6–9; a leading 0 is the domestic trunk prefix and is dropped.
        DB::table('markets')->where('country_code', 'IN')->whereNull('phone_national_pattern')
            ->update(['phone_national_pattern' => '^[6-9][0-9]{9}$', 'phone_trunk_prefix' => '0']);
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table): void {
            $table->dropColumn(['phone_national_pattern', 'phone_trunk_prefix']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['public_id', 'ip', 'user_agent']);
        });
        Schema::dropIfExists('credential_reset_tokens');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('otp_challenges');
    }
};
