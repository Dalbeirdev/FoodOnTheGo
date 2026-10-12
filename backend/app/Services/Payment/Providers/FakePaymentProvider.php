<?php

namespace App\Services\Payment\Providers;

use App\Contracts\Payment\PaymentProvider;
use App\Enums\DisputeStatus;
use App\Enums\ProviderFailure;
use App\Enums\ProviderPaymentState;
use App\Enums\ProviderRefundState;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderRefundRejected;
use App\Services\Payment\Data\ProviderDispute;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\Data\ProviderRefund;
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

    private const OPERATIONS = ['create_session', 'find_session', 'payments', 'payment', 'create_refund', 'refund', 'refunds', 'dispute'];

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
        $refund = is_array($event['refund'] ?? null) ? ProviderRefund::fromSummary($event['refund']) : null;
        $dispute = is_array($event['dispute'] ?? null) ? ProviderDispute::fromSummary($event['dispute']) : null;
        $eventId = trim((string) ($headers[self::EVENT_ID_HEADER] ?? ''));

        return new ProviderWebhook(
            eventReference: $eventId !== '' ? mb_substr($eventId, 0, 100) : null,
            type: mb_substr($event['type'], 0, 64),
            normalizedType: self::EVENTS[$event['type']] ?? null,
            orderReference: is_string($event['order'] ?? null) ? $event['order'] : $payment?->orderReference,
            payment: $payment,
            createdAt: is_int($event['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($event['created_at']) : null,
            refund: $refund,
            dispute: $dispute,
        );
    }

    public function configuredMethods(): ?array
    {
        return ['UPI', 'CARD', 'NET_BANKING', 'WALLET'];
    }

    /* ------------------------------------------------------------------ refunds and disputes (Module 35) */

    /**
     * Behaves as the documented provider does: the idempotency key returns the refund it already made, a second
     * receipt is refused, only a captured payment can be refunded, never more than what is left of it, and not
     * while a dispute is open. What the refund becomes (processed at once, pending, failed) is scripted; the
     * default is "pending", so a local run shows the wait for the provider's confirmation.
     */
    public function createRefund(ProviderRefundRequest $request): ProviderRefund
    {
        $failure = $this->scripted('create_refund');
        $lost = $failure === ProviderFailure::Timeout && $this->flag('create_refund_lost');
        if ($failure !== null && ! $lost) {
            throw new ProviderException($failure, self::NAME);
        }
        if (is_string($reason = Cache::pull(self::PREFIX.'refund_reject'))) {
            throw new ProviderRefundRejected(self::NAME, $reason);
        }
        $known = Cache::get(self::PREFIX.'refund_key:'.$request->key());
        if (is_string($known) && ($existing = $this->readRefund($known)) !== null) {
            if (! $existing->amount->equals($request->amount) || $existing->paymentReference !== $request->paymentReference) {
                throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::IDEMPOTENCY_CONFLICT);
            }

            return $existing; // the same key and the same request: the same refund
        }
        if (is_string(Cache::get(self::PREFIX.'refund_receipt:'.$request->receipt))) {
            throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::DUPLICATE_RECEIPT);
        }
        $payment = $this->read($request->paymentReference) ?? throw ProviderException::notFound(self::NAME);
        if (! $payment->captured()) {
            throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::PAYMENT_NOT_CAPTURED);
        }
        if ($payment->amount->currency !== $request->amount->currency || $request->amount->amount < 1) {
            throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::REJECTED);
        }
        foreach ($this->disputesOf($request->paymentReference) as $dispute) {
            if ($dispute->status->isOpen()) {
                throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::DISPUTE_BLOCK);
            }
        }
        $taken = array_sum(array_map(fn (ProviderRefund $r): int => $r->state === ProviderRefundState::Failed ? 0 : $r->amount->amount, $this->refundsOfPayment($request->paymentReference, false)));
        if ($taken >= $payment->amount->amount) {
            throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::ALREADY_REFUNDED);
        }
        if ($taken + $request->amount->amount > $payment->amount->amount) {
            throw new ProviderRefundRejected(self::NAME, ProviderRefundRejected::AMOUNT_EXCEEDS_AVAILABLE);
        }
        $outcome = Cache::get(self::PREFIX.'refund_outcome');
        $state = match (is_string($outcome) ? $outcome : (string) config('payments.fake.refund_outcome', 'pending')) {
            'processed' => ProviderRefundState::Processed,
            'failed' => ProviderRefundState::Failed,
            default => ProviderRefundState::Pending,
        };
        $record = [
            'reference' => 'fakerfnd_'.Str::lower(Str::random(14)),
            'payment' => $request->paymentReference,
            'state' => $state->value,
            'provider_status' => strtolower($state->value),
            'amount_minor' => $request->amount->amount,
            'currency' => $request->amount->currency,
            'receipt' => $request->receipt,
            'trace' => $state === ProviderRefundState::Processed ? 'FAKEARN'.random_int(100000, 999999) : null,
            'created_at' => now()->utc()->getTimestamp(),
        ];
        Cache::put(self::PREFIX.'refund:'.$record['reference'], $record, self::TTL);
        Cache::put(self::PREFIX.'refund_key:'.$request->key(), $record['reference'], self::TTL);
        Cache::put(self::PREFIX.'refund_receipt:'.$request->receipt, $record['reference'], self::TTL);
        $list = Cache::get(self::PREFIX.'refunds_of:'.$request->paymentReference);
        Cache::put(self::PREFIX.'refunds_of:'.$request->paymentReference, [...(is_array($list) ? $list : []), $record['reference']], self::TTL);
        if ($lost) {
            throw ProviderException::timeout(self::NAME); // created — and the caller is told nothing
        }

        return ProviderRefund::fromSummary($record);
    }

    public function refund(string $paymentReference, string $refundReference): ProviderRefund
    {
        $this->failIfScripted('refund');
        $refund = $this->readRefund($refundReference);

        return $refund !== null && $refund->paymentReference === $paymentReference ? $refund : throw ProviderException::notFound(self::NAME);
    }

    public function refundsOfPayment(string $paymentReference, bool $scriptable = true): array
    {
        if ($scriptable) {
            $this->failIfScripted('refunds');
        }
        $list = Cache::get(self::PREFIX.'refunds_of:'.$paymentReference);

        return array_values(array_filter(array_map(fn (string $ref): ?ProviderRefund => $this->readRefund($ref), is_array($list) ? $list : [])));
    }

    public function dispute(string $disputeReference): ProviderDispute
    {
        $this->failIfScripted('dispute');
        $record = Cache::get(self::PREFIX.'dispute:'.$disputeReference);

        return is_array($record) ? ProviderDispute::fromSummary($record) : throw ProviderException::notFound(self::NAME);
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
     * What the next refunds become when they are created: 'processed' (at once), 'pending' (the provider confirms
     * later — settleRefund) or 'failed'. Null returns to the configured default.
     *
     * @param  'processed'|'pending'|'failed'|null  $outcome
     */
    public function refundsBecome(?string $outcome): void
    {
        $outcome === null ? Cache::forget(self::PREFIX.'refund_outcome') : Cache::put(self::PREFIX.'refund_outcome', $outcome, self::TTL);
    }

    /** The next refund request is refused with this reason (a ProviderRefundRejected constant). */
    public function rejectNextRefund(string $reason): void
    {
        Cache::put(self::PREFIX.'refund_reject', $reason, self::TTL);
    }

    /**
     * Later, the provider (the bank) finishes a pending refund — or fails it.
     *
     * @param  'processed'|'failed'|'pending'  $outcome
     */
    public function settleRefund(string $refundReference, string $outcome): ProviderRefund
    {
        $record = Cache::get(self::PREFIX.'refund:'.$refundReference);
        if (! is_array($record)) {
            throw ProviderException::notFound(self::NAME);
        }
        $state = match ($outcome) {
            'processed' => ProviderRefundState::Processed,
            'failed' => ProviderRefundState::Failed,
            'pending' => ProviderRefundState::Pending,
            default => throw new InvalidArgumentException("unknown outcome {$outcome}"),
        };
        $record['state'] = $state->value;
        $record['provider_status'] = strtolower($state->value);
        $record['trace'] = $state === ProviderRefundState::Processed ? ($record['trace'] ?? 'FAKEARN'.random_int(100000, 999999)) : null;
        Cache::put(self::PREFIX.'refund:'.$refundReference, $record, self::TTL);

        return ProviderRefund::fromSummary($record);
    }

    /** The provider's refund for one of our receipts, when it made one (tests and the development simulator). */
    public function refundByReceipt(string $receipt): ?ProviderRefund
    {
        $reference = Cache::get(self::PREFIX.'refund_receipt:'.$receipt);

        return is_string($reference) ? $this->readRefund($reference) : null;
    }

    /**
     * A refund webhook as the provider would send it (refund.created / refund.processed / refund.failed).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function refundWebhook(string $type, ProviderRefund $refund, ?string $eventId = null): array
    {
        $payment = $this->read($refund->paymentReference);

        return $this->signed([
            'object' => 'event',
            'type' => $type,
            'order' => $payment?->orderReference,
            'payment' => $payment === null ? null : self::toRecord($payment),
            'refund' => $refund->summary(),
            'created_at' => now()->utc()->getTimestamp(),
        ], $eventId);
    }

    /**
     * A customer disputes a payment with their bank: the provider opens a dispute.
     */
    public function openDispute(string $paymentReference, ?int $amountMinor = null, string $reasonCode = 'goods_not_received', string $phase = 'chargeback'): ProviderDispute
    {
        $payment = $this->read($paymentReference) ?? throw ProviderException::notFound(self::NAME);
        $record = [
            'reference' => 'fakedisp_'.Str::lower(Str::random(14)),
            'payment' => $paymentReference,
            'status' => DisputeStatus::Open->value,
            'provider_status' => 'open',
            'amount_minor' => $amountMinor ?? $payment->amount->amount,
            'currency' => $payment->amount->currency,
            'amount_deducted_minor' => 0,
            'phase' => $phase,
            'reason_code' => $reasonCode,
            'respond_by' => now()->utc()->addDays(7)->getTimestamp(),
            'created_at' => now()->utc()->getTimestamp(),
        ];
        Cache::put(self::PREFIX.'dispute:'.$record['reference'], $record, self::TTL);
        $list = Cache::get(self::PREFIX.'disputes_of:'.$paymentReference);
        Cache::put(self::PREFIX.'disputes_of:'.$paymentReference, [...(is_array($list) ? $list : []), $record['reference']], self::TTL);

        return ProviderDispute::fromSummary($record);
    }

    /**
     * The dispute moves on at the provider. A lost dispute takes its amount from the merchant.
     */
    public function settleDispute(string $disputeReference, DisputeStatus $status): ProviderDispute
    {
        $record = Cache::get(self::PREFIX.'dispute:'.$disputeReference);
        if (! is_array($record)) {
            throw ProviderException::notFound(self::NAME);
        }
        $record['status'] = $status->value;
        $record['provider_status'] = strtolower($status->value);
        $record['amount_deducted_minor'] = $status === DisputeStatus::Lost ? (int) $record['amount_minor'] : 0;
        Cache::put(self::PREFIX.'dispute:'.$disputeReference, $record, self::TTL);

        return ProviderDispute::fromSummary($record);
    }

    /**
     * A dispute webhook as the provider would send it (payment.dispute.created / under_review / won / lost / closed).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function disputeWebhook(string $type, ProviderDispute $dispute, ?string $eventId = null, ?int $createdAt = null): array
    {
        $payment = $this->read($dispute->paymentReference);

        return $this->signed([
            'object' => 'event',
            'type' => $type,
            'order' => $payment?->orderReference,
            'payment' => $payment === null ? null : self::toRecord($payment),
            'dispute' => $dispute->summary(),
            'created_at' => $createdAt ?? now()->utc()->getTimestamp(),
        ], $eventId);
    }

    /**
     * The next calls of an operation fail the way a provider can: create_session, find_session, payments, payment,
     * create_refund, refund, refunds, dispute. With $lostAnswer a create_session / create_refund times out AFTER the
     * order / the refund was created.
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
        foreach (self::OPERATIONS as $operation) {
            Cache::forget(self::PREFIX.'fail:'.$operation);
            Cache::forget(self::PREFIX.'flag:'.$operation.'_lost');
        }
        Cache::forget(self::PREFIX.'refund_reject');
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: array<string, string>}
     */
    private function signed(array $event, ?string $eventId): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [$body, [
            self::SIGNATURE_HEADER => hash_hmac('sha256', $body, self::WEBHOOK_KEY),
            self::EVENT_ID_HEADER => $eventId ?? 'fakeevt_'.Str::lower(Str::random(16)),
        ]];
    }

    private function readRefund(string $refundReference): ?ProviderRefund
    {
        $record = Cache::get(self::PREFIX.'refund:'.$refundReference);

        return is_array($record) ? ProviderRefund::fromSummary($record) : null;
    }

    /**
     * @return list<ProviderDispute>
     */
    private function disputesOf(string $paymentReference): array
    {
        $list = Cache::get(self::PREFIX.'disputes_of:'.$paymentReference);
        $out = [];
        foreach (is_array($list) ? $list : [] as $reference) {
            $record = Cache::get(self::PREFIX.'dispute:'.$reference);
            if (is_array($record)) {
                $out[] = ProviderDispute::fromSummary($record);
            }
        }

        return $out;
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
