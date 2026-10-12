<?php

namespace App\Services\Refund;

use App\Enums\OrderPaymentState;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Jobs\Refund\SubmitRefundJob;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\Refund;
use App\Models\RefundEvent as Line;
use App\Services\Audit\AuditRecorder;
use App\Services\Payment\PaymentAttemptStore;
use App\Services\Payment\PaymentStateMachine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refunds, from the condition that owes money to the provider's confirmation (Module 35).
 *
 *   a paid order is rejected or cancelled   → requireForOrder(): ONE refund, REQUIRED, for what is left of the payment
 *   an administrator asks for a refund      → requestForOrder() / requestForPayment(): REQUESTED, the amount checked here
 *   the policy or a person approves         → APPROVED          (who asked is not who approved)
 *   it is sent to the provider              → RefundSubmissionService
 *   the provider's record settles it        → RefundReconciliationService
 *
 * Every amount is decided here, from the payment and from what was already refunded or is on its way: a client's
 * amount is a request that is checked, never a fact. The refund never changes the payment (it stays VERIFIED) or the
 * order's state. Every action of a person needs its own permission, a reason where money or a decision is involved,
 * and is audited.
 */
final class RefundService
{
    public function __construct(
        private readonly RefundStore $store,
        private readonly RefundStateMachine $machine,
        private readonly RefundEligibilityService $eligibility,
        private readonly RefundPolicyService $policy,
        private readonly RefundSubmissionService $submission,
        private readonly RefundReconciliationService $reconciliation,
        private readonly PaymentAttemptStore $attempts,
        private readonly PaymentStateMachine $payments,
        private readonly AuditRecorder $audit,
        private readonly RefundMetrics $metrics,
    ) {}

    /* ------------------------------------------------------------------ creation */

    /**
     * A paid order ended without being fulfilled: what is left of its payment is owed back. Called by the order
     * workflow inside the transaction that ends the order (the order's row is locked). The same order never gets a
     * second such refund, however often the condition is reported. $as is REQUIRED (money is owed) or REQUESTED (a
     * cancellation whose financial effect a person decides).
     */
    public function requireForOrder(Order $order, RefundReason $reason, ?string $detail, string $byType, ?string $byPublicId, ?CarbonInterface $at = null, RefundStatus $as = RefundStatus::Required): ?Refund
    {
        if ($order->payment_state !== OrderPaymentState::Paid) {
            return null; // nothing was charged: there is nothing to refund, and no provider is asked for anything
        }
        $key = 'order:'.$order->public_id.':closeout';
        $existing = Refund::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $attempt = $order->payment_attempt_id === null ? null : PaymentAttempt::query()->find($order->payment_attempt_id);
        if ($attempt === null) {
            Log::critical('refund.payment_missing', ['order_number' => (string) $order->order_number]);

            return null;
        }
        $balance = $this->store->balance($attempt);
        $amount = $balance->availableMinor();
        if ($amount < 1) {
            return null; // everything was refunded already, or is on its way
        }
        $refund = $this->build($attempt, $order, $reason, $detail, null, $byType, $byPublicId, $amount, $amount === (int) $balance->paid_minor ? Refund::TYPE_FULL : Refund::TYPE_PARTIAL, $as, $key, $at);

        return $this->machine->open($refund, $balance, $order, $byType === Refund::BY_SYSTEM ? Line::SOURCE_SYSTEM : $byType, ['reason' => $reason->value, 'detail' => $detail], null, $byPublicId);
    }

