<?php

namespace App\Enums;

/**
 * Why a checkout cannot be created, confirmed or paid. Issue codes are upper case in answers; the matching API
 * error code is the same word in lower case. `recover` tells a client where to send the customer.
 */
enum CheckoutIssueCode: string
{
    case CartMissing = 'CART_NOT_FOUND';
    case CartEmpty = 'CART_EMPTY';
    case CartExpired = 'CART_EXPIRED';
    case CartInvalid = 'CART_INVALID';
    case CartChanged = 'CART_CHANGED';
    case ItemUnavailable = 'ITEM_UNAVAILABLE';
    case PriceChanged = 'PRICE_CHANGED';
    case RestaurantUnavailable = 'RESTAURANT_UNAVAILABLE';
    case RestaurantNotAccepting = 'RESTAURANT_NOT_ACCEPTING';
    case MarketUnavailable = 'MARKET_UNAVAILABLE';
    case PickupRequired = 'PICKUP_REQUIRED';
    case PickupInvalid = 'PICKUP_INVALID';
    case PickupChanged = 'PICKUP_CHANGED';
    case PickupSlotFull = 'PICKUP_SLOT_FULL';
    case PickupSlotUnavailable = 'PICKUP_SLOT_UNAVAILABLE';
    case PickupSelectionExpired = 'PICKUP_SELECTION_EXPIRED';
    case PromotionInvalid = 'PROMOTION_INVALID';
    case PromotionExpired = 'PROMOTION_EXPIRED';
    case PromotionMinimumNotMet = 'PROMOTION_MINIMUM_NOT_MET';
    case PromotionLimitReached = 'PROMOTION_LIMIT_REACHED';
    case PromotionChanged = 'PROMOTION_CHANGED';
    case PricingChanged = 'PRICING_CHANGED';
    case TaxConfigurationChanged = 'TAX_CONFIGURATION_CHANGED';
    case FeeConfigurationChanged = 'FEE_CONFIGURATION_CHANGED';
    case CurrencyMismatch = 'CURRENCY_MISMATCH';
    case CheckoutExpired = 'CHECKOUT_EXPIRED';
    case CheckoutStale = 'CHECKOUT_STALE';
    case LegalAcceptanceRequired = 'LEGAL_ACCEPTANCE_REQUIRED';
    case PaymentMethodRequired = 'PAYMENT_METHOD_REQUIRED';
    case PaymentMethodUnavailable = 'PAYMENT_METHOD_UNAVAILABLE';
    case PaymentInProgress = 'PAYMENT_IN_PROGRESS';

    public function errorCode(): string
    {
        return strtolower($this->value);
    }

    /** Where the customer can put it right: the cart, the pickup step, or a fresh checkout. */
    public function recover(): string
    {
        return match ($this) {
            self::CartMissing, self::CartEmpty, self::CartExpired, self::CartInvalid, self::ItemUnavailable, self::CurrencyMismatch,
            self::RestaurantUnavailable, self::RestaurantNotAccepting, self::MarketUnavailable => 'cart',
            self::PickupRequired, self::PickupInvalid, self::PickupSlotFull, self::PickupSlotUnavailable, self::PickupSelectionExpired => 'pickup',
            self::LegalAcceptanceRequired, self::PaymentMethodRequired, self::PaymentMethodUnavailable => 'checkout',
            self::PaymentInProgress => 'payment',
            default => 'refresh',
        };
    }
}
