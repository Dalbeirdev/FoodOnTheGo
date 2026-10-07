<?php

namespace App\Services\Payment\Providers;

use App\Contracts\Payment\PaymentProvider;
use App\Enums\ProviderPaymentState;
use App\Exceptions\ProviderException;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\Data\ProviderRefundRequest;
use App\Services\Payment\Data\ProviderSession;
use App\Services\Payment\Data\ProviderSessionRequest;
use App\Services\Payment\Data\ProviderWebhook;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Razorpay, as documented by Razorpay on 2026-10-07 (docs/backend/payment-domain.md lists the pages):
 *
 *   order      POST /v1/orders            amount in currency subunits, currency, receipt (≤ 40 ASCII, unique — a second
 *                                         create with the same receipt is refused), notes, payment.capture
 *   payments   GET  /v1/orders/{id}/payments, GET /v1/payments/{id}
 *   proof      hex HMAC-SHA256(order_id + "|" + payment_id, key secret) — with OUR order id, never the returned one
 *   webhook    header X-Razorpay-Signature = hex HMAC-SHA256(raw body, webhook secret); X-Razorpay-Event-Id is unique per event
 *
 * Nothing of Razorpay's vocabulary leaves this class: states become ProviderPaymentState, methods become the
 * application's method types, and of a payment only safe fields are kept (never an e-mail, a phone number, a full
 * card or UPI address). The key secret and the webhook secret are used here and nowhere else.
 */
final class RazorpayPaymentProvider implements PaymentProvider
{
    public const NAME = 'razorpay';

    private const BASE_URL = 'https://api.razorpay.com/v1';

    private const SIGNATURE_HEADER = 'x-razorpay-signature';

    private const EVENT_ID_HEADER = 'x-razorpay-event-id';

    private const METHODS = ['card' => 'CARD', 'upi' => 'UPI', 'netbanking' => 'NET_BANKING', 'wallet' => 'WALLET', 'emi' => 'EMI', 'paylater' => 'PAY_LATER'];

    private const EVENTS = [
        'payment.authorized' => ProviderWebhook::PAYMENT_AUTHORIZED,
        'payment.captured' => ProviderWebhook::PAYMENT_CAPTURED,
        'payment.failed' => ProviderWebhook::PAYMENT_FAILED,
        'order.paid' => ProviderWebhook::ORDER_PAID,
    ];

