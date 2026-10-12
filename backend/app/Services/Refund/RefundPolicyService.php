<?php

namespace App\Services\Refund;

use App\Enums\RefundStatus;
use App\Models\Refund;

/**
 * Who approves a refund: the policy itself, or a person (Module 35).
 *
 *   NOT_ELIGIBLE   the records say the money cannot go back as things stand (a dispute, nothing left, no payment)
 *   AUTO_APPROVE   a refund the SYSTEM owes (REQUIRED) whose reason the business has approved for automatic refunds
 *                  (config refunds.policy.auto_approve_reasons), within the automatic limit
 *   MANUAL_REVIEW  everything else — every refund a person asked for, and every reason nobody approved
 *
 * AUTO-REFUND POLICY = PENDING BUSINESS APPROVAL: no reason is configured by default, so every refund waits for a
 * person with admin.refunds.approve. Nothing is refunded just because an order was rejected.
 */
final class RefundPolicyService
{
    public const AUTO_APPROVE = 'AUTO_APPROVE';

    public const MANUAL_REVIEW = 'MANUAL_REVIEW';

    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    /**
     * @return array{outcome: self::AUTO_APPROVE|self::MANUAL_REVIEW|self::NOT_ELIGIBLE, reason: string}
     */
    public function decide(Refund $refund, RefundEligibility $eligibility): array
    {
        // The refund's own amount is held already: "nothing left" is not a reason against the refund that holds it.
        if (! $eligibility->eligible && ! in_array($eligibility->code, ['refund_already_processing', 'refund_already_completed'], true)) {
            return ['outcome' => self::NOT_ELIGIBLE, 'reason' => strtoupper((string) $eligibility->code)];
        }
        if ($refund->status !== RefundStatus::Required || $refund->requested_by_type === Refund::BY_CUSTOMER) {
            return ['outcome' => self::MANUAL_REVIEW, 'reason' => 'REQUESTED_BY_A_PERSON'];
        }
        if (! in_array($refund->reason_code->value, (array) config('refunds.policy.auto_approve_reasons'), true)) {
            return ['outcome' => self::MANUAL_REVIEW, 'reason' => 'REASON_NOT_APPROVED_FOR_AUTOMATIC_REFUND'];
        }
        $max = config('refunds.policy.auto_approve_max_minor');
        if ($max !== null && $refund->amountMinor() > (int) $max) {
            return ['outcome' => self::MANUAL_REVIEW, 'reason' => 'ABOVE_AUTOMATIC_LIMIT'];
        }

        return ['outcome' => self::AUTO_APPROVE, 'reason' => 'REASON_APPROVED_FOR_AUTOMATIC_REFUND'];
    }
}
