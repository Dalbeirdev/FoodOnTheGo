<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\CommissionPlan;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Commercial terms as an administrator manages them (Module 36).
 *
 * A plan is a fact orders are allocated with, so it is never edited:
 *
 *   - a new plan starts in the FUTURE — it can never reach back over orders that were already placed;
 *   - new terms for a scope END the plan that was open there, at the moment the new one starts (both are audited);
 *   - a plan that has not started can be WITHDRAWN; one that has started can only be ENDED at a future moment;
 *   - an order keeps the plan, the rate and the base it was allocated with, whatever happens to the plan afterwards.
 *
 * Every change records who, the old and the new values, the effective date and the reason. A plan made here is a TEST
 * plan unless the reference of its approval is given; TEST plans are never applied in production.
 */
final class CommissionPlanService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{name: string, rate_bps: int, base_policy: string, effective_from: CarbonImmutable, effective_until?: CarbonImmutable|null, approval: string, approval_reference?: string|null, reason: string}  $input
     */
    public function create(AdminUser $admin, Market $market, ?RestaurantOrganization $organization, ?RestaurantLocation $location, array $input): CommissionPlan
    {
        $scope = $location !== null ? CommissionPlan::SCOPE_LOCATION : ($organization !== null ? CommissionPlan::SCOPE_ORGANIZATION : CommissionPlan::SCOPE_MARKET);
        $from = $input['effective_from'];
        $until = $input['effective_until'] ?? null;
        if (! $from->greaterThan(now())) {
            throw new ApiException(422, 'effective_from_in_the_past', 'A commission plan starts in the future: it never changes orders that were already placed.', ['fields' => ['effective_from' => ['Must be a moment in the future.']]]);
        }
        if ($until !== null && ! $until->greaterThan($from)) {
            throw new ApiException(422, 'effective_until_invalid', 'A plan ends after it starts.', ['fields' => ['effective_until' => ['Must be after effective_from.']]]);
        }
        if (! app()->environment(['local', 'testing']) && $input['approval'] !== CommissionPlan::APPROVAL_APPROVED) {
            throw new ApiException(422, 'approval_required', 'A production commission plan needs the reference of its approval.', ['fields' => ['approval' => ['Only an APPROVED plan can be made here.']]]);
        }
        if ($location !== null && ((int) $location->market_id !== (int) $market->getKey() || $organization === null || (int) $location->organization_id !== (int) $organization->getKey())) {
            throw new ApiException(422, 'scope_mismatch', 'The location does not belong to this market and organization.', ['fields' => ['location' => ['Not a location of this market and organization.']]]);
        }

        return DB::transaction(function () use ($admin, $market, $organization, $location, $scope, $input, $from, $until): CommissionPlan {
            // One change of a scope's terms at a time.
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['finance:commission:'.$market->getKey().':'.($organization?->getKey() ?? 0).':'.($location?->getKey() ?? 0)]);
            $same = CommissionPlan::query()->where('market_id', $market->getKey())->where('scope_type', $scope)->whereNull('withdrawn_at')
                ->when($organization !== null, fn ($q) => $q->where('organization_id', $organization->getKey()))
                ->when($location !== null, fn ($q) => $q->where('location_id', $location->getKey()))
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                ->when($until !== null, fn ($q) => $q->where('effective_from', '<', $until))
                ->orderBy('effective_from')->lockForUpdate()->get();
            $superseded = null;
            foreach ($same as $existing) {
                if ($existing->effective_from->greaterThanOrEqualTo($from)) {
                    throw ApiException::conflict('plan_overlaps', 'Another plan for the same scope starts in that period ('.$existing->name.'). Withdraw it first.');
                }
                // The plan in force ends where the new one starts.
                $before = $existing->effective_until?->toIso8601String();
                $existing->forceFill(['effective_until' => $from, 'version' => (int) $existing->version + 1])->save();
                $this->audit->record('finance.commission_plan_ended', $existing, $admin, ['effective_until' => ['from' => $before, 'to' => $from->toIso8601String()]], 'Superseded: '.$input['reason'], $market->getKey());
                $superseded = $existing;
            }
            $plan = new CommissionPlan;
            $plan->forceFill([
                'name' => $input['name'],
                'scope_type' => $scope,
                'market_id' => $market->getKey(),
                'organization_id' => $organization?->getKey(),
                'location_id' => $location?->getKey(),
                'type' => 'PERCENTAGE',
                'rate_bps' => (int) $input['rate_bps'],
                'base_policy' => $input['base_policy'],
                'effective_from' => $from,
                'effective_until' => $until,
                'approval' => $input['approval'],
                'approval_reference' => $input['approval'] === CommissionPlan::APPROVAL_APPROVED ? ($input['approval_reference'] ?? null) : null,
                'source' => CommissionPlan::SOURCE_ADMIN,
                'reason' => mb_substr($input['reason'], 0, 500),
                'created_by_admin_id' => $admin->getKey(),
                'supersedes_plan_id' => $superseded?->getKey(),
            ])->save();
            $this->audit->record('finance.commission_plan_created', $plan, $admin, [
                'rate_bps' => ['from' => $superseded?->rate_bps, 'to' => (int) $plan->rate_bps],
                'base_policy' => ['from' => $superseded?->base_policy->value, 'to' => $plan->base_policy->value],
                'effective_from' => ['from' => null, 'to' => $from->toIso8601String()],
                'effective_until' => ['from' => null, 'to' => $until?->toIso8601String()],
                'scope' => ['from' => null, 'to' => $scope],
                'approval' => ['from' => null, 'to' => $plan->approval],
            ], $input['reason'], $market->getKey());

            return $plan;
        });
    }

    /**
     * Ends a plan at a future moment. Orders placed before it keep the plan.
     */
    public function end(CommissionPlan $plan, AdminUser $admin, CarbonImmutable $at, string $reason, ?int $expectedVersion = null): CommissionPlan
    {
        return DB::transaction(function () use ($plan, $admin, $at, $reason, $expectedVersion): CommissionPlan {
            $locked = CommissionPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $expectedVersion !== (int) $locked->version) {
                throw ApiException::conflict('version_conflict', 'This plan was changed by somebody else. Read it again.');
            }
            if ($locked->withdrawn_at !== null) {
                throw ApiException::conflict('plan_withdrawn', 'This plan was withdrawn.');
            }
            if (! $at->greaterThan(now()) || ! $at->greaterThan($locked->effective_from)) {
                throw new ApiException(422, 'effective_until_invalid', 'A plan ends at a future moment after its start.', ['fields' => ['effective_until' => ['Must be in the future and after the plan\'s start.']]]);
            }
            if ($locked->effective_until !== null && ! $locked->effective_until->greaterThan(now())) {
                throw ApiException::conflict('plan_already_ended', 'This plan has already ended.');
            }
            $before = $locked->effective_until?->toIso8601String();
            $locked->forceFill(['effective_until' => $at, 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('finance.commission_plan_ended', $locked, $admin, ['effective_until' => ['from' => $before, 'to' => $at->toIso8601String()]], $reason, $locked->market_id);

            return $locked;
        });
    }

    /**
     * Withdraws a plan that has not started. A plan orders were allocated with is never withdrawn.
     */
    public function withdraw(CommissionPlan $plan, AdminUser $admin, string $reason): CommissionPlan
    {
        return DB::transaction(function () use ($plan, $admin, $reason): CommissionPlan {
            $locked = CommissionPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->withdrawn_at !== null) {
                return $locked;
            }
            if (! $locked->effective_from->greaterThan(now())) {
                throw ApiException::conflict('plan_already_started', 'A plan that has started cannot be withdrawn: orders were placed under it. End it at a future moment instead.');
            }
            $locked->forceFill(['withdrawn_at' => now(), 'withdrawn_by_admin_id' => $admin->getKey(), 'withdrawn_reason' => mb_substr($reason, 0, 500), 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('finance.commission_plan_withdrawn', $locked, $admin, ['withdrawn' => ['from' => false, 'to' => true]], $reason, $locked->market_id);

            return $locked;
        });
    }
}
