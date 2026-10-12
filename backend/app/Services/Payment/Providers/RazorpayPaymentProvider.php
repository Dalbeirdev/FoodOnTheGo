<?php

namespace App\Services\Payment\Providers;

use App\Contracts\Finance\ProviderSettlementSource;
use App\Contracts\Payment\PaymentProvider;
use App\Enums\DisputeStatus;
use App\Enums\ProviderPaymentState;
use App\Enums\ProviderRefundState;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderRefundRejected;
use App\Services\Finance\ProviderSettlement\ProviderSettlementLine;
use App\Services\Finance\ProviderSettlement\ProviderSettlementRecord;
use App\Services\Payment\Data\ProviderDispute;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\Data\ProviderRefund;
use App\Services\Payment\Data\ProviderRefundRequest;
use App\Services\Payment\Data\ProviderSession;
use App\Services\Payment\Data\ProviderSessionRequest;
use App\Services\Payment\Data\ProviderWebhook;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
 * Refunds and disputes, as documented by Razorpay on 2026-10-11 (docs/backend/refunds.md lists the pages):
 *
 *   refund     POST /v1/payments/{id}/refund   amount in currency subunits, speed, receipt (unique — "duplicate receipt" is
 *                                              refused), notes; header X-Refund-Idempotency (the same key + body = the same refund)
 *   lookups    GET  /v1/payments/{id}/refunds/{refund_id}, GET /v1/payments/{id}/refunds
 *   statuses   pending (accepted, with the bank) · processed (completed) · failed
 *   events     refund.created / refund.processed / refund.failed; payment.dispute.created / under_review /
 *              action_required / won / lost / closed
 *   dispute    GET  /v1/disputes/{id}          open · under_review · won · lost · closed; amount_deducted once lost
 *
 * NOT EXERCISED against Razorpay: there is no Razorpay account on this machine. The shapes are the documented ones.
 *
 * Settlement records (Module 36, the Settlements API as documented on 2026-10-12 — PENDING PROVIDER VERIFICATION):
 *   settlements  GET /v1/settlements?from&to&count&skip                       id, amount (subunits), status created ·
 *                                                                             processed · failed, fees, tax, utr, created_at
 *   recon lines  GET /v1/settlements/recon/combined?year&month&day&count&skip entity_id, type payment · refund · transfer ·
 *                                                                             adjustment, debit, credit, amount, currency, fee,
 *                                                                             tax, on_hold, settled, created_at, settled_at,
 *                                                                             settlement_id, payment_id, settlement_utr
 *   Every number is taken as the provider sent it: no fee is calculated and no field is derived from another. Card,
 *   bank and customer fields of a line are never kept.
 *
 * Nothing of Razorpay's vocabulary leaves this class: states become ProviderPaymentState, methods become the
 * application's method types, and of a payment only safe fields are kept (never an e-mail, a phone number, a full
 * card or UPI address). The key secret and the webhook secret are used here and nowhere else.
 */
