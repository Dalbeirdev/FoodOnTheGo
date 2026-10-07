<?php

namespace App\Services\Payment;

use App\Contracts\Payment\PaymentProvider;
use App\Enums\PaymentFailureCategory;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProviderPaymentState;
use App\Events\Payment\PaymentEvent;
use App\Exceptions\ProviderException;
use App\Models\CheckoutSession;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Services\Checkout\CheckoutService;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\Data\ProviderSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Decides what a payment attempt is, from evidence.
 *
 * Whoever notices something — the customer's device reporting an outcome, a webhook, the scheduled sweep, an
 * administrator — ends up here, and here the same question is asked every time: what does THE PROVIDER say about
 * the payments of this attempt's own order? The answer is fetched from the provider (so the order in which signals
 * arrive does not matter) and judged once:
 *
 *   a captured payment for our order, our amount and our currency        → VERIFIED
 *   a captured payment that does not match                               → REVIEW_REQUIRED (money moved; never "paid")
 *   an authorized payment                                                → AUTHORIZED (wait for capture)
 *   a payment still in progress                                          → PENDING
 *   only failed payments, and the customer is done or time is up         → FAILED
 *   nothing, and the customer cancelled / time is up                     → CANCELLED / EXPIRED
 *   the provider cannot be asked                                         → nothing is assumed: UNKNOWN, and try again
 *
 * A webhook's own content is used only when the provider cannot be asked, and then only to move forward (captured,
 * authorized) — a late "failed" event can never close what a later look would find paid.
 *
 * When an attempt ends without money its checkout becomes a confirmed checkout again; when it is VERIFIED the
 * checkout and its held pickup time are kept for the order, or the payment is marked as needing recovery.
 */