    /**
     * The policy looks at a refund the system owes: approved by the policy and sent, left for a person, or — when the
     * records say it cannot go back as things stand — handed to a person as REVIEW_REQUIRED. Runs once per refund.
     */
    public function applyPolicy(int $refundId): Refund
    {
        [$refund, $send] = DB::transaction(function () use ($refundId): array {
            $lock = $this->store->locked($refundId);
            $refund = $lock->refund;
            if ($refund->status !== RefundStatus::Required || $refund->policy_decision !== null) {
                return [$refund, false];
            }
            $decision = $this->policy->decide($refund, $this->eligibilityOf($lock));
            $refund->forceFill(['policy_decision' => $decision['outcome'], 'policy_reason' => mb_substr($decision['reason'], 0, 64)])->save();
            $this->machine->note($refund, Line::SOURCE_SYSTEM, 'policy_'.strtolower($decision['outcome']), ['reason' => $decision['reason']]);
            if ($decision['outcome'] === RefundPolicyService::NOT_ELIGIBLE) {
                $this->machine->move($lock, RefundStatus::ReviewRequired, Line::SOURCE_SYSTEM, ['review_reason' => mb_substr($decision['reason'], 0, 48)], ['reason' => $decision['reason']]);

                return [$refund, false];
            }
            if ($decision['outcome'] === RefundPolicyService::AUTO_APPROVE) {
                $this->machine->move($lock, RefundStatus::Approved, Line::SOURCE_SYSTEM, [
                    'approved_amount_minor' => (int) $refund->requested_amount_minor, 'approval_mode' => Refund::APPROVAL_AUTO, 'approved_at' => now(), 'approval_note' => 'Approved by the refund policy.',
                ], ['reason' => $decision['reason']]);

                return [$refund, true];
            }

            return [$refund, false];
        });
        if ($send) {
            SubmitRefundJob::dispatch((int) $refund->getKey());
        }

        return $refund;
    }

    /**
     * An administrator asks for a refund of an order that is over: all that is left (FULL) or a part (PARTIAL). The
     * request is REQUESTED — it moves no money until it is approved and executed.
     *
     * @param  array{type: string, amount_minor?: int|null, currency?: string|null, reason_code: string, note?: string|null}  $input
     *
     * @throws ApiException 409 refund_not_eligible / refund_payment_not_verified / refund_order_active / refund_dispute_conflict /
     *                      refund_already_processing / refund_already_completed / refund_amount_exceeds_available;
     *                      422 refund_amount_invalid / refund_currency_mismatch
     */
    public function requestForOrder(Order $order, AdminUser $admin, array $input): Refund
    {
        return DB::transaction(function () use ($order, $admin, $input): Refund {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $attempt = $locked->payment_attempt_id === null ? null : PaymentAttempt::query()->find($locked->payment_attempt_id);
            $balance = $attempt === null ? null : $this->store->balance($attempt);
            $eligibility = $this->eligibility->forOrder($locked, $balance);
            if (! $eligibility->eligible || $attempt === null || $balance === null) {
                throw $eligibility->refusal();
            }
            [$amount, $type] = $this->amount($input, $eligibility);
            $reason = RefundReason::from((string) $input['reason_code']);
            $refund = $this->build($attempt, $locked, $reason, null, $input['note'] ?? null, Refund::BY_ADMIN, (string) $admin->public_id, $amount, $type, RefundStatus::Requested, null, null);
            $this->machine->open($refund, $balance, $locked, Line::SOURCE_ADMIN, ['reason' => $reason->value], (int) $admin->getKey(), (string) $admin->public_id);
            $this->audit->record('refund.requested', $refund, $admin, ['amount_minor' => ['from' => null, 'to' => $amount], 'type' => ['from' => null, 'to' => $type]], $input['note'] ?? $reason->value, $refund->market_id);

            return $refund;
        });
    }

