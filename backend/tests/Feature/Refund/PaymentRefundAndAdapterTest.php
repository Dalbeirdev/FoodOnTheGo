<?php

namespace Tests\Feature\Refund;

use App\Enums\DisputeStatus;
use App\Enums\ProviderFailure;
use App\Enums\ProviderRefundState;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderRefundRejected;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\PaymentAttemptEvent;
use App\Models\Refund;
use App\Services\Payment\Data\ProviderRefundRequest;
use App\Services\Payment\Data\ProviderWebhook;
use App\Services\Payment\Providers\RazorpayPaymentProvider;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — two things beside the order's refund:
 *
 *  1. Refunds of a payment that never became an order: a verified payment whose order cannot be made, and a second
 *     payment for a checkout that was already paid.
 *  2. The Razorpay adapter's side of the refund / dispute contract, against Razorpay's documented API (reviewed
 *     2026-10-11) with the HTTP layer faked. No request leaves this machine and the keys are test values that exist
 *     nowhere else. This is NOT a test against Razorpay's servers: that needs test-mode credentials and is recorded
 *     as PENDING PROVIDER VERIFICATION.
 */
class PaymentRefundAndAdapterTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    private const KEY_ID = 'rzp_test_UnitTestKey00001';

    private const KEY_SECRET = 'unit-test-key-secret';

    private const WEBHOOK_SECRET = 'unit-test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /* ------------------------------------------------------------------ payments without an order */

    public function test_a_verified_payment_whose_order_cannot_be_made_is_refunded_and_never_becomes_an_order(): void
    {
        // Paid — and the restaurant stopped taking orders before the order could be created.
        [$customer, $headers, $checkout] = $this->readyCheckout(2, $this->nextSlot());
        $payment = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $paid = $this->providerPays($payment['id']);
        DB::table('restaurant_locations')->where('id', $this->burgerHub->getKey())->update(['status' => 'SUSPENDED']);
        $verified = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->json('payment');
        $this->assertSame(['VERIFIED', 'RECOVERY_REQUIRED', null], [$verified['status'], $verified['fulfillment']['status'], $verified['fulfillment']['order_number']]);
        $this->assertSame(0, Order::query()->count());

        $this->finance();
        $detail = $this->adminGet('/payments/'.$payment['id'])->assertOk()->json('payment');
        $this->assertSame(['mark_paid' => false, 'refund' => true, 'mark_refunded' => 'NOT_PROVIDED'], $detail['actions']);
        $this->assertSame([[], $this->inr(50000)], [$detail['refunds'], $detail['refund_balance']['available']]);

        // The caller chooses nothing: no amount, no type, no reason — the whole payment, for the reason the payment shows.
        $this->adminPost('/payments/'.$payment['id'].'/refunds', ['note' => 'Restaurant closed', 'amount_minor' => 100])->assertStatus(422);
        $this->adminPost('/payments/'.$payment['id'].'/refunds', ['note' => 'Restaurant closed', 'reason_code' => 'OTHER'])->assertStatus(422);
        $this->adminPost('/payments/'.$payment['id'].'/refunds', [])->assertStatus(422);
        $asked = $this->adminPost('/payments/'.$payment['id'].'/refunds', ['note' => 'Restaurant closed for the day'])->assertCreated()->json('refund');
        $this->assertSame(['REQUESTED', 'FULL', $this->inr(50000), 'PAYMENT_ORDER_FAILURE', null, 'ADMIN'], [$asked['status'], $asked['type'], $asked['amount'], $asked['reason']['code'], $asked['order'], $asked['requested_by']['type']]);
        $this->assertSame((string) $customer->public_id, $asked['customer']['id']);
        $this->adminPost('/payments/'.$payment['id'].'/refunds', ['note' => 'Again'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_processing');
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'refund_requested')->count());

        // From now on this payment cannot become an order — even when the restaurant is back and somebody asks for recovery.
        DB::table('restaurant_locations')->where('id', $this->burgerHub->getKey())->update(['status' => 'APPROVED']);
        $fulfillment = OrderFulfillment::query()->sole();
        $this->adminFor();
        $this->adminPost('/order-fulfillments/'.$fulfillment->public_id.'/recover', ['reason' => 'Restaurant is open again'])->assertOk()->assertJsonPath('fulfillment.last_error_code', 'ORDER_PAYMENT_REFUNDED')->assertJsonPath('fulfillment.order_id', null);
        $this->assertSame(0, Order::query()->count(), 'no order AND a refund for the same money');

        $this->finance();
        $this->fakeProvider()->refundsBecome('processed');
        $refund = Refund::query()->sole();
        $done = $this->approveAndExecute($refund);
        $this->assertSame(['REFUNDED', $this->inr(50000), $this->inr(0)], [$done['status'], $done['balance']['refunded'], $done['balance']['available']]);
        // The payment is still VERIFIED: a refund is a record of its own.
        $after = $this->adminGet('/payments/'.$payment['id'])->assertOk()->json('payment');
        $this->assertSame(['VERIFIED', false, 'REFUNDED'], [$after['status'], $after['actions']['refund'], $after['refunds'][0]['status']]);
        $this->assertNull($refund->refresh()->order_id);
    }

    public function test_a_second_payment_for_a_checkout_already_paid_is_refunded_as_a_duplicate(): void
    {
        [, $headers, $checkout, $first] = $this->openPayment();
        // The customer pays twice in the provider's interface: two captured payments on two attempts of one checkout.
        $firstPaid = $this->providerPays($first['id']);
        DB::table('payment_attempts')->where('public_id', $first['id'])->update(['status' => 'FAILED', 'closed_at' => now(), 'failure_code' => 'PAYMENT_FAILED']);
        DB::table('checkout_sessions')->where('public_id', $checkout['id'])->update(['status' => 'READY']);
        $second = $this->startPayment($headers, $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->using($headers))->json('checkout'))->assertCreated()->json('payment');
        $this->completePayment($headers, $second['id'], $this->proofOf($second['id'], $this->providerPays($second['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        // The first payment's late webhook: captured — for a checkout that is paid already.
        $this->providerSends('payment.captured', $firstPaid)->assertOk();
        $duplicate = $this->attempt($first['id']);
        $this->assertSame(['REVIEW_REQUIRED', 'DUPLICATE_PAYMENT', $firstPaid->reference], [$duplicate->status->value, $duplicate->status_reason, $duplicate->provider_payment_reference]);
        $this->assertSame(1, Order::query()->count());

        $this->finance();
        $this->assertTrue($this->adminGet('/payments/'.$first['id'])->assertOk()->json('payment.actions.refund'));
        $asked = $this->adminPost('/payments/'.$first['id'].'/refunds', ['note' => 'Charged twice for one order'])->assertCreated()->json('refund');
        $this->assertSame(['DUPLICATE_PAYMENT', $this->inr(50000), null], [$asked['reason']['code'], $asked['amount'], $asked['order']]);
        $this->fakeProvider()->refundsBecome('processed');
        $done = $this->approveAndExecute(Refund::query()->where('public_id', $asked['id'])->sole());
        $this->assertSame(['REFUNDED', $firstPaid->reference], [$done['status'], $done['provider_detail']['payment_reference']]);

        // The order's own payment is untouched: its refund balance is its own and nothing of it was refunded.
        $order = Order::query()->sole();
        $this->assertSame(['NONE', 'WAITING_FOR_RESTAURANT', $second['id']], [$order->refund_status->value, $order->status->value, (string) $order->payment_public_id]);
        $this->assertSame([], $this->fakeProvider()->refundsOfPayment((string) $this->attempt($second['id'])->provider_payment_reference, false));
        // The payment that became the order is refunded through the order — not here.
        $this->adminPost('/payments/'.$second['id'].'/refunds', ['note' => 'Wrong one'])->assertStatus(409)->assertJsonPath('error.code', 'refund_not_eligible');
    }

    /* ------------------------------------------------------------------ the Razorpay adapter */

    private function razorpay(array $overrides = []): RazorpayPaymentProvider
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return new RazorpayPaymentProvider($overrides + ['key_id' => self::KEY_ID, 'key_secret' => self::KEY_SECRET, 'webhook_secret' => self::WEBHOOK_SECRET, 'mode' => 'test', 'timeout' => 5]);
    }

    /** @return array<string, mixed> */
    private function refundEntity(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'rfnd_QxUnitRefund0001', 'entity' => 'refund', 'amount' => 20000, 'currency' => 'INR', 'payment_id' => 'pay_QxUnitTest00001', 'notes' => ['refund' => 'x'], 'receipt' => 'rf-0123456789abcdef0123456789abcdef-1',
            'acquirer_data' => ['arn' => null], 'created_at' => 1791700000, 'batch_id' => null, 'status' => 'pending', 'speed_processed' => 'normal', 'speed_requested' => 'normal',
        ];
    }

    public function test_the_razorpay_adapter_asks_for_the_documented_refund_with_the_idempotency_key(): void
    {
        $provider = $this->razorpay();
        Http::fake(['api.razorpay.com/v1/payments/pay_QxUnitTest00001/refund' => Http::response($this->refundEntity())]);

        $refund = $provider->createRefund(new ProviderRefundRequest('pay_QxUnitTest00001', new Money(20000, 'INR'), 'rf-0123456789abcdef0123456789abcdef-1', null, ['refund' => 'a0b1c2d3-0000-4000-8000-000000000001', 'order' => 'FOTG-20261011-ABC234']));

        $this->assertSame(['rfnd_QxUnitRefund0001', 'pay_QxUnitTest00001', ProviderRefundState::Pending, 'pending', 20000, 'INR', 'rf-0123456789abcdef0123456789abcdef-1', null],
            [$refund->reference, $refund->paymentReference, $refund->state, $refund->providerStatus, $refund->amount->amount, $refund->amount->currency, $refund->receipt, $refund->traceReference]);
        $sent = Http::recorded()[0][0];
        $this->assertSame(['POST', 'https://api.razorpay.com/v1/payments/pay_QxUnitTest00001/refund'], [$sent->method(), $sent->url()]);
        // INR 200.00 is 20000 currency subunits: the minor units pass as they are — no float, no rounding.
        $this->assertSame(['amount' => 20000, 'speed' => 'normal', 'receipt' => 'rf-0123456789abcdef0123456789abcdef-1', 'notes' => ['refund' => 'a0b1c2d3-0000-4000-8000-000000000001', 'order' => 'FOTG-20261011-ABC234']], json_decode((string) $sent->body(), true));
        $this->assertTrue($sent->hasHeader('X-Refund-Idempotency', 'rf-0123456789abcdef0123456789abcdef-1'), 'the idempotency header of the provider carries our key');
        $this->assertTrue($sent->hasHeader('Authorization', 'Basic '.base64_encode(self::KEY_ID.':'.self::KEY_SECRET)));
        $this->assertCount(1, Http::recorded());
        // The idempotency key satisfies the provider's rule: at least ten characters of letters, digits, hyphen or underscore.
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{10,40}\z/', (new Refund)->forceFill(['public_id' => 'a0b1c2d3-0000-4000-8000-000000000001'])->receiptFor(12));

        // The provider's states, normalized: processed (with the bank's reference), failed, and a word we do not know.
        $request = new ProviderRefundRequest('pay_QxUnitTest00001', new Money(20000, 'INR'), 'rf-0123456789abcdef0123456789abcdef-1');
        $answered = function (array $entity) use ($provider, $request) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($this->refundEntity($entity))]);

            return $provider->createRefund($request);
        };
        $processed = $answered(['status' => 'processed', 'acquirer_data' => ['arn' => '10000000000000']]);
        $this->assertSame([ProviderRefundState::Processed, '10000000000000'], [$processed->state, $processed->traceReference]);
        $this->assertSame(ProviderRefundState::Failed, $answered(['status' => 'failed'])->state);
        $this->assertSame(ProviderRefundState::Unknown, $answered(['status' => 'reversed'])->state, 'a status this application does not know concludes nothing');

        // A zero or negative amount never leaves the adapter; a three-decimal currency has to end in 0.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        foreach ([new Money(0, 'INR'), new Money(1001, 'KWD')] as $bad) {
            try {
                $provider->createRefund(new ProviderRefundRequest('pay_QxUnitTest00001', $bad, 'rf-0123456789abcdef0123456789abcdef-1'));
                $this->fail('An amount the provider cannot take was sent');
            } catch (ProviderException $e) {
                $this->assertSame(ProviderFailure::Rejected, $e->failure);
            }
        }
        // A payment reference that is not the provider's is not sent either.
        $this->expectException(ProviderException::class);
        $provider->createRefund(new ProviderRefundRequest('../orders', new Money(100, 'INR'), 'rf-x'));
    }

    public function test_the_razorpay_adapter_tells_unknown_refused_and_not_sent_apart(): void
    {
        $request = new ProviderRefundRequest('pay_QxUnitTest00001', new Money(20000, 'INR'), 'rf-0123456789abcdef0123456789abcdef-1');
        $outcome = function (callable $fake) use ($request): ProviderException {
            $provider = $this->razorpay();
            $fake();
            try {
                $provider->createRefund($request);
            } catch (ProviderException $e) {
                return $e;
            }
            $this->fail('The adapter did not report the failure');
        };

        // No answer, or a server error: the refund MAY exist — Timeout, never "failed".
        $this->assertSame(ProviderFailure::Timeout, $outcome(fn () => Http::fake(fn () => throw new ConnectionException('timed out')))->failure);
        $this->assertSame(ProviderFailure::Timeout, $outcome(fn () => Http::fake(['*' => Http::response(['error' => ['code' => 'SERVER_ERROR']], 502)]))->failure);
        // Refused before it was read: certainly not created.
        $this->assertSame(ProviderFailure::RateLimited, $outcome(fn () => Http::fake(['*' => Http::response([], 429)]))->failure);
        $this->assertSame(ProviderFailure::Misconfigured, $outcome(fn () => Http::fake(['*' => Http::response(['error' => ['description' => 'Authentication failed']], 401)]))->failure);
        // The same idempotency key is still being worked on: nothing is concluded.
        $conflict = $outcome(fn () => Http::fake(['*' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR']], 409)]));
        $this->assertInstanceOf(ProviderRefundRejected::class, $conflict);
        $this->assertSame([ProviderRefundRejected::IDEMPOTENCY_CONFLICT, true], [$conflict->reason, $conflict->outcomeUnknown()]);

        // The documented refusals (HTTP 400), each as one of our own words. The provider's sentence goes no further.
        $refusals = [
            'The refund amount provided is greater than amount captured' => [ProviderRefundRejected::AMOUNT_EXCEEDS_AVAILABLE, 'review'],
            'The payment has been fully refunded already' => [ProviderRefundRejected::ALREADY_REFUNDED, 'review'],
            'Your account does not have enough balance to carry out the refund operation.' => [ProviderRefundRejected::INSUFFICIENT_BALANCE, 'retryable'],
            'The refund on this payment is blocked due to ongoing dispute investigation' => [ProviderRefundRejected::DISPUTE_BLOCK, 'review'],
            'Duplicate receipt found for this refund request' => [ProviderRefundRejected::DUPLICATE_RECEIPT, 'unknown'],
            'Request failed because another payment operation is in progress' => [ProviderRefundRejected::OPERATION_IN_PROGRESS, 'unknown'],
            'The payment status should be captured for action to be taken' => [ProviderRefundRejected::PAYMENT_NOT_CAPTURED, 'failed'],
            'Partial refund is not allowed for this payment' => [ProviderRefundRejected::PARTIAL_NOT_SUPPORTED, 'failed'],
            'Refund is not supported for this payment method' => [ProviderRefundRejected::METHOD_NOT_SUPPORTED, 'failed'],
            'Something nobody documented' => [ProviderRefundRejected::REJECTED, 'failed'],
        ];
        foreach ($refusals as $sentence => [$reason, $kind]) {
            $e = $outcome(fn () => Http::fake(['*' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => $sentence]], 400)]));
            $this->assertInstanceOf(ProviderRefundRejected::class, $e, $sentence);
            $this->assertSame([$reason, $kind === 'unknown', $kind === 'review', $kind === 'retryable'], [$e->reason, $e->outcomeUnknown(), $e->needsReview(), $e->retryable()], $sentence);
            $this->assertStringNotContainsString($sentence, $e->getMessage(), 'the provider\'s sentence is not carried on');
        }
        // An answer for another payment is not accepted as this refund.
        $this->assertSame(ProviderFailure::Unavailable, $outcome(fn () => Http::fake(['*' => Http::response($this->refundEntity(['payment_id' => 'pay_SomebodyElse00001']))]))->failure);
        // Without keys no request is even built.
        try {
            (new RazorpayPaymentProvider([]))->createRefund($request);
            $this->fail('A refund was requested without credentials');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::Misconfigured, $e->failure);
        }
    }

    public function test_the_razorpay_adapter_looks_refunds_and_disputes_up_and_reads_their_webhooks(): void
    {
        $provider = $this->razorpay();
        Http::fake([
            'api.razorpay.com/v1/payments/pay_QxUnitTest00001/refunds/rfnd_QxUnitRefund0001' => Http::response($this->refundEntity(['status' => 'processed', 'acquirer_data' => ['rrn' => '412345678901']])),
            'api.razorpay.com/v1/payments/pay_QxUnitTest00001/refunds/rfnd_QxUnitMissing001' => Http::response(['error' => ['description' => 'The id provided does not exist']], 400),
            'api.razorpay.com/v1/payments/pay_QxUnitTest00001/refunds*' => Http::response(['entity' => 'collection', 'count' => 2, 'items' => [$this->refundEntity(), $this->refundEntity(['id' => 'rfnd_QxUnitRefund0002', 'receipt' => 'rf-other', 'amount' => 5000])]]),
            'api.razorpay.com/v1/disputes/disp_QxUnitDispute001' => Http::response(['id' => 'disp_QxUnitDispute001', 'entity' => 'dispute', 'payment_id' => 'pay_QxUnitTest00001', 'amount' => 50000, 'currency' => 'INR', 'amount_deducted' => 50000, 'reason_code' => 'goods_not_received', 'respond_by' => 1792000000, 'status' => 'lost', 'phase' => 'chargeback', 'created_at' => 1791700000, 'evidence' => ['shipping_proof' => ['doc_x']]]),
        ]);

        $one = $provider->refund('pay_QxUnitTest00001', 'rfnd_QxUnitRefund0001');
        $this->assertSame([ProviderRefundState::Processed, '412345678901'], [$one->state, $one->traceReference]);
        $list = $provider->refundsOfPayment('pay_QxUnitTest00001');
        $this->assertSame(['rf-0123456789abcdef0123456789abcdef-1', 'rf-other'], array_map(fn ($r) => $r->receipt, $list));
        try {
            $provider->refund('pay_QxUnitTest00001', 'rfnd_QxUnitMissing001');
            $this->fail('A refund the provider does not have was returned');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailure::NotFound, $e->failure);
        }
        $dispute = $provider->dispute('disp_QxUnitDispute001');
        $this->assertSame(['disp_QxUnitDispute001', 'pay_QxUnitTest00001', DisputeStatus::Lost, 50000, 50000, 'chargeback', 'goods_not_received'], [$dispute->reference, $dispute->paymentReference, $dispute->status, $dispute->amount->amount, $dispute->amountDeductedMinor, $dispute->phase, $dispute->reasonCode]);
        $this->assertStringNotContainsString('doc_x', json_encode($dispute->summary()), 'no evidence document is kept');

        // Webhooks: the signature is HMAC-SHA256 over the raw body; refund and dispute events are read into safe fields only.
        $sign = fn (string $body): string => hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
        $payment = ['id' => 'pay_QxUnitTest00001', 'entity' => 'payment', 'amount' => 50000, 'currency' => 'INR', 'status' => 'captured', 'order_id' => 'order_QxUnitTest00001', 'method' => 'upi', 'vpa' => 'asha.rao@okbank', 'email' => 'asha@example.test', 'contact' => '+919876543210', 'captured' => true];
        $body = json_encode(['entity' => 'event', 'event' => 'refund.processed', 'contains' => ['refund', 'payment'], 'payload' => ['refund' => ['entity' => $this->refundEntity(['status' => 'processed'])], 'payment' => ['entity' => $payment]], 'created_at' => 1791700100]);
        $this->assertTrue($provider->verifyWebhookSignature($body, $sign($body)));
        $this->assertFalse($provider->verifyWebhookSignature($body.' ', $sign($body)));
        $event = $provider->parseWebhook($body, ['x-razorpay-event-id' => 'evt_unit_refund_0001']);
        $this->assertSame(['evt_unit_refund_0001', 'refund.processed', ProviderWebhook::REFUND_PROCESSED, true, 'rfnd_QxUnitRefund0001', ProviderRefundState::Processed, 'order_QxUnitTest00001'],
            [$event->eventReference, $event->type, $event->normalizedType, $event->aboutRefund(), $event->refund?->reference, $event->refund?->state, $event->orderReference]);
        // The payment entity inside a refund event carries the customer's e-mail and phone: neither is kept.
        $kept = json_encode([$event->payment?->summary(), $event->refund?->summary(), $event->payment?->methodSummary]);
        $this->assertStringNotContainsString('asha@example.test', $kept);
        $this->assertStringNotContainsString('9876543210', $kept);
        $this->assertStringNotContainsString('asha.rao', $kept);

        foreach (['refund.created' => ProviderWebhook::REFUND_CREATED, 'refund.failed' => ProviderWebhook::REFUND_FAILED, 'refund.speed_changed' => null] as $name => $normalized) {
            $parsed = $provider->parseWebhook(json_encode(['entity' => 'event', 'event' => $name, 'payload' => ['refund' => ['entity' => $this->refundEntity()]]]), []);
            $this->assertSame([$normalized, $normalized !== null], [$parsed->normalizedType, $parsed->handled()]);
        }
        $entity = ['id' => 'disp_QxUnitDispute001', 'entity' => 'dispute', 'payment_id' => 'pay_QxUnitTest00001', 'amount' => 50000, 'currency' => 'INR', 'amount_deducted' => 0, 'reason_code' => 'goods_not_received', 'respond_by' => 1792000000, 'status' => 'open', 'phase' => 'chargeback', 'created_at' => 1791700000];
        $expected = [
            'payment.dispute.created' => [ProviderWebhook::DISPUTE_CREATED, 'open', DisputeStatus::Open],
            'payment.dispute.action_required' => [ProviderWebhook::DISPUTE_UPDATED, 'open', DisputeStatus::ActionRequired],
            'payment.dispute.under_review' => [ProviderWebhook::DISPUTE_UPDATED, 'under_review', DisputeStatus::UnderReview],
            'payment.dispute.won' => [ProviderWebhook::DISPUTE_WON, 'won', DisputeStatus::Won],
            'payment.dispute.lost' => [ProviderWebhook::DISPUTE_LOST, 'lost', DisputeStatus::Lost],
            'payment.dispute.closed' => [ProviderWebhook::DISPUTE_CLOSED, 'closed', DisputeStatus::Closed],
        ];
        foreach ($expected as $name => [$normalized, $word, $status]) {
            $parsed = $provider->parseWebhook(json_encode(['entity' => 'event', 'event' => $name, 'payload' => ['dispute' => ['entity' => ['status' => $word] + $entity], 'payment' => ['entity' => $payment]]]), []);
            $this->assertSame([$normalized, true, $status, 'pay_QxUnitTest00001'], [$parsed->normalizedType, $parsed->aboutDispute(), $parsed->dispute?->status, $parsed->dispute?->paymentReference], $name);
        }
        // Live mode stays refused: the refund calls are test mode only.
        $this->assertNotSame([], $this->razorpay(['key_id' => 'rzp_live_UnitTestKey00001'])->missingConfiguration());
    }
}
