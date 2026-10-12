<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Enums\DifferenceStatus;
use App\Enums\DifferenceType;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Http\Support\ListQuery;
use App\Models\FinanceRun;
use App\Models\Market;
use App\Models\ProviderSettlement;
use App\Models\ProviderSettlementItem;
use App\Models\ReconciliationDifference;
use App\Services\Finance\DifferenceService;
use App\Services\Finance\InternalReconciliationService;
use App\Services\Finance\LedgerIntegrityService;
use App\Services\Finance\ProviderSettlement\ProviderSettlementReconciliationService;
use App\Services\Finance\ProviderSettlement\ProviderSettlementSources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reconciliation for administrators (Module 36): the payment provider's settlement records, and every place where
 * two financial records that should agree do not.
 *
 *   read                                        admin.finance.reconciliation.view
 *   import, run a check, close a difference      admin.finance.reconciliation.manage
 *
 * A PROVIDER settlement is what the payment provider settled to the platform — it is not a restaurant settlement.
 * Provider records carry no market, so they need the permission for ALL markets; a difference that belongs to a
 * market is visible to that market's administrators.
 *
 * Nothing here changes a provider record, a match or a reconciliation result by hand (the fields are refused). A
 * difference is closed with a resolution and a note, and that is audited; a critical one cannot be ignored.
 */
class ReconciliationController extends Controller
{
    use FinanceAccess;

    private const DIFFERENCE_WITH = ['market', 'location', 'assignedTo', 'resolvedBy'];

    public function __construct(
        private readonly ProviderSettlementReconciliationService $reconciliation,
        private readonly ProviderSettlementSources $sources,
        private readonly DifferenceService $differences,
        private readonly FinancePresenter $presenter,
    ) {}

    /* ------------------------------------------------------------------ provider settlements */

    public function providerSettlements(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdminFinanceReconciliationView->value);
        $list = new ListQuery($request, filterable: ['provider', 'status', 'reconciliation', 'reference', 'from', 'to'], sortable: ['settled_at', 'provider_created_at', 'amount_minor', 'imported_at'], defaultSort: '-provider_created_at', handlers: [
            'provider' => fn (Builder $q, string $v) => $q->where('provider', strtolower($v)),
            'status' => fn (Builder $q, string $v) => $q->where('status', strtoupper($v)),
            'reconciliation' => fn (Builder $q, string $v) => $q->where('reconciliation_status', strtoupper($v)),
            'reference' => fn (Builder $q, string $v) => $q->where('provider_settlement_reference', $v),
            'from' => fn (Builder $q, string $v) => $q->where('provider_created_at', '>=', self::day($v)),
            'to' => fn (Builder $q, string $v) => $q->where('provider_created_at', '<', self::day($v)->addDay()),
        ]);
        $response = JsonResource::collection($list->paginate(ProviderSettlement::query())->through(fn (ProviderSettlement $s): array => $this->presenter->providerSettlement($s)))->response()->getData(true);
        $response['source'] = $this->sources->report();
        $response['last_import'] = ($last = FinanceRun::query()->where('kind', FinanceRun::PROVIDER_IMPORT)->orderByDesc('id')->first()) === null ? null : $this->presenter->run($last);

