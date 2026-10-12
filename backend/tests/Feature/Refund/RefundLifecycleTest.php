<?php

namespace Tests\Feature\Refund;

use App\Enums\RefundStatus;
use App\Events\Order\OrderEvent;
use App\Events\Refund\RefundEvent;
use App\Models\AuditEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Models\RefundEvent as Line;
use App\Services\Refund\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — the refund's life, from the condition that owes money to the provider's confirmation.
 *
 * Order, payment and refund are three states: a rejected order is REJECTED, its payment stays VERIFIED and its
 * refund is REQUIRED → APPROVED → PROCESSING → REFUNDED. Nothing is "refunded" before the provider says so.
 */
class RefundLifecycleTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_rejected_paid_order_owes_one_refund_and_nothing_is_refunded_before_the_provider_confirms(): void
    {
        [, $customer, $order, $payment, $refund] = $this->rejectedPaidOrder();
        $n = $order['order_number'];

        // Example 1 of the financial states: order REJECTED, payment VERIFIED, refund REQUIRED.
        $this->assertSame([RefundStatus::Required, 'FULL', 50000, null, 'INR', 'RESTAURANT_REJECTED', 'ITEM_UNAVAILABLE', 'RESTAURANT_USER'],
            [$refund->status, $refund->type, $refund->requested_amount_minor, $refund->approved_amount_minor, $refund->currency, $refund->reason_code->value, $refund->reason_detail, $refund->requested_by_type]);
        $this->assertSame($payment['id'], $refund->payment_public_id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', (string) $refund->public_id);
        $this->assertMatchesRegularExpression('/\ARFD-[0-9A-F]{10}\z/', $refund->supportReference());
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
        // The policy looked at it: no reason is approved for automatic refunds, so a person decides.
        $this->assertSame(['MANUAL_REVIEW', 'REASON_NOT_APPROVED_FOR_AUTOMATIC_REFUND'], [$refund->policy_decision, $refund->policy_reason]);
        // The whole payment is held by the refund: nothing else can be refunded meanwhile.
        $balance = $this->balanceOf($refund);
        $this->assertSame([50000, 50000, 0, 0], [$balance->paid_minor, $balance->reserved_minor, $balance->refunded_minor, $balance->availableMinor()]);

        $mine = $this->showOrder($customer, $n)->assertOk()->json('order');
        $this->assertSame(['REJECTED', 'PAID', 'REQUIRED', 'AWAIT_REFUND'], [$mine['status'], $mine['payment_state'], $mine['refund_status'], $mine['next_action']]);
        $this->assertSame(['status' => 'REQUIRED', 'paid' => $this->inr(50000), 'refunded' => $this->inr(0), 'pending' => $this->inr(50000)], array_intersect_key($mine['refund'], array_flip(['status', 'paid', 'refunded', 'pending'])));
        $this->assertNull($mine['refund']['timing_note'], 'no number of days is promised');
        $this->assertCount(1, $mine['refund']['items']);
        $item = $mine['refund']['items'][0];
        $this->assertSame([(string) $refund->public_id, $refund->supportReference(), 'FULL', $this->inr(50000), 'REQUIRED', null], [$item['id'], $item['reference'], $item['type'], $item['amount'], $item['status'], $item['completed_at']]);
        $this->assertSame(['code' => 'RESTAURANT_REJECTED', 'label' => 'The restaurant could not accept the order'], $item['reason']);
        // The customer never sees a provider reference, a receipt, who approved or the policy.
        foreach (['provider', 'receipt', 'fakepay_', 'policy', 'approved', 'MANUAL_REVIEW'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($mine['refund']));
        }

        // A person approves (admin.refunds.approve) — still nothing sent, nothing refunded.
        $this->finance();
        $approved = $this->refundPost($refund, 'approve', ['reason' => 'Rejected before preparation'])->assertOk()->json('refund');
        $this->assertSame(['APPROVED', $this->inr(50000), 'MANUAL', 'Rejected before preparation'], [$approved['status'], $approved['approved_amount'], $approved['approval']['mode'], $approved['approval']['note']]);
        $this->assertSame(['approve' => false, 'reject' => true, 'execute' => true, 'retry' => false, 'reconcile' => false, 'mark_refunded' => 'NOT_PROVIDED'], $approved['actions']);
        // Approving sends nothing: for the customer the refund is still due — not "on its way".
        $mineApproved = $this->showOrder($customer, $n)->json('order');
        $this->assertSame(['REQUIRED', 'REQUIRED'], [$mineApproved['refund_status'], $mineApproved['refund']['items'][0]['status']], 'approved: still owed for the customer, not processing');
        $this->assertSame(0, RefundAttempt::query()->count());

        // Executed (admin.refunds.issue): the provider accepts it and has not confirmed it — PROCESSING (example 2).
        $this->finance();
        $sent = $this->refundPost($refund, 'execute')->assertOk()->json('refund');
        $this->assertSame('PROCESSING', $sent['status']);
        $this->assertSame(['fake', 'FAKE', 'pending', 'rf-'.str_replace('-', '', (string) $refund->public_id).'-1'], [$sent['provider_detail']['name'], $sent['provider_detail']['mode'], $sent['provider_detail']['status'], $sent['provider_detail']['receipt']]);
        $this->assertStringStartsWith('fakerfnd_', $sent['provider_detail']['refund_reference']);
        $this->assertSame($this->attempt($payment['id'])->provider_payment_reference, $sent['provider_detail']['payment_reference'], 'the refund names the payment\'s own provider transaction');
        $this->assertSame([['CREATE', 'PENDING', 1]], array_map(fn (array $a): array => [$a['operation'], $a['outcome'], $a['generation']], $sent['attempts']));
        $mine = $this->showOrder($customer, $n)->json('order');
        $this->assertSame(['REJECTED', 'PROCESSING', 'PROCESSING', $this->inr(0)], [$mine['status'], $mine['refund_status'], $mine['refund']['items'][0]['status'], $mine['refund']['refunded']]);
        $this->assertSame(50000, $this->balanceOf($refund)->reserved_minor, 'still held: not refunded yet');

        // The provider confirms (its signed webhook): REFUNDED (example 3) — and only now.
        $settled = $this->providerSettlesRefund($refund, 'processed');
        $this->providerSendsRefund('refund.processed', $settled)->assertOk();
        $refund->refresh();
        $this->assertSame(RefundStatus::Refunded, $refund->status);
        $this->assertNotNull($refund->completed_at);
        $balance = $this->balanceOf($refund);
        $this->assertSame([0, 50000, 0], [$balance->reserved_minor, $balance->refunded_minor, $balance->availableMinor()]);

        $mine = $this->showOrder($customer, $n)->json('order');
        // The order is still REJECTED, the payment still PAID / VERIFIED, the prices untouched.
        $this->assertSame(['REJECTED', 'PAID', 'REFUNDED', 'ORDER_AGAIN'], [$mine['status'], $mine['payment_state'], $mine['refund_status'], $mine['next_action']]);
        $this->assertSame([$this->inr(50000), $this->inr(50000), $this->inr(0), 'REFUNDED'], [$mine['total'], $mine['refund']['refunded'], $mine['refund']['pending'], $mine['refund']['items'][0]['status']]);
        $this->assertNotNull($mine['refund']['items'][0]['completed_at']);
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value, 'a refund never rewrites the payment');
        $this->assertSame(50000, (int) $this->attempt($payment['id'])->amount_minor);
        $this->assertSame(['REFUND_REQUIRED', 'REFUND_PROCESSING', 'REFUND_COMPLETED'], array_values(array_filter(array_column($mine['timeline'], 'type'), fn (string $t): bool => str_starts_with($t, 'REFUND_'))));

        // The refund's own timeline and the audit trail name every step and who took it.
        $this->assertSame(['required', 'policy_manual_review', 'approved', 'submitting', 'processing', 'webhook_refund_processed', 'refunded'], Line::query()->where('refund_id', $refund->getKey())->orderBy('id')->pluck('type')->all());
        $this->assertSame(['refund.approved', 'refund.executed'], AuditEvent::query()->where('target_public_id', $refund->public_id)->orderBy('id')->pluck('action')->all());

        // Nothing more can be done to a completed refund.
        $this->finance();
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'REFUNDED');
        $this->refundPost($refund, 'reject', ['reason' => 'Changed my mind'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_completed');
        $this->refundPost($refund, 'retry', ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_completed');
        $this->assertSame(1, RefundAttempt::query()->where('operation', 'CREATE')->count(), 'a completed refund is never executed twice');
    }

    public function test_the_same_refund_required_condition_reported_twice_makes_one_refund(): void
    {
        [, , $order, , $refund] = $this->rejectedPaidOrder();

        // The restaurant rejects again, and the order workflow's condition is reported once more.
        $this->step($order['order_number'], 'reject', ['reason' => 'OTHER'])->assertOk();
        $model = $this->orderModel($order['order_number']);
        $again = app(RefundService::class)->requireForOrder($model, $refund->reason_code, 'ITEM_UNAVAILABLE', 'SYSTEM', null);
        $this->assertSame((int) $refund->getKey(), (int) $again?->getKey());
        // The refund's own event delivered twice: the policy decides once.
        event(new RefundEvent(RefundEvent::REQUIRED, $refund));
        event(new RefundEvent(RefundEvent::REQUIRED, $refund));

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(50000, $this->balanceOf($refund)->reserved_minor, 'held once');
        $this->assertSame(1, Line::query()->where('refund_id', $refund->getKey())->where('type', 'policy_manual_review')->count());
    }

    public function test_a_reason_approved_for_automatic_refunds_is_approved_by_the_policy_and_submitted_once(): void
    {
        config(['refunds.policy.auto_approve_reasons' => ['RESTAURANT_REJECTED']]);
        $this->fakeProvider()->refundsBecome('processed');

        [, $customer, $order, $payment, $refund] = $this->rejectedPaidOrder();

        // The policy approved it, the queue sent it, the provider completed it — nobody clicked anything.
        $this->assertSame([RefundStatus::Refunded, 'AUTO_APPROVE', 'AUTO', null], [$refund->status, $refund->policy_decision, $refund->approval_mode, $refund->approved_by_admin_id]);
        $this->assertSame(1, RefundAttempt::query()->where('operation', 'CREATE')->count());
        $this->assertCount(1, $this->fakeProvider()->refundsOfPayment((string) $this->attempt($payment['id'])->provider_payment_reference));
        $this->assertSame(['REJECTED', 'REFUNDED'], [$this->showOrder($customer, $order['order_number'])->json('order.status'), $this->showOrder($customer, $order['order_number'])->json('order.refund_status')]);

        // The scheduled sweep and a second policy run find nothing left to do.
        app(RefundService::class)->applyPolicy((int) $refund->getKey());
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame(1, RefundAttempt::query()->where('operation', 'CREATE')->count(), 'submitted once');

        // Above the automatic limit a person decides.
        config(['refunds.policy.auto_approve_max_minor' => 10000]);
        [, , , , $second] = $this->rejectedPaidOrder('+919876543212');
        $this->assertSame([RefundStatus::Required, 'MANUAL_REVIEW', 'ABOVE_AUTOMATIC_LIMIT'], [$second->status, $second->policy_decision, $second->policy_reason]);
    }

    public function test_an_order_that_was_not_paid_never_has_a_refund_and_no_provider_is_asked(): void
    {
        [, $customer, , $order] = $this->zeroTotalOrder();
        $this->orderStaff();
        $this->step($order['order_number'], 'reject', ['reason' => 'KITCHEN_CAPACITY'])->assertOk();

        $this->assertSame(0, Refund::query()->count());
        $mine = $this->showOrder($customer, $order['order_number'])->json('order');
        $this->assertSame(['REJECTED', 'PAYMENT_NOT_REQUIRED', 'NONE', null], [$mine['status'], $mine['payment_state'], $mine['refund_status'], $mine['refund']]);

        // An administrator cannot ask for one either: nothing was charged.
        $this->finance();
        $this->askRefund($order['order_number'], ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'Goodwill'])->assertStatus(409)->assertJsonPath('error.code', 'refund_not_eligible');
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, RefundAttempt::query()->count());
    }

    public function test_the_restaurant_sees_that_money_is_going_back_and_has_no_refund_controls(): void
    {
        [, , $order, , $refund] = $this->rejectedPaidOrder();
        $n = $order['order_number'];

        $seen = $this->restaurantOrder($n)->assertOk()->json('order');
        $this->assertSame(['REJECTED', 'REQUIRED'], [$seen['status'], $seen['refund_status']]);
        $this->assertSame(['status' => 'REQUIRED', 'amount' => $this->inr(50000), 'refunded' => $this->inr(0), 'controls' => 'NOT_PROVIDED'], $seen['refund']);
        $this->assertSame([], $seen['allowed_actions']);
        // No refund id, no provider, nothing a restaurant could act on.
        $this->assertStringNotContainsString((string) $refund->public_id, json_encode($seen));
        // The refund API is the administrators': a restaurant token is not one.
        $this->getJson('/api/v1/admin/refunds')->assertStatus(401);
        $this->postJson('/api/v1/admin/refunds/'.$refund->public_id.'/execute')->assertStatus(401);
        foreach (['refund', 'refunds', 'refund/execute'] as $path) {
            $this->postJson('/api/v1/restaurant/orders/'.$n.'/'.$path)->assertNotFound();
        }

        $staff = $this->actor;
        $this->finance();
        $this->fakeProvider()->refundsBecome('processed');
        $this->approveAndExecute($refund);
        $this->asActor($staff);
        $seen = $this->restaurantOrder($n)->assertOk()->json('order');
        $this->assertSame(['REJECTED', 'REFUNDED', $this->inr(50000)], [$seen['status'], $seen['refund_status'], $seen['refund']['refunded']]);
    }

    public function test_refund_changes_are_announced_on_the_order_after_the_commit(): void
    {
        [, , $order, , $refund] = $this->rejectedPaidOrder();
        $before = (int) $this->orderModel($order['order_number'])->version;
        Event::fake([OrderEvent::class]);

        $this->finance();
        $this->fakeProvider()->refundsBecome('processed');
        $this->approveAndExecute($refund);

        // Approving changes nothing the customer is told (the refund is still due). With a provider that completes at once the
        // order goes from REQUIRED straight to REFUNDED: one notice to the order's followers, and the version moved with it.
        Event::assertDispatchedTimes(OrderEvent::class, 1);
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::REFUND_UPDATED && $e->detail['refund'] === 'REFUNDED' && $e->sequence !== null);
        $this->assertSame($before + 1, (int) $this->orderModel($order['order_number'])->version);
        $this->assertSame('REJECTED', $this->orderModel($order['order_number'])->status->value);
    }
}
