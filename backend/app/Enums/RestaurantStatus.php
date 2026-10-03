<?php

namespace App\Enums;

/**
 * Administrative lifecycle of a restaurant organization and of each restaurant location. It says whether the
 * platform has approved the business / the outlet — never whether the restaurant is open right now (that is
 * hours and the operational status) or accepting FoodOnTheGo orders.
 *
 *   DRAFT → SUBMITTED → UNDER_REVIEW → APPROVED ⇄ SUSPENDED
 *                                    ↘ REJECTED → SUBMITTED / UNDER_REVIEW
 *   anything live or parked → INACTIVE → DRAFT
 *
 * A status can only move along transitions(); anything else is refused. Which administrator permission a
 * move needs is permissionFor(). Restaurant users never change this status.
 */
enum RestaurantStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Suspended = 'SUSPENDED';
    case Inactive = 'INACTIVE';

    /**
     * @return array<string, list<string>> from → allowed targets
     */
    public static function transitions(): array
    {
        return [
            'DRAFT' => ['SUBMITTED', 'INACTIVE'],
            'SUBMITTED' => ['UNDER_REVIEW', 'APPROVED', 'REJECTED', 'DRAFT'],
            'UNDER_REVIEW' => ['APPROVED', 'REJECTED', 'DRAFT'],
            'APPROVED' => ['SUSPENDED', 'INACTIVE'],
            'REJECTED' => ['SUBMITTED', 'UNDER_REVIEW', 'INACTIVE'],
            'SUSPENDED' => ['APPROVED', 'INACTIVE'],
            'INACTIVE' => ['DRAFT'],
        ];
    }

    public function canBecome(self $target): bool
    {
        return in_array($target->value, self::transitions()[$this->value], true);
    }

    /**
     * Only an approved business / outlet can be shown to customers.
     */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * The administrator permission needed to move from this status to the target.
     */
    public function permissionFor(self $target): Permission
    {
        return match (true) {
            $target === self::Suspended, $this === self::Suspended && $target === self::Approved => Permission::AdminRestaurantsSuspend,
            in_array($target, [self::UnderReview, self::Approved, self::Rejected], true) => Permission::AdminRestaurantsApprove,
            // A reviewer sends an application back for changes; everything else on a draft is plain management.
            $target === self::Draft && in_array($this, [self::Submitted, self::UnderReview], true) => Permission::AdminRestaurantsApprove,
            default => Permission::AdminRestaurantsManage,
        };
    }

    /**
     * Rejecting, suspending and closing take something away from the restaurant: a reason is mandatory.
     */
    public function needsReason(): bool
    {
        return in_array($this, [self::Rejected, self::Suspended, self::Inactive], true);
    }
}
