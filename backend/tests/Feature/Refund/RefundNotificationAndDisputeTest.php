<?php

namespace Tests\Feature\Refund;

use App\Enums\DisputeStatus;
use App\Enums\Permission;
use App\Enums\ProviderFailure;
use App\Enums\RefundStatus;
use App\Models\CustomerNotification;
use App\Models\PaymentDispute;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsNotifications;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — what the customer is told about a refund (through the Module 33 notification domain), and the
 * dispute / chargeback foundation.
 *
 * "Refund completed" is said only on the provider's confirmation — never when a refund is merely owed, approved or
 * on its way. A dispute is not a refund: it is recorded from verified provider events, read-only, and while one is
 * open no refund of that payment is approved or sent.
 */
class RefundNotificationAndDisputeTest extends TestCase
{
    use BuildsNotifications, BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /** @return list<string> */
    private function typesOf(int $customerId): array
    {
        return CustomerNotification::query()->where('customer_id', $customerId)->orderBy('id')->pluck('event_type')->all();
    }

    public function test_the_customer_hears_about_a_refund_at_each_real_step_and_completed_only_on_the_providers_word(): void
    {
        [$customer, $headers, $order, , $refund] = $this->rejectedPaidOrder();
        $n = $order['order_number'];

        // REQUIRED: a refund is due — nothing says it is on its way, and nothing says it is done.
        $due = $this->notificationOf($customer, 'refund.pending');
        $this->assertNotNull($due);
        $this->assertSame(['Refund due', 2, 'PAYMENT_UPDATES', 'PAYMENT_STATUS', $n], [$due->title, (int) $due->template_version, $due->category->value, $due->action_target->value, $due->action_reference]);
        $rejected = $this->notificationOf($customer, 'order.rejected');
        $this->assertStringContainsString('A refund is due for your payment; we are arranging it.', $rejected->body);
        foreach (CustomerNotification::query()->where('customer_id', $customer->getKey())->get() as $notice) {
            $this->assertStringNotContainsStringIgnoringCase('completed', $notice->title.' '.$notice->body);
            $this->assertStringNotContainsStringIgnoringCase('refunded', $notice->title.' '.$notice->body);
            $this->assertDoesNotMatchRegularExpression('/\d+\s*(–|-|to)?\s*\d*\s*(working |business )?days/i', $notice->body, 'no number of days is promised');
        }

        // APPROVED: an internal step — the customer is not written to.
        $this->finance();
        $this->refundPost($refund, 'approve')->assertOk();
        $this->assertNull($this->notificationOf($customer, 'refund.initiated'));
        $this->assertNull($this->notificationOf($customer, 'refund.completed'));

        // PROCESSING: the provider has it — "initiated", with the amount, to the method that paid. Still not "completed".
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'PROCESSING');
        $initiated = $this->notificationOf($customer, 'refund.initiated');
        $this->assertNotNull($initiated);
        $this->assertSame('Refund initiated', $initiated->title);
        $this->assertStringContainsString('₹500.00', $initiated->body);
        $this->assertStringContainsString($n, $initiated->body);
        $this->assertSame(['ORDER', $n, 'PAYMENT_STATUS'], [$initiated->subject_type, $initiated->subject_reference, $initiated->action_target->value]);
        $this->assertNull($this->notificationOf($customer, 'refund.completed'), 'PROCESSING never produces "refund completed"');

        // REFUNDED (the provider's webhook): "completed" — once, however often the event is delivered.
        $settled = $this->providerSettlesRefund($refund, 'processed');
        $this->providerSendsRefund('refund.processed', $settled, 'fakeevt_notify_0001')->assertOk();
        $this->providerSendsRefund('refund.processed', $settled, 'fakeevt_notify_0002')->assertOk();
        $completed = $this->notificationOf($customer, 'refund.completed');
        $this->assertNotNull($completed);
        $this->assertSame('Refund completed', $completed->title);
        $this->assertStringContainsString('₹500.00', $completed->body);
        $this->assertStringContainsString('depends on your bank', $completed->body);
        $this->assertSame(1, CustomerNotification::query()->where('event_type', 'refund.completed')->count());
        $this->assertEqualsCanonicalizing(['order.created', 'order.rejected', 'refund.pending', 'refund.initiated', 'refund.completed'], $this->typesOf((int) $customer->getKey()));
        $this->assertSame(['refund.pending', 'refund.initiated', 'refund.completed'], array_values(array_filter($this->typesOf((int) $customer->getKey()), fn (string $t): bool => str_starts_with($t, 'refund.'))), 'due, then initiated, then completed — in that order');

