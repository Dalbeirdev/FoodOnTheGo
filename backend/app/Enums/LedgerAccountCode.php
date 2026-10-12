<?php

namespace App\Enums;

/**
 * The accounts of the platform's financial ledger (Module 36). Only accounts something really posts to exist.
 *
 * Debit accounts (assets, expenses) grow with a debit; credit accounts (liabilities, revenue) with a credit. A
 * "clearing" account holds money that is on its way and belongs to nobody yet: it should return to zero.
 */
enum LedgerAccountCode: string
{
    /** Customer money received and not yet anybody's: until the order is recognized or the money goes back. */
    case PaymentClearing = 'PAYMENT_CLEARING';
    /** What the payment provider holds for the platform (per provider): payments in, refunds / fees / settlements out. */
    case ProviderClearing = 'PROVIDER_CLEARING';
    /** What the platform owes a restaurant location (one account per location and currency). */
    case RestaurantPayable = 'RESTAURANT_PAYABLE';
    case PlatformCommissionRevenue = 'PLATFORM_COMMISSION_REVENUE';
    case PlatformFeeRevenue = 'PLATFORM_FEE_REVENUE';
    /** Discounts the platform pays for. */
    case PromotionExpense = 'PROMOTION_EXPENSE';
    /** Tax collected from customers and not passed to a restaurant. Whose liability it is, is a pending decision. */
    case TaxLiability = 'TAX_LIABILITY';
    /** A completed refund of a recognized order, until somebody decides who bears it. */
    case RefundClearing = 'REFUND_CLEARING';
    case PlatformRefundExpense = 'PLATFORM_REFUND_EXPENSE';
    /** The provider's processing fee, net of the tax on it — only from the provider's own settlement records. */
    case ProviderFeeExpense = 'PROVIDER_FEE_EXPENSE';
    case ProviderTaxExpense = 'PROVIDER_TAX_EXPENSE';
    /** What a lost dispute took, until somebody decides who bears it. */
    case DisputeClearing = 'DISPUTE_CLEARING';
    case PlatformDisputeExpense = 'PLATFORM_DISPUTE_EXPENSE';
    /** Money sent to restaurants (the other side of a paid payout; cleared by the bank side, outside this ledger). */
    case PayoutClearing = 'PAYOUT_CLEARING';
    /** What the payment provider settled to the platform's bank account. */
    case PlatformBank = 'PLATFORM_BANK';
    /** The other side of an approved manual adjustment of a restaurant's payable. */
    case PlatformAdjustments = 'PLATFORM_ADJUSTMENTS';

    /** 'D' or 'C': the side that grows the account. */
    public function normalSide(): string
    {
        return match ($this) {
            self::ProviderClearing, self::PromotionExpense, self::RefundClearing, self::PlatformRefundExpense, self::ProviderFeeExpense,
            self::ProviderTaxExpense, self::DisputeClearing, self::PlatformDisputeExpense, self::PlatformBank, self::PlatformAdjustments => 'D',
            default => 'C',
        };
    }

    /** Accounts whose balance on the wrong side means something is wrong (a clearing account may be zero, never inverted). */
    public function mayNotInvert(): bool
    {
        return in_array($this, [self::PaymentClearing, self::ProviderClearing, self::RefundClearing, self::DisputeClearing, self::TaxLiability, self::PlatformBank, self::PayoutClearing], true);
    }
}