    /**
     * @param  array{key_id?: ?string, key_secret?: ?string, webhook_secret?: ?string, mode?: ?string, base_url?: ?string, timeout?: int|string|null, allow_live?: bool, capture?: array<string, mixed>, methods?: ?array<int, string>}  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function mode(): string
    {
        return str_starts_with($this->keyId(), 'rzp_live_') || strtolower((string) ($this->config['mode'] ?? 'test')) === 'live' ? 'LIVE' : 'TEST';
    }

    public function isFake(): bool
    {
        return false;
    }

    public function missingConfiguration(): array
    {
        $missing = [];
        if ($this->keyId() === '') {
            $missing[] = 'RAZORPAY_KEY_ID';
        }
        if ((string) ($this->config['key_secret'] ?? '') === '') {
            $missing[] = 'RAZORPAY_KEY_SECRET';
        }
        if ((string) ($this->config['webhook_secret'] ?? '') === '') {
            $missing[] = 'RAZORPAY_WEBHOOK_SECRET';
        }
        // Test mode first: a live key, or the word "live", is refused until live payments are deliberately enabled.
        if ($this->mode() === 'LIVE' && ! (bool) ($this->config['allow_live'] ?? false)) {
            $missing[] = 'RAZORPAY_KEY_ID (test mode only: live payments are not enabled)';
        } elseif ($this->keyId() !== '' && $this->mode() === 'TEST' && ! str_starts_with($this->keyId(), 'rzp_test_')) {
            $missing[] = 'RAZORPAY_KEY_ID (not a test-mode key)';
        }

        return $missing;
    }

    public function clientKey(): ?string
    {
        return $this->keyId() === '' ? null : $this->keyId();
    }

    public function createSession(ProviderSessionRequest $request): ProviderSession
    {
        $body = [
            'amount' => self::providerAmount($request->amount),
            'currency' => $request->amount->currency,
            'receipt' => $request->receipt,
            'notes' => (object) array_slice(array_map(fn (string $v): string => mb_substr($v, 0, 256), $request->notes), 0, 15, true),
        ] + $this->capture();

        try {
            $response = $this->request()->post('/orders', $body);
        } catch (ConnectionException $e) {
            // The request may have arrived. Whether the order exists is not known.
            throw ProviderException::timeout(self::NAME, $e);
        }
        if ($response->status() === 400 && str_contains(strtolower((string) $response->json('error.description')), 'duplicate')) {
            // Our receipt is unique per attempt: the provider already has the order of an earlier, unanswered try.
            return $this->findSessionByReceipt($request->receipt) ?? throw ProviderException::unavailable(self::NAME, 'duplicate receipt without an order');
        }
        if ($response->serverError()) {
            throw ProviderException::timeout(self::NAME);
        }
        $session = self::session($this->answered($response));
        if (! $session->amount->equals($request->amount)) {
            throw ProviderException::unavailable(self::NAME, 'the order does not carry the requested amount');
        }

        return $session;
    }

    public function findSessionByReceipt(string $receipt): ?ProviderSession
    {
        $response = $this->answered($this->send(fn () => $this->request()->get('/orders', ['receipt' => $receipt, 'count' => 10])));
        foreach ((array) ($response['items'] ?? []) as $order) {
            if (is_array($order) && ($order['receipt'] ?? null) === $receipt) {
                return self::session($order);
            }
        }

        return null;
    }

    public function paymentsOfSession(string $orderReference): array
    {
        self::assertReference($orderReference, 'order_');
        $response = $this->answered($this->send(fn () => $this->request()->get("/orders/{$orderReference}/payments")));

        return array_values(array_map(fn (array $p): ProviderPayment => self::mapPayment($p), array_filter((array) ($response['items'] ?? []), 'is_array')));
    }

    public function payment(string $paymentReference): ProviderPayment
    {
        self::assertReference($paymentReference, 'pay_');
        $response = $this->send(fn () => $this->request()->get("/payments/{$paymentReference}"));
        if (in_array($response->status(), [400, 404], true)) {
            throw ProviderException::notFound(self::NAME);
        }

        return self::mapPayment($this->answered($response));
    }

    public function verifyClientSignature(string $orderReference, string $paymentReference, string $signature): bool
    {
        $secret = (string) ($this->config['key_secret'] ?? '');
        if ($secret === '' || preg_match('/\A[0-9a-fA-F]{64}\z/', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderReference.'|'.$paymentReference, $secret), strtolower($signature));
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '' || $signature === null || preg_match('/\A[0-9a-fA-F]{64}\z/', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }

    public function webhookSignatureHeader(): string
    {
        return self::SIGNATURE_HEADER;
    }

    public function parseWebhook(string $rawBody, array $headers): ProviderWebhook
    {
        $event = json_decode($rawBody, true);
        if (! is_array($event) || ($event['entity'] ?? null) !== 'event' || ! is_string($event['event'] ?? null) || $event['event'] === '') {
            throw new InvalidArgumentException('not a provider event');
        }
        $payment = $event['payload']['payment']['entity'] ?? null;
        $order = $event['payload']['order']['entity'] ?? null;
        $mapped = is_array($payment) && is_string($payment['id'] ?? null) ? self::mapPayment($payment) : null;
        $eventId = trim((string) ($headers[self::EVENT_ID_HEADER] ?? ''));

        return new ProviderWebhook(
            eventReference: $eventId !== '' && strlen($eventId) <= 100 ? $eventId : null,
            type: mb_substr($event['event'], 0, 64),
            normalizedType: self::EVENTS[$event['event']] ?? null,
            orderReference: self::reference(is_array($order) ? ($order['id'] ?? null) : null) ?? $mapped?->orderReference,
            payment: $mapped,
            createdAt: is_int($event['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($event['created_at']) : null,
        );
    }

    public function configuredMethods(): ?array
    {
        $methods = $this->config['methods'] ?? null;

        return is_array($methods) && $methods !== [] ? array_values(array_map('strval', $methods)) : null;
    }

    public function refund(ProviderRefundRequest $request): array
    {
        self::assertReference($request->paymentReference, 'pay_');
        try {
            $response = $this->request()->post("/payments/{$request->paymentReference}/refund", [
                'amount' => self::providerAmount($request->amount),
                'speed' => 'normal',
                'receipt' => $request->receipt,
            ]);
        } catch (ConnectionException $e) {
            throw ProviderException::timeout(self::NAME, $e);
        }
        $refund = $this->answered($response);

        return [
            'reference' => (string) ($refund['id'] ?? ''),
            'status' => strtoupper((string) ($refund['status'] ?? 'UNKNOWN')),
            'amount_minor' => (int) ($refund['amount'] ?? 0),
            'currency' => strtoupper((string) ($refund['currency'] ?? $request->amount->currency)),
        ];
    }

    /**
     * FoodOnTheGo's minor units are the ISO minor units of the currency, and so are the provider's "currency
     * subunits" — INR 500.00 is 50000 on both sides, a zero-decimal currency passes as it is. The one difference
     * the provider documents: a three-decimal currency must end in 0.
     */
    public static function providerAmount(Money $amount): int
    {
        if ($amount->amount < 1) {
            throw ProviderException::rejected(self::NAME, 'the amount must be positive');
        }
        if ($amount->fractionDigits() === 3 && $amount->amount % 10 !== 0) {
            throw ProviderException::rejected(self::NAME, 'a three-decimal amount must end in 0');
        }

        return $amount->amount;
    }

