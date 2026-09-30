<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = one permission held by one principal, optionally limited to a scope
     * (restaurant organization, restaurant location or market). A null scope means "every scope the
     * principal type may reach". Roles (named bundles of grants) arrive with the identity module.
     */
    public function up(): void
    {
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

        DB::statement("alter table permission_grants add constraint permission_grants_scope_check check ((scope_type is null and scope_id is null) or (scope_type in ('organization', 'location', 'market') and scope_id is not null))");
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_grants');
    }
};
