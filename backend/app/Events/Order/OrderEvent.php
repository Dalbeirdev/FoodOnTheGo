<?php

namespace App\Events\Order;

use App\Models\Order;

/**
 * Something happened to an order (Module 31). Dispatched after the commit that made it true, so a consumer that
 * reads the order finds it as announced — and possibly announced twice, so consumers tolerate a repeat.
 *
 * ORDER_CREATED is the hook for Module 32 (tracking) and Module 33 (notifications); REFUND_REQUIRED for the refund
 * module; RECOVERY_REQUIRED / FULFILLMENT_FAILED for operations.
 */
final readonly class OrderEvent
{
    public const ORDER_CREATED = 'order.created';

    public const STATUS_CHANGED = 'order.status_changed';

    public const RESTAURANT_ACCEPTED = 'order.restaurant_accepted';

    public const RESTAURANT_REJECTED = 'order.restaurant_rejected';

    public const PREPARING = 'order.preparing';

    public const READY = 'order.ready';

    public const CANCELLED = 'order.cancelled';

    public const PICKED_UP = 'order.picked_up';

    public const COMPLETED = 'order.completed';

    public const PICKUP_CREDENTIAL_REISSUED = 'order.pickup_credential_reissued';

    public const REFUND_REQUIRED = 'order.refund_required';

    public const RECOVERY_REQUIRED = 'order.recovery_required';

    public const FULFILLMENT_FAILED = 'order.fulfillment_failed';

    public string $orderId;

    public string $orderNumber;

    public string $status;

    public string $checkoutId;

    public ?string $paymentId;

    public ?int $locationId;

    public ?int $customerId;

    public int $version;

    public ?int $sequence;

    public ?int $orderKey;

    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(public string $type, Order $order, public array $detail = [])
    {
        $this->orderId = (string) $order->public_id;
        $this->orderNumber = (string) $order->order_number;
        $this->status = $order->status->value;
        $this->checkoutId = (string) $order->checkout_public_id;
        $this->paymentId = $order->payment_public_id === null ? null : (string) $order->payment_public_id;
        $this->locationId = $order->location_id === null ? null : (int) $order->location_id;
        $this->customerId = $order->customer_id === null ? null : (int) $order->customer_id;
        $this->version = (int) $order->version;
        $this->orderKey = $order->getKey() === null ? null : (int) $order->getKey();
        // Module 32: the timeline line this event announces (the latest of the order when it was dispatched).
        $this->sequence = isset($detail['sequence']) ? (int) $detail['sequence'] : null;
    }
}
