<?php

namespace App\Services\Refund;

use App\Enums\ProviderRefundState;
use App\Enums\RefundStatus;
use App\Events\Refund\RefundEvent;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\PaymentRefundBalance;
use App\Models\Refund;
use App\Models\RefundEvent as Line;
use App\Services\Payment\Data\ProviderRefund;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only place a refund changes its state — and the only place the payment's refund balance moves.
 *
 * Every step is checked against RefundStatus::allows(), moves the amount between "reserved" and "refunded" on the
 * payment's balance in the same transaction, is written to the refund's timeline with who or what caused it, updates
 * the order's derived refund status, and is announced after the commit. A signal that asks for a step the machine
 * does not allow — a webhook that arrives late, a second confirmation — changes nothing and is noted, never an error.
 *
 * REFUNDED has one door, adopt(): it opens only for the provider's own record of THIS refund — the same payment,
 * the same amount and currency, our receipt. Nobody can reach REFUNDED with a status they were merely told.
 *
 * The caller holds the locks (RefundStore::locked) inside a transaction.
 */
final class RefundStateMachine
{
    public const OUTCOME_PROCESSED = 'PROCESSED';

    public const OUTCOME_PENDING = 'PENDING';

    public const OUTCOME_FAILED = 'FAILED';

    public const OUTCOME_MISMATCH = 'MISMATCH';

    public function __construct(private readonly RefundProjection $projection) {}

    /**
     * A new refund enters its first state (REQUIRED or REQUESTED): it is saved and its amount is held against the
     * payment. The caller has checked that the amount is available; the balance's own rule is the last word.
     *
     * @param  array<string, mixed>  $detail
     *
     * @throws ApiException 409 refund_amount_exceeds_available
     */
    public function open(Refund $refund, PaymentRefundBalance $balance, ?Order $order, string $source, array $detail = [], ?int $adminId = null, ?string $actorPublicId = null): Refund
    {
        if (! in_array($refund->status, [RefundStatus::Required, RefundStatus::Requested], true)) {
            throw new LogicException('A refund starts as REQUIRED or REQUESTED.');
        }
        $amount = $refund->amountMinor();
        if ($amount < 1 || $amount > $balance->availableMinor()) {
            throw self::exceeds($balance, $amount);
        }
        $balance->forceFill(['reserved_minor' => (int) $balance->reserved_minor + $amount, 'version' => (int) $balance->version + 1])->save();
        $refund->save();
        $this->line($refund, $source, strtolower($refund->status->value), null, $refund->status, $detail + ['amount_minor' => $amount, 'currency' => (string) $refund->currency], $adminId, $actorPublicId);
        $this->projection->project($order, $balance, $refund, null, $refund->status);
        $name = $refund->status === RefundStatus::Required ? RefundEvent::REQUIRED : RefundEvent::REQUESTED;
        DB::afterCommit(fn () => event(new RefundEvent($name, $refund, ['source' => $source])));

        return $refund;
    }

    /**
     * A line on the timeline without a step.
     *
     * @param  array<string, mixed>  $detail  references and codes only
     */
    public function note(Refund $refund, string $source, string $type, array $detail = [], ?int $adminId = null, ?string $actorPublicId = null): void
    {
        $this->line($refund, $source, $type, null, null, $detail, $adminId, $actorPublicId);
    }

