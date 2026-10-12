<?php

namespace App\Enums;

/**
 * What a reconciliation difference is about (Module 36).
 *
 * The first group compares our records with the payment provider's; the second our own records with each other.
 */
enum DifferenceType: string
{
    /** The provider settled a payment we do not have. */
    case MissingInternalPayment = 'MISSING_INTERNAL_PAYMENT';
    /** We verified a payment no provider settlement record mentions. */
    case MissingProviderPayment = 'MISSING_PROVIDER_PAYMENT';
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case CurrencyMismatch = 'CURRENCY_MISMATCH';
    /** The provider has a refund we do not have. */
    case MissingRefund = 'MISSING_REFUND';
    /** We completed a refund no provider settlement record mentions. */
    case MissingProviderRefund = 'MISSING_PROVIDER_REFUND';
    case RefundAmountMismatch = 'REFUND_AMOUNT_MISMATCH';
    case FeeMismatch = 'FEE_MISMATCH';
    case SettlementTotalMismatch = 'SETTLEMENT_TOTAL_MISMATCH';
    /** A provider line we cannot interpret (a transfer, an adjustment, something new). */
    case UnknownReference = 'UNKNOWN_REFERENCE';
    /** A provider record that came again with other numbers than the first time. */
    case ProviderRecordChanged = 'PROVIDER_RECORD_CHANGED';

    case PaymentOrderAmountMismatch = 'PAYMENT_ORDER_AMOUNT_MISMATCH';
    case PaymentOrderCurrencyMismatch = 'PAYMENT_ORDER_CURRENCY_MISMATCH';
    case OrderWithoutPayment = 'ORDER_WITHOUT_PAYMENT';
    case OrphanPayment = 'ORPHAN_PAYMENT';
    case RefundExceedsPayment = 'REFUND_EXCEEDS_PAYMENT';
    case PayoutSettlementMismatch = 'PAYOUT_SETTLEMENT_MISMATCH';
    case MissingLedgerPosting = 'MISSING_LEDGER_POSTING';
    case LedgerImbalance = 'LEDGER_IMBALANCE';
    case PayableMismatch = 'PAYABLE_MISMATCH';
    case ClearingInverted = 'CLEARING_INVERTED';
    /** A restaurant owes the platform. Nothing is collected automatically: FINANCE REVIEW REQUIRED. */
    case NegativeRestaurantBalance = 'NEGATIVE_RESTAURANT_BALANCE';
    case AllocationReviewRequired = 'ALLOCATION_REVIEW_REQUIRED';

    public function isInternal(): bool
    {
        return in_array($this, [
            self::PaymentOrderAmountMismatch, self::PaymentOrderCurrencyMismatch, self::OrderWithoutPayment, self::OrphanPayment, self::RefundExceedsPayment,
            self::PayoutSettlementMismatch, self::MissingLedgerPosting, self::LedgerImbalance, self::PayableMismatch, self::ClearingInverted,
            self::NegativeRestaurantBalance, self::AllocationReviewRequired,
        ], true);
    }

    /** LOW / MEDIUM / HIGH / CRITICAL — how soon a person should look. */
    public function severity(): string
    {
        return match ($this) {
            self::LedgerImbalance, self::RefundExceedsPayment => 'CRITICAL',
            self::MissingInternalPayment, self::AmountMismatch, self::CurrencyMismatch, self::MissingRefund, self::RefundAmountMismatch, self::SettlementTotalMismatch,
            self::PaymentOrderAmountMismatch, self::PaymentOrderCurrencyMismatch, self::OrderWithoutPayment, self::PayoutSettlementMismatch, self::PayableMismatch,
            self::ClearingInverted, self::ProviderRecordChanged => 'HIGH',
            self::MissingProviderPayment, self::MissingProviderRefund, self::FeeMismatch, self::OrphanPayment, self::MissingLedgerPosting,
            self::NegativeRestaurantBalance, self::AllocationReviewRequired => 'MEDIUM',
            self::UnknownReference => 'LOW',
        };
    }
}
