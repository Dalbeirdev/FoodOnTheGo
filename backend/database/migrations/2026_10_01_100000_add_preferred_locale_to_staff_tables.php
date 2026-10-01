<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Language in which a restaurant or admin user receives e-mails. NULL = no preference: the message carries
 * every configured language.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['admin_users', 'restaurant_users'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('preferred_locale', 12)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['admin_users', 'restaurant_users'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('preferred_locale');
            });
        }
    }
};
