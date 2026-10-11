<?php

namespace App\Services\Notifications\Providers;

final readonly class EmailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $textBody,
        public string $htmlBody,
        public string $deliveryPublicId,
    ) {}
}
