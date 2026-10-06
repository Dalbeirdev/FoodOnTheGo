<?php

namespace App\Services\Cart;

use App\Exceptions\ApiException;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Services\Menu\MenuPricingService;
use App\Services\Menu\PriceQuote;
use App\Support\Money;

/**
 * The price of a cart is the backend's: base price + variant adjustments + modifier adjustments = unit price,
 * unit price × quantity = line subtotal, the sum of the lines = cart subtotal — integer minor units in the
 * currency of the restaurant location. A client never sends a price, a subtotal or a currency (the request
 * validation refuses those fields), and nothing here reads one.
 *
 * The calculation itself is Module 24's MenuPricingService, the single place a configured item price is worked
 * out; this class adds what a cart needs around it: the cart's error vocabulary, the currency rule and the
 * subtotal. Taxes, fees and promotions are not part of a cart subtotal (Modules 29+).
 */
final class CartPricingService
{
    public function __construct(private readonly MenuPricingService $menuPricing) {}

    /**
     * Validates the selection against the item's current rules and prices it.
     *
     * @param  list<array{group_id: string, option_ids: list<string>}>  $selections
     *
     * @throws ApiException 422 invalid_customization, 409 option_unavailable, 422 quantity_limit
     */
    public function quote(MenuItem $item, array $selections, int $quantity): PriceQuote
    {
        try {
            return $this->menuPricing->quote($item, $selections, $quantity);
        } catch (ApiException $e) {
            throw $this->translate($e, $item);
        }
    }

    /**
     * The same calculation for a line that is already in the cart, as an answer instead of an exception: the
     * quote when the stored selection is still valid today, otherwise the issues MenuPricingService reported.
     *
     * @param  list<array{group_id: string, option_ids: list<string>}>  $selections
     * @return array{quote: PriceQuote|null, issues: list<array<string, mixed>>}
     */
    public function evaluate(MenuItem $item, array $selections, int $quantity): array
    {
        try {
            return ['quote' => $this->menuPricing->quote($item, $selections, $quantity), 'issues' => []];
        } catch (ApiException $e) {
            return ['quote' => null, 'issues' => $e->errorCode === 'invalid_price' ? [['code' => 'invalid_price']] : array_values((array) ($e->details['issues'] ?? []))];
        }
    }

    /**
     * One currency per cart: the location's. The menu, the item and every existing line must agree with it.
     *
     * @throws ApiException 409 currency_mismatch
     */
    public function assertCurrency(RestaurantLocation $location, Menu $menu, MenuItem $item, ?string $cartCurrency): string
    {
        $currency = (string) $location->currency;
        foreach ([(string) $menu->currency, (string) $item->currency, $cartCurrency ?? $currency] as $other) {
            if ($other !== $currency) {
                throw ApiException::conflict('currency_mismatch', 'This item cannot be added to this cart: the currencies differ.', ['cart_currency' => $cartCurrency ?? $currency]);
            }
        }

        return $currency;
    }

    /**
     * The sum of line subtotals — Money refuses to add two currencies.
     *
     * @param  iterable<array{0: int, 1: string}>  $lines  [line subtotal in minor units, currency]
     */
    public function subtotal(iterable $lines, string $currency): Money
    {
        $sum = Money::zero($currency);
        foreach ($lines as [$amount, $lineCurrency]) {
            $sum = $sum->plus(new Money($amount, $lineCurrency));
        }

        return $sum;
    }

    private function translate(ApiException $e, MenuItem $item): ApiException
    {
        if ($e->errorCode === 'invalid_price') {
            return new ApiException(422, 'invalid_customization', 'This combination of options is not available.', ['issues' => [['code' => 'invalid_price']]]);
        }
        $issues = array_values((array) ($e->details['issues'] ?? []));
        $codes = array_unique(array_column($issues, 'code'));

        if ($codes === ['quantity']) {
            return new ApiException(422, 'quantity_limit', 'This quantity is not available for this item.', ['min' => (int) $item->min_quantity, 'max' => (int) $item->max_quantity]);
        }
        if ($codes === ['option_unavailable']) {
            return ApiException::conflict('option_unavailable', 'One of the chosen options is not available right now.', ['issues' => $issues]);
        }

        return new ApiException(422, 'invalid_customization', 'The chosen options are not valid for this item.', ['issues' => $issues]);
    }
}
