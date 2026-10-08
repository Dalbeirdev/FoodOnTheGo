<?php

namespace App\Services\Order;

use App\Enums\CartCloseReason;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PickupSelectionStatus;
use App\Enums\RestaurantStatus;
use App\Events\Order\OrderEvent;
use App\Exceptions\ApiException;
use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderEvent as Line;
use App\Models\OrderFeeLine;
use App\Models\OrderFulfillment;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderTaxLine;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\PickupSlotReservation;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Services\Checkout\PickupSlotHoldService;
use App\Services\Payment\PaymentStateMachine;
use App\Services\Pickup\PickupCapacityRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a confirmed checkout into exactly one order (Module 31).
 *
 * Trusted triggers only: a VERIFIED payment attempt of the checkout (PaymentVerified, the queue, the sweep, an
 * administrator's retry) or a confirmed checkout with nothing to pay. Never a client's word.
 *
 * One transaction, under the lock of the checkout's fulfilment row (one row per checkout — the row every worker
 * waits on, so of two workers exactly one creates the order and the other finds it), then the checkout's row and
 * the payment attempt's row:
 *
 *   judge     the payment is VERIFIED for exactly this checkout's total and currency (or nothing is to be paid);
 *             the checkout is still the one that was paid; the restaurant and the market still take orders —
 *             the PRICE is not judged again: the customer paid the confirmed price
 *   capacity  the pickup hold becomes the order's reservation, or the slot is taken again atomically
 *   create    the order and its items, options, taxes, fees, timeline and pickup credential — copied, never priced
 *   finalize  promotion use redeemed; checkout, cart and pickup selection CONVERTED; payment → ORDER_CREATED
 *
 * What cannot be made as it stands (no capacity, restaurant or market unavailable, payment that does not match)
 * is handed to a person: the fulfilment is REVIEW_REQUIRED, the payment RECOVERY_REQUIRED with the reason, and the
 * money is never touched. A technical failure is tried again with a growing wait. Nothing here is ever silent.
 */
final class OrderFulfillmentService
{
    public const ORDER_PAYMENT_NOT_VERIFIED = 'ORDER_PAYMENT_NOT_VERIFIED';

    public const ORDER_PAYMENT_MISMATCH = 'ORDER_PAYMENT_MISMATCH';

    public const ORDER_CHECKOUT_INVALID = 'ORDER_CHECKOUT_INVALID';

    public const ORDER_PICKUP_CAPACITY_UNAVAILABLE = 'ORDER_PICKUP_CAPACITY_UNAVAILABLE';

    public const ORDER_RESTAURANT_UNAVAILABLE = 'ORDER_RESTAURANT_UNAVAILABLE';

    public const ORDER_MARKET_UNAVAILABLE = 'ORDER_MARKET_UNAVAILABLE';

    public const ORDER_CUSTOMER_MISSING = 'ORDER_CUSTOMER_MISSING';

    public const ORDER_CREATION_FAILED = 'ORDER_CREATION_FAILED';

    public function __construct(
        private readonly PickupSlotHoldService $holds,
        private readonly PickupCapacityRepository $capacity,
        private readonly OrderStateMachine $machine,
        private readonly PaymentStateMachine $payments,
        private readonly PickupCredentials $credentials,
        private readonly OrderMetrics $metrics,
    ) {}

    /**
     * A verified payment's order — created now, or found. The entry for the queue, the sweep and the administrator.
     *
     * @throws ApiException 404 payment_not_found
     */
    public function fulfillPayment(string $paymentPublicId, string $trigger = OrderFulfillment::TRIGGER_PAYMENT_VERIFIED, ?int $adminId = null, ?CarbonInterface $at = null): OrderFulfillment
    {
        $attempt = PaymentAttempt::query()->where('public_id', $paymentPublicId)->first()
            ?? throw ApiException::notFound('payment_not_found', 'This payment does not exist.');

        return $this->run((string) $attempt->checkout_public_id, $trigger, (int) $attempt->getKey(), $adminId, $at);
    }