    /**
     * An administrator asks for the refund of a payment that never became an order: a verified payment whose order
     * cannot be made, or a second payment for a checkout that was already paid. Always the whole payment; the reason
     * is read from the payment, not chosen.
     *
     * @throws ApiException as requestForOrder
     */
    public function requestForPayment(PaymentAttempt $attempt, AdminUser $admin, ?string $note): Refund
    {
        return DB::transaction(function () use ($attempt, $admin, $note): Refund {
            // The attempt's row is locked: an order cannot be created from this payment while this decides.
            [$locked] = $this->attempts->locked((int) $attempt->getKey());
            $balance = $this->store->balance($locked);
            [$eligibility, $reason] = $this->eligibility->forPayment($locked, $balance);
            if (! $eligibility->eligible || $reason === null) {
                throw $eligibility->refusal();
            }
            $amount = $eligibility->availableMinor();
            $refund = $this->build($locked, null, $reason, null, $note, Refund::BY_ADMIN, (string) $admin->public_id, $amount, $amount === (int) $balance->paid_minor ? Refund::TYPE_FULL : Refund::TYPE_PARTIAL, RefundStatus::Requested, null, null);
            $this->machine->open($refund, $balance, null, Line::SOURCE_ADMIN, ['reason' => $reason->value], (int) $admin->getKey(), (string) $admin->public_id);
            $this->payments->note($locked, PaymentAttemptEvent::SOURCE_ADMIN, 'refund_requested', ['refund' => (string) $refund->public_id, 'reason' => $reason->value], (int) $admin->getKey());
            $this->audit->record('refund.requested', $refund, $admin, ['amount_minor' => ['from' => null, 'to' => $amount]], $note ?? $reason->value, $refund->market_id);

            return $refund;
        });
    }

    /* ------------------------------------------------------------------ a person's decisions */

    /**
     * Approves a refund for the amount it was asked for. Asked twice, it is approved once.
     *
     * @throws ApiException 409 refund_version_conflict / refund_invalid_transition / refund_dispute_conflict / …; 403 refund_not_authorized
     */
    public function approve(Refund $refund, AdminUser $admin, ?string $note = null, ?int $expectedVersion = null): Refund
    {
        return DB::transaction(function () use ($refund, $admin, $note, $expectedVersion): Refund {
            $lock = $this->store->locked((int) $refund->getKey());
            $current = $lock->refund;
            if ($current->status === RefundStatus::Approved || $current->status->withProvider() || $current->status === RefundStatus::Refunded) {
                return $current; // approved already
            }
            self::sameVersion($current, $expectedVersion);
            $neverSent = $current->status === RefundStatus::ReviewRequired && (int) $current->generation === 0;
            if (! $current->status->awaitsApproval() && ! $neverSent) {
                throw self::invalid($current, 'approved');
            }
            if ((bool) config('refunds.policy.four_eyes') && $current->requested_by_type === Refund::BY_ADMIN && $current->requested_by_public_id === (string) $admin->public_id) {
                throw new ApiException(403, 'refund_not_authorized', 'A refund has to be approved by somebody other than the person who asked for it.');
            }
            $eligibility = $this->eligibilityOf($lock);
            // Its own amount is held already: "nothing left" does not speak against the refund that holds it.
            if (! $eligibility->eligible && ! in_array($eligibility->code, ['refund_already_processing', 'refund_already_completed'], true)) {
                throw $eligibility->refusal();
            }
            $from = $current->status->value;
            $this->machine->move($lock, RefundStatus::Approved, Line::SOURCE_ADMIN, [
                'approved_amount_minor' => (int) $current->requested_amount_minor,
                'approval_mode' => Refund::APPROVAL_MANUAL,
                'approved_by_admin_id' => $admin->getKey(),
                'approved_at' => now(),
                'approval_note' => $note === null ? null : mb_substr($note, 0, 500),
                'policy_decision' => $current->policy_decision ?? RefundPolicyService::MANUAL_REVIEW,
            ], [], (int) $admin->getKey(), (string) $admin->public_id);
            $this->audit->record('refund.approved', $current, $admin, ['status' => ['from' => $from, 'to' => RefundStatus::Approved->value], 'amount_minor' => ['from' => null, 'to' => (int) $current->requested_amount_minor]], $note, $current->market_id);

            return $current;
        });
    }