    /**
     * Moves the refund. False when it is already there or the step is not allowed (noted, nothing changed).
     *
     * @param  array<string, mixed>  $fill  further columns to write with the step
     * @param  array<string, mixed>  $detail
     *
     * @throws ApiException 409 refund_amount_exceeds_available when a refund that gave its amount back wants it again and it is gone
     */
    public function move(RefundLock $lock, RefundStatus $to, string $source, array $fill = [], array $detail = [], ?int $adminId = null, ?string $actorPublicId = null): bool
    {
        $refund = $lock->refund;
        $balance = $lock->balance;
        $from = $refund->status;
        if ($from === $to) {
            return false;
        }
        if (! $from->allows($to)) {
            $this->note($refund, $source, 'step_refused', ['from' => $from->value, 'to' => $to->value] + $detail, $adminId, $actorPublicId);

            return false;
        }
        if ($to === RefundStatus::Refunded && ($fill['provider_refund_reference'] ?? $refund->provider_refund_reference) === null) {
            throw new LogicException('A refund becomes REFUNDED only with the provider\'s refund reference.');
        }

        $before = $refund->amountMinor();
        $after = (int) ($fill['approved_amount_minor'] ?? $refund->approved_amount_minor ?? $refund->requested_amount_minor);
        $reserved = (int) $balance->reserved_minor - ($from->reserves() ? $before : 0);
        if ($reserved < 0) {
            throw new LogicException('The refund balance does not hold what this refund reserved.');
        }
        $refunded = (int) $balance->refunded_minor;
        if (! $from->reserves() && ($to->reserves() || $to === RefundStatus::Refunded) && $reserved + $refunded + (int) $balance->charged_back_minor + $after > (int) $balance->paid_minor) {
            // It gave its amount back (FAILED) and something else took it in the meantime.
            throw self::exceeds($balance, $after);
        }
        $reserved += $to->reserves() ? $after : 0;
        $refunded += $to === RefundStatus::Refunded ? $after : 0;
        $balance->forceFill(['reserved_minor' => $reserved, 'refunded_minor' => $refunded, 'version' => (int) $balance->version + 1])->save();

        $now = now();
        $stamp = match ($to) {
            RefundStatus::Submitting => ['submitted_at' => $now],
            RefundStatus::Processing => ['processing_at' => $refund->processing_at ?? $now],
            RefundStatus::Refunded => ['completed_at' => $now, 'failure_category' => null, 'failure_code' => null, 'failure_message' => null, 'retryable' => false, 'review_reason' => null, 'next_action_at' => null],
            RefundStatus::Failed => ['failed_at' => $now, 'next_action_at' => null],
            RefundStatus::Cancelled => ['cancelled_at' => $now, 'next_action_at' => null],
            RefundStatus::ReviewRequired => ['next_action_at' => null],
            RefundStatus::Approved => ['review_reason' => null],
            default => [],
        };
        $refund->forceFill($fill + $stamp + ['status' => $to->value, 'version' => (int) $refund->version + 1])->save();
        $this->line($refund, $source, strtolower($to->value), $from, $to, $detail, $adminId, $actorPublicId);
        $this->projection->project($lock->order, $balance, $refund, $from, $to);

        $name = match ($to) {
            RefundStatus::Approved => RefundEvent::APPROVED,
            RefundStatus::Submitting => RefundEvent::SUBMITTED,
            RefundStatus::SubmissionUnknown => RefundEvent::SUBMISSION_UNKNOWN,
            RefundStatus::Processing => RefundEvent::PROCESSING,
            RefundStatus::Refunded => RefundEvent::COMPLETED,
            RefundStatus::Failed => RefundEvent::FAILED,
            RefundStatus::Cancelled => RefundEvent::CANCELLED,
            RefundStatus::ReviewRequired => RefundEvent::REVIEW_REQUIRED,
            RefundStatus::Required => RefundEvent::REQUIRED,
            RefundStatus::Requested => RefundEvent::REQUESTED,
        };
        $facts = array_filter(['from' => $from->value, 'source' => $source, 'failure' => $refund->failure_code, 'review' => $refund->review_reason], fn ($v): bool => $v !== null);
        DB::afterCommit(fn () => event(new RefundEvent($name, $refund, $facts)));

        return true;
    }

    /**
     * What the provider holds for this refund becomes its state — after it has been checked to BE this refund.
     * Returns the outcome for the attempt log. Never moves a refund backwards: a stale "pending" cannot undo a
     * confirmed refund, and nothing a provider says later re-opens a REFUNDED one.
     */
    public function adopt(RefundLock $lock, ProviderRefund $found, string $source, ?int $adminId = null): string
    {
        $refund = $lock->refund;
        $mismatch = match (true) {
            $found->reference === '' => 'REFERENCE',
            $found->paymentReference !== (string) $refund->provider_payment_reference => 'PAYMENT',
            $found->amount->currency !== (string) $refund->currency => 'CURRENCY',
            $found->amount->amount !== $refund->amountMinor() => 'AMOUNT',
            $refund->provider_refund_reference !== null && $refund->provider_refund_reference !== $found->reference => 'REFERENCE',
            $found->receipt !== null && $refund->provider_receipt !== null && $found->receipt !== $refund->provider_receipt => 'RECEIPT',
            Refund::query()->where('provider', $refund->provider)->where('provider_refund_reference', $found->reference)->whereKeyNot($refund->getKey())->exists() => 'REFERENCE_TAKEN',
            default => null,
        };
        if ($mismatch !== null) {
            // A provider refund that is not this refund is never attached to it, whoever says so.
            $this->note($refund, $source, 'provider_refund_mismatch', ['what' => $mismatch, 'refund' => $found->reference], $adminId);
            Log::warning('refund.provider_mismatch', ['refund' => (string) $refund->public_id, 'payment' => (string) $refund->payment_public_id, 'what' => $mismatch]);
            if ($refund->status->withProvider()) {
                $this->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'PROVIDER_REFUND_MISMATCH'], ['what' => $mismatch], $adminId);
            }

