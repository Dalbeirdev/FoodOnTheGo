<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles are named bundles of permissions for one principal type. A role assignment gives a role to one
     * restaurant user or admin user, optionally limited to a scope (organization / location / market).
     * Permission codes come from the App\Enums\Permission catalogue.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('principal_type', 20);
            $table->string('code', 60);
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['principal_type', 'code']);
        });
        DB::statement("alter table roles add constraint roles_principal_type_check check (principal_type in ('RESTAURANT_USER', 'ADMIN_USER'))");
        DB::statement("alter table roles add constraint roles_code_check check (code ~ '^[A-Z][A-Z0-9_]*$')");

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 100);
            $table->timestampsTz();

            $table->unique(['role_id', 'permission']);
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('principal_type', 20);
            $table->unsignedBigInteger('principal_id');
            $table->string('scope_type', 20)->nullable();
            $table->uuid('scope_id')->nullable();
            $table->foreignId('granted_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['principal_type', 'principal_id']);
            $table->unique(['role_id', 'principal_type', 'principal_id', 'scope_type', 'scope_id'], 'role_assignments_unique')->nullsNotDistinct();
        });
        DB::statement("alter table role_assignments add constraint role_assignments_principal_type_check check (principal_type in ('restaurant_user', 'admin_user'))");
        DB::statement("alter table role_assignments add constraint role_assignments_scope_check check ((scope_type is null and scope_id is null) or (scope_type in ('organization', 'location', 'market') and scope_id is not null))");
        // Tenant isolation at database level: a restaurant user's role is always tied to an organization or location.
        DB::statement("alter table role_assignments add constraint role_assignments_restaurant_scope_check check (principal_type <> 'restaurant_user' or (scope_type is not null and scope_type in ('organization', 'location')))");
        DB::statement("alter table role_assignments add constraint role_assignments_admin_scope_check check (principal_type <> 'admin_user' or scope_type is null or scope_type = 'market')");
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
