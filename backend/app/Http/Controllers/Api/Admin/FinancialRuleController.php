<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\FinancialRuleStatus;
use App\Enums\Permission;
use App\Enums\PickupMethod;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Models\FeeRule;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\TaxRule;
use App\Models\TaxRuleComponent;
use App\Services\Checkout\CheckoutMetrics;
use App\Services\Checkout\FinancialAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tax rules and fee rules of a market for platform administrators (Module 29).
 *
 * admin.market_configuration.view reads; admin.market_configuration.manage writes — per market, like the rest of the
 * market configuration. Restaurant staff have no access to tax or fee configuration. Every change needs a reason and
 * is audited; a rule is frozen once it is ACTIVE. Rates are basis points and amounts are minor units — integers.
 *
 * Nothing here knows an official rate: a rule is a TEST configuration until an administrator marks it APPROVED, and
 * the answers say which is which.
 */
class FinancialRuleController extends Controller
{
    use AuthorizesMarketScope;

    public function __construct(private readonly FinancialAdminService $admin) {}

    public function rules(Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationView, $market);
        $tax = TaxRule::query()->with(['components', 'location'])->where('market_id', $market->getKey())->orderByDesc('effective_from')->orderByDesc('id')->get();
        $fees = FeeRule::query()->with('location')->where('market_id', $market->getKey())->orderBy('code')->orderByDesc('effective_from')->orderByDesc('id')->get();

