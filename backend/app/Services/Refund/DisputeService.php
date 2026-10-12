<?php

namespace App\Services\Refund;

use App\Enums\DisputeStatus;
use App\Events\Refund\RefundEvent;
use App\Exceptions\ProviderException;
use App\Models\Order;
use App\Models\PaymentAttemptEvent;
use App\Models\PaymentDispute;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\Data\ProviderDispute;
use App\Services\Payment\PaymentAttemptStore;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\PaymentStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Disputes / chargebacks — the foundation only (Module 35).
 *
 * A dispute is started by a customer's bank through the payment network. It is NOT a refund and never becomes one
 * here. What this service does is keep the provider's word about it:
 *
 *   a verified provider event names a dispute → the provider is asked for it (the event's own content is used when
 *   the provider cannot be asked) → the dispute row is written or moved forward. A decided dispute (won, lost,
 *   closed) is never re-opened by a late event.
 *
 * Two things follow for refunds, and only these: while a dispute is open no refund of that payment is approved or
 * sent (the same money could go back twice), and what a LOST dispute took lowers what can still be refunded.
 *
 * There is no evidence upload, no accept / contest action and no customer-facing view: those need a business
 * process nobody has defined.
 */
final class DisputeService
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentAttemptStore $attempts,
        private readonly PaymentStateMachine $payments,
        private readonly RefundStore $store,
    ) {}

    /**
     * A stored provider event about a dispute. Returns what became of the event for the webhook log.
     *
     * @return array{status: string, outcome: string, payment_attempt_id: int|null, refund_id: int|null}
     */
    public function fromWebhook(PaymentWebhookEvent $event): array
    {
        $summary = is_array($event->summary['dispute'] ?? null) ? $event->summary['dispute'] : null;
        if ($summary === null) {
            return ['status' => PaymentWebhookEvent::IGNORED, 'outcome' => 'NO_DISPUTE_IN_EVENT', 'payment_attempt_id' => null, 'refund_id' => null];
        }
        $given = ProviderDispute::fromSummary($summary);
        $attempt = $this->attempts->byProviderReferences((string) $event->provider, null, $given->paymentReference);
        if ($attempt === null) {
            // A genuine event about a payment that is not a payment attempt here (another integration of the account).
            return ['status' => PaymentWebhookEvent::IGNORED, 'outcome' => 'NO_MATCHING_PAYMENT', 'payment_attempt_id' => null, 'refund_id' => null];
        }

        // The provider's current record is the authority; the signed event's own content stands in when it cannot be asked.
        $current = $given;
        $authoritative = false;
        $provider = $this->providers->named((string) $event->provider);
        if ($provider !== null) {
            try {
                $looked = $this->providers->call($provider, 'dispute', fn (): ProviderDispute => $provider->dispute($given->reference), safeToRepeat: true, capability: 'refund');
                if ($looked->reference === $given->reference && $looked->paymentReference === $given->paymentReference) {
                    $current = $looked;
                    $authoritative = true;
                }
            } catch (ProviderException) {
                // not reachable: the event's content is used, forward only
            }
        }

        $status = DB::transaction(function () use ($attempt, $event, $current, $authoritative): string {
            // Lock order: the payment attempt, then its refund balance — the mutex of everything that changes what a
            // payment can give back.
            [$locked] = $this->attempts->locked((int) $attempt->getKey());
            $balance = $this->store->balance($locked);
            $row = PaymentDispute::query()->where('provider', (string) $event->provider)->where('provider_dispute_reference', $current->reference)->lockForUpdate()->first();
            $at = $event->provider_created_at ?? $event->received_at ?? now();
            if ($row === null) {
                $order = Order::query()->where('payment_attempt_id', $attempt->getKey())->first();
                $row = (new PaymentDispute)->forceFill([
                    'payment_attempt_id' => $attempt->getKey(),
                    'payment_public_id' => (string) $attempt->public_id,
                    'order_id' => $order?->getKey(),
                    'order_number' => $order?->order_number,
                    'market_id' => $attempt->market_id,
                    'restaurant_name' => $attempt->restaurant_name,
                    'provider' => (string) $event->provider,
                    'provider_dispute_reference' => $current->reference,
                    'provider_payment_reference' => $current->paymentReference,
                    'status' => DisputeStatus::Unknown->value,
                    'amount_minor' => $current->amount->amount,
                    'currency' => $current->amount->currency,
                    'opened_at' => $current->createdAt ?? $at,
                ]);
            } elseif (! $row->status->isOpen() && $current->status->isOpen()) {
                return 'STALE_'.$row->status->value; // decided already: a late "open" does not re-open it
            } elseif (! $authoritative && $row->last_event_at !== null && $at < $row->last_event_at) {
                return 'STALE_'.$row->status->value; // an older event than the one already applied
            }
            $from = $row->exists ? $row->status->value : null;
            $row->forceFill([
                'status' => $current->status->value,
                'provider_status' => mb_substr($current->providerStatus, 0, 24),
                'phase' => $current->phase,
                'reason_code' => $current->reasonCode,
                'amount_minor' => $current->amount->amount,
                'amount_deducted_minor' => $current->amountDeductedMinor,
                'respond_by_at' => $current->respondBy,
                'closed_at' => $current->status->isOpen() ? null : ($row->closed_at ?? $at),
                'last_event_at' => $row->last_event_at !== null && $row->last_event_at > $at ? $row->last_event_at : $at,
                'version' => $row->exists ? (int) $row->version + 1 : 1,
            ])->save();

            // What lost disputes took from this payment is no longer there to refund.
            $taken = (int) PaymentDispute::query()->where('payment_attempt_id', $attempt->getKey())->where('status', DisputeStatus::Lost->value)
                ->selectRaw('coalesce(sum(case when amount_deducted_minor > 0 then amount_deducted_minor else amount_minor end), 0) as taken')->value('taken');
            $balance->forceFill(['charged_back_minor' => min((int) $balance->paid_minor, $taken), 'version' => (int) $balance->version + 1])->save();

            $this->payments->note($locked, PaymentAttemptEvent::SOURCE_WEBHOOK, 'dispute_'.strtolower($current->status->value), ['dispute' => (string) $row->public_id, 'from' => $from, 'event' => (string) $event->public_id]);
            Log::warning('refund.dispute_recorded', ['payment' => (string) $attempt->public_id, 'dispute' => (string) $row->public_id, 'status' => $current->status->value, 'from' => $from, 'amount_minor' => $current->amount->amount, 'currency' => $current->amount->currency]);
            DB::afterCommit(fn () => event(new RefundEvent(RefundEvent::DISPUTE_RECORDED, null, ['status' => $current->status->value, 'provider' => (string) $event->provider])));

            return $current->status->value;
        });

        return ['status' => PaymentWebhookEvent::PROCESSED, 'outcome' => 'DISPUTE_'.$status, 'payment_attempt_id' => (int) $attempt->getKey(), 'refund_id' => null];
    }
}
