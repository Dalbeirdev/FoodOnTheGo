<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Enums\AllocationStatus;
use App\Enums\CommissionBasePolicy;
use App\Enums\LedgerAccountCode;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Http\Controllers\Api\Restaurant\FinanceController as RestaurantFinance;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Http\Support\ListQuery;
use App\Models\FinanceRun;
use App\Models\FinancialHold;
use App\Models\LedgerAccount;
use App\Models\Market;
use App\Models\OrderFinancialAllocation;
use App\Models\RestaurantPayoutProfile;
use App\Services\Finance\FinanceHealthService;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\OrderAllocationService;
use App\Services\Finance\RestaurantEarningsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The finance overview and reports for administrators (Module 36) — admin.finance.view, for a market or for all.
 *
 * Everything is read from the ledger and the allocations with SQL sums for an explicit period. Gross marketplace
 * sales are shown as that and never called revenue; provider fees are the provider's own figures or zero.
 * Commission rates are NOT part of this permission: plans are read with admin.finance.commission.view.
 */
class OverviewController extends Controller
{
    use FinanceAccess;

    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly RestaurantEarningsService $earnings,
        private readonly OrderAllocationService $allocations,
        private readonly FinancePresenter $presenter,
    ) {}

    public function overview(Request $request, FinanceHealthService $health): JsonResponse
    {
        $timezone = $this->reportingTimezone();
        [$from, $to, $period] = $this->period($request, $timezone);
        $overview = $this->reports->overview($this->marketIds($request, Permission::AdminFinanceView), $from, $to);
        $report = $health->report();

        return response()->json([
            'period' => $period,
            'currencies' => array_map(fn (array $row): array => RestaurantFinance::moneyRow($row), $overview['currencies']),
            'queues' => $overview['queues'],
            'provider_fees' => $overview['provider_fees'],
            'terms' => [
                'gross_marketplace_sales' => 'Merchandise restaurants sold through the platform. It is not FoodOnTheGo revenue.',
                'platform_revenue' => 'Commission plus the customer fees that are the platform\'s.',
                'restaurant_payables' => 'What the platform owes restaurants now, from the ledger.',
                'provider_fees' => 'The payment provider\'s own reported fees. Never estimated.',
            ],
            // Health in the words of the health page: nothing is "healthy" because it exists.
            'health' => ['ledger' => $report['ledger']['status'], 'internal_reconciliation' => $report['internal_reconciliation']['status'], 'provider_reconciliation' => $report['provider_reconciliation']['source_status'],
                'payout_provider' => $report['payout_provider']['status'], 'live_payouts' => 'DISABLED', 'queue' => $report['queue']['status']],
        ]);
    }

    public function commissionReport(Request $request): JsonResponse
    {
        $input = $request->validate(['group_by' => ['sometimes', 'string', Rule::in(['day', 'location', 'organization', 'market', 'plan'])], 'restaurant' => ['sometimes', 'string', 'uuid'], 'market' => ['sometimes', 'string', 'max:40']]);
        $group = (string) ($input['group_by'] ?? 'day');
        // Grouping by plan shows negotiated rates: that needs the commission permission.
        if ($group === 'plan') {
            $this->marketsWith($request, Permission::AdminFinanceCommissionView);
        }
        $timezone = $this->reportingTimezone($input['market'] ?? null);
        [$from, $to, $period] = $this->period($request, $timezone);
        $markets = $this->narrow($request, $input['market'] ?? null);
        // Module 39: negotiated rates only for the markets where the caller holds the commission permission.
        if ($group === 'plan' && ($commission = $this->marketIds($request, Permission::AdminFinanceCommissionView)) !== null) {
            $markets = $markets === null ? $commission : array_values(array_intersect($markets, $commission));
        }
        $location = isset($input['restaurant']) ? $this->locationOf($input['restaurant']) : null;
        if ($location !== null) {
            $this->authorizeFor(Permission::AdminFinanceView, $location->market);
        }
        $rows = $this->reports->commission($markets, $from, $to, $group, $timezone, $location?->getKey());

        return response()->json(['period' => $period, 'group_by' => $group, 'rows' => array_map(fn (array $r): array => RestaurantFinance::moneyRow($r), $rows)]);
    }

    public function refundImpactReport(Request $request): JsonResponse
    {
        $input = $request->validate(['market' => ['sometimes', 'string', 'max:40']]);
        [$from, $to, $period] = $this->period($request, $this->reportingTimezone($input['market'] ?? null));

        return response()->json([
            'period' => $period,
            'rows' => array_map(fn (array $r): array => RestaurantFinance::moneyRow($r), $this->reports->refundImpact($this->narrow($request, $input['market'] ?? null), $from, $to)),
            'liability_policy' => ['default' => (string) config('finance.refund_liability.default'), 'configured_reasons' => array_keys((array) config('finance.refund_liability.by_reason')), 'approval' => (string) config('finance.refund_liability.approval')],
        ]);
    }

    /**
     * Provider processing costs. Provider records carry no market: the report needs the permission for all markets.
     */
    public function providerCostReport(Request $request): JsonResponse
    {
        if ($this->marketIds($request, Permission::AdminFinanceView) !== null) {
            throw new ApiException(403, 'forbidden', 'Provider costs are not split by market: this report needs the permission for all markets.');
        }
        $timezone = $this->reportingTimezone();
        [$from, $to, $period] = $this->period($request, $timezone);
        $costs = $this->reports->providerCosts($from, $to, $timezone);

        return response()->json(['period' => $period, 'source' => $costs['source'], 'estimated' => $costs['estimated'], 'policy' => (string) config('finance.provider_fees.policy'),
            'rows' => array_map(fn (array $r): array => RestaurantFinance::moneyRow($r), $costs['rows'])]);
    }

    /**
     * What the platform owes each restaurant location, per currency — from the ledger.
     */
    public function payables(Request $request): JsonResponse
    {
        $query = $this->scoped($request, LedgerAccount::query()->with('location.organization', 'location.market')->where('code', LedgerAccountCode::RestaurantPayable->value)
            ->join('restaurant_locations as l', 'l.id', '=', 'ledger_accounts.location_id')->select('ledger_accounts.*'), Permission::AdminFinanceView, 'l.market_id');
        $list = new ListQuery($request, filterable: ['restaurant', 'market', 'currency', 'q'], sortable: ['name'], defaultSort: 'name', handlers: [
            'restaurant' => fn (Builder $q, string $v) => $q->where('l.public_id', Str::isUuid($v) ? $v : null),
            'market' => fn (Builder $q, string $v) => $q->whereIn('l.market_id', Market::query()->where('slug', $v)->select('id')),
            'currency' => fn (Builder $q, string $v) => $q->where('ledger_accounts.currency', strtoupper($v)),
            'q' => fn (Builder $q, string $v) => $q->where('l.name', 'ilike', '%'.addcslashes($v, '%_\\').'%'),
        ]);
        $page = $list->paginate($query->orderBy('ledger_accounts.currency'));
        $ids = $page->getCollection()->pluck('location_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $standing = $this->earnings->standing($ids);
        $balances = $this->earnings->balances($ids);
        $held = FinancialHold::query()->where('status', FinancialHold::STATUS_ACTIVE)->where('scope', FinancialHold::SCOPE_LOCATION)->whereIn('location_id', $ids)->pluck('location_id')->flip();
        $profiles = RestaurantPayoutProfile::query()->whereIn('location_id', $ids)->orderBy('id')->get()->keyBy('location_id');

        return JsonResource::collection($page->through(function (LedgerAccount $a) use ($standing, $balances, $held, $profiles): array {
            $c = (string) $a->currency;
            $s = $standing[$a->location_id.':'.$c] ?? ['available_minor' => 0, 'held_minor' => 0, 'in_settlement_minor' => 0, 'open_lines' => 0, 'paid_out_minor' => 0];
            $balance = (int) ($balances[$c][(int) $a->location_id] ?? 0);

            return [
                'restaurant' => ['id' => (string) $a->location->public_id, 'name' => (string) $a->location->name, 'organization' => (string) $a->location->organization->display_name],
                'market' => $a->location->market?->slug,
                'currency' => $c,
                'balance' => FinancePresenter::money($balance, $c),
                'available' => FinancePresenter::money($s['available_minor'], $c),
                'held' => FinancePresenter::money($s['held_minor'], $c),
                'in_settlement' => FinancePresenter::money($s['in_settlement_minor'], $c),
                'paid_out' => FinancePresenter::money($s['paid_out_minor'], $c),
                'open_lines' => $s['open_lines'],
                'location_held' => isset($held[$a->location_id]),
                // The restaurant owes the platform: nothing is collected automatically.
                'negative' => $balance < 0,
                'finance_review_required' => $balance < 0,
                'payout_destination' => $this->presenter->payoutProfile($profiles[$a->location_id] ?? null),
            ];
        }))->response();
    }

    public function allocations(Request $request): JsonResponse
    {
        $query = $this->scoped($request, OrderFinancialAllocation::query()->with(['location', 'market']), Permission::AdminFinanceView);
        $list = new ListQuery($request, filterable: ['status', 'restaurant', 'market', 'order', 'from', 'to'], sortable: ['placed_at', 'recognized_at'], defaultSort: '-placed_at', handlers: [
            'status' => fn (Builder $q, string $v) => $q->where('status', AllocationStatus::tryFrom(strtoupper($v))?->value ?? '-'),
            'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
            'order' => fn (Builder $q, string $v) => $q->where('order_number', strtoupper($v)),
            'from' => fn (Builder $q, string $v) => $q->where('placed_at', '>=', self::day($v)),
            'to' => fn (Builder $q, string $v) => $q->where('placed_at', '<', self::day($v)->addDay()),
        ]);
        // What a commission is made of is commercial detail: without the commission permission the terms are left out.
        $terms = $this->admin($request)->can(Permission::AdminFinanceCommissionView->value);

        return JsonResource::collection($list->paginate($query)->through(function (OrderFinancialAllocation $a) use ($terms): array {
            $shown = $this->presenter->allocation($a);
            if (! $terms && $shown['commission'] !== null) {
                $shown['commission'] = ['amount' => $shown['commission']['amount']];
            }

            return $shown;
        }))->response();
    }

    /**
     * A person decides the terms of ONE order that had none. The order's own amounts cannot be touched by it.
     */
    public function decideAllocation(Request $request, string $allocation): JsonResponse
    {
        $found = OrderFinancialAllocation::query()->with(['location', 'market'])->where('public_id', $allocation)->first() ?? throw ApiException::notFound('allocation_not_found', 'This allocation does not exist.');
        $this->authorizeFor(Permission::AdminFinanceAdjustmentsCreate, $found->market);
        $input = $request->validate([
            'rate_bps' => ['required', 'integer', 'between:0,10000'],
            'base_policy' => ['required', 'string', Rule::in(array_map(fn (CommissionBasePolicy $p): string => $p->value, CommissionBasePolicy::cases()))],
            'restaurant_discount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            ...self::prohibited(['customer_total_minor', 'merchandise_gross_minor', 'discount_minor', 'tax_minor', 'fee_minor', 'currency']),
        ], self::PROHIBITED_MESSAGE);
        $decided = $this->allocations->decide($found, $this->admin($request), ['rate_bps' => (int) $input['rate_bps'], 'base_policy' => (string) $input['base_policy'], 'restaurant_discount_minor' => $input['restaurant_discount_minor'] ?? null], (string) $input['reason']);

        return response()->json(['allocation' => $this->presenter->allocation($decided->load(['location', 'market']))]);
    }

    /**
     * The record of finance jobs: when the ledger was last checked, what a reconciliation found.
     */
    public function runs(Request $request): JsonResponse
    {
        $this->marketsWith($request, Permission::AdminFinanceView);
        $list = new ListQuery($request, filterable: ['kind', 'status'], sortable: ['started_at'], defaultSort: '-started_at');

        return JsonResource::collection($list->paginate(FinanceRun::query())->through(fn (FinanceRun $r): array => $this->presenter->run($r)))->response();
    }

    public function system(FinanceHealthService $health): JsonResponse
    {
        return response()->json($health->report());
    }

    /**
     * The caller's markets, narrowed to one when a market is named (which must be one of theirs).
     *
     * @return list<int>|null
     */
    private function narrow(Request $request, ?string $slug): ?array
    {
        $ids = $this->marketIds($request, Permission::AdminFinanceView);
        if ($slug === null) {
            return $ids;
        }
        $market = Market::query()->where('slug', $slug)->first() ?? throw ApiException::notFound('market_not_found', 'This market does not exist.');
        $this->authorizeFor(Permission::AdminFinanceView, $market);

        return [(int) $market->getKey()];
    }
}
