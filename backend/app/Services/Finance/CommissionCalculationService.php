<?php

namespace App\Services\Finance;

use App\Enums\CommissionBasePolicy;
use App\Models\CommissionPlan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the commercial terms that applied at a moment and calculates a commission with them (Module 36).
 *
 * POLICY (configuration, never code): the rate and the base come from the commission plan. No rate exists in this
 * class, in a constant, in an environment variable or in a default — and when no plan applies there is NO commission
 * calculation at all (it is never treated as 0 %): the order waits for a finance decision.
 *
 * CODE INVARIANTS: the most specific plan wins (location, then organization, then market); a plan applies from
 * effective_from (inclusive) to effective_until (exclusive); a withdrawn plan never applies; a TEST plan never applies
 * in production; the amount is rate × base in integer arithmetic, rounded half up to the minor unit.
 */
final class CommissionCalculationService
{
    /**
     * The plan that applied to an order of this location placed at this moment, or null when none did.
     */
    public function planFor(int $marketId, int $organizationId, int $locationId, CarbonInterface $at): ?CommissionPlan
    {
        $applies = fn (Builder $q) => $q->whereNull('withdrawn_at')
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $w) => $w->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->when(app()->isProduction(), fn (Builder $w) => $w->where('approval', CommissionPlan::APPROVAL_APPROVED));

        return CommissionPlan::query()->where('market_id', $marketId)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $s) => $s->where('scope_type', CommissionPlan::SCOPE_LOCATION)->where('location_id', $locationId))
                ->orWhere(fn (Builder $s) => $s->where('scope_type', CommissionPlan::SCOPE_ORGANIZATION)->where('organization_id', $organizationId))
                ->orWhere('scope_type', CommissionPlan::SCOPE_MARKET))
            ->tap($applies)
            ->orderByRaw("case scope_type when 'LOCATION' then 1 when 'ORGANIZATION' then 2 else 3 end")
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{base_minor: int, rate_bps: int, amount_minor: int, base_policy: string}
     */
    public function calculate(int $rateBps, CommissionBasePolicy $policy, int $merchandiseGross, int $restaurantDiscount, int $platformDiscount): array
    {
        $base = $policy->base($merchandiseGross, $restaurantDiscount, $platformDiscount);

        return [
            'base_minor' => $base,
            'rate_bps' => $rateBps,
            'amount_minor' => self::percentage($base, $rateBps),
            'base_policy' => $policy->value,
        ];
    }

    /**
     * rate × base, exact: integers only, half a minor unit rounds up.
     */
    public static function percentage(int $baseMinor, int $rateBps): int
    {
        return intdiv($baseMinor * $rateBps + 5000, 10000);
    }
}
