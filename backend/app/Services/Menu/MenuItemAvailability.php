<?php

namespace App\Services\Menu;

use App\Enums\MenuAvailabilityReason;

/**
 * Whether an item is shown to customers and whether it can be ordered right now, with the first failing
 * condition as the reason. `restaurantReason` carries the restaurant's own reason when the restaurant itself
 * is the obstacle (closed, paused, outside coverage …).
 */
final readonly class MenuItemAvailability
{
    public function __construct(
        public bool $visible,
        public bool $orderable,
        public ?MenuAvailabilityReason $reason,
        public ?string $restaurantReason = null,
    ) {}

    public static function orderable(): self
    {
        return new self(true, true, null);
    }

    public static function blocked(MenuAvailabilityReason $reason, ?string $restaurantReason = null): self
    {
        return new self($reason->keepsVisible(), false, $reason, $restaurantReason);
    }

    /**
     * @return array{visible: bool, orderable: bool, reason: string|null, restaurant_reason: string|null}
     */
    public function toArray(): array
    {
        return ['visible' => $this->visible, 'orderable' => $this->orderable, 'reason' => $this->reason?->value, 'restaurant_reason' => $this->restaurantReason];
    }
}
