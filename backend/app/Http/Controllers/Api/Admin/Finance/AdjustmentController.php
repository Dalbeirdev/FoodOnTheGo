<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Enums\HoldReason;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Http\Support\ListQuery;
use App\Models\FinancialAdjustment;
use App\Models\FinancialHold;
use App\Models\Market;
use App\Models\Order;
use App\Models\RestaurantPayableItem;
use App\Services\Finance\AdjustmentService;
use App\Services\Finance\HoldService;
use App\Services\Order\OrderStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Financial adjustments and holds for administrators (Module 36).
 *
 *   read adjustments and holds                 admin.finance.view
 *   decide who bears a refund or a dispute,
 *   post a manual adjustment, approve one      admin.finance.adjustments.create
 *   place and release a hold                   admin.finance.holds.manage
 *
 * THERE IS NO BALANCE TO EDIT. An adjustment is a record with a reason, a supporting reference and an audit event,
 * posted as ONE balanced ledger transaction together with a new payable line; nothing here names a ledger account, a
 * debit or a credit, and those fields are refused when they are sent. A hold moves no money at all.
 */
class AdjustmentController extends Controller
{
    use FinanceAccess;

    private const WITH = ['location', 'market', 'refund', 'dispute', 'createdBy', 'decidedBy', 'ledgerTransaction'];

    private const HOLD_WITH = ['location', 'market', 'payableItem', 'placedBy', 'releasedBy'];

    public function __construct(
        private readonly AdjustmentService $adjustments,
        private readonly HoldService $holds,
        private readonly FinancePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request, FinancialAdjustment::query()->with(self::WITH), Permission::AdminFinanceView);
        $list = new ListQuery($request, filterable: ['status', 'kind', 'restaurant', 'market', 'order'], sortable: ['created_at', 'posted_at', 'amount_minor'], defaultSort: '-created_at', handlers: [
            'status' => fn (Builder $q, string $v) => $q->where('status', strtoupper($v)),
            'kind' => fn (Builder $q, string $v) => $q->where('kind', strtoupper($v)),
            'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
            'order' => fn (Builder $q, string $v) => $q->where('order_number', strtoupper($v)),
        ]);
        $response = JsonResource::collection($list->paginate($query)->through(fn (FinancialAdjustment $a): array => $this->presenter->adjustment($a)))->response()->getData(true);
        $response['policy'] = [
            'refund_liability_default' => (string) config('finance.refund_liability.default'),
            'four_eyes' => (bool) config('finance.adjustments.four_eyes'),
            'reasons' => array_values((array) config('finance.adjustments.reasons')),
            'approval' => (string) config('finance.refund_liability.approval'),
        ];

