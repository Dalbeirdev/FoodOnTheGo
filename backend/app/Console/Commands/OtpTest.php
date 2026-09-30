<?php

namespace App\Console\Commands;

use App\Exceptions\DeliveryException;
use App\Services\Market\MarketContext;
use App\Services\Otp\OtpDelivery;
use App\Support\PhoneNumber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('otp:test {phone : Mobile number to receive the test code} {--channel= : sms or whatsapp (default: the first configured channel)} {--country= : ISO country code of the market (default market when omitted)}')]
#[Description('Send one real test code through the configured channel to prove delivery end to end (uses provider credit)')]
class OtpTest extends Command
{
    public function handle(OtpDelivery $delivery, MarketContext $markets): int
    {
        try {
            $phone = PhoneNumber::parse((string) $this->argument('phone'), $markets->current($this->option('country') ?: null));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $channel = $this->option('channel') ?: null;
        if ($channel !== null && ! in_array($channel, $delivery->channels(), true)) {
            $this->error("Channel [{$channel}] is not enabled here. Enabled: ".implode(', ', $delivery->channels()).'. See php artisan otp:check.');

            return self::FAILURE;
        }

        if ($delivery->simulated()) {
            $this->warn('Nothing is sent in this configuration (SMS_DRIVER=log). Configure a provider to send a real message.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $used = $delivery->deliver($phone->e164, $code, (int) ceil(config('otp.ttl_seconds') / 60), $channel);
        } catch (DeliveryException $e) {
            $this->error('NOT SENT — '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Accepted on ['.$used.'] for '.$phone->masked().'. The test code ends in …'.substr($code, -2).' — check the phone.');

        return self::SUCCESS;
    }
}