        // It is in the customer's inbox and opens the order.
        $inbox = collect($this->inbox($headers)->assertOk()->json('data'));
        $this->assertSame(['refund.completed', 'refund.initiated', 'refund.pending'], $inbox->pluck('type')->filter(fn (string $t): bool => str_starts_with($t, 'refund.'))->values()->all());
        // The refund's amount is the only figure a notice carries; no provider reference, no receipt.
        $this->assertStringNotContainsString('fakerfnd_', json_encode($inbox));
        $this->assertStringNotContainsString('rf-', json_encode($inbox->pluck('body')));
    }

    public function test_a_refund_that_fails_or_needs_a_person_tells_the_customer_once_and_never_says_completed(): void
    {
        $this->fakeProvider()->refundsBecome('failed');
        [$customer, , , , $refund] = $this->rejectedPaidOrder();
        $this->finance();
        $this->approveAndExecute($refund);

        $attention = $this->notificationOf($customer, 'refund.attention');
        $this->assertNotNull($attention);
        $this->assertSame('Your refund is taking longer', $attention->title);
        $this->assertStringContainsString('you do not need to do anything', $attention->body);
        $this->assertStringNotContainsStringIgnoringCase('failed', $attention->body);
        $this->assertNull($this->notificationOf($customer, 'refund.completed'));
        $this->assertNull($this->notificationOf($customer, 'refund.initiated'), 'it never reached PROCESSING');

        // Tried again and failed again: the customer is not written to a second time.
        $this->refundPost($refund, 'retry', ['reason' => 'Second try'])->assertOk()->assertJsonPath('refund.status', 'FAILED');
        $this->assertSame(1, CustomerNotification::query()->where('event_type', 'refund.attention')->count());

        // A third try succeeds: now — and only now — "completed".
        $this->fakeProvider()->refundsBecome('processed');
        $this->refundPost($refund, 'retry', ['reason' => 'Third try'])->assertOk()->assertJsonPath('refund.status', 'REFUNDED');
        $this->assertSame(1, CustomerNotification::query()->where('event_type', 'refund.completed')->count());

        // A request nobody approved, and its rejection, notify nobody.
        [$other, , $completed] = $this->completedPaidOrder('+919876543212');
        $this->finance();
        $asked = $this->askRefund((string) $completed->order_number, ['type' => 'PARTIAL', 'amount_minor' => 5000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated()->json('refund.id');
        $this->refundPost($asked, 'reject', ['reason' => 'Not justified'])->assertOk();
        $this->assertSame([], array_values(array_filter($this->typesOf((int) $other->getKey()), fn (string $t): bool => str_starts_with($t, 'refund.'))));
    }

    public function test_a_notification_problem_never_touches_the_refund(): void
    {
        [$customer, , , , $refund] = $this->rejectedPaidOrder();
        // The templates of the refund notices are gone (a deployment mistake): the refund still completes.
        config(['notifications.types' => array_diff_key((array) config('notifications.types'), array_flip(['refund.initiated', 'refund.completed', 'refund.attention']))]);
        app()->forgetInstance(NotificationRegistry::class);
        $this->fakeProvider()->refundsBecome('processed');
        $this->finance();

        $this->assertSame('REFUNDED', $this->approveAndExecute($refund)['status']);
        $this->assertNull($this->notificationOf($customer, 'refund.completed'));
        $this->assertSame(50000, $this->balanceOf($refund)->refunded_minor);
    }

    public function test_a_dispute_is_recorded_from_verified_provider_events_and_is_not_a_refund(): void
    {
        [, , , , $order] = $this->paidOrder('+919876543210', $this->nextSlot());
        $attempt = $this->orderModel($order['order_number'])->paymentAttempt;
        $pay = (string) $attempt->provider_payment_reference;

        // The customer's bank opens a chargeback; the provider tells us with a signed event.
        $opened = $this->fakeProvider()->openDispute($pay, null, 'goods_not_received');
        $this->providerSendsDispute('payment.dispute.created', $opened, 'fakeevt_dispute_0001')->assertOk()->assertExactJson(['received' => true]);
        $dispute = PaymentDispute::query()->sole();
        $this->assertSame([DisputeStatus::Open, 'fake', $opened->reference, $pay, 50000, 'INR', 0, 'chargeback', 'goods_not_received', $order['order_number']],
            [$dispute->status, $dispute->provider, $dispute->provider_dispute_reference, $dispute->provider_payment_reference, $dispute->amount_minor, $dispute->currency, $dispute->amount_deducted_minor, $dispute->phase, $dispute->reason_code, $dispute->order_number]);
        $this->assertNotNull($dispute->respond_by_at);
        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame(['DISPUTE_CREATED', 'PROCESSED', 'DISPUTE_OPEN', $opened->reference], [$event->normalized_type, $event->processing_status, $event->outcome, $event->provider_dispute_reference]);
        // It is not a refund and changes neither the order, the payment nor any refund.
        $model = $this->orderModel($order['order_number']);
        $this->assertSame([0, 'WAITING_FOR_RESTAURANT', 'NONE', 'VERIFIED'], [Refund::query()->count(), $model->status->value, $model->refund_status->value, $attempt->refresh()->status->value]);
        // A wrong signature writes nothing.
        [$body, $headers] = $this->fakeProvider()->disputeWebhook('payment.dispute.lost', $this->fakeProvider()->dispute($opened->reference));
        $this->sendWebhook($body, ['x-fake-signature' => str_repeat('0', 64)] + $headers)->assertStatus(401);
        $this->assertSame(DisputeStatus::Open, $dispute->refresh()->status);

        // Administrators with admin.disputes.view read it; nobody can change it here.
        $this->adminFor([Permission::AdminRefundsView]);
        $this->adminGet('/disputes')->assertForbidden();
        $this->adminGet('/disputes/'.$dispute->public_id)->assertForbidden();
        $this->finance();
        $listed = $this->adminGet('/disputes?filter[open]=1')->assertOk()->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame([(string) $dispute->public_id, 'OPEN', true, $this->inr(50000), $this->inr(0), 'goods_not_received', ['respond' => 'NOT_PROVIDED', 'accept' => 'NOT_PROVIDED']],
            [$listed[0]['id'], $listed[0]['status'], $listed[0]['open'], $listed[0]['amount'], $listed[0]['amount_deducted'], $listed[0]['reason_code'], $listed[0]['actions']]);
        $this->assertSame($opened->reference, $this->adminGet('/disputes/'.$dispute->public_id)->assertOk()->json('dispute.provider_dispute_reference'));
        $this->assertSame([], $this->adminGet('/disputes?filter[open]=0')->json('data'));
        $this->assertCount(1, $this->adminGet('/disputes?q='.$order['order_number'])->json('data'));
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $this->json($method, '/api/v1/admin/disputes/'.$dispute->public_id, ['status' => 'WON'])->assertStatus(405);
        }
        $this->postJson('/api/v1/admin/disputes/'.$dispute->public_id.'/accept')->assertNotFound();
        // The payment's detail names it; the customer's order does not.
        $this->assertSame([['id' => (string) $dispute->public_id, 'status' => 'OPEN', 'open' => true]], array_map(fn (array $d): array => array_intersect_key($d, array_flip(['id', 'status', 'open'])), $this->adminGet('/payments/'.$attempt->public_id)->assertOk()->json('payment.disputes')));

        // Under review, then decided — and a late "created" event does not re-open a decided dispute.
        $this->providerSendsDispute('payment.dispute.under_review', $this->fakeProvider()->settleDispute($opened->reference, DisputeStatus::UnderReview))->assertOk();
        $this->assertSame(DisputeStatus::UnderReview, $dispute->refresh()->status);
        $won = $this->fakeProvider()->settleDispute($opened->reference, DisputeStatus::Won);
        $this->providerSendsDispute('payment.dispute.won', $won)->assertOk();
        $this->fakeProvider()->failNext('dispute', ProviderFailure::Unavailable, 5);   // the provider cannot be asked: only the stale event's content is there
        $this->providerSendsDispute('payment.dispute.created', $opened, 'fakeevt_dispute_late')->assertOk();
        $this->fakeProvider()->recover();
        $dispute->refresh();
        $this->assertSame([DisputeStatus::Won, 0], [$dispute->status, $dispute->amount_deducted_minor]);
        $this->assertNotNull($dispute->closed_at);
        $this->assertSame('DISPUTE_STALE_WON', PaymentWebhookEvent::query()->where('provider_event_reference', 'fakeevt_dispute_late')->value('outcome'));
        $this->assertSame(1, PaymentDispute::query()->count());
    }

    public function test_while_a_dispute_is_open_no_refund_of_that_payment_is_asked_approved_or_sent(): void
    {
        // A refund is already owed and approved when the dispute opens.
        [, $customer, $order, $payment, $refund] = $this->rejectedPaidOrder();
        $this->finance();
        $this->refundPost($refund, 'approve')->assertOk();
        $pay = (string) $this->attempt($payment['id'])->provider_payment_reference;
        $opened = $this->fakeProvider()->openDispute($pay);
        $this->providerSendsDispute('payment.dispute.created', $opened)->assertOk();

        // Sending it now could return the same money twice: it is not sent — a person looks.
        $this->finance();
        $held = $this->refundPost($refund, 'execute')->assertOk()->json('refund');
        $this->assertSame(['REVIEW_REQUIRED', 'DISPUTE_OPEN', $this->inr(50000)], [$held['status'], $held['review_reason'], $held['balance']['in_flight']]);
        $this->assertSame(0, RefundAttempt::query()->count(), 'nothing reached the provider');
        $this->assertSame([], $this->fakeProvider()->refundsOfPayment($pay, false));
        $this->assertSame([['status' => 'OPEN', 'open' => true]], array_map(fn (array $d): array => array_intersect_key($d, array_flip(['status', 'open'])), $held['disputes']));
        $this->refundPost($refund, 'approve')->assertStatus(409)->assertJsonPath('error.code', 'refund_dispute_conflict')->assertJsonPath('error.details.dispute_open', true);
        // For the customer a refund that needs a person is "under review"; the internal word stays on the order record.
        $this->assertSame('UNDER_REVIEW', $this->showOrder($customer, $order['order_number'])->json('order.refund_status'));
        $this->assertSame('REVIEW_REQUIRED', $this->orderModel($order['order_number'])->refund_status->value);

        // A second payment: disputed BEFORE its order is rejected. The refund is recorded as owed and held for a person.
        [, , $other, $otherPayment] = $this->paidOrder('+919876543212', $this->nextSlot()) + [4 => null];
        $otherOrder = $this->orderModel($otherPayment['fulfillment']['order_number']);
        $second = $this->fakeProvider()->openDispute((string) $otherOrder->paymentAttempt->provider_payment_reference);
        $this->providerSendsDispute('payment.dispute.created', $second)->assertOk();
        config(['refunds.policy.auto_approve_reasons' => ['RESTAURANT_REJECTED']]);   // even a reason approved for automatic refunds
        $this->orderStaff();
        $this->step((string) $otherOrder->order_number, 'reject', ['reason' => 'CLOSING'])->assertOk();
        $owed = $this->refundOf((string) $otherOrder->order_number);
        $this->assertSame([RefundStatus::ReviewRequired, 'NOT_ELIGIBLE', 'REFUND_DISPUTE_CONFLICT'], [$owed->status, $owed->policy_decision, $owed->review_reason]);
        // An administrator cannot ask for another one on top either.
        $this->finance();
        $this->adminPost('/orders/'.$otherOrder->order_number.'/refunds', ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'On top'])->assertStatus(409)->assertJsonPath('error.code', 'refund_dispute_conflict');

        // The first dispute is WON (the money stays with the merchant): the refund can go on, approved again by a person.
        $this->providerSendsDispute('payment.dispute.won', $this->fakeProvider()->settleDispute($opened->reference, DisputeStatus::Won))->assertOk();
        $this->fakeProvider()->refundsBecome('processed');
        $this->finance();
        $this->refundPost($refund, 'approve', ['reason' => 'Dispute won; refund still owed for the rejection'])->assertOk()->assertJsonPath('refund.status', 'APPROVED');
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'REFUNDED');

        // The second dispute is LOST: the bank took the money back. What it took can no longer be refunded.
        $this->providerSendsDispute('payment.dispute.lost', $this->fakeProvider()->settleDispute($second->reference, DisputeStatus::Lost))->assertOk();
        $lost = PaymentDispute::query()->where('provider_dispute_reference', $second->reference)->sole();
        $this->assertSame([DisputeStatus::Lost, 50000], [$lost->status, $lost->amount_deducted_minor]);
        $this->assertSame(50000, $this->balanceOf($owed)->charged_back_minor);
        $detail = $this->refundGet('/'.$owed->public_id)->assertOk()->json('refund');
        $this->assertSame(['REVIEW_REQUIRED', $this->inr(50000), $this->inr(0)], [$detail['status'], $detail['balance']['charged_back'], $detail['balance']['available']]);
        // The owed refund is closed by a person with the reason — the customer already has the money through the chargeback.
        $this->refundPost($owed, 'reject', ['reason' => 'Returned through the lost chargeback'])->assertOk()->assertJsonPath('refund.status', 'CANCELLED');
        $this->adminPost('/orders/'.$otherOrder->order_number.'/refunds', ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'After chargeback'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_completed');
        $this->assertSame([], $this->fakeProvider()->refundsOfPayment((string) $otherOrder->paymentAttempt->provider_payment_reference, false), 'no double compensation');
        $this->assertNotNull($other);
    }
}
