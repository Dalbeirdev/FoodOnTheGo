<?php

namespace App\Services\Payment;

use App\Events\Payment\PaymentEvent;
use App\Jobs\Payment\ProcessPaymentWebhookEvent;
use App\Models\PaymentAttemptEvent as Line;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\Data\ProviderWebhook;
use App\Services\Refund\DisputeService;
use App\Services\Refund\RefundReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Provider webhooks (Module 30).
 *
 * accept()   the request path — fast and careful: the signature is verified over the raw body before a single
 *            field is read; the event is stored once, however often the provider delivers it; and the provider gets
 *            its answer. No customer session is involved: the signature is the only credential.
 * process()  the work, on the queue: find the attempt the event is about and let reconciliation judge it. An event
 *            never sets a status itself, so the order in which events arrive does not matter and a late one cannot
 *            take a settled payment back.
 *
 * Nothing of a webhook is trusted before its signature is, and the raw payload is never stored — only its hash and
 * the fields needed to reconcile and to support a customer.
 *
 * Module 35: refund and dispute events come through this same door — the same signature check over the raw body,
 * the same once-only storage, the same queue. A refund event is handed to the refund reconciliation, a dispute
 * event to the dispute foundation; neither one sets a state by itself.
 */
final class PaymentWebhookService
{
    public const ACCEPTED = 'ACCEPTED';

    public const DUPLICATE = 'DUPLICATE';

    public const REJECTED = 'REJECTED';

    public const INVALID = 'INVALID';

