<?php

namespace App\Listeners;

use App\Enums\OrderPaymentState;
use App\Enums\OrderRefundStatus;
use App\Events\Order\OrderEvent;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Order event (Module 31 / 32, dispatched after the commit) → customer notification through the registry. The
 * variables handed to the templates are the allow-listed ones only: the restaurant's name, the order number, the pickup
 * time in the restaurant's zone and a refund line derived from the order's refund state — never the pickup secret or
 * code, the phone, the address, the items or an amount. Any failure here is logged and never reaches the order flow.
 */
final class NotifyOnOrderEvent
{
    public function __construct(
        private readonly NotificationRegistry $registry,
        private readonly NotificationOrchestrator $orchestrator,
        private readonly NotificationTemplates $templates,
    ) {}

    public function handle(OrderEvent $event): void
    {
        $type = $this->registry->forOrderEvent($event->type);
        if ($type === null || $event->customerId === null || $event->orderKey === null) {
            return;
        }
        try {
            $order = Order::query()->with('customer.market')->find($event->orderKey);
            $customer = $order?->customer;
            if ($order === null || ! $customer instanceof Customer) {
                return;
            }
            $this->orchestrator->notify(
                $type,
                $customer,
                ['type' => CustomerNotification::SUBJECT_ORDER, 'public_id' => (string) $order->public_id, 'reference' => (string) $order->order_number],
                $this->variables($order, $customer),
                'order:'.$order->public_id.':'.($event->sequence !== null ? 'seq'.$event->sequence : 'v'.$event->version).':'.$type->key,
            );
        } catch (Throwable $e) {
            Log::error('notifications.order_listener_failed', ['order' => $event->orderId, 'event' => $event->type, 'class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
            if (app()->environment('testing')) {
                throw $e; // a test must see a notification bug; production only logs it
            }
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function variables(Order $order, Customer $customer): array
    {
        $snapshot = (array) ($order->snapshot ?? []);
        $restaurant = (array) ($snapshot['restaurant'] ?? []);
        $locale = $customer->preferred_locale ?? $customer->market?->default_locale ?? (string) config('notifications.default_locale');

        $pickupTime = null;
        $at = $order->pickup_at ?? $order->pickup_slot_starts_at ?? null;
        if ($at !== null) {
            try {
                $zone = (string) ($order->restaurant_timezone ?: ($restaurant['timezone'] ?? 'Asia/Kolkata'));
                $pickupTime = Carbon::parse($at)->setTimezone($zone)->format('g:i a');
            } catch (Throwable) {
                $pickupTime = null;
            }
        }

        $refundLine = match (true) {
            $order->refund_status instanceof OrderRefundStatus && in_array($order->refund_status, [OrderRefundStatus::Required, OrderRefundStatus::Pending, OrderRefundStatus::Partial], true) => $this->templates->fragment('refund_line.pending', $locale),
            $order->payment_state === OrderPaymentState::PaymentNotRequired => $this->templates->fragment('refund_line.not_required', $locale),
            $order->payment_state === OrderPaymentState::Paid => $this->templates->fragment('refund_line.pending', $locale),
            default => $this->templates->fragment('refund_line.none', $locale),
        };

        return [
            'restaurant' => (string) ($restaurant['name'] ?? ''),
            'order' => (string) $order->order_number,
            'pickup_time' => $pickupTime,
            'refund_line' => $refundLine,
        ];
    }
}