final class RazorpayPaymentProvider implements PaymentProvider, ProviderSettlementSource
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
        // Module 35 (the events documented on 2026-10-11). "refund.speed_changed" changes nothing here and is not handled.
        'refund.created' => ProviderWebhook::REFUND_CREATED,
        'refund.processed' => ProviderWebhook::REFUND_PROCESSED,
        'refund.failed' => ProviderWebhook::REFUND_FAILED,
        'payment.dispute.created' => ProviderWebhook::DISPUTE_CREATED,
        'payment.dispute.under_review' => ProviderWebhook::DISPUTE_UPDATED,
        'payment.dispute.action_required' => ProviderWebhook::DISPUTE_UPDATED,
        'payment.dispute.won' => ProviderWebhook::DISPUTE_WON,
        'payment.dispute.lost' => ProviderWebhook::DISPUTE_LOST,
        'payment.dispute.closed' => ProviderWebhook::DISPUTE_CLOSED,
    ];

    /** The provider's idempotency header for refunds: the same key with the same body is the same refund. */
    private const REFUND_IDEMPOTENCY_HEADER = 'X-Refund-Idempotency';

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
        $refund = $event['payload']['refund']['entity'] ?? null;
        $dispute = $event['payload']['dispute']['entity'] ?? null;
        $mapped = is_array($payment) && is_string($payment['id'] ?? null) ? self::mapPayment($payment) : null;
        $eventId = trim((string) ($headers[self::EVENT_ID_HEADER] ?? ''));

        return new ProviderWebhook(
            eventReference: $eventId !== '' && strlen($eventId) <= 100 ? $eventId : null,
            type: mb_substr($event['event'], 0, 64),
            normalizedType: self::EVENTS[$event['event']] ?? null,
            orderReference: self::reference(is_array($order) ? ($order['id'] ?? null) : null) ?? $mapped?->orderReference,
            payment: $mapped,
            createdAt: is_int($event['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($event['created_at']) : null,
            // Module 35: of a refund / dispute event only the refund's and the dispute's own safe fields are read.
            refund: is_array($refund) && is_string($refund['id'] ?? null) ? self::mapRefund($refund) : null,
            dispute: is_array($dispute) && is_string($dispute['id'] ?? null) ? self::mapDispute($dispute, $event['event']) : null,
        );
    }

    public function configuredMethods(): ?array
    {
        $methods = $this->config['methods'] ?? null;

        return is_array($methods) && $methods !== [] ? array_values(array_map('strval', $methods)) : null;
    }

    /* ------------------------------------------------------------------ refunds and disputes (Module 35) */

    public function createRefund(ProviderRefundRequest $request): ProviderRefund
    {
        self::assertReference($request->paymentReference, 'pay_');
        $body = [
            'amount' => self::providerAmount($request->amount),
            // "normal": back to the method that paid, through the bank's own refund rails. No instant refund is asked for.
            'speed' => 'normal',
            'receipt' => $request->receipt,
        ];
        if ($request->notes !== []) {
            $body['notes'] = (object) array_slice(array_map(fn (string $v): string => mb_substr($v, 0, 256), $request->notes), 0, 15, true);
        }
        try {
            $response = $this->request()->withHeaders([self::REFUND_IDEMPOTENCY_HEADER => $request->key()])->post("/payments/{$request->paymentReference}/refund", $body);
        } catch (ConnectionException $e) {
            // The request may have arrived. Whether the refund exists is not known.
            throw ProviderException::timeout(self::NAME, $e);
        }
        if ($response->serverError()) {
            throw ProviderException::timeout(self::NAME); // the provider may have created it before it failed to answer
        }
        if ($response->status() === 409) {
            // The same idempotency key is still being worked on (or was used with another body): nothing is concluded.
            throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::IDEMPOTENCY_CONFLICT);
        }
        if ($response->status() === 400) {
            throw new ProviderRefundRejected(self::NAME, self::refundRefusal((string) $response->json('error.description')));
        }
        $refund = self::mapRefund($this->answered($response));
        if ($refund->paymentReference !== $request->paymentReference) {
            throw ProviderException::unavailable(self::NAME, 'a refund answer for another payment');
        }

        return $refund;
    }

    public function refund(string $paymentReference, string $refundReference): ProviderRefund
    {
        self::assertReference($paymentReference, 'pay_');
        self::assertReference($refundReference, 'rfnd_');
        $response = $this->send(fn () => $this->request()->get("/payments/{$paymentReference}/refunds/{$refundReference}"));
        if (in_array($response->status(), [400, 404], true)) {
            throw ProviderException::notFound(self::NAME);
        }

        return self::mapRefund($this->answered($response));
    }

    public function refundsOfPayment(string $paymentReference): array
    {
        self::assertReference($paymentReference, 'pay_');
        $response = $this->answered($this->send(fn () => $this->request()->get("/payments/{$paymentReference}/refunds", ['count' => 100])));

        return array_values(array_map(fn (array $r): ProviderRefund => self::mapRefund($r), array_filter((array) ($response['items'] ?? []), 'is_array')));
    }

    public function dispute(string $disputeReference): ProviderDispute
    {
        self::assertReference($disputeReference, 'disp_');
        $response = $this->send(fn () => $this->request()->get("/disputes/{$disputeReference}"));
        if (in_array($response->status(), [400, 404], true)) {
            throw ProviderException::notFound(self::NAME);
        }

        return self::mapDispute($this->answered($response));
    }

    /* ------------------------------------------------------------------ settlement records (Module 36) */

    /** The fields of a reconciliation line that are kept as the provider's snapshot — references and amounts only. */
    private const SETTLEMENT_LINE_FIELDS = ['entity_id', 'type', 'debit', 'credit', 'amount', 'currency', 'fee', 'tax', 'on_hold', 'settled', 'created_at', 'settled_at', 'settlement_id', 'payment_id', 'settlement_utr', 'order_id', 'dispute_id'];

    private const SETTLEMENT_FIELDS = ['id', 'entity', 'amount', 'status', 'fees', 'tax', 'utr', 'created_at'];

    public function lines(CarbonInterface $day, int $skip, int $count): array
    {
        $response = $this->answered($this->send(fn () => $this->request()->get('/settlements/recon/combined', [
            'year' => (int) $day->format('Y'), 'month' => (int) $day->format('n'), 'day' => (int) $day->format('j'), 'count' => max(1, min(1000, $count)), 'skip' => max(0, $skip),
        ])));
        $lines = [];
        foreach ((array) ($response['items'] ?? []) as $item) {
            if (! is_array($item) || self::reference($item['entity_id'] ?? null) === null) {
                continue;
            }
            $lines[] = new ProviderSettlementLine(
                (string) $item['entity_id'],
                match (strtolower((string) ($item['type'] ?? ''))) {
                    'payment' => 'PAYMENT', 'refund' => 'REFUND', 'transfer' => 'TRANSFER', 'adjustment' => 'ADJUSTMENT', default => 'OTHER',
                },
                strtoupper(mb_substr((string) ($item['currency'] ?? ''), 0, 3)),
                (int) ($item['amount'] ?? 0), (int) ($item['credit'] ?? 0), (int) ($item['debit'] ?? 0), (int) ($item['fee'] ?? 0), (int) ($item['tax'] ?? 0),
                (bool) ($item['settled'] ?? false), (bool) ($item['on_hold'] ?? false),
                self::reference($item['settlement_id'] ?? null), self::text($item['settlement_utr'] ?? null), self::reference($item['payment_id'] ?? null),
                self::moment($item['created_at'] ?? null), self::moment($item['settled_at'] ?? null),
                self::only($item, self::SETTLEMENT_LINE_FIELDS),
            );
        }

        return $lines;
    }

    public function settlements(CarbonInterface $from, CarbonInterface $to, int $skip, int $count): array
    {
        $response = $this->answered($this->send(fn () => $this->request()->get('/settlements', ['from' => $from->getTimestamp(), 'to' => $to->getTimestamp(), 'count' => max(1, min(100, $count)), 'skip' => max(0, $skip)])));
        $records = [];
        foreach ((array) ($response['items'] ?? []) as $item) {
            if (! is_array($item) || self::reference($item['id'] ?? null) === null) {
                continue;
            }
            $status = strtolower((string) ($item['status'] ?? ''));
            $records[] = new ProviderSettlementRecord(
                (string) $item['id'],
                in_array($status, ['created', 'processed', 'failed'], true) ? strtoupper($status) : 'UNKNOWN',
                mb_substr($status, 0, 24),
                // The settlement entity documents no currency of its own: an account settles in its own currency.
                strtoupper(mb_substr((string) ($item['currency'] ?? ($this->config['settlement_currency'] ?? 'INR')), 0, 3)),
                (int) ($item['amount'] ?? 0), (int) ($item['fees'] ?? 0), (int) ($item['tax'] ?? 0),
                self::text($item['utr'] ?? null), self::moment($item['created_at'] ?? null),
                self::only($item, self::SETTLEMENT_FIELDS),
            );
        }

        return $records;
    }

    private static function moment(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestampUTC($value) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $fields
     * @return array<string, scalar|null>
     */
    private static function only(array $item, array $fields): array
    {
        $kept = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $item) && (is_scalar($item[$field]) || $item[$field] === null)) {
                $kept[$field] = is_string($item[$field]) ? mb_substr($item[$field], 0, 80) : $item[$field];
            }
        }

        return $kept;
    }

    /**
     * Why the provider refused a refund, as one of our own words. The provider's sentence is read here and goes no
     * further (the documented sentences of 2026-10-11; an unknown one is simply REJECTED).
     */
    public static function refundRefusal(string $description): string
    {
        $d = strtolower($description);

        return match (true) {
            str_contains($d, 'duplicate receipt') => ProviderRefundRejected::DUPLICATE_RECEIPT,
            str_contains($d, 'fully refunded') => ProviderRefundRejected::ALREADY_REFUNDED,
            str_contains($d, 'greater than') || str_contains($d, 'more than') || str_contains($d, 'exceed') => ProviderRefundRejected::AMOUNT_EXCEEDS_AVAILABLE,
            str_contains($d, 'balance') => ProviderRefundRejected::INSUFFICIENT_BALANCE,
            str_contains($d, 'dispute') => ProviderRefundRejected::DISPUTE_BLOCK,
            str_contains($d, 'in progress') => ProviderRefundRejected::OPERATION_IN_PROGRESS,
            str_contains($d, 'partial') => ProviderRefundRejected::PARTIAL_NOT_SUPPORTED,
            str_contains($d, 'captured') => ProviderRefundRejected::PAYMENT_NOT_CAPTURED,
            str_contains($d, 'months') || str_contains($d, 'older') => ProviderRefundRejected::PAYMENT_TOO_OLD,
            str_contains($d, 'not supported') || str_contains($d, 'does not support') => ProviderRefundRejected::METHOD_NOT_SUPPORTED,
            str_contains($d, 'not enabled') || str_contains($d, 'disabled') || str_contains($d, 'not allowed') => ProviderRefundRejected::REFUNDS_DISABLED,
            default => ProviderRefundRejected::REJECTED,
        };
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

    /**
     * @param  array<string, mixed>  $r
     */
    private static function mapRefund(array $r): ProviderRefund
    {
        $reference = self::reference($r['id'] ?? null);
        $payment = self::reference($r['payment_id'] ?? null);
        $currency = strtoupper((string) ($r['currency'] ?? ''));
        if ($reference === null || ! str_starts_with($reference, 'rfnd_') || $payment === null || ! is_int($r['amount'] ?? null) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw ProviderException::unavailable(self::NAME, 'a refund answer that cannot be read');
        }
        $status = strtolower((string) ($r['status'] ?? ''));
        $acquirer = is_array($r['acquirer_data'] ?? null) ? $r['acquirer_data'] : [];

        return new ProviderRefund(
            reference: $reference,
            paymentReference: $payment,
            state: match ($status) {
                // "pending": accepted and with the bank. "processed": completed. "failed": the provider could not make it.
                'pending', 'created' => ProviderRefundState::Pending,
                'processed' => ProviderRefundState::Processed,
                'failed' => ProviderRefundState::Failed,
                default => ProviderRefundState::Unknown,
            },
            providerStatus: mb_substr($status, 0, 24),
            amount: new Money($r['amount'], $currency),
            receipt: self::text($r['receipt'] ?? null, 40),
            traceReference: self::text($acquirer['arn'] ?? $acquirer['rrn'] ?? $acquirer['utr'] ?? null),
            createdAt: is_int($r['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($r['created_at']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private static function mapDispute(array $d, ?string $event = null): ProviderDispute
    {
        $reference = self::reference($d['id'] ?? null);
        $payment = self::reference($d['payment_id'] ?? null);
        $currency = strtoupper((string) ($d['currency'] ?? ''));
        if ($reference === null || ! str_starts_with($reference, 'disp_') || $payment === null || ! is_int($d['amount'] ?? null) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw ProviderException::unavailable(self::NAME, 'a dispute answer that cannot be read');
        }
        $word = strtolower((string) ($d['status'] ?? ''));
        $status = match ($word) {
            'open' => $event === 'payment.dispute.action_required' ? DisputeStatus::ActionRequired : DisputeStatus::Open,
            'under_review' => DisputeStatus::UnderReview,
            'won' => DisputeStatus::Won,
            'lost' => DisputeStatus::Lost,
            'closed' => DisputeStatus::Closed,
            default => DisputeStatus::Unknown,
        };

        return new ProviderDispute(
            reference: $reference,
            paymentReference: $payment,
            status: $status,
            providerStatus: mb_substr($word, 0, 24),
            amount: new Money($d['amount'], $currency),
            amountDeductedMinor: is_int($d['amount_deducted'] ?? null) ? max(0, $d['amount_deducted']) : 0,
            phase: self::text($d['phase'] ?? null, 24),
            reasonCode: self::text($d['reason_code'] ?? null),
            respondBy: is_int($d['respond_by'] ?? null) ? CarbonImmutable::createFromTimestampUTC($d['respond_by']) : null,
            createdAt: is_int($d['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($d['created_at']) : null,
        );
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
