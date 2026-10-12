<?php

namespace App\Services\Refund;

use App\Enums\ProviderFailure;
use App\Enums\ProviderRefundState;
use App\Enums\RefundStatus;
use App\Events\Refund\RefundEvent;
use App\Exceptions\ProviderException;
use App\Models\PaymentAttemptEvent;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Models\RefundEvent as Line;
use App\Services\Payment\Data\ProviderRefund;
use App\Services\Payment\PaymentAttemptStore;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\PaymentStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides what a refund is, from the provider's own record (Module 35).
 *
 * Whoever notices something — a webhook, the scheduled sweep, an administrator — ends up here, and here the same
 * question is asked every time: what does THE PROVIDER hold for this refund? The answer is fetched (so the order in
 * which signals arrive does not matter) and adopted through RefundStateMachine::adopt, which never moves a refund
 * backwards.
 *
 *   the provider's refund reference is known   → that refund is read
 *   only our receipt is known (no answer came) → the payment's refunds are listed and ours is found by its receipt
 *       found                                  → adopted: PROCESSING, REFUNDED or FAILED
 *       not found, and the submission is over  → the SAME request is sent again (same receipt, same key), a limited
 *                                                number of times; then a person looks (REVIEW_REQUIRED)
 *   the provider cannot be asked               → nothing is assumed. A verified webhook's own content may still move
 *                                                the refund forward; an outage never makes a refund "failed".
 *
 * The sweep looks with growing waits and a limit; a refund it cannot settle in all its tries needs a person.
 */
final class RefundReconciliationService
{
    public function __construct(
        private readonly RefundStore $store,
        private readonly RefundStateMachine $machine,
        private readonly RefundSubmissionService $submission,
        private readonly PaymentProviderManager $providers,
        private readonly PaymentAttemptStore $attempts,
        private readonly PaymentStateMachine $payments,
        private readonly RefundMetrics $metrics,
    ) {}

    /**
     * @param  ProviderRefund|null  $given  the refund a webhook named, when its signature was verified
     * @param  bool  $resubmit  an administrator asked for an unanswered submission to be sent again if the provider holds nothing
     */
    public function reconcile(Refund $refund, string $source, ?ProviderRefund $given = null, ?int $adminId = null, bool $resubmit = false): Refund
    {
        $id = (int) $refund->getKey();
        // One look at a time per refund: a second caller takes what the first one finds.
        $lock = Cache::lock('refunds:reconcile:'.$id, 30);
        if (! $lock->get()) {
            return $refund->refresh();
        }
        $again = false;
        try {
            $refund->refresh();
            $open = in_array($refund->status, [RefundStatus::Submitting, RefundStatus::SubmissionUnknown, RefundStatus::Processing], true)
                || (in_array($refund->status, [RefundStatus::ReviewRequired, RefundStatus::Failed], true) && $refund->provider_receipt !== null);
            if (! $open) {
                if ($given !== null && $refund->status === RefundStatus::Refunded && $given->state === ProviderRefundState::Failed) {
                    // A verified event says a refund we hold as completed failed after all. It is never undone here —
                    // the state machine notes it for a person.
                    DB::transaction(fn (): string => $this->machine->adopt($this->store->locked($id), $given, $source, $adminId));
                }

                return $refund; // nothing the provider could know about, or final
            }
            // A submission that may still be on its way is not second-guessed: its own answer settles it.
            $inFlight = $refund->status === RefundStatus::Submitting && ! $this->submissionOver($refund);
            $evidence = $this->gather($refund);

            $again = DB::transaction(function () use ($id, $evidence, $given, $source, $adminId, $inFlight, $resubmit): bool {
                $locked = $this->store->locked($id);

                return $this->apply($locked, $evidence, $given, $source, $adminId, $inFlight, $resubmit, CarbonImmutable::now());
            });
        } finally {
            $lock->release();
        }
        if ($again) {
            // The provider holds nothing for our receipt: the same request, with the same key, goes again.
            return $this->submission->submit($id, $source, $adminId, resubmitUnknown: true);
        }

        return $refund->refresh();
    }

