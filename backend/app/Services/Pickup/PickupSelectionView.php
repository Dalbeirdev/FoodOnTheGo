<?php

namespace App\Services\Pickup;

use App\Enums\PickupSelectionStatus;
use App\Models\CartPickupSelection;
use Carbon\CarbonImmutable;

/**
 * A pickup selection as it stands right now: the stored choice plus the result of validating it again against the
 * cart, the restaurant and the clock.
 */
final readonly class PickupSelectionView
{
    /**
     * @param  list<array{code: string, severity: string, message: string, details: array<string, mixed>}>  $issues
     */
    public function __construct(
        public CartPickupSelection $selection,
        public PickupSelectionStatus $status,
        public CarbonImmutable $pickupAt,
        public array $issues,
        public PickupAvailability $availability,
    ) {}

    public function valid(): bool
    {
        return $this->status->isUsable();
    }
}
