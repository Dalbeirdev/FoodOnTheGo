<?php

namespace App\Http\Controllers\Api\Admin;

use App\Auth\Principal;
use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderPresenter;
use App\Http\Support\ListQuery;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Services\Audit\AuditRecorder;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\OrderMetrics;
use App\Services\Order\OrderStore;
use App\Services\Order\OrderWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Orders for administrators (Module 31): the records, their money state, the payment each one came from — and the
 * paid checkouts that did NOT become an order, which is the one list an operator must never lose sight of.
 *
 * Reading needs admin.orders.view (per market). Two actions exist, both with a reason and audited: cancelling an
 * order (admin.orders.override) and asking for a failed order creation to be tried again (admin.orders.recover).
 * Neither one edits a status, an amount or an item by hand, and neither one touches money.
 */
class OrderController extends Controller
{
    use AuthorizesMarketScope;

    public function __construct(
        private readonly OrderPresenter $presenter,
        private readonly OrderStore $orders,
        private readonly OrderWorkflowService $workflow,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $visible = $this->marketsWith($request, Permission::AdminOrdersView);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $query = Order::query()->with(['market'])
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $visible)->select('id')));
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            // Exact references: an order number, an order id, the checkout or the payment it came from.
            $query->where(function (Builder $q) use ($text): void {
                $q->where('order_number', strtoupper($text));
                if (Str::isUuid($text)) {
                    $q->orWhere('public_id', $text)->orWhere('checkout_public_id', $text)->orWhere('payment_public_id', $text);
                }
            });
        }
        $list = new ListQuery(
            $request,
            filterable: ['status', 'payment_state', 'refund_status', 'restaurant', 'attention', 'from', 'to'],
            sortable: ['placed_at', 'pickup_at', 'updated_at', 'total_minor', 'status'],
            defaultSort: '-placed_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', OrderStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'payment_state' => fn (Builder $q, string $v) => $q->where('payment_state', strtoupper($v)),
                'refund_status' => fn (Builder $q, string $v) => $q->where('refund_status', OrderRefundStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
                'attention' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true) ? $q->whereIn('refund_status', [OrderRefundStatus::Required->value, OrderRefundStatus::Failed->value]) : $q,
                'from' => fn (Builder $q, string $v) => $q->where('placed_at', '>=', self::day($v)),
                'to' => fn (Builder $q, string $v) => $q->where('placed_at', '<', self::day($v)->addDay()),
            ],
        );

        return JsonResource::collection($list->paginate($query)->through(fn (Order $o): array => $this->presenter->forAdmin($o)))->response();
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return response()->json(['order' => $this->presenter->detailForAdmin($this->find($order, Permission::AdminOrdersView))]);
    }

    /**
     * Paid checkouts and their way to an order — above all the ones that did not get there (needs_attention).
     */
    public function fulfillments(Request $request): JsonResponse
    {
        $visible = $this->marketsWith($request, Permission::AdminOrdersView);
        $query = OrderFulfillment::query()->with(['order'])
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('checkout_session_id', DB::table('checkout_sessions')->whereIn('market_id', Market::query()->whereIn('public_id', $visible)->select('id'))->select('id')));
        $list = new ListQuery(
            $request,
            filterable: ['status', 'attention', 'checkout', 'payment'],
            sortable: ['created_at', 'updated_at', 'attempts'],
            defaultSort: '-updated_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', OrderFulfillmentStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'attention' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true) ? $q->whereIn('status', [OrderFulfillmentStatus::ReviewRequired->value, OrderFulfillmentStatus::Failed->value, OrderFulfillmentStatus::Retrying->value]) : $q,
                'checkout' => fn (Builder $q, string $v) => $q->where('checkout_public_id', Str::isUuid($v) ? $v : '-'),
                'payment' => fn (Builder $q, string $v) => $q->where('payment_public_id', Str::isUuid($v) ? $v : '-'),
            ],
        );

        return JsonResource::collection($list->paginate($query)->through(fn (OrderFulfillment $f): array => $this->presenter->fulfillment($f)))->response();
    }

    /**
     * Asks for the order to be created again from a checkout whose creation failed. Whether it can be is judged
     * again from scratch — the payment's verification is not this administrator's word, and nothing is created by hand.
     */
    public function recover(Request $request, string $fulfillment): JsonResponse
    {
        $row = OrderFulfillment::query()->with(['checkout.market', 'order'])->where('public_id', $fulfillment)->first()
            ?? throw ApiException::notFound('order_fulfillment_not_found', 'This order creation record does not exist.');
        $this->authorizeFor(Permission::AdminOrdersRecover, $row->checkout?->market);
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            ...array_fill_keys(['status', 'order_id', 'order_number', 'payment_id', 'payment_status', 'verified', 'amount', 'amount_minor', 'total', 'currency', 'items'], ['prohibited']),
        ], ['prohibited' => 'The :attribute field cannot be set by hand.']);
        /** @var Principal $admin */
        $admin = $request->user();
        $before = $row->status->value;
        $after = $this->fulfillment->retry($row, (int) $admin->getAuthIdentifier());
        $this->audit->record('order.recovery_attempted', $after, $admin, ['status' => ['from' => $before, 'to' => $after->status->value], 'order' => $after->order?->public_id], (string) $input['reason'], $row->checkout?->market_id);

        return response()->json(['fulfillment' => $this->presenter->fulfillment($after->load('order'))]);
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        $found = $this->find($order, Permission::AdminOrdersOverride);
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            ...array_fill_keys(['status', 'refund_status', 'payment_state', 'amount', 'total', 'items', 'refund'], ['prohibited']),
        ], ['prohibited' => 'The :attribute field cannot be set by hand.']);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $after = $this->workflow->cancel($found, $admin, (string) $input['reason']);

        return response()->json(['order' => $this->presenter->detailForAdmin($after->load(['fulfillment', 'market']))]);
    }

    public function metrics(OrderMetrics $metrics): JsonResponse
    {
        return response()->json($metrics->report());
    }

    private function find(string $reference, Permission $permission): Order
    {
        $order = $this->orders->byReference(Order::query(), $reference)->with([...OrderStore::relations(), 'fulfillment'])->first()
            ?? throw ApiException::notFound('order_not_found', 'This order does not exist.');
        $this->authorizeFor($permission, $order->market);

        return $order;
    }

    private function authorizeFor(Permission $permission, ?Market $market): void
    {
        $market === null ? Gate::authorize($permission->value) : $this->authorizeMarket($permission, $market);
    }

    private static function day(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC') ?: throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw new ApiException(422, 'validation_failed', 'A date filter must be a day (YYYY-MM-DD).', ['fields' => ['filter' => ['A date filter must be a day (YYYY-MM-DD).']]]);
        }
    }
}
