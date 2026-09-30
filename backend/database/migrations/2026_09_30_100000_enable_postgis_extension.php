<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostGIS is a platform prerequisite (route-based discovery). The extension is created by a database
     * superuser during environment setup (scripts/local/setup-database.ps1 locally; the DBA / managed-database
     * console in staging and production). This migration verifies it, and only tries to create it when missing.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('FoodOnTheGo requires PostgreSQL + PostGIS. Driver in use: '.DB::getDriverName());
        }

        $installed = DB::selectOne("select extversion from pg_extension where extname = 'postgis'");

        if ($installed === null) {
            try {
                DB::statement('create extension if not exists postgis');
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'PostGIS is not enabled in this database and the application role may not create it. '
                    .'Run "create extension postgis" as a superuser (locally: scripts/local/setup-database.ps1).',
                    previous: $e,
                );
            }
        }
    }

    /**
     * Never drop the extension: later spatial columns depend on it and dropping would destroy data.
     */
    public function down(): void
    {
        //
    }
};
