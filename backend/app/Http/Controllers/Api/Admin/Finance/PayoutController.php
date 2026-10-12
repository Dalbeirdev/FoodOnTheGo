<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Http\Support\ListQuery;
use App\Models\Market;
use App\Models\RestaurantPayout;
use App\Models\RestaurantSettlement;
use App\Services\Finance\Payout\PayoutProviderManager;
use App\Services\Finance\Payout\PayoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Restaurant payouts for administrators (Module 36). LIVE PAYOUT = DISABLED.
 *
 *   read                                   admin.finance.payouts.view
 *   start a payout, send again, ask again,
 *   withdraw one that was never sent        admin.finance.payouts.execute
 *   record a payout made outside            admin.finance.payouts.record_external
 *
 * A payout's amount is its settlement's — no request carries an amount that is used (the external record states one
 * only to CONFIRM it, and is refused when it differs). Nothing sets PAID by hand: a provider payout is paid on the
 * provider's own record of it, an external one by its bank reference, day and evidence. There is no "mark as paid"
 * route. Every action that reaches the provider must be confirmed ("confirm": true) and is audited.
 */
class PayoutController extends Controller
{
    use FinanceAccess;

    private const WITH = ['settlement', 'location', 'market', 'requestedBy'];

    private const QUEUES = [
        'required' => ['REQUIRED'], 'processing' => ['SUBMITTING', 'PROCESSING'], 'unknown' => ['UNKNOWN'], 'review_required' => ['REVIEW_REQUIRED'],
        'paid' => ['PAID'], 'failed' => ['FAILED'], 'cancelled' => ['CANCELLED'],
    ];

    public function __construct(private readonly PayoutService $payouts, private readonly FinancePresenter $presenter) {}

