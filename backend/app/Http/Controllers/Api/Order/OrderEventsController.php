<?php

namespace App\Http\Controllers\Api\Order;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderPresenter;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\RestaurantUser;
use App\Services\Order\OrderMetrics;
use App\Services\Order\OrderStore;
use App\Services\Restaurant\RestaurantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The event stream of one order (Module 32): the persistent timeline, read by cursor. A client that noticed a gap in
 * the realtime sequence, came back from the background or lost its socket asks for everything after the sequence
 * it has; the answer also carries the order's current version and status so that one call is a full resync.
 *
 * The customer gets the customer-visible lines; the restaurant every line of an order of its locations (internal
 * recovery / security lines are not order events and never appear here). Bounded to 200 lines per call.
 */
class OrderEventsController extends Controller
{
    private const LIMIT = 200;

    public function __construct(private readonly OrderStore $orders, private readonly OrderPresenter $presenter, private readonly OrderMetrics $metrics, private readonly RestaurantAccess $access) {}

    public function customer(Request $request, string $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $found = $this->orders->ownedBy($customer, $order);

        return $this->answer($request, $found, false);
    }

    public function restaurant(Request $request, string $order): JsonResponse
    {
        /** @var RestaurantUser $user */
        $user = $request->user();
        $found = $this->orders->forRestaurant($user, $order);
        // Module 39: like every other read of a restaurant's order, the permission is required, not only the location.
        $this->access->authorize($user, $found->location, Permission::RestaurantOrdersView);

        return $this->answer($request, $found, true);
    }

    private function answer(Request $request, Order $order, bool $internal): JsonResponse
    {
        $input = $request->validate(['after_sequence' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000']]);
        $after = (int) ($input['after_sequence'] ?? 0);
        if ($after > 0) {
            $this->metrics->record('events_resync');
        }
        $query = OrderEvent::query()->where('order_id', $order->getKey())->where('sequence', '>', $after)->orderBy('sequence')->limit(self::LIMIT + 1);
        if (! $internal) {
            $query->where('customer_visible', true);
        }
        $rows = $query->get();
        $more = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT);

        return response()->json([
            'order' => ['id' => (string) $order->public_id, 'order_number' => (string) $order->order_number, 'status' => $order->status->value, 'version' => (int) $order->version, 'refund_status' => $internal ? $order->refund_status->value : $order->refund_status->forCustomer(), 'payment_state' => $order->payment_state->value, 'updated_at' => $order->updated_at?->toIso8601String()],
            'events' => $rows->map(fn (OrderEvent $e): array => $this->presenter->eventLine($order, $e, $internal))->values()->all(),
            'cursor' => ['after_sequence' => $after, 'last_sequence' => $rows->last()?->sequence ?? $after, 'has_more' => $more],
            'realtime' => $this->presenter->realtimeHints($order, $internal),
        ]);
    }
}