    /**
     * Turns a refund down, or closes one that will not be made — with a reason. Possible only while nothing of it can
     * be with the provider: a refund that was sent is never "cancelled" here (the provider cannot cancel it either).
     *
     * @throws ApiException 409 refund_already_processing / refund_already_completed / refund_version_conflict
     */
    public function reject(Refund $refund, AdminUser $admin, string $reason, ?int $expectedVersion = null): Refund
    {
        return DB::transaction(function () use ($refund, $admin, $reason, $expectedVersion): Refund {
            $lock = $this->store->locked((int) $refund->getKey());
            $current = $lock->refund;
            if ($current->status === RefundStatus::Cancelled) {
                return $current;
            }
            self::sameVersion($current, $expectedVersion);
            if ($current->status === RefundStatus::Refunded) {
                throw ApiException::conflict('refund_already_completed', 'This refund was completed by the provider and cannot be undone.');
            }
            $closable = $current->status->awaitsApproval() || in_array($current->status, [RefundStatus::Approved, RefundStatus::Failed], true)
                || ($current->status === RefundStatus::ReviewRequired && (int) $current->generation === 0);
            if (! $closable) {
                throw ApiException::conflict('refund_already_processing', 'This refund was sent to the provider. It cannot be cancelled; the provider\'s record decides how it ends.', ['status' => $current->status->value]);
            }
            $from = $current->status->value;
            $this->machine->move($lock, RefundStatus::Cancelled, Line::SOURCE_ADMIN, ['closed_by_admin_id' => $admin->getKey(), 'closed_note' => mb_substr($reason, 0, 500)], [], (int) $admin->getKey(), (string) $admin->public_id);
            $this->audit->record('refund.rejected', $current, $admin, ['status' => ['from' => $from, 'to' => RefundStatus::Cancelled->value]], $reason, $current->market_id);

            return $current;
        });
    }

    /**
     * Sends an approved refund to the provider. A second click finds it on its way and changes nothing.
     *
     * @throws ApiException 409 refund_not_approved
     */
    public function execute(Refund $refund, AdminUser $admin, ?string $reason = null): Refund
    {
        $current = $refund->refresh();
        if ($current->status->withProvider() || $current->status === RefundStatus::Refunded) {
            return $current; // sent already
        }
        if ($current->status !== RefundStatus::Approved) {
            throw ApiException::conflict('refund_not_approved', 'Only an approved refund can be sent to the provider.', ['status' => $current->status->value]);
        }
        $this->audit->record('refund.executed', $current, $admin, ['amount_minor' => ['from' => null, 'to' => $current->amountMinor()]], $reason, $current->market_id);

        return $this->submission->submit((int) $current->getKey(), Line::SOURCE_ADMIN, (int) $admin->getKey());
    }

    /**
     * Tries again what can safely be tried again:
     *  - a refund the provider definitively failed in a way a new attempt may fix → a NEW provider refund (new receipt
     *    and key), after its amount is held again;
     *  - a refund whose submission was never answered → the provider is asked first; only when it holds nothing is the
     *    SAME request sent again. While the provider cannot be asked, nothing is sent.
     *
     * @throws ApiException 409 refund_failed / refund_invalid_transition / refund_amount_exceeds_available / refund_dispute_conflict
     */
    public function retry(Refund $refund, AdminUser $admin, string $reason): Refund
    {
        $mode = DB::transaction(function () use ($refund, $admin, $reason): string {
            $lock = $this->store->locked((int) $refund->getKey());
            $current = $lock->refund;
            if ($current->status === RefundStatus::Failed) {
                if (! (bool) $current->retryable) {
                    throw ApiException::conflict('refund_failed', 'The provider refused this refund in a way a new attempt cannot fix. Close it; if money is still owed, ask for a new refund.', ['failure_code' => $current->failure_code]);
                }
                $eligibility = $this->eligibilityOf($lock);
                if ($eligibility->disputeOpen) {
                    throw $eligibility->refusal();
                }
                // A new provider refund: the failed one keeps its place in the attempt history.
                $this->machine->move($lock, RefundStatus::Approved, Line::SOURCE_ADMIN, [
                    'provider_receipt' => null, 'provider_refund_reference' => null, 'provider_status' => null, 'provider_trace_reference' => null,
                    'failure_category' => null, 'failure_code' => null, 'failure_message' => null, 'retryable' => false,
                    'unknown_resubmits' => 0, 'reconcile_runs' => 0, 'submit_attempts' => 0, 'next_action_at' => null,
                ], ['retry' => true], (int) $admin->getKey(), (string) $admin->public_id);
                $mode = 'submit';
            } elseif ($current->status === RefundStatus::ReviewRequired && $current->provider_receipt !== null) {
                $mode = 'reconcile';
            } elseif ($current->status === RefundStatus::SubmissionUnknown) {
                $mode = 'reconcile';
            } else {
                throw self::invalid($current, 'tried again');
            }
            $this->audit->record('refund.retried', $current, $admin, ['mode' => ['from' => null, 'to' => $mode]], $reason, $current->market_id);

            return $mode;
        });

        return $mode === 'submit'
            ? $this->submission->submit((int) $refund->getKey(), Line::SOURCE_ADMIN, (int) $admin->getKey())
            : $this->reconciliation->reconcile($refund, Line::SOURCE_ADMIN, null, (int) $admin->getKey(), resubmit: true);
    }

