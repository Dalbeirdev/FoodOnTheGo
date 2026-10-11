<?php

namespace App\Services\Notifications\Providers;

use App\Enums\NotificationErrorCategory;

/**
 * The normalised outcome of one provider call. `accepted` = the provider took the message (SENT, not "seen").
 */
final readonly class ProviderResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerReference = null,
        public ?string $providerCode = null,
        public ?NotificationErrorCategory $errorCategory = null,
        public ?string $safeError = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function sent(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    public static function failed(NotificationErrorCategory $category, string $code, string $safeError, ?int $retryAfterSeconds = null): self
    {
        return new self(false, null, mb_substr($code, 0, 64), $category, mb_substr($safeError, 0, 200), $retryAfterSeconds);
    }
}
