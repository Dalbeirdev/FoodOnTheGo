<?php

namespace App\Events\Checkout;

use App\Models\CheckoutSession;

/**
 * Something happened to a checkout (Module 29). Carries the checkout's public id, its currency and total, its status
 * and a few facts (a reason code, a count) — never the cart's contents, the order note, a phone number, a payment
 * reference or the journey.
 */
final class CheckoutEvent
{
    public const CREATED = 'created';

    public const REUSED = 'reused';

    public const BLOCKED = 'blocked';

    public const CONFIRMED = 'confirmed';

    public const STALE = 'stale';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const PROMOTION_APPLIED = 'promotion_applied';

    public const PROMOTION_REFUSED = 'promotion_refused';

    public const PROMOTION_REMOVED = 'promotion_removed';

    public const HOLD_FAILED = 'hold_failed';

    public const PAYMENT_STARTED = 'payment_started';

    public readonly ?string $checkoutId;

    public readonly ?string $status;

    public readonly ?string $currency;

    public readonly ?int $totalMinor;

    public readonly ?int $discountMinor;

    /**
     * @param  array<string, scalar|null>  $facts
     */
    public function __construct(public readonly string $name, ?CheckoutSession $session, public readonly array $facts = [])
    {
        $this->checkoutId = $session === null ? null : (string) $session->public_id;
        $this->status = $session?->status->value;
        $this->currency = $session === null ? null : (string) $session->currency;
        $this->totalMinor = $session === null ? null : (int) $session->total_minor;
        $this->discountMinor = $session === null ? null : (int) $session->discount_minor;
    }
}
