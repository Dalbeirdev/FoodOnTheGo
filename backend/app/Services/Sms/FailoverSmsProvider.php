<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsProvider;
use App\Exceptions\SmsDeliveryException;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Primary provider with one fallback (SMS_FALLBACK_DRIVER): when the primary cannot deliver, the same code
 * is sent through the second provider, so a provider outage does not lock customers out.
 */
final class FailoverSmsProvider implements SmsProvider
{
    public function __construct(private readonly SmsProvider $primary, private readonly SmsProvider $fallback) {}

    public function name(): string
    {
        return $this->primary->name().'+'.$this->fallback->name();
    }

    public function sendOtp(string $phone, #[SensitiveParameter] string $code, int $minutes): void
    {
        try {
            $this->primary->sendOtp($phone, $code, $minutes);
        } catch (SmsDeliveryException $e) {
            Log::warning('sms.failover', ['from' => $this->primary->name(), 'to' => $this->fallback->name(), 'reason' => $e->reason]);
            $this->fallback->sendOtp($phone, $code, $minutes);
        }
    }
}