    /**
     * A stored provider event about a refund. The event never sets a state itself: it points at the refund and the
     * provider is asked. Returns what became of the event for the webhook log.
     *
     * @return array{status: string, outcome: string, payment_attempt_id: int|null, refund_id: int|null}
     */
    public function fromWebhook(PaymentWebhookEvent $event): array
    {
        $summary = is_array($event->summary['refund'] ?? null) ? $event->summary['refund'] : null;
        $given = $summary === null ? null : ProviderRefund::fromSummary($summary);
        $refund = $this->store->byProviderReferences((string) $event->provider, $event->provider_refund_reference, $given?->receipt);
        if ($refund === null) {
            // A genuine refund event for a refund FoodOnTheGo did not make (issued in the provider's own dashboard, or by
            // another integration of the account). Nothing is recorded as refunded here: a person is told.
            $attempt = $this->attempts->byProviderReferences((string) $event->provider, null, $event->provider_payment_reference);
            if ($attempt !== null) {
                DB::transaction(function () use ($attempt, $event): void {
                    [$locked] = $this->attempts->locked((int) $attempt->getKey());
                    $this->payments->note($locked, PaymentAttemptEvent::SOURCE_WEBHOOK, 'external_refund_seen', ['event' => (string) $event->public_id, 'refund' => $event->provider_refund_reference]);
                });
                Log::warning('refund.external_refund_seen', ['payment' => (string) $attempt->public_id, 'provider' => (string) $event->provider, 'provider_refund' => $event->provider_refund_reference]);
                event(new RefundEvent(RefundEvent::EXTERNAL_REFUND_SEEN, null, ['provider' => (string) $event->provider]));

                return ['status' => PaymentWebhookEvent::IGNORED, 'outcome' => 'EXTERNAL_REFUND', 'payment_attempt_id' => (int) $attempt->getKey(), 'refund_id' => null];
            }

            return ['status' => PaymentWebhookEvent::IGNORED, 'outcome' => 'NO_MATCHING_REFUND', 'payment_attempt_id' => null, 'refund_id' => null];
        }
        DB::transaction(function () use ($refund, $event): void {
            $locked = $this->store->locked((int) $refund->getKey());
            $this->machine->note($locked->refund, Line::SOURCE_WEBHOOK, 'webhook_'.strtolower((string) ($event->normalized_type ?? 'event')), [
                'event' => (string) $event->public_id, 'refund' => $event->provider_refund_reference, 'state' => $event->summary['refund']['state'] ?? null,
            ]);
        });
        $after = $this->reconcile($refund, Line::SOURCE_WEBHOOK, $given);

        return ['status' => PaymentWebhookEvent::PROCESSED, 'outcome' => $after->status->value, 'payment_attempt_id' => (int) $after->payment_attempt_id, 'refund_id' => (int) $after->getKey()];
    }