            return self::OUTCOME_MISMATCH;
        }

        $seen = ['provider_refund_reference' => $found->reference, 'provider_status' => mb_substr($found->providerStatus, 0, 24), 'provider_trace_reference' => $found->traceReference ?? $refund->provider_trace_reference];
        $detail = ['refund' => $found->reference, 'provider_status' => $found->providerStatus];

        if ($found->state === ProviderRefundState::Processed) {
            if ($refund->status === RefundStatus::Refunded) {
                $refund->forceFill(['provider_status' => $seen['provider_status'], 'provider_trace_reference' => $seen['provider_trace_reference']])->save();

                return self::OUTCOME_PROCESSED; // confirmed already: nothing happens twice
            }
            if ($refund->status === RefundStatus::Failed && $refund->amountMinor() > $lock->balance->availableMinor()) {
                // It was given up, its amount was used again, and now the provider says it went through after all.
                $this->note($refund, $source, 'late_success_without_balance', $detail, $adminId);
                Log::critical('refund.late_success_without_balance', ['refund' => (string) $refund->public_id, 'payment' => (string) $refund->payment_public_id]);

                return self::OUTCOME_MISMATCH;
            }
            $this->move($lock, RefundStatus::Refunded, $source, $seen, $detail, $adminId);

            return self::OUTCOME_PROCESSED;
        }

        if ($found->state === ProviderRefundState::Failed) {
            if ($refund->status === RefundStatus::Refunded) {
                // Never undone here: a provider that reverses a completed refund is a case for a person.
                $this->note($refund, $source, 'provider_reports_failed_after_refunded', $detail, $adminId);
                Log::critical('refund.failed_after_refunded', ['refund' => (string) $refund->public_id, 'payment' => (string) $refund->payment_public_id]);

                return self::OUTCOME_FAILED;
            }
            $this->move($lock, RefundStatus::Failed, $source, $seen + [
                'failure_category' => 'PROVIDER_FAILED', 'failure_code' => 'REFUND_FAILED', 'failure_message' => 'The payment provider could not complete this refund.', 'retryable' => true,
            ], $detail, $adminId);

            return self::OUTCOME_FAILED;
        }

        // Pending, or a status this application does not know: the provider has it, nothing more is concluded.
        if (in_array($refund->status, [RefundStatus::Submitting, RefundStatus::SubmissionUnknown, RefundStatus::ReviewRequired], true)) {
            $this->move($lock, RefundStatus::Processing, $source, $seen, $detail, $adminId);
        } elseif ($refund->status === RefundStatus::Processing) {
            $refund->forceFill($seen)->save();
        } else {
            $this->note($refund, $source, 'stale_provider_state', $detail + ['status' => $refund->status->value], $adminId);
        }

        return self::OUTCOME_PENDING;
    }

    private static function exceeds(PaymentRefundBalance $balance, int $asked): ApiException
    {
        return ApiException::conflict('refund_amount_exceeds_available', 'This amount is more than what can still be refunded for this payment.', [
            'requested_minor' => $asked, 'available_minor' => $balance->availableMinor(), 'currency' => (string) $balance->currency,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function line(Refund $refund, string $source, string $type, ?RefundStatus $from, ?RefundStatus $to, array $detail, ?int $adminId, ?string $actorPublicId): void
    {
        (new Line)->forceFill([
            'refund_id' => $refund->getKey(),
            'type' => mb_substr($type, 0, 40),
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'source' => $source,
            'actor_public_id' => $actorPublicId,
            'admin_user_id' => $adminId,
            'detail' => ($detail = array_filter($detail, fn ($v): bool => $v !== null)) === [] ? null : $detail,
            'request_id' => ($id = Context::get('request_id')) === null ? null : mb_substr((string) $id, 0, 64),
            'occurred_at' => now(),
        ])->save();
    }
}