    public function index(Request $request, PayoutProviderManager $providers): JsonResponse
    {
        $query = $this->scoped($request, RestaurantPayout::query()->with(self::WITH), Permission::AdminFinancePayoutsView);
        $list = new ListQuery($request, filterable: ['status', 'queue', 'method', 'restaurant', 'market', 'review', 'from', 'to'], sortable: ['requested_at', 'completed_at', 'amount_minor', 'status'], defaultSort: '-requested_at', handlers: [
            'status' => fn (Builder $q, string $v) => $q->where('status', PayoutStatus::tryFrom(strtoupper($v))?->value ?? '-'),
            'queue' => fn (Builder $q, string $v) => $q->whereIn('status', self::QUEUES[strtolower($v)] ?? ['-']),
            'method' => fn (Builder $q, string $v) => $q->where('method', strtoupper($v)),
            'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
            'review' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true) ? $q->whereIn('status', [PayoutStatus::ReviewRequired->value, PayoutStatus::Unknown->value, PayoutStatus::Failed->value]) : $q,
            'from' => fn (Builder $q, string $v) => $q->where('requested_at', '>=', self::day($v)),
            'to' => fn (Builder $q, string $v) => $q->where('requested_at', '<', self::day($v)->addDay()),
        ]);
        $response = JsonResource::collection($list->paginate($query)->through(fn (RestaurantPayout $p): array => $this->presenter->payout($p, true)))->response()->getData(true);
        $byStatus = $this->scoped($request, RestaurantPayout::query(), Permission::AdminFinancePayoutsView)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $response['queues'] = array_map(fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s): int => (int) ($byStatus[$s] ?? 0)), self::QUEUES);
        $report = $providers->report();
        $response['provider'] = ['status' => $report['status'], 'driver' => $report['driver'], 'mode' => $report['mode'], 'live_payout' => 'DISABLED', 'real_adapter' => $report['real_adapter'], 'provider_account' => $report['provider_account']];

        return response()->json($response);
    }

    public function show(Request $request, string $payout): JsonResponse
    {
        return $this->answer($request, $this->find($payout, Permission::AdminFinancePayoutsView));
    }

    /**
     * Makes the payout of an approved settlement and sends it to the payout provider.
     */
    public function start(Request $request, string $settlement): JsonResponse
    {
        $found = $this->settlement($settlement, Permission::AdminFinancePayoutsExecute);
        $input = $request->validate([
            'confirm' => ['required', 'accepted'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            ...self::prohibited(['amount', 'amount_minor', 'currency', 'destination', 'account', 'provider', 'method']),
        ], [...self::PROHIBITED_MESSAGE, 'confirm.accepted' => 'A payout has to be confirmed.', 'confirm.required' => 'A payout has to be confirmed.']);
        $payout = $this->payouts->start($found, $this->admin($request), $input['note'] ?? null);

        return $this->answer($request, $this->find((string) $payout->public_id, Permission::AdminFinancePayoutsView), 201);
    }

    /**
     * Records a payout that was made outside the platform, with what proves it.
     */
    public function recordExternal(Request $request, string $settlement): JsonResponse
    {
        $found = $this->settlement($settlement, Permission::AdminFinancePayoutsRecordExternal);
        $input = $request->validate([
            'confirm' => ['required', 'accepted'],
            'external_reference' => ['required', 'string', 'min:4', 'max:80', 'regex:/\A[A-Za-z0-9][A-Za-z0-9 ._\/-]*\z/'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            // Stated to be CONFIRMED against the settlement — not to choose an amount.
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'evidence_reference' => ['required', 'string', 'min:4', 'max:160'],
            ...self::prohibited(['provider', 'method', 'destination', 'account', 'bank_account', 'account_number', 'ifsc', 'upi']),
        ], [...self::PROHIBITED_MESSAGE, 'confirm.accepted' => 'Recording a payout has to be confirmed.', 'confirm.required' => 'Recording a payout has to be confirmed.']);
        $payout = $this->payouts->recordExternal($found, $this->admin($request), [
            'external_reference' => (string) $input['external_reference'], 'paid_on' => (string) $input['paid_on'], 'amount_minor' => (int) $input['amount_minor'], 'currency' => strtoupper((string) $input['currency']),
            'reason' => (string) $input['reason'], 'evidence_reference' => (string) $input['evidence_reference'],
        ]);

        return $this->answer($request, $this->find((string) $payout->public_id, Permission::AdminFinancePayoutsView), 201);
    }

    /**
     * Asks the provider again. Whatever comes of it is the provider's word, not the administrator's.
     */
    public function reconcile(Request $request, string $payout): JsonResponse
    {
        $found = $this->find($payout, Permission::AdminFinancePayoutsExecute);
        $input = $this->decision($request);
        $this->payouts->reconcile($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($payout, Permission::AdminFinancePayoutsView));
    }

    public function retry(Request $request, string $payout): JsonResponse
    {
        $found = $this->find($payout, Permission::AdminFinancePayoutsExecute);
        $input = $this->decision($request, confirm: true);
        $this->payouts->retry($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($payout, Permission::AdminFinancePayoutsView));
    }

    public function cancel(Request $request, string $payout): JsonResponse
    {
        $found = $this->find($payout, Permission::AdminFinancePayoutsExecute);
        $input = $this->decision($request);
        $this->payouts->cancel($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($payout, Permission::AdminFinancePayoutsView));
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(Request $request, bool $confirm = false): array
    {
        return $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            ...($confirm ? ['confirm' => ['required', 'accepted']] : []),
            // Nothing about a payout can be set by hand — not its state, its amount or its provider record.
            ...self::prohibited(['amount', 'amount_minor', 'currency', 'destination', 'provider', 'method', 'external_reference']),
        ], ['prohibited' => 'The :attribute field cannot be set by hand.', 'confirm.accepted' => 'This has to be confirmed.', 'confirm.required' => 'This has to be confirmed.']);
    }

    private function answer(Request $request, RestaurantPayout $payout, int $status = 200): JsonResponse
    {
        $can = $this->allowed($request, Permission::AdminFinancePayoutsExecute, $payout->market);
        $provider = $payout->method === RestaurantPayout::METHOD_PROVIDER;

        return response()->json(['payout' => [
            ...$this->presenter->payout($payout, true),
            'can' => [
                'reconcile' => $can && $provider && ! in_array($payout->status, [PayoutStatus::Required, PayoutStatus::Cancelled], true),
                'retry' => $can && $provider && $payout->status === PayoutStatus::ReviewRequired && in_array($payout->review_reason, ['PROVIDER_HAS_NO_RECORD', 'DESTINATION_NOT_VERIFIED', 'LOCATION_HELD'], true),
                'cancel' => $can && $provider && $payout->status === PayoutStatus::Required,
            ],
        ]], $status);
    }

    private function find(string $payout, Permission $permission): RestaurantPayout
    {
        $found = RestaurantPayout::query()->with(self::WITH)->where('public_id', $payout)->first() ?? throw ApiException::notFound('payout_not_found', 'This payout does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }

    private function settlement(string $settlement, Permission $permission): RestaurantSettlement
    {
        $found = RestaurantSettlement::query()->with('market')->where('public_id', $settlement)->first() ?? throw ApiException::notFound('settlement_not_found', 'This settlement does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }
}
