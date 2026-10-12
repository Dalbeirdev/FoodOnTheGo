<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Enums\Permission;
use App\Enums\SettlementStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Http\Support\ListQuery;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayout;
use App\Models\RestaurantSettlement;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\Payout\PayoutProviderManager;
use App\Services\Finance\SettlementCalculationService;
use App\Services\Finance\SettlementService;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restaurant settlements for administrators (Module 36).
 *
 *   read                admin.settlements.view
 *   generate, withdraw  admin.finance.settlements.manage   — a calculation; it sends no money
 *   approve             admin.finance.settlements.approve  — still sends no money
 *
 * NOTHING HERE SETS A TOTAL, A LINE OR "PAID": totals are the sums of the lines, lines are immutable, and a
 * settlement is PAID only through a payout's confirmation (PayoutController). There is no "mark as paid" — the
 * routes do not exist and the fields are refused when they are sent.
 */
class SettlementController extends Controller
{
    use FinanceAccess;

    private const WITH = ['location', 'market', 'organization', 'generatedBy', 'approvedBy', 'cancelledBy'];

    private const QUEUES = [
        'calculated' => ['CALCULATED'], 'review_required' => ['REVIEW_REQUIRED'], 'approved' => ['APPROVED'], 'payment_pending' => ['PAYMENT_PENDING'],
        'paid' => ['PAID'], 'failed' => ['FAILED'], 'closed' => ['CLOSED'], 'cancelled' => ['CANCELLED'],
    ];

