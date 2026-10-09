<?php

namespace App\Http\Resources\Order;

use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderFeeLine;
use App\Models\OrderFulfillment;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderPickupCredential;
use App\Models\OrderPickupVerificationAttempt;
use App\Models\OrderRefund;
use App\Models\OrderTaxLine;
use App\Services\Order\PickupCredentials;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * An order as each side may see it (Module 31).
 *
 *  - The customer: everything they bought, paid and are owed — and their pickup credential while the order is on
 *    its way. Never a restaurant's internal note, never the payment's provider references.
 *  - The restaurant: what to make, when it is wanted, how to reach the customer about THIS order — the name and
 *    phone the order was placed with — and never the payment method, the promotion's economics or the journey.
 *    No pickup credential: the restaurant verifies it (Module 32), it does not read it.
 *  - The administrator: the record, the money state, the payment and the fulfilment linked, the full timeline.
 *
 * Every time is an instant in UTC; the restaurant's time zone is given so clients can show its clock.
 */
final class OrderPresenter
{
    public function __construct(private readonly PickupCredentials $credentials) {}

    /* ------------------------------------------------------------------ customer */

    /**
     * @return array<string, mixed>
     */
    public function summaryForCustomer(Order $order): array
    {
        $snapshot = (array) $order->snapshot;
        $restaurant = (array) ($snapshot['restaurant'] ?? []);

        return [
            'id' => (string) $order->public_id,
            'order_number' => (string) $order->order_number,
            'status' => $order->status->value,
            'payment_state' => $order->payment_state->value,
            'refund_status' => $order->refund_status->value,
            'restaurant' => ['id' => $restaurant['location_id'] ?? null, 'slug' => $restaurant['slug'] ?? null, 'name' => (string) ($restaurant['name'] ?? ''), 'branch_label' => $restaurant['branch_label'] ?? null],
            'item_count' => (int) $order->item_count,
            'total' => $order->total()->toArray(),
            'pickup' => $this->pickup($order),
            'placed_at' => self::utc($order->placed_at),
            'updated_at' => self::utc($order->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forCustomer(Order $order): array
    {
        $snapshot = (array) $order->snapshot;
        $restaurant = (array) ($snapshot['restaurant'] ?? []);
        $payment = (array) ($snapshot['payment'] ?? []);

        return array_replace($this->summaryForCustomer($order), [
            'restaurant' => [
                'id' => $restaurant['location_id'] ?? null,
                'slug' => $restaurant['slug'] ?? null,
                'name' => (string) ($restaurant['name'] ?? ''),
                'branch_label' => $restaurant['branch_label'] ?? null,
                'formatted_address' => $restaurant['formatted_address'] ?? null,
                'phone' => $restaurant['phone'] ?? null,
                'timezone' => (string) $order->restaurant_timezone,
                'pickup_instructions' => $restaurant['pickup_instructions'] ?? null,
            ],
            'items' => $order->items->map(fn (OrderItem $i): array => $this->item($i))->all(),
            'pricing' => $this->pricing($order),
            'promotion' => $order->promotion_code === null ? null : ['code' => (string) $order->promotion_code, 'name' => $snapshot['promotion']['name'] ?? null, 'discount' => (new Money((int) $order->discount_minor, (string) $order->currency))->toArray()],
            'taxes' => $order->taxLines->map(fn (OrderTaxLine $t): array => $this->taxLine($t, (string) $order->currency))->all(),
            'fees' => $order->feeLines->map(fn (OrderFeeLine $f): array => $this->feeLine($f, (string) $order->currency))->all(),
            'payment' => $order->payment_public_id === null ? ['required' => false, 'id' => null, 'provider' => null, 'method' => null, 'verified_at' => null] : [
                'required' => true,
                'id' => (string) $order->payment_public_id,
                'provider' => $payment['provider'] ?? null,
                'method' => $payment['method_type'] === null || ! isset($payment['method_type']) ? null : ['type' => (string) $payment['method_type'], 'summary' => (object) ($payment['method_summary'] ?? [])],
                'verified_at' => $payment['verified_at'] ?? null,
            ],
            'customer_note' => $order->customer_note,
            'journey' => $snapshot['journey'] ?? null,
            'legal' => array_values((array) ($snapshot['legal'] ?? [])),
            'rejection' => $order->rejection_reason === null ? null : ['reason' => $order->rejection_reason->value, 'message' => $order->rejection_reason->customerMessage()],
            'cancellation' => $order->status === OrderStatus::Cancelled ? ['message' => 'This order was cancelled. If you paid, the amount will be refunded.'] : null,
            // The credential the customer shows at pickup: present only while there is something to pick up.
            'pickup_credential' => $order->status->isActive() ? $this->credentials->present($order->pickupCredential) : null,
            'pickup_verification' => $this->pickupVerification($order),
            'timeline' => $order->events->filter(fn (OrderEvent $e): bool => (bool) $e->customer_visible)->map(fn (OrderEvent $e): array => $this->eventLine($order, $e, false))->values()->all(),
            'realtime' => $this->realtimeHints($order, false),
            'next_action' => $this->customerNextAction($order),
            'times' => $this->times($order),
            'version' => (int) $order->version,
        ]);
    }

    /* ------------------------------------------------------------------ restaurant */

    /**
     * @return array<string, mixed>
     */
    public function summaryForRestaurant(Order $order): array
    {
        $snapshot = (array) $order->snapshot;
        $customer = (array) ($snapshot['customer'] ?? []);

        return [
            'id' => (string) $order->public_id,
            'order_number' => (string) $order->order_number,
            'status' => $order->status->value,
            'payment_state' => $order->payment_state->value,
            'refund_status' => $order->refund_status->value,
            'location_id' => (string) ($snapshot['restaurant']['location_id'] ?? ''),
            'customer' => ['name' => (string) ($customer['name'] ?? ''), 'phone' => $customer['phone'] ?? null],
            'item_count' => (int) $order->item_count,
            'total' => $order->total()->toArray(),
            'pickup' => $this->pickup($order),
            'placed_at' => self::utc($order->placed_at),
            'updated_at' => self::utc($order->updated_at),
            'version' => (int) $order->version,
            'allowed_actions' => self::restaurantActions($order->status),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forRestaurant(Order $order): array
    {
        $snapshot = (array) $order->snapshot;

        return array_replace($this->summaryForRestaurant($order), [
            'items' => $order->items->map(fn (OrderItem $i): array => $this->item($i))->all(),
            'pricing' => $this->pricing($order),
            'promotion' => $order->promotion_code === null ? null : ['code' => (string) $order->promotion_code],
            'taxes' => $order->taxLines->map(fn (OrderTaxLine $t): array => $this->taxLine($t, (string) $order->currency))->all(),
            'fees' => $order->feeLines->map(fn (OrderFeeLine $f): array => $this->feeLine($f, (string) $order->currency))->all(),
            'customer_note' => $order->customer_note,
            'rejection' => $order->rejection_reason === null ? null : ['reason' => $order->rejection_reason->value, 'note' => $order->rejection_note],
            'cancellation' => $order->status === OrderStatus::Cancelled ? ['reason' => $order->cancellation_reason] : null,
            'journey' => $snapshot['journey'] === null || ! isset($snapshot['journey']) ? null : ['estimated_arrival_at' => $snapshot['journey']['estimated_arrival_at'] ?? null],
            'pickup_verification' => $this->pickupVerification($order, true),
            'timeline' => $order->events->map(fn (OrderEvent $e): array => $this->eventLine($order, $e, true))->values()->all(),
            'times' => $this->times($order),
            'realtime' => $this->realtimeHints($order, true),
        ]);
    }

    /* ------------------------------------------------------------------ admin */

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(Order $order): array
    {
        $snapshot = (array) $order->snapshot;
        $restaurant = (array) ($snapshot['restaurant'] ?? []);
        $customer = (array) ($snapshot['customer'] ?? []);

        return [
            'id' => (string) $order->public_id,
            'order_number' => (string) $order->order_number,
            'status' => $order->status->value,
            'payment_state' => $order->payment_state->value,
            'refund_status' => $order->refund_status->value,
            'needs_attention' => $order->refund_status->value === 'REQUIRED' || $order->refund_status->value === 'FAILED',
            'restaurant' => ['id' => $restaurant['location_id'] ?? null, 'name' => (string) ($restaurant['name'] ?? ''), 'organization' => $restaurant['organization_name'] ?? null],
            'market' => $order->market?->slug,
            'customer' => ['id' => $customer['id'] ?? null, 'phone_masked' => self::maskPhone($customer['phone'] ?? null)],
            'payment_id' => $order->payment_public_id,
            'checkout_id' => (string) $order->checkout_public_id,
            'item_count' => (int) $order->item_count,
            'total' => $order->total()->toArray(),
            'pickup' => $this->pickup($order),
            'placed_at' => self::utc($order->placed_at),
            'updated_at' => self::utc($order->updated_at),
            'version' => (int) $order->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailForAdmin(Order $order): array
    {
        $snapshot = (array) $order->snapshot;
        $customer = (array) ($snapshot['customer'] ?? []);

        return array_replace($this->forAdmin($order), [
            'customer' => ['id' => $customer['id'] ?? null, 'name' => $customer['name'] ?? null, 'phone_masked' => self::maskPhone($customer['phone'] ?? null)],
            'items' => $order->items->map(fn (OrderItem $i): array => $this->item($i))->all(),
            'pricing' => $this->pricing($order),
            'promotion' => $order->promotion_code === null ? null : ['code' => (string) $order->promotion_code, 'name' => $snapshot['promotion']['name'] ?? null, 'type' => $snapshot['promotion']['type'] ?? null],
            'taxes' => $order->taxLines->map(fn (OrderTaxLine $t): array => $this->taxLine($t, (string) $order->currency))->all(),
            'fees' => $order->feeLines->map(fn (OrderFeeLine $f): array => $this->feeLine($f, (string) $order->currency))->all(),
            'payment' => $snapshot['payment'] ?? null,
            'customer_note' => $order->customer_note,
            'rejection' => $order->rejection_reason === null ? null : ['reason' => $order->rejection_reason->value, 'note' => $order->rejection_note],
            'cancellation' => $order->status === OrderStatus::Cancelled ? ['reason' => $order->cancellation_reason] : null,
            'refunds' => $order->refunds->map(fn (OrderRefund $r): array => [
                'id' => (string) $r->public_id, 'amount' => (new Money((int) $r->amount_minor, (string) $r->currency))->toArray(), 'reason' => (string) $r->reason, 'status' => (string) $r->status,
                'requested_by' => ['type' => (string) $r->requested_by_type, 'id' => $r->requested_by_public_id], 'requested_at' => self::utc($r->requested_at), 'processing' => 'NOT_IMPLEMENTED',
            ])->all(),
            'pickup_credential' => $order->pickupCredential === null ? null : ['id' => (string) $order->pickupCredential->public_id, 'status' => $order->pickupCredential->status->value, 'issued_at' => self::utc($order->pickupCredential->issued_at ?? $order->pickupCredential->created_at), 'expires_at' => self::utc($order->pickupCredential->expires_at), 'format_version' => (int) $order->pickupCredential->format_version, 'failed_attempts' => (int) $order->pickupCredential->failed_attempts, 'locked_until' => self::utc($order->pickupCredential->locked_until)],
            'pickup_credentials' => $order->pickupCredentials->map(fn (OrderPickupCredential $c): array => ['id' => (string) $c->public_id, 'status' => $c->status->value, 'issued_at' => self::utc($c->issued_at ?? $c->created_at), 'revoked_at' => self::utc($c->revoked_at), 'revoke_reason' => $c->revoke_reason, 'consumed_at' => self::utc($c->consumed_at), 'consumed_method' => $c->consumed_method])->values()->all(),
            'pickup_verification' => $this->pickupVerification($order, true),
            'pickup_attempts' => ['total' => $order->pickupVerificationAttempts->count(), 'failed' => $order->pickupVerificationAttempts->where('result', '!=', 'SUCCESS')->count(), 'last' => $order->pickupVerificationAttempts->map(fn (OrderPickupVerificationAttempt $a): array => ['at' => self::utc($a->attempted_at), 'method' => (string) $a->method, 'result' => (string) $a->result, 'staff' => $a->user === null ? null : ['id' => (string) $a->user->public_id, 'name' => (string) $a->user->name]])->reverse()->take(10)->values()->all()],
            'journey' => $snapshot['journey'] ?? null,
            'legal' => array_values((array) ($snapshot['legal'] ?? [])),
            'pricing_fingerprint' => $snapshot['pricing_fingerprint'] ?? null,
            'fulfillment' => $order->fulfillment === null ? null : $this->fulfillment($order->fulfillment),
            'timeline' => $order->events->map(fn (OrderEvent $e): array => $this->eventLine($order, $e, true))->values()->all(),
            'times' => $this->times($order),
            'actions' => ['cancel' => ! $order->status->isTerminal(), 'refund' => 'NOT_IMPLEMENTED', 'mark_picked_up' => 'NOT_PROVIDED'],
        ]);
    }

    /**
     * A fulfilment as administrators see it: a paid checkout that has (or has not) become an order.
     *
     * @return array<string, mixed>
     */
    public function fulfillment(OrderFulfillment $f): array
    {
        return [
            'id' => (string) $f->public_id,
            'checkout_id' => (string) $f->checkout_public_id,
            'payment_id' => $f->payment_public_id,
            'order_id' => $f->order?->public_id === null ? null : (string) $f->order->public_id,
            'order_number' => $f->order?->order_number,
            'trigger' => (string) $f->trigger,
            'status' => $f->status->value,
            'attempts' => (int) $f->attempts,
            'last_error_code' => $f->last_error_code,
            'last_error_detail' => $f->last_error_detail,
            'next_attempt_at' => self::utc($f->next_attempt_at),
            'needs_attention' => $f->status === OrderFulfillmentStatus::ReviewRequired || $f->status === OrderFulfillmentStatus::Failed,
            'started_at' => self::utc($f->started_at),
            'finished_at' => self::utc($f->finished_at),
            'created_at' => self::utc($f->created_at),
            'updated_at' => self::utc($f->updated_at),
        ];
    }

    /* ------------------------------------------------------------------ shared pieces */

    /**
     * @return list<string>
     */
    public static function restaurantActions(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::WaitingForRestaurant => ['accept', 'reject'],
            OrderStatus::Accepted => ['start_preparing'],
            OrderStatus::Preparing => ['ready'],
            OrderStatus::ReadyForPickup => ['verify_pickup'],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pickup(Order $order): array
    {
        return [
            'mode' => $order->pickup_mode->value,
            'method' => $order->pickup_method->value,
            'pickup_at' => self::utc($order->pickup_at),
            'pickup_at_local' => self::local($order->pickup_at, (string) $order->restaurant_timezone),
            'slot' => ['starts_at' => self::utc($order->pickup_slot_starts_at), 'ends_at' => self::utc($order->pickup_slot_ends_at)],
            'timezone' => (string) $order->restaurant_timezone,
            'estimated_prep_minutes' => $order->estimated_prep_minutes,
            'traveler_arrival_at' => self::utc($order->traveler_arrival_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(Order $order): array
    {
        $c = (string) $order->currency;
        $m = fn (int $v): array => (new Money($v, $c))->toArray();

        return [
            'currency' => $c,
            'subtotal' => $m((int) $order->subtotal_minor),
            'discount' => $m((int) $order->discount_minor),
            'tax' => $m((int) $order->tax_minor),
            'tax_included' => $m((int) $order->tax_included_minor),
            'fee' => $m((int) $order->fee_minor),
            'total' => $m((int) $order->total_minor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(OrderItem $item): array
    {
        $c = (string) $item->currency;

        return [
            'id' => (string) $item->public_id,
            'item_id' => $item->item_public_id,
            'slug' => $item->item_slug,
            'name' => (string) $item->name,
            'quantity' => (int) $item->quantity,
            'unit_price' => (new Money((int) $item->unit_price_minor, $c))->toArray(),
            'line_subtotal' => (new Money((int) $item->line_subtotal_minor, $c))->toArray(),
            'special_instructions' => $item->special_instructions,
            'options' => $item->options->map(fn (OrderItemOption $o): array => [
                'kind' => (string) $o->kind, 'group' => (string) $o->group_name, 'name' => (string) $o->option_name, 'price_adjustment' => (new Money((int) $o->price_adjustment_minor, $c))->toArray(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxLine(OrderTaxLine $t, string $currency): array
    {
        return [
            'code' => (string) $t->code, 'label' => (string) $t->label, 'applies_to' => (string) $t->applies_to, 'rate_basis_points' => (int) $t->rate_basis_points,
            'taxable_amount' => (new Money((int) $t->taxable_minor, $currency))->toArray(), 'amount' => (new Money((int) $t->amount_minor, $currency))->toArray(), 'included_in_prices' => (bool) $t->included_in_prices,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feeLine(OrderFeeLine $f, string $currency): array
    {
        return ['code' => (string) $f->code, 'label' => (string) $f->label, 'amount' => (new Money((int) $f->amount_minor, $currency))->toArray(), 'taxable' => (bool) $f->taxable];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * One timeline line with its sequence and a stable event id (Module 32): what clients deduplicate and order by.
     *
     * @return array<string, mixed>
     */
    public function eventLine(Order $order, OrderEvent $e, bool $internal): array
    {
        return ['sequence' => (int) $e->sequence, 'event_id' => (string) $order->public_id.'-'.(int) $e->sequence] + $this->line($e, $internal);
    }

    /**
     * How the pickup stands (Module 32): nothing to verify yet, available (READY), verified (method, when, by whom —
     * the staff member only for the restaurant / the administrator), or not possible any more.
     *
     * @return array<string, mixed>
     */
    public function pickupVerification(Order $order, bool $internal = false): array
    {
        $credential = $order->pickupCredential;
        $status = match (true) {
            $order->status === OrderStatus::PickedUp || $order->status === OrderStatus::Completed => 'VERIFIED',
            $order->status->isTerminal() => 'NOT_POSSIBLE',
            $order->status === OrderStatus::ReadyForPickup => 'AVAILABLE',
            default => 'NOT_YET',
        };

        return array_filter([
            'status' => $status,
            'method' => $status === 'VERIFIED' ? $credential?->consumed_method : null,
            'verified_at' => $status === 'VERIFIED' ? self::utc($credential?->consumed_at ?? $order->picked_up_at) : null,
            'verified_by' => $status === 'VERIFIED' && $internal && $credential?->consumedBy !== null ? ['id' => (string) $credential->consumedBy->public_id, 'name' => (string) $credential->consumedBy->name] : null,
            'credential_status' => $credential?->status->value,
            'completed_at' => self::utc($order->completed_at),
        ], fn ($v) => $v !== null);
    }

    /**
     * Where to listen and how often to ask when listening is not possible (Module 32). The channel is an address,
     * not a permission: the server authorizes every subscription.
     *
     * @return array<string, mixed>
     */
    public function realtimeHints(Order $order, bool $internal): array
    {
        return [
            'enabled' => (bool) config('orders.realtime.enabled'),
            'channel' => $internal ? 'restaurant.location.'.(string) ($order->location?->public_id ?? '') : 'customer.order.'.(string) $order->public_id,
            'event' => 'order.updated',
            'auth_endpoint' => $internal ? '/restaurant/broadcasting/auth' : '/customer/broadcasting/auth',
            'poll_seconds' => $order->status->isActive() ? (int) config('orders.realtime.poll_seconds_active') : (int) config('orders.realtime.poll_seconds_idle'),
            'terminal' => $order->status->isTerminal(),
        ];
    }

    private function line(OrderEvent $e, bool $internal): array
    {
        $detail = (array) ($e->detail ?? []);
        if (! $internal) {
            // The customer's timeline carries states and public reasons — never a staff note, a trigger or an id.
            $detail = array_intersect_key($detail, array_flip(['reason', 'message', 'amount_minor', 'currency', 'method']));
        }

        return [
            'at' => self::utc($e->occurred_at),
            'type' => (string) $e->type,
            'from' => $e->from_status,
            'to' => $e->to_status,
            'actor' => $internal ? (string) $e->actor_type : self::publicActor((string) $e->actor_type),
            'detail' => (object) $detail,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function times(Order $order): array
    {
        return [
            'placed_at' => self::utc($order->placed_at),
            'accepted_at' => self::utc($order->accepted_at),
            'rejected_at' => self::utc($order->rejected_at),
            'preparing_at' => self::utc($order->preparing_at),
            'ready_at' => self::utc($order->ready_at),
            'picked_up_at' => self::utc($order->picked_up_at),
            'completed_at' => self::utc($order->completed_at),
            'cancelled_at' => self::utc($order->cancelled_at),
        ];
    }

    private function customerNextAction(Order $order): string
    {
        return match ($order->status) {
            OrderStatus::Created, OrderStatus::WaitingForRestaurant => 'WAIT_FOR_RESTAURANT',
            OrderStatus::Accepted, OrderStatus::Preparing => 'TRACK',
            OrderStatus::ReadyForPickup => 'GO_TO_PICKUP',
            OrderStatus::PickedUp, OrderStatus::Completed => 'DONE',
            OrderStatus::Rejected, OrderStatus::Cancelled => $order->refund_status->value === 'NONE' ? 'ORDER_AGAIN' : 'AWAIT_REFUND',
        };
    }

    private static function publicActor(string $actor): string
    {
        return match ($actor) {
            OrderEvent::ACTOR_RESTAURANT => 'RESTAURANT',
            OrderEvent::ACTOR_CUSTOMER => 'YOU',
            default => 'FOODONTHEGO',
        };
    }

    private static function maskPhone(?string $phone): ?string
    {
        return $phone === null || strlen($phone) < 6 ? null : substr($phone, 0, 3).str_repeat('*', max(0, strlen($phone) - 7)).substr($phone, -4);
    }

    private static function local(?\DateTimeInterface $at, string $timezone): ?string
    {
        if ($at === null) {
            return null;
        }
        try {
            return CarbonImmutable::instance($at)->setTimezone($timezone)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function utc(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->toIso8601String();
    }
}
