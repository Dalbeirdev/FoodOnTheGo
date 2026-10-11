<?php

namespace App\Contracts\Notifications;

use App\Services\Notifications\Providers\EmailMessage;
use App\Services\Notifications\Providers\ProviderResult;

interface EmailNotificationProvider
{
    public function name(): string;

    public function send(EmailMessage $message): ProviderResult;

    public function health(): array;
}
