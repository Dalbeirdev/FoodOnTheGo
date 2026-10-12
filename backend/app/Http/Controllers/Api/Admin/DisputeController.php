<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DisputeStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Refund\RefundPresenter;
use App\Http\Support\ListQuery;
use App\Models\Market;
use App\Models\PaymentDispute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Payment disputes / chargebacks for administrators (Module 35 foundation). READ ONLY.
 *
 * A dispute is written here only by a verified provider event; nobody answers, accepts or edits one in FoodOnTheGo —
 * that happens with the provider. Reading needs admin.disputes.view (for a market, or for all). A dispute is not a
 * refund: it is shown so that finance sees it and so that no refund is issued on top of an open one.
 */
class DisputeController extends Controller
{
    use AuthorizesMarketScope;

    public function __construct(private readonly RefundPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $markets = $this->marketsWith($request, Permission::AdminDisputesView);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $query = PaymentDispute::query()->with('market')->when($markets !== null, fn (Builder $q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $markets)->select('id')));
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            $query->where(function (Builder $q) use ($text): void {
                $q->where('provider_dispute_reference', $text)->orWhere('order_number', strtoupper($text));
                if (Str::isUuid($text)) {
                    $q->orWhere('public_id', $text)->orWhere('payment_public_id', $text);
                }
            });
        }
        $list = new ListQuery(
            $request,
            filterable: ['status', 'open', 'provider'],
            sortable: ['created_at', 'respond_by_at', 'amount_minor'],
            defaultSort: '-created_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', DisputeStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'open' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true) ? $q->whereIn('status', DisputeStatus::openValues()) : $q->whereNotIn('status', DisputeStatus::openValues()),
                'provider' => fn (Builder $q, string $v) => $q->where('provider', strtolower($v)),
            ],
        );

        return JsonResource::collection($list->paginate($query)->through(fn (PaymentDispute $d): array => $this->presenter->dispute($d)))->response();
    }

    public function show(Request $request, string $dispute): JsonResponse
    {
        $found = PaymentDispute::query()->with('market')->where('public_id', $dispute)->first()
            ?? throw ApiException::notFound('dispute_not_found', 'This dispute does not exist.');
        $found->market === null ? Gate::authorize(Permission::AdminDisputesView->value) : $this->authorizeMarket(Permission::AdminDisputesView, $found->market);

        return response()->json(['dispute' => $this->presenter->dispute($found)]);
    }
}