        return response()->json($response);
    }

    public function providerSettlement(string $providerSettlement): JsonResponse
    {
        Gate::authorize(Permission::AdminFinanceReconciliationView->value);
        $found = ProviderSettlement::query()->where('public_id', $providerSettlement)->first() ?? throw ApiException::notFound('provider_settlement_not_found', 'This provider settlement does not exist.');
        $items = ProviderSettlementItem::query()->with(['paymentAttempt', 'refund'])->where('provider_settlement_id', $found->getKey())->orderBy('id')->limit(1000)->get();

        return response()->json(['provider_settlement' => [
            ...$this->presenter->providerSettlement($found),
            'items' => $items->map(fn (ProviderSettlementItem $i): array => $this->presenter->providerSettlementItem($i))->all(),
            'items_truncated' => (int) $found->item_count > $items->count(),
        ]]);
    }

    /**
     * Imports the provider's settlement records of one day and reconciles them.
     */
    public function import(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdminFinanceReconciliationManage->value);
        $input = $request->validate(['day' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], ...self::prohibited(['items', 'amount', 'amount_minor', 'fee', 'fee_minor', 'tax', 'provider'])], self::PROHIBITED_MESSAGE);
        $run = $this->reconciliation->import(self::day((string) $input['day']), $this->admin($request));

        return response()->json(['run' => $this->presenter->run($run)], 201);
    }

    /* ------------------------------------------------------------------ checks */

    /**
     * Runs a check now: the ledger's integrity, the internal reconciliation, or the provider comparison of what is
     * already imported. A check reads and reports; it repairs nothing.
     */
    public function run(Request $request, LedgerIntegrityService $integrity, InternalReconciliationService $internal): JsonResponse
    {
        Gate::authorize(Permission::AdminFinanceReconciliationManage->value);
        $input = $request->validate(['check' => ['required', 'string', Rule::in(['LEDGER', 'INTERNAL', 'PROVIDER'])]]);
        $admin = $this->admin($request);
        if ($input['check'] === 'PROVIDER') {
            $source = $this->sources->current() ?? throw ApiException::conflict('provider_settlements_unavailable', 'No provider settlement records can be read here.');

            return response()->json(['check' => 'PROVIDER', 'result' => $this->reconciliation->reconcile($source->name())]);
        }
        $run = $input['check'] === 'LEDGER' ? $integrity->verify(FinanceRun::BY_ADMIN, (int) $admin->getKey()) : $internal->run(FinanceRun::BY_ADMIN, (int) $admin->getKey());

        return response()->json(['check' => $input['check'], 'run' => $this->presenter->run($run)]);
    }

    /* ------------------------------------------------------------------ differences */

    public function differences(Request $request): JsonResponse
    {
        $query = $this->visibleDifferences($request)->with(self::DIFFERENCE_WITH);
        $list = new ListQuery($request, filterable: ['status', 'open', 'type', 'severity', 'source', 'provider', 'market'], sortable: ['first_seen_at', 'last_seen_at', 'severity', 'difference_minor'], defaultSort: '-first_seen_at', handlers: [
            'status' => fn (Builder $q, string $v) => $q->where('status', DifferenceStatus::tryFrom(strtoupper($v))?->value ?? '-'),
            'open' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true) ? $q->unresolved() : $q->whereNotIn('status', [DifferenceStatus::Open->value, DifferenceStatus::Investigating->value]),
            'type' => fn (Builder $q, string $v) => $q->where('type', DifferenceType::tryFrom(strtoupper($v))?->value ?? '-'),
            'severity' => fn (Builder $q, string $v) => $q->where('severity', strtoupper($v)),
            'source' => fn (Builder $q, string $v) => $q->where('source', strtoupper($v)),
            'provider' => fn (Builder $q, string $v) => $q->where('provider', strtolower($v)),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
        ]);
        $response = JsonResource::collection($list->paginate($query)->through(fn (ReconciliationDifference $d): array => $this->presenter->difference($d)))->response()->getData(true);
        $open = $this->visibleDifferences($request)->unresolved()->toBase()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->map(fn ($n): int => (int) $n);
        $response['open'] = ['total' => (int) $open->sum(), 'by_severity' => (object) $open->all()];

        return response()->json($response);
    }

    public function difference(Request $request, string $difference): JsonResponse
    {
        return $this->answer($request, $this->findDifference($difference, Permission::AdminFinanceReconciliationView));
    }

    public function investigate(Request $request, string $difference): JsonResponse
    {
        $found = $this->findDifference($difference, Permission::AdminFinanceReconciliationManage);
        $input = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500'], ...self::prohibited(['resolution', 'expected_minor', 'actual_minor', 'difference_minor', 'type', 'severity'])], self::PROHIBITED_MESSAGE);
        $this->differences->investigate($found, $this->admin($request), $input['note'] ?? null);

        return $this->answer($request, $this->findDifference($difference, Permission::AdminFinanceReconciliationView));
    }

    public function resolve(Request $request, string $difference): JsonResponse
    {
        $found = $this->findDifference($difference, Permission::AdminFinanceReconciliationManage);
        $input = $request->validate([
            'resolution' => ['required', 'string', Rule::in(ReconciliationDifference::RESOLUTIONS)],
            'note' => ['required', 'string', 'min:10', 'max:500'],
            ...self::prohibited(['expected_minor', 'actual_minor', 'difference_minor', 'type', 'severity']),
        ], self::PROHIBITED_MESSAGE);
        $this->differences->resolve($found, $this->admin($request), (string) $input['resolution'], (string) $input['note']);

        return $this->answer($request, $this->findDifference($difference, Permission::AdminFinanceReconciliationView));
    }

    public function ignore(Request $request, string $difference): JsonResponse
    {
        $found = $this->findDifference($difference, Permission::AdminFinanceReconciliationManage);
        $input = $request->validate(['note' => ['required', 'string', 'min:10', 'max:500'], ...self::prohibited(['resolution', 'expected_minor', 'actual_minor', 'difference_minor', 'type', 'severity'])], self::PROHIBITED_MESSAGE);
        $this->differences->ignore($found, $this->admin($request), (string) $input['note']);

        return $this->answer($request, $this->findDifference($difference, Permission::AdminFinanceReconciliationView));
    }

    /**
     * Differences of the caller's markets; those without a market only for a caller who holds the permission for all.
     *
     * @return Builder<ReconciliationDifference>
     */
    private function visibleDifferences(Request $request): Builder
    {
        $ids = $this->marketIds($request, Permission::AdminFinanceReconciliationView);

        return ReconciliationDifference::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('market_id', $ids));
    }

    private function answer(Request $request, ReconciliationDifference $difference): JsonResponse
    {
        $can = $this->allowed($request, Permission::AdminFinanceReconciliationManage, $difference->market);
        $open = $difference->status->isOpen();

        return response()->json(['difference' => [
            ...$this->presenter->difference($difference, true),
            'can' => ['investigate' => $can && $difference->status === DifferenceStatus::Open, 'resolve' => $can && $open, 'ignore' => $can && $open && $difference->severity !== 'CRITICAL'],
            'resolutions' => ReconciliationDifference::RESOLUTIONS,
        ]]);
    }

    private function findDifference(string $difference, Permission $permission): ReconciliationDifference
    {
        $found = ReconciliationDifference::query()->with(self::DIFFERENCE_WITH)->where('public_id', $difference)->first() ?? throw ApiException::notFound('difference_not_found', 'This difference does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }
}
