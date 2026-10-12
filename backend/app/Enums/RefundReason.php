<?php

namespace App\Enums;

/**
 * Why money goes back (Module 35). A controlled list: free text is a note beside it, never the reason itself.
 */
enum RefundReason: string
{
    case RestaurantRejected = 'RESTAURANT_REJECTED';
    case OrderCannotBeFulfilled = 'ORDER_CANNOT_BE_FULFILLED';
    case CustomerCancellation = 'CUSTOMER_CANCELLATION';
    case RestaurantCancellation = 'RESTAURANT_CANCELLATION';
    case AdminCancellation = 'ADMIN_CANCELLATION';
    case DuplicatePayment = 'DUPLICATE_PAYMENT';
    case PaymentOrderFailure = 'PAYMENT_ORDER_FAILURE';
    case ItemUnavailable = 'ITEM_UNAVAILABLE';
    case PickupCapacityFailure = 'PICKUP_CAPACITY_FAILURE';
    case AdminFinancialCorrection = 'ADMIN_FINANCIAL_CORRECTION';
    case Other = 'OTHER';

    /** What a customer reads. Neutral words: never who was at fault, never an internal note. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::RestaurantRejected => 'The restaurant could not accept the order',
            self::OrderCannotBeFulfilled, self::PickupCapacityFailure, self::PaymentOrderFailure => 'The order could not be completed',
            self::CustomerCancellation, self::RestaurantCancellation, self::AdminCancellation => 'The order was cancelled',
            self::DuplicatePayment => 'A payment was received twice',
            self::ItemUnavailable => 'An item was not available',
            self::AdminFinancialCorrection, self::Other => 'Adjustment by FoodOnTheGo support',
        };
    }

    /** A person has to write why. */
    public function needsNote(): bool
    {
        return $this === self::Other || $this === self::AdminFinancialCorrection;
    }

    /**
     * The reasons an administrator may give for a refund of an order that exists.
     *
     * @return list<self>
     */
    public static function forOrderRequests(): array
    {
        return [self::OrderCannotBeFulfilled, self::CustomerCancellation, self::RestaurantCancellation, self::ItemUnavailable, self::PickupCapacityFailure, self::AdminFinancialCorrection, self::Other];
    }

    /**
     * The reasons an administrator may give when ending an order.
     *
     * @return list<self>
     */
    public static function forCancellations(): array
    {
        return [self::AdminCancellation, self::RestaurantCancellation, self::CustomerCancellation, self::OrderCannotBeFulfilled, self::ItemUnavailable, self::PickupCapacityFailure];
    }
}
