<?php

namespace Tests\Feature\Refund;

use App\Enums\ProviderFailure;
use App\Enums\ProviderRefundState;
use App\Enums\RefundStatus;
use App\Models\PaymentAttemptEvent;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Models\RefundEvent as Line;
use App\Services\Payment\Data\ProviderRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — refund webhooks and reconciliation. They come through the Module 30 webhook door: the signature over
 * the raw body is the only credential, an event is stored once however often it is delivered, and an event never
 * sets a state itself — it points at the refund and the provider's record decides. So the order in which signals
 * arrive does not matter, and a late one cannot take a confirmed refund back.
 */
class RefundWebhookTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, mixed>, 2: Refund, 3: ProviderRefund} a refund the provider accepted and has not confirmed
     */
    private function processingRefund(string $phone = '+919876543210'): array
    {
        [, $customer, $order, , $refund] = $this->rejectedPaidOrder($phone);
        $this->finance();
        $this->assertSame('PROCESSING', $this->approveAndExecute($refund)['status']);

        return [$customer, $order, $refund->refresh(), $this->fakeProvider()->refundByReceipt((string) $refund->provider_receipt)];
    }

    public function test_a_verified_refund_event_completes_the_refund_once_however_often_it_is_delivered(): void
    {
        [$customer, $order, $refund, $held] = $this->processingRefund();
        $settled = $this->providerSettlesRefund($refund, 'processed');

        $this->providerSendsRefund('refund.processed', $settled, 'fakeevt_refund_0001')->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame(RefundStatus::Refunded, $refund->refresh()->status);
        $event = PaymentWebhookEvent::query()->where('provider_event_reference', 'fakeevt_refund_0001')->sole();
        $this->assertSame(['refund.processed', 'REFUND_PROCESSED', 'PROCESSED', 'REFUNDED', $held->reference, (int) $refund->getKey(), (int) $refund->payment_attempt_id],
            [$event->event_type, $event->normalized_type, $event->processing_status, $event->outcome, $event->provider_refund_reference, (int) $event->refund_id, (int) $event->payment_attempt_id]);
        // Of the event only safe fields are kept: references, state, amount. No payload, nothing about the customer.
        $this->assertEqualsCanonicalizing(['payment', 'refund'], array_keys($event->summary));
        $this->assertEqualsCanonicalizing(['reference', 'payment', 'state', 'provider_status', 'amount_minor', 'currency', 'receipt', 'trace'], array_keys($event->summary['refund']));
        $this->assertStringNotContainsString('+91', json_encode($event->summary));

        // Delivered again (the same event id), and once more as a new event: one logical transition.
        $this->providerSendsRefund('refund.processed', $settled, 'fakeevt_refund_0001')->assertOk()->assertJsonPath('duplicate', true);
        // Module 39: a new event carries a body of its own (its own created_at); a byte-identical body under a new event id is a replay (DataProtectionTest).
        $this->travel(1)->seconds();
        $this->providerSendsRefund('refund.processed', $settled, 'fakeevt_refund_0002')->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame(2, (int) $event->refresh()->deliveries);
        $this->assertSame(1, Line::query()->where('refund_id', $refund->getKey())->where('to_status', 'REFUNDED')->count());
        $balance = $this->balanceOf($refund);
        $this->assertSame([0, 50000], [$balance->reserved_minor, $balance->refunded_minor], 'counted as refunded once');
        $this->assertSame('REFUNDED', $this->showOrder($customer, $order['order_number'])->json('order.refund_status'));
        // The administrator sees the provider's events on the refund.
        $this->finance();
        $this->assertSame([['refund.processed', 'PROCESSED', 2], ['refund.processed', 'PROCESSED', 1]], array_map(fn (array $e): array => [$e['type'], $e['processing_status'], $e['deliveries']], $this->refundGet('/'.$refund->public_id)->json('refund.webhook_events')));
    }

    public function test_a_refund_event_with_a_wrong_signature_changes_nothing(): void
    {
        [, , $refund] = $this->processingRefund();
        $settled = $this->providerSettlesRefund($refund, 'processed');
        [$body, $headers] = $this->fakeProvider()->refundWebhook('refund.processed', $settled);

        $this->sendWebhook($body, ['x-fake-signature' => str_repeat('0', 64)] + $headers)->assertStatus(401);
        $this->sendWebhook($body.' ', $headers)->assertStatus(401);                                   // one byte more than what was signed
        $this->sendWebhook(str_replace('"PROCESSED"', '"FAILED"', $body), $headers)->assertStatus(401); // a changed state under the old signature
        unset($headers['x-fake-signature']);
        $this->sendWebhook($body, $headers)->assertStatus(401);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
        $this->assertSame(RefundStatus::Processing, $refund->refresh()->status);
        $this->assertSame(50000, $this->balanceOf($refund)->reserved_minor);
    }

    public function test_events_out_of_order_never_take_a_confirmed_refund_back(): void
    {
        [, , $refund, $held] = $this->processingRefund();
        $settled = $this->providerSettlesRefund($refund, 'processed');

        // The final event first; the earlier "created" (pending) event afterwards.
        $this->providerSendsRefund('refund.processed', $settled)->assertOk();
        $this->providerSendsRefund('refund.created', $held)->assertOk();
        $this->assertSame(RefundStatus::Refunded, $refund->refresh()->status);

        // Delivered once more: still no step backwards.
        $this->providerSendsRefund('refund.created', $held)->assertOk();
        // A "failed" event after the refund was confirmed is not believed either — it is noted for a person.
        $this->providerSendsRefund('refund.failed', new ProviderRefund($held->reference, $held->paymentReference, ProviderRefundState::Failed, 'failed', $held->amount, $held->receipt));
        $refund->refresh();
        $this->assertSame([RefundStatus::Refunded, 50000], [$refund->status, $this->balanceOf($refund)->refunded_minor]);
        $this->assertSame(1, Line::query()->where('refund_id', $refund->getKey())->where('type', 'provider_reports_failed_after_refunded')->count());
        $this->assertSame(1, Line::query()->where('refund_id', $refund->getKey())->where('to_status', 'REFUNDED')->count());
    }

    public function test_a_refund_completes_from_the_webhook_alone_when_nobody_is_there_to_see_the_answer(): void
    {
        [$customer, $order, $refund, $pay] = (function (): array {
            [, $customer, $order, $payment, $refund] = $this->rejectedPaidOrder();
            $this->finance();
            $this->refundPost($refund, 'approve')->assertOk();

            return [$customer, $order, $refund, (string) $this->attempt($payment['id'])->provider_payment_reference];
        })();

        // The provider creates and completes the refund; the answer to our request is lost (the worker died, the
        // administrator closed the window). We do not even know the provider's refund reference.
        $this->fakeProvider()->refundsBecome('processed');
        $this->fakeProvider()->failNext('create_refund', ProviderFailure::Timeout, 1, true);
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'SUBMISSION_UNKNOWN')->assertJsonPath('refund.provider_detail.refund_reference', null);

        // The provider's webhook arrives: the refund is found by OUR receipt and completed.
        $atProvider = $this->fakeProvider()->refundsOfPayment($pay, false)[0];
        $this->providerSendsRefund('refund.processed', $atProvider)->assertOk()->assertExactJson(['received' => true]);
        $refund->refresh();
        $this->assertSame([RefundStatus::Refunded, $atProvider->reference], [$refund->status, $refund->provider_refund_reference]);
        $this->assertSame('REFUNDED', $this->showOrder($customer, $order['order_number'])->json('order.refund_status'));
        $this->assertCount(1, $this->fakeProvider()->refundsOfPayment($pay, false));
        $this->assertSame(1, RefundAttempt::query()->where('operation', 'CREATE')->count());
    }

    public function test_the_webhook_may_arrive_before_the_answer_to_the_request_and_both_orders_end_the_same(): void
    {
        [, , $refund, $held] = $this->processingRefund();

        // The refund is still "being sent" here when the provider's event arrives.
        DB::table('refunds')->where('id', $refund->getKey())->update(['status' => 'SUBMITTING', 'provider_refund_reference' => null, 'provider_status' => null, 'submitted_at' => now()]);
        $settled = $this->providerSettlesRefund($refund, 'processed');
        $this->providerSendsRefund('refund.processed', $settled)->assertOk();
        $refund->refresh();
        $this->assertSame([RefundStatus::Refunded, $held->reference], [$refund->status, $refund->provider_refund_reference]);

        // The late answer to the request (or a second look) finds it done and changes nothing.
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame([0, 50000], [$this->balanceOf($refund)->reserved_minor, $this->balanceOf($refund)->refunded_minor]);
        $this->assertSame(1, Line::query()->where('refund_id', $refund->getKey())->where('to_status', 'REFUNDED')->count());
    }

    public function test_without_any_webhook_the_scheduled_reconciliation_resolves_the_refund(): void
    {
        [, , $refund] = $this->processingRefund();

        // Not due yet: the provider is not asked on every tick.
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame(0, RefundAttempt::query()->where('operation', 'LOOKUP')->count());

        // Due, and the bank has not finished: still PROCESSING, the next look further away.
        $this->travel(61)->seconds();
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $refund->refresh();
        $this->assertSame([RefundStatus::Processing, 1], [$refund->status, $refund->reconcile_runs]);
        $this->assertEqualsWithDelta(300, now()->diffInSeconds($refund->next_action_at), 2);

        // The provider is down: nothing is concluded from that — the refund is not "failed".
        $this->fakeProvider()->failNext('refund', ProviderFailure::Unavailable, 3);
        $this->travel(6)->minutes();
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame(RefundStatus::Processing, $refund->refresh()->status);
        $this->assertSame('UNAVAILABLE', RefundAttempt::query()->latest('id')->value('outcome'));
        $this->fakeProvider()->recover();

        // The bank finishes; no webhook ever comes; the next due look completes the refund.
        $this->providerSettlesRefund($refund, 'processed');
        $this->travel(20)->minutes();
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame(RefundStatus::Refunded, $refund->refresh()->status);
        $this->assertSame(0, PaymentWebhookEvent::query()->count());

        // An administrator can ask the provider again at any time — with a reason; it changes only what the provider confirms.
        [, , $second] = $this->processingRefund('+919876543212');
        $this->finance();
        $this->refundPost($second, 'reconcile')->assertStatus(422);
        $this->refundPost($second, 'reconcile', ['reason' => 'Customer called', 'status' => 'REFUNDED'])->assertStatus(422)->assertJsonPath('error.details.fields.status.0', 'The status field cannot be set by hand.');
        $this->refundPost($second, 'reconcile', ['reason' => 'Customer called'])->assertOk()->assertJsonPath('refund.status', 'PROCESSING');
        $this->providerSettlesRefund($second, 'failed');
        $this->refundPost($second, 'reconcile', ['reason' => 'Customer called again'])->assertOk()->assertJsonPath('refund.status', 'FAILED')->assertJsonPath('refund.balance.available', $this->inr(50000));
    }

    public function test_a_refund_the_provider_never_confirms_reaches_the_review_queue(): void
    {
        config(['refunds.reconcile.backoff_seconds' => [60, 60, 60]]);
        [$customer, $order, $refund] = $this->processingRefund();

        foreach ([1, 2, 3, 4] as $round) {
            $this->travel(2)->minutes();
            $this->artisan('refunds:reconcile')->assertSuccessful();
        }
        $refund->refresh();
        $this->assertSame([RefundStatus::ReviewRequired, 'RECONCILIATION_EXHAUSTED', 3], [$refund->status, $refund->review_reason, $refund->reconcile_runs]);
        $this->assertSame(50000, $this->balanceOf($refund)->reserved_minor, 'still held: the provider may yet complete it');
        $this->assertSame(['UNDER_REVIEW', 'UNDER_REVIEW'], [$this->showOrder($customer, $order['order_number'])->json('order.refund_status'), $this->showOrder($customer, $order['order_number'])->json('order.refund.items.0.status')]);
        // The sweep leaves it alone now.
        $looks = RefundAttempt::query()->count();
        $this->travel(1)->days();
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame($looks, RefundAttempt::query()->count());

        // It is in the administrators' review queue, and a late confirmation still completes it.
        $this->finance();
        $this->assertSame([(string) $refund->public_id], array_column($this->refundGet('?filter[queue]=review_required')->assertOk()->json('data'), 'id'));
        $this->assertSame(1, $this->refundGet('/queues')->assertOk()->json('queues.review_required'));
        $settled = $this->providerSettlesRefund($refund, 'processed');
        $this->providerSendsRefund('refund.processed', $settled)->assertOk();
        $this->assertSame(RefundStatus::Refunded, $refund->refresh()->status);
    }

    public function test_a_refund_event_for_a_refund_made_outside_foodonthego_is_flagged_and_records_nothing_as_refunded(): void
    {
        [, , , , $order] = $this->paidOrder('+919876543210', $this->nextSlot());
        $attempt = $this->orderModel($order['order_number'])->paymentAttempt;
        // Somebody refunds the payment in the provider's own dashboard.
        $external = new ProviderRefund('fakerfnd_external0001', (string) $attempt->provider_payment_reference, ProviderRefundState::Processed, 'processed', $attempt->amount(), 'made-in-the-dashboard');

        $this->providerSendsRefund('refund.processed', $external)->assertOk()->assertExactJson(['received' => true]);

        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame(['IGNORED', 'EXTERNAL_REFUND', (int) $attempt->getKey()], [$event->processing_status, $event->outcome, (int) $event->payment_attempt_id]);
        $this->assertSame(0, Refund::query()->count(), 'no refund record is invented from an event');
        $this->assertSame('NONE', $this->orderModel($order['order_number'])->refund_status->value);
        $this->assertSame(1, PaymentAttemptEvent::query()->where('payment_attempt_id', $attempt->getKey())->where('type', 'external_refund_seen')->count(), 'a person is told on the payment\'s timeline');
        // An event about a payment that is not ours at all is ignored quietly.
        $this->providerSendsRefund('refund.processed', new ProviderRefund('fakerfnd_unknown00001', 'fakepay_somebodyelse', ProviderRefundState::Processed, 'processed', $attempt->amount()))->assertOk();
        $this->assertSame('NO_MATCHING_REFUND', PaymentWebhookEvent::query()->latest('id')->value('outcome'));
    }
}
