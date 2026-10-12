<?php

namespace Tests\Feature\Payment;

use App\Enums\ProviderFailure;
use App\Enums\ProviderPaymentState;
use App\Exceptions\ProviderException;
use App\Services\Payment\Data\ProviderSessionRequest;
use App\Services\Payment\Data\ProviderWebhook;
use App\Services\Payment\Providers\RazorpayPaymentProvider;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The Razorpay adapter against Razorpay's documented API (reviewed 2026-10-07) — with the HTTP layer faked: no
 * request leaves this machine, and the keys below are test values that exist nowhere else.
 *
 * What is proven here is the adapter's side of the contract: what it sends, how it reads the answers, how it
 * verifies signatures and how failures are told apart. It is NOT a test against Razorpay's servers — that needs
 * test-mode credentials and is recorded as pending provider configuration.
 */
class RazorpayPaymentProviderTest extends TestCase
{
    private const KEY_ID = 'rzp_test_UnitTestKey00001';

    private const KEY_SECRET = 'unit-test-key-secret';

    private const WEBHOOK_SECRET = 'unit-test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function provider(array $overrides = []): RazorpayPaymentProvider
    {
        return new RazorpayPaymentProvider($overrides + ['key_id' => self::KEY_ID, 'key_secret' => self::KEY_SECRET, 'webhook_secret' => self::WEBHOOK_SECRET, 'mode' => 'test', 'timeout' => 5]);
    }

    private function order(array $overrides = []): array
    {
        return $overrides + ['id' => 'order_QxUnitTest00001', 'entity' => 'order', 'amount' => 50000, 'amount_paid' => 0, 'amount_due' => 50000, 'currency' => 'INR', 'receipt' => 'pa_0123456789abcdef0123456789abcdef', 'status' => 'created', 'attempts' => 0, 'notes' => [], 'created_at' => 1791354600];
    }

