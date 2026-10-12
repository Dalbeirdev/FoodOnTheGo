<?php

namespace App\Services\Refund;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefundBalance;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * Finding and locking refunds.
 *
 * Lock order, everywhere: the order's row (when the refund has an order), then the payment's refund balance, then
 * the refund. The order workflow takes the order first and then asks for a refund; a refund change takes the order
 * first as well — taken in two different orders the two would wait for each other.
 */
final class RefundStore
{
    /**
     * The payment's refund balance, created on first use and locked for this transaction. The balance row is the
     * mutex of everything that changes what a payment can still give back.
     */
    public function balance(PaymentAttempt $attempt): PaymentRefundBalance
    {
        $key = (int) $attempt->getKey();
        $row = PaymentRefundBalance::query()->whereKey($key)->lockForUpdate()->first();
        if ($row === null) {
            // Two first refunds of one payment at once: one insert wins, both lock the same row afterwards.
            DB::table('payment_refund_balances')->insertOrIgnore([
                'payment_attempt_id' => $key,
                'currency' => (string) $attempt->currency,
                'paid_minor' => (int) $attempt->amount_minor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = PaymentRefundBalance::query()->whereKey($key)->lockForUpdate()->firstOrFail();
        }

        return $row;
    }

    /**
     * The balance as it stands, without a lock and without creating it — for reading.
     */
    public function peek(?PaymentAttempt $attempt): ?PaymentRefundBalance
    {
        return $attempt === null ? null : PaymentRefundBalance::query()->whereKey($attempt->getKey())->first();
    }

    /**
     * A refund, its balance and its order, locked in the one order of locks. Call inside a transaction.
     */
    public function locked(int $refundId): RefundLock
    {
        $ids = Refund::query()->whereKey($refundId)->toBase()->first(['order_id', 'payment_attempt_id'])
            ?? throw ApiException::notFound('refund_not_found', 'This refund does not exist.');
        $order = $ids->order_id === null ? null : Order::query()->whereKey($ids->order_id)->lockForUpdate()->first();
        $attempt = PaymentAttempt::query()->whereKey($ids->payment_attempt_id)->firstOrFail();
        $balance = $this->balance($attempt);
        $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();

        return new RefundLock($refund, $balance, $order);
    }

    /**
     * @throws ApiException 404 refund_not_found
     */
    public function byPublicId(string $publicId): Refund
    {
        return Refund::query()->where('public_id', $publicId)->first()
            ?? throw ApiException::notFound('refund_not_found', 'This refund does not exist.');
    }

    /**
     * The refund a provider event or lookup is about: by the provider's refund reference, or by our receipt when the
     * provider's reference never reached us (an unanswered creation).
     */
    public function byProviderReferences(string $provider, ?string $refundReference, ?string $receipt): ?Refund
    {
        if ($refundReference !== null && $refundReference !== '' && ($refund = Refund::query()->where('provider', $provider)->where('provider_refund_reference', $refundReference)->first()) !== null) {
            return $refund;
        }

        return $receipt === null || $receipt === '' ? null : Refund::query()->where('provider', $provider)->where('provider_receipt', $receipt)->first();
    }
}