    /**
     * Asks the provider again. Whatever comes of it is the provider's word, not the administrator's.
     */
    public function reconcile(Refund $refund, AdminUser $admin, string $reason): Refund
    {
        $before = $refund->status->value;
        $after = $this->reconciliation->reconcile($refund, Line::SOURCE_ADMIN, null, (int) $admin->getKey());
        $this->audit->record('refund.reconciled', $after, $admin, ['status' => ['from' => $before, 'to' => $after->status->value]], $reason, $after->market_id);

        return $after;
    }

    /* ------------------------------------------------------------------ the scheduled safety net */

    /**
     * What an after-commit step may have lost: a refund the system owes that the policy never looked at, and an
     * approved refund that is due to be sent (approved by the policy, or waiting after a try that was not carried out).
     *
     * @return array{policy: int, submitted: int}
     */
    public function sweep(?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $policy = $submitted = 0;
        foreach (Refund::query()->where('status', RefundStatus::Required->value)->whereNull('policy_decision')->where('requested_at', '<=', $at->subSeconds(30))->orderBy('id')->limit(100)->pluck('id') as $id) {
            $this->applyPolicy((int) $id);
            $policy++;
        }
        $due = Refund::query()->where('status', RefundStatus::Approved->value)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('next_action_at')->where('next_action_at', '<=', $at))
                ->orWhere(fn ($w) => $w->whereNull('next_action_at')->where('approval_mode', Refund::APPROVAL_AUTO)->where('approved_at', '<=', $at->subSeconds(60))))
            ->orderBy('id')->limit(100)->pluck('id');
        foreach ($due as $id) {
            SubmitRefundJob::dispatch((int) $id);
            $submitted++;
        }

        return ['policy' => $policy, 'submitted' => $submitted];
    }

    /* ------------------------------------------------------------------ internals */

    private function eligibilityOf(RefundLock $lock): RefundEligibility
    {
        if ($lock->order !== null) {
            return $this->eligibility->forOrder($lock->order, $lock->balance);
        }
        $attempt = PaymentAttempt::query()->findOrFail($lock->refund->payment_attempt_id);

        return $this->eligibility->forPayment($attempt, $lock->balance)[0];
    }

    /**
     * The amount of a request, checked. FULL is what is left — not blindly what was paid. A PARTIAL amount is the
     * caller's wish: it has to be a positive whole number of minor units, in the payment's currency, and available.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: int, 1: string}
     */
    private function amount(array $input, RefundEligibility $eligibility): array
    {
        $currency = isset($input['currency']) ? strtoupper((string) $input['currency']) : null;
        if ($currency !== null && $currency !== $eligibility->currency) {
            throw new ApiException(422, 'refund_currency_mismatch', 'A refund is made in the currency of the payment ('.$eligibility->currency.').', ['currency' => $eligibility->currency]);
        }
        if (($input['type'] ?? null) === Refund::TYPE_FULL) {
            return [$eligibility->availableMinor(), Refund::TYPE_FULL];
        }
        $amount = $input['amount_minor'] ?? null;
        if (! is_int($amount) || $amount < 1) {
            throw new ApiException(422, 'refund_amount_invalid', 'A partial refund needs an amount greater than zero, in minor units.', ['fields' => ['amount_minor' => ['The amount must be a whole number of minor units greater than zero.']]]);
        }
        if ($amount > $eligibility->availableMinor()) {
            $this->metrics->record('over_refund_blocked');
            Log::warning('refund.over_refund_blocked', ['requested_minor' => $amount, 'available_minor' => $eligibility->availableMinor(), 'currency' => $eligibility->currency]);
            throw ApiException::conflict('refund_amount_exceeds_available', 'This amount is more than what can still be refunded for this payment.', ['requested_minor' => $amount, 'available_minor' => $eligibility->availableMinor(), 'currency' => $eligibility->currency]);
        }

        return [$amount, Refund::TYPE_PARTIAL];
    }

    private function build(PaymentAttempt $attempt, ?Order $order, RefundReason $reason, ?string $detail, ?string $note, string $byType, ?string $byPublicId, int $amount, string $type, RefundStatus $status, ?string $key, ?CarbonInterface $at): Refund
    {
        $snapshot = (array) ($order?->snapshot ?? []);

        return (new Refund)->forceFill([
            'payment_attempt_id' => $attempt->getKey(),
            'payment_public_id' => (string) $attempt->public_id,
            'order_id' => $order?->getKey(),
            'order_number' => $order?->order_number,
            'customer_id' => $order?->customer_id ?? $attempt->customer_id,
            'customer_public_id' => $attempt->customer_public_id,
            'market_id' => $order?->market_id ?? $attempt->market_id,
            'location_id' => $order?->location_id ?? $attempt->location_id,
            'restaurant_name' => mb_substr((string) ($snapshot['restaurant']['name'] ?? $attempt->restaurant_name ?? ''), 0, 160) ?: null,
            'provider' => (string) $attempt->provider,
            'provider_mode' => $attempt->provider_mode,
            // The provider payment is the payment's own — it is never taken from a request.
            'provider_payment_reference' => $attempt->provider_payment_reference,
            'type' => $type,
            'requested_amount_minor' => $amount,
            'currency' => (string) $attempt->currency,
            'status' => $status->value,
            'reason_code' => $reason->value,
            'reason_detail' => $detail === null ? null : mb_substr($detail, 0, 48),
            'reason_note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
            'request_key' => $key,
            'requested_by_type' => $byType,
            'requested_by_public_id' => $byPublicId,
            'requested_at' => $at ?? now(),
        ]);
    }

    private static function sameVersion(Refund $refund, ?int $expected): void
    {
        if ($expected !== null && (int) $refund->version !== $expected) {
            throw ApiException::conflict('refund_version_conflict', 'This refund changed since you last saw it. Please refresh and try again.', ['current_version' => (int) $refund->version, 'current_status' => $refund->status->value]);
        }
    }

    private static function invalid(Refund $refund, string $verb): ApiException
    {
        $code = match ($refund->status) {
            RefundStatus::Refunded => 'refund_already_completed',
            RefundStatus::Submitting, RefundStatus::Processing => 'refund_already_processing',
            RefundStatus::SubmissionUnknown => 'refund_submission_unknown',
            RefundStatus::ReviewRequired => 'refund_review_required',
            default => 'refund_invalid_transition',
        };

        return ApiException::conflict($code, 'A refund that is '.$refund->status->value.' cannot be '.$verb.'.', ['status' => $refund->status->value, 'current_version' => (int) $refund->version]);
    }
}