    /**
     * The scheduled look: refunds the provider may hold and that are due.
     *
     * @return array{looked: int, failed: int}
     */
    public function sweep(?int $limit = null, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $stale = $at->subSeconds((int) config('services.payment.timeout') + (int) config('refunds.submission.settle_margin_seconds') + 60);
        $looked = $failed = 0;
        Refund::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('status', [RefundStatus::SubmissionUnknown->value, RefundStatus::Processing->value])->where(fn ($d) => $d->whereNull('next_action_at')->orWhere('next_action_at', '<=', $at)))
                ->orWhere(fn ($w) => $w->where('status', RefundStatus::Submitting->value)->where('submitted_at', '<=', $stale)))
            ->orderBy('next_action_at')->orderBy('id')->limit(max(1, $limit ?? (int) config('refunds.reconcile.batch')))->get()
            ->each(function (Refund $refund) use (&$looked, &$failed): void {
                try {
                    $this->reconcile($refund, Line::SOURCE_JOB);
                    $looked++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('refund.reconcile_failed', ['refund' => (string) $refund->public_id, 'error' => $e::class]);
                }
            });

        return ['looked' => $looked, 'failed' => $failed];
    }

    /* ------------------------------------------------------------------ evidence */

    /**
     * Asks the provider. Outside any transaction: no row is locked while the network is waited for.
     *
     * @return array{kind: 'found', refund: ProviderRefund, operation: string, ms: int}|array{kind: 'none'|'missing'|'nothing', operation: string, ms: int}|array{kind: 'error', failure: string, operation: string, ms: int}
     */
    private function gather(Refund $refund): array
    {
        $byReference = $refund->provider_refund_reference !== null;
        $operation = $byReference ? RefundAttempt::OP_LOOKUP : RefundAttempt::OP_LIST;
        if ($refund->provider_payment_reference === null || ($refund->provider_receipt === null && ! $byReference)) {
            return ['kind' => 'nothing', 'operation' => $operation, 'ms' => 0];
        }
        $provider = $this->providers->named((string) $refund->provider);
        if ($provider === null) {
            return ['kind' => 'error', 'failure' => 'PROVIDER_NOT_CONFIGURED', 'operation' => $operation, 'ms' => 0];
        }
        $started = hrtime(true);
        $ms = fn (): int => (int) round((hrtime(true) - $started) / 1_000_000);
        try {
            if ($byReference) {
                $found = $this->providers->call($provider, 'lookup', fn (): ProviderRefund => $provider->refund((string) $refund->provider_payment_reference, (string) $refund->provider_refund_reference), safeToRepeat: true, capability: 'refund');

                return ['kind' => 'found', 'refund' => $found, 'operation' => $operation, 'ms' => $ms()];
            }
            $list = $this->providers->call($provider, 'list', fn (): array => $provider->refundsOfPayment((string) $refund->provider_payment_reference), safeToRepeat: true, capability: 'refund');
            foreach ($list as $candidate) {
                if ($candidate->receipt !== null && $candidate->receipt === $refund->provider_receipt) {
                    return ['kind' => 'found', 'refund' => $candidate, 'operation' => $operation, 'ms' => $ms()];
                }
            }

            return ['kind' => 'none', 'operation' => $operation, 'ms' => $ms()];
        } catch (ProviderException $e) {
            if ($e->failure === ProviderFailure::NotFound) {
                return ['kind' => $byReference ? 'missing' : 'none', 'operation' => $operation, 'ms' => $ms()];
            }

            return ['kind' => 'error', 'failure' => $e->failure->value, 'operation' => $operation, 'ms' => $ms()];
        }
    }

    /* ------------------------------------------------------------------ judgement */

    /**
     * @param  array<string, mixed>  $evidence
     * @return bool whether the same request has to be sent again (the provider holds nothing for our receipt)
     */
    private function apply(RefundLock $lock, array $evidence, ?ProviderRefund $given, string $source, ?int $adminId, bool $inFlight, bool $resubmit, CarbonImmutable $at): bool
    {
        $refund = $lock->refund;
        if ($refund->status->isTerminal()) {
            return false;
        }
        $sweep = $source === Line::SOURCE_JOB;
        $refund->forceFill(['last_reconciled_at' => $at, 'reconcile_runs' => (int) $refund->reconcile_runs + ($sweep ? 1 : 0)])->save();
        $again = false;
        $outcome = RefundAttempt::UNAVAILABLE;
        $seen = null;

        if ($evidence['kind'] === 'found') {
            $seen = $evidence['refund'];
            $outcome = $this->machine->adopt($lock, $seen, $source, $adminId);
        } elseif ($evidence['kind'] === 'missing') {
            // We hold a provider reference and the provider does not know it.
            $outcome = RefundAttempt::NOT_FOUND;
            if ($refund->status->withProvider()) {
                $this->machine->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'PROVIDER_REFUND_MISSING'], [], $adminId);
            }
        } elseif ($evidence['kind'] === 'none') {
            $outcome = RefundAttempt::NOT_FOUND;
            if ($given !== null) {
                // The list did not show it (yet) and a verified event names it: the event is the provider's word too.
                $seen = $given;
                $outcome = $this->machine->adopt($lock, $given, $source, $adminId);
            } elseif ($inFlight) {
                // still being sent: its own answer settles it
            } elseif ($refund->status === RefundStatus::Submitting) {
                $this->machine->move($lock, RefundStatus::SubmissionUnknown, $source, ['next_action_at' => $at], ['reason' => 'ABANDONED_SUBMISSION'], $adminId);
                $again = $this->mayResubmit($lock, $source, $adminId, $resubmit);
            } elseif ($refund->status === RefundStatus::SubmissionUnknown || ($resubmit && $refund->status === RefundStatus::ReviewRequired && $refund->provider_refund_reference === null)) {
                $again = $this->mayResubmit($lock, $source, $adminId, $resubmit);
            }
        } elseif ($evidence['kind'] === 'error') {
            // The provider could not be asked. Nothing is concluded from that — least of all "failed".
            $this->machine->note($refund, $source, 'provider_unreachable', ['failure' => $evidence['failure']], $adminId);
            if ($given !== null) {
                $seen = $given;
                $outcome = $this->machine->adopt($lock, $given, $source, $adminId);
            }
        }

        if ($evidence['kind'] !== 'nothing') {
            (new RefundAttempt)->forceFill([
                'refund_id' => $refund->getKey(),
                'generation' => (int) $refund->generation,
                'operation' => $evidence['operation'],
                'idempotency_key' => null,
                'source' => $source,
                'admin_user_id' => $adminId,
                'outcome' => $outcome,
                'provider_status' => $seen === null ? null : mb_substr($seen->providerStatus, 0, 24),
                'provider_refund_reference' => $outcome === RefundStateMachine::OUTCOME_MISMATCH ? null : $seen?->reference,
                'failure_code' => $evidence['kind'] === 'error' ? mb_substr((string) $evidence['failure'], 0, 48) : null,
                'latency_ms' => (int) $evidence['ms'],
                'request_id' => ($id = Context::get('request_id')) === null ? null : mb_substr((string) $id, 0, 64),
                'started_at' => $at,
                'finished_at' => $at,
            ])->save();
            if ($evidence['ms'] > 0) {
                $this->metrics->record('provider_calls');
                $this->metrics->record('provider_ms_total', (int) $evidence['ms']);
            }
        }
        if (! $again) {
            $this->schedule($lock, $source, $adminId, $at);
        }
        DB::afterCommit(fn () => event(new RefundEvent(RefundEvent::RECONCILED, $refund, ['source' => $source, 'evidence' => (string) $evidence['kind']])));

        return $again;
    }

    /**
     * The provider holds nothing for our receipt. The same request may go again a limited number of times — or once
     * more when an administrator asks; after that a person decides.
     */
    private function mayResubmit(RefundLock $lock, string $source, ?int $adminId, bool $asked): bool
    {
        $refund = $lock->refund;
        if ($asked || (int) $refund->unknown_resubmits < max(0, (int) config('refunds.submission.max_unknown_resubmits'))) {
            return true;
        }
        $this->machine->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'SUBMISSION_UNRESOLVED'], ['resubmits' => (int) $refund->unknown_resubmits], $adminId);

        return false;
    }

    /**
     * When the sweep looks again — and when it stops: a refund it could not settle in all its tries needs a person.
     */
    private function schedule(RefundLock $lock, string $source, ?int $adminId, CarbonImmutable $at): void
    {
        $refund = $lock->refund;
        if (! in_array($refund->status, [RefundStatus::SubmissionUnknown, RefundStatus::Processing], true)) {
            return;
        }
        $waits = array_values(array_filter((array) config('refunds.reconcile.backoff_seconds'), 'is_numeric')) ?: [60];
        $runs = (int) $refund->reconcile_runs;
        if ($runs >= count($waits)) {
            $this->machine->move($lock, RefundStatus::ReviewRequired, $source, ['review_reason' => 'RECONCILIATION_EXHAUSTED'], ['runs' => $runs], $adminId);

            return;
        }
        $refund->forceFill(['next_action_at' => $at->addSeconds((int) $waits[$runs])])->save();
    }

    private function submissionOver(Refund $refund): bool
    {
        $settle = (int) config('services.payment.timeout') + (int) config('refunds.submission.settle_margin_seconds');

        return $refund->submitted_at === null || $refund->submitted_at->diffInSeconds(now()) >= $settle;
    }
}