    /**
     * A confirmed checkout with nothing to pay becomes an order at the customer's request. A checkout that has to
     * be paid is refused: paying it is the only way to an order.
     *
     * @throws ApiException 404 checkout_not_found; 409 order_payment_required / order_* (what blocked it)
     */
    public function fulfillZeroTotal(Customer $customer, string $checkoutPublicId, ?CarbonInterface $at = null): OrderFulfillment
    {
        $session = CheckoutSession::query()->where('public_id', $checkoutPublicId)->where('customer_id', $customer->getKey())->first()
            ?? throw ApiException::notFound('checkout_not_found', 'This checkout does not exist.');
        if ((bool) $session->payment_required) {
            throw ApiException::conflict('order_payment_required', 'This checkout has to be paid before it becomes an order.', ['next_action' => 'PAY']);
        }
        $fulfillment = $this->run($checkoutPublicId, OrderFulfillment::TRIGGER_ZERO_TOTAL, null, null, $at);
        if ($fulfillment->order_id === null) {
            throw ApiException::conflict(strtolower((string) ($fulfillment->last_error_code ?? self::ORDER_CREATION_FAILED)), self::customerMessage((string) $fulfillment->last_error_code), ['fulfillment_status' => $fulfillment->status->value]);
        }

        return $fulfillment;
    }

    /**
     * An administrator asks for the order to be created again (recovery). The payment is not touched: whether it
     * is verified is still the payment domain's word.
     *
     * @throws ApiException 409 order_already_exists
     */
    public function retry(OrderFulfillment $fulfillment, int $adminId, ?CarbonInterface $at = null): OrderFulfillment
    {
        if ($fulfillment->order_id !== null) {
            throw ApiException::conflict('order_already_exists', 'This checkout already became an order.', ['order_id' => (string) $fulfillment->order?->public_id]);
        }

        return $this->run((string) $fulfillment->checkout_public_id, OrderFulfillment::TRIGGER_RECOVERY, $fulfillment->payment_attempt_id === null ? null : (int) $fulfillment->payment_attempt_id, $adminId, $at);
    }

    /**
     * The safety net: tries that are due again, and verified payments nobody turned into an order (a lost job).
     *
     * @return array{retried: int, picked_up: int}
     */
    public function sweep(?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $retried = 0;
        foreach (OrderFulfillment::query()->where('status', OrderFulfillmentStatus::Retrying->value)->where('next_attempt_at', '<=', $at)->orderBy('next_attempt_at')->limit(100)->get() as $row) {
            $this->run((string) $row->checkout_public_id, OrderFulfillment::TRIGGER_RECOVERY, $row->payment_attempt_id === null ? null : (int) $row->payment_attempt_id, null, $at);
            $retried++;
        }
        $pickedUp = 0;
        $settled = OrderFulfillment::query()->whereIn('status', [OrderFulfillmentStatus::Succeeded->value, OrderFulfillmentStatus::ReviewRequired->value, OrderFulfillmentStatus::Failed->value, OrderFulfillmentStatus::Retrying->value])->select('checkout_public_id');
        $waiting = PaymentAttempt::query()->where('status', PaymentStatus::Verified->value)->where('fulfillment_status', PaymentFulfillmentStatus::OrderPending->value)
            ->where('verified_at', '<=', $at->subSeconds(60))->whereNotIn('checkout_public_id', $settled)->orderBy('verified_at')->limit(100)->get();
        foreach ($waiting as $attempt) {
            $this->run((string) $attempt->checkout_public_id, OrderFulfillment::TRIGGER_RECOVERY, (int) $attempt->getKey(), null, $at);
            $pickedUp++;
        }

        return ['retried' => $retried, 'picked_up' => $pickedUp];
    }

    public static function customerMessage(string $code): string
    {
        return match ($code) {
            self::ORDER_PICKUP_CAPACITY_UNAVAILABLE => 'The pickup time is no longer available. Please choose another time.',
            self::ORDER_RESTAURANT_UNAVAILABLE => 'The restaurant is not taking orders right now.',
            self::ORDER_MARKET_UNAVAILABLE => 'Ordering is paused in this area right now.',
            self::ORDER_CHECKOUT_INVALID => 'This checkout can no longer become an order. Please review your order again.',
            self::ORDER_PAYMENT_NOT_VERIFIED, self::ORDER_PAYMENT_MISMATCH => 'This payment could not be matched to the order.',
            default => 'Your order could not be created right now. Nothing was charged twice — please try again in a moment.',
        };
    }

