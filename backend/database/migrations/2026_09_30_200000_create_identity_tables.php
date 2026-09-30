<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Three separate identity tables replace the prototype `users` table: a customer (phone + OTP, no
     * password), a restaurant user and an admin user (email + password, MFA-capable) can never be confused
     * with one another — an access token always points at exactly one of these tables.
     *
     * Prototype customers that had a phone number are carried over; prototype tokens are discarded.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('phone_e164', 16)->unique();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('name', 120)->nullable();
            $table->string('email')->nullable();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->string('preferred_locale', 15)->nullable();
            $table->foreignId('market_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('terms_accepted_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table customers add constraint customers_status_check check (status in ('ACTIVE', 'RESTRICTED', 'SUSPENDED', 'DEACTIVATED'))");
        DB::statement("alter table customers add constraint customers_phone_e164_check check (phone_e164 ~ '^\\+[1-9][0-9]{7,14}$')");

        foreach (['restaurant_users', 'admin_users'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('name', 120);
                $table->string('email')->unique();
                $table->string('password')->nullable();
                $table->string('status', 16)->default('INVITED')->index();
                $table->timestampTz('email_verified_at')->nullable();
                $table->timestampTz('password_changed_at')->nullable();
                $table->timestampTz('last_login_at')->nullable();
                $table->text('mfa_secret')->nullable();
                $table->timestampTz('mfa_enabled_at')->nullable();
                $table->text('mfa_recovery_codes')->nullable();
                $table->unsignedBigInteger('mfa_last_used_step')->nullable();
                $table->timestampsTz();
            });
            DB::statement("alter table {$name} add constraint {$name}_status_check check (status in ('INVITED', 'ACTIVE', 'SUSPENDED', 'DISABLED'))");
            DB::statement("alter table {$name} add constraint {$name}_email_lowercase_check check (email = lower(email))");
            DB::statement("alter table {$name} add constraint {$name}_active_has_password_check check (status <> 'ACTIVE' or password is not null)");
        }

        if (Schema::hasTable('users')) {
            foreach (DB::table('users')->whereNotNull('phone')->where('principal_type', 'CUSTOMER')->orderBy('id')->get() as $user) {
                if (preg_match('/^\+[1-9][0-9]{7,14}$/', (string) $user->phone) !== 1) {
                    continue;
                }
                DB::table('customers')->insertOrIgnore([
                    'public_id' => $user->public_id ?? (string) Str::uuid(),
                    'phone_e164' => $user->phone,
                    'phone_verified_at' => $user->phone_verified_at,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]);
            }
        }

        DB::table('personal_access_tokens')->delete();
        Schema::dropIfExists('permission_grants');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }

    /**
     * Restores the Module 20 table shapes (structure only — prototype rows are not recreated).
     */
    public function down(): void
    {
        DB::table('personal_access_tokens')->delete();
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('restaurant_users');
        Schema::dropIfExists('customers');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->nullable()->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('password');
            $table->string('principal_type', 20)->default('CUSTOMER')->index();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
        DB::statement("alter table users add constraint users_principal_type_check check (principal_type in ('CUSTOMER', 'RESTAURANT_USER', 'ADMIN_USER'))");

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('permission_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 100);
            $table->string('scope_type', 20)->nullable();
            $table->uuid('scope_id')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'permission', 'scope_type', 'scope_id'], 'permission_grants_unique')->nullsNotDistinct();
        });
    }
};