    /* ------------------------------------------------------------------ internals */

    private function keyId(): string
    {
        return trim((string) ($this->config['key_id'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function capture(): array
    {
        $capture = (array) ($this->config['capture'] ?? []);
        if (($capture['mode'] ?? 'automatic') !== 'automatic') {
            return []; // the account's own capture setting decides
        }

        return ['payment' => ['capture' => 'automatic', 'capture_options' => [
            'automatic_expiry_period' => max(12, (int) ($capture['automatic_expiry_minutes'] ?? 12)),
            'manual_expiry_period' => max(12, min(7200, (int) ($capture['manual_expiry_minutes'] ?? 7200))),
            'refund_speed' => in_array($capture['refund_speed'] ?? 'normal', ['normal', 'optimum'], true) ? $capture['refund_speed'] ?? 'normal' : 'normal',
        ]]];
    }

    private function request(): PendingRequest
    {
        $secret = (string) ($this->config['key_secret'] ?? '');
        if ($this->keyId() === '' || $secret === '') {
            throw ProviderException::misconfigured(self::NAME, 'no API key is configured');
        }
        $timeout = max(1, (int) ($this->config['timeout'] ?? 15));

        return Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? '') ?: self::BASE_URL, '/'))
            ->withBasicAuth($this->keyId(), $secret)->acceptJson()->asJson()->timeout($timeout)->connectTimeout(min(5, $timeout));
    }

    /**
     * A read: a lost connection is a timeout, and the call may be repeated by the caller.
     */
    private function send(\Closure $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw ProviderException::timeout(self::NAME, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function answered(Response $response): array
    {
        if ($response->successful() && is_array($response->json())) {
            return $response->json();
        }
        $status = $response->status();
        // The provider's description may name a field; nothing else of the answer is kept.
        $field = mb_substr((string) $response->json('error.field'), 0, 40);
        throw match (true) {
            $status === 429 => ProviderException::rateLimited(self::NAME),
            $status === 401 || $status === 403 => ProviderException::misconfigured(self::NAME, 'the API key was refused'),
            $status === 404 => ProviderException::notFound(self::NAME),
            $status >= 400 && $status < 500 => ProviderException::rejected(self::NAME, 'HTTP '.$status.($field !== '' ? " ({$field})" : '')),
            default => ProviderException::unavailable(self::NAME, "HTTP {$status}"),
        };
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private static function session(array $order): ProviderSession
    {
        $reference = self::reference($order['id'] ?? null);
        $currency = strtoupper((string) ($order['currency'] ?? ''));
        if ($reference === null || ! str_starts_with($reference, 'order_') || ! is_int($order['amount'] ?? null) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw ProviderException::unavailable(self::NAME, 'an order answer that cannot be read');
        }

        return new ProviderSession(
            reference: $reference,
            amount: new Money($order['amount'], $currency),
            receipt: (string) ($order['receipt'] ?? ''),
            providerStatus: mb_substr((string) ($order['status'] ?? ''), 0, 24),
            paid: ($order['status'] ?? null) === 'paid',
        );
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function mapPayment(array $p): ProviderPayment
    {
        $reference = self::reference($p['id'] ?? null);
        $currency = strtoupper((string) ($p['currency'] ?? ''));
        if ($reference === null || ! is_int($p['amount'] ?? null) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw ProviderException::unavailable(self::NAME, 'a payment answer that cannot be read');
        }
        $status = strtolower((string) ($p['status'] ?? ''));
        $method = strtolower((string) ($p['method'] ?? ''));

        return new ProviderPayment(
            reference: $reference,
            orderReference: self::reference($p['order_id'] ?? null),
            state: match ($status) {
                'created' => ProviderPaymentState::Created,
                'authorized' => ProviderPaymentState::Authorized,
                // "captured" is the provider's word for a completed payment; the flag has to agree.
                'captured' => ($p['captured'] ?? true) === false ? ProviderPaymentState::Unknown : ProviderPaymentState::Captured,
                'failed' => ProviderPaymentState::Failed,
                'refunded' => ProviderPaymentState::Refunded,
                default => ProviderPaymentState::Unknown,
            },
            providerStatus: mb_substr($status, 0, 24),
            amount: new Money($p['amount'], $currency),
            methodType: self::METHODS[$method] ?? null,
            methodSummary: self::methodSummary($method, $p),
            errorCode: self::text($p['error_code'] ?? null),
            errorReason: self::text($p['error_reason'] ?? null),
            errorSource: self::text($p['error_source'] ?? null),
            createdAt: is_int($p['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($p['created_at']) : null,
        );
    }

    /**
     * What may be remembered about how a customer paid. No e-mail, no phone number, no full card or UPI address.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, string|bool|null>
     */
    private static function methodSummary(string $method, array $p): array
    {
        $card = is_array($p['card'] ?? null) ? $p['card'] : [];
        $summary = match ($method) {
            'card', 'emi' => [
                'network' => self::text($card['network'] ?? null, 24),
                'card_type' => self::text($card['type'] ?? null, 12),
                'last4' => preg_match('/\A\d{4}\z/', (string) ($card['last4'] ?? '')) === 1 ? (string) $card['last4'] : null,
                'international' => is_bool($card['international'] ?? null) ? $card['international'] : null,
            ],
            'upi' => ['vpa' => self::maskVpa($p['vpa'] ?? ($p['upi']['vpa'] ?? null)), 'flow' => self::text($p['upi']['flow'] ?? null, 12)],
            'netbanking' => ['bank' => self::text($p['bank'] ?? null, 12)],
            'wallet', 'paylater' => ['wallet' => self::text($p['wallet'] ?? null, 24)],
            default => [],
        };

        return array_filter($summary, fn ($v): bool => $v !== null);
    }

    private static function maskVpa(mixed $vpa): ?string
    {
        if (! is_string($vpa) || ! str_contains($vpa, '@')) {
            return null;
        }
        [$name, $handle] = explode('@', $vpa, 2);

        return mb_substr($name, 0, 2).'***@'.mb_substr($handle, 0, 24);
    }

    private static function text(mixed $value, int $max = 64): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private static function reference(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_]{6,64}\z/', $value) === 1 ? $value : null;
    }

    private static function assertReference(string $reference, string $prefix): void
    {
        if (! str_starts_with($reference, $prefix) || self::reference($reference) === null) {
            throw ProviderException::notFound(self::NAME);
        }
    }
}
