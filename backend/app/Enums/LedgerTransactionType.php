<?php

namespace App\Enums;

/**
 * What a ledger transaction records (Module 36). One business event, one transaction, once.
 */
enum LedgerTransactionType: string
{
    case PaymentVerified = 'PAYMENT_VERIFIED';
    case OrderRecognized = 'ORDER_RECOGNIZED';
    case RefundCompleted = 'REFUND_COMPLETED';
    case RefundLiability = 'REFUND_LIABILITY';
    case DisputeLost = 'DISPUTE_LOST';
    case DisputeLiability = 'DISPUTE_LIABILITY';
    case PayoutPaid = 'PAYOUT_PAID';
    case PayoutReversed = 'PAYOUT_REVERSED';
    case ProviderFee = 'PROVIDER_FEE';
    case ProviderSettlementReceived = 'PROVIDER_SETTLEMENT_RECEIVED';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
    case Reversal = 'REVERSAL';
}