    public function __construct(
        private readonly SettlementCalculationService $calculation,
        private readonly SettlementService $settlements,
        private readonly FinancePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request, RestaurantSettlement::query()->with(self::WITH), Permission::AdminSettlementsView);
        $list = new ListQuery($request, filterable: ['status', 'queue', 'restaurant', 'market', 'currency', 'reference', 'from', 'to'], sortable: ['period_end', 'generated_at', 'net_payable_minor', 'status'], defaultSort: '-period_end', handlers: [
            'status' => fn (Builder $q, string $v) => $q->where('status', SettlementStatus::tryFrom(strtoupper($v))?->value ?? '-'),
            'queue' => fn (Builder $q, string $v) => $q->whereIn('status', self::QUEUES[strtolower($v)] ?? ['-']),
            'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
            'currency' => fn (Builder $q, string $v) => $q->where('currency', strtoupper($v)),
            'reference' => fn (Builder $q, string $v) => $q->where('reference', strtoupper($v)),
            'from' => fn (Builder $q, string $v) => $q->where('period_end', '>=', self::day($v)),
            'to' => fn (Builder $q, string $v) => $q->where('period_end', '<', self::day($v)->addDays(2)),
        ]);
        $response = JsonResource::collection($list->paginate($query)->through(fn (RestaurantSettlement $s): array => $this->presenter->settlement($s, true)))->response()->getData(true);
        $byStatus = $this->scoped($request, RestaurantSettlement::query(), Permission::AdminSettlementsView)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $response['queues'] = array_map(fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s): int => (int) ($byStatus[$s] ?? 0)), self::QUEUES);

        return response()->json($response);
    }

    public function show(Request $request, string $settlement): JsonResponse
    {
        return $this->answer($request, $this->find($settlement, Permission::AdminSettlementsView));
    }

    /**
     * Calculates the settlements that are due — for one restaurant, for a market, or for everything the caller may
     * see. With dry_run nothing is written: the calculation is the answer.
     */
    public function generate(Request $request): JsonResponse
    {
        $this->marketsWith($request, Permission::AdminFinanceSettlementsManage);
        $input = $request->validate([
            'restaurant' => ['sometimes', 'nullable', 'string', 'uuid'],
            'market' => ['sometimes', 'nullable', 'string', 'max:40'],
            // The cutoff as a day of the market's calendar: lines that became available before that day began.
            'until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dry_run' => ['sometimes', 'boolean'],
            ...self::prohibited(['amount', 'amount_minor', 'lines', 'currency']),
        ], self::PROHIBITED_MESSAGE);
        $locations = RestaurantLocation::query()->whereIn('id', fn ($q) => $q->select('location_id')->from('restaurant_payable_items')->where('status', 'AVAILABLE'));
        if (isset($input['restaurant'])) {
            $location = $this->locationOf($input['restaurant']);
            $this->authorizeFor(Permission::AdminFinanceSettlementsManage, $location->market);
            $locations->whereKey($location->getKey());
        } elseif (isset($input['market'])) {
            $market = Market::query()->where('slug', $input['market'])->first() ?? throw ApiException::notFound('market_not_found', 'This market does not exist.');
            $this->authorizeFor(Permission::AdminFinanceSettlementsManage, $market);
            $locations->where('market_id', $market->getKey());
        } else {
            $ids = $this->marketIds($request, Permission::AdminFinanceSettlementsManage);
            $locations->when($ids !== null, fn (Builder $q) => $q->whereIn('market_id', $ids));
        }
        $found = $locations->orderBy('id')->limit(1000)->get();
        $until = null;
        if (isset($input['until'])) {
            $until = self::day($input['until'], $found->isEmpty() ? 'UTC' : $this->calculation->timezoneOf($found->first()));
            if ($until->greaterThan(now())) {
                throw new ApiException(422, 'validation_failed', 'The cutoff is a day that has started.', ['fields' => ['until' => ['Must not be in the future.']]]);
            }
        }
        $dry = (bool) ($input['dry_run'] ?? false);
        $outcome = $this->calculation->generate($found, $until, $dry, $this->admin($request));

        return response()->json([
            'dry_run' => $dry,
            'summary' => $outcome['summary'],
            'results' => array_map(function (array $r): array {
                $r['net_payable'] = $r['net_payable_minor'] === null ? null : FinancePresenter::money((int) $r['net_payable_minor'], (string) $r['currency']);
                unset($r['net_payable_minor'], $r['totals']);

                return $r;
            }, $outcome['results']),
            'run' => $outcome['run'] === null ? null : $this->presenter->run($outcome['run']),
            'note' => 'A settlement is a statement of what is owed. Generating one sends no money.',
        ], $dry ? 200 : 201);
    }

    public function approve(Request $request, string $settlement): JsonResponse
    {
        $found = $this->find($settlement, Permission::AdminFinanceSettlementsApprove);
        $input = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...self::prohibited(['amount', 'amount_minor', 'currency', 'lines']),
        ], self::PROHIBITED_MESSAGE);
        $this->settlements->approve($found, $this->admin($request), $input['note'] ?? null, $input['expected_version'] ?? null);

        return $this->answer($request, $this->find($settlement, Permission::AdminSettlementsView));
    }

    public function cancel(Request $request, string $settlement): JsonResponse
    {
        $found = $this->find($settlement, Permission::AdminFinanceSettlementsManage);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], ...self::prohibited(['amount', 'amount_minor', 'currency', 'lines'])], self::PROHIBITED_MESSAGE);
        $this->settlements->cancel($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($settlement, Permission::AdminSettlementsView));
    }

    public function export(Request $request, string $settlement, AuditRecorder $audit, FinanceMetrics $metrics): Response
    {
        $found = $this->find($settlement, Permission::AdminFinanceExport);
        $this->authorizeFor(Permission::AdminSettlementsView, $found->market);
        $c = (string) $found->currency;
        $zone = (string) $found->timezone;
        $rows = collect([
            ['Settlement statement — this is not a tax invoice'], ['Reference', $found->reference], ['Restaurant', $found->restaurant_name], ['Currency', $c], ['Status', $found->status->value],
            ['Period end ('.$zone.')', CarbonImmutable::instance($found->period_end)->setTimezone($zone)->format('Y-m-d H:i')], ['Net payable', Csv::amount((int) $found->net_payable_minor, $c)], [],
            ['Line', 'Date ('.$zone.')', 'Type', 'Order number', 'Gross sales', 'Restaurant-funded discount', 'Commission', 'Net amount'],
        ])->concat($found->lines()->get()->map(fn ($l): array => [(int) $l->line_no, CarbonImmutable::instance($l->occurred_at)->setTimezone($zone)->format('Y-m-d H:i'), $l->type, $l->order_number,
            Csv::amount((int) $l->gross_minor, $c), Csv::amount((int) $l->restaurant_discount_minor, $c), Csv::amount((int) $l->commission_minor, $c), Csv::amount((int) $l->amount_minor, $c)]));
        $audit->record('finance.exported', $found, $this->admin($request), ['export' => ['from' => null, 'to' => 'settlement_statement']], null, $found->market_id);
        $metrics->record('export');

        return Csv::response('settlement-'.$found->reference.'.csv', ['FoodOnTheGo'], $rows);
    }

    private function answer(Request $request, RestaurantSettlement $settlement): JsonResponse
    {
        $market = $settlement->market;
        $alive = $this->settlements->alivePayout($settlement);
        $payable = in_array($settlement->status, SettlementStatus::payable(), true) && $alive === null && (int) $settlement->net_payable_minor > 0;
        $providerReady = app(PayoutProviderManager::class)->report()['status'] === 'FAKE_PROVIDER';

        return response()->json(['settlement' => [
            ...$this->presenter->settlement($settlement, true),
            'lines' => $settlement->lines()->get()->map(fn ($l): array => $this->presenter->settlementLine($l))->all(),
            'payouts' => $settlement->payouts()->with(['settlement', 'location', 'market', 'requestedBy'])->get()->map(fn (RestaurantPayout $p): array => $this->presenter->payout($p, true))->all(),
            'can' => [
                'approve' => $settlement->status === SettlementStatus::Calculated && $this->allowed($request, Permission::AdminFinanceSettlementsApprove, $market),
                'cancel' => in_array($settlement->status, SettlementStatus::cancellable(), true) && $alive === null && $this->allowed($request, Permission::AdminFinanceSettlementsManage, $market),
                'start_payout' => $payable && $providerReady && $this->allowed($request, Permission::AdminFinancePayoutsExecute, $market),
                'record_external_payout' => $payable && $this->allowed($request, Permission::AdminFinancePayoutsRecordExternal, $market),
                'export' => $this->allowed($request, Permission::AdminFinanceExport, $market) && $this->allowed($request, Permission::AdminSettlementsView, $market),
            ],
            // There is deliberately no way to mark a settlement as paid.
            'live_payouts' => 'DISABLED',
        ]]);
    }

    private function find(string $settlement, Permission $permission): RestaurantSettlement
    {
        $found = RestaurantSettlement::query()->with(self::WITH)->where('public_id', $settlement)->first() ?? throw ApiException::notFound('settlement_not_found', 'This settlement does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }
}
