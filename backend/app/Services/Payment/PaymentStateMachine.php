<?php

namespace App\Services\Payment;

use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Events\Payment\PaymentEvent;
use App\Events\Payment\PaymentVerified;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Services\Payment\Data\ProviderPayment;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only place a payment attempt changes its state.
 *
 * Every step is checked against PaymentStatus::allows(), written to the attempt's timeline with who said so, and
 * announced after the commit. A signal that asks for a step the machine does not allow — a webhook that arrives
 * late, a second confirmation of something already settled — changes nothing and is noted, never an error.
 *
 * VERIFIED has its own door, verify(), and that door opens only for the provider's evidence: a captured payment on
 * this attempt's own provider order, for this attempt's amount and currency. No caller can reach VERIFIED with a
 * status it was merely told.
 *
 * The caller holds the attempt's row lock inside a transaction.
 */
final class PaymentStateMachine
{
    /**
     * A line on the timeline without a step.
     *
     * @param  array<string, mixed>  $detail  references and codes only
     */
    public function note(PaymentAttempt $attempt, string $source, string $type, array $detail = [], ?int $adminId = null): void
    {
        $this->line($attempt, $source, $type, null, null, $detail, $adminId);
    }

    /**
     * Moves the attempt to any state but VERIFIED. False when it is already there or the step is not allowed.
     *
     * @param  array<string, mixed>  $fill  further columns to write with the step (reason, failure, provider state)
     * @param  array<string, mixed>  $detail
     */
    public function move(PaymentAttempt $attempt, PaymentStatus $to, string $source, array $fill = [], array $detail = [], ?int $adminId = null): bool
    {
        if ($to === PaymentStatus::Verified) {
            throw new LogicException('A payment becomes VERIFIED only through verify().');
        }
        $from = $attempt->status;
        if ($from === $to) {
            return false;
        }
        if (! $from->allows($to)) {
            $this->note($attempt, $source, 'step_refused', ['from' => $from->value, 'to' => $to->value] + $detail, $adminId);

            return false;
        }
        $now = now();
        $attempt->forceFill($fill + [
            'status' => $to->value,
            'closed_at' => $to->isOpen() ? null : $now,
            'next_reconcile_at' => $to->isOpen() ? ($fill['next_reconcile_at'] ?? $attempt->next_reconcile_at) : null,
        ])->save();
        $this->line($attempt, $source, strtolower($to->value), $from, $to, $detail, $adminId);
        $facts = array_filter(['from' => $from->value, 'source' => $source, 'reason' => $attempt->status_reason, 'failure' => $attempt->failure_category?->value], fn ($v): bool => $v !== null);
        DB::afterCommit(fn () => event(new PaymentEvent(strtolower($to->value), $attempt, $facts)));

        return true;
    }

