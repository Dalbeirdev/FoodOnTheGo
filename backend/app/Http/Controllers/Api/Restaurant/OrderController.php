<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\OrderRejectionReason;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderPresenter;
use App\Http\Support\ListQuery;
use App\Models\Order;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use App\Services\Order\OrderStore;
use App\Services\Order\OrderWorkflowService;
use App\Services\Restaurant\RestaurantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * The restaurant's order queue (Module 31). Which orders a staff member sees is decided by their ACTIVE memberships
 * (RestaurantAccess): an order of another organization is "not found", one of a location they may not update is
 * 403 on every action. The steps are accept / reject / start preparing / ready; "picked up" is Module 32's verified
 * step and has no button here.
 */
class OrderController extends Controller
{
    private const PROTECTED = ['status', 'order_number', 'total', 'total_minor', 'amount', 'currency', 'items', 'customer', 'customer_id', 'payment_state', 'refund_status', 'pickup_at', 'picked_up', 'location_id'];

    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly OrderStore $orders,
        private readonly OrderWorkflowService $workflow,
        private readonly OrderPresenter $presenter,
    ) {}

    /**
     * Orders of one location (`location` = its public id, required): the queue by default — everything active —
     * or a status, or the history of a day.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location' => ['required', 'string', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        $location = $this->location($request, (string) $input['location'], Permission::RestaurantOrdersView);
        $query = Order::query()->where('location_id', $location->getKey());
        if (($text = strtoupper(trim((string) ($input['q'] ?? '')))) !== '') {
            $query->where('order_number', 'like', $text.'%');
        }
        $list = new ListQuery(
            $request,
            filterable: ['status', 'queue', 'day'],
            sortable: ['placed_at', 'pickup_at', 'updated_at'],
            defaultSort: 'pickup_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', OrderStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'queue' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true) ? $q->whereIn('status', OrderStatus::activeValues()) : $q,
                'day' => fn (Builder $q, string $v) => $q->whereBetween('pickup_at', self::dayBounds($v, (string) $location->timezone)),
            ],
        );
        $page = $list->paginate($query);
        $counts = Order::query()->where('location_id', $location->getKey())->whereIn('status', OrderStatus::activeValues())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return JsonResource::collection($page->through(fn (Order $o): array => $this->presenter->summaryForRestaurant($o)))
            ->additional(['counts' => array_combine(array_map('strtolower', OrderStatus::activeValues()), array_map(fn (string $s): int => (int) ($counts[$s] ?? 0), OrderStatus::activeValues()))])
            ->response();
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $found = $this->orders->forRestaurant($this->user($request), $order);
        $this->access->authorize($this->user($request), $found->location, Permission::RestaurantOrdersView);

        return response()->json(['order' => $this->presenter->forRestaurant($found)]);
    }

    public function accept(Request $request, string $order): JsonResponse
    {
        [$found, $version] = $this->forUpdate($request, $order);

        return $this->answer($this->workflow->accept($found, $this->user($request), $version));
    }

    public function reject(Request $request, string $order): JsonResponse
    {
        [$found, $version] = $this->forUpdate($request, $order, [
            'reason' => ['required', 'string', Rule::enum(OrderRejectionReason::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $input = $request->only(['reason', 'note']);

        return $this->answer($this->workflow->reject($found, $this->user($request), OrderRejectionReason::from((string) $input['reason']), $input['note'] ?? null, $version));
    }

    public function startPreparing(Request $request, string $order): JsonResponse
    {
        [$found, $version] = $this->forUpdate($request, $order);

        return $this->answer($this->workflow->startPreparing($found, $this->user($request), $version));
    }

    public function ready(Request $request, string $order): JsonResponse
    {
        [$found, $version] = $this->forUpdate($request, $order);

        return $this->answer($this->workflow->ready($found, $this->user($request), $version));
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: Order, 1: int|null}
     */
    private function forUpdate(Request $request, string $order, array $rules = []): array
    {
        $input = $request->validate([
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...$rules,
            ...array_fill_keys(self::PROTECTED, ['prohibited']),
        ], ['prohibited' => 'The :attribute field is decided by the server and cannot be sent.']);
        $found = $this->orders->forRestaurant($this->user($request), $order);
        $this->access->authorize($this->user($request), $found->location, Permission::RestaurantOrdersUpdate);

        return [$found, isset($input['expected_version']) ? (int) $input['expected_version'] : null];
    }

    private function location(Request $request, string $publicId, Permission $permission): RestaurantLocation
    {
        $location = $this->orders->location($this->user($request), $publicId)
            ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
        $this->access->authorize($this->user($request), $location, $permission);

        return $location;
    }

    private function answer(Order $order): JsonResponse
    {
        return response()->json(['order' => $this->presenter->forRestaurant($order)]);
    }

    private function user(Request $request): RestaurantUser
    {
        return $request->user();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function dayBounds(string $value, string $timezone): array
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) ?: throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw new ApiException(422, 'validation_failed', 'A day filter must be a date (YYYY-MM-DD).', ['fields' => ['filter' => ['A day filter must be a date (YYYY-MM-DD).']]]);
        }

        return [$day->utc(), $day->addDay()->utc()];
    }
}
