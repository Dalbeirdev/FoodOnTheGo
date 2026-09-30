<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsProvider;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Development / test driver: records that a message would have been sent. The number is masked and the
 * body is never written, so one-time codes cannot end up in log files.
 */
final class LogSmsProvider implements SmsProvider
{
    public function send(string $phone, #[SensitiveParameter] string $message): void
    {
        Log::info('sms.sent', [
            'driver' => 'log',
            'to' => str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4),
            'length' => strlen($message),
        ]);
    }
}