    /**
     * The provider captured this attempt's payment. False when the attempt is VERIFIED already (nothing happens twice).
     *
     * @throws LogicException when the evidence is not a captured payment for this attempt's own order, amount and currency
     */
    public function verify(PaymentAttempt $attempt, ProviderPayment $evidence, PaymentFulfillmentStatus $fulfillment, ?string $fulfillmentReason, string $source, ?int $adminId = null): bool
    {
        if (! $evidence->captured()
            || $attempt->provider_order_reference === null
            || $evidence->orderReference !== $attempt->provider_order_reference
            || ! $evidence->amount->equals($attempt->amount())
            || $fulfillment === PaymentFulfillmentStatus::NotApplicable) {
            throw new LogicException('Only a captured payment for the attempt\'s own order, amount and currency can verify it.');
        }
        $from = $attempt->status;
        if ($from === PaymentStatus::Verified) {
            if ($attempt->provider_payment_reference !== $evidence->reference) {
                $this->note($attempt, $source, 'second_capture_seen', ['payment' => $evidence->reference], $adminId);
            }

            return false;
        }
        if (! $from->allows(PaymentStatus::Verified)) {
            $this->note($attempt, $source, 'step_refused', ['from' => $from->value, 'to' => PaymentStatus::Verified->value], $adminId);

            return false;
        }
        $late = ! $from->isOpen();
        $now = now();
        $attempt->forceFill([
            'status' => PaymentStatus::Verified->value,
            'status_reason' => $late ? 'LATE_SUCCESS' : null,
            'provider_payment_reference' => $evidence->reference,
            'provider_payment_status' => $evidence->providerStatus,
            'method_type' => $evidence->methodType,
            'method_summary' => $evidence->methodSummary === [] ? null : $evidence->methodSummary,
            'failure_category' => null,
            'failure_code' => null,
            'provider_error_code' => null,
            'provider_error_reason' => null,
            'fulfillment_status' => $fulfillment->value,
            'fulfillment_reason' => $fulfillmentReason,
            'fulfillment_changed_at' => $now,
            'verified_at' => $now,
            'closed_at' => $now,
            'next_reconcile_at' => null,
        ])->save();
        $this->line($attempt, $source, 'verified', $from, PaymentStatus::Verified, ['payment' => $evidence->reference, 'method' => $evidence->methodType, 'late' => $late, 'fulfillment' => $fulfillment->value, 'fulfillment_reason' => $fulfillmentReason], $adminId);

        $facts = ['from' => $from->value, 'source' => $source, 'fulfillment' => $fulfillment->value];
        $started = $attempt->client_reported_at ?? $attempt->provider_ready_at ?? $attempt->created_at;
        DB::afterCommit(function () use ($attempt, $late, $facts, $fulfillment, $fulfillmentReason, $started, $now): void {
            event(new PaymentEvent(PaymentEvent::VERIFIED, $attempt, $facts + ['verification_ms' => $started === null ? null : max(0, (int) $started->diffInMilliseconds($now))]));
            if ($late) {
                event(new PaymentEvent(PaymentEvent::LATE_SUCCESS, $attempt, $facts));
            }
            if ($fulfillment === PaymentFulfillmentStatus::RecoveryRequired) {
                event(new PaymentEvent(PaymentEvent::RECOVERY_REQUIRED, $attempt, ['reason' => $fulfillmentReason]));
            }
            event(new PaymentVerified($attempt, $late));
        });

        return true;
    }

    /**
     * A verified payment's fulfilment changes — the payment itself does not.
     */
    public function fulfillment(PaymentAttempt $attempt, PaymentFulfillmentStatus $to, ?string $reason, string $source, ?int $adminId = null): bool
    {
        if ($attempt->status !== PaymentStatus::Verified || $to === PaymentFulfillmentStatus::NotApplicable || $attempt->fulfillment_status === $to) {
            return false;
        }
        $from = $attempt->fulfillment_status;
        $attempt->forceFill(['fulfillment_status' => $to->value, 'fulfillment_reason' => $reason, 'fulfillment_changed_at' => now()])->save();
        $this->note($attempt, $source, 'fulfillment_'.strtolower($to->value), ['from' => $from->value, 'reason' => $reason], $adminId);
        if ($to === PaymentFulfillmentStatus::RecoveryRequired) {
            DB::afterCommit(fn () => event(new PaymentEvent(PaymentEvent::RECOVERY_REQUIRED, $attempt, ['reason' => $reason])));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function line(PaymentAttempt $attempt, string $source, string $type, ?PaymentStatus $from, ?PaymentStatus $to, array $detail, ?int $adminId): void
    {
        (new PaymentAttemptEvent)->forceFill([
            'payment_attempt_id' => $attempt->getKey(),
            'type' => mb_substr($type, 0, 40),
            'source' => $source,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'detail' => ($detail = array_filter($detail, fn ($v): bool => $v !== null)) === [] ? null : $detail,
            'admin_user_id' => $adminId,
            'request_id' => ($id = Context::get('request_id')) === null ? null : mb_substr((string) $id, 0, 64),
            'occurred_at' => now(),
        ])->save();
    }
}
