<?php

namespace App\Services\Checkout;

use App\Enums\FinancialRuleStatus;
use App\Enums\PickupMethod;
use App\Models\FeeRule;
use App\Models\RestaurantLocation;
use App\Models\TaxRule;
use App\Support\Rounding;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Calculates the fees of a checkout from the configured fee rules (Module 29) — and from nothing else. No fee is
 * written in code and none is seeded: without an ACTIVE rule the fee total is zero and no fee line exists.
 * FoodOnTheGo is a pickup product: there is no delivery fee, and a restaurant's commission is a settlement matter
 * that never appears as a customer fee (the database refuses such codes).
 *
 * Which rules apply: every ACTIVE rule of the restaurant's market whose effective period contains the moment of the
 * checkout and whose restaurant location / pickup method (when it names one) match. Per fee code one rule is used:
 * the location's before the market's, one for the pickup method before a general one, then the latest.
 *
 * How a fee is calculated — integers only: a FIXED amount as configured; a PERCENTAGE of the merchandise subtotal
 * (before the promotion discount, or after it when the rule says DISCOUNTED_SUBTOTAL), rounded half up, then kept
 * between the rule's minimum and maximum. A promotion never reduces a fee on its own.
 */
final class FeeCalculationService
{
    public function calculate(RestaurantLocation $location, string $currency, PickupMethod $method, int $subtotalMinor, int $discountMinor, ?CarbonInterface $at = null): FeeResult
    {
        $at = CarbonImmutable::instance($at ?? now());
        $lines = [];
        $used = [];
        $approvals = [];

        foreach ($this->rulesFor($location, $method, $at) as $rule) {
            // No conversion exists: a rule with amounts in another currency cannot be applied to this checkout.
            if ($rule->currency !== null && $rule->currency !== $currency) {
                Log::warning('checkout.fee_rule_skipped', ['fee_rule' => $rule->public_id, 'reason' => 'currency_mismatch', 'rule_currency' => $rule->currency, 'checkout_currency' => $currency]);

                continue;
            }
            $amount = $rule->type === FeeRule::TYPE_FIXED
                ? (int) $rule->amount_minor
                : Rounding::basisPoints(max(0, $rule->percent_of === FeeRule::OF_DISCOUNTED_SUBTOTAL ? $subtotalMinor - $discountMinor : $subtotalMinor), (int) $rule->percent_basis_points);
            if ($rule->minimum_minor !== null) {
                $amount = max($amount, $rule->minimum_minor);
            }
            if ($rule->maximum_minor !== null) {
                $amount = min($amount, $rule->maximum_minor);
            }
            if ($amount <= 0) {
                continue;
            }
            $lines[] = ['code' => (string) $rule->code, 'label' => (string) $rule->label, 'type' => (string) $rule->type, 'amount_minor' => $amount, 'taxable' => (bool) $rule->taxable];
            $used[] = ['id' => (string) $rule->public_id, 'version' => (int) $rule->version, 'code' => (string) $rule->code, 'approval' => (string) $rule->approval];
            $approvals[] = (string) $rule->approval;
        }

        return new FeeResult($lines, $used, match (true) {
            $approvals === [] => FeeResult::NOT_CONFIGURED,
            in_array(TaxRule::APPROVAL_TEST, $approvals, true) => FeeResult::TEST,
            default => FeeResult::APPROVED,
        });
    }

    /**
     * One rule per fee code, in a stable order.
     *
     * @return list<FeeRule>
     */
    public function rulesFor(RestaurantLocation $location, PickupMethod $method, CarbonInterface $at): array
    {
        $candidates = FeeRule::query()
            ->where('market_id', $location->market_id)
            ->where('status', FinancialRuleStatus::Active->value)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $location->getKey()))
            ->where(fn ($q) => $q->whereNull('pickup_method')->orWhere('pickup_method', $method->value))
            ->when(! config('checkout.financial_rules.allow_test'), fn ($q) => $q->where('approval', TaxRule::APPROVAL_APPROVED))
            ->orderByRaw('location_id is null')
            ->orderByRaw('pickup_method is null')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $rules = [];
        foreach ($candidates as $rule) {
            $rules[(string) $rule->code] ??= $rule;
        }
        ksort($rules);

        return array_values($rules);
    }
}
