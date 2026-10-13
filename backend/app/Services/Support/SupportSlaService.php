<?php

namespace App\Services\Support;

use App\Enums\SupportCaseStatus;
use App\Enums\SupportRequesterType;
use App\Enums\SupportSlaStatus;
use App\Models\SupportCase;
use App\Models\SupportSlaPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * INTERNAL targets for support cases (Module 37): when a first reply and a resolution are due, and whether a case is
 * on track, at risk, breached or paused. Elapsed wall-clock time against configurable targets
 * (support_sla_policies) — an operating aid, NOT a contractual commitment, never shown to a requester. A case no
 * policy matches is not measured (N/A). Every figure comes from real timestamps: a breach, once it happened, stays.
 */
final class SupportSlaService
{
    /**
     * The policy that applies to a case: the ACTIVE one that matches it on every field it names and names the most.
     */
    public function policyFor(SupportCase $case): ?SupportSlaPolicy
    {
        $best = null;
        $bestScore = -1;
        foreach (SupportSlaPolicy::query()->where('status', SupportSlaPolicy::ACTIVE)->orderBy('id')->get() as $policy) {
            $score = 0;
            foreach ([['market_id', $case->market_id, 1], ['team', $case->assigned_team?->value, 2], ['category', $case->category, 4], ['priority', $case->priority->value, 8]] as [$field, $value, $weight]) {
                $wanted = $policy->getAttribute($field);
                if ($wanted === null) {
                    continue;
                }
                if ((string) $wanted !== (string) $value) {
                    continue 2;
                }
                $score += $weight;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$policy, $score];
            }
        }

