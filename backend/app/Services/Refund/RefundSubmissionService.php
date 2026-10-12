<?php

namespace App\Services\Refund;

use App\Contracts\Payment\PaymentProvider;
use App\Enums\DisputeStatus;
use App\Enums\ProviderFailure;
use App\Enums\RefundStatus;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderRefundRejected;
use App\Models\PaymentDispute;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Models\RefundEvent as Line;
use App\Services\Payment\Data\ProviderRefund;
use App\Services\Payment\Data\ProviderRefundRequest;
use App\Services\Payment\PaymentProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends an APPROVED refund to the provider — once (Module 35).
 *
 *   1. under the locks: APPROVED → SUBMITTING, the receipt / idempotency key of this provider refund is fixed and the
 *      attempt is written down. Committed before anything is sent.
 *   2. outside any transaction: the provider is asked (one call, an explicit timeout, never repeated here).
 *   3. under the locks again: what came back becomes the refund's state.
 *
 *   the provider returns the refund          → REFUNDED (processed), PROCESSING (pending) or FAILED
 *   the provider refuses it                  → FAILED — or, when our books and the provider's disagree or a dispute
 *                                              is in the way, REVIEW_REQUIRED
 *   no answer (timeout, 5xx, a duplicate /   → SUBMISSION_UNKNOWN: the provider may have created it. It is looked
 *   "in progress" answer)                      up by its receipt; it is never sent again blindly.
 *   certainly not carried out (rate limit,   → back to APPROVED, sent again later with the SAME key; after the
 *   provider not usable)                       configured tries a person looks.
 *
 * One submission at a time per refund (a lock), and the key is the same for every try of one provider refund: two
 * workers, a double click or a retry after a timeout cannot produce two refunds.
 */
final class RefundSubmissionService
{
    public function __construct(
        private readonly RefundStore $store,
        private readonly RefundStateMachine $machine,
        private readonly PaymentProviderManager $providers,
        private readonly RefundMetrics $metrics,
    ) {}

    public static function lockKey(int $refundId): string
    {
        return 'refunds:submit:'.$refundId;
    }

    /**
     * @param  bool  $resubmitUnknown  the provider was asked and holds no refund with this receipt: the same request may go again
     */
    public function submit(int $refundId, string $source = Line::SOURCE_JOB, ?int $adminId = null, bool $resubmitUnknown = false): Refund
    {
        $lock = Cache::lock(self::lockKey($refundId), max(30, (int) config('services.payment.timeout') + 30));
        if (! $lock->get()) {
            return Refund::query()->findOrFail($refundId); // somebody else is sending it right now
        }
        try {
            $prepared = DB::transaction(fn (): ?array => $this->prepare($refundId, $source, $adminId, $resubmitUnknown));
            if ($prepared === null) {
                return Refund::query()->findOrFail($refundId);
            }
            [$provider, $request, $attemptId] = $prepared;

            $started = hrtime(true);
            $result = $error = null;
            try {
                $result = $this->providers->call($provider, 'create', fn (): ProviderRefund => $provider->createRefund($request), capability: 'refund');
            } catch (ProviderException $e) {
                $error = $e;
            }
            $ms = (int) round((hrtime(true) - $started) / 1_000_000);
            $this->metrics->record('provider_calls');
            $this->metrics->record('provider_ms_total', max(1, $ms));

            return DB::transaction(fn (): Refund => $this->settle($refundId, $attemptId, $result, $error, $ms, $source, $adminId));
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{0: PaymentProvider, 1: ProviderRefundRequest, 2: int}|null null when there is nothing to send
     */
    private function prepare(int $refundId, string $source, ?int $adminId, bool $resubmitUnknown): ?array
    {
        $lock = $this->store->locked($refundId);
        $refund = $lock->refund;
        $at = CarbonImmutable::now();

        if ($refund->status === RefundStatus::Submitting) {
            // A worker took it and never came back (this lock was free): what the provider did is not known.
            $this->machine->move($lock, RefundStatus::SubmissionUnknown, $source, ['next_action_at' => $at], ['reason' => 'ABANDONED_SUBMISSION'], $adminId);

            return null;
        }
        $again = $resubmitUnknown && in_array($refund->status, [RefundStatus::SubmissionUnknown, RefundStatus::ReviewRequired], true) && $refund->provider_receipt !== null && $refund->provider_refund_reference === null;
        if ($refund->status !== RefundStatus::Approved && ! $again) {
            return null;
        }
        if ($refund->provider_payment_reference === null) {
            $this->machine->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'PAYMENT_REFERENCE_MISSING'], [], $adminId);

            return null;
        }
        if (PaymentDispute::query()->where('payment_attempt_id', $refund->payment_attempt_id)->whereIn('status', DisputeStatus::openValues())->exists()) {
            // Not sent while a dispute is open: the same money could go back twice.
            $this->machine->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'DISPUTE_OPEN'], [], $adminId);

