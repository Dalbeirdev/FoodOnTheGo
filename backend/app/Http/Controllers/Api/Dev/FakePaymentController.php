<?php

namespace App\Http\Controllers\Api\Dev;

use App\Enums\ProviderFailure;
use App\Enums\ProviderPaymentState;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\PaymentAttemptStore;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Payment\Providers\FakePaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DEVELOPMENT ONLY — the fake payment provider's side of a payment (Module 30).
 *
 * A real provider has its own interface, its own servers and its own webhooks. The fake one has this endpoint: it
 * plays the customer in the provider's interface and the provider itself, so the payment flows can be tried on this
 * machine without a provider account. NO MONEY MOVES.
 *
 * It does not shortcut anything: it only changes the FAKE PROVIDER's record and returns what a provider's interface
 * would hand the device (a payment reference and the provider's proof) or sends what a provider would send
 * (a signed webhook, through the same verification as a real one). Whether the payment becomes VERIFIED is still
 * decided by the payment domain, exactly as for a real provider.
 *
 * The route exists only where fake providers are allowed — never in production — and answers only for an attempt
 * of the signed-in customer that was created with the fake provider.
 */
class FakePaymentController extends Controller
{
    private const SCENARIOS = ['SUCCESS', 'AUTHORIZED', 'PENDING', 'FAILED', 'CANCELLED', 'INVALID_SIGNATURE', 'AMOUNT_MISMATCH', 'LATE_SUCCESS', 'LATE_FAILURE', 'PROVIDER_DOWN', 'PROVIDER_UP'];

    private const WEBHOOKS = ['none', 'deliver', 'first', 'only', 'duplicate', 'out_of_order'];

    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentAttemptStore $attempts,
        private readonly PaymentWebhookService $webhooks,
    ) {}

    public function simulate(Request $request, string $payment): JsonResponse
    {
        $input = $request->validate([
            'scenario' => ['required', 'string', 'in:'.implode(',', self::SCENARIOS)],
            'webhook' => ['sometimes', 'string', 'in:'.implode(',', self::WEBHOOKS)],
            'method' => ['sometimes', 'string', 'in:UPI,CARD,NET_BANKING,WALLET'],
            'reason' => ['sometimes', 'string', 'max:64', 'regex:/\A[a-z0-9_]+\z/'],
        ]);
        /** @var Customer $customer */
        $customer = $request->user();
        $attempt = $this->attempts->ownedBy($customer, $payment);
        $fake = $this->providers->named(FakePaymentProvider::NAME);
        if (! $fake instanceof FakePaymentProvider || $attempt->provider !== FakePaymentProvider::NAME || $attempt->provider_order_reference === null) {
            throw ApiException::notFound('payment_not_found', 'This payment does not exist.');
        }
        $order = (string) $attempt->provider_order_reference;
        $webhook = (string) ($input['webhook'] ?? 'none');
        $options = array_filter(['method' => $input['method'] ?? null, 'error_reason' => $input['reason'] ?? null]);
        $result = ['scenario' => $input['scenario'], 'provider' => FakePaymentProvider::NAME, 'money_moved' => false];

        switch ($input['scenario']) {
            case 'SUCCESS':
            case 'AUTHORIZED':
                $paid = $fake->pay($order, $input['scenario'] === 'SUCCESS' ? 'captured' : 'authorized', $options);
                $result += $this->deliver($fake, $webhook, $paid, $input['scenario'] === 'SUCCESS' ? 'payment.captured' : 'payment.authorized');
                // The provider's interface hands the device a reference and the provider's proof — unless the device never hears back.
                $result += $webhook === 'only' ? ['client' => null] : ['client' => ['outcome' => 'success', 'provider_payment_reference' => $paid->reference, 'provider_signature' => $fake->signature($order, $paid->reference)]];
                break;
            case 'INVALID_SIGNATURE':
                $paid = $fake->pay($order, 'captured', $options);
                $result['client'] = ['outcome' => 'success', 'provider_payment_reference' => $paid->reference, 'provider_signature' => str_repeat('0', 64)];
                break;
            case 'AMOUNT_MISMATCH':
                // The provider captured another amount than the attempt's: must never become VERIFIED.
                $paid = $fake->pay($order, 'captured', $options + ['amount_minor' => (int) $attempt->amount_minor + 100]);
                $result += $this->deliver($fake, $webhook, $paid, 'payment.captured');
                $result['client'] = ['outcome' => 'success', 'provider_payment_reference' => $paid->reference, 'provider_signature' => $fake->signature($order, $paid->reference)];
                break;
            case 'PENDING':
                $paid = $fake->pay($order, 'created', $options);
                $result['client'] = null; // nothing is handed back while the bank has not answered
                $result['provider_payment_reference'] = $paid->reference;
                break;
            case 'FAILED':
                $paid = $fake->pay($order, 'failed', $options);
                $result += $this->deliver($fake, $webhook, $paid, 'payment.failed');
                $result['client'] = ['outcome' => 'failed', 'provider_error' => ['code' => $paid->errorCode, 'reason' => $paid->errorReason]];
                break;
            case 'CANCELLED':
                $result['client'] = ['outcome' => 'cancelled'];
                break;
            case 'LATE_SUCCESS':
            case 'LATE_FAILURE':
                $last = collect($fake->paymentsOfSession($order))->last() ?? throw new ApiException(409, 'payment_not_ready', 'There is no payment at the fake provider to settle.');
                $settled = $fake->settle($last->reference, $input['scenario'] === 'LATE_SUCCESS' ? 'captured' : 'failed', $input['reason'] ?? null);
                $result += $this->deliver($fake, $webhook, $settled, $input['scenario'] === 'LATE_SUCCESS' ? 'payment.captured' : 'payment.failed');
                $result['client'] = null;
                break;
            case 'PROVIDER_DOWN':
                foreach (['payments', 'payment', 'find_session', 'create_session'] as $operation) {
                    $fake->failNext($operation, ProviderFailure::Unavailable, 1000);
                }
                $result['client'] = null;
                break;
            case 'PROVIDER_UP':
                $fake->recover();
                $result['client'] = null;
                break;
        }

        return response()->json(['simulation' => $result]);
    }

    /**
     * Sends the provider's webhook through the real acceptance path — signature, deduplication, queue.
     *
     * @return array{webhooks: list<array{type: string, result: string}>}
     */
    private function deliver(FakePaymentProvider $fake, string $webhook, ProviderPayment $payment, string $type): array
    {
        $sent = [];
        $send = function (string $type, ProviderPayment $payment, ?string $eventId = null) use ($fake, &$sent): string {
            [$body, $headers] = $fake->webhook($type, $payment, $eventId);
            $sent[] = ['type' => $type, 'result' => $this->webhooks->accept(FakePaymentProvider::NAME, $body, $headers)];

            return $headers[FakePaymentProvider::EVENT_ID_HEADER];
        };
        match ($webhook) {
            'deliver', 'first', 'only' => $send($type, $payment),
            // The provider delivers the same event twice.
            'duplicate' => $send($type, $payment, $send($type, $payment)),
            // The final event first, an earlier one afterwards.
            'out_of_order' => [$send($type, $payment), $send('payment.authorized', new ProviderPayment($payment->reference, $payment->orderReference, ProviderPaymentState::Authorized, 'authorized', $payment->amount, $payment->methodType, $payment->methodSummary))],
            default => null,
        };

        return ['webhooks' => $sent];
    }
}
