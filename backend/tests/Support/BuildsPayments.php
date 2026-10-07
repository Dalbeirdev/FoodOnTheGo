<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\Providers\FakePaymentProvider;
use Illuminate\Testing\TestResponse;

/**
 * Payments in tests (Module 30): a confirmed ₹500 checkout, the payment API as a client calls it, and the FAKE
 * provider's side — the customer paying in the provider's interface, the provider sending a webhook.
 *
 * The fake provider keeps its own record and signs what it hands out, so every test goes through the same
 * verification as a real provider would. No test sets a payment's status directly.
 */
trait BuildsPayments
{
    use BuildsCheckout;

    protected function fakeProvider(): FakePaymentProvider
    {
        $provider = $this->app->make(PaymentProviderManager::class)->named(FakePaymentProvider::NAME);
        $this->assertInstanceOf(FakePaymentProvider::class, $provider, 'The test environment uses the fake payment provider.');

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $checkout  a READY checkout as the API returned it
     * @param  array<string, mixed>  $body
     */
    protected function startPayment(array $headers, array $checkout, array $body = [], array $extraHeaders = []): TestResponse
    {
        return $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/payment-attempts', ['pricing_fingerprint' => $checkout['pricing']['fingerprint'], ...$body], [...$this->using($headers), ...$extraHeaders]);
    }

    protected function showPayment(array $headers, string $id): TestResponse
    {
        return $this->getJson('/api/v1/payments/'.$id, $this->using($headers));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function completePayment(array $headers, string $id, array $body): TestResponse
    {
        return $this->postJson('/api/v1/payments/'.$id.'/complete', $body, $this->using($headers));
    }

    /**
     * A customer with a confirmed ₹500 checkout and a payment attempt the provider is ready for.
     *
     * @return array{0: Customer, 1: array<string, string>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    protected function openPayment(string $phone = '+919876543210'): array
    {
        [$customer, $headers, $checkout] = $this->readyCheckout(phone: $phone);
        $payment = $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.status', 'PROVIDER_READY')->json('payment');

        return [$customer, $headers, $checkout, $payment];
    }

    protected function attempt(string $id): PaymentAttempt
    {
        return PaymentAttempt::query()->where('public_id', $id)->firstOrFail();
    }

    /**
     * The customer pays in the provider's interface: the provider records a payment on the attempt's order.
     *
     * @param  'captured'|'authorized'|'failed'|'created'  $outcome
     * @param  array<string, mixed>  $options
     */
    protected function providerPays(string $paymentId, string $outcome = 'captured', array $options = []): ProviderPayment
    {
        return $this->fakeProvider()->pay((string) $this->attempt($paymentId)->provider_order_reference, $outcome, $options);
    }

    /**
     * What the provider's interface hands the device after a payment: the reference and the provider's proof.
     *
     * @return array{outcome: string, provider_payment_reference: string, provider_signature: string}
     */
    protected function proofOf(string $paymentId, ProviderPayment $paid): array
    {
        return [
            'outcome' => 'success',
            'provider_payment_reference' => $paid->reference,
            'provider_signature' => $this->fakeProvider()->signature((string) $this->attempt($paymentId)->provider_order_reference, $paid->reference),
        ];
    }

    /**
     * The provider sends a webhook: the raw body with its signature and event id, to the public endpoint, with no
     * customer or admin session.
     */
    protected function providerSends(string $type, ProviderPayment $payment, ?string $eventId = null): TestResponse
    {
        [$body, $headers] = $this->fakeProvider()->webhook($type, $payment, $eventId);

        return $this->sendWebhook($body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function sendWebhook(string $body, array $headers, string $provider = 'fake'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/v1/webhooks/payments/'.$provider, [], [], [], $server, $body);
    }
}
