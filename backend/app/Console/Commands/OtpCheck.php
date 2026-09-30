<?php

namespace App\Console\Commands;

use App\Services\Auth\DevelopmentOtp;
use App\Services\Auth\TruecallerVerifier;
use App\Services\Otp\OtpDelivery;
use App\Services\Sms\SmsManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('otp:check')]
#[Description('Show how one-time codes reach customers in this environment (channels, providers, readiness). No secret is printed')]
class OtpCheck extends Command
{
    public function handle(SmsManager $sms, OtpDelivery $delivery, DevelopmentOtp $development, TruecallerVerifier $truecaller): int
    {
        $status = $sms->status();
        $wanted = array_values(array_filter(array_map('trim', explode(',', (string) config('otp.channels')))));
        $skipped = array_diff($wanted, $delivery->channels());

        $this->line('environment:        '.config('app.env'));
        $this->line('channel order:      '.implode(' → ', $delivery->channels()).($skipped === [] ? '' : '   (skipped, not configured: '.implode(', ', $skipped).')'));
        $this->line('SMS provider:       '.$status['driver'].($status['fallback'] ? ' (fallback: '.$status['fallback'].')' : ''));
        $this->line('real messages:      '.($delivery->simulated() ? 'NO — nothing is sent; the log driver only writes a masked line' : 'YES'));
        $this->line('one-time code:      '.($development->enabled() ? 'FIXED development code (OTP_DEV_CODE)' : 'random, '.config('otp.length').' digits'));
        $this->line('Truecaller one-tap: '.($truecaller->enabled() ? 'enabled' : 'off (TRUECALLER_CLIENT_ID not set)'));
        $this->line('configuration:      '.($status['ready'] && $skipped === [] ? 'complete' : 'INCOMPLETE — set: '.implode(', ', [...$status['missing'], ...array_map(fn (string $c): string => strtoupper($c).'_* credentials', $skipped)])));

        return $status['ready'] && $skipped === [] ? self::SUCCESS : self::FAILURE;
    }
}
