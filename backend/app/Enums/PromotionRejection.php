<?php

namespace App\Enums;

/**
 * Why a promotion code does not apply to a checkout. The eligibility service and the administrators see the exact
 * reason; a customer is told less: an unknown code, an unpublished one and one that has not started yet all read
 * "this code is not valid", so that a code cannot be probed for campaigns that are not public.
 */
enum PromotionRejection: string
{
    case NotFound = 'PROMOTION_NOT_FOUND';
    case Inactive = 'PROMOTION_INACTIVE';
    case NotStarted = 'PROMOTION_NOT_STARTED';
    case Expired = 'PROMOTION_EXPIRED';
    case NotApplicable = 'PROMOTION_NOT_APPLICABLE';
    case CurrencyMismatch = 'PROMOTION_CURRENCY_MISMATCH';
    case MinimumNotMet = 'PROMOTION_MINIMUM_NOT_MET';
    case LimitReached = 'PROMOTION_LIMIT_REACHED';
    case CustomerLimitReached = 'PROMOTION_CUSTOMER_LIMIT_REACHED';

    /** The error code a customer receives. */
    public function customerCode(): string
    {
        return match ($this) {
            self::NotFound, self::Inactive, self::NotStarted => 'promotion_not_found',
            self::NotApplicable, self::CurrencyMismatch => 'promotion_not_applicable',
            default => strtolower($this->value),
        };
    }

    public function customerMessage(): string
    {
        return match ($this) {
            self::NotFound, self::Inactive, self::NotStarted => 'This promotion code is not valid.',
            self::Expired => 'This promotion code has expired.',
            self::NotApplicable, self::CurrencyMismatch => 'This promotion code cannot be used for this order.',
            self::MinimumNotMet => 'Your order does not reach the minimum amount for this promotion code.',
            self::LimitReached => 'This promotion code has been used up.',
            self::CustomerLimitReached => 'You have already used this promotion code.',
        };
    }
}