        return response()->json([
            'market' => ['id' => (string) $market->public_id, 'name' => (string) $market->name, 'default_currency' => (string) $market->default_currency],
            'tax_rules' => $tax->map(fn (TaxRule $r): array => $this->presentTax($r))->all(),
            'fee_rules' => $fees->map(fn (FeeRule $r): array => $this->presentFee($r))->all(),
            // Whether TEST rules are applied in this environment (never in production).
            'test_rules_applied' => (bool) config('checkout.financial_rules.allow_test'),
            'configured' => [
                'tax' => $tax->contains(fn (TaxRule $r): bool => $r->status === FinancialRuleStatus::Active && $r->approval === TaxRule::APPROVAL_APPROVED),
                'fees' => $fees->contains(fn (FeeRule $r): bool => $r->status === FinancialRuleStatus::Active && $r->approval === TaxRule::APPROVAL_APPROVED),
            ],
        ]);
    }

    public function storeTaxRule(Request $request, Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationManage, $market);
        $input = $this->location($this->taxInput($request, creating: true), $market);

        return response()->json(['tax_rule' => $this->presentTax($this->admin->createTaxRule($market, $input, $request->user())->load('location'))], 201);
    }

    public function updateTaxRule(Request $request, TaxRule $taxRule): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationManage, $taxRule->market);
        $input = $this->location($this->taxInput($request, creating: false), $taxRule->market);

        return response()->json(['tax_rule' => $this->presentTax($this->admin->updateTaxRule($taxRule, $input, $request->user())->load('location'))]);
    }

    public function taxRuleStatus(Request $request, TaxRule $taxRule): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationManage, $taxRule->market);

        return response()->json(['tax_rule' => $this->presentTax($this->admin->setRuleStatus($taxRule, $this->statusInput($request), $request->user())->load(['components', 'location']))]);
    }

    public function storeFeeRule(Request $request, Market $market): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationManage, $market);
        $input = $this->location($this->feeInput($request, $market, creating: true), $market);

        return response()->json(['fee_rule' => $this->presentFee($this->admin->createFeeRule($market, $input, $request->user())->load('location'))], 201);
    }

    public function updateFeeRule(Request $request, FeeRule $feeRule): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationManage, $feeRule->market);
        $input = $this->location($this->feeInput($request, $feeRule->market, creating: false), $feeRule->market);

        return response()->json(['fee_rule' => $this->presentFee($this->admin->updateFeeRule($feeRule, $input, $request->user())->load('location'))]);
    }

    public function feeRuleStatus(Request $request, FeeRule $feeRule): JsonResponse
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationManage, $feeRule->market);

        return response()->json(['fee_rule' => $this->presentFee($this->admin->setRuleStatus($feeRule, $this->statusInput($request), $request->user())->load('location'))]);
    }

    /**
     * Checkout numbers (counts only) for the System status page.
     */
    public function metrics(CheckoutMetrics $metrics): JsonResponse
    {
        return response()->json($metrics->report());
    }

    /**
     * @return array<string, mixed>
     */
    private function taxInput(Request $request, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'version' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'name' => [$need, 'string', 'min:2', 'max:120'],
            'applies_to' => [$need, 'string', Rule::in([TaxRule::APPLIES_MERCHANDISE, TaxRule::APPLIES_FEES])],
            'price_treatment' => [$need, 'string', Rule::in([TaxRule::PRICE_EXCLUSIVE, TaxRule::PRICE_INCLUSIVE])],
            'discount_treatment' => ['sometimes', 'string', Rule::in([TaxRule::DISCOUNT_AFTER, TaxRule::DISCOUNT_BEFORE])],
            'approval' => ['sometimes', 'string', Rule::in([TaxRule::APPROVAL_TEST, TaxRule::APPROVAL_APPROVED])],
            'effective_from' => [$need, 'date'],
            // That it lies after the start is checked against the stored rule (a change may name only the end).
            'effective_until' => ['sometimes', 'nullable', 'date'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'components' => [$need, 'array', 'min:1', 'max:10'],
            'components.*' => ['array:code,label,rate_basis_points'],
            'components.*.code' => ['required', 'string', 'distinct:ignore_case', 'regex:/\A[A-Za-z][A-Za-z0-9_]{1,23}\z/'],
            'components.*.label' => ['required', 'string', 'min:1', 'max:60'],
            // Basis points, an integer: 5 % is 500. Never a float, never a "percent" guess.
            'components.*.rate_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'status' => ['prohibited'],
        ], ['prohibited' => 'The :attribute field cannot be set here.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function feeInput(Request $request, Market $market, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';
        $input = $request->validate([
            'version' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'code' => [$need, 'string', 'regex:/\A[A-Z][A-Z0-9_]{2,23}\z/'],
            'label' => [$need, 'string', 'min:1', 'max:60'],
            'type' => [$need, 'string', Rule::in([FeeRule::TYPE_FIXED, FeeRule::TYPE_PERCENTAGE])],
            // A fee is charged in the market's currency — there is no conversion.
            'currency' => ['sometimes', 'nullable', 'string', Rule::in([(string) $market->default_currency])],
            'amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'percent_basis_points' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'percent_of' => ['sometimes', 'string', Rule::in([FeeRule::OF_SUBTOTAL, FeeRule::OF_DISCOUNTED_SUBTOTAL])],
            'minimum_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'maximum_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'pickup_method' => ['sometimes', 'nullable', 'string', Rule::enum(PickupMethod::class)],
            'taxable' => ['sometimes', 'boolean'],
            'approval' => ['sometimes', 'string', Rule::in([TaxRule::APPROVAL_TEST, TaxRule::APPROVAL_APPROVED])],
            'effective_from' => [$need, 'date'],
            // That it lies after the start is checked against the stored rule (a change may name only the end).
            'effective_until' => ['sometimes', 'nullable', 'date'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['prohibited'],
        ], ['prohibited' => 'The :attribute field cannot be set here.']);

        // Whether the parts fit together (a fixed fee has an amount, a percentage has a rate, no delivery fee, no
        // commission) is decided on the whole rule by the service — for a new rule and for every change.
        return $input;
    }

    /**
     * @return array{status: string, version: int, reason: string}
     */
    private function statusInput(Request $request): array
    {
        return $request->validate([
            'status' => ['required', 'string', Rule::enum(FinancialRuleStatus::class)],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
    }

    /**
     * Replaces `location_id` (a public id) by the restaurant location of THIS market, or null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function location(array $input, Market $market): array
    {
        if (! array_key_exists('location_id', $input)) {
            return $input;
        }
        $id = $input['location_id'];
        unset($input['location_id']);
        $input['location'] = $id === null ? null : (RestaurantLocation::query()->where('public_id', $id)->where('market_id', $market->getKey())->first() ?? throw ValidationException::withMessages(['location_id' => ['Not a restaurant of this market.']]));

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTax(TaxRule $rule): array
    {
        $iso = fn ($instant): ?string => $instant?->utc()->toIso8601String();

        return [
            'id' => (string) $rule->public_id,
            'name' => (string) $rule->name,
            'applies_to' => (string) $rule->applies_to,
            'price_treatment' => (string) $rule->price_treatment,
            'discount_treatment' => (string) $rule->discount_treatment,
            'status' => $rule->status->value,
            'approval' => (string) $rule->approval,
            'in_force' => $rule->status === FinancialRuleStatus::Active && $rule->effective_from <= now() && ($rule->effective_until === null || $rule->effective_until > now()),
            'effective_from' => $iso($rule->effective_from),
            'effective_until' => $iso($rule->effective_until),
            'location' => $rule->location === null ? null : ['id' => (string) $rule->location->public_id, 'name' => (string) $rule->location->name],
            'components' => $rule->components->map(fn (TaxRuleComponent $c): array => ['code' => (string) $c->code, 'label' => (string) $c->label, 'rate_basis_points' => (int) $c->rate_basis_points])->all(),
            'version' => (int) $rule->version,
            'updated_at' => $iso($rule->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFee(FeeRule $rule): array
    {
        $iso = fn ($instant): ?string => $instant?->utc()->toIso8601String();

        return [
            'id' => (string) $rule->public_id,
            'code' => (string) $rule->code,
            'label' => (string) $rule->label,
            'type' => (string) $rule->type,
            'currency' => $rule->currency,
            'amount_minor' => $rule->amount_minor,
            'percent_basis_points' => $rule->percent_basis_points,
            'percent_of' => (string) $rule->percent_of,
            'minimum_minor' => $rule->minimum_minor,
            'maximum_minor' => $rule->maximum_minor,
            'pickup_method' => $rule->pickup_method?->value,
            'taxable' => (bool) $rule->taxable,
            'status' => $rule->status->value,
            'approval' => (string) $rule->approval,
            'in_force' => $rule->status === FinancialRuleStatus::Active && $rule->effective_from <= now() && ($rule->effective_until === null || $rule->effective_until > now()),
            'effective_from' => $iso($rule->effective_from),
            'effective_until' => $iso($rule->effective_until),
            'location' => $rule->location === null ? null : ['id' => (string) $rule->location->public_id, 'name' => (string) $rule->location->name],
            'version' => (int) $rule->version,
            'updated_at' => $iso($rule->updated_at),
        ];
    }
}
