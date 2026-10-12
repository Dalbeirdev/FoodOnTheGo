<?php

namespace App\Services\Order;

use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Refund\RefundEligibilityService;
use App\Support\Money;

/**
 * What cancelling an order would mean for its customer — decided on the server, shown before they confirm
 * (Module 35).
 *
 * CUSTOMER CANCELLATION = DISABLED, PENDING BUSINESS / LEGAL APPROVAL. No rule such as "cancel any time before
 * preparation" is built in: the states in which a customer may cancel and what that does to the money are
 * configuration (config/refunds.php → customer_cancellation), and until the business approves a policy the switch
 * is off and every quote says so. Web and Android show what this answers; neither computes an amount or an effect.
 */
final class OrderCancellationPolicy
{
    public const FULL_REFUND = 'FULL_REFUND';

    public const NO_REFUND = 'NO_REFUND';

    public const MANUAL_REVIEW = 'MANUAL_REVIEW';

    /** Nothing was charged: cancelling has no financial effect. */
    public const NO_PAYMENT = 'NO_PAYMENT';

    public function __construct(private readonly RefundEligibilityService $eligibility) {}

    /**
     * @return array<string, mixed>
     */
    public function quote(Order $order): array
    {
        $policy = (array) config('refunds.customer_cancellation');
        $enabled = (bool) ($policy['enabled'] ?? false);
        $allowed = in_array($order->status->value, (array) ($policy['allowed_statuses'] ?? []), true);
        $can = $enabled && $allowed;
        $effect = null;
        $refundable = null;
        if ($can) {
            $configured = in_array($policy['financial_effect'] ?? null, [self::FULL_REFUND, self::NO_REFUND, self::MANUAL_REVIEW], true) ? (string) $policy['financial_effect'] : self::MANUAL_REVIEW;
            $effect = $order->payment_state === OrderPaymentState::Paid ? $configured : self::NO_PAYMENT;
            if ($effect === self::FULL_REFUND) {
                // What is left of the payment — from the records, at this moment.
                $refundable = (new Money($this->eligibility->forOrder($order, null, false)->availableMinor(), (string) $order->currency))->toArray();
            }
        }

        return [
            'enabled' => $enabled,
            'policy_status' => $enabled ? 'CONFIGURED' : 'PENDING_BUSINESS_APPROVAL',
            'policy_version' => (string) ($policy['policy_version'] ?? ''),
            'can_cancel' => $can,
            'reason' => match (true) {
                ! $enabled => 'CUSTOMER_CANCELLATION_DISABLED',
                $order->status->isTerminal() || $order->status === OrderStatus::PickedUp => 'ORDER_ALREADY_CLOSED',
                ! $allowed => 'ORDER_STATE_NOT_CANCELLABLE',
                default => null,
            },
            'message' => match (true) {
                ! $enabled => 'Orders cannot be cancelled in the app yet. Please contact FoodOnTheGo support.',
                ! $allowed => 'This order can no longer be cancelled in the app. Please contact FoodOnTheGo support.',
                $effect === self::FULL_REFUND => 'If you cancel now, your payment for this order will be refunded to the method you paid with.',
                $effect === self::NO_REFUND => 'If you cancel now, no refund is due for this order.',
                $effect === self::MANUAL_REVIEW => 'If you cancel now, our team reviews the payment for this order and tells you the outcome.',
                default => 'Nothing was charged for this order.',
            },
            'financial_effect' => $effect,
            'refundable_amount' => $refundable,
            'reasons' => $can ? array_values((array) ($policy['reasons'] ?? [])) : [],
            'order' => ['id' => (string) $order->public_id, 'order_number' => (string) $order->order_number, 'status' => $order->status->value, 'version' => (int) $order->version],
        ];
    }
}
