<?php

namespace App\Contracts\Notifications;

use App\Services\Notifications\Providers\ProviderResult;
use App\Services\Notifications\Providers\SmsMessage;

/**
 * Notification SMS — a separate contract from the Module 21 OTP sender: authentication codes and order messages never
 * share a policy, a template or a cost rule.
 */
interface SmsNotificationProvider
{
    public function name(): string;

    public function send(SmsMessage $message): ProviderResult;

    public function health(): array;
}
