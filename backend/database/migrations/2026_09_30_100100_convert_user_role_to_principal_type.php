<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identities have three separate security contexts: CUSTOMER, RESTAURANT_USER, ADMIN_USER.
     * The free-text "role" column becomes a constrained principal_type; permissions live in permission_grants.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->renameColumn('role', 'principal_type');
        });

        DB::statement('alter table users alter column principal_type drop default');
        DB::statement("update users set principal_type = case principal_type when 'restaurant' then 'RESTAURANT_USER' when 'admin' then 'ADMIN_USER' else 'CUSTOMER' end");
        DB::statement("alter table users alter column principal_type set default 'CUSTOMER'");
        DB::statement("alter table users add constraint users_principal_type_check check (principal_type in ('CUSTOMER', 'RESTAURANT_USER', 'ADMIN_USER'))");

        Schema::table('users', function (Blueprint $table): void {
            $table->index('principal_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['principal_type']);
        });

        DB::statement('alter table users drop constraint users_principal_type_check');
        DB::statement('alter table users alter column principal_type drop default');
        DB::statement("update users set principal_type = case principal_type when 'RESTAURANT_USER' then 'restaurant' when 'ADMIN_USER' then 'admin' else 'customer' end");
        DB::statement("alter table users alter column principal_type set default 'customer'");

        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('principal_type', 'role');
            $table->index('role');
        });
    }
};
