<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\PaymentAttempt;

/**
 * Finding and locking payment attempts.
 *
 * Lock order, everywhere: the checkout's row first, then the attempt's. Starting a payment locks the checkout and
 * then writes an attempt; ending one changes the attempt and then the checkout — taken in two different orders the
 * two would wait for each other.
 */
final class PaymentAttemptStore
{
    /**
     * The attempt and its checkout, both locked for this transaction. The checkout is null once it is gone.
     *
     * @return array{0: PaymentAttempt, 1: CheckoutSession|null}
     */
    public function locked(int $attemptId): array
    {
        $sessionId = PaymentAttempt::query()->whereKey($attemptId)->value('checkout_session_id');
        $session = $sessionId === null ? null : CheckoutSession::query()->whereKey($sessionId)->lockForUpdate()->first();
        $attempt = PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();

        return [$attempt, $session];
    }

    /**
     * A customer's own attempt. Anybody else's is "not found" — exactly as an id that does not exist.
     *
     * @throws ApiException 404 payment_not_found
     */
    public function ownedBy(Customer $customer, string $paymentId): PaymentAttempt
    {
        return PaymentAttempt::query()->where('public_id', $paymentId)->where('customer_id', $customer->getKey())->first()
            ?? throw ApiException::notFound('payment_not_found', 'This payment does not exist.');
    }

    /**
     * The attempt of a checkout that may still end in a payment — there is at most one.
     */
    public function openOf(string $checkoutPublicId): ?PaymentAttempt
    {
        return PaymentAttempt::query()->where('checkout_public_id', $checkoutPublicId)->whereIn('status', PaymentStatus::openValues())->first();
    }

    public function verifiedOf(string $checkoutPublicId, ?int $exceptAttemptId = null): ?PaymentAttempt
    {
        return PaymentAttempt::query()->where('checkout_public_id', $checkoutPublicId)->where('status', PaymentStatus::Verified->value)
            ->when($exceptAttemptId !== null, fn ($q) => $q->whereKeyNot($exceptAttemptId))->first();
    }

    public function countOf(string $checkoutPublicId): int
    {
        return PaymentAttempt::query()->where('checkout_public_id', $checkoutPublicId)->count();
    }

    public function byProviderReferences(string $provider, ?string $orderReference, ?string $paymentReference): ?PaymentAttempt
    {
        if ($orderReference !== null && ($attempt = PaymentAttempt::query()->where('provider', $provider)->where('provider_order_reference', $orderReference)->first()) !== null) {
            return $attempt;
        }

        return $paymentReference === null ? null : PaymentAttempt::query()->where('provider', $provider)->where('provider_payment_reference', $paymentReference)->first();
    }
}
