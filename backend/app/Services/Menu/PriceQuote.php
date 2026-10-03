<?php

namespace App\Services\Menu;

/**
 * The configured price of one item as the backend calculates it: base price plus the adjustments of the
 * selected options, times the quantity. Integer minor units in the menu's currency.
 */
final readonly class PriceQuote
{
    /**
     * @param  list<array{group_id: string, group_name: string, kind: string, options: list<array{id: string, name: string, price_adjustment_minor: int}>}>  $selections
     */
    public function __construct(
        public int $basePriceMinor,
        public int $adjustmentsMinor,
        public int $unitPriceMinor,
        public int $quantity,
        public int $lineTotalMinor,
        public string $currency,
        public array $selections,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base_price_minor' => $this->basePriceMinor,
            'adjustments_minor' => $this->adjustmentsMinor,
            'unit_price_minor' => $this->unitPriceMinor,
            'quantity' => $this->quantity,
            'line_total_minor' => $this->lineTotalMinor,
            'currency' => $this->currency,
            'selections' => $this->selections,
        ];
    }
}
