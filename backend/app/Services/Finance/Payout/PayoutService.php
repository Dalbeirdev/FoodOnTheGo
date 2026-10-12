<?php

namespace App\Services\Finance\Payout;

use App\Contracts\Finance\PayoutProvider;
use App\Enums\DifferenceType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Enums\PayoutStatus;
use App\Enums\ProviderFailure;
use App\Enums\ProviderPayoutState;
use App\Enums\SettlementStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ProviderException;
use App\Jobs\Finance\SubmitPayoutJob;
use App\Models\AdminUser;
use App\Models\PayoutWebhookEvent;
use App\Models\RestaurantPayout;
use App\Models\RestaurantPayoutProfile;
use App\Models\RestaurantSettlement;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\DifferenceService;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\HoldService;
use App\Services\Finance\LedgerDraft;
use App\Services\Finance\LedgerPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restaurant payouts (Module 36): the disbursement of ONE approved settlement.
 *
 * LIVE PAYOUT = DISABLED. The only provider is the development fake; besides it a payout that was really made outside
 * the platform can be RECORDED, with its bank reference, its date and its evidence.
 *
 * CODE INVARIANTS
 *   - the amount is the settlement's net payable — derived here, never taken from a request;
 *   - ONE payout is alive per settlement: the settlement row is locked while one is made and a unique index refuses a
 *     second (two administrators, a double click, a retried request: one payout);
 *   - every try of a payout carries the SAME idempotency key;
 *   - a submission without an answer is UNKNOWN — it is NOT sent again: the provider is asked what it has for the
 *     key. Only a person may send the same request (same key) again, after the provider said it has nothing;
 *   - PAID is the provider's own record of the payout (never a webhook's body, never an administrator's word), or an
 *     external payment recorded with reference, date and evidence. There is no "mark as paid";
 *   - PAID posts the ledger (Dr RESTAURANT_PAYABLE / Cr PAYOUT_CLEARING, key payout:{id}:paid) and makes the
 *     settlement PAID in the same transaction;
 *   - a paid payout is never edited. A payout the bank returns is reversed by a NEW transaction (payout:{id}:reversed).
 */
