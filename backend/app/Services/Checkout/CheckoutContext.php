<?php

namespace App\Services\Checkout;

use App\Enums\CheckoutIssueCode;
use App\Models\Cart;
use App\Services\Cart\CartInspection;
use App\Services\Pickup\PickupAvailability;
use App\Services\Pickup\PickupSelectionView;

/**
 * What the final validation of a checkout found: the customer's cart as inspected just now, its pickup selection as
 * judged just now, and every reason why a checkout cannot go on. No issue = everything a checkout is built from is
 * valid at this moment.
 */
final readonly class CheckoutContext
{
    /**
     * @param  list<array{code: string, message: string, recover: string, details: array<string, mixed>}>  $issues
     * @param  list<array<string, mixed>>  $changes  what the customer must be shown (e.g. prices that changed)
     */
    public function __construct(
        public ?Cart $cart,
        public ?CartInspection $inspection,
        public ?PickupAvailability $availability,
        public ?PickupSelectionView $pickup,
        public array $issues,
        public array $changes = [],
    ) {}

    public function ok(): bool
    {
        return $this->issues === [] && $this->cart !== null && $this->inspection !== null && $this->pickup !== null;
    }

    public function has(CheckoutIssueCode $code): bool
    {
        return in_array($code->value, array_column($this->issues, 'code'), true);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{code: string, message: string, recover: string, details: array<string, mixed>}
     */
    public static function issue(CheckoutIssueCode $code, string $message, array $details = []): array
    {
        return ['code' => $code->value, 'message' => $message, 'recover' => $code->recover(), 'details' => $details];
    }
}
