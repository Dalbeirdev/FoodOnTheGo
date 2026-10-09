<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderPresenter;
use App\Models\RestaurantUser;
use App\Services\Order\OrderStore;
use App\Services\Order\PickupVerificationService;
use App\Services\Restaurant\RestaurantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pickup verification by restaurant staff (Module 32). The scanner (or the person at the counter) sends what the
 * customer presented — the decoded QR payload, or the order number with the read-out code — and the location the
 * dashboard is on. Everything else is decided by the backend (PickupVerificationService): the staff member must have
 * restaurant.pickup.verify at the order's location, which must be that location; the order must be READY; the
 * credential must be the active one. The answer is the order as the restaurant sees it, now PICKED_UP (and, a moment
 * later, COMPLETED).
 */
class PickupVerificationController extends Controller
{
    private const PROTECTED = ['status', 'order_id', 'order', 'picked_up', 'picked_up_at', 'verified', 'result', 'customer', 'customer_id', 'force', 'override'];

    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly OrderStore $orders,
        private readonly PickupVerificationService $verification,
        private readonly OrderPresenter $presenter,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location' => ['required', 'string', 'uuid'],
            'method' => ['required', 'string', 'in:QR,MANUAL_CODE'],
            'payload' => ['required_if:method,QR', 'nullable', 'string', 'max:128'],
            'order_number' => ['required_if:method,MANUAL_CODE', 'nullable', 'string', 'max:24', 'regex:/^[A-Za-z0-9-]+$/'],
            'code' => ['required_if:method,MANUAL_CODE', 'nullable', 'string', 'max:16'],
            ...array_fill_keys(self::PROTECTED, ['prohibited']),
        ], ['prohibited' => 'The :attribute field is decided by the server and cannot be sent.']);
        /** @var RestaurantUser $user */
        $user = $request->user();
        $location = $this->orders->location($user, (string) $input['location'])
            ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
        // The scanner's own location must be one where this staff member may verify pickups at all.
        $this->access->authorize($user, $location, Permission::RestaurantPickupVerify);
        $requestId = (string) $request->header('X-Request-Id');

        $order = $input['method'] === 'QR'
            ? $this->verification->verifyQr($user, $location, (string) $input['payload'], $requestId)
            : $this->verification->verifyCode($user, $location, (string) $input['order_number'], (string) $input['code'], $requestId);

        return response()->json([
            'result' => 'PICKUP_VERIFIED',
            'order' => $this->presenter->forRestaurant($order),
            'verification' => $this->presenter->pickupVerification($order, true),
        ]);
    }
}