    /* ------------------------------------------------------------------ the one path */

    private function run(string $checkoutPublicId, string $trigger, ?int $attemptId, ?int $adminId, ?CarbonInterface $at): OrderFulfillment
    {
        $at = CarbonImmutable::instance($at ?? now());
        $started = hrtime(true);
        try {
            $fulfillment = DB::transaction(function () use ($checkoutPublicId, $trigger, $attemptId, $adminId, $at, $started): OrderFulfillment {
                $fulfillment = $this->lockRow($checkoutPublicId, $trigger, $attemptId, $adminId);
                if ($fulfillment->order_id !== null || $fulfillment->status === OrderFulfillmentStatus::Succeeded) {
                    return $fulfillment; // exactly once: the order exists
                }
                $fulfillment->forceFill(['status' => OrderFulfillmentStatus::Processing->value, 'attempts' => (int) $fulfillment->attempts + 1, 'started_at' => $fulfillment->started_at ?? $at])->save();

                $session = CheckoutSession::query()->with(['customer', 'location.organization', 'location.market', 'location.pickupSettings', 'cart.pickupSelection', 'legalAcceptances'])
                    ->where('public_id', $checkoutPublicId)->lockForUpdate()->first();
                $attempt = $attemptId === null ? null : PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

                // A checkout that already became an order through another path: that order is this fulfilment's.
                $existing = Order::query()->where('checkout_public_id', $checkoutPublicId)->first();
                if ($existing !== null) {
                    $fulfillment->forceFill(['status' => OrderFulfillmentStatus::Succeeded->value, 'order_id' => $existing->getKey(), 'finished_at' => $at, 'last_error_code' => null])->save();

                    return $fulfillment;
                }

                $blocked = $this->judge($session, $attempt, $trigger, $at) ?? $this->create($fulfillment, $session, $attempt, $trigger, $adminId, $at, $started);
                if ($blocked !== null) {
                    $this->blocked($fulfillment, $attempt, $blocked, $trigger, $adminId, $at);
                }

                return $fulfillment;
            });
        } catch (Throwable $e) {
            return $this->technicalFailure($checkoutPublicId, $trigger, $attemptId, $adminId, $e, $at);
        }

        return $fulfillment;
    }

    /**
     * The checkout's fulfilment row, created if it is the first time and locked. Two workers meet here: the second
     * waits for the first and then finds what it did.
     */
    private function lockRow(string $checkoutPublicId, string $trigger, ?int $attemptId, ?int $adminId): OrderFulfillment
    {
        $row = OrderFulfillment::query()->where('checkout_public_id', $checkoutPublicId)->lockForUpdate()->first();
        if ($row === null) {
            $session = CheckoutSession::query()->where('public_id', $checkoutPublicId)->first();
            $attempt = $attemptId === null ? null : PaymentAttempt::query()->whereKey($attemptId)->first();
            try {
                // Written in a savepoint: a worker that was a moment faster leaves its row for us to lock.
                DB::transaction(function () use ($checkoutPublicId, $trigger, $attemptId, $adminId, $session, $attempt): void {
                    (new OrderFulfillment)->forceFill([
                        'checkout_session_id' => $session?->getKey(),
                        'checkout_public_id' => $checkoutPublicId,
                        'customer_id' => $session?->customer_id,
                        'payment_attempt_id' => $attemptId,
                        'payment_public_id' => $attempt === null ? null : (string) $attempt->public_id,
                        'trigger' => $trigger,
                        'status' => OrderFulfillmentStatus::Pending->value,
                        'admin_user_id' => $adminId,
                    ])->save();
                });
            } catch (UniqueConstraintViolationException) {
                // somebody else created it: locked below
            }
            $row = OrderFulfillment::query()->where('checkout_public_id', $checkoutPublicId)->lockForUpdate()->firstOrFail();
        }
        $fill = ['trigger' => $trigger];
        if ($attemptId !== null && $row->payment_attempt_id === null) {
            $fill['payment_attempt_id'] = $attemptId;
            $fill['payment_public_id'] = (string) PaymentAttempt::query()->whereKey($attemptId)->value('public_id');
        }
        if ($adminId !== null) {
            $fill['admin_user_id'] = $adminId;
        }
        $row->forceFill($fill)->save();

        return $row;
    }

