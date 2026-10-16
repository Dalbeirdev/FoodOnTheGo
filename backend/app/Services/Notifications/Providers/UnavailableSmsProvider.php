<?php

namespace App\Services\Notifications\Providers;

use App\Contracts\Notifications\SmsNotificationProvider;
use App\Enums\NotificationErrorCategory;

/**
 * Module 41: notification SMS outside local / testing. No SMS provider is approved for notifications (the Module 21
 * OTP sender is a different contract and policy), so a server that switched the channel on refuses every message as
 * not configured and reports UNAVAILABLE — instead of the fake provider reporting messages as SENT that nobody received.
 */
final class UnavailableSmsProvider implements SmsNotificationProvider
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function send(SmsMessage $message): ProviderResult
    {
        return ProviderResult::failed(NotificationErrorCategory::AuthConfiguration, 'NOT_CONFIGURED', 'No SMS provider is configured for notifications on this server.');
    }

    public function health(): array
    {
        return ['status' => 'UNAVAILABLE', 'driver' => 'unavailable', 'detail' => 'No SMS provider is approved for notifications (PENDING PROVIDER CONFIGURATION).', 'latency_ms' => null];
    }
}
