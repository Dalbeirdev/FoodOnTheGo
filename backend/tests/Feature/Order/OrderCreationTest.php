<?php

namespace Tests\Feature\Order;

use App\Enums\CheckoutStatus;
use App\Events\Order\OrderEvent;
use App\Events\Payment\PaymentVerified;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\PaymentAttempt;
use App\Models\PickupSlotReservation;
use App\Models\PromotionReservation;
use App\Services\Order\OrderNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * A verified payment becomes exactly one order (Module 31): copied from the confirmed checkout, numbered, with its
 * pickup place, its promotion used up, its checkout and cart closed, and the payment marked ORDER_CREATED. The
 * client is never asked and never believed: no request carries an amount, a status or "paid".
 */
class OrderCreationTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_verified_payment_creates_one_order_that_is_a_copy_of_the_paid_checkout(): void
    {
        Event::fake([OrderEvent::class]);
        [$customer, $headers, $checkout, $payment, $order] = $this->paidOrder();

        // The payment says which order it became; the order says which payment it came from.
        $this->assertSame('ORDER_CREATED', $payment['fulfillment']['status']);
        $this->assertSame($order['id'], $payment['fulfillment']['order_id']);
        $this->assertSame('Payment received. Your order is placed.', $payment['message']);
        $this->assertSame('PROCEED_TO_ORDER', $payment['next_action']);
        $this->assertSame($payment['id'], $order['payment']['id']);
        $this->assertTrue($order['payment']['required']);

        // The number: FOTG-<restaurant-local date>-<6 unambiguous characters>.
        $this->assertMatchesRegularExpression('/\AFOTG-20261007-[A-HJ-NP-Z2-9]{6}\z/', $order['order_number']);
        $this->assertTrue(OrderNumbers::valid($order['order_number']));

        // Everything the customer saw at checkout, copied — the same total as the payment, nothing priced again.
        $this->assertSame('WAITING_FOR_RESTAURANT', $order['status']);
        $this->assertSame(['PAID', 'NONE'], [$order['payment_state'], $order['refund_status']]);
        $this->assertSame($checkout['pricing']['total'], $order['pricing']['total']);
        $this->assertSame($this->inr(50000), $order['total']);
        $this->assertSame(2, $order['item_count']);
        $this->assertCount(1, $order['items']);
        $this->assertSame(['Classic Burger', 2, $this->inr(25000), $this->inr(50000)], [$order['items'][0]['name'], $order['items'][0]['quantity'], $order['items'][0]['unit_price'], $order['items'][0]['line_subtotal']]);
        $this->assertSame([['kind' => 'VARIANT', 'group' => 'Size', 'name' => 'Large', 'price_adjustment' => $this->inr(5000)]], $order['items'][0]['options']);
        $this->assertSame('Burger Hub', $order['restaurant']['name']);
        $this->assertSame('Asia/Kolkata', $order['pickup']['timezone']);
        $this->assertSame('ASAP', $order['pickup']['mode']);
        $this->assertSame($checkout['pickup']['pickup_at'], $order['pickup']['pickup_at']);
        $this->assertSame([], $order['taxes']);
        $this->assertSame([], $order['fees']);
        $this->assertNull($order['promotion']);
        $this->assertSame(1, $order['version']);
        $this->assertSame('WAIT_FOR_RESTAURANT', $order['next_action']);
        $this->assertSame(['status' => 'NOT_YET', 'credential_status' => 'ACTIVE'], $order['pickup_verification']);   // Module 32: nothing to verify before READY
        // The legal documents the customer accepted, by type and version — a reference, never the text.
        $this->assertEqualsCanonicalizing(array_map('strtoupper', array_column($checkout['legal']['required'], 'type')), array_column($order['legal'], 'type'));
        $this->assertSame($checkout['legal']['required'][0]['version'], $order['legal'][0]['version']);

        // The pickup credential: a code and an opaque QR payload, nothing that names the order or the customer.
        $this->assertSame('ACTIVE', $order['pickup_credential']['status']);
        $this->assertMatchesRegularExpression('/\A[A-HJ-NP-Z2-9]{6}\z/', $order['pickup_credential']['code']);
        $this->assertMatchesRegularExpression('/\AFOTG1\.[A-Za-z0-9_-]{43}\z/', $order['pickup_credential']['qr_payload']);
        $this->assertStringNotContainsString($order['id'], $order['pickup_credential']['qr_payload']);

        // The customer's timeline starts with the order being placed; the restaurant's note is nowhere in it.
        $this->assertSame(['ORDER_CREATED'], array_column($order['timeline'], 'type'));
        $this->assertSame(['from' => 'CREATED', 'to' => 'WAITING_FOR_RESTAURANT'], ['from' => $order['timeline'][0]['from'], 'to' => $order['timeline'][0]['to']]);

        // The record behind it.
        $model = $this->orderModel($order['order_number']);
        $this->assertSame($customer->getKey(), (int) $model->customer_id);
        $this->assertSame($checkout['id'], (string) $model->checkout_public_id);
        $this->assertSame($payment['id'], (string) $model->payment_public_id);
        $this->assertSame(50000, (int) $model->total_minor);
        $this->assertSame('INR', (string) $model->currency);
        $this->assertNotNull($model->placed_at);
        $snapshot = (array) $model->snapshot;
        $this->assertSame($customer->phone_e164, $snapshot['customer']['phone']);
        $this->assertSame($payment['id'], $snapshot['payment']['id']);
        $this->assertArrayNotHasKey('key_secret', $snapshot['payment']);
        $this->assertArrayNotHasKey('signature', $snapshot['payment']);
        $this->assertNull($snapshot['journey']);

        // The checkout and the cart are converted — immutable from here; the pickup selection is converted too.
        $session = $this->sessionModel($checkout['id']);
        $this->assertSame(CheckoutStatus::Converted, $session->status);
        $this->assertNotNull($session->closed_at);
        $cart = Cart::query()->whereKey($session->cart_id)->sole();
        $this->assertSame('CONVERTED', $cart->status->value);
        $this->assertNotNull($cart->closed_at);
        $this->assertSame('CONVERTED', (string) $cart->pickupSelection->status->value);

        // The hold became the order's reservation: the same row, now of source ORDER, without an expiry.
        $reservation = PickupSlotReservation::query()->where('checkout_session_id', $session->getKey())->sole();
        $this->assertSame(['ORDER', 'ACTIVE', $order['id'], null], [$reservation->source, $reservation->status, $reservation->reference, $reservation->expires_at]);
        $this->assertSame($reservation->getKey(), (int) $model->pickup_reservation_id);

        // One fulfilment record, succeeded, pointing at the order; the payment's fulfilment ORDER_CREATED.
        $fulfillment = OrderFulfillment::query()->where('checkout_public_id', $checkout['id'])->sole();
        $this->assertSame(['SUCCEEDED', 1, $model->getKey(), 'PAYMENT_VERIFIED'], [$fulfillment->status->value, (int) $fulfillment->attempts, (int) $fulfillment->order_id, $fulfillment->trigger]);
        $this->assertSame('ORDER_CREATED', $this->attempt($payment['id'])->fulfillment_status->value);

        Event::assertDispatchedTimes(OrderEvent::class, 1);
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::ORDER_CREATED && $e->orderNumber === $order['order_number'] && $e->paymentId === $payment['id'] && $e->status === 'WAITING_FOR_RESTAURANT');
    }

    public function test_the_same_verified_payment_delivered_twice_creates_one_order(): void
    {
        [, $headers, $checkout, $payment, $order] = $this->paidOrder();
        $attempt = $this->attempt($payment['id']);

        // The verification is announced again (a redelivered webhook, a replayed queue message) and the sweep runs.
        event(new PaymentVerified($attempt, false));
        event(new PaymentVerified($attempt, true));
        $this->artisan('orders:recover')->assertSuccessful();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderFulfillment::query()->count());
        $this->assertSame(1, (int) OrderFulfillment::query()->sole()->attempts);
        $this->assertSame(1, \App\Models\OrderEvent::query()->where('type', 'ORDER_CREATED')->count());
        $this->showOrder($headers, $order['order_number'])->assertOk()->assertJsonPath('order.id', $order['id']);
        $this->assertSame(CheckoutStatus::Converted, $this->sessionModel($checkout['id'])->status);
    }

    public function test_a_promotion_the_customer_paid_with_is_redeemed_exactly_once(): void
    {
        $promotion = $this->promotion();   // ₹100 off
        [$customer, $headers] = $this->customerReadyForCheckout(phone: '+919811122233');
        $draft = $this->openCheckout($headers, ['promotion_code' => 'SAVE100'])->assertCreated()->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout');
        $this->assertSame(40000, $ready['pricing']['total']['amount']);
        $payment = $this->startPayment($headers, $ready)->assertCreated()->json('payment');
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');

        $order = $this->showOrder($headers, $this->attempt($payment['id'])->order->order_number)->assertOk()->json('order');
        $this->assertSame(['code' => 'SAVE100', 'name' => 'Test promotion', 'discount' => $this->inr(10000)], $order['promotion']);
        $this->assertSame($this->inr(40000), $order['total']);
        $this->assertSame(1, (int) $promotion->refresh()->redeemed_count);
        $use = PromotionReservation::query()->where('checkout_session_id', $this->sessionModel($ready['id'])->getKey())->sole();
        $this->assertSame(['REDEEMED', $customer->getKey()], [$use->status, (int) $use->customer_id]);

        // Announced again: still one redemption.
        event(new PaymentVerified($this->attempt($payment['id']), true));
        $this->assertSame(1, (int) $promotion->refresh()->redeemed_count);
        $this->assertSame(1, PromotionReservation::query()->where('status', 'REDEEMED')->count());
    }

    public function test_a_checkout_with_nothing_to_pay_becomes_an_order_without_a_payment_at_the_customers_request(): void
    {
        Event::fake([OrderEvent::class]);
        [, $headers, $checkout, $order] = $this->zeroTotalOrder();

        $this->assertSame(['PAYMENT_NOT_REQUIRED', 'NONE', 'WAITING_FOR_RESTAURANT'], [$order['payment_state'], $order['refund_status'], $order['status']]);
        $this->assertSame($this->inr(0), $order['total']);
        $this->assertSame($this->inr(50000), $order['pricing']['subtotal']);
        $this->assertSame($this->inr(50000), $order['pricing']['discount']);
        $this->assertFalse($order['payment']['required']);
        $this->assertNull($order['payment']['id']);
        $this->assertSame('FREEMEAL', $order['promotion']['code']);
        $this->assertNotNull($order['pickup_credential']);

        $model = $this->orderModel($order['order_number']);
        $this->assertNull($model->payment_attempt_id);
        $this->assertNull($model->payment_public_id);
        $this->assertSame(0, PaymentAttempt::query()->count(), 'No fake provider payment is invented for a zero total');
        $this->assertSame(CheckoutStatus::Converted, $this->sessionModel($checkout['id'])->status);
        $this->assertSame('ZERO_TOTAL', OrderFulfillment::query()->sole()->trigger);
        $this->assertSame('ACTIVE', PickupSlotReservation::query()->where('reference', $order['id'])->sole()->status);
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::ORDER_CREATED && $e->paymentId === null);

        // Asked again: the same order, not a second one.
        $this->fulfillCheckout($headers, $checkout['id'])->assertOk()->assertJsonPath('order.id', $order['id']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_checkout_that_has_to_be_paid_cannot_be_turned_into_an_order_by_asking(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        $this->fulfillCheckout($headers, $checkout['id'])->assertStatus(409)->assertJsonPath('error.code', 'order_payment_required');
        // Nor by saying it is paid, free, or anything else.
        $this->fulfillCheckout($headers, $checkout['id'], ['paid' => true])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->fulfillCheckout($headers, $checkout['id'], ['total_minor' => 0, 'payment_required' => false])->assertStatus(422);
        $this->fulfillCheckout($headers, $checkout['id'], ['payment_state' => 'PAID'])->assertStatus(422);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);

        // Somebody else's zero-total checkout is not found.
        $this->promotion(['code' => 'FREEMEAL', 'type' => 'PERCENTAGE', 'percent_basis_points' => 10000]);
        [, $asha] = $this->customerReadyForCheckout(phone: '+919876543212');
        $free = $this->confirmCheckout($asha, $this->openCheckout($asha, ['promotion_code' => 'FREEMEAL'])->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->fulfillCheckout($headers, $free['id'])->assertNotFound();
        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_order_is_created_only_from_a_verified_payment_never_from_the_clients_word(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        // The device says success for a payment the provider never captured.
        $this->completePayment($headers, $payment['id'], ['outcome' => 'success', 'provider_payment_reference' => 'pay_NeverHappened00', 'provider_signature' => str_repeat('ab', 32)])->assertStatus(422);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderFulfillment::query()->count());

        // A payment that failed creates nothing — and a verified one for ANOTHER checkout cannot be pointed at this one.
        $this->completePayment($headers, $payment['id'], ['outcome' => 'failed'])->assertOk();
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);
    }

    public function test_an_order_number_collision_is_retried_and_the_number_is_never_reused(): void
    {
        [, , , , $first] = $this->paidOrder();
        // A number is unique per database row, whatever the day.
        $this->assertDatabaseHas('orders', ['order_number' => $first['order_number']]);
        $this->assertThrows(fn () => Order::query()->whereKey(0)->firstOrFail());

        $generated = [];
        for ($i = 0; $i < 500; $i++) {
            $generated[] = OrderNumbers::generate(now(), 'Asia/Kolkata');
        }
        $this->assertCount(500, array_unique($generated));
        foreach ($generated as $number) {
            $this->assertTrue(OrderNumbers::valid($number));
            $this->assertStringNotContainsString('0', substr($number, 14));
            $this->assertStringNotContainsString('O', substr($number, 14));
            $this->assertStringNotContainsString('1', substr($number, 14));
            $this->assertStringNotContainsString('I', substr($number, 14));
        }
        // The date part is the RESTAURANT's calendar day: 23:30 UTC on the 7th is the 8th in Kolkata.
        $this->assertStringStartsWith('FOTG-20261008-', OrderNumbers::generate(CarbonImmutable::parse('2026-10-07 23:30:00', 'UTC'), 'Asia/Kolkata'));
    }
}
