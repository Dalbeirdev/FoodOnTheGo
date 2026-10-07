<?php

namespace App\Services\Checkout;

use App\Enums\FinancialRuleStatus;
use App\Models\RestaurantLocation;
use App\Models\TaxRule;
use App\Support\Rounding;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calculates tax from the configured rules (Module 29) — and from nothing else. There is no tax rate in this class
 * or anywhere in the code: where no ACTIVE rule exists for the restaurant's market, the tax is zero and the answer
 * says NOT_CONFIGURED.
 *
 * Which rule applies. For each of the two things that can be taxed (MERCHANDISE, FEES) exactly one rule is used: the
 * ACTIVE rule whose effective period contains the moment of the checkout — the one of the restaurant location if
 * there is one, otherwise the market's; among several, the one that became effective last. Rules marked TEST are
 * used only where test rules are allowed (config checkout.financial_rules.allow_test — off in production).
 *
 * How it is calculated — deterministic, integers only:
 *  - taxable base of MERCHANDISE = subtotal − promotion discount (rule AFTER_DISCOUNT) or the subtotal (rule
 *    BEFORE_DISCOUNT); the rule decides, nothing is assumed;
 *  - taxable base of FEES = the sum of the fees configured as taxable;
 *  - EXCLUSIVE rule: each component = base × rate, rounded half up to the minor unit, and ADDED to the total;
 *  - INCLUSIVE rule: the tax inside the base = base − base ÷ (1 + sum of rates), rounded half up, split over the
 *    components by largest remainder (the parts add up exactly); it is REPORTED, never added;
 *  - rounding happens once per component at checkout level — not per line.
 */
final class TaxCalculationService
{
    /**
     * @param  list<array{amount_minor: int, taxable: bool}>  $fees
     */
    public function calculate(RestaurantLocation $location, int $subtotalMinor, int $discountMinor, array $fees, ?CarbonInterface $at = null): TaxResult
    {
        $at = CarbonImmutable::instance($at ?? now());
        $rules = $this->rulesFor($location, $at);
        $lines = [];
        $used = [];
        $approvals = [];

        $bases = [
            TaxRule::APPLIES_MERCHANDISE => fn (TaxRule $rule): int => max(0, $rule->discount_treatment === TaxRule::DISCOUNT_BEFORE ? $subtotalMinor : $subtotalMinor - $discountMinor),
            TaxRule::APPLIES_FEES => fn (TaxRule $rule): int => array_sum(array_map(fn (array $fee): int => $fee['taxable'] ? $fee['amount_minor'] : 0, $fees)),
        ];

        foreach ($bases as $appliesTo => $baseOf) {
            $rule = $rules[$appliesTo] ?? null;
            if ($rule === null) {
                continue;
            }
            $base = $baseOf($rule);
            $components = $rule->components->all();
            $rates = array_map(fn ($c): int => (int) $c->rate_basis_points, $components);
            $inclusive = $rule->price_treatment === TaxRule::PRICE_INCLUSIVE;
            $amounts = $inclusive
                ? Rounding::allocate(Rounding::includedTax($base, array_sum($rates)), $rates)
                : array_map(fn (int $rate): int => Rounding::basisPoints($base, $rate), $rates);

            foreach ($components as $i => $component) {
                $lines[] = [
                    'code' => (string) $component->code,
                    'label' => (string) $component->label,
                    'applies_to' => $appliesTo,
                    'rate_basis_points' => (int) $component->rate_basis_points,
                    'taxable_amount_minor' => $base,
                    'amount_minor' => $amounts[$i],
                    'included_in_prices' => $inclusive,
                ];
            }
            $used[] = ['id' => (string) $rule->public_id, 'version' => (int) $rule->version, 'applies_to' => $appliesTo, 'price_treatment' => (string) $rule->price_treatment, 'discount_treatment' => (string) $rule->discount_treatment, 'approval' => (string) $rule->approval];
            $approvals[] = (string) $rule->approval;
        }

        return new TaxResult($lines, $used, match (true) {
            $approvals === [] => TaxResult::NOT_CONFIGURED,
            in_array(TaxRule::APPROVAL_TEST, $approvals, true) => TaxResult::TEST,
            default => TaxResult::APPROVED,
        });
    }

    /**
     * The rule in force for each thing that can be taxed.
     *
     * @return array<string, TaxRule>
     */
    public function rulesFor(RestaurantLocation $location, CarbonInterface $at): array
    {
        $candidates = TaxRule::query()
            ->with('components')
            ->where('market_id', $location->market_id)
            ->where('status', FinancialRuleStatus::Active->value)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $location->getKey()))
            ->when(! config('checkout.financial_rules.allow_test'), fn ($q) => $q->where('approval', TaxRule::APPROVAL_APPROVED))
            // The location's own rule first, then the one that became effective last.
            ->orderByRaw('location_id is null')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $rules = [];
        foreach ($candidates as $rule) {
            $rules[(string) $rule->applies_to] ??= $rule;
        }

        return $rules;
    }
}