    /**
     * Why the order cannot be made as it stands — null when it can. The price is not judged: it was paid.
     */
    private function judge(?CheckoutSession $session, ?PaymentAttempt $attempt, string $trigger, CarbonImmutable $at): ?string
    {
        if ($session === null) {
            return self::ORDER_CHECKOUT_INVALID;
        }
        if ($trigger === OrderFulfillment::TRIGGER_ZERO_TOTAL || ($attempt === null && ! (bool) $session->payment_required)) {
            if ((bool) $session->payment_required || (int) $session->total_minor !== 0) {
                return self::ORDER_PAYMENT_NOT_VERIFIED;
            }
            if ($session->status !== CheckoutStatus::Ready || $at->greaterThanOrEqualTo($session->expires_at)) {
                return self::ORDER_CHECKOUT_INVALID;
            }
        } else {
            if ($attempt === null || $attempt->status !== PaymentStatus::Verified) {
                return self::ORDER_PAYMENT_NOT_VERIFIED;
            }
            if ((string) $attempt->checkout_public_id !== (string) $session->public_id) {
                return self::ORDER_CHECKOUT_INVALID;
            }
            if ((int) $attempt->amount_minor !== (int) $session->total_minor || (string) $attempt->currency !== (string) $session->currency || ! (bool) $session->payment_required) {
                return self::ORDER_PAYMENT_MISMATCH;
            }
            if ($session->status === CheckoutStatus::Ready && $at->lessThan($session->expires_at)) {
                // A late success found the checkout given back to the customer: it is taken back for the order.
                $session->forceFill(['status' => CheckoutStatus::PaymentPending->value, 'payment_started_at' => $session->payment_started_at ?? $at])->save();
            }
            if ($session->status !== CheckoutStatus::PaymentPending) {
                return self::ORDER_CHECKOUT_INVALID;
            }
        }
        if ($session->customer === null) {
            return self::ORDER_CUSTOMER_MISSING;
        }
        $location = $session->location;
        if ($location === null || $location->status !== RestaurantStatus::Approved) {
            return self::ORDER_RESTAURANT_UNAVAILABLE;
        }
        if (! $location->market->status->isServingCustomers()) {
            return self::ORDER_MARKET_UNAVAILABLE;
        }
        if (! $location->acceptsOrdersAt($at)) {
            return self::ORDER_RESTAURANT_UNAVAILABLE;
        }

        return null;
    }

