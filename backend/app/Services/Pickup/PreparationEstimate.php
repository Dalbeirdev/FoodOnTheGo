<?php

namespace App\Services\Pickup;

/**
 * How long the kitchen is expected to need for an order, and from when it can be handed over. An ESTIMATE made
 * before the order exists — never a promise that food is ready at a given minute.
 *
 *   minutes          max(restaurant default, slowest item) + adjustment for a large order
 *   readyInMinutes   max(minutes + buffer, minimum lead)  — the lead time is a floor, it is not added on top
 */
final readonly class PreparationEstimate
{
    public function __construct(
        public int $defaultMinutes,
        public int $itemMinutes,
        public int $quantityExtraMinutes,
        public int $minutes,
        public int $bufferMinutes,
        public int $minimumLeadMinutes,
        public int $totalQuantity,
    ) {}

    /**
     * Minutes from now until the order can be handed over.
     */
    public function readyInMinutes(): int
    {
        return max($this->minutes + $this->bufferMinutes, $this->minimumLeadMinutes);
    }

    /**
     * Customer-safe representation: the estimate and what it is bounded by, not how the kitchen works.
     *
     * @return array<string, int|bool>
     */
    public function forCustomers(): array
    {
        return [
            'estimated_minutes' => $this->minutes,
            'buffer_minutes' => $this->bufferMinutes,
            'minimum_lead_minutes' => $this->minimumLeadMinutes,
            'ready_in_minutes' => $this->readyInMinutes(),
            'is_estimate' => true,
        ];
    }

    /**
     * The parts of the estimate, for the restaurant's own staff (settings preview).
     *
     * @return array<string, int|bool>
     */
    public function forStaff(): array
    {
        return [...$this->forCustomers(), 'default_minutes' => $this->defaultMinutes, 'slowest_item_minutes' => $this->itemMinutes, 'quantity_extra_minutes' => $this->quantityExtraMinutes, 'total_quantity' => $this->totalQuantity];
    }
}
