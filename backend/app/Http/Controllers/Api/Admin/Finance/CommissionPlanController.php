<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Enums\CommissionBasePolicy;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Http\Support\ListQuery;
use App\Models\CommissionPlan;
use App\Models\Market;
use App\Models\RestaurantOrganization;
use App\Services\Finance\CommissionPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Commercial terms for administrators (Module 36): commission plans and their history.
 *
 *   read                 admin.finance.commission.view   (negotiated terms are confidential: a separate permission)
 *   make, end, withdraw  admin.finance.commission.manage
 *
 * A plan is never edited: there is no PUT and no PATCH. A new plan starts in the future and ends the one before it; a
 * plan that has started can be ended at a future moment; one that has not started can be withdrawn. Every change has
 * a reason and an audit event with the old and the new values. NO RATE IS A DEFAULT ANYWHERE: the request states it,
 * and a plan without the reference of its approval is a TEST plan that production never applies.
 */
class CommissionPlanController extends Controller
{
    use FinanceAccess;

    private const WITH = ['market', 'organization', 'location', 'createdBy'];

    public function __construct(private readonly CommissionPlanService $plans, private readonly FinancePresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request, CommissionPlan::query()->with(self::WITH), Permission::AdminFinanceCommissionView);
        $now = now();
        $list = new ListQuery($request, filterable: ['scope', 'state', 'market', 'organization', 'restaurant', 'approval'], sortable: ['effective_from', 'created_at', 'rate_bps'], defaultSort: '-effective_from', handlers: [
            'scope' => fn (Builder $q, string $v) => $q->where('scope_type', strtoupper($v)),
            'approval' => fn (Builder $q, string $v) => $q->where('approval', strtoupper($v)),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
            'organization' => fn (Builder $q, string $v) => $q->whereIn('organization_id', DB::table('restaurant_organizations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'state' => fn (Builder $q, string $v) => match (strtoupper($v)) {
                'WITHDRAWN' => $q->whereNotNull('withdrawn_at'),
                'SCHEDULED' => $q->whereNull('withdrawn_at')->where('effective_from', '>', $now),
                'ENDED' => $q->whereNull('withdrawn_at')->whereNotNull('effective_until')->where('effective_until', '<=', $now),
                'ACTIVE' => $q->whereNull('withdrawn_at')->where('effective_from', '<=', $now)->where(fn (Builder $w) => $w->whereNull('effective_until')->orWhere('effective_until', '>', $now)),
                default => $q->whereRaw('false'),
            },
        ]);

        return JsonResource::collection($list->paginate($query)->through(fn (CommissionPlan $p): array => $this->presenter->plan($p)))->response();
    }

    public function show(Request $request, string $commissionPlan): JsonResponse
    {
        return $this->answer($request, $this->find($commissionPlan, Permission::AdminFinanceCommissionView));
    }

    public function store(Request $request): JsonResponse
    {
        // Who holds the permission nowhere is refused before anything of the request is looked at.
        $this->marketsWith($request, Permission::AdminFinanceCommissionManage);
        $input = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'market' => ['required', 'string', 'max:40'],
            'organization' => ['sometimes', 'nullable', 'string', 'uuid'],
            'restaurant' => ['sometimes', 'nullable', 'string', 'uuid'],
            // No default: the rate is stated, in basis points (1 % = 100).
            'rate_bps' => ['required', 'integer', 'between:0,10000'],
            'base_policy' => ['required', 'string', Rule::in(array_map(fn (CommissionBasePolicy $p): string => $p->value, CommissionBasePolicy::cases()))],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date'],
            'approval' => ['required', 'string', Rule::in([CommissionPlan::APPROVAL_TEST, CommissionPlan::APPROVAL_APPROVED])],
            'approval_reference' => ['nullable', 'string', 'min:3', 'max:160', 'required_if:approval,'.CommissionPlan::APPROVAL_APPROVED],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            ...self::prohibited(['type', 'source', 'version', 'created_by', 'scope', 'scope_type']),
        ], self::PROHIBITED_MESSAGE);
        $market = Market::query()->where('slug', $input['market'])->first() ?? throw ApiException::notFound('market_not_found', 'This market does not exist.');
        $this->authorizeFor(Permission::AdminFinanceCommissionManage, $market);
        $location = isset($input['restaurant']) ? $this->locationOf($input['restaurant']) : null;
        $organization = $location?->organization ?? (isset($input['organization']) ? (RestaurantOrganization::query()->where('public_id', $input['organization'])->first() ?? throw ApiException::notFound('organization_not_found', 'This organization does not exist.')) : null);
        $plan = $this->plans->create($this->admin($request), $market, $organization, $location, [
            'name' => (string) $input['name'], 'rate_bps' => (int) $input['rate_bps'], 'base_policy' => (string) $input['base_policy'],
            'effective_from' => CarbonImmutable::parse((string) $input['effective_from']), 'effective_until' => isset($input['effective_until']) ? CarbonImmutable::parse((string) $input['effective_until']) : null,
            'approval' => (string) $input['approval'], 'approval_reference' => $input['approval_reference'] ?? null, 'reason' => (string) $input['reason'],
        ]);

        return $this->answer($request, $this->find((string) $plan->public_id, Permission::AdminFinanceCommissionView), 201);
    }

    public function end(Request $request, string $commissionPlan): JsonResponse
    {
        $found = $this->find($commissionPlan, Permission::AdminFinanceCommissionManage);
        $input = $request->validate([
            'effective_until' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...self::prohibited(['rate_bps', 'base_policy', 'effective_from', 'approval']),
        ], ['prohibited' => 'The :attribute field of a plan is never changed: make a new plan instead.']);
        $this->plans->end($found, $this->admin($request), CarbonImmutable::parse((string) $input['effective_until']), (string) $input['reason'], $input['expected_version'] ?? null);

        return $this->answer($request, $this->find($commissionPlan, Permission::AdminFinanceCommissionView));
    }

    public function withdraw(Request $request, string $commissionPlan): JsonResponse
    {
        $found = $this->find($commissionPlan, Permission::AdminFinanceCommissionManage);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], ...self::prohibited(['rate_bps', 'base_policy', 'effective_from', 'approval'])], self::PROHIBITED_MESSAGE);
        $this->plans->withdraw($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($commissionPlan, Permission::AdminFinanceCommissionView));
    }

    private function answer(Request $request, CommissionPlan $plan, int $status = 200): JsonResponse
    {
        $manage = $this->allowed($request, Permission::AdminFinanceCommissionManage, $plan->market);
        $state = $plan->stateAt(now());

        return response()->json(['plan' => [
            ...$this->presenter->plan($plan),
            'orders_allocated' => (int) DB::table('order_financial_allocations')->where('commission_plan_id', $plan->getKey())->count(),
            'can' => ['end' => $manage && $state === 'ACTIVE', 'withdraw' => $manage && $state === 'SCHEDULED'],
        ]], $status);
    }

    private function find(string $plan, Permission $permission): CommissionPlan
    {
        $found = CommissionPlan::query()->with(self::WITH)->where('public_id', $plan)->first() ?? throw ApiException::notFound('commission_plan_not_found', 'This commission plan does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }
}
