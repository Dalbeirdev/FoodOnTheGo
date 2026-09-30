<?php

namespace App\Console\Commands;

use App\Services\Auth\DevelopmentOtp;
use App\Services\Sms\SmsManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sms:check')]
#[Description('Show which SMS provider delivers one-time codes in this environment and whether it is fully configured (no secret is printed)')]
class SmsCheck extends Command
{
    public function handle(SmsManager $sms, DevelopmentOtp $development): int
    {
        $status = $sms->status();

        $this->line('environment:        '.config('app.env'));
        $this->line('SMS driver:         '.$status['driver'].($status['fallback'] ? ' (fallback: '.$status['fallback'].')' : ''));
        $this->line('sends real SMS:     '.($status['sends_real_sms'] ? 'YES' : 'NO — the log driver only writes a masked line to the log'));
        $this->line('one-time code:      '.($development->enabled() ? 'FIXED development code (OTP_DEV_CODE)' : 'random, '.config('otp.length').' digits'));
        $this->line('configuration:      '.($status['ready'] ? 'complete' : 'INCOMPLETE — set: '.implode(', ', $status['missing'])));

        return $status['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
