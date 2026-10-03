<?php

namespace App\Services\Restaurant;

use App\Enums\OpenState;
use App\Enums\RestaurantAvailabilityReason;
use Carbon\CarbonImmutable;

/**
 * The state of one restaurant location at one instant.
 *
 *   visible    a customer may see it (lists, detail page)
 *   orderable  a customer may place an order right now — `reason` is set exactly when this is false
 *
 * Open, accepting orders and orderable are separate facts: a restaurant can be open but paused, or accepting
 * orders but closed until tomorrow.
 */
final readonly class RestaurantAvailability
{
    public function __construct(
        public bool $visible,
        public bool $orderable,
        public ?RestaurantAvailabilityReason $reason,
        public OpenState $openState,
        public bool $acceptingOrders,
        public ?CarbonImmutable $closesAt,
        public ?CarbonImmutable $opensNextAt,
        public CarbonImmutable $checkedAt,
    ) {}

    public function isOpen(): bool
    {
        return $this->openState === OpenState::Open;
    }

    /**
     * Customer-safe representation. Only meaningful for a visible restaurant. Instants are UTC, like every
     * timestamp of the API; the restaurant's time zone is part of its own representation.
     *
     * @return array<string, mixed>
     */
    public function forCustomers(): array
    {
        return [
            'open_now' => $this->isOpen(),
            'open_state' => $this->openState->value,
            'accepting_orders' => $this->acceptingOrders,
            'orderable' => $this->orderable,
            'reason' => $this->reason?->forCustomers(),
            'closes_at' => $this->closesAt?->utc()->toIso8601String(),
            'opens_next_at' => $this->opensNextAt?->utc()->toIso8601String(),
            'checked_at' => $this->checkedAt->utc()->toIso8601String(),
        ];
    }

    /**
     * Full representation for the restaurant's own staff and for administrators.
     *
     * @return array<string, mixed>
     */
    public function forStaff(): array
    {
        return ['visible_to_customers' => $this->visible, ...$this->forCustomers(), 'reason' => $this->reason?->value];
    }
}
