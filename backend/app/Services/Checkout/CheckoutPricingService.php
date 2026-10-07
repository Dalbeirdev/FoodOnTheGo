<?php

namespace App\Services\Checkout;

use App\Enums\PickupMode;
use App\Models\Customer;
use App\Models\Promotion;
use App\Services\Cart\CartInspection;
use App\Services\Cart\CartLineInspection;
use App\Services\Pickup\PickupSelectionView;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The authority on what a checkout costs (Module 29). Everything is calculated here, on the server, in integers:
 *
 *   1. merchandise subtotal   current menu prices × quantities of the validated cart lines (Module 27 / 24)
 *   2. promotion discount     PromotionEligibilityService — one promotion, never more than the subtotal
 *   3. fees                   FeeCalculationService — only configured fees; a promotion does not reduce them
 *   4. taxes                  TaxCalculationService — configured rules decide the base, the rates and whether the
 *                             tax is added or already inside the prices; taxable fees are taxed by the FEES rule
 *   5. total                  subtotal − discount + fees + added tax, never below zero
 *
 * One currency per checkout: the cart's, which is the restaurant location's. Rounding is the one policy of
 * App\Support\Rounding (half up, at the minor unit). A client never supplies any of these numbers.
 */
final class CheckoutPricingService
{
    public const FINGERPRINT_VERSION = 1;

    public function __construct(
        private readonly PromotionEligibilityService $promotions,
        private readonly TaxCalculationService $taxes,
        private readonly FeeCalculationService $fees,
    ) {}

    /**
     * @param  int|null  $exceptCheckoutId  the checkout being priced again: its own held promotion use does not count against it
     */
    public function price(CartInspection $inspection, PickupSelectionView $pickup, Customer $customer, ?Promotion $promotion, ?CarbonInterface $at = null, ?int $exceptCheckoutId = null): CheckoutPricing
    {
        $at = CarbonImmutable::instance($at ?? now());
        $cart = $inspection->cart;
        $location = $cart->location;
        $currency = (string) $cart->currency;
        $subtotal = $inspection->subtotalMinor;

        $decision = $promotion === null
            ? PromotionDecision::none()
            : $this->promotions->evaluate($promotion, $customer, $location, $currency, $subtotal, $at, $exceptCheckoutId);
        $discount = $decision->applied() ? $decision->discountMinor : 0;

        $fees = $this->fees->calculate($location, $currency, $pickup->selection->pickup_method, $subtotal, $discount, $at);
        $taxes = $this->taxes->calculate($location, $subtotal, $discount, $fees->lines, $at);

        $selection = $pickup->selection;
        $basis = [
            'v' => self::FINGERPRINT_VERSION,
            'currency' => $currency,
            'cart' => ['id' => (string) $cart->public_id, 'version' => (int) $cart->version],
            'lines' => array_map(fn (CartLineInspection $line): array => [
                'line' => (string) $line->line->public_id,
                'item' => (string) $line->item->public_id,
                'quantity' => (int) $line->line->quantity,
                'unit_price_minor' => $line->unitPriceMinor(),
                'signature' => (string) $line->line->signature,
            ], $inspection->lines),
            // An ASAP time moves with the clock; a scheduled time is part of what was quoted.
            'pickup' => [
                'selection' => (string) $selection->public_id,
                'version' => (int) $selection->version,
                'mode' => $selection->mode->value,
                'method' => $selection->pickup_method->value,
                'slot' => $selection->mode === PickupMode::Scheduled ? $pickup->pickupAt->utc()->toIso8601String() : null,
            ],
            'promotion' => $decision->applied() ? ['id' => (string) $decision->promotion->public_id, 'version' => (int) $decision->promotion->version, 'discount_minor' => $discount] : null,
            'fees' => ['rules' => $fees->rules, 'lines' => $fees->lines],
            'taxes' => ['rules' => $taxes->rules, 'lines' => $taxes->lines],
            'totals' => [
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discount,
                'fee_minor' => $fees->totalMinor(),
                'tax_minor' => $taxes->addedMinor(),
                'tax_included_minor' => $taxes->includedMinor(),
                'total_minor' => max(0, $subtotal - $discount + $fees->totalMinor() + $taxes->addedMinor()),
            ],
        ];

        return new CheckoutPricing($currency, $subtotal, $decision, $fees, $taxes, hash('sha256', (string) json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $basis);
    }
}
