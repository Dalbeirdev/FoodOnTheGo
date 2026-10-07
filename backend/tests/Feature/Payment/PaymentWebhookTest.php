<?php

namespace Tests\Feature\Payment;

use App\Enums\ProviderFailure;
use App\Enums\ProviderPaymentState;
use App\Events\Payment\PaymentVerified;
use App\Jobs\Payment\ProcessPaymentWebhookEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Payment\Providers\FakePaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * Provider webhooks (Module 30): accepted only with the provider's signature over the raw body, stored once however
 * often they are delivered, processed in any order — and able to settle a payment whose customer never came back.
 */
class PaymentWebhookTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_signed_webhook_alone_verifies_a_payment_whose_customer_never_came_back(): void
    {
        Event::fake([PaymentVerified::class]);
        [, $headers, , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id']);   // the customer pays and closes the app: no callback ever arrives

        // No customer session, no admin session — only the provider's signature.
        $this->providerSends('payment.captured', $paid)->assertOk()->assertExactJson(['received' => true]);

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('VERIFIED', $attempt->status->value);
        $this->assertNull($attempt->client_outcome);
        $this->assertSame($paid->reference, $attempt->provider_payment_reference);
        Event::assertDispatchedTimes(PaymentVerified::class, 1);
        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame(['fake', 'payment.captured', 'PAYMENT_CAPTURED', 'PROCESSED', 'VERIFIED'], [$event->provider, $event->event_type, $event->normalized_type, $event->processing_status, $event->outcome]);
        $this->assertSame($attempt->getKey(), $event->payment_attempt_id);
        // When the customer comes back, the payment is simply paid.
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED')->assertJsonPath('payment.next_action', 'PROCEED_TO_ORDER');
    }

    public function test_a_webhook_without_a_valid_signature_is_rejected_and_changes_nothing(): void
    {
        [, , , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id']);
        [$body, $headers] = $this->fakeProvider()->webhook('payment.captured', $paid);

        // a wrong signature, no signature, a signature of another body, and a signature in the wrong header
        $this->sendWebhook($body, [...$headers, FakePaymentProvider::SIGNATURE_HEADER => str_repeat('0', 64)])->assertStatus(401)->assertJsonPath('error.code', 'webhook_signature_invalid');
        $this->sendWebhook($body, [FakePaymentProvider::EVENT_ID_HEADER => 'evt_1'])->assertStatus(401);
        $this->sendWebhook(str_replace('captured', 'CAPTURED', $body), $headers)->assertStatus(401);
        $this->sendWebhook($body, ['x-razorpay-signature' => $headers[FakePaymentProvider::SIGNATURE_HEADER]])->assertStatus(401);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
    }

    public function test_the_signature_is_checked_over_the_raw_body_exactly_as_it_arrived(): void
    {
        [, , , $payment] = $this->openPayment();
        [$body, $headers] = $this->fakeProvider()->webhook('payment.captured', $this->providerPays($payment['id']));

        // The same JSON, re-encoded with other whitespace, is another body: its signature does not match.
        $reencoded = json_encode(json_decode($body, true), JSON_PRETTY_PRINT);
        $this->assertSame(json_decode($body, true), json_decode($reencoded, true));
        $this->sendWebhook($reencoded, $headers)->assertStatus(401);
        $this->sendWebhook($body.' ', $headers)->assertStatus(401);

        $this->sendWebhook($body, $headers)->assertOk();
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
    }

    public function test_a_customer_session_is_neither_needed_nor_of_any_use_for_a_webhook(): void
    {
        [, $customerHeaders, , $payment] = $this->openPayment();
        [$body, $headers] = $this->fakeProvider()->webhook('payment.captured', $this->providerPays($payment['id']));

        // A valid customer token does not replace the signature …
        $this->sendWebhook($body, ['Authorization' => $customerHeaders['Authorization']])->assertStatus(401);
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
        // … and a nonsense token does not stop a signed webhook.
        $this->sendWebhook($body, [...$headers, 'Authorization' => 'Bearer not-a-token'])->assertOk();
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
    }

    public function test_the_same_event_delivered_twice_is_stored_once_and_acts_once(): void
    {
        Event::fake([PaymentVerified::class]);
        [, , , $payment] = $this->openPayment();
        [$body, $headers] = $this->fakeProvider()->webhook('payment.captured', $this->providerPays($payment['id']), 'evt_same_0001');

        $this->sendWebhook($body, $headers)->assertOk()->assertExactJson(['received' => true]);
        $this->sendWebhook($body, $headers)->assertOk()->assertExactJson(['received' => true, 'duplicate' => true]);
        $this->sendWebhook($body, $headers)->assertOk()->assertJsonPath('duplicate', true);

        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame(3, (int) $event->deliveries);
        $this->assertSame(1, (int) $event->processing_attempts);
        Event::assertDispatchedTimes(PaymentVerified::class, 1);
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'verified')->count());
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'webhook_payment_captured')->count());
    }

    public function test_an_event_without_a_provider_event_id_is_deduplicated_by_its_content(): void
    {
        [, , , $payment] = $this->openPayment();
        [$body, $headers] = $this->fakeProvider()->webhook('payment.captured', $this->providerPays($payment['id']));
        unset($headers[FakePaymentProvider::EVENT_ID_HEADER]);

        $this->sendWebhook($body, $headers)->assertOk()->assertExactJson(['received' => true]);
        $this->sendWebhook($body, $headers)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame('sha256:'.hash('sha256', $body), PaymentWebhookEvent::query()->sole()->provider_event_reference);
    }

    public function test_events_that_arrive_in_the_wrong_order_cannot_take_a_settled_payment_back(): void
    {
        Event::fake([PaymentVerified::class]);
        [, , , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id']);
        $earlier = fn (ProviderPaymentState $state, string $status): ProviderPayment => new ProviderPayment($paid->reference, $paid->orderReference, $state, $status, $paid->amount, $paid->methodType, $paid->methodSummary);

        // The final event first …
        $this->providerSends('payment.captured', $paid)->assertOk();
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
        // … then the events that happened before it, and a stale failure of an earlier try.
        $this->providerSends('payment.authorized', $earlier(ProviderPaymentState::Authorized, 'authorized'))->assertOk();
        $this->providerSends('payment.failed', $earlier(ProviderPaymentState::Failed, 'failed'))->assertOk();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('VERIFIED', $attempt->status->value);
        $this->assertSame('ORDER_PENDING', $attempt->fulfillment_status->value);
        Event::assertDispatchedTimes(PaymentVerified::class, 1);
        $this->assertSame(3, PaymentWebhookEvent::query()->where('processing_status', 'PROCESSED')->count());
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'verified')->count());
    }

    public function test_webhook_first_or_callback_first_the_payment_is_verified_once(): void
    {
        Event::fake([PaymentVerified::class]);
        // The webhook arrives before the browser reports …
        [, $asha, , $first] = $this->openPayment('+919876543210');
        $paid = $this->providerPays($first['id']);
        $this->providerSends('payment.captured', $paid)->assertOk();
        $this->completePayment($asha, $first['id'], $this->proofOf($first['id'], $paid))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');

        // … and the browser reports before the webhook arrives.
        [, $ravi, , $second] = $this->openPayment('+919811122233');
        $paid = $this->providerPays($second['id']);
        $this->completePayment($ravi, $second['id'], $this->proofOf($second['id'], $paid))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        $this->providerSends('payment.captured', $paid)->assertOk();
        $this->providerSends('order.paid', $paid)->assertOk();

        Event::assertDispatchedTimes(PaymentVerified::class, 2);
        $this->assertSame(2, PaymentAttemptEvent::query()->where('type', 'verified')->count());
        $this->assertSame(['VERIFIED', 'VERIFIED'], PaymentAttempt::query()->orderBy('id')->pluck('status')->map->value->all());
    }

    public function test_a_failed_event_does_not_close_an_attempt_and_an_authorized_one_only_moves_it_forward(): void
    {
        [, , , $payment] = $this->openPayment();
        $authorized = $this->providerPays($payment['id'], 'authorized');

        $this->providerSends('payment.authorized', $authorized)->assertOk();
        $this->assertSame('AUTHORIZED', $this->attempt($payment['id'])->status->value);

        $captured = $this->fakeProvider()->settle($authorized->reference, 'captured');
        $this->providerSends('payment.captured', $captured)->assertOk();
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
    }

    public function test_a_webhook_settles_a_payment_even_while_the_providers_api_cannot_be_asked(): void
    {
        [, , , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id']);
        $this->fakeProvider()->failNext('payments', ProviderFailure::Unavailable, 50);

        // The provider's API is down, its webhook is signed: what it says moves the payment forward.
        $this->providerSends('payment.captured', $paid)->assertOk();
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);

        // But a webhook's word alone never closes an attempt as unpaid.
        [, , , $other] = $this->openPayment('+919811122233');
        $failed = $this->providerPays($other['id'], 'failed');
        $this->providerSends('payment.failed', $failed)->assertOk();
        $this->assertContains($this->attempt($other['id'])->status->value, ['PROVIDER_READY', 'PROCESSING', 'UNKNOWN']);
        $this->assertNull($this->attempt($other['id'])->closed_at);
    }

    public function test_a_webhook_naming_another_amount_never_verifies(): void
    {
        [, , , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id'], 'captured', ['amount_minor' => 100]);   // ₹1 instead of ₹500

        $this->providerSends('payment.captured', $paid)->assertOk();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('REVIEW_REQUIRED', $attempt->status->value);
        $this->assertSame('PAYMENT_AMOUNT_MISMATCH', $attempt->status_reason);
        $this->assertNull($attempt->verified_at);
    }

    public function test_a_genuine_event_that_is_not_handled_or_belongs_to_nothing_here_is_acknowledged_and_ignored(): void
    {
        [, , , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id']);

        // An event type FoodOnTheGo does not act on (a provider may add types at any time).
        $this->providerSends('payment.downtime.started', $paid)->assertOk()->assertExactJson(['received' => true]);
        // A handled event about an order that is not a payment attempt here.
        $foreign = new ProviderPayment('fakepay_foreign00001', 'fakeorder_foreign0001', ProviderPaymentState::Captured, 'captured', $paid->amount);
        $this->providerSends('payment.captured', $foreign)->assertOk();

        $this->assertSame(['EVENT_NOT_HANDLED', 'NO_MATCHING_PAYMENT'], PaymentWebhookEvent::query()->orderBy('id')->pluck('outcome')->all());
        $this->assertSame(['IGNORED', 'IGNORED'], PaymentWebhookEvent::query()->orderBy('id')->pluck('processing_status')->all());
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
    }

    public function test_bodies_that_cannot_be_events_are_refused_safely(): void
    {
        $sign = fn (string $body): array => [FakePaymentProvider::SIGNATURE_HEADER => hash_hmac('sha256', $body, 'fake-provider-webhook-key')];

        $this->sendWebhook('', [])->assertStatus(400)->assertJsonPath('error.code', 'webhook_payload_invalid');
        // Correctly signed, but not an event: nothing is stored.
        foreach (['not json', '[]', '{"object":"something"}', '{"object":"event"}', '{"object":"event","type":""}'] as $body) {
            $this->sendWebhook($body, $sign($body))->assertStatus(400)->assertJsonPath('error.code', 'webhook_payload_invalid');
        }
        $huge = json_encode(['object' => 'event', 'type' => 'payment.captured', 'padding' => str_repeat('x', 70000)]);
        $this->sendWebhook($huge, $sign($huge))->assertStatus(413)->assertJsonPath('error.code', 'webhook_payload_too_large');

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_only_the_configured_provider_has_a_webhook_endpoint(): void
    {
        [, , , $payment] = $this->openPayment();
        [$body, $headers] = $this->fakeProvider()->webhook('payment.captured', $this->providerPays($payment['id']));

        // Razorpay is not the configured provider here, and an unknown name is nothing at all.
        $this->sendWebhook($body, $headers, 'razorpay')->assertNotFound();
        $this->sendWebhook($body, $headers, 'stripe')->assertNotFound();
        $this->sendWebhook($body, $headers, 'FAKE')->assertNotFound();
        // The fake provider's endpoint disappears where fake providers are not allowed (as in production).
        config(['payments.providers.allow_fake' => false]);
        $this->sendWebhook($body, $headers)->assertNotFound();

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
    }

    public function test_the_request_only_stores_the_event_and_the_work_is_done_on_the_queue(): void
    {
        Queue::fake();
        [, , , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id']);

        $this->providerSends('payment.captured', $paid)->assertOk();

        // Acknowledged and stored — nothing decided yet.
        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame('RECEIVED', $event->processing_status);
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
        Queue::assertPushedOn('payments', ProcessPaymentWebhookEvent::class, fn (ProcessPaymentWebhookEvent $job): bool => $job->eventId === $event->getKey());

        // The worker runs: the payment is verified. Running the job again does nothing more.
        $webhooks = $this->app->make(PaymentWebhookService::class);
        (new ProcessPaymentWebhookEvent($event->getKey()))->handle($webhooks);
        (new ProcessPaymentWebhookEvent($event->getKey()))->handle($webhooks);
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
        $this->assertSame(['PROCESSED', 1], [$event->refresh()->processing_status, (int) $event->processing_attempts]);
    }

    public function test_an_accepted_event_that_no_worker_finished_is_picked_up_by_the_sweep(): void
    {
        Queue::fake();
        [, , , $payment] = $this->openPayment();
        $this->providerSends('payment.captured', $this->providerPays($payment['id']))->assertOk();
        $event = PaymentWebhookEvent::query()->sole();
        Queue::assertPushed(ProcessPaymentWebhookEvent::class, 1);

        // The worker died with the job. A minute later the sweep hands the event to a worker again.
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        Queue::assertPushed(ProcessPaymentWebhookEvent::class, 2);

        (new ProcessPaymentWebhookEvent($event->getKey()))->handle($this->app->make(PaymentWebhookService::class));
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
    }

    public function test_no_raw_payload_and_no_customer_contact_details_are_kept_of_a_webhook(): void
    {
        [, , , $payment] = $this->openPayment();
        $this->providerSends('payment.captured', $this->providerPays($payment['id'], 'captured', ['method' => 'CARD']))->assertOk();

        $columns = Schema::getColumnListing('payment_webhook_events');
        foreach (['payload', 'raw_payload', 'body', 'raw_body', 'headers', 'signature'] as $column) {
            $this->assertNotContains($column, $columns);
        }
        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame(64, strlen($event->payload_hash));
        // jsonb keeps no key order: the set of fields is what matters.
        $this->assertEqualsCanonicalizing(['reference', 'order', 'state', 'provider_status', 'amount_minor', 'currency', 'method', 'method_summary', 'error_code', 'error_reason', 'error_source'], array_keys($event->summary['payment']));
        $stored = json_encode($event->getAttributes());
        foreach (['email', 'contact', 'phone', '+9198', 'signature', 'secret'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $stored);
        }
    }
}