        return response()->json($response);
    }

    public function show(Request $request, string $adjustment): JsonResponse
    {
        return $this->answer($request, $this->find($adjustment, Permission::AdminFinanceView));
    }

    /**
     * A manual correction of a restaurant's payable.
     */
    public function store(Request $request, OrderStore $orders): JsonResponse
    {
        // Who holds the permission nowhere is refused before anything of the request is looked at.
        $this->marketsWith($request, Permission::AdminFinanceAdjustmentsCreate);
        $input = $request->validate([
            'restaurant' => ['required', 'string', 'uuid'],
            'direction' => ['required', 'string', Rule::in([FinancialAdjustment::CREDIT_RESTAURANT, FinancialAdjustment::DEBIT_RESTAURANT])],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'currency' => ['required', 'string', 'size:3'],
            'reason_code' => ['required', 'string', Rule::in((array) config('finance.adjustments.reasons'))],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'supporting_reference' => ['required', 'string', 'min:4', 'max:160'],
            'order' => ['sometimes', 'nullable', 'string', 'max:40'],
            ...self::prohibited(['kind', 'liability', 'restaurant_share_minor', 'platform_share_minor', 'policy_source']),
        ], self::PROHIBITED_MESSAGE);
        $location = $this->locationOf($input['restaurant']);
        $this->authorizeFor(Permission::AdminFinanceAdjustmentsCreate, $location->market);
        $order = isset($input['order']) ? ($orders->byReference(Order::query(), (string) $input['order'])->first() ?? throw ApiException::notFound('order_not_found', 'This order does not exist.')) : null;
        $key = $request->header('Idempotency-Key');
        $adjustment = $this->adjustments->manual($location, $this->admin($request), [
            'direction' => (string) $input['direction'], 'amount_minor' => (int) $input['amount_minor'], 'currency' => strtoupper((string) $input['currency']), 'reason_code' => (string) $input['reason_code'],
            'reason' => (string) $input['reason'], 'supporting_reference' => (string) $input['supporting_reference'], 'order' => $order,
        ], is_string($key) && $key !== '' ? 'adj:'.$this->admin($request)->getKey().':'.substr(hash('sha256', $key), 0, 48) : null);

        return $this->answer($request, $this->find((string) $adjustment->public_id, Permission::AdminFinanceView), 201);
    }

    /**
     * Decides who bears a completed refund or a lost dispute: the restaurant's share, explicitly.
     */
    public function decide(Request $request, string $adjustment): JsonResponse
    {
        $found = $this->find($adjustment, Permission::AdminFinanceAdjustmentsCreate);
        $input = $request->validate([
            'restaurant_share_minor' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...self::prohibited(['amount', 'amount_minor', 'currency', 'platform_share_minor', 'liability', 'kind', 'direction']),
        ], self::PROHIBITED_MESSAGE);
        $this->adjustments->decide($found, $this->admin($request), (int) $input['restaurant_share_minor'], (string) $input['reason'], $input['expected_version'] ?? null);

        return $this->answer($request, $this->find($adjustment, Permission::AdminFinanceView));
    }

    public function approve(Request $request, string $adjustment): JsonResponse
    {
        $found = $this->find($adjustment, Permission::AdminFinanceAdjustmentsCreate);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], ...self::prohibited(['amount', 'amount_minor', 'currency', 'direction'])], self::PROHIBITED_MESSAGE);
        $this->adjustments->approve($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($adjustment, Permission::AdminFinanceView));
    }

    public function cancel(Request $request, string $adjustment): JsonResponse
    {
        $found = $this->find($adjustment, Permission::AdminFinanceAdjustmentsCreate);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], ...self::prohibited(['amount', 'amount_minor', 'currency', 'direction'])], self::PROHIBITED_MESSAGE);
        $this->adjustments->cancel($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($adjustment, Permission::AdminFinanceView));
    }

    /* ------------------------------------------------------------------ holds */

    public function holds(Request $request): JsonResponse
    {
        $query = $this->scoped($request, FinancialHold::query()->with(self::HOLD_WITH), Permission::AdminFinanceView);
        $list = new ListQuery($request, filterable: ['status', 'reason', 'scope', 'restaurant', 'market'], sortable: ['placed_at'], defaultSort: '-placed_at', handlers: [
            'status' => fn (Builder $q, string $v) => $q->where('status', strtoupper($v)),
            'reason' => fn (Builder $q, string $v) => $q->where('reason', strtoupper($v)),
            'scope' => fn (Builder $q, string $v) => $q->where('scope', strtoupper($v)),
            'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
        ]);

        return JsonResource::collection($list->paginate($query)->through(fn (FinancialHold $h): array => $this->presenter->hold($h)))->response();
    }

    public function placeHold(Request $request): JsonResponse
    {
        $this->marketsWith($request, Permission::AdminFinanceHoldsManage);
        $input = $request->validate([
            'restaurant' => ['required', 'string', 'uuid'],
            // Without a line the whole location is held; with one, only that payable line.
            'line' => ['sometimes', 'nullable', 'string', 'uuid'],
            'reason' => ['required', 'string', Rule::in(array_map(fn (HoldReason $r): string => $r->value, HoldReason::forPeople()))],
            'note' => ['required', 'string', 'min:10', 'max:500'],
            ...self::prohibited(['amount', 'amount_minor', 'scope', 'source']),
        ], self::PROHIBITED_MESSAGE);
        $location = $this->locationOf($input['restaurant']);
        $this->authorizeFor(Permission::AdminFinanceHoldsManage, $location->market);
        $item = isset($input['line']) ? (RestaurantPayableItem::query()->where('public_id', $input['line'])->where('location_id', $location->getKey())->first() ?? throw ApiException::notFound('line_not_found', 'This line does not exist.')) : null;
        $hold = $this->holds->place($location, $item, HoldReason::from((string) $input['reason']), (string) $input['note'], $this->admin($request));

        return response()->json(['hold' => $this->presenter->hold($hold->load(self::HOLD_WITH))], 201);
    }

    public function releaseHold(Request $request, string $hold): JsonResponse
    {
        $found = FinancialHold::query()->with(self::HOLD_WITH)->where('public_id', $hold)->first() ?? throw ApiException::notFound('hold_not_found', 'This hold does not exist.');
        $this->authorizeFor(Permission::AdminFinanceHoldsManage, $found->market);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $released = $this->holds->release($found, $this->admin($request), (string) $input['reason']);

        return response()->json(['hold' => $this->presenter->hold($released->load(self::HOLD_WITH))]);
    }

    private function answer(Request $request, FinancialAdjustment $adjustment, int $status = 200): JsonResponse
    {
        $can = $this->allowed($request, Permission::AdminFinanceAdjustmentsCreate, $adjustment->market);
        $waiting = $adjustment->status === FinancialAdjustment::STATUS_PENDING_APPROVAL;

        return response()->json(['adjustment' => [
            ...$this->presenter->adjustment($adjustment),
            'can' => [
                'decide' => $can && $adjustment->status === FinancialAdjustment::STATUS_PENDING_DECISION,
                'approve' => $can && $waiting && (int) $adjustment->created_by_admin_id !== (int) $this->admin($request)->getKey(),
                'cancel' => $can && $waiting,
            ],
        ]], $status);
    }

    private function find(string $adjustment, Permission $permission): FinancialAdjustment
    {
        $found = FinancialAdjustment::query()->with(self::WITH)->where('public_id', $adjustment)->first() ?? throw ApiException::notFound('adjustment_not_found', 'This adjustment does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }
}
