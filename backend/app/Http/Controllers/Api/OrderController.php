<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderPresenter;
use App\Http\Support\ListQuery;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\OrderStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer's own orders (Module 31). Identity is the token: no request names a customer, and an order that is
 * not theirs is "not found". Nothing here creates a paid order — a paid order is created by the payment's
 * verification on the server. The one creation a customer can ask for is a checkout with nothing to pay.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderStore $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->where('customer_id', $this->customer($request)->getKey())->with('review'); // the review block of each row, one query for the page
        $list = new ListQuery(
            $request,
            filterable: ['status', 'active'],
            sortable: ['placed_at', 'updated_at'],
            defaultSort: '-placed_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', OrderStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'active' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true) ? $q->whereIn('status', OrderStatus::activeValues()) : $q->whereNotIn('status', OrderStatus::activeValues()),
            ],
        );

        return JsonResource::collection($list->paginate($query)->through(fn (Order $o): array => $this->presenter->summaryForCustomer($o)))->response();
    }

    /**
     * By order number or public id — the customer's own only.
     */
    public function show(Request $request, string $order): JsonResponse
    {
        return response()->json(['order' => $this->presenter->forCustomer($this->orders->ownedBy($this->customer($request), $order))]);
    }

    /**
     * A confirmed checkout with nothing to pay becomes an order. Refused for a checkout that has to be paid.
     * Idempotent: asked twice, the same order is answered.
     */
    public function fulfill(Request $request, string $checkout, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $request->validate(array_fill_keys([
            'amount', 'amount_minor', 'total', 'total_minor', 'currency', 'status', 'payment_state', 'payment_required', 'paid', 'payment_id', 'payment', 'order_number', 'customer_id', 'restaurant_id', 'location_id', 'items', 'promotion', 'promotion_code', 'pickup_at',
        ], ['prohibited']), ['prohibited' => 'The :attribute field is decided by the server and cannot be sent.']);
        $result = $fulfillment->fulfillZeroTotal($this->customer($request), $checkout);
        $order = $this->orders->ownedBy($this->customer($request), (string) $result->order->public_id);

        return response()->json(['order' => $this->presenter->forCustomer($order)], $result->order->wasRecentlyCreated ? 201 : 200);
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }
}