        return $best;
    }

    /**
     * Sets the targets of a case from the policy that applies now (when it is opened, and again when its priority,
     * category or queue changes). Targets already met or missed are left as they are.
     */
    public function apply(SupportCase $case, CarbonInterface $now): void
    {
        $policy = $this->policyFor($case);
        if ($policy === null) {
            if ($case->sla_breached_at === null) {
                $case->forceFill(['sla_policy_id' => null, 'first_response_due_at' => null, 'resolution_due_at' => null, 'sla_status' => SupportSlaStatus::NotApplicable->value, 'sla_paused_at' => null]);
            }

            return;
        }
        $start = CarbonImmutable::instance($case->reopened_at ?? $case->created_at ?? $now)->addSeconds((int) $case->sla_paused_seconds);
        // A first response is owed to somebody: a case of the system or of an administrator has no requester to answer.
        $answerable = in_array($case->requester_type, [SupportRequesterType::Customer, SupportRequesterType::Restaurant], true);
        $case->forceFill([
            'sla_policy_id' => $policy->getKey(),
            'first_response_due_at' => ! $answerable ? null : ($case->first_response_at !== null || $policy->first_response_minutes === null ? $case->first_response_due_at : $start->addMinutes($policy->first_response_minutes)),
            'resolution_due_at' => $policy->resolution_minutes === null ? null : $start->addMinutes($policy->resolution_minutes),
        ]);
        $case->setRelation('slaPolicy', $policy);
        $case->forceFill(['sla_status' => $this->evaluate($case, $now)->value]);
    }

    /** The clock stands still while support waits for the requester's own answer — when the policy says so. */
    public function pauses(SupportCase $case, SupportCaseStatus $status): bool
    {
        $policy = $case->sla_policy_id === null ? null : ($case->relationLoaded('slaPolicy') ? $case->slaPolicy : SupportSlaPolicy::query()->find($case->sla_policy_id));
        if ($policy === null || ! $policy->pause_while_waiting_for_requester) {
            return false;
        }

        return ($status === SupportCaseStatus::WaitingForCustomer && $case->requester_type === SupportRequesterType::Customer)
            || ($status === SupportCaseStatus::WaitingForRestaurant && $case->requester_type === SupportRequesterType::Restaurant);
    }

    /**
     * What a change of status means for the clock. Called by the state machine before the case is saved.
     */
    public function onTransition(SupportCase $case, SupportCaseStatus $from, SupportCaseStatus $to, CarbonInterface $now): void
    {
        if ($case->sla_policy_id === null) {
            return;
        }
        $now = CarbonImmutable::instance($now);
        if ($case->sla_paused_at !== null && ! $this->pauses($case, $to)) {
            $paused = max(0, (int) CarbonImmutable::instance($case->sla_paused_at)->diffInSeconds($now));
            $case->forceFill([
                'sla_paused_seconds' => (int) $case->sla_paused_seconds + $paused,
                'sla_paused_at' => null,
                'first_response_due_at' => $case->first_response_at === null && $case->first_response_due_at !== null ? CarbonImmutable::instance($case->first_response_due_at)->addSeconds($paused) : $case->first_response_due_at,
                'resolution_due_at' => $case->resolution_due_at === null ? null : CarbonImmutable::instance($case->resolution_due_at)->addSeconds($paused),
            ]);
        }
        if ($case->sla_paused_at === null && $this->pauses($case, $to)) {
            $case->forceFill(['sla_paused_at' => $now]);
        }
        if ($to === SupportCaseStatus::Reopened) {
            // A reopened case is measured again from now; what was missed before stays missed.
            // The waiting of its earlier life is over with it: a later re-calculation starts at the reopening, not
            // at the reopening plus every pause the case ever had.
            $minutes = $this->policyFor($case)?->resolution_minutes;
            $case->forceFill(['resolution_due_at' => $minutes === null ? null : $now->addMinutes($minutes), 'sla_paused_seconds' => 0]);
        }
        if (! $to->isActive() && $case->resolution_due_at !== null && $case->sla_breached_at === null && $now->greaterThan($case->resolution_due_at)) {
            $case->forceFill(['sla_breached_at' => $now]);
        }
        $case->forceFill(['sla_status' => $this->evaluate($case, $now, $to)->value]);
    }

    /** A person of support answered for the first time. */
    public function firstResponse(SupportCase $case, CarbonInterface $now): void
    {
        if ($case->first_response_at !== null) {
            return;
        }
        $now = CarbonImmutable::instance($now);
        $case->forceFill(['first_response_at' => $now]);
        if ($case->sla_policy_id !== null) {
            if ($case->first_response_due_at !== null && $case->sla_breached_at === null && $now->greaterThan($case->first_response_due_at)) {
                $case->forceFill(['sla_breached_at' => $now]);
            }
            $case->forceFill(['sla_status' => $this->evaluate($case, $now)->value]);
        }
    }

    /**
     * Where the case stands against its targets at this moment — from its own timestamps only.
     */
    public function evaluate(SupportCase $case, CarbonInterface $now, ?SupportCaseStatus $as = null): SupportSlaStatus
    {
        if ($case->sla_policy_id === null) {
            return $case->sla_breached_at !== null ? SupportSlaStatus::Breached : SupportSlaStatus::NotApplicable;
        }
        $status = $as ?? $case->status;
        $now = CarbonImmutable::instance($now);
        if ($case->sla_breached_at !== null) {
            return SupportSlaStatus::Breached;
        }
        if (! $status->isActive()) {
            return SupportSlaStatus::OnTrack;
        }
        if ($case->sla_paused_at !== null) {
            return SupportSlaStatus::Paused;
        }
        $pending = array_values(array_filter([$case->first_response_at === null ? $case->first_response_due_at : null, $case->resolution_due_at]));
        foreach ($pending as $due) {
            if ($now->greaterThan($due)) {
                return SupportSlaStatus::Breached;
            }
        }
        $policy = $case->relationLoaded('slaPolicy') ? $case->slaPolicy : SupportSlaPolicy::query()->find($case->sla_policy_id);
        $ratio = min(0.99, max(0.1, (float) config('support.sla.at_risk_ratio')));
        foreach ([[$case->first_response_at === null ? $case->first_response_due_at : null, $policy?->first_response_minutes], [$case->resolution_due_at, $policy?->resolution_minutes]] as [$due, $minutes]) {
            if ($due === null || $minutes === null) {
                continue;
            }
            $left = $now->diffInSeconds(CarbonImmutable::instance($due), false);
            if ($left <= (1 - $ratio) * $minutes * 60) {
                return SupportSlaStatus::AtRisk;
            }
        }

        return SupportSlaStatus::OnTrack;
    }
}
