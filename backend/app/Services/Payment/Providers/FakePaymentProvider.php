<?php

namespace App\Services\Payment\Providers;

use App\Contracts\Payment\PaymentProvider;
use App\Enums\ProviderFailure;
use App\Enums\ProviderPaymentState;
use App\Exceptions\ProviderException;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\Data\ProviderRefundRequest;
use App\Services\Payment\Data\ProviderSession;
use App\Services\Payment\Data\ProviderSessionRequest;
use App\Services\Payment\Data\ProviderWebhook;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A payment provider that exists only on this machine — for automated tests and for trying the payment flows
 * locally without a provider account. NO MONEY MOVES. It behaves like a real provider where it matters: it keeps
 * its own record of orders and payments (in the cache), it signs what it hands the customer's device and what it
 * sends as a webhook, and the application verifies both exactly as it verifies a real provider.
 *
 * The methods after "the provider's side" play the customer and the provider: they are called by tests and by the
 * development simulator, never by the payment flow itself.
 *
 * It is refused wherever fake providers are not allowed (always in production) — see PaymentProviderManager.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const NAME = 'fake';

    public const SIGNATURE_HEADER = 'x-fake-signature';

    public const EVENT_ID_HEADER = 'x-fake-event-id';

    /** Signing keys of a provider that does not exist. They protect nothing and are not credentials. */
    private const SIGNING_KEY = 'fake-provider-signing-key';

    private const WEBHOOK_KEY = 'fake-provider-webhook-key';

    private const PREFIX = 'payments:fake:';

    private const TTL = 172800;

    private const EVENTS = [
        'payment.authorized' => ProviderWebhook::PAYMENT_AUTHORIZED,
        'payment.captured' => ProviderWebhook::PAYMENT_CAPTURED,
        'payment.failed' => ProviderWebhook::PAYMENT_FAILED,
        'order.paid' => ProviderWebhook::ORDER_PAID,
    ];

    public function name(): string
    {
        return self::NAME;
    }

    public function mode(): string
    {
        return 'FAKE';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function missingConfiguration(): array
    {
        return [];
    }

    public function clientKey(): ?string
    {
        return 'fake_key_local';
    }

    public function createSession(ProviderSessionRequest $request): ProviderSession
    {
        $failure = $this->scripted('create_session');
        // A scripted "lost answer": the order is created, the caller is told nothing.
        if ($failure === ProviderFailure::Timeout && $this->flag('create_session_lost')) {
            $this->store($request);
            throw ProviderException::timeout(self::NAME);
        }
        if ($failure !== null) {
            throw new ProviderException($failure, self::NAME);
        }
        if (($existing = $this->findSessionByReceipt($request->receipt)) !== null) {
            return $existing; // the receipt is the provider's idempotency key
        }

        return $this->store($request);
    }

    public function findSessionByReceipt(string $receipt): ?ProviderSession
    {
        $this->failIfScripted('find_session');
        $reference = Cache::get(self::PREFIX.'receipt:'.$receipt);

        return is_string($reference) ? $this->session($reference) : null;
    }

    public function paymentsOfSession(string $orderReference): array
    {
        $this->failIfScripted('payments');
        $order = $this->order($orderReference);

        return array_values(array_filter(array_map(fn (string $ref): ?ProviderPayment => $this->read($ref), $order['payments'] ?? [])));
    }

    public function payment(string $paymentReference): ProviderPayment
    {
        $this->failIfScripted('payment');

        return $this->read($paymentReference) ?? throw ProviderException::notFound(self::NAME);
    }

    public function verifyClientSignature(string $orderReference, string $paymentReference, string $signature): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $signature) === 1 && hash_equals($this->signature($orderReference, $paymentReference), $signature);
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        return is_string($signature) && preg_match('/\A[0-9a-f]{64}\z/', $signature) === 1 && hash_equals(hash_hmac('sha256', $rawBody, self::WEBHOOK_KEY), $signature);
    }

    public function webhookSignatureHeader(): string
    {
        return self::SIGNATURE_HEADER;
    }

    public function parseWebhook(string $rawBody, array $headers): ProviderWebhook
    {
        $event = json_decode($rawBody, true);
        if (! is_array($event) || ($event['object'] ?? null) !== 'event' || ! is_string($event['type'] ?? null) || $event['type'] === '') {
            throw new InvalidArgumentException('not a provider event');
        }
        $payment = is_array($event['payment'] ?? null) ? self::fromRecord($event['payment']) : null;
        $eventId = trim((string) ($headers[self::EVENT_ID_HEADER] ?? ''));

        return new ProviderWebhook(
            eventReference: $eventId !== '' ? mb_substr($eventId, 0, 100) : null,
            type: mb_substr($event['type'], 0, 64),
            normalizedType: self::EVENTS[$event['type']] ?? null,
            orderReference: is_string($event['order'] ?? null) ? $event['order'] : $payment?->orderReference,
            payment: $payment,
            createdAt: is_int($event['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($event['created_at']) : null,
        );
    }

    public function configuredMethods(): ?array
    {
        return ['UPI', 'CARD', 'NET_BANKING', 'WALLET'];
    }

    public function refund(ProviderRefundRequest $request): array
    {
        $payment = $this->read($request->paymentReference) ?? throw ProviderException::notFound(self::NAME);
        if (! $payment->captured()) {
            throw ProviderException::rejected(self::NAME, 'only a captured payment can be refunded');
        }

        return ['reference' => 'fakerfnd_'.Str::lower(Str::random(14)), 'status' => 'PROCESSED', 'amount_minor' => $request->amount->amount, 'currency' => $request->amount->currency];
    }

    /* ------------------------------------------------------------------ the provider's side */

    /**
     * The customer pays (or fails to) in the provider's interface: a payment appears on the order.
     *
     * @param  'captured'|'authorized'|'failed'|'created'  $outcome
     * @param  array{amount_minor?: int, currency?: string, method?: string, error_reason?: string, error_code?: string}  $options
     */
    public function pay(string $orderReference, string $outcome = 'captured', array $options = []): ProviderPayment
    {
        $order = $this->order($orderReference);
        $state = match ($outcome) {
            'captured' => ProviderPaymentState::Captured,
            'authorized' => ProviderPaymentState::Authorized,
            'failed' => ProviderPaymentState::Failed,
            'created' => ProviderPaymentState::Created,
            default => throw new InvalidArgumentException("unknown outcome {$outcome}"),
        };
        $method = $options['method'] ?? 'UPI';
        $record = [
            'reference' => 'fakepay_'.Str::lower(Str::random(14)),
            'order' => $orderReference,
            'state' => $state->value,
            'amount_minor' => $options['amount_minor'] ?? $order['amount_minor'],
            'currency' => $options['currency'] ?? $order['currency'],
            'method' => $method,
            'summary' => match ($method) {
                'CARD' => ['network' => 'Visa', 'card_type' => 'debit', 'last4' => '1007'],
                'UPI' => ['vpa' => 'su***@fake'],
                'NET_BANKING' => ['bank' => 'FAKE'],
                'WALLET' => ['wallet' => 'fakewallet'],
                default => [],
            },
            'error_code' => $state === ProviderPaymentState::Failed ? ($options['error_code'] ?? 'BAD_REQUEST_ERROR') : null,
            'error_reason' => $state === ProviderPaymentState::Failed ? ($options['error_reason'] ?? 'payment_failed') : null,
            'created_at' => now()->utc()->getTimestamp(),
        ];
        Cache::put(self::PREFIX.'payment:'.$record['reference'], $record, self::TTL);
        $order['payments'][] = $record['reference'];
        $order['status'] = $state === ProviderPaymentState::Captured ? 'paid' : 'attempted';
        Cache::put(self::PREFIX.'order:'.$orderReference, $order, self::TTL);

        return self::fromRecord($record);
    }

    /**
     * Later, the provider learns how a payment ended: a pending or authorized one is captured, or fails.
     *
     * @param  'captured'|'failed'|'authorized'  $outcome
     */
    public function settle(string $paymentReference, string $outcome, ?string $errorReason = null): ProviderPayment
    {
        $record = Cache::get(self::PREFIX.'payment:'.$paymentReference);
        if (! is_array($record)) {
            throw ProviderException::notFound(self::NAME);
        }
        $record['state'] = match ($outcome) {
            'captured' => ProviderPaymentState::Captured->value,
            'failed' => ProviderPaymentState::Failed->value,
            'authorized' => ProviderPaymentState::Authorized->value,
            default => throw new InvalidArgumentException("unknown outcome {$outcome}"),
        };
        $record['error_code'] = $outcome === 'failed' ? 'BAD_REQUEST_ERROR' : null;
        $record['error_reason'] = $outcome === 'failed' ? ($errorReason ?? 'payment_failed') : null;
        Cache::put(self::PREFIX.'payment:'.$paymentReference, $record, self::TTL);
        if ($outcome === 'captured') {
            $order = $this->order((string) $record['order']);
            $order['status'] = 'paid';
            Cache::put(self::PREFIX.'order:'.$record['order'], $order, self::TTL);
        }

        return self::fromRecord($record);
    }

    /**
     * The proof the provider's interface hands the customer's device after a successful payment.
     */
    public function signature(string $orderReference, string $paymentReference): string
    {
        return hash_hmac('sha256', $orderReference.'|'.$paymentReference, self::SIGNING_KEY);
    }

    /**
     * A webhook as the provider would send it: the raw body and the headers that sign and identify it.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function webhook(string $type, ProviderPayment $payment, ?string $eventId = null): array
    {
        $body = json_encode([
            'object' => 'event',
            'type' => $type,
            'order' => $payment->orderReference,
            'payment' => self::toRecord($payment),
            'created_at' => now()->utc()->getTimestamp(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [$body, [
            self::SIGNATURE_HEADER => hash_hmac('sha256', $body, self::WEBHOOK_KEY),
            self::EVENT_ID_HEADER => $eventId ?? 'fakeevt_'.Str::lower(Str::random(16)),
        ]];
    }

    /**
     * The next calls of an operation fail the way a provider can: create_session, find_session, payments, payment.
     * With $lostAnswer a create_session times out AFTER the order was created.
     */
    public function failNext(string $operation, ProviderFailure $failure, int $times = 1, bool $lostAnswer = false): void
    {
        Cache::put(self::PREFIX.'fail:'.$operation, ['failure' => $failure->value, 'times' => max(1, $times)], self::TTL);
        if ($lostAnswer) {
            Cache::put(self::PREFIX.'flag:'.$operation.'_lost', true, self::TTL);
        }
    }

    public function recover(): void
    {
        foreach (['create_session', 'find_session', 'payments', 'payment'] as $operation) {
            Cache::forget(self::PREFIX.'fail:'.$operation);
            Cache::forget(self::PREFIX.'flag:'.$operation.'_lost');
        }
    }

    /* ------------------------------------------------------------------ internals */

    private function store(ProviderSessionRequest $request): ProviderSession
    {
        $reference = 'fakeorder_'.Str::lower(Str::random(14));
        Cache::put(self::PREFIX.'order:'.$reference, [
            'reference' => $reference,
            'amount_minor' => $request->amount->amount,
            'currency' => $request->amount->currency,
            'receipt' => $request->receipt,
            'status' => 'created',
            'payments' => [],
        ], self::TTL);
        Cache::put(self::PREFIX.'receipt:'.$request->receipt, $reference, self::TTL);

        return $this->session($reference);
    }

    /**
     * @return array{reference: string, amount_minor: int, currency: string, receipt: string, status: string, payments: list<string>}
     */
    private function order(string $reference): array
    {
        $order = Cache::get(self::PREFIX.'order:'.$reference);

        return is_array($order) ? $order : throw ProviderException::notFound(self::NAME);
    }

    private function session(string $reference): ProviderSession
    {
        $order = $this->order($reference);

        return new ProviderSession($order['reference'], new Money((int) $order['amount_minor'], (string) $order['currency']), (string) $order['receipt'], (string) $order['status'], $order['status'] === 'paid');
    }

    private function read(string $paymentReference): ?ProviderPayment
    {
        $record = Cache::get(self::PREFIX.'payment:'.$paymentReference);

        return is_array($record) ? self::fromRecord($record) : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function fromRecord(array $record): ProviderPayment
    {
        $state = ProviderPaymentState::tryFrom((string) ($record['state'] ?? '')) ?? ProviderPaymentState::Unknown;

        return new ProviderPayment(
            reference: (string) ($record['reference'] ?? ''),
            orderReference: is_string($record['order'] ?? null) ? $record['order'] : null,
            state: $state,
            providerStatus: strtolower($state->value),
            amount: new Money((int) ($record['amount_minor'] ?? 0), (string) ($record['currency'] ?? 'XXX')),
            methodType: is_string($record['method'] ?? null) ? $record['method'] : null,
            methodSummary: is_array($record['summary'] ?? null) ? $record['summary'] : [],
            errorCode: is_string($record['error_code'] ?? null) ? $record['error_code'] : null,
            errorReason: is_string($record['error_reason'] ?? null) ? $record['error_reason'] : null,
            createdAt: is_int($record['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($record['created_at']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function toRecord(ProviderPayment $payment): array
    {
        return [
            'reference' => $payment->reference,
            'order' => $payment->orderReference,
            'state' => $payment->state->value,
            'amount_minor' => $payment->amount->amount,
            'currency' => $payment->amount->currency,
            'method' => $payment->methodType,
            'summary' => $payment->methodSummary,
            'error_code' => $payment->errorCode,
            'error_reason' => $payment->errorReason,
            'created_at' => $payment->createdAt?->getTimestamp(),
        ];
    }

    private function failIfScripted(string $operation): void
    {
        if (($failure = $this->scripted($operation)) !== null) {
            throw new ProviderException($failure, self::NAME);
        }
    }

    private function scripted(string $operation): ?ProviderFailure
    {
        $script = Cache::get(self::PREFIX.'fail:'.$operation);
        if (! is_array($script)) {
            return null;
        }
        $script['times']--;
        $script['times'] > 0 ? Cache::put(self::PREFIX.'fail:'.$operation, $script, self::TTL) : Cache::forget(self::PREFIX.'fail:'.$operation);

        return ProviderFailure::tryFrom((string) $script['failure']);
    }

    private function flag(string $name): bool
    {
        return (bool) Cache::pull(self::PREFIX.'flag:'.$name);
    }
}