    public const UNKNOWN_PROVIDER = 'UNKNOWN_PROVIDER';

    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentAttemptStore $attempts,
        private readonly PaymentStateMachine $machine,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly RefundReconciliationService $refunds,
        private readonly DisputeService $disputes,
    ) {}

    /**
     * @param  array<string, string|null>  $headers  lower-case names
     * @return self::ACCEPTED|self::DUPLICATE|self::REJECTED|self::INVALID|self::UNKNOWN_PROVIDER
     */
    public function accept(string $providerName, string $rawBody, array $headers): string
    {
        $provider = $this->providers->named($providerName);
        if ($provider === null) {
            return self::UNKNOWN_PROVIDER;
        }
        if (! $provider->verifyWebhookSignature($rawBody, $headers[$provider->webhookSignatureHeader()] ?? null)) {
            event(new PaymentEvent(PaymentEvent::WEBHOOK_REJECTED, null, ['provider' => $provider->name()]));
            Log::warning('payment.webhook_rejected', ['provider' => $provider->name(), 'signature_valid' => false, 'bytes' => strlen($rawBody)]);

            return self::REJECTED;
        }
        try {
            $event = $provider->parseWebhook($rawBody, $headers);
        } catch (Throwable $e) {
            Log::warning('payment.webhook_unreadable', ['provider' => $provider->name(), 'signature_valid' => true, 'reason' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'unreadable']);

            return self::INVALID;
        }

        $hash = hash('sha256', $rawBody);
        $reference = $event->eventReference ?? 'sha256:'.$hash;
        $stored = $this->store($provider->name(), $reference, $hash, $event);
        if ($stored === null) {
            // Delivered again. Counted, and handed to a worker once more only if the first handling did not finish.
            $existing = PaymentWebhookEvent::query()->where('provider', $provider->name())->where('provider_event_reference', $reference)->first();
            $existing?->forceFill(['deliveries' => (int) $existing->deliveries + 1])->save();
            if ($existing !== null && $existing->processing_status === PaymentWebhookEvent::FAILED) {
                ProcessPaymentWebhookEvent::dispatch((int) $existing->getKey());
            }
            event(new PaymentEvent(PaymentEvent::WEBHOOK_DUPLICATE, null, ['provider' => $provider->name(), 'type' => $event->type]));
            Log::info('payment.webhook_duplicate', ['provider' => $provider->name(), 'event' => $reference, 'type' => $event->type]);

            return self::DUPLICATE;
        }
        event(new PaymentEvent(PaymentEvent::WEBHOOK_RECEIVED, null, ['provider' => $provider->name(), 'type' => $event->type, 'handled' => $event->handled()]));
        Log::info('payment.webhook_received', ['provider' => $provider->name(), 'event' => $reference, 'type' => $event->type, 'signature_valid' => true]);
        if ($event->handled()) {
            ProcessPaymentWebhookEvent::dispatch((int) $stored->getKey());
        }

        return self::ACCEPTED;
    }

    /**
     * Handles one stored event. Safe to run again: an event that is done stays done.
     *
     * @throws Throwable so that the queue tries again; the event is marked FAILED meanwhile
     */
    public function process(int $eventId): ?PaymentWebhookEvent
    {
        $event = PaymentWebhookEvent::query()->find($eventId);
        if ($event === null || in_array($event->processing_status, [PaymentWebhookEvent::PROCESSED, PaymentWebhookEvent::IGNORED], true)) {
            return $event;
        }
        $event->forceFill(['processing_attempts' => (int) $event->processing_attempts + 1])->save();
        try {
            $kind = (string) $event->normalized_type;
            if (str_starts_with($kind, 'REFUND_') || str_starts_with($kind, 'DISPUTE_')) {
                $done = str_starts_with($kind, 'REFUND_') ? $this->refunds->fromWebhook($event) : $this->disputes->fromWebhook($event);
                if ($done['refund_id'] !== null) {
                    $event->forceFill(['refund_id' => $done['refund_id']]);
                }

                return $this->finish($event, $done['status'], $done['outcome'], $done['payment_attempt_id']);
            }
            $attempt = $this->attempts->byProviderReferences((string) $event->provider, $event->provider_order_reference, $event->provider_payment_reference);
            if ($attempt === null) {
                // A genuine event about something that is not a payment attempt here (another integration of the same account).
                return $this->finish($event, PaymentWebhookEvent::IGNORED, 'NO_MATCHING_PAYMENT');
            }
            $provider = $this->providers->named((string) $event->provider);
            $given = [];
            if ($provider !== null && is_array($event->summary['payment'] ?? null)) {
                $given = [PaymentWebhookSummary::payment($event->summary['payment'])];
            }
            DB::transaction(function () use ($attempt, $event): void {
                [$locked] = $this->attempts->locked((int) $attempt->getKey());
                $this->machine->note($locked, Line::SOURCE_WEBHOOK, 'webhook_'.strtolower((string) ($event->normalized_type ?? 'event')), [
                    'event' => (string) $event->public_id, 'payment' => $event->provider_payment_reference, 'state' => $event->summary['payment']['state'] ?? null,
                ]);
            });
            $after = $this->reconciliation->reconcile($attempt, Line::SOURCE_WEBHOOK, $given);

            return $this->finish($event, PaymentWebhookEvent::PROCESSED, $after->status->value, (int) $attempt->getKey());
        } catch (Throwable $e) {
            $event->forceFill(['processing_status' => PaymentWebhookEvent::FAILED, 'failure_reason' => mb_substr(class_basename($e), 0, 160)])->save();
            event(new PaymentEvent(PaymentEvent::WEBHOOK_FAILED, null, ['provider' => (string) $event->provider]));
            throw $e;
        }
    }

    /**
     * Events that were accepted and that no worker finished: handed to a worker again. Nothing accepted is lost.
     */
    public function sweep(?CarbonImmutable $at = null): int
    {
        $before = ($at ?? CarbonImmutable::now())->subSeconds(max(1, (int) config('payments.webhook.sweep_after_seconds')));
        $ids = PaymentWebhookEvent::query()->whereIn('processing_status', [PaymentWebhookEvent::RECEIVED, PaymentWebhookEvent::FAILED])
            ->whereNotNull('normalized_type')->where('received_at', '<=', $before)->where('processing_attempts', '<', 20)->orderBy('id')->limit(200)->pluck('id');
        foreach ($ids as $id) {
            ProcessPaymentWebhookEvent::dispatch((int) $id);
        }

        return $ids->count();
    }

    private function store(string $provider, string $reference, string $hash, ProviderWebhook $event): ?PaymentWebhookEvent
    {
        $row = new PaymentWebhookEvent;
        try {
            // A savepoint of its own: a duplicate must not break a transaction this runs inside.
            DB::transaction(fn () => $row->forceFill([
                'provider' => $provider,
                'provider_event_reference' => mb_substr($reference, 0, 120),
                'event_type' => $event->type,
                'normalized_type' => $event->normalizedType,
                'payload_hash' => $hash,
                'provider_order_reference' => $event->orderReference,
                'provider_payment_reference' => $event->payment?->reference ?? $event->refund?->paymentReference ?? $event->dispute?->paymentReference,
                'provider_refund_reference' => $event->refund?->reference,
                'provider_dispute_reference' => $event->dispute?->reference,
                // Safe fields only, per kind: never the payload, never anything about the customer.
                'summary' => array_filter([
                    'payment' => $event->payment === null ? null : PaymentWebhookSummary::of($event->payment),
                    'refund' => $event->refund?->summary(),
                    'dispute' => $event->dispute?->summary(),
                ]) ?: null,
                'processing_status' => $event->handled() ? PaymentWebhookEvent::RECEIVED : PaymentWebhookEvent::IGNORED,
                'outcome' => $event->handled() ? null : 'EVENT_NOT_HANDLED',
                'provider_created_at' => $event->createdAt,
                'received_at' => now(),
                'processed_at' => $event->handled() ? null : now(),
            ])->save());
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $row;
    }

    private function finish(PaymentWebhookEvent $event, string $status, string $outcome, ?int $attemptId = null): PaymentWebhookEvent
    {
        $now = now();
        $event->forceFill(['processing_status' => $status, 'outcome' => mb_substr($outcome, 0, 48), 'failure_reason' => null, 'payment_attempt_id' => $attemptId ?? $event->payment_attempt_id, 'processed_at' => $now])->save();
        event(new PaymentEvent($status === PaymentWebhookEvent::IGNORED ? PaymentEvent::WEBHOOK_IGNORED : PaymentEvent::WEBHOOK_PROCESSED, null, [
            'provider' => (string) $event->provider, 'processing_ms' => $event->received_at === null ? null : max(0, (int) $event->received_at->diffInMilliseconds($now)),
        ]));

        return $event;
    }
}
