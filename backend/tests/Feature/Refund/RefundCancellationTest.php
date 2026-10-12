<?php

namespace Tests\Feature\Refund;

use App\Enums\RefundStatus;
use App\Models\AuditEvent;
use App\Models\PickupSlotReservation;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — cancelling an order and what that does to the money.
 *
 * CUSTOMER CANCELLATION = DISABLED, PENDING BUSINESS / LEGAL APPROVAL: by default a customer cannot cancel, and the
 * quote says so. The tests that switch a policy on show the mechanism behind the switch — in which states an order
 * may be cancelled and what happens to the payment are configuration, never the client's choice.
 */
class RefundCancellationTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.order_cancel' => 1000]);
    }

    public function test_cancellation_requests_are_rate_limited_per_customer(): void
    {
        config(['rate_limits.order_cancel' => 2]);
        [, $headers, , , $order] = $this->paidOrder('+919876543210', $this->nextSlot());
        $this->cancel($headers, $order['order_number'])->assertStatus(409);
        $this->cancel($headers, $order['order_number'])->assertStatus(409);
        $this->cancel($headers, $order['order_number'])->assertStatus(429);
        // The quote is a read and is not counted with the cancellations.
        $this->assertFalse($this->quote($headers, $order['order_number'])['can_cancel']);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    private function cancel(array $headers, string $order, array $body = ['reason_code' => 'CHANGED_MIND']): TestResponse
    {
        return $this->postJson('/api/v1/customer/orders/'.$order.'/cancel', $body, $this->using($headers));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function quote(array $headers, string $order): array
    {
        return $this->getJson('/api/v1/customer/orders/'.$order.'/cancellation', $this->using($headers))->assertOk()->json('cancellation');
    }

    public function test_customer_cancellation_is_disabled_until_a_policy_is_approved(): void
    {
        [, $headers, , , $order] = $this->paidOrder('+919876543210', $this->nextSlot());
        $n = $order['order_number'];

        $quote = $this->quote($headers, $n);
        $this->assertSame([false, 'PENDING_BUSINESS_APPROVAL', false, 'CUSTOMER_CANCELLATION_DISABLED', null, null, []], [$quote['enabled'], $quote['policy_status'], $quote['can_cancel'], $quote['reason'], $quote['financial_effect'], $quote['refundable_amount'], $quote['reasons']]);
        $this->assertSame(['enabled' => false, 'can_cancel' => false, 'reason' => 'CUSTOMER_CANCELLATION_DISABLED'], $this->showOrder($headers, $n)->json('order.customer_cancellation'));

        $this->cancel($headers, $n)->assertStatus(409)->assertJsonPath('error.code', 'order_cancellation_not_allowed')->assertJsonPath('error.details.reason', 'CUSTOMER_CANCELLATION_DISABLED');
        $model = $this->orderModel($n);
        $this->assertSame(['WAITING_FOR_RESTAURANT', 'NONE'], [$model->status->value, $model->refund_status->value]);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame('ACTIVE', $model->pickupCredential->status->value);
    }

    public function test_with_a_full_refund_policy_the_customer_cancels_and_the_refund_is_owed_not_made(): void
    {
        config(['refunds.customer_cancellation.enabled' => true, 'refunds.customer_cancellation.financial_effect' => 'FULL_REFUND', 'refunds.customer_cancellation.policy_version' => 'test-policy-1']);
        [$customer, $headers, , $payment, $order] = $this->paidOrder('+919876543210', $this->nextSlot());
        $n = $order['order_number'];

        // The server says what cancelling means — before the customer confirms.
        $quote = $this->quote($headers, $n);
        $this->assertSame([true, 'CONFIGURED', 'test-policy-1', true, null, 'FULL_REFUND', $this->inr(50000)], [$quote['enabled'], $quote['policy_status'], $quote['policy_version'], $quote['can_cancel'], $quote['reason'], $quote['financial_effect'], $quote['refundable_amount']]);
        $this->assertSame(['CHANGED_MIND', 'ORDERED_BY_MISTAKE', 'PICKUP_TIME_NO_LONGER_WORKS', 'OTHER'], $quote['reasons']);

        // The customer names a reason — never an amount, an effect or a state.
        $this->cancel($headers, $n, [])->assertStatus(422);
        $this->cancel($headers, $n, ['reason_code' => 'BECAUSE'])->assertStatus(422);
        foreach ([['refund_amount' => 99999], ['refund_amount_minor' => 99999], ['amount_minor' => 1], ['currency' => 'USD'], ['financial_effect' => 'FULL_REFUND'], ['refund_status' => 'REFUNDED'], ['status' => 'COMPLETED'], ['customer_id' => 1]] as $forged) {
            $this->cancel($headers, $n, ['reason_code' => 'CHANGED_MIND'] + $forged)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->assertSame('WAITING_FOR_RESTAURANT', $this->orderModel($n)->status->value);

        $cancelled = $this->cancel($headers, $n, ['reason_code' => 'PICKUP_TIME_NO_LONGER_WORKS', 'note' => 'Stuck in traffic'])->assertOk()->json('order');
        // The order is CANCELLED; the refund is a separate thing and it is REQUIRED — not refunded.
        $this->assertSame(['CANCELLED', 'PAID', 'REQUIRED', 'AWAIT_REFUND'], [$cancelled['status'], $cancelled['payment_state'], $cancelled['refund_status'], $cancelled['next_action']]);
        $this->assertSame(['by' => 'YOU', 'message' => 'This order was cancelled. A refund is due — its status is shown with this order.'], $cancelled['cancellation']);
        $this->assertNotNull($cancelled['times']['cancelled_at']);
        $this->assertNull($cancelled['pickup_credential']);
        $refund = Refund::query()->sole();
        $this->assertSame([RefundStatus::Required, 'CUSTOMER_CANCELLATION', 'PICKUP_TIME_NO_LONGER_WORKS', 'CUSTOMER', (string) $customer->public_id, 50000], [$refund->status, $refund->reason_code->value, $refund->reason_detail, $refund->requested_by_type, $refund->requested_by_public_id, $refund->requested_amount_minor]);
        $this->assertSame('MANUAL_REVIEW', $refund->policy_decision, 'a customer\'s cancellation is not refunded automatically unless the reason is approved for it');
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
        // Who ended it, when and why are on the order and in the audit trail.
        $model = $this->orderModel($n);
        $this->assertSame(['CUSTOMER', 'CUSTOMER_CANCELLATION', 'PICKUP_TIME_NO_LONGER_WORKS: Stuck in traffic'], [$model->cancelled_by_type, $model->cancellation_reason_code, $model->cancellation_reason]);
        $audit = AuditEvent::query()->where('action', 'order.cancelled')->sole();
        $this->assertSame([(string) $customer->public_id, 'PICKUP_TIME_NO_LONGER_WORKS'], [(string) $audit->actor_public_id, $audit->reason]);
        // The pickup place is given back and the credential can never be used.
        $this->assertSame('RELEASED', PickupSlotReservation::query()->where('reference', $order['id'])->sole()->status);
        $this->assertSame('REVOKED', $model->pickupCredential->status->value);

        // Asked twice: cancelled once, one refund.
        $this->cancel($headers, $n)->assertOk()->assertJsonPath('order.status', 'CANCELLED');
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame([false, 'ORDER_ALREADY_CLOSED'], [$this->quote($headers, $n)['can_cancel'], $this->quote($headers, $n)['reason']]);
        // The restaurant can no longer accept it.
        $this->orderStaff();
        $this->step($n, 'accept')->assertStatus(409)->assertJsonPath('error.code', 'order_invalid_transition');
    }

    public function test_when_the_restaurant_moves_the_order_first_the_customers_cancellation_loses(): void
    {
        config(['refunds.customer_cancellation.enabled' => true, 'refunds.customer_cancellation.financial_effect' => 'FULL_REFUND']);
        [, $headers, , , $order] = $this->paidOrder('+919876543210', $this->nextSlot());
        $n = $order['order_number'];
        $seen = (int) $order['version'];

        // The customer looks at the quote (cancellable) — and meanwhile the restaurant accepts and starts preparing.
        $this->assertTrue($this->quote($headers, $n)['can_cancel']);
        $this->orderStaff();
        $this->step($n, 'accept')->assertOk();
        $this->step($n, 'start-preparing')->assertOk();

        // The policy sees the state as it IS, under the order's lock — whatever the customer's screen showed.
        $this->cancel($headers, $n, ['reason_code' => 'CHANGED_MIND', 'expected_version' => $seen])->assertStatus(409)->assertJsonPath('error.code', 'order_version_conflict');
        $this->cancel($headers, $n)->assertStatus(409)->assertJsonPath('error.code', 'order_cancellation_not_allowed')->assertJsonPath('error.details.reason', 'ORDER_STATE_NOT_CANCELLABLE')->assertJsonPath('error.details.current_status', 'PREPARING');
        $quote = $this->quote($headers, $n);
        $this->assertSame([false, 'ORDER_STATE_NOT_CANCELLABLE', null], [$quote['can_cancel'], $quote['reason'], $quote['financial_effect']]);
        $model = $this->orderModel($n);
        $this->assertSame(['PREPARING', 'NONE', 'ACTIVE'], [$model->status->value, $model->refund_status->value, $model->pickupCredential->status->value]);
        $this->assertSame(0, Refund::query()->count());

        // A policy that allows more states allows the cancellation there too — it is configuration, not code.
        config(['refunds.customer_cancellation.allowed_statuses' => ['WAITING_FOR_RESTAURANT', 'ACCEPTED', 'PREPARING']]);
        $this->cancel($headers, $n)->assertOk()->assertJsonPath('order.status', 'CANCELLED')->assertJsonPath('order.refund_status', 'REQUIRED');
    }

    public function test_the_policy_decides_the_financial_effect_no_refund_or_a_person_decides(): void
    {
        // NO_REFUND: the order is cancelled and no refund is created — and nothing says one is coming.
        config(['refunds.customer_cancellation.enabled' => true, 'refunds.customer_cancellation.financial_effect' => 'NO_REFUND']);
        [, $a, , , $first] = $this->paidOrder('+919876543210', $this->nextSlot());
        $this->assertSame(['NO_REFUND', null], [$this->quote($a, $first['order_number'])['financial_effect'], $this->quote($a, $first['order_number'])['refundable_amount']]);
        $cancelled = $this->cancel($a, $first['order_number'])->assertOk()->json('order');
        $this->assertSame(['CANCELLED', 'NONE', 'ORDER_AGAIN', []], [$cancelled['status'], $cancelled['refund_status'], $cancelled['next_action'], $cancelled['refund']['items']]);
        $this->assertSame(0, Refund::query()->count());
        $this->assertStringNotContainsString('refund is due', $cancelled['cancellation']['message']);
        // An administrator can still decide a refund for it afterwards (service recovery) — a request, approved by finance.
        $this->finance();
        $this->askRefund($first['order_number'], ['type' => 'PARTIAL', 'amount_minor' => 25000, 'reason_code' => 'CUSTOMER_CANCELLATION'])->assertCreated()->assertJsonPath('refund.status', 'REQUESTED');

        // MANUAL_REVIEW: a refund is ASKED for (REQUESTED) — a person decides; the customer is not told money is owed.
        config(['refunds.customer_cancellation.financial_effect' => 'MANUAL_REVIEW']);
        [$customer, $b, , , $second] = $this->paidOrder('+919876543212', $this->nextSlot());
        $this->assertSame('MANUAL_REVIEW', $this->quote($b, $second['order_number'])['financial_effect']);
        $cancelled = $this->cancel($b, $second['order_number'])->assertOk()->json('order');
        $this->assertSame(['CANCELLED', 'NONE', []], [$cancelled['status'], $cancelled['refund_status'], $cancelled['refund']['items']]);
        $this->assertSame('This order was cancelled. We will review the payment for it and update you here.', $cancelled['cancellation']['message']);
        $refund = $this->refundOf($second['order_number']);
        $this->assertSame([RefundStatus::Requested, 'CUSTOMER', (string) $customer->public_id, 50000], [$refund->status, $refund->requested_by_type, $refund->requested_by_public_id, $refund->requested_amount_minor]);
        // Finance approves: now it is owed and on its way.
        $this->finance();
        $this->fakeProvider()->refundsBecome('processed');
        $this->approveAndExecute($refund);
        $this->assertSame(['CANCELLED', 'REFUNDED'], [$this->showOrder($b, $second['order_number'])->json('order.status'), $this->showOrder($b, $second['order_number'])->json('order.refund_status')]);

        // A zero-total order: cancelling has no financial effect at all.
        [, $c, , $free] = $this->zeroTotalOrder('+919876543213');
        $this->assertSame(['NO_PAYMENT', null], [$this->quote($c, $free['order_number'])['financial_effect'], $this->quote($c, $free['order_number'])['refundable_amount']]);
        $this->cancel($c, $free['order_number'])->assertOk()->assertJsonPath('order.refund_status', 'NONE')->assertJsonPath('order.refund', null);
    }

    public function test_an_administrators_cancellation_records_who_and_why_and_a_completed_order_is_not_cancelled(): void
    {
        [, $headers, , , $order] = $this->paidOrder('+919876543210', $this->nextSlot());
        $n = $order['order_number'];
        $admin = $this->finance();

        $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'x', 'reason_code' => 'RESTAURANT_REJECTED'])->assertStatus(422);   // a restaurant's rejection is not an admin's reason
        $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'Refund in full', 'refund_amount' => 1])->assertStatus(422);
        $cancelled = $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'Customer called: cannot travel', 'reason_code' => 'CUSTOMER_CANCELLATION'])->assertOk()->json('order');
        $this->assertSame(['CANCELLED', 'REQUIRED'], [$cancelled['status'], $cancelled['refund_status']]);
        $this->assertSame(['reason' => 'Customer called: cannot travel', 'by' => 'ADMIN', 'reason_code' => 'CUSTOMER_CANCELLATION'], $cancelled['cancellation']);
        $refund = Refund::query()->sole();
        $this->assertSame(['CUSTOMER_CANCELLATION', 'ADMIN', (string) $admin->public_id], [$refund->reason_code->value, $refund->requested_by_type, $refund->requested_by_public_id]);
        $this->assertSame('FOODONTHEGO', $this->showOrder($headers, $n)->json('order.cancellation.by'));

        // A completed order is never turned into a cancelled one: service recovery is a refund, the order stays COMPLETED.
        [, , $done] = $this->completedPaidOrder('+919876543212');
        $this->finance();
        $this->adminPost('/orders/'.$done->order_number.'/cancel', ['reason' => 'Late complaint'])->assertStatus(409)->assertJsonPath('error.code', 'order_invalid_transition');
        $this->askRefund((string) $done->order_number, ['type' => 'PARTIAL', 'amount_minor' => 10000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated();
        $this->assertSame('COMPLETED', $done->refresh()->status->value);
    }
}