final class PayoutService
{
    public function __construct(
        private readonly PayoutProviderManager $providers,
        private readonly LedgerPostingService $ledger,
        private readonly HoldService $holds,
        private readonly DifferenceService $differences,
        private readonly FinanceMetrics $metrics,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Makes the payout of an approved settlement and queues its submission to the payout provider.
     */
    public function start(RestaurantSettlement $settlement, AdminUser $admin, ?string $note = null): RestaurantPayout
    {
        $provider = $this->usableProvider();
        try {
            [$payout, $created] = DB::transaction(function () use ($settlement, $admin, $note, $provider): array {
                $locked = RestaurantSettlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
                if (($alive = $this->alive($locked)) !== null) {
                    // Asked twice (two administrators, a double click): the payout that exists is the answer.
                    $this->metrics->record('payout_duplicate_blocked');
                    Log::warning('finance.payout_duplicate_blocked', ['settlement' => $locked->public_id, 'payout' => $alive->public_id]);

                    return [$alive, false];
                }
                $this->assertPayable($locked, $admin);
                $profile = RestaurantPayoutProfile::query()->where('location_id', $locked->location_id)->where('provider', $provider->name())->first();
                if ($profile === null || $profile->status !== RestaurantPayoutProfile::VERIFIED || $profile->provider_account_reference === null) {
                    throw ApiException::conflict('payout_destination_not_verified', 'This restaurant has no verified payout destination with the payout provider.');
                }
                $payout = $this->make($locked, $admin, [
                    'method' => RestaurantPayout::METHOD_PROVIDER, 'provider' => $provider->name(), 'provider_mode' => $provider->mode(),
                    'destination_mask' => $profile->destination_mask, 'status' => PayoutStatus::Required, 'note' => $note === null ? null : mb_substr($note, 0, 500),
                    'next_action_at' => now()->addSeconds((int) config('finance.payouts.settle_margin_seconds')),
                ]);
                $from = $locked->status->value;
                $locked->forceFill(['status' => SettlementStatus::PaymentPending, 'version' => (int) $locked->version + 1])->save();
                $this->audit->record('finance.payout_started', $payout, $admin, [
                    'status' => ['from' => null, 'to' => PayoutStatus::Required->value],
                    'amount_minor' => ['from' => null, 'to' => (int) $payout->amount_minor],
                    'settlement_status' => ['from' => $from, 'to' => SettlementStatus::PaymentPending->value],
                ], $note, $locked->market_id);

                return [$payout, true];
            });
        } catch (UniqueConstraintViolationException) {
            // The index refused a second payout that slipped past the lock.
            $this->metrics->record('payout_duplicate_blocked');
            $existing = $this->alive($settlement);

            return $existing ?? throw ApiException::conflict('payout_in_progress', 'This settlement already has a payout.');
        }
        if ($created) {
            $this->metrics->record('payout_started');
            SubmitPayoutJob::dispatch((int) $payout->getKey());
        }

        return $payout->refresh();
    }

    /**
     * Sends a payout to the provider — once. Run by the queue; safe to run again and by two workers.
     */
    public function submit(int $payoutId): void
    {
        $request = DB::transaction(function () use ($payoutId): ?PayoutRequest {
            $payout = RestaurantPayout::query()->whereKey($payoutId)->lockForUpdate()->first();
            if ($payout === null || $payout->status !== PayoutStatus::Required || $payout->method !== RestaurantPayout::METHOD_PROVIDER) {
                return null;
            }
            $profile = RestaurantPayoutProfile::query()->where('location_id', $payout->location_id)->where('provider', $payout->provider)->first();
            if ($profile === null || $profile->status !== RestaurantPayoutProfile::VERIFIED || $this->holds->locationHeld((int) $payout->location_id)) {
                $this->close($payout, PayoutStatus::ReviewRequired, ['review_reason' => $profile?->status === RestaurantPayoutProfile::VERIFIED ? 'LOCATION_HELD' : 'DESTINATION_NOT_VERIFIED']);

                return null;
            }
            $payout->forceFill([
                'status' => PayoutStatus::Submitting, 'submitted_at' => now(), 'submit_attempts' => (int) $payout->submit_attempts + 1,
                'next_action_at' => now()->addSeconds((int) config('finance.payouts.settle_margin_seconds')), 'version' => (int) $payout->version + 1,
            ])->save();

            return new PayoutRequest((string) $payout->idempotency_key, (string) $profile->provider_account_reference, (int) $payout->amount_minor, (string) $payout->currency, (string) $payout->public_id, 'FoodOnTheGo settlement');
        });
        if ($request === null) {
            return;
        }
        $payout = RestaurantPayout::query()->findOrFail($payoutId);
        $provider = $this->providers->named((string) $payout->provider);
        if ($provider === null) {
            $this->notSent($payout, 'PROVIDER_NOT_USABLE');

            return;
        }
        $this->metrics->record('payout_submitted');
        try {
            $answer = $this->providers->call($provider, 'create', fn (): ProviderPayout => $provider->createPayout($request));
        } catch (ProviderException $e) {
            match ($e->failure) {
                // The provider certainly did nothing: it may be sent again later (same key).
                ProviderFailure::RateLimited, ProviderFailure::Misconfigured => $this->notSent($payout, $e->failure->value),
                ProviderFailure::Rejected => $this->rejected($payout, mb_substr($e->getMessage(), 0, 240)),
                // A timeout, a broken connection: the payout may exist. It is NOT sent again.
                default => $this->unknown($payout, $e->failure->value),
            };

            return;
        } catch (Throwable $e) {
            $this->unknown($payout, 'UNEXPECTED');
            Log::error('finance.payout_submission_failed', ['payout' => $payout->public_id, 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);

            return;
        }
        $this->adopt($payout, $answer, 'SUBMISSION');
    }

    /**
     * Asks the provider what it has for a payout and takes its answer. Changes only what the provider confirms.
     */
    public function reconcile(RestaurantPayout $payout, ?AdminUser $admin = null, ?string $reason = null, bool $sweep = false): RestaurantPayout
    {
        if ($payout->method !== RestaurantPayout::METHOD_PROVIDER || $payout->status === PayoutStatus::Cancelled || $payout->status === PayoutStatus::Required) {
            return $payout;
        }
        $provider = $this->providers->named((string) $payout->provider);
        if ($provider === null) {
            throw ApiException::conflict('payout_provider_unavailable', 'The payout provider of this payout cannot be reached here.');
        }
        if ($admin !== null) {
            $this->audit->record('finance.payout_reconciled', $payout, $admin, ['status' => ['from' => $payout->status->value, 'to' => $payout->status->value]], $reason, $payout->market_id);
        }
        try {
            $found = $payout->provider_payout_reference !== null
                ? $this->providers->call($provider, 'get', fn (): ProviderPayout => $provider->getPayout((string) $payout->provider_payout_reference))
                : $this->providers->call($provider, 'find', fn (): ?ProviderPayout => $provider->findPayoutByKey((string) $payout->idempotency_key));
        } catch (ProviderException $e) {
            $this->waited($payout, $sweep);
            if ($admin !== null) {
                throw new ApiException(503, 'payout_provider_unavailable', 'The payout provider did not answer. Nothing was changed.');
            }

            return $payout->refresh();
        }
        if ($found === null) {
            // The provider has nothing for our key. It is still not sent again by itself: a person decides.
            return $this->transition($payout, function (RestaurantPayout $p) use ($sweep): void {
                $runs = (int) $p->reconcile_runs + 1;
                $giveUp = $runs >= 2 || ! $sweep;
                $p->forceFill([
                    'reconcile_runs' => $runs, 'last_reconciled_at' => now(),
                    ...($giveUp && in_array($p->status, [PayoutStatus::Unknown, PayoutStatus::Submitting], true)
                        ? ['status' => PayoutStatus::ReviewRequired, 'review_reason' => 'PROVIDER_HAS_NO_RECORD', 'next_action_at' => null]
                        : ['next_action_at' => $this->nextCheck($runs)]),
                    'version' => (int) $p->version + 1,
                ])->save();
                if ($p->status === PayoutStatus::ReviewRequired) {
                    $this->metrics->record('payout_review_required');
                }
            });
        }

        return $this->adopt($payout, $found, 'RECONCILIATION', $sweep);
    }

    /**
     * A person sends a payout again that the provider said it does not have — the SAME request, the same idempotency
     * key: if the provider has it after all, it answers with the payout that exists.
     */
    public function retry(RestaurantPayout $payout, AdminUser $admin, string $reason): RestaurantPayout
    {
        $this->usableProvider();
        DB::transaction(function () use ($payout, $admin, $reason): void {
            $locked = RestaurantPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PayoutStatus::ReviewRequired || ! in_array($locked->review_reason, ['PROVIDER_HAS_NO_RECORD', 'DESTINATION_NOT_VERIFIED', 'LOCATION_HELD'], true)) {
                throw ApiException::conflict('payout_not_retryable', 'Only a payout the provider has no record of can be sent again. A failed payout is replaced by a new one from its settlement.');
            }
            $from = $locked->status->value;
            $locked->forceFill(['status' => PayoutStatus::Required, 'review_reason' => null, 'reconcile_runs' => 0, 'next_action_at' => now()->addSeconds((int) config('finance.payouts.settle_margin_seconds')), 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('finance.payout_retried', $locked, $admin, ['status' => ['from' => $from, 'to' => PayoutStatus::Required->value]], $reason, $locked->market_id);
        });
        SubmitPayoutJob::dispatch((int) $payout->getKey());

        return $payout->refresh();
    }

    /**
     * Withdraws a payout that was never sent. The settlement is approved again.
     */
    public function cancel(RestaurantPayout $payout, AdminUser $admin, string $reason): RestaurantPayout
    {
        return DB::transaction(function () use ($payout, $admin, $reason): RestaurantPayout {
            $settlement = RestaurantSettlement::query()->whereKey($payout->settlement_id)->lockForUpdate()->firstOrFail();
            $locked = RestaurantPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            $neverSent = $locked->status === PayoutStatus::Required || ($locked->status === PayoutStatus::ReviewRequired && in_array($locked->review_reason, ['DESTINATION_NOT_VERIFIED', 'LOCATION_HELD'], true) && (int) $locked->submit_attempts === 0);
            if (! $neverSent) {
                throw ApiException::conflict('payout_not_cancellable', 'Only a payout that was never sent to the provider can be withdrawn.');
            }
            $from = $locked->status->value;
            $locked->forceFill(['status' => PayoutStatus::Cancelled, 'cancelled_at' => now(), 'next_action_at' => null, 'version' => (int) $locked->version + 1])->save();
            if ($settlement->status === SettlementStatus::PaymentPending) {
                $settlement->forceFill(['status' => SettlementStatus::Approved, 'version' => (int) $settlement->version + 1])->save();
            }
            $this->audit->record('finance.payout_cancelled', $locked, $admin, ['status' => ['from' => $from, 'to' => PayoutStatus::Cancelled->value]], $reason, $locked->market_id);

            return $locked;
        });
    }

    /**
     * Records a payout that was made OUTSIDE the platform (a bank transfer by the finance team). It is a statement of
     * evidence — reference, day, amount, currency, what proves it — and the amount and currency must be exactly the
     * settlement's: it confirms, it does not choose. It posts the same ledger transaction a provider payout does.
     *
     * @param  array{external_reference: string, paid_on: string, amount_minor: int, currency: string, reason: string, evidence_reference: string}  $input
     */
    public function recordExternal(RestaurantSettlement $settlement, AdminUser $admin, array $input): RestaurantPayout
    {
        try {
            $payout = DB::transaction(function () use ($settlement, $admin, $input): RestaurantPayout {
                $locked = RestaurantSettlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
                if ($this->alive($locked) !== null) {
                    $this->metrics->record('payout_duplicate_blocked');
                    throw ApiException::conflict('payout_in_progress', 'This settlement already has a payout.');
                }
                $this->assertPayable($locked, $admin);
                if ((int) $input['amount_minor'] !== (int) $locked->net_payable_minor || $input['currency'] !== (string) $locked->currency) {
                    throw new ApiException(422, 'amount_mismatch', 'An external payout confirms the settlement\'s own amount and currency.', ['fields' => ['amount_minor' => ['This settlement is '.$locked->net_payable_minor.' '.$locked->currency.'.']]]);
                }
                $paidOn = CarbonImmutable::createFromFormat('!Y-m-d', $input['paid_on'], (string) $locked->timezone);
                if ($paidOn->greaterThan(CarbonImmutable::now((string) $locked->timezone)) || $paidOn->lessThan(CarbonImmutable::instance($locked->approved_at)->setTimezone((string) $locked->timezone)->startOfDay())) {
                    throw new ApiException(422, 'paid_on_invalid', 'The day of the payment lies between the settlement\'s approval and today.', ['fields' => ['paid_on' => ['Must be between the approval of the settlement and today.']]]);
                }
                $payout = $this->make($locked, $admin, [
                    'method' => RestaurantPayout::METHOD_EXTERNAL, 'provider' => RestaurantPayout::PROVIDER_EXTERNAL, 'status' => PayoutStatus::Processing,
                    'external_reference' => mb_substr(trim($input['external_reference']), 0, 80), 'external_paid_on' => $paidOn->toDateString(),
                    'evidence_reference' => mb_substr($input['evidence_reference'], 0, 160), 'note' => mb_substr($input['reason'], 0, 500), 'processing_at' => now(),
                ]);
                $this->paid($payout, $locked, CarbonImmutable::now());
                $this->audit->record('finance.payout_recorded_external', $payout, $admin, [
                    'status' => ['from' => null, 'to' => PayoutStatus::Paid->value],
                    'amount_minor' => ['from' => null, 'to' => (int) $payout->amount_minor],
                    'external_reference' => ['from' => null, 'to' => $payout->external_reference],
                    'external_paid_on' => ['from' => null, 'to' => $paidOn->toDateString()],
                    'evidence_reference' => ['from' => null, 'to' => $payout->evidence_reference],
                ], $input['reason'], $locked->market_id);

                return $payout;
            });
        } catch (UniqueConstraintViolationException) {
            $this->metrics->record('payout_duplicate_blocked');
            throw ApiException::conflict('external_reference_used', 'This bank reference is already recorded for a payout — or this settlement already has one.');
        }
        $this->metrics->record('payout_external');

        return $payout;
    }

    /**
     * A payout provider's webhook: verified, counted once, and then nothing but a reason to ask the provider.
     *
     * @param  array<string, string>  $headers  lower-cased
     * @return array{status: int, result: string}
     */
    public function webhook(string $providerName, string $rawBody, array $headers): array
    {
        $provider = $this->providers->named($providerName);
        if ($provider === null) {
            return ['status' => 404, 'result' => 'UNKNOWN_PROVIDER'];
        }
        if (! $provider->verifyWebhookSignature($rawBody, $headers[$provider->webhookSignatureHeader()] ?? null)) {
            $this->metrics->record('payout_webhook_refused');
            Log::warning('finance.payout_webhook_refused', ['provider' => $provider->name(), 'why' => 'signature']);

            return ['status' => 400, 'result' => 'INVALID_SIGNATURE'];
        }
        try {
            $event = $provider->parseWebhook($rawBody, $headers);
        } catch (ProviderException) {
            $this->metrics->record('payout_webhook_refused');

            return ['status' => 400, 'result' => 'MALFORMED'];
        }
        $tolerance = (int) config('finance.payouts.webhook_tolerance_seconds');
        if ($event->createdAt !== null && $event->createdAt->greaterThan(now()->addSeconds($tolerance))) {
            $this->metrics->record('payout_webhook_refused');

            return ['status' => 400, 'result' => 'TIMESTAMP_IN_FUTURE'];
        }
        try {
            DB::transaction(function () use ($provider, $event): void {
                (new PayoutWebhookEvent)->forceFill([
                    'provider' => $provider->name(), 'event_id' => $event->eventId, 'event_type' => $event->type,
                    'provider_payout_reference' => $event->payoutReference, 'result' => 'RECEIVED', 'provider_created_at' => $event->createdAt, 'received_at' => now(),
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Delivered again: acknowledged, nothing done.
            $this->metrics->record('payout_webhook_duplicate');

            return ['status' => 200, 'result' => 'DUPLICATE'];
        }
        $this->metrics->record('payout_webhook');
        $payout = $event->payoutReference === null ? null : RestaurantPayout::query()->where('provider', $provider->name())->where('provider_payout_reference', $event->payoutReference)->first();
        $result = 'NO_MATCHING_PAYOUT';
        if ($payout !== null) {
            // The webhook's body decides nothing (it may be old, or arrive before an earlier one): the provider's
            // record, read now, does.
            try {
                $this->reconcile($payout);
                $result = 'PROCESSED';
            } catch (Throwable $e) {
                $result = 'DEFERRED';
                Log::warning('finance.payout_webhook_deferred', ['payout' => $payout->public_id, 'error' => $e::class]);
            }
        }
        PayoutWebhookEvent::query()->where('provider', $provider->name())->where('event_id', $event->eventId)->update(['result' => $result]);

        return ['status' => 200, 'result' => $result];
    }

    /**
     * The scheduled part: payouts whose submission job was lost are submitted; payouts without a final answer are
     * looked up, with a growing wait, until a person has to look.
     *
     * @return array{submitted: int, checked: int}
     */
    public function sweep(): array
    {
        $done = ['submitted' => 0, 'checked' => 0];
        $batch = max(1, (int) config('finance.payouts.batch'));
        if ($this->providers->driver() === null) {
            return $done;
        }
        foreach (RestaurantPayout::query()->where('method', RestaurantPayout::METHOD_PROVIDER)->where('status', PayoutStatus::Required->value)->where('next_action_at', '<=', now())->orderBy('id')->limit($batch)->pluck('id') as $id) {
            $this->submit((int) $id);
            $done['submitted']++;
        }
        $unsettled = RestaurantPayout::query()->where('method', RestaurantPayout::METHOD_PROVIDER)->whereIn('status', array_map(fn (PayoutStatus $s): string => $s->value, PayoutStatus::unsettled()))
            ->where('next_action_at', '<=', now())->orderBy('next_action_at')->limit($batch)->get();
        foreach ($unsettled as $payout) {
            try {
                $this->reconcile($payout, sweep: true);
            } catch (Throwable $e) {
                Log::warning('finance.payout_check_failed', ['payout' => $payout->public_id, 'error' => $e::class]);
                if (app()->environment('testing')) {
                    throw $e;
                }
            }
            $done['checked']++;
        }

        return $done;
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * Takes the provider's record of a payout. The only way a provider payout becomes PAID.
     */
    private function adopt(RestaurantPayout $payout, ProviderPayout $record, string $source, bool $sweep = false): RestaurantPayout
    {
        $mismatch = false;
        $adopted = DB::transaction(function () use ($payout, $record, $sweep, &$mismatch): RestaurantPayout {
            $settlement = RestaurantSettlement::query()->whereKey($payout->settlement_id)->lockForUpdate()->firstOrFail();
            $locked = RestaurantPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === PayoutStatus::Cancelled) {
                return $locked;
            }
            $locked->forceFill(['provider_payout_reference' => $locked->provider_payout_reference ?? $record->reference, 'provider_status' => mb_substr($record->status, 0, 24), 'last_reconciled_at' => now()]);
            // The provider's record must be OUR payout: the same amount, the same currency, the same reference.
            if ($record->amountMinor !== (int) $locked->amount_minor || $record->currency !== (string) $locked->currency || ($locked->provider_payout_reference !== $record->reference)) {
                $mismatch = true;
                $this->close($locked, PayoutStatus::ReviewRequired, ['review_reason' => 'PROVIDER_RECORD_DIFFERS']);

                return $locked;
            }
            $paid = $locked->status === PayoutStatus::Paid;
            match (true) {
                $record->state === ProviderPayoutState::Processed && ! $paid && $locked->status !== PayoutStatus::Failed => $this->paid($locked, $settlement, CarbonImmutable::now()),
                $record->state === ProviderPayoutState::Processed && $locked->status === PayoutStatus::Failed => $this->close($locked, PayoutStatus::ReviewRequired, ['review_reason' => 'PROVIDER_PAID_A_FAILED_PAYOUT']),
                $record->state === ProviderPayoutState::Reversed && $paid => $this->reversed($locked, $settlement, $record),
                in_array($record->state, [ProviderPayoutState::Failed, ProviderPayoutState::Reversed], true) && ! $paid && $locked->status !== PayoutStatus::Failed => $this->fail($locked, $record->failureCode ?? strtoupper($record->status), $record->failureMessage, $settlement),
                $record->state === ProviderPayoutState::Processing && in_array($locked->status, [PayoutStatus::Required, PayoutStatus::Submitting, PayoutStatus::Unknown, PayoutStatus::ReviewRequired], true) => $locked->forceFill([
                    'status' => PayoutStatus::Processing, 'processing_at' => $locked->processing_at ?? now(), 'review_reason' => null, 'reconcile_runs' => 0, 'next_action_at' => $this->nextCheck(0), 'version' => (int) $locked->version + 1,
                ])->save(),
                // Nothing new (still processing, a late "processing" after PAID, a state we do not know): keep waiting.
                default => $locked->forceFill($locked->status->isFinal() ? [] : ['reconcile_runs' => (int) $locked->reconcile_runs + ($sweep ? 1 : 0), 'next_action_at' => $this->nextCheck((int) $locked->reconcile_runs + ($sweep ? 1 : 0))])->save(),
            };
            if (! $locked->status->isFinal() && $locked->status !== PayoutStatus::ReviewRequired && $locked->next_action_at === null) {
                // The waits are used up: a person looks.
                $this->close($locked, PayoutStatus::ReviewRequired, ['review_reason' => 'NO_FINAL_ANSWER']);
            }

            return $locked;
        });
        if ($mismatch) {
            $this->differences->report(DifferenceType::PayoutSettlementMismatch, 'payout-record:'.$adopted->public_id, [
                'provider' => (string) $adopted->provider, 'provider_reference' => $record->reference, 'subject_type' => 'restaurant_payout', 'subject_id' => (int) $adopted->getKey(),
                'subject_public_id' => (string) $adopted->public_id, 'subject_reference' => $adopted->supportReference(), 'market_id' => $adopted->market_id, 'location_id' => (int) $adopted->location_id,
                'currency' => (string) $adopted->currency, 'expected_minor' => (int) $adopted->amount_minor, 'actual_minor' => $record->amountMinor, 'detail' => ['provider_currency' => $record->currency, 'source' => $source],
            ]);
        }

        return $adopted;
    }

    private function paid(RestaurantPayout $payout, RestaurantSettlement $settlement, CarbonImmutable $at): void
    {
        $location = $payout->location()->firstOrFail();
        $currency = (string) $payout->currency;
        $transaction = $this->ledger->post(
            LedgerDraft::for('payout:'.$payout->public_id.':paid', LedgerTransactionType::PayoutPaid, $currency, $at)
                ->source('restaurant_payout', (int) $payout->getKey(), (string) $payout->public_id, (string) $settlement->reference)
                ->dimensions($payout->market_id, (int) $payout->organization_id, (int) $payout->location_id)
                ->explained(null, $payout->requested_by_admin_id, ['method' => $payout->method, 'provider' => $payout->provider, 'settlement' => (string) $settlement->public_id])
                ->debit($this->ledger->payable($location, $currency), (int) $payout->amount_minor, 'PAID_TO_RESTAURANT')
                ->credit($this->ledger->platform(LedgerAccountCode::PayoutClearing, $currency), (int) $payout->amount_minor, 'PAYOUT_SENT'),
        );
        $payout->forceFill([
            'status' => PayoutStatus::Paid, 'completed_at' => $at, 'processing_at' => $payout->processing_at ?? $at, 'ledger_transaction_id' => $transaction->getKey(),
            'review_reason' => null, 'next_action_at' => null, 'version' => (int) $payout->version + 1,
        ])->save();
        $settlement->forceFill(['status' => SettlementStatus::Paid, 'paid_at' => $at, 'paid_minor' => (int) $payout->amount_minor, 'version' => (int) $settlement->version + 1])->save();
        $this->audit->record('finance.payout_paid', $payout, null, ['status' => ['from' => null, 'to' => PayoutStatus::Paid->value], 'amount_minor' => ['from' => null, 'to' => (int) $payout->amount_minor]], null, $payout->market_id);
        $this->metrics->record('payout_paid');
        Log::info('finance.payout_paid', ['payout' => $payout->public_id, 'settlement' => $settlement->public_id, 'amount_minor' => (int) $payout->amount_minor, 'currency' => $currency, 'method' => $payout->method]);
    }

    /**
     * The bank returned a payout that had been paid: the payout's posting is undone by a NEW transaction, and the
     * settlement can be paid again. The paid posting itself stays in the ledger, untouched.
     */
    private function reversed(RestaurantPayout $payout, RestaurantSettlement $settlement, ProviderPayout $record): void
    {
        $original = $this->ledger->find('payout:'.$payout->public_id.':paid');
        if ($original !== null) {
            $this->ledger->reverse($original, 'payout:'.$payout->public_id.':reversed', 'The provider reported the payout as reversed.');
        }
        $payout->forceFill([
            'status' => PayoutStatus::Failed, 'failed_at' => now(), 'failure_code' => 'REVERSED', 'failure_message' => mb_substr((string) ($record->failureMessage ?? 'The payout was returned.'), 0, 240),
            'next_action_at' => null, 'version' => (int) $payout->version + 1,
        ])->save();
        $settlement->forceFill(['status' => SettlementStatus::Failed, 'paid_at' => null, 'paid_minor' => 0, 'version' => (int) $settlement->version + 1])->save();
        $this->audit->record('finance.payout_reversed', $payout, null, ['status' => ['from' => PayoutStatus::Paid->value, 'to' => PayoutStatus::Failed->value]], null, $payout->market_id);
        $this->metrics->record('payout_reversed');
        $this->metrics->record('payout_failed');
    }

    private function fail(RestaurantPayout $payout, string $code, ?string $message, ?RestaurantSettlement $settlement = null): void
    {
        $settlement ??= RestaurantSettlement::query()->whereKey($payout->settlement_id)->lockForUpdate()->firstOrFail();
        $from = $payout->status->value;
        $payout->forceFill([
            'status' => PayoutStatus::Failed, 'failed_at' => now(), 'failure_code' => mb_substr($code, 0, 40), 'failure_message' => $message === null ? null : mb_substr($message, 0, 240),
            'next_action_at' => null, 'version' => (int) $payout->version + 1,
        ])->save();
        if ($settlement->status === SettlementStatus::PaymentPending) {
            $settlement->forceFill(['status' => SettlementStatus::Failed, 'version' => (int) $settlement->version + 1])->save();
        }
        $this->audit->record('finance.payout_failed', $payout, null, ['status' => ['from' => $from, 'to' => PayoutStatus::Failed->value], 'failure_code' => ['from' => null, 'to' => $code]], null, $payout->market_id);
        $this->metrics->record('payout_failed');
    }

    /**
     * The provider refused the payout outright: nothing was sent. Settlement first, then the payout — the lock order
     * every payout change uses.
     */
    private function rejected(RestaurantPayout $payout, string $message): void
    {
        DB::transaction(function () use ($payout, $message): void {
            $settlement = RestaurantSettlement::query()->whereKey($payout->settlement_id)->lockForUpdate()->firstOrFail();
            $locked = RestaurantPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === PayoutStatus::Submitting) {
                $this->fail($locked, 'PROVIDER_REJECTED', $message, $settlement);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function close(RestaurantPayout $payout, PayoutStatus $status, array $attributes = []): void
    {
        $payout->forceFill(['status' => $status, 'next_action_at' => null, ...$attributes, 'version' => (int) $payout->version + 1])->save();
        if ($status === PayoutStatus::ReviewRequired) {
            $this->metrics->record('payout_review_required');
            Log::warning('finance.payout_review_required', ['payout' => $payout->public_id, 'reason' => $attributes['review_reason'] ?? null]);
        }
    }

    private function unknown(RestaurantPayout $payout, string $why): void
    {
        $this->transition($payout, function (RestaurantPayout $p) use ($why): void {
            if ($p->status !== PayoutStatus::Submitting) {
                return;
            }
            $p->forceFill(['status' => PayoutStatus::Unknown, 'failure_code' => mb_substr($why, 0, 40), 'reconcile_runs' => 0, 'next_action_at' => $this->nextCheck(0), 'version' => (int) $p->version + 1])->save();
            $this->metrics->record('payout_unknown');
            Log::warning('finance.payout_unknown', ['payout' => $p->public_id, 'why' => $why]);
        });
    }

    private function notSent(RestaurantPayout $payout, string $why): void
    {
        $this->transition($payout, function (RestaurantPayout $p) use ($why): void {
            if ($p->status !== PayoutStatus::Submitting) {
                return;
            }
            $waits = [60, 300, 900, 3600];
            $attempt = (int) $p->submit_attempts;
            $attempt > count($waits)
                ? $this->close($p, PayoutStatus::ReviewRequired, ['review_reason' => 'NOT_ACCEPTED_BY_PROVIDER', 'failure_code' => mb_substr($why, 0, 40)])
                : $p->forceFill(['status' => PayoutStatus::Required, 'failure_code' => mb_substr($why, 0, 40), 'next_action_at' => now()->addSeconds($waits[$attempt - 1] ?? 3600), 'version' => (int) $p->version + 1])->save();
        });
    }

    private function waited(RestaurantPayout $payout, bool $sweep): void
    {
        $this->transition($payout, function (RestaurantPayout $p) use ($sweep): void {
            if ($p->status->isFinal() || $p->status === PayoutStatus::ReviewRequired) {
                return;
            }
            $runs = (int) $p->reconcile_runs + ($sweep ? 1 : 0);
            $next = $this->nextCheck($runs);
            $next === null
                ? $this->close($p, PayoutStatus::ReviewRequired, ['review_reason' => 'NO_FINAL_ANSWER', 'reconcile_runs' => $runs])
                : $p->forceFill(['reconcile_runs' => $runs, 'next_action_at' => $next, 'last_reconciled_at' => now()])->save();
        });
    }

    /**
     * @param  callable(RestaurantPayout): void  $change
     */
    private function transition(RestaurantPayout $payout, callable $change): RestaurantPayout
    {
        return DB::transaction(function () use ($payout, $change): RestaurantPayout {
            $locked = RestaurantPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            $change($locked);

            return $locked;
        });
    }

    private function nextCheck(int $runs): ?CarbonImmutable
    {
        $waits = (array) config('finance.payouts.reconcile_backoff_seconds');

        return isset($waits[$runs]) ? CarbonImmutable::now()->addSeconds((int) $waits[$runs]) : null;
    }

    private function alive(RestaurantSettlement $settlement): ?RestaurantPayout
    {
        return RestaurantPayout::query()->where('settlement_id', $settlement->getKey())->whereNotIn('status', [PayoutStatus::Failed->value, PayoutStatus::Cancelled->value])->first();
    }

    private function assertPayable(RestaurantSettlement $settlement, AdminUser $admin): void
    {
        if (! in_array($settlement->status, SettlementStatus::payable(), true)) {
            throw ApiException::conflict('settlement_not_payable', 'A payout needs an approved settlement (this one is '.$settlement->status->value.').');
        }
        if ((int) $settlement->net_payable_minor <= 0) {
            throw ApiException::conflict('nothing_to_pay', 'This settlement has nothing to pay.');
        }
        if ((bool) config('finance.payouts.four_eyes') && (int) $settlement->approved_by_admin_id === (int) $admin->getKey()) {
            throw new ApiException(403, 'four_eyes', 'Who approved a settlement cannot pay it.');
        }
        if ($this->holds->locationHeld((int) $settlement->location_id)) {
            throw ApiException::conflict('location_held', 'This restaurant has an active financial hold. Nothing is paid while it lasts.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(RestaurantSettlement $settlement, AdminUser $admin, array $attributes): RestaurantPayout
    {
        $payout = new RestaurantPayout;
        $payout->forceFill([
            'public_id' => $id = (string) Str::uuid(),
            'settlement_id' => $settlement->getKey(),
            'organization_id' => $settlement->organization_id,
            'location_id' => $settlement->location_id,
            'market_id' => $settlement->market_id,
            'restaurant_name' => $settlement->restaurant_name,
            // One key for every try of this payout.
            'idempotency_key' => 'po-'.$id,
            'amount_minor' => (int) $settlement->net_payable_minor,
            'currency' => $settlement->currency,
            'requested_by_admin_id' => $admin->getKey(),
            'requested_at' => now(),
            ...$attributes,
        ])->save();

        return $payout;
    }

    private function usableProvider(): PayoutProvider
    {
        try {
            return $this->providers->provider();
        } catch (ProviderException) {
            throw new ApiException(409, 'payout_provider_not_configured', 'No payout provider can be used here: live restaurant payouts are DISABLED. A payout made outside the platform can be recorded with its evidence.');
        }
    }
}
