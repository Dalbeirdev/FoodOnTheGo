<?php

namespace App\Services\Notifications\Providers;

final readonly class SmsMessage
{
    public function __construct(
        public string $toE164,
        public string $body,
        public string $deliveryPublicId,
    ) {}
}