    private function payment(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'pay_QxUnitTest00001', 'entity' => 'payment', 'amount' => 50000, 'currency' => 'INR', 'status' => 'captured', 'order_id' => 'order_QxUnitTest00001', 'method' => 'card', 'captured' => true,
            'card' => ['id' => 'card_x', 'name' => 'ASHA RAO', 'last4' => '1007', 'network' => 'Visa', 'type' => 'debit', 'issuer' => 'HDFC', 'international' => false],
            'email' => 'asha@example.test', 'contact' => '+919876543210', 'fee' => 1180, 'tax' => 180, 'error_code' => null, 'error_description' => null, 'created_at' => 1791354660,
        ];
    }

    public function test_an_order_is_created_for_exactly_the_requested_amount_in_the_providers_unit(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response($this->order())]);

        $session = $this->provider()->createSession(new ProviderSessionRequest(new Money(50000, 'INR'), 'pa_0123456789abcdef0123456789abcdef', ['payment' => 'p-1', 'checkout' => 'c-1', 'environment' => 'testing']));

        $this->assertSame('order_QxUnitTest00001', $session->reference);
        $this->assertTrue($session->amount->equals(new Money(50000, 'INR')));
        $this->assertFalse($session->paid);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);   // what actually goes over the wire

            return $request->method() === 'POST'
                && $request->url() === 'https://api.razorpay.com/v1/orders'
                // Basic authentication with the key id and the key secret — the secret travels to the provider only.
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::KEY_ID.':'.self::KEY_SECRET))
                // ₹500.00 is 50000 paise: the same integer on both sides, never multiplied or divided on the way.
                && $body['amount'] === 50000 && $body['currency'] === 'INR'
                && $body['receipt'] === 'pa_0123456789abcdef0123456789abcdef' && strlen($body['receipt']) <= 40
                && $body['notes'] === ['payment' => 'p-1', 'checkout' => 'c-1', 'environment' => 'testing']
                && $body['payment'] === ['capture' => 'automatic', 'capture_options' => ['automatic_expiry_period' => 12, 'manual_expiry_period' => 7200, 'refund_speed' => 'normal']];
        });
    }

    public function test_the_accounts_own_capture_setting_can_be_left_to_decide(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response($this->order())]);

        $this->provider(['capture' => ['mode' => 'dashboard']])->createSession(new ProviderSessionRequest(new Money(50000, 'INR'), 'pa_0123456789abcdef0123456789abcdef'));

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('payment', $request->data()));
    }

    public function test_amounts_are_passed_in_the_currencys_own_minor_unit(): void
    {
        $this->assertSame(50000, RazorpayPaymentProvider::providerAmount(new Money(50000, 'INR')));   // ₹500.00
        $this->assertSame(100, RazorpayPaymentProvider::providerAmount(new Money(100, 'INR')));       // ₹1.00
        $this->assertSame(500, RazorpayPaymentProvider::providerAmount(new Money(500, 'JPY')));       // ¥500, a zero-decimal currency, as it is
        $this->assertSame(1230, RazorpayPaymentProvider::providerAmount(new Money(1230, 'KWD')));     // KD 1.230

        foreach ([new Money(1235, 'KWD'), new Money(0, 'INR')] as $impossible) {
            try {
                RazorpayPaymentProvider::providerAmount($impossible);
                $this->fail('An amount the provider cannot represent must be refused.');
            } catch (ProviderException $e) {
                $this->assertSame(ProviderFailure::Rejected, $e->failure);
            }
        }
    }

    public function test_an_order_that_does_not_carry_the_requested_amount_is_not_accepted(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response($this->order(['amount' => 100]))]);

        $this->assertFailure(ProviderFailure::Unavailable, fn () => $this->provider()->createSession(new ProviderSessionRequest(new Money(50000, 'INR'), 'pa_0123456789abcdef0123456789abcdef')));
    }

    public function test_a_lost_connection_or_a_server_error_while_creating_means_it_is_not_known_whether_the_order_exists(): void
    {
        $request = new ProviderSessionRequest(new Money(50000, 'INR'), 'pa_0123456789abcdef0123456789abcdef');

        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->assertFailure(ProviderFailure::Timeout, fn () => $this->provider()->createSession($request));
        Http::assertSentCount(0);

        foreach ([500, 502, 503, 504] as $status) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['api.razorpay.com/v1/orders' => Http::response(['error' => ['code' => 'SERVER_ERROR']], $status)]);
            $this->assertFailure(ProviderFailure::Timeout, fn () => $this->provider()->createSession($request));
            // Creating is never repeated by the adapter: the first request may have arrived.
            Http::assertSentCount(1);
        }
    }

    public function test_a_receipt_the_provider_already_knows_returns_the_order_that_exists_instead_of_a_second_one(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Duplicate request. This request has already been processed', 'field' => 'receipt']], 400)
                : Http::response(['entity' => 'collection', 'count' => 2, 'items' => [$this->order(['id' => 'order_QxOtherOne00001', 'receipt' => 'pa_other']), $this->order()]]);
        });

        $session = $this->provider()->createSession(new ProviderSessionRequest(new Money(50000, 'INR'), 'pa_0123456789abcdef0123456789abcdef'));

        $this->assertSame('order_QxUnitTest00001', $session->reference);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_starts_with($request->url(), 'https://api.razorpay.com/v1/orders?') && $request->data()['receipt'] === 'pa_0123456789abcdef0123456789abcdef');
    }

    public function test_an_order_is_found_by_our_receipt_or_not_at_all(): void
    {
        Http::fake(['api.razorpay.com/v1/orders*' => Http::sequence()->push(['entity' => 'collection', 'count' => 1, 'items' => [$this->order()]])->push(['entity' => 'collection', 'count' => 0, 'items' => []])]);

        $this->assertSame('order_QxUnitTest00001', $this->provider()->findSessionByReceipt('pa_0123456789abcdef0123456789abcdef')?->reference);
        $this->assertNull($this->provider()->findSessionByReceipt('pa_0123456789abcdef0123456789abcdef'));
    }

    public function test_the_providers_refusals_are_told_apart(): void
    {
        $request = new ProviderSessionRequest(new Money(50000, 'INR'), 'pa_0123456789abcdef0123456789abcdef');
        foreach ([[401, ProviderFailure::Misconfigured], [403, ProviderFailure::Misconfigured], [429, ProviderFailure::RateLimited], [400, ProviderFailure::Rejected], [422, ProviderFailure::Rejected]] as [$status, $failure]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['api.razorpay.com/v1/orders' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The amount must be at least INR 1.00', 'field' => 'amount']], $status)]);
            $this->assertFailure($failure, fn () => $this->provider()->createSession($request));
        }
        // A read that loses its connection is a timeout the caller may repeat.
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->assertFailure(ProviderFailure::Timeout, fn () => $this->provider()->paymentsOfSession('order_QxUnitTest00001'));
    }

    public function test_the_payments_of_an_order_are_read_into_normalized_states_and_safe_fields_only(): void
    {
        Http::fake(['api.razorpay.com/v1/orders/order_QxUnitTest00001/payments' => Http::response(['entity' => 'collection', 'count' => 5, 'items' => [
            $this->payment(['id' => 'pay_QxFailed0000001', 'status' => 'failed', 'captured' => false, 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'Payment failed because of incorrect OTP', 'error_source' => 'customer', 'error_step' => 'payment_authentication', 'error_reason' => 'incorrect_otp']),
            $this->payment(['id' => 'pay_QxAuthorized001', 'status' => 'authorized', 'captured' => false, 'method' => 'upi', 'vpa' => 'asharao@okbank', 'upi' => ['vpa' => 'asharao@okbank', 'flow' => 'intent'], 'card' => null]),
            $this->payment(['id' => 'pay_QxNetbanking001', 'status' => 'created', 'captured' => false, 'method' => 'netbanking', 'bank' => 'HDFC', 'card' => null]),
            $this->payment(['id' => 'pay_QxSomethingNew1', 'status' => 'on_hold', 'captured' => false, 'method' => 'wallet', 'wallet' => 'payzapp', 'card' => null]),
            $this->payment(),
        ]])]);

        $payments = $this->provider()->paymentsOfSession('order_QxUnitTest00001');

        $this->assertSame([ProviderPaymentState::Failed, ProviderPaymentState::Authorized, ProviderPaymentState::Created, ProviderPaymentState::Unknown, ProviderPaymentState::Captured], array_map(fn ($p) => $p->state, $payments));
        $this->assertSame(['CARD', 'UPI', 'NET_BANKING', 'WALLET', 'CARD'], array_map(fn ($p) => $p->methodType, $payments));
        $this->assertSame(['BAD_REQUEST_ERROR', 'incorrect_otp', 'customer'], [$payments[0]->errorCode, $payments[0]->errorReason, $payments[0]->errorSource]);
        $this->assertSame(['vpa' => 'as***@okbank', 'flow' => 'intent'], $payments[1]->methodSummary);   // the address is masked
        $this->assertSame(['bank' => 'HDFC'], $payments[2]->methodSummary);
        $this->assertSame(['wallet' => 'payzapp'], $payments[3]->methodSummary);
        $captured = $payments[4];
        $this->assertTrue($captured->captured());
        $this->assertSame(['network' => 'Visa', 'card_type' => 'debit', 'last4' => '1007', 'international' => false], $captured->methodSummary);
        $this->assertSame('order_QxUnitTest00001', $captured->orderReference);
        $this->assertTrue($captured->amount->equals(new Money(50000, 'INR')));
        // Nothing of the customer and nothing sensitive survives the adapter.
        $kept = json_encode([$payments, array_map(fn ($p) => $p->summary(), $payments)]);
        foreach (['asha@example.test', '+919876543210', 'ASHA RAO', 'asharao@okbank', 'card_x', 'HDFC"', 'incorrect OTP', '1180'] as $private) {
            $this->assertStringNotContainsString($private, str_replace('"bank":"HDFC"', '', $kept), $private);
        }
    }

    public function test_a_payment_marked_captured_without_being_captured_is_not_treated_as_paid(): void
    {
        Http::fake(['api.razorpay.com/v1/payments/pay_QxUnitTest00001' => Http::response($this->payment(['captured' => false]))]);

        $this->assertSame(ProviderPaymentState::Unknown, $this->provider()->payment('pay_QxUnitTest00001')->state);
    }

    public function test_a_reference_that_cannot_be_a_provider_reference_never_reaches_the_provider(): void
    {
        foreach (['', 'order_QxUnitTest00001', 'pay_../orders', "pay_x\n", 'pay_'.str_repeat('a', 80)] as $reference) {
            $this->assertFailure(ProviderFailure::NotFound, fn () => $this->provider()->payment($reference));
        }
        $this->assertFailure(ProviderFailure::NotFound, fn () => $this->provider()->paymentsOfSession('pay_QxUnitTest00001'));
        Http::assertSentCount(0);

        Http::fake(['api.razorpay.com/v1/payments/pay_QxUnknown000001' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The id provided does not exist']], 400)]);
        $this->assertFailure(ProviderFailure::NotFound, fn () => $this->provider()->payment('pay_QxUnknown000001'));
    }

    public function test_the_proof_of_a_payment_is_the_documented_hmac_over_our_order_and_the_payment(): void
    {
        $provider = $this->provider();
        // As documented: hex HMAC-SHA256 of order_id + "|" + payment_id with the key secret.
        $signature = hash_hmac('sha256', 'order_QxUnitTest00001|pay_QxUnitTest00001', self::KEY_SECRET);

        $this->assertTrue($provider->verifyClientSignature('order_QxUnitTest00001', 'pay_QxUnitTest00001', $signature));
        $this->assertTrue($provider->verifyClientSignature('order_QxUnitTest00001', 'pay_QxUnitTest00001', strtoupper($signature)));
        // Another order, another payment, another secret, a truncated or decorated signature: all fail.
        $this->assertFalse($provider->verifyClientSignature('order_QxOtherOne00001', 'pay_QxUnitTest00001', $signature));
        $this->assertFalse($provider->verifyClientSignature('order_QxUnitTest00001', 'pay_QxOtherOne00001', $signature));
        $this->assertFalse($provider->verifyClientSignature('order_QxUnitTest00001', 'pay_QxUnitTest00001', hash_hmac('sha256', 'order_QxUnitTest00001|pay_QxUnitTest00001', 'another-secret')));
        $this->assertFalse($provider->verifyClientSignature('order_QxUnitTest00001', 'pay_QxUnitTest00001', substr($signature, 0, 40)));
        $this->assertFalse($provider->verifyClientSignature('order_QxUnitTest00001', 'pay_QxUnitTest00001', $signature.' '));
        $this->assertFalse($provider->verifyClientSignature('order_QxUnitTest00001', 'pay_QxUnitTest00001', ''));
        // Without a secret nothing can be verified — and nothing is accepted.
        $this->assertFalse($this->provider(['key_secret' => ''])->verifyClientSignature('order_QxUnitTest00001', 'pay_QxUnitTest00001', hash_hmac('sha256', 'order_QxUnitTest00001|pay_QxUnitTest00001', '')));
        Http::assertSentCount(0);
    }

    public function test_a_webhook_is_verified_over_its_raw_body_with_the_webhook_secret(): void
    {
        $provider = $this->provider();
        $body = '{"entity":"event","account_id":"acc_x","event":"payment.captured","contains":["payment"],"payload":{"payment":{"entity":'.json_encode($this->payment()).'}},"created_at":1791354700}';
        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $this->assertSame('x-razorpay-signature', $provider->webhookSignatureHeader());
        $this->assertTrue($provider->verifyWebhookSignature($body, $signature));
        // The key secret does not sign webhooks, a changed byte breaks the signature, and no signature is no webhook.
        $this->assertFalse($provider->verifyWebhookSignature($body, hash_hmac('sha256', $body, self::KEY_SECRET)));
        $this->assertFalse($provider->verifyWebhookSignature($body.' ', $signature));
        $this->assertFalse($provider->verifyWebhookSignature(json_encode(json_decode($body, true), JSON_PRETTY_PRINT), $signature));
        $this->assertFalse($provider->verifyWebhookSignature($body, null));
        $this->assertFalse($this->provider(['webhook_secret' => null])->verifyWebhookSignature($body, hash_hmac('sha256', $body, '')));
    }

    public function test_the_documented_events_are_read_and_others_are_recognized_as_not_handled(): void
    {
        $provider = $this->provider();
        $event = fn (string $name, array $payload): string => json_encode(['entity' => 'event', 'account_id' => 'acc_x', 'event' => $name, 'contains' => array_keys($payload), 'payload' => $payload, 'created_at' => 1791354700]);

        $captured = $provider->parseWebhook($event('payment.captured', ['payment' => ['entity' => $this->payment()]]), ['x-razorpay-event-id' => 'evt_QxUnit0001']);
        $this->assertSame(['evt_QxUnit0001', 'payment.captured', ProviderWebhook::PAYMENT_CAPTURED, 'order_QxUnitTest00001'], [$captured->eventReference, $captured->type, $captured->normalizedType, $captured->orderReference]);
        $this->assertTrue($captured->payment->captured());
        $this->assertSame(1791354700, $captured->createdAt->getTimestamp());

        $paid = $provider->parseWebhook($event('order.paid', ['payment' => ['entity' => $this->payment()], 'order' => ['entity' => $this->order(['status' => 'paid'])]]), []);
        $this->assertSame([null, ProviderWebhook::ORDER_PAID, 'order_QxUnitTest00001', 'pay_QxUnitTest00001'], [$paid->eventReference, $paid->normalizedType, $paid->orderReference, $paid->payment->reference]);

        $failed = $provider->parseWebhook($event('payment.failed', ['payment' => ['entity' => $this->payment(['status' => 'failed', 'captured' => false, 'error_reason' => 'payment_cancelled'])]]), []);
        $this->assertSame([ProviderWebhook::PAYMENT_FAILED, ProviderPaymentState::Failed, 'payment_cancelled'], [$failed->normalizedType, $failed->payment->state, $failed->payment->errorReason]);
        $this->assertSame(ProviderWebhook::PAYMENT_AUTHORIZED, $provider->parseWebhook($event('payment.authorized', ['payment' => ['entity' => $this->payment(['status' => 'authorized', 'captured' => false])]]), [])->normalizedType);

        // An event FoodOnTheGo has no use for is still a genuine event — just not handled.
        $other = $provider->parseWebhook($event('payment.downtime.started', ['payment.downtime' => ['entity' => ['id' => 'down_x']]]), []);
        $this->assertFalse($other->handled());
        $this->assertNull($other->payment);

        foreach (['', 'not json', '[]', '{"entity":"payment"}', '{"entity":"event"}', '{"entity":"event","event":""}'] as $body) {
            try {
                $provider->parseWebhook($body, []);
                $this->fail('A body that is not an event must be refused: '.$body);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_says_what_configuration_is_missing_by_name_and_refuses_live_mode(): void
    {
        $this->assertSame([], $this->provider()->missingConfiguration());
        $this->assertSame('TEST', $this->provider()->mode());
        $this->assertSame(self::KEY_ID, $this->provider()->clientKey());
        $this->assertFalse($this->provider()->isFake());

        $this->assertSame(['RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET'], (new RazorpayPaymentProvider([]))->missingConfiguration());
        $this->assertNull((new RazorpayPaymentProvider([]))->clientKey());
        $this->assertSame(['RAZORPAY_WEBHOOK_SECRET'], $this->provider(['webhook_secret' => ''])->missingConfiguration());
        // Test mode first: a live key — or the word "live" — is refused while live payments are not enabled.
        $live = $this->provider(['key_id' => 'rzp_live_UnitTestKey00001']);
        $this->assertSame('LIVE', $live->mode());
        $this->assertSame(['RAZORPAY_KEY_ID (test mode only: live payments are not enabled)'], $live->missingConfiguration());
        $this->assertNotSame([], $this->provider(['mode' => 'live'])->missingConfiguration());
        $this->assertSame(['RAZORPAY_KEY_ID (not a test-mode key)'], $this->provider(['key_id' => 'something_else'])->missingConfiguration());
        // Names only — never a value.
        $this->assertStringNotContainsString(self::KEY_SECRET, json_encode($this->provider(['webhook_secret' => ''])->missingConfiguration()));

        // Without keys no request is even built.
        $this->assertFailure(ProviderFailure::Misconfigured, fn () => (new RazorpayPaymentProvider([]))->createSession(new ProviderSessionRequest(new Money(50000, 'INR'), 'pa_x')));
        Http::assertSentCount(0);
    }

    public function test_method_types_are_named_only_when_they_are_configured(): void
    {
        $this->assertNull($this->provider()->configuredMethods());   // the provider's own interface shows what the account offers
        $this->assertSame(['UPI', 'CARD'], $this->provider(['methods' => ['UPI', 'CARD']])->configuredMethods());
    }

    // The refund side of the adapter (Module 35: createRefund with the idempotency key, the lookups, the refusals, the refund and
    // dispute webhooks) is tested in tests/Feature/Refund/PaymentRefundAndAdapterTest.php.

    private function assertFailure(ProviderFailure $expected, \Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected the provider to fail with '.$expected->value);
        } catch (ProviderException $e) {
            $this->assertSame($expected, $e->failure, $e->getMessage());
        }
    }
}
