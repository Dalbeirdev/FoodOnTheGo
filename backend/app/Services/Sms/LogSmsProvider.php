<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsProvider;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Development / test driver: NO message is sent. It records that one would have been — with the number
 * masked and without the code — so one-time codes cannot end up in log files. Refused in production.
 */
final class LogSmsProvider implements SmsProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function sendOtp(string $phone, #[SensitiveParameter] string $code, int $minutes): void
    {
        Log::info('sms.sent', [
            'driver' => 'log',
            'to' => str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4),
            'kind' => 'otp',
        ]);
    }
}
