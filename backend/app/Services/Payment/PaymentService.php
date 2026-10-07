<?php

namespace App\Services\Payment;

use App\Contracts\Payment\PaymentProvider;
use App\Enums\PaymentFailureCategory;
use App\Enums\PaymentNextAction;
use App\Enums\PaymentStatus;
use App\Enums\ProviderFailure;
use App\Events\Payment\PaymentEvent;
use App\Exceptions\ApiException;
use App\Exceptions\ProviderException;
use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent as Line;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\PaymentOptions;
use App\Services\Payment\Data\ProviderSession;
use App\Services\Payment\Data\ProviderSessionRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Payments as a customer meets them (Module 30).
 *
 *   start     a confirmed checkout goes into payment and gets a payment attempt with a provider session — or the
 *             attempt that is already open is returned
 *   show      the attempt as it stands (and, while it is unsettled, a look at the provider every few seconds)
 *   complete  the customer's device reports how the provider's interface ended — a signal that is VERIFIED here,
 *             never believed
 *
 * Rules that hold everywhere:
 *  - the amount and the currency of a payment are the checkout's total and nothing else; no request carries either;
 *  - a customer finds only their own payments (anybody else gets "not found");
 *  - what a device says about a payment starts a verification. Only the provider's own record — a captured payment
 *    on our order for our amount — makes a payment VERIFIED;
 *  - a checkout has at most one attempt that may still end in a payment, and at most one VERIFIED payment;
 *  - no order is created here: a VERIFIED payment waits for the order module.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentAttemptStore $attempts,
        private readonly PaymentStateMachine $machine,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly CheckoutService $checkouts,
        private readonly PaymentOptions $options,
    ) {}

    /**
     * @param  array{pricing_fingerprint: string, payment_method?: array{type?: string|null}|null}  $input
     * @return array{0: PaymentAttempt, 1: bool} the attempt, and whether this call created it
     *
     * @throws ApiException 404 checkout_not_found; 409 checkout_stale / checkout_not_ready / payment_not_required /
     *                      payment_already_verified / payment_already_in_progress / payment_attempt_limit_reached /
     *                      payment_window_closed; 410 checkout_expired; 422 payment_method_unavailable;
     *                      503 payment_provider_unavailable / payment_initialization_failed / payment_initialization_unknown
     */
    public function start(Customer $customer, string $checkoutId, array $input, ?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $provider = $this->provider(); // before anything changes: no payments here means no checkout is touched

        $session = CheckoutSession::query()->where('public_id', $checkoutId)->where('customer_id', $customer->getKey())->first()
            ?? throw ApiException::notFound('checkout_not_found', 'This checkout does not exist.');
        if (! $session->payment_required) {
            throw ApiException::conflict('payment_not_required', 'Nothing has to be paid for this checkout.');
        }
        $method = $this->method($session, $input);

        // An attempt that is already there is settled first — never guessed around.
        $open = $this->attempts->openOf($checkoutId);
        if ($open !== null && ($open->status !== PaymentStatus::ProviderReady || $open->expired($at))) {
            $open = $this->reconciliation->reconcile($open, Line::SOURCE_SYSTEM);
        }
        if (($current = $this->existing($checkoutId, $open, $at)) !== null) {
            return [$current, false];
        }
        if ($this->attempts->countOf($checkoutId) >= max(1, (int) config('payments.attempt.max_per_checkout'))) {
            throw ApiException::conflict('payment_attempt_limit_reached', 'This checkout cannot be paid any more. Please review your order again.', ['next_action' => PaymentNextAction::ReturnToCheckout->value]);
        }
        $windowEnds = CarbonImmutable::instance($session->confirmed_at ?? $at)->addMinutes(max(1, (int) config('payments.window_minutes')));
        if ($at->greaterThanOrEqualTo($windowEnds)) {
            throw ApiException::conflict('payment_window_closed', 'This checkout can no longer be paid. Please review your order again.', ['next_action' => PaymentNextAction::ReturnToCheckout->value]);
        }
        $expiresAt = $at->addMinutes(max(1, (int) config('payments.attempt.ttl_minutes')))->min($windowEnds);
        $keepUntil = $expiresAt->addMinutes(max(0, (int) config('payments.attempt.grace_minutes')));

        // The checkout goes into payment and the attempt is written — together or not at all.
        $attempt = null;
        try {
            $preparation = $this->checkouts->beginPayment($customer, $checkoutId, (string) $input['pricing_fingerprint'], $at, $keepUntil, function (CheckoutSession $locked) use (&$attempt, $customer, $provider, $method, $expiresAt): void {
                $attempt = $this->write($locked, $customer, $provider, $method, $expiresAt);
            });
        } catch (ApiException $e) {
            // Another device was a moment faster: there is one attempt, and this device gets it.
            if ($e->errorCode === 'payment_in_progress' && ($current = $this->existing($checkoutId, $this->attempts->openOf($checkoutId), $at)) !== null) {
                return [$current, false];
            }
            throw $e;
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('payment_already_in_progress', 'A payment for this checkout is already in progress.', ['next_action' => PaymentNextAction::RefreshStatus->value]);
        }
        $attempt->forceFill(['preparation_key' => $preparation->idempotencyKey])->save();
        event(new PaymentEvent(PaymentEvent::ATTEMPT_CREATED, $attempt));

        return [$this->initialize($attempt, $provider, $at), true];
    }

    /**
     * @throws ApiException 404 payment_not_found
     */
    public function show(Customer $customer, string $paymentId, ?CarbonInterface $at = null): PaymentAttempt
    {
        $at = CarbonImmutable::instance($at ?? now());
        $attempt = $this->attempts->ownedBy($customer, $paymentId);
        if (! $attempt->status->isOpen()) {
            return $attempt; // settled: it is what it is (what becomes of a verified payment is watched by the sweep)
        }
        // An unsettled attempt is looked up at the provider — also one the customer "has not paid yet": a device that
        // was closed after paying comes back with exactly this request. At most once every few seconds.
        $recently = $attempt->last_reconciled_at !== null && $attempt->last_reconciled_at->diffInSeconds($at) < max(1, (int) config('payments.reconcile.inline_interval_seconds'));

        return $recently ? $attempt : $this->reconciliation->reconcile($attempt, Line::SOURCE_CLIENT);
    }

    /**
     * The device reports how the provider's interface ended.
     *
     * @param  array{outcome: string, provider_payment_reference?: string|null, provider_signature?: string|null, provider_error?: array{code?: string|null, reason?: string|null}|null}  $input
     *
     * @throws ApiException 404 payment_not_found; 409 payment_not_ready; 422 payment_signature_invalid; 503 payment_provider_unavailable
     */
    public function complete(Customer $customer, string $paymentId, array $input, ?CarbonInterface $at = null): PaymentAttempt
    {
        $at = CarbonImmutable::instance($at ?? now());
        $attempt = $this->attempts->ownedBy($customer, $paymentId);
        if (! $attempt->status->isOpen()) {
            return $attempt; // settled already: the answer is what it is, however often the device reports
        }
        if ($attempt->provider_order_reference === null) {
            throw ApiException::conflict('payment_not_ready', 'This payment has not been opened yet.', ['next_action' => PaymentNextAction::RefreshStatus->value]);
        }
        $outcome = (string) $input['outcome'];
        $reported = ['client_outcome' => $outcome, 'client_reported_at' => $at];
        $detail = ['outcome' => $outcome];

        if ($outcome === 'success') {
            $provider = $this->providers->named((string) $attempt->provider) ?? throw $this->unavailable();
            $reference = (string) ($input['provider_payment_reference'] ?? '');
            // The proof is checked against OUR session reference — a payment of any other order cannot pass.
            if (! $provider->verifyClientSignature((string) $attempt->provider_order_reference, $reference, (string) ($input['provider_signature'] ?? ''))) {
                DB::transaction(function () use ($attempt): void {
                    [$locked] = $this->attempts->locked((int) $attempt->getKey());
                    $locked->forceFill(['signature_failures' => (int) $locked->signature_failures + 1])->save();
                    $this->machine->note($locked, Line::SOURCE_CLIENT, 'signature_invalid');
                });
                event(new PaymentEvent(PaymentEvent::SIGNATURE_INVALID, $attempt));
                Log::warning('payment.signature_invalid', ['payment' => (string) $attempt->public_id, 'provider' => (string) $attempt->provider]);
                // The claim is refused — but what really happened is the provider's to say. It is asked about OUR order
                // now: a payment that was truly made (its proof damaged on the way) is found and verified on the
                // provider's word, and an invented one is simply not there. Nothing of the claim is used.
                try {
                    $this->reconciliation->reconcile($attempt->refresh(), Line::SOURCE_CLIENT);
                } catch (Throwable) {
                    // the scheduled sweep asks again
                }
                throw new ApiException(422, 'payment_signature_invalid', 'This payment could not be verified.', ['next_action' => PaymentNextAction::RefreshStatus->value]);
            }
            $reported['signature_verified_at'] = $at;
            $detail['payment'] = $reference;
        } elseif (is_array($input['provider_error'] ?? null)) {
            // What the provider's interface told the device — remembered for the message, trusted for nothing.
            $reported['provider_error_code'] = self::code($input['provider_error']['code'] ?? null);
            $reported['provider_error_reason'] = self::code($input['provider_error']['reason'] ?? null);
            $detail['reason'] = $reported['provider_error_reason'];
        }

        DB::transaction(function () use ($attempt, $reported, $detail): void {
            [$locked] = $this->attempts->locked((int) $attempt->getKey());
            if (! $locked->status->isOpen()) {
                return;
            }
            $locked->forceFill($reported)->save();
            $this->machine->note($locked, Line::SOURCE_CLIENT, 'client_callback', $detail);
            if ($locked->status === PaymentStatus::ProviderReady) {
                $this->machine->move($locked, PaymentStatus::Processing, Line::SOURCE_CLIENT);
            }
        });
        event(new PaymentEvent(PaymentEvent::CLIENT_CALLBACK, $attempt, ['outcome' => $outcome]));

        return $this->reconciliation->reconcile($attempt, Line::SOURCE_CLIENT);
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * The attempt a second request for the same checkout gets instead of a new one — or the refusal.
     *
     * @throws ApiException 409 payment_already_verified / payment_already_in_progress
     */
    private function existing(string $checkoutId, ?PaymentAttempt $open, CarbonImmutable $at): ?PaymentAttempt
    {
        if (($paid = $this->attempts->verifiedOf($checkoutId)) !== null) {
            throw ApiException::conflict('payment_already_verified', 'This checkout has been paid.', ['payment_id' => (string) $paid->public_id, 'next_action' => PaymentNextAction::ProceedToOrder->value]);
        }
        if ($open === null || ! $open->status->isOpen()) {
            return null;
        }
        if ($open->status->payable() && ! $open->expired($at)) {
            return $open;
        }
        // Money may be on its way: a second payment is not started beside it.
        throw ApiException::conflict('payment_already_in_progress', 'A payment for this checkout is still being confirmed.', ['payment_id' => (string) $open->public_id, 'status' => $open->status->value, 'next_action' => PaymentNextAction::RefreshStatus->value]);
    }

    private function write(CheckoutSession $session, Customer $customer, PaymentProvider $provider, ?string $method, CarbonImmutable $expiresAt): PaymentAttempt
    {
        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'checkout_session_id' => $session->getKey(),
            'checkout_public_id' => (string) $session->public_id,
            'checkout_version' => (int) $session->version,
            'pricing_fingerprint' => (string) $session->pricing_fingerprint,
            'customer_id' => $customer->getKey(),
            'customer_public_id' => (string) $customer->public_id,
            'market_id' => $session->market_id,
            'location_id' => $session->location_id,
            'restaurant_name' => mb_substr((string) ($session->snapshot['restaurant']['name'] ?? ''), 0, 160),
            'attempt_number' => $this->attempts->countOf((string) $session->public_id) + 1,
            // The checkout's total, read under the checkout's row lock. It never changes afterwards.
            'amount_minor' => (int) $session->total_minor,
            'currency' => (string) $session->currency,
            'provider' => $provider->name(),
            'provider_mode' => $provider->mode(),
            // Our reference at the provider: unique per attempt, so the provider itself refuses to create it twice.
            'provider_receipt' => 'pa_'.Str::lower(str_replace('-', '', (string) Str::uuid())),
            'status' => PaymentStatus::Created->value,
            'requested_method_type' => $method,
            'expires_at' => $expiresAt,
        ])->save();
        $this->machine->note($attempt, Line::SOURCE_SYSTEM, 'attempt_created', ['attempt' => (int) $attempt->attempt_number, 'provider' => $provider->name(), 'mode' => $provider->mode()]);

        return $attempt;
    }

    /**
     * Asks the provider for its session — outside any transaction, and exactly once: creating a session is not
     * repeated on a timeout, because the first request may have arrived.
     */
    private function initialize(PaymentAttempt $attempt, PaymentProvider $provider, CarbonImmutable $at): PaymentAttempt
    {
        $request = new ProviderSessionRequest($attempt->amount(), (string) $attempt->provider_receipt, [
            'payment' => (string) $attempt->public_id,
            'checkout' => (string) $attempt->checkout_public_id,
            'environment' => (string) app()->environment(),
        ]);
        $session = null;
        $failure = null;
        try {
            $session = $this->providers->call($provider, 'create_session', fn () => $provider->createSession($request));
        } catch (ProviderException $e) {
            $failure = $e->failure;
            if ($failure === ProviderFailure::Timeout) {
                try {
                    // It is not known whether the session exists. The provider is asked by our receipt.
                    $session = $this->providers->call($provider, 'find_session', fn () => $provider->findSessionByReceipt((string) $attempt->provider_receipt), safeToRepeat: true);
                } catch (ProviderException) {
                    return $this->initializationUnknown($attempt, $at);
                }
            }
        }
        if ($session instanceof ProviderSession && ! $session->amount->equals($attempt->amount())) {
            $session = null; // a session for another amount is not ours to use
            $failure = ProviderFailure::Rejected;
        }

        // A refusal is thrown only after the commit: the FAILED attempt and the released checkout must be kept.
        $refusal = null;
        $attempt = DB::transaction(function () use ($attempt, $session, $failure, $at, &$refusal): PaymentAttempt {
            [$locked, $checkout] = $this->attempts->locked((int) $attempt->getKey());
            if ($session !== null) {
                $this->machine->move($locked, PaymentStatus::ProviderReady, Line::SOURCE_PROVIDER, [
                    'provider_order_reference' => $session->reference, 'provider_order_status' => $session->providerStatus, 'provider_ready_at' => $at, 'next_reconcile_at' => $locked->expires_at,
                ], ['order' => $session->reference]);

                return $locked;
            }
            $this->machine->move($locked, PaymentStatus::Failed, Line::SOURCE_PROVIDER, [
                'status_reason' => 'INITIALIZATION_FAILED', 'failure_category' => PaymentFailureCategory::InitializationFailed->value, 'failure_code' => 'PAYMENT_INITIALIZATION_FAILED',
            ], ['failure' => $failure?->value]);
            if ($checkout !== null) {
                $this->checkouts->releasePayment($checkout, $at); // nothing was charged: the checkout can be paid again
            }
            DB::afterCommit(fn () => event(new PaymentEvent(PaymentEvent::INITIALIZATION_FAILED, $locked, ['failure' => $failure?->value])));
            $refusal = new ApiException(503, $failure === ProviderFailure::Rejected ? 'payment_initialization_failed' : 'payment_provider_unavailable', 'Payments are temporarily unavailable. Nothing was charged — please try again in a moment.', [
                'payment_id' => (string) $locked->public_id, 'retryable' => true, 'next_action' => PaymentNextAction::RetryPayment->value,
            ]);

            return $locked;
        });

        return $refusal === null ? $attempt : throw $refusal;
    }

    private function initializationUnknown(PaymentAttempt $attempt, CarbonImmutable $at): PaymentAttempt
    {
        DB::transaction(function () use ($attempt, $at): void {
            [$locked] = $this->attempts->locked((int) $attempt->getKey());
            $wait = (int) (((array) config('payments.reconcile.backoff_seconds'))[0] ?? 60);
            $this->machine->move($locked, PaymentStatus::Unknown, Line::SOURCE_PROVIDER, ['status_reason' => 'INITIALIZATION_UNKNOWN', 'failure_code' => 'PAYMENT_INITIALIZATION_UNKNOWN', 'next_reconcile_at' => $at->addSeconds($wait)]);
        });
        event(new PaymentEvent(PaymentEvent::INITIALIZATION_UNKNOWN, $attempt));
        throw new ApiException(503, 'payment_initialization_unknown', 'We could not start the payment. Nothing was charged — please wait a moment before trying again.', [
            'payment_id' => (string) $attempt->public_id, 'retryable' => false, 'next_action' => PaymentNextAction::RefreshStatus->value,
        ]);
    }

    /**
     * How the customer means to pay: the type recorded at checkout, or another one the market offers. A context for
     * the provider's interface — never credentials, and never cash.
     */
    private function method(CheckoutSession $session, array $input): ?string
    {
        $type = strtoupper(trim((string) ($input['payment_method']['type'] ?? '')));
        if ($type === '') {
            return $session->payment_method_type;
        }
        $market = $session->market;
        if ($market === null || ! $this->options->offers($market, $type)) {
            throw new ApiException(422, 'payment_method_unavailable', 'This way of paying is not available.');
        }

        return $type;
    }

    private function provider(): PaymentProvider
    {
        try {
            return $this->providers->provider();
        } catch (ProviderException) {
            throw $this->unavailable();
        }
    }

    private function unavailable(): ApiException
    {
        return new ApiException(503, 'payment_provider_unavailable', 'Payments are temporarily unavailable. Please try again later.', ['retryable' => true, 'next_action' => PaymentNextAction::Wait->value]);
    }

    private static function code(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_.\- ]{1,64}\z/', $value) === 1 ? $value : null;
    }
}
