<?php

namespace App\Enums;

/**
 * A restaurant settlement's state (Module 36). A settlement is a calculation of what is owed for a period — it is
 * not money sent: that is a payout, and only a payout's trusted evidence makes a settlement PAID.
 *
 * CALCULATED       generated; waits for a person with admin.finance.settlements.approve
 * REVIEW_REQUIRED  its lines no longer add up to its total, or to the ledger: nobody may approve it
 * APPROVED         approved; its lines are final; a payout may be made
 * PAYMENT_PENDING  a payout is on its way
 * PAID             the payout is confirmed
 * FAILED           the payout failed (or was reversed): another one may be made
 * CANCELLED        withdrawn; its lines are free for another settlement
 * CLOSED           a statement whose lines add up to zero: nothing to pay
 */
enum SettlementStatus: string
{
    case Calculated = 'CALCULATED';
    case ReviewRequired = 'REVIEW_REQUIRED';
    case Approved = 'APPROVED';
    case PaymentPending = 'PAYMENT_PENDING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Closed = 'CLOSED';

    /**
     * The state in a RESTAURANT's words. The internal steps of the finance team never reach a restaurant:
     * IN_REVIEW (calculated, or being looked at), APPROVED, PAYMENT_PENDING (a payout is on its way — also when a
     * first try did not go through), PAID, CLOSED (nothing to pay). A withdrawn settlement is never shown at all.
     */
    public function forRestaurant(): string
    {
        return match ($this) {
            self::Calculated, self::ReviewRequired => 'IN_REVIEW',
            self::Approved => 'APPROVED',
            self::PaymentPending, self::Failed => 'PAYMENT_PENDING',
            self::Paid => 'PAID',
            self::Closed, self::Cancelled => 'CLOSED',
        };
    }

    public const RESTAURANT_WORDS = ['IN_REVIEW', 'APPROVED', 'PAYMENT_PENDING', 'PAID', 'CLOSED'];

    /**
     * @return list<string> the internal states behind a restaurant's word
     */
    public static function behind(string $restaurantWord): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s !== self::Cancelled && $s->forRestaurant() === $restaurantWord)));
    }

    /** @return list<self> a payout may be started */
    public static function payable(): array
    {
        return [self::Approved, self::Failed];
    }

    /** @return list<self> may still be withdrawn */
    public static function cancellable(): array
    {
        return [self::Calculated, self::ReviewRequired, self::Approved, self::Failed];
    }
}