final class PaymentReconciliationService
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentStateMachine $machine,
        private readonly PaymentAttemptStore $attempts,
        private readonly CheckoutService $checkouts,
    ) {}

    /**
     * @param  list<ProviderPayment>  $given  payments named by a webhook whose signature was verified
     */
    public function reconcile(PaymentAttempt $attempt, string $source, array $given = [], ?int $adminId = null): PaymentAttempt
    {
        // One look at a time per attempt: a second caller takes what the first one finds.
        $lock = Cache::lock('payments:reconcile:'.$attempt->getKey(), 30);
        if (! $lock->get()) {
            return $attempt->refresh();
        }
        try {
            $attempt->refresh();
            if ($attempt->status === PaymentStatus::Verified) {
                return $attempt; // final: nothing a provider says later changes it, so the provider is not asked
            }
            $evidence = $this->gather($attempt);

            return DB::transaction(function () use ($attempt, $evidence, $given, $source, $adminId): PaymentAttempt {
                [$locked, $session] = $this->attempts->locked((int) $attempt->getKey());
                $this->apply($locked, $session, $evidence, $given, $source, $adminId, CarbonImmutable::now());

                return $locked;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * A verified payment whose checkout can no longer become an order as it stands needs recovery.
     */
    public function reviewFulfillment(PaymentAttempt $attempt, string $source): PaymentAttempt
    {
        if ($attempt->status !== PaymentStatus::Verified || $attempt->fulfillment_status !== PaymentFulfillmentStatus::OrderPending) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt, $source): PaymentAttempt {
            [$locked, $session] = $this->attempts->locked((int) $attempt->getKey());
            if ($locked->status === PaymentStatus::Verified && $locked->fulfillment_status === PaymentFulfillmentStatus::OrderPending) {
                $why = $session === null ? 'CHECKOUT_MISSING' : $this->checkouts->orderReadiness($session);
                if ($why !== null) {
                    $this->machine->fulfillment($locked, PaymentFulfillmentStatus::RecoveryRequired, $why, $source);
                }
            }

            return $locked;
        });
    }

    /* ------------------------------------------------------------------ evidence */

    /**
     * Asks the provider. Outside any transaction: no row is locked while the network is waited for.
     *
     * @return array{kind: 'payments', payments: list<ProviderPayment>}|array{kind: 'session', session: ProviderSession|null}|array{kind: 'error', failure: string}
     */
    private function gather(PaymentAttempt $attempt): array
    {
        $provider = $this->providers->named((string) $attempt->provider);
        if ($provider === null) {
            return ['kind' => 'error', 'failure' => 'PROVIDER_NOT_CONFIGURED'];
        }
        try {
            if ($attempt->provider_order_reference === null) {
                return ['kind' => 'session', 'session' => $this->providers->call($provider, 'find_session', fn () => $provider->findSessionByReceipt((string) $attempt->provider_receipt), safeToRepeat: true)];
            }

            return ['kind' => 'payments', 'payments' => $this->payments($provider, (string) $attempt->provider_order_reference)];
        } catch (ProviderException $e) {
            return ['kind' => 'error', 'failure' => $e->failure->value];
        }
    }

    /**
     * @return list<ProviderPayment>
     */
    private function payments(PaymentProvider $provider, string $orderReference): array
    {
        return $this->providers->call($provider, 'payments', fn () => $provider->paymentsOfSession($orderReference), safeToRepeat: true);
    }

    /* ------------------------------------------------------------------ judgement */

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<ProviderPayment>  $given
     */
    private function apply(PaymentAttempt $attempt, ?CheckoutSession $session, array $evidence, array $given, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        if ($attempt->status === PaymentStatus::Verified) {
            return;
        }
        $sweep = $source === PaymentAttemptEvent::SOURCE_JOB;
        $attempt->forceFill(['last_reconciled_at' => $at, 'reconcile_runs' => (int) $attempt->reconcile_runs + ($sweep ? 1 : 0)])->save();

        if ($evidence['kind'] === 'session') {
            $this->settleInitialization($attempt, $session, $evidence['session'], $source, $adminId, $at);
        } elseif ($evidence['kind'] === 'payments') {
            $this->judge($attempt, $session, $evidence['payments'], true, $source, $adminId, $at);
        } else {
            // The provider could not be asked. What a verified webhook itself says may still move the attempt forward.
            $this->machine->note($attempt, $source, 'provider_unreachable', ['failure' => $evidence['failure']], $adminId);
            if ($given !== [] && $attempt->provider_order_reference !== null) {
                $this->judge($attempt, $session, $given, false, $source, $adminId, $at);
            }
            if (in_array($attempt->status, [PaymentStatus::ProviderReady, PaymentStatus::Processing], true) && ($attempt->client_outcome !== null || $attempt->expired($at))) {
                // Something happened and it cannot be checked: neither paid nor unpaid may be assumed.
                $this->machine->move($attempt, PaymentStatus::Unknown, $source, ['status_reason' => 'PROVIDER_UNAVAILABLE'], [], $adminId);
            }
        }
        $this->schedule($attempt, $source, $adminId, $at);
        DB::afterCommit(fn () => event(new PaymentEvent(PaymentEvent::RECONCILED, $attempt, ['source' => $source, 'evidence' => $evidence['kind']])));
    }

    /**
     * Creating the provider's session did not get an answer. Now the provider says whether it exists.
     */
    private function settleInitialization(PaymentAttempt $attempt, ?CheckoutSession $session, ?ProviderSession $found, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        // While the request that creates the session may still be on its way, nothing is concluded.
        $settled = (int) config('services.payment.timeout') + 15;
        if ($attempt->status === PaymentStatus::Created && $attempt->created_at !== null && $attempt->created_at->diffInSeconds($at) < $settled) {
            return;
        }
        if ($found !== null && $found->amount->equals($attempt->amount()) && ! $attempt->expired($at)) {
            // It exists and nobody has used it: the customer can pay with it after all.
            $this->machine->move($attempt, PaymentStatus::ProviderReady, $source, [
                'provider_order_reference' => $found->reference, 'provider_order_status' => $found->providerStatus, 'provider_ready_at' => $at,
                'status_reason' => null, 'next_reconcile_at' => $attempt->expires_at,
            ], ['order' => $found->reference, 'recovered' => true], $adminId);

            return;
        }
        // No session — or one that was never handed to the customer's device, so nobody can pay with it.
        $this->machine->move($attempt, PaymentStatus::Failed, $source, [
            'provider_order_reference' => $found?->reference, 'provider_order_status' => $found?->providerStatus,
            'status_reason' => 'INITIALIZATION_FAILED', 'failure_category' => PaymentFailureCategory::InitializationFailed->value, 'failure_code' => 'PAYMENT_INITIALIZATION_FAILED',
        ], ['order' => $found?->reference], $adminId) && $this->release($session, $at);
    }

    /**
     * @param  list<ProviderPayment>  $payments
     * @param  bool  $authoritative  the list is the provider's own answer for this attempt's order (not a webhook's content)
     */
    private function judge(PaymentAttempt $attempt, ?CheckoutSession $session, array $payments, bool $authoritative, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        $ours = array_values(array_filter($payments, fn (ProviderPayment $p): bool => $p->orderReference === $attempt->provider_order_reference));
        $captured = array_values(array_filter($ours, fn (ProviderPayment $p): bool => $p->captured()));
        $paidElsewhere = $this->attempts->verifiedOf((string) $attempt->checkout_public_id, (int) $attempt->getKey());

        if ($captured !== []) {
            $match = collect($captured)->first(fn (ProviderPayment $p): bool => $p->amount->equals($attempt->amount()));
            if ($match === null) {
                // Money moved, but not the money this attempt was created for. Never VERIFIED; a person decides.
                $reason = $captured[0]->amount->currency !== $attempt->currency ? 'PAYMENT_CURRENCY_MISMATCH' : 'PAYMENT_AMOUNT_MISMATCH';
                if ($this->machine->move($attempt, PaymentStatus::ReviewRequired, $source, ['status_reason' => $reason, 'failure_code' => $reason, 'provider_payment_status' => $captured[0]->providerStatus], ['payment' => $captured[0]->reference, 'provider_amount_minor' => $captured[0]->amount->amount, 'provider_currency' => $captured[0]->amount->currency], $adminId)) {
                    DB::afterCommit(fn () => event(new PaymentEvent(PaymentEvent::MISMATCH, $attempt, ['reason' => $reason])));
                }

                return;
            }
            if ($paidElsewhere !== null) {
                // The checkout is paid already by another attempt: this is a second charge and has to be returned.
                if ($this->machine->move($attempt, PaymentStatus::ReviewRequired, $source, ['status_reason' => 'DUPLICATE_PAYMENT', 'failure_code' => 'PAYMENT_DUPLICATE', 'provider_payment_status' => $match->providerStatus], ['payment' => $match->reference, 'verified_payment' => (string) $paidElsewhere->public_id], $adminId)) {
                    DB::afterCommit(fn () => event(new PaymentEvent(PaymentEvent::DUPLICATE_PAYMENT, $attempt)));
                }

                return;
            }
            if (count($captured) > 1) {
                $this->machine->note($attempt, $source, 'several_captures_seen', ['count' => count($captured)], $adminId);
            }
            $this->verify($attempt, $session, $match, $source, $adminId, $at);

            return;
        }
        if ($paidElsewhere !== null) {
            // Another attempt paid this checkout and this one has taken no money: it is over.
            $this->machine->move($attempt, PaymentStatus::Cancelled, $source, ['status_reason' => 'SUPERSEDED', 'failure_category' => PaymentFailureCategory::CustomerCancelled->value, 'failure_code' => 'PAYMENT_SUPERSEDED'], ['verified_payment' => (string) $paidElsewhere->public_id], $adminId);

            return;
        }
        foreach ($ours as $payment) {
            if ($payment->state === ProviderPaymentState::Authorized) {
                $this->machine->move($attempt, PaymentStatus::Authorized, $source, ['provider_payment_status' => $payment->providerStatus, 'status_reason' => null], ['payment' => $payment->reference], $adminId);

                return;
            }
        }
        if (! $authoritative) {
            return; // a webhook's own word never closes an attempt
        }
        foreach ($ours as $payment) {
            if ($payment->state === ProviderPaymentState::Created || $payment->state === ProviderPaymentState::Unknown) {
                $this->machine->move($attempt, PaymentStatus::Pending, $source, ['provider_payment_status' => $payment->providerStatus, 'status_reason' => null], ['payment' => $payment->reference], $adminId);

                return;
            }
        }
        $done = in_array($attempt->client_outcome, ['failed', 'cancelled'], true) || $attempt->expired($at);
        $failed = collect($ours)->last(fn (ProviderPayment $p): bool => $p->state === ProviderPaymentState::Failed);
        if ($failed !== null) {
            $failure = ['provider_payment_status' => $failed->providerStatus, 'provider_error_code' => $failed->errorCode, 'provider_error_reason' => $failed->errorReason];
            if (! $done) {
                // The provider's interface lets the customer try again on the same order: the attempt is not over.
                $this->machine->move($attempt, PaymentStatus::Processing, $source, $failure, ['payment' => $failed->reference, 'state' => 'failed'], $adminId);

                return;
            }
            $category = PaymentFailureCategory::fromProvider($failed->errorReason, $failed->errorCode, $failed->errorSource);
            $this->machine->move($attempt, PaymentStatus::Failed, $source, $failure + ['status_reason' => 'PROVIDER_FAILED', 'failure_category' => $category->value, 'failure_code' => 'PAYMENT_FAILED'], ['payment' => $failed->reference], $adminId)
                && $this->release($session, $at);

            return;
        }

        // The provider has no payment at all for this order.
        match (true) {
            $attempt->client_outcome === 'cancelled' => $this->machine->move($attempt, PaymentStatus::Cancelled, $source, ['status_reason' => 'CUSTOMER_CANCELLED', 'failure_category' => PaymentFailureCategory::CustomerCancelled->value, 'failure_code' => 'PAYMENT_CANCELLED'], [], $adminId)
                && $this->release($session, $at),
            $attempt->client_outcome === 'failed' => $this->machine->move($attempt, PaymentStatus::Failed, $source, ['status_reason' => 'CLIENT_REPORTED', 'failure_category' => PaymentFailureCategory::fromProvider($attempt->provider_error_reason, $attempt->provider_error_code)->value, 'failure_code' => 'PAYMENT_FAILED'], [], $adminId)
                && $this->release($session, $at),
            // The device showed a genuine proof of payment and the provider lists nothing: not to be decided here.
            $attempt->client_outcome === 'success' => $this->machine->move($attempt, PaymentStatus::Unknown, $source, ['status_reason' => 'PROVIDER_HAS_NO_PAYMENT'], [], $adminId),
            $attempt->expired($at) => $this->machine->move($attempt, PaymentStatus::Expired, $source, ['status_reason' => 'NOT_PAID_IN_TIME', 'failure_category' => PaymentFailureCategory::Expired->value, 'failure_code' => 'PAYMENT_EXPIRED'], [], $adminId)
                && $this->release($session, $at),
            default => null,
        };
    }

    /**
     * The payment is captured and matches. What its checkout can still become decides the fulfilment state.
     */
    private function verify(PaymentAttempt $attempt, ?CheckoutSession $session, ProviderPayment $evidence, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        if ($session !== null && ! $attempt->status->isOpen()) {
            // Late success: the checkout may have been given back to the customer in the meantime.
            $this->checkouts->reclaimForPayment($session, $at);
        }
        $why = $session === null ? 'CHECKOUT_MISSING' : $this->checkouts->orderReadiness($session, $at);
        $fulfillment = $why === null ? PaymentFulfillmentStatus::OrderPending : PaymentFulfillmentStatus::RecoveryRequired;
        if ($this->machine->verify($attempt, $evidence, $fulfillment, $why, $source, $adminId) && $why === null) {
            // Paid: the checkout and its pickup time are kept for the order to be created.
            $this->checkouts->keepUntil($session, $at->addMinutes(max(1, (int) config('payments.order_handoff_minutes'))));
        }
    }

    private function release(?CheckoutSession $session, CarbonImmutable $at): bool
    {
        if ($session !== null) {
            $this->checkouts->releasePayment($session, $at);
        }

        return true;
    }

    /**
     * When the sweep looks again — and when it stops: an attempt that the sweep could not settle in all its tries
     * needs a person.
     */
    private function schedule(PaymentAttempt $attempt, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        if (! $attempt->status->isOpen()) {
            return;
        }
        if ($attempt->status === PaymentStatus::ProviderReady && ! $attempt->expired($at)) {
            $attempt->forceFill(['next_reconcile_at' => $attempt->expires_at])->save(); // the customer's turn: look when the time is up

            return;
        }
        $waits = array_values(array_filter((array) config('payments.reconcile.backoff_seconds'), 'is_numeric')) ?: [60];
        $runs = (int) $attempt->reconcile_runs;
        if ($runs >= count($waits)) {
            $this->machine->move($attempt, PaymentStatus::ReviewRequired, $source, ['status_reason' => 'RECONCILIATION_EXHAUSTED', 'failure_code' => 'PAYMENT_UNKNOWN'], ['runs' => $runs], $adminId);

            return;
        }
        $attempt->forceFill(['next_reconcile_at' => $at->addSeconds((int) $waits[$runs])])->save();
    }
}
