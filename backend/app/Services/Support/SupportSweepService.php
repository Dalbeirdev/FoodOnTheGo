<?php

namespace App\Services\Support;

use App\Enums\SupportCaseStatus;
use App\Enums\SupportEscalationReason;
use App\Enums\SupportRequesterType;
use App\Enums\SupportResolutionCode;
use App\Enums\SupportSlaStatus;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the clock does to support cases (Module 37) — php artisan support:sweep, every minute:
 *
 *   1. opens the system cases a listener missed (SystemCaseService::sweep),
 *   2. brings every measured case's SLA state up to date and writes "at risk" / "breached" to its timeline ONCE —
 *      from the case's real timestamps, never to make a figure look better,
 *   3. when the business has set the numbers of days (it has not): resolves cases whose requester never answered and
 *      closes cases that stayed resolved.
 *
 * Safe to run any number of times and by two runners at once: every step locks its case and checks again.
 */
final class SupportSweepService
{
    public function __construct(
        private readonly SystemCaseService $system,
        private readonly SupportCaseService $cases,
        private readonly SupportSlaService $sla,
        private readonly SupportCaseStateMachine $machine,
        private readonly SupportMetrics $metrics,
    ) {}

    /**
     * @return array{system_cases: array<string, int>, sla_at_risk: int, sla_breached: int, sla_escalated: int, no_response_resolved: int, auto_closed: int, failed: int}
     */
    public function sweep(?CarbonInterface $at = null): array
    {
        $now = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();
        $counts = ['system_cases' => ['payment_recovery' => 0, 'refund_review' => 0, 'finance_difference' => 0, 'dispute' => 0], 'sla_at_risk' => 0, 'sla_breached' => 0, 'sla_escalated' => 0, 'no_response_resolved' => 0, 'auto_closed' => 0, 'failed' => 0];
        // 1. The cases the listeners missed. One record that cannot be read must not stop the clock work below.
        try {
            $counts['system_cases'] = $this->system->sweep();
        } catch (Throwable $e) {
            $counts['failed']++;
            Log::error('support.sweep_failed', ['step' => 'system_cases', 'class' => $e::class]);
        }
        $batch = max(1, (int) config('support.system_cases.sweep_batch'));

        // 2. SLA: only cases that are measured, still active and not already known to be breached.
        // Every such case is looked at, a batch after the other (a case that is on track stays in this set, so the first
        // batch alone would be read again every minute and later cases never). A case is locked only when what the
        // clock says differs from what the case says.
        $measured = fn () => SupportCase::query()->whereNotNull('sla_policy_id')->whereIn('status', SupportCaseStatus::activeValues())->whereNull('sla_breached_at')->whereNull('sla_paused_at')
            ->where(fn ($q) => $q->whereNotNull('resolution_due_at')->orWhere(fn ($f) => $f->whereNull('first_response_at')->whereNotNull('first_response_due_at')));
        $due = [];
        $after = 0;
        do {
            $page = $measured()->where('id', '>', $after)->orderBy('id')->limit($batch)->get();
            foreach ($page as $candidate) {
                $after = (int) $candidate->getKey();
                $says = $this->sla->evaluate($candidate, $now);
                if ($says !== $candidate->sla_status || ($says === SupportSlaStatus::AtRisk && $candidate->sla_at_risk_at === null)) {
                    $due[] = $after;
                }
            }
        } while ($page->count() === $batch);
        foreach ($due as $id) {
            try {
                $result = DB::transaction(function () use ($id, $now): ?string {
                    $case = SupportCase::query()->whereKey($id)->lockForUpdate()->first();
                    if ($case === null || ! $case->status->isActive() || $case->sla_policy_id === null || $case->sla_breached_at !== null) {
                        return null;
                    }
                    $status = $this->sla->evaluate($case, $now);
                    $told = null;
                    if ($status === SupportSlaStatus::Breached) {
                        $missed = $case->first_response_at === null && $case->first_response_due_at !== null && $now->greaterThan($case->first_response_due_at) ? 'FIRST_RESPONSE' : 'RESOLUTION';
                        $case->forceFill(['sla_breached_at' => $now, 'sla_status' => $status->value]);
                        $this->machine->line($case, SupportCaseEvent::SLA_BREACHED, SupportActor::system(), SupportCaseEvent::INTERNAL, ['target' => $missed], null, null, $now);
                        $told = 'breached';
                    } elseif ($status === SupportSlaStatus::AtRisk && $case->sla_at_risk_at === null) {
                        $case->forceFill(['sla_at_risk_at' => $now, 'sla_status' => $status->value]);
                        $this->machine->line($case, SupportCaseEvent::SLA_AT_RISK, SupportActor::system(), SupportCaseEvent::INTERNAL, [], null, null, $now);
                        $told = 'at_risk';
                    } elseif ($case->sla_status !== $status) {
                        $case->forceFill(['sla_status' => $status->value])->save();
                    }

                    return $told;
                });
                if ($result === 'breached') {
                    $counts['sla_breached']++;
                    $this->metrics->record('sla_breached');
                    if ((bool) config('support.sla.auto_escalate')) {
                        $case = SupportCase::query()->find($id);
                        if ($case !== null && $case->escalation_level === null) {
                            $this->cases->escalate($case, null, SupportEscalationReason::SlaRisk, 'L2', null, null, 'An internal target of this case was missed.', null);
                            $counts['sla_escalated']++;
                        }
                    }
                } elseif ($result === 'at_risk') {
                    $counts['sla_at_risk']++;
                    $this->metrics->record('sla_at_risk');
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                Log::error('support.sweep_failed', ['step' => 'sla', 'case' => $id, 'class' => $e::class]);
            }
        }

        // 3. By the clock — only when the business set the days.
        $silent = config('support.auto_close.no_response_after_days');
        if (is_int($silent) && $silent > 0) {
            // Only a case that waits for ITS OWN requester: a customer's case that waits for the restaurant is not the customer's silence.
            $waiting = SupportCase::query()->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('status', SupportCaseStatus::WaitingForCustomer->value)->where('requester_type', SupportRequesterType::Customer->value))
                ->orWhere(fn ($r) => $r->where('status', SupportCaseStatus::WaitingForRestaurant->value)->where('requester_type', SupportRequesterType::Restaurant->value)))
                ->where('updated_at', '<=', $now->subDays($silent))->where(fn ($q) => $q->whereNull('last_requester_message_at')->orWhere('last_requester_message_at', '<=', $now->subDays($silent)))
                ->orderBy('id')->limit($batch)->get();
            foreach ($waiting as $case) {
                try {
                    DB::transaction(function () use ($case, $now): void {
                        $locked = $this->cases->lock($case);
                        if (! in_array($locked->status, [SupportCaseStatus::WaitingForCustomer, SupportCaseStatus::WaitingForRestaurant], true)) {
                            return;
                        }
                        $this->cases->closeEscalations($locked, $now);
                        $this->cases->systemMessage($locked, 'We did not hear back from you, so this case was marked as resolved. You can reopen it if you still need help.');
                        $this->machine->move($locked, SupportCaseStatus::Resolved, SupportActor::system(), ['resolution_code' => SupportResolutionCode::NoActionRequired->value, 'resolution_note' => 'No answer from the requester.'], ['resolution_code' => SupportResolutionCode::NoActionRequired->value, 'reason' => 'NO_RESPONSE'], $now);
                        $locked->save();
                    });
                    $counts['no_response_resolved']++;
                } catch (Throwable $e) {
                    $counts['failed']++;
                    Log::error('support.sweep_failed', ['step' => 'no_response', 'case' => (string) $case->public_id, 'class' => $e::class]);
                }
            }
        }
        $resolved = config('support.auto_close.resolved_after_days');
        if (is_int($resolved) && $resolved > 0) {
            foreach (SupportCase::query()->where('status', SupportCaseStatus::Resolved->value)->where('resolved_at', '<=', $now->subDays($resolved))->orderBy('id')->limit($batch)->get() as $case) {
                try {
                    $this->cases->close($case, SupportActor::system());
                    $counts['auto_closed']++;
                    $this->metrics->record('auto_closed');
                } catch (Throwable $e) {
                    $counts['failed']++;
                    Log::error('support.sweep_failed', ['step' => 'auto_close', 'case' => (string) $case->public_id, 'class' => $e::class]);
                }
            }
        }

        return $counts;
    }
}
