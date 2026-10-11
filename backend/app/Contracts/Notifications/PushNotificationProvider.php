<?php

namespace App\Contracts\Notifications;

use App\Services\Notifications\Providers\ProviderResult;
use App\Services\Notifications\Providers\PushMessage;

/**
 * Provider-neutral push delivery. One call = one device. The implementation maps its own errors to the normalised
 * categories; it never throws for a delivery outcome (only for programmer errors).
 */
interface PushNotificationProvider
{
    public function name(): string;

    public function send(PushMessage $message): ProviderResult;

    /** OPERATIONAL | DEGRADED | UNAVAILABLE | OFF with a detail — a real probe, never "green because configured". */
    public function health(): array;
}