            return null;
        }
        $tries = (int) $refund->submit_attempts + 1;
        $provider = $this->providers->named((string) $refund->provider);
        if ($provider === null) {
            // The provider this payment was made with cannot be used here right now. Nothing was sent.
            $refund->forceFill(['submit_attempts' => $tries])->save();
            $this->notSent($lock, 'PROVIDER_NOT_CONFIGURED', $source, $adminId, $at);

            return null;
        }

        // One provider refund = one receipt = one idempotency key, however often it is sent.
        $generation = $refund->provider_receipt === null ? (int) $refund->generation + 1 : (int) $refund->generation;
        $receipt = $refund->provider_receipt ?? $refund->receiptFor($generation);
        $this->machine->move($lock, RefundStatus::Submitting, $source, [
            'generation' => $generation,
            'provider_receipt' => $receipt,
            'provider_mode' => $provider->mode(),
            'submit_attempts' => $tries,
            'unknown_resubmits' => (int) $refund->unknown_resubmits + ($again ? 1 : 0),
            'failure_category' => null, 'failure_code' => null, 'failure_message' => null, 'retryable' => false,
            'next_action_at' => null,
        ], ['generation' => $generation, 'try' => $tries, 'resubmission' => $again ?: null], $adminId);

        $attempt = new RefundAttempt;
        $attempt->forceFill([
            'refund_id' => $refund->getKey(),
            'generation' => $generation,
            'operation' => RefundAttempt::OP_CREATE,
            'idempotency_key' => $receipt,
            'source' => $source,
            'admin_user_id' => $adminId,
            'outcome' => RefundAttempt::STARTED,
            'request_id' => ($id = Context::get('request_id')) === null ? null : mb_substr((string) $id, 0, 64),
            'started_at' => $at,
        ])->save();

        $request = new ProviderRefundRequest(
            paymentReference: (string) $refund->provider_payment_reference,
            amount: $refund->amount(),
            receipt: $receipt,
            idempotencyKey: $receipt,
            // References only — nothing about the customer.
            notes: array_filter(['refund' => (string) $refund->public_id, 'order' => $refund->order_number, 'reason' => $refund->reason_code->value], fn ($v): bool => $v !== null),
        );

        return [$provider, $request, (int) $attempt->getKey()];
    }

    private function settle(int $refundId, int $attemptId, ?ProviderRefund $result, ?ProviderException $error, int $ms, string $source, ?int $adminId): Refund
    {
        $lock = $this->store->locked($refundId);
        $refund = $lock->refund;
        $at = CarbonImmutable::now();
        $outcome = RefundAttempt::UNKNOWN;
        $failure = null;
        // A webhook may have settled the refund while the answer was on its way: then only the attempt is completed.
        $ours = $refund->status === RefundStatus::Submitting;

        if ($result !== null) {
            $outcome = $this->machine->adopt($lock, $result, $source, $adminId);
            if ($refund->status === RefundStatus::Processing) {
                $refund->forceFill(['next_action_at' => $at->addSeconds(self::firstWait())])->save();
            }
        } elseif ($error instanceof ProviderRefundRejected) {
            $failure = $error->reason;
            $outcome = RefundAttempt::REJECTED;
            if (! $ours) {
                // settled meanwhile
            } elseif ($error->outcomeUnknown()) {
                $outcome = RefundAttempt::UNKNOWN;
                $this->unknown($lock, 'PROVIDER_'.$error->reason, $source, $adminId, $at);
            } elseif ($error->needsReview()) {
                $this->machine->move($lock, RefundStatus::ReviewRequired, $source, [
                    'review_reason' => $error->reason === ProviderRefundRejected::DISPUTE_BLOCK ? 'DISPUTE_OPEN' : 'PROVIDER_BALANCE_MISMATCH',
                    'failure_category' => 'PROVIDER_REJECTED', 'failure_code' => $error->reason, 'failure_message' => self::message($error->reason),
                ], ['provider_reason' => $error->reason], $adminId);
            } else {
                $this->machine->move($lock, RefundStatus::Failed, $source, [
                    'failure_category' => 'PROVIDER_REJECTED', 'failure_code' => $error->reason, 'failure_message' => self::message($error->reason), 'retryable' => $error->retryable(),
                ], ['provider_reason' => $error->reason], $adminId);
            }
        } elseif ($error !== null) {
            $failure = $error->failure->value;
            if (in_array($error->failure, [ProviderFailure::Timeout, ProviderFailure::Unavailable], true)) {
                // The request may have arrived. Nothing is assumed — not "refunded", not "failed".
                $outcome = RefundAttempt::UNKNOWN;
                $ours && $this->unknown($lock, 'PROVIDER_'.$error->failure->value, $source, $adminId, $at);
            } elseif (in_array($error->failure, [ProviderFailure::RateLimited, ProviderFailure::Misconfigured], true)) {
                // Refused before it was read: certainly no refund was created.
                $outcome = RefundAttempt::NOT_SENT;
                $ours && $this->notSent($lock, 'PROVIDER_'.$error->failure->value, $source, $adminId, $at);
            } elseif ($error->failure === ProviderFailure::NotFound) {
                $outcome = RefundAttempt::REJECTED;
                $ours && $this->machine->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'PROVIDER_PAYMENT_NOT_FOUND', 'failure_category' => 'PROVIDER_REJECTED', 'failure_code' => 'PAYMENT_NOT_FOUND', 'failure_message' => 'The provider has no such payment.'], [], $adminId);
            } else {
                $outcome = RefundAttempt::REJECTED;
                $ours && $this->machine->move($lock, RefundStatus::Failed, $source, ['failure_category' => 'PROVIDER_REJECTED', 'failure_code' => ProviderRefundRejected::REJECTED, 'failure_message' => self::message(ProviderRefundRejected::REJECTED), 'retryable' => false], [], $adminId);
            }
        }

        RefundAttempt::query()->whereKey($attemptId)->update([
            'outcome' => $outcome,
            'provider_status' => $result === null ? null : mb_substr($result->providerStatus, 0, 24),
            'provider_refund_reference' => $outcome === RefundStateMachine::OUTCOME_MISMATCH ? null : $result?->reference,
            'failure_code' => $failure === null ? null : mb_substr($failure, 0, 48),
            'latency_ms' => $ms,
            'finished_at' => $at,
        ]);
        Log::info('refund.submission', array_filter([
            'request_id' => Context::get('request_id'), 'refund' => (string) $refund->public_id, 'payment' => (string) $refund->payment_public_id, 'order_number' => $refund->order_number,
            'amount_minor' => $refund->amountMinor(), 'currency' => (string) $refund->currency, 'provider' => (string) $refund->provider, 'provider_refund' => $refund->provider_refund_reference,
            'outcome' => $outcome, 'failure' => $failure, 'status' => $refund->status->value, 'provider_ms' => $ms,
        ], fn ($v): bool => $v !== null));

        return $refund;
    }

    private function unknown(RefundLock $lock, string $why, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        $settle = (int) config('services.payment.timeout') + (int) config('refunds.submission.settle_margin_seconds');
        $this->machine->move($lock, RefundStatus::SubmissionUnknown, $source, ['next_action_at' => $at->addSeconds(max(1, $settle))], ['reason' => $why], $adminId);
    }

    /**
     * Certainly not carried out: the refund stays approved and is sent again later, with the same key. Not forever.
     */
    private function notSent(RefundLock $lock, string $why, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        $refund = $lock->refund;
        $waits = array_values(array_filter((array) config('refunds.submission.not_sent_backoff_seconds'), 'is_numeric')) ?: [60];
        $tries = (int) $refund->submit_attempts;
        if ($tries > count($waits)) {
            $this->machine->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'PROVIDER_UNAVAILABLE'], ['reason' => $why, 'tries' => $tries], $adminId);

            return;
        }
        $next = $at->addSeconds((int) $waits[max(0, $tries - 1)]);
        if ($refund->status === RefundStatus::Submitting) {
            $this->machine->move($lock, RefundStatus::Approved, $source, ['next_action_at' => $next], ['reason' => $why, 'not_sent' => true], $adminId);
        } else {
            $refund->forceFill(['next_action_at' => $next])->save();
            $this->machine->note($refund, $source, 'not_sent', ['reason' => $why, 'tries' => $tries], $adminId);
        }
    }

    public static function firstWait(): int
    {
        $waits = array_values(array_filter((array) config('refunds.reconcile.backoff_seconds'), 'is_numeric')) ?: [60];

        return max(1, (int) $waits[0]);
    }

    /** What an administrator reads. Our own sentence — never the provider's. */
    public static function message(string $reason): string
    {
        return match ($reason) {
            ProviderRefundRejected::INSUFFICIENT_BALANCE => 'The provider account does not hold enough balance for this refund right now.',
            ProviderRefundRejected::PAYMENT_NOT_CAPTURED => 'The provider says this payment was not captured.',
            ProviderRefundRejected::METHOD_NOT_SUPPORTED => 'The provider cannot refund this payment method.',
            ProviderRefundRejected::PARTIAL_NOT_SUPPORTED => 'The provider cannot refund a part of this payment — only all of it.',
            ProviderRefundRejected::REFUNDS_DISABLED => 'Refunds are not enabled on the provider account.',
            ProviderRefundRejected::PAYMENT_TOO_OLD => 'The provider no longer refunds a payment this old.',
            ProviderRefundRejected::AMOUNT_EXCEEDS_AVAILABLE => 'The provider says less than this amount is left to refund on the payment.',
            ProviderRefundRejected::ALREADY_REFUNDED => 'The provider says this payment was refunded in full already.',
            ProviderRefundRejected::DISPUTE_BLOCK => 'The provider blocks refunds on this payment while a dispute is investigated.',
            default => 'The provider refused this refund.',
        };
    }
}
