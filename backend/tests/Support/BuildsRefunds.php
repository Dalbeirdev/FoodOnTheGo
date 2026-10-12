<?php

namespace Tests\Support;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentRefundBalance;
use App\Models\Refund;
use App\Services\Payment\Data\ProviderDispute;
use App\Services\Payment\Data\ProviderRefund;
use Illuminate\Testing\TestResponse;

/**
 * Refunds in tests (Module 35): a paid ₹500 order that the restaurant rejected (REJECTED, payment VERIFIED, one
 * refund REQUIRED), a completed paid order, the refund API as an administrator calls it, and the FAKE provider's
 * side — the bank finishing a refund, the provider sending a refund or a dispute webhook.
 *
 * No test sets a refund's status directly: every refund here moves through the real path, and REFUNDED is reached
 * only through the (fake) provider's own record.
 */
trait BuildsRefunds
{
    use BuildsOrders;

    /** Slots handed to successive orders of one test (the Burger Hub fixture has room in each). */
    private int $refundSlot = 0;

    /** Every refund permission, with what the order and payment screens need. */
    protected const FINANCE = [
        Permission::AdminRefundsView, Permission::AdminRefundsCreate, Permission::AdminRefundsApprove, Permission::AdminRefundsIssue, Permission::AdminRefundsReconcile,
        Permission::AdminDisputesView, Permission::AdminOrdersView, Permission::AdminOrdersOverride, Permission::AdminPaymentsView, Permission::AdminSystemView,
    ];

    private function nextSlot(): string
    {
        $slots = ['13:00', '13:15', '13:30', '13:45', '14:00', '14:15', '14:30', '14:45', '15:00', '15:15', '15:30', '15:45'];

        return $slots[$this->refundSlot++ % count($slots)];
    }

    /**
     * A paid order the restaurant turned down: the order is REJECTED, the payment VERIFIED and ONE refund is REQUIRED.
     *
     * @return array{0: Customer, 1: array<string, string>, 2: array<string, mixed>, 3: array<string, mixed>, 4: Refund}
     */
    protected function rejectedPaidOrder(string $phone = '+919876543210', string $reason = 'ITEM_UNAVAILABLE'): array
    {
        [$customer, $headers, , $payment, $order] = $this->paidOrder($phone, $this->nextSlot());
        $this->orderStaff();
        $this->step($order['order_number'], 'reject', ['reason' => $reason])->assertOk()->assertJsonPath('order.status', 'REJECTED');

        return [$customer, $headers, $order, $payment, $this->refundOf($order['order_number'])];
    }

    /**
     * A paid order that was made, collected and completed.
     *
     * @return array{0: Customer, 1: array<string, string>, 2: Order, 3: array<string, mixed>}
     */
    protected function completedPaidOrder(string $phone = '+919876543210'): array
    {
        [$customer, $headers, $order] = $this->readyOrder($phone, $this->nextSlot());
        $this->verifyQr($this->credentialOf($headers, $order['order_number'])['qr_payload'])->assertOk()->assertJsonPath('order.status', 'COMPLETED');

        return [$customer, $headers, $this->orderModel($order['order_number']), $order];
    }

    protected function refundOf(string $orderNumber): Refund
    {
        return Refund::query()->where('order_number', $orderNumber)->orderBy('id')->firstOrFail();
    }

    protected function balanceOf(Refund|int $refund): PaymentRefundBalance
    {
        return PaymentRefundBalance::query()->whereKey($refund instanceof Refund ? $refund->payment_attempt_id : $refund)->firstOrFail();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    protected function finance(array $permissions = self::FINANCE): AdminUser
    {
        return $this->adminFor($permissions);
    }

    protected function refundGet(string $path = ''): TestResponse
    {
        return $this->adminGet('/refunds'.$path);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function refundPost(Refund|string $refund, string $action, array $body = []): TestResponse
    {
        return $this->adminPost('/refunds/'.($refund instanceof Refund ? $refund->public_id : $refund).'/'.$action, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function askRefund(string $orderNumber, array $body, array $headers = []): TestResponse
    {
        $this->act();

        return $this->postJson('/api/v1/admin/orders/'.$orderNumber.'/refunds', $body, $headers);
    }

    /**
     * The development simulator (the FAKE provider's side), as the signed-in administrator.
     *
     * @param  array<string, mixed>  $body
     */
    protected function devPost(string $path, array $body = []): TestResponse
    {
        $this->act();

        return $this->postJson('/api/v1/dev'.$path, $body);
    }

    /**
     * Approved by a person and sent to the (fake) provider. What the refund becomes is the provider's answer.
     *
     * @return array<string, mixed> the refund as the administrator's API answers it after the execution
     */
    protected function approveAndExecute(Refund $refund): array
    {
        $this->refundPost($refund, 'approve', ['reason' => 'Checked against the order'])->assertOk()->assertJsonPath('refund.status', 'APPROVED');

        return $this->refundPost($refund, 'execute')->assertOk()->json('refund');
    }

    /**
     * The bank finishes (or fails) the refund the fake provider holds for this record.
     *
     * @param  'processed'|'failed'|'pending'  $outcome
     */
    protected function providerSettlesRefund(Refund $refund, string $outcome = 'processed'): ProviderRefund
    {
        $held = $this->fakeProvider()->refundByReceipt((string) $refund->refresh()->provider_receipt);
        $this->assertNotNull($held, 'The fake provider holds a refund for this record.');

        return $this->fakeProvider()->settleRefund($held->reference, $outcome);
    }

    /**
     * The provider sends a refund webhook: the raw body with its signature and event id, with no session.
     */
    protected function providerSendsRefund(string $type, ProviderRefund $refund, ?string $eventId = null): TestResponse
    {
        [$body, $headers] = $this->fakeProvider()->refundWebhook($type, $refund, $eventId);

        return $this->sendWebhook($body, $headers);
    }

    protected function providerSendsDispute(string $type, ProviderDispute $dispute, ?string $eventId = null, ?int $createdAt = null): TestResponse
    {
        [$body, $headers] = $this->fakeProvider()->disputeWebhook($type, $dispute, $eventId, $createdAt);

        return $this->sendWebhook($body, $headers);
    }
}