    /**
     * Makes the order. Returns null when done, or the code of what stopped it (capacity).
     */
    private function create(OrderFulfillment $fulfillment, CheckoutSession $session, ?PaymentAttempt $attempt, string $trigger, ?int $adminId, CarbonImmutable $at, int $started): ?string
    {
        $publicId = (string) Str::uuid();
        $location = $session->location;

        // The place the checkout held becomes the order's; a hold that ran out is taken again — atomically, or not at all.
        $reservation = $this->holds->consume($session, $publicId, $at);
        if ($reservation === null) {
            $reservation = $this->capacity->reserve($location, $session->pickup_slot_starts_at, $session->pickup_slot_ends_at, $location->pickupSettings?->capacity_per_slot, 1, PickupSlotReservation::SOURCE_ORDER, $publicId, null);
            if ($reservation === null) {
                return self::ORDER_PICKUP_CAPACITY_UNAVAILABLE;
            }
        }

        $columns = OrderSnapshot::columns($session, $attempt) + [
            'public_id' => $publicId,
            'status' => OrderStatus::WaitingForRestaurant->value,
            'payment_state' => $attempt === null ? OrderPaymentState::PaymentNotRequired->value : OrderPaymentState::Paid->value,
            'refund_status' => OrderRefundStatus::None->value,
            'pickup_reservation_id' => $reservation->getKey(),
            'placed_at' => $at,
            'version' => 1,
        ];
        $order = $this->insertWithNumber($columns, $at, (string) $session->restaurant_timezone);

        foreach (OrderSnapshot::items($session) as $entry) {
            $item = (new OrderItem)->forceFill($entry['line'] + ['order_id' => $order->getKey()]);
            $item->save();
            foreach ($entry['options'] as $option) {
                (new OrderItemOption)->forceFill($option + ['order_item_id' => $item->getKey()])->save();
            }
        }
        foreach (OrderSnapshot::taxLines($session) as $line) {
            (new OrderTaxLine)->forceFill($line + ['order_id' => $order->getKey()])->save();
        }
        foreach (OrderSnapshot::feeLines($session) as $line) {
            (new OrderFeeLine)->forceFill($line + ['order_id' => $order->getKey()])->save();
        }
        $this->credentials->issue($order);
        $this->machine->note($order, Line::ORDER_CREATED, Line::ACTOR_SYSTEM, null, OrderStatus::Created, OrderStatus::WaitingForRestaurant, ['trigger' => $trigger, 'payment' => $attempt === null ? null : (string) $attempt->public_id], $at);

        $this->redeemPromotion($session, $order);
        $session->forceFill(['status' => CheckoutStatus::Converted->value, 'status_reason' => 'ORDER_CREATED', 'closed_at' => $at])->save();
        $cart = $session->cart;
        if ($cart !== null && $cart->status === CartStatus::Active) {
            $cart->close(CartCloseReason::Converted, $at);
            $cart->save();
        }
        $cart?->pickupSelection?->forceFill(['status' => PickupSelectionStatus::Converted->value])->save();
        if ($attempt !== null) {
            $this->payments->fulfillment($attempt, PaymentFulfillmentStatus::OrderCreated, null, $adminId === null ? PaymentAttemptEvent::SOURCE_JOB : PaymentAttemptEvent::SOURCE_ADMIN, $adminId);
        }
        $fulfillment->forceFill(['status' => OrderFulfillmentStatus::Succeeded->value, 'order_id' => $order->getKey(), 'finished_at' => $at, 'last_error_code' => null, 'last_error_detail' => null, 'next_attempt_at' => null])->save();
        $fulfillment->setRelation('order', $order); // the caller can tell a creation (wasRecentlyCreated) from a find

        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        DB::afterCommit(function () use ($order, $trigger, $attempt, $ms): void {
            $this->metrics->record('created');
            if ($attempt === null) {
                $this->metrics->record('zero_total');
            }
            $this->metrics->recordCreationLatency($ms);
            event(new OrderEvent(OrderEvent::ORDER_CREATED, $order, ['trigger' => $trigger]));
        });

        return null;
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function insertWithNumber(array $columns, CarbonImmutable $placedAt, string $timezone): Order
    {
        for ($try = 1; ; $try++) {
            $order = (new Order)->forceFill($columns + ['order_number' => OrderNumbers::generate($placedAt, $timezone)]);
            try {
                DB::transaction(fn () => $order->save()); // a savepoint: a collision leaves the transaction usable

                return $order;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 5 || ! str_contains($e->getMessage(), 'orders_order_number_unique')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The promotion the customer paid with is used up: its reservation becomes a redemption and the promotion's
     * count grows, under the promotion's row lock. A reservation the housekeeping had already released (the payment
     * took longer than the hold) is redeemed all the same: the discount was paid.
     */
    private function redeemPromotion(CheckoutSession $session, Order $order): void
    {
        if ($session->promotion_id === null) {
            return;
        }
        $promotion = Promotion::query()->whereKey($session->promotion_id)->lockForUpdate()->first();
        if ($promotion === null) {
            return;
        }
        $use = PromotionReservation::query()->where('checkout_session_id', $session->getKey())->first() ?? new PromotionReservation;
        if ($use->status === PromotionReservation::STATUS_REDEEMED) {
            return;
        }
        $use->forceFill(['promotion_id' => $promotion->getKey(), 'checkout_session_id' => $session->getKey(), 'customer_id' => $session->customer_id, 'status' => PromotionReservation::STATUS_REDEEMED, 'expires_at' => null])->save();
        $promotion->forceFill(['redeemed_count' => (int) $promotion->redeemed_count + 1])->save();
        $this->machine->note($order, 'promotion_redeemed', Line::ACTOR_SYSTEM, null, null, null, ['promotion_code' => (string) $session->promotion_code, 'discount_minor' => (int) $session->discount_minor], $order->placed_at, null, false);
    }

    /**
     * The order cannot be made as it stands. The money is not touched; a person is asked.
     */
    private function blocked(OrderFulfillment $fulfillment, ?PaymentAttempt $attempt, string $code, string $trigger, ?int $adminId, CarbonImmutable $at): void
    {
        $final = $attempt === null ? OrderFulfillmentStatus::Failed : OrderFulfillmentStatus::ReviewRequired;
        $fulfillment->forceFill(['status' => $final->value, 'last_error_code' => $code, 'last_error_detail' => null, 'next_attempt_at' => null, 'finished_at' => $at])->save();
        if ($attempt !== null && $attempt->status === PaymentStatus::Verified) {
            $this->payments->fulfillment($attempt, PaymentFulfillmentStatus::RecoveryRequired, $code, $adminId === null ? PaymentAttemptEvent::SOURCE_JOB : PaymentAttemptEvent::SOURCE_ADMIN, $adminId);
        }
        Log::warning('order.fulfillment_blocked', ['checkout' => (string) $fulfillment->checkout_public_id, 'payment' => $fulfillment->payment_public_id, 'code' => $code, 'trigger' => $trigger, 'attempts' => (int) $fulfillment->attempts]);
        DB::afterCommit(fn () => $this->metrics->record($attempt === null ? 'creation_failed' : 'recovery_required'));
    }

    /**
     * Something broke (a database error, an exception): nothing of the try is kept except the fact that it was
     * tried. Tried again with a growing wait; after the last try a person is asked.
     */
    private function technicalFailure(string $checkoutPublicId, string $trigger, ?int $attemptId, ?int $adminId, Throwable $e, CarbonImmutable $at): OrderFulfillment
    {
        Log::error('order.fulfillment_failed', ['checkout' => $checkoutPublicId, 'trigger' => $trigger, 'error' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 200)]);

        return DB::transaction(function () use ($checkoutPublicId, $trigger, $attemptId, $adminId, $e, $at): OrderFulfillment {
            $row = $this->lockRow($checkoutPublicId, $trigger, $attemptId, $adminId);
            if ($row->order_id !== null) {
                return $row;
            }
            $attempts = (int) $row->attempts + 1;
            $backoff = (array) config('orders.fulfillment.backoff_seconds');
            $max = max(1, (int) config('orders.fulfillment.max_attempts'));
            $again = $attempts < $max;
            $row->forceFill([
                'status' => ($again ? OrderFulfillmentStatus::Retrying : OrderFulfillmentStatus::ReviewRequired)->value,
                'attempts' => $attempts,
                'last_error_code' => self::ORDER_CREATION_FAILED,
                'last_error_detail' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 300),
                'next_attempt_at' => $again ? $at->addSeconds((int) ($backoff[min($attempts, count($backoff)) - 1] ?? 60)) : null,
                'finished_at' => $again ? null : $at,
            ])->save();
            if (! $again && $attemptId !== null) {
                $attempt = PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
                if ($attempt !== null && $attempt->status === PaymentStatus::Verified) {
                    $this->payments->fulfillment($attempt, PaymentFulfillmentStatus::RecoveryRequired, self::ORDER_CREATION_FAILED, PaymentAttemptEvent::SOURCE_JOB, null);
                }
            }
            DB::afterCommit(fn () => $this->metrics->record($again ? 'creation_failed' : 'review_required'));

            return $row;
        });
    }
}
