<?php

namespace App\Enums;

/**
 * What a support case can point at (Module 37). A link is a reference: the linked record stays the only truth about
 * itself and is read live whenever the case is shown.
 */
enum SupportLinkType: string
{
    case Order = 'ORDER';
    case Payment = 'PAYMENT';
    case Refund = 'REFUND';
    case Settlement = 'SETTLEMENT';
    case Payout = 'PAYOUT';
    case Dispute = 'DISPUTE';
    case Review = 'REVIEW';
    case Difference = 'DIFFERENCE';
    case Location = 'LOCATION';
    case Organization = 'ORGANIZATION';
    case Customer = 'CUSTOMER';

    /** The column of support_case_links that holds this kind of record. */
    public function column(): string
    {
        return match ($this) {
            self::Order => 'order_id',
            self::Payment => 'payment_attempt_id',
            self::Refund => 'refund_id',
            self::Settlement => 'settlement_id',
            self::Payout => 'payout_id',
            self::Dispute => 'dispute_id',
            self::Review => 'review_id',
            self::Difference => 'difference_id',
            self::Location => 'location_id',
            self::Organization => 'organization_id',
            self::Customer => 'customer_id',
        };
    }

    /**
     * What a customer may link — only their own.
     *
     * @return list<self>
     */
    public static function forCustomer(): array
    {
        return [self::Order, self::Payment, self::Refund];
    }

    /**
     * What a restaurant member may link — only inside the locations they may open.
     *
     * @return list<self>
     */
    public static function forRestaurant(): array
    {
        return [self::Order, self::Settlement, self::Payout];
    }
}
