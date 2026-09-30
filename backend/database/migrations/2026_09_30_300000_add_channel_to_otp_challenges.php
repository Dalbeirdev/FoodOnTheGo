<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which channel carried the code (WhatsApp or SMS): needed to escalate a resend to the next channel and
     * to see delivery cost and success per channel later.
     */
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->string('channel', 16)->nullable()->after('purpose');
        });
        DB::statement("alter table otp_challenges add constraint otp_challenges_channel_check check (channel is null or channel in ('sms', 'whatsapp'))");
    }

    public function down(): void
    {
        DB::statement('alter table otp_challenges drop constraint otp_challenges_channel_check');
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }
};
