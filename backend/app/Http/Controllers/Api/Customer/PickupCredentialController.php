<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderPresenter;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Auth\SecurityEventRecorder;
use App\Services\Order\OrderStateMachine;
use App\Services\Order\OrderStore;
use App\Services\Order\PickupCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's pickup credential (Module 32): what to show at the counter — the QR payload and the code — for
 * their own order, while the order is on its way. Private, never cached. A customer may ask for a new one (the old
 * QR and code stop working at once), a few times an hour; the rotation is on the timeline and is a security event.
 * Nothing here verifies anything: a customer cannot mark their own order picked up.
 */
class PickupCredentialController extends Controller
{
    public function __construct(
        private readonly OrderStore $orders,
        private readonly PickupCredentials $credentials,
        private readonly OrderStateMachine $machine,
        private readonly OrderPresenter $presenter,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function show(Request $request, string $order): JsonResponse
    {
        $found = $this->orders->ownedBy($this->customer($request), $order);
        if ($found->status->isActive() && $this->credentials->needsRotation($found)) {
            // A Module 31 credential (no secret kept) is replaced by one whose QR verifies.
            $this->credentials->rotate($found, OrderEvent::ACTOR_SYSTEM, null, $this->machine, (string) $request->header('X-Request-Id'));
            $found = $this->orders->ownedBy($this->customer($request), $order);
        }

        return $this->answer($found);
    }

    public function reissue(Request $request, string $order): JsonResponse
    {
        $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $customer = $this->customer($request);
        $found = $this->orders->ownedBy($customer, $order);
        $issued = $this->credentials->rotate($found, OrderEvent::ACTOR_CUSTOMER, (string) $customer->public_id, $this->machine, (string) $request->header('X-Request-Id'));
        $this->security->record(SecurityEventType::PickupCredentialReissued, $customer, ['order' => (string) $found->public_id, 'credential' => (string) $issued->public_id]);

        return $this->answer($this->orders->ownedBy($customer, $order), 201);
    }

    private function answer(Order $order, int $status = 200): JsonResponse
    {
        return response()->json([
            'order' => ['id' => (string) $order->public_id, 'order_number' => (string) $order->order_number, 'status' => $order->status->value, 'version' => (int) $order->version],
            'pickup_credential' => $order->status->isActive() ? $this->credentials->present($order->pickupCredential) : null,
            'pickup_verification' => $this->presenter->pickupVerification($order),
        ], $status)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }
}
