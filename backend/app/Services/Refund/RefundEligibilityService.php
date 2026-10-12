<?php

namespace App\Services\Refund;

use App\Enums\DisputeStatus;
use App\Enums\OrderPaymentState;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentRefundBalance;

/**
 * Decides whether money can go back, from the records — never from a request (Module 35).
 *
 *   the order was paid (a zero-total order never has a refund: nothing was charged)
 *   its payment is VERIFIED — the provider captured it — and the provider's payment reference is known
 *   the currency is the payment's
 *   the order is over (rejected, cancelled, picked up or completed): an order that is still being made is ended
 *   first, and ending it creates the refund
 *   no dispute is open on the payment (a refund now could return the same money twice)
 *   something is left: paid − confirmed refunds − refunds on their way or not understood − lost disputes
 *
 * A payment that never became an order can be refunded in two cases only: it is VERIFIED and its order cannot be
 * made (recovery required), or it is a second capture for a checkout that was already paid (duplicate payment).
 */
final class RefundEligibilityService
{
    /**
     * @param  PaymentRefundBalance|null  $balance  the payment's balance when the caller holds it (locked); read otherwise
     * @param  bool  $requireEnded  false when the order is being ended in this very transaction
     */
    public function forOrder(Order $order, ?PaymentRefundBalance $balance = null, bool $requireEnded = true): RefundEligibility
    {
        $currency = (string) $order->currency;
        if ($order->payment_state !== OrderPaymentState::Paid) {
            return new RefundEligibility(false, 'refund_not_eligible', 'Nothing was charged for this order, so there is nothing to refund.', $currency);
        }
        $attempt = $order->payment_attempt_id === null ? null : PaymentAttempt::query()->find($order->payment_attempt_id);
        if ($attempt === null) {
            return new RefundEligibility(false, 'refund_payment_not_verified', 'The payment of this order is not on record any more.', $currency);
        }
        if ((string) $attempt->currency !== $currency) {
            return new RefundEligibility(false, 'refund_currency_mismatch', 'The order and its payment are not in the same currency.', $currency);
        }
        $figures = $this->figures($attempt, $balance);
        if ($attempt->status !== PaymentStatus::Verified || $attempt->provider_payment_reference === null) {
            return new RefundEligibility(false, 'refund_payment_not_verified', 'Only a verified payment can be refunded.', $currency, ...$figures);
        }
        if ($requireEnded && ! $order->status->isTerminal() && $order->picked_up_at === null) {
            return new RefundEligibility(false, 'refund_order_active', 'This order is still in progress. End the order first; ending a paid order creates its refund.', $currency, ...$figures);
        }

        return $this->judge($currency, $figures);
    }

    /**
     * A payment without an order. The reason is the server's reading of the payment, never the caller's choice.
     *
     * @return array{0: RefundEligibility, 1: RefundReason|null}
     */
    public function forPayment(PaymentAttempt $attempt, ?PaymentRefundBalance $balance = null): array
    {
        $currency = (string) $attempt->currency;
        if (Order::query()->where('payment_attempt_id', $attempt->getKey())->exists()) {
            return [new RefundEligibility(false, 'refund_not_eligible', 'This payment became an order. Refund the order instead.', $currency), null];
        }
        $reason = match (true) {
            $attempt->status === PaymentStatus::Verified && $attempt->fulfillment_status === PaymentFulfillmentStatus::RecoveryRequired => RefundReason::PaymentOrderFailure,
            $attempt->status === PaymentStatus::ReviewRequired && $attempt->status_reason === 'DUPLICATE_PAYMENT' => RefundReason::DuplicatePayment,
            default => null,
        };
        $figures = $this->figures($attempt, $balance);
        if ($reason === null || $attempt->provider_payment_reference === null) {
            return [new RefundEligibility(false, 'refund_payment_not_verified', 'Only a captured payment whose order cannot be made, or a second payment for the same checkout, can be refunded here.', $currency, ...$figures), null];
        }

        return [$this->judge($currency, $figures), $reason];
    }

    /**
     * @param  array{paidMinor: int, refundedMinor: int, reservedMinor: int, chargedBackMinor: int, disputeOpen: bool}  $figures
     */
    private function judge(string $currency, array $figures): RefundEligibility
    {
        if ($figures['disputeOpen']) {
            return new RefundEligibility(false, 'refund_dispute_conflict', 'A dispute is open on this payment. A refund now could return the same money twice.', $currency, ...$figures);
        }
        $left = max(0, $figures['paidMinor'] - $figures['refundedMinor'] - $figures['reservedMinor'] - $figures['chargedBackMinor']);
        if ($left === 0) {
            return $figures['reservedMinor'] > 0
                ? new RefundEligibility(false, 'refund_already_processing', 'Refunds for the whole remaining amount are already under way.', $currency, ...$figures)
                : new RefundEligibility(false, 'refund_already_completed', 'This payment has been refunded in full.', $currency, ...$figures);
        }

        return new RefundEligibility(true, null, 'This payment can be refunded.', $currency, ...$figures);
    }

    /**
     * @return array{paidMinor: int, refundedMinor: int, reservedMinor: int, chargedBackMinor: int, disputeOpen: bool}
     */
    private function figures(PaymentAttempt $attempt, ?PaymentRefundBalance $balance): array
    {
        $balance ??= PaymentRefundBalance::query()->whereKey($attempt->getKey())->first();

        return [
            'paidMinor' => (int) ($balance?->paid_minor ?? $attempt->amount_minor),
            'refundedMinor' => (int) ($balance?->refunded_minor ?? 0),
            'reservedMinor' => (int) ($balance?->reserved_minor ?? 0),
            'chargedBackMinor' => (int) ($balance?->charged_back_minor ?? 0),
            'disputeOpen' => PaymentDispute::query()->where('payment_attempt_id', $attempt->getKey())->whereIn('status', DisputeStatus::openValues())->exists(),
        ];
    }
}
