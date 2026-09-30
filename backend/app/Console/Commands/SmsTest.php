<?php

namespace App\Console\Commands;

use App\Contracts\Sms\SmsProvider;
use App\Exceptions\SmsDeliveryException;
use App\Services\Market\MarketContext;
use App\Support\PhoneNumber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('sms:test {phone : Mobile number to receive the test code} {--country= : ISO country code of the market (default market when omitted)}')]
#[Description('Send one real test code through the configured SMS provider to prove delivery end to end (uses provider credit)')]
class SmsTest extends Command
{
    public function handle(SmsProvider $sms, MarketContext $markets): int
    {
        try {
            $phone = PhoneNumber::parse((string) $this->argument('phone'), $markets->current($this->option('country') ?: null));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($sms->name() === 'log') {
            $this->warn('SMS_DRIVER=log: nothing is sent. Configure msg91, twofactor or twilio to send a real message.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $sms->sendOtp($phone->e164, $code, (int) ceil(config('otp.ttl_seconds') / 60));
        } catch (SmsDeliveryException $e) {
            $this->error('NOT SENT — '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Accepted by ['.$sms->name().'] for '.$phone->masked().'. The test code ends in …'.substr($code, -2).' — check the phone.');

        return self::SUCCESS;
    }
}
