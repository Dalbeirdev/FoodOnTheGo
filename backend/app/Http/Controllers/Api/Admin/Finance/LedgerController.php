<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Http\Support\ListQuery;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The financial ledger, READ-ONLY (Module 36) — admin.finance.ledger.view.
 *
 * There is no route that writes the ledger: no POST, PUT, PATCH or DELETE exists under /admin/finance/ledger, and no
 * request anywhere can name an account, a direction or an entry. Postings are made only by the domain services, from
 * the financial events themselves. A list is always paged; the ledger is never sent whole.
 */
class LedgerController extends Controller
{
    use FinanceAccess;

    public function __construct(private readonly FinancePresenter $presenter) {}

    /**
     * The accounts with their balances. The platform's and the providers' accounts have no market: they need the
     * permission for all markets; a restaurant's payable account belongs to its location's market.
     */
    public function accounts(Request $request): JsonResponse
    {
        $ids = $this->marketIds($request, Permission::AdminFinanceLedgerView);
        $query = LedgerAccount::query()->with('location')->leftJoin('restaurant_locations as l', 'l.id', '=', 'ledger_accounts.location_id')->select('ledger_accounts.*')
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('l.market_id', $ids));
        $list = new ListQuery($request, filterable: ['code', 'currency', 'owner', 'restaurant'], sortable: ['code'], defaultSort: 'code', handlers: [
            'code' => fn (Builder $q, string $v) => $q->where('ledger_accounts.code', LedgerAccountCode::tryFrom(strtoupper($v))?->value ?? '-'),
            'currency' => fn (Builder $q, string $v) => $q->where('ledger_accounts.currency', strtoupper($v)),
            'owner' => fn (Builder $q, string $v) => $q->where('ledger_accounts.owner_type', strtoupper($v)),
            'restaurant' => fn (Builder $q, string $v) => $q->where('l.public_id', Str::isUuid($v) ? $v : null),
        ]);
        $page = $list->paginate($query->orderBy('ledger_accounts.currency')->orderBy('ledger_accounts.id'));
        $sums = DB::table('ledger_entries')->whereIn('account_id', $page->getCollection()->modelKeys())
            ->selectRaw("account_id, coalesce(sum(case direction when 'D' then amount_minor else 0 end), 0) as debits, coalesce(sum(case direction when 'C' then amount_minor else 0 end), 0) as credits")->groupBy('account_id')->get()->keyBy('account_id');

        return JsonResource::collection($page->through(fn (LedgerAccount $a): array => $this->presenter->account($a, (int) ($sums[$a->getKey()]->debits ?? 0), (int) ($sums[$a->getKey()]->credits ?? 0))))->response();
    }

    public function transactions(Request $request): JsonResponse
    {
        $query = $this->scoped($request, LedgerTransaction::query()->with(['market', 'location', 'reverses']), Permission::AdminFinanceLedgerView);
        $list = new ListQuery($request, filterable: ['type', 'source', 'reference', 'restaurant', 'market', 'currency', 'account', 'from', 'to'], sortable: ['occurred_at', 'posted_at', 'amount_minor'], defaultSort: '-occurred_at', handlers: [
            'type' => fn (Builder $q, string $v) => $q->where('type', LedgerTransactionType::tryFrom(strtoupper($v))?->value ?? '-'),
            'source' => fn (Builder $q, string $v) => $q->where('source_type', strtolower($v)),
            // An order number, a provider reference — or the public id of the payment, order, refund or payout.
            'reference' => fn (Builder $q, string $v) => $q->where(fn (Builder $w) => $w->where('source_reference', $v)->when(Str::isUuid($v), fn (Builder $u) => $u->orWhere('source_public_id', $v)->orWhere('public_id', $v))),
            'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
            'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
            'currency' => fn (Builder $q, string $v) => $q->where('currency', strtoupper($v)),
            'account' => fn (Builder $q, string $v) => $q->whereIn('id', DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')->where('a.public_id', Str::isUuid($v) ? $v : null)->select('e.ledger_transaction_id')),
            'from' => fn (Builder $q, string $v) => $q->where('occurred_at', '>=', self::day($v)),
            'to' => fn (Builder $q, string $v) => $q->where('occurred_at', '<', self::day($v)->addDay()),
        ]);

        return JsonResource::collection($list->paginate($query)->through(fn (LedgerTransaction $t): array => $this->presenter->transaction($t)))->response();
    }

    public function transaction(Request $request, string $ledgerTransaction): JsonResponse
    {
        $found = LedgerTransaction::query()->with(['market', 'location', 'reverses', 'entries.account'])->where('public_id', $ledgerTransaction)->first()
            ?? throw ApiException::notFound('ledger_transaction_not_found', 'This ledger transaction does not exist.');
        $found->market === null ? Gate::authorize(Permission::AdminFinanceLedgerView->value) : $this->authorizeMarket(Permission::AdminFinanceLedgerView, $found->market);

        return response()->json(['transaction' => [...$this->presenter->transaction($found, true), 'balanced' => $found->entries->where('direction', 'D')->sum('amount_minor') === $found->entries->where('direction', 'C')->sum('amount_minor')]]);
    }
}
