<?php

namespace App\Services\Cart;

use App\Enums\CartIssueSeverity;
use App\Models\CartItem;
use App\Models\MenuItem;
use App\Services\Menu\MenuItemAvailability;
use App\Services\Menu\PriceQuote;

/**
 * One cart line against the current menu: the price today (when the stored selection can still be priced), the
 * item's availability and the issues. The stored snapshot stays on the line for comparison.
 */
final readonly class CartLineInspection
{
    /**
     * @param  list<array<string, mixed>>  $issues
     */
    public function __construct(
        public CartItem $line,
        public MenuItem $item,
        public ?PriceQuote $quote,
        public MenuItemAvailability $availability,
        public array $issues,
    ) {}

    /**
     * The price that counts: today's, from the menu. The snapshot is only shown when the line cannot be priced.
     */
    public function unitPriceMinor(): int
    {
        return $this->quote?->unitPriceMinor ?? (int) $this->line->unit_price_minor;
    }

    public function lineSubtotalMinor(): int
    {
        return $this->unitPriceMinor() * (int) $this->line->quantity;
    }

    public function isBlocked(): bool
    {
        return in_array(CartIssueSeverity::Blocking->value, array_column($this->issues, 'severity'), true);
    }

    public function priceChanged(): bool
    {
        return $this->quote !== null && $this->quote->unitPriceMinor !== (int) $this->line->unit_price_minor;
    }
}
