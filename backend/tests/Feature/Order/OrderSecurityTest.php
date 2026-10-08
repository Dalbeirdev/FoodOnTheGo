<?php

namespace Tests\Feature\Order;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PickupSlotReservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * What must never happen to an order (Module 31): a client setting its money or state, a customer cancelling, a
 * restaurant reaching another tenant's orders, an administrator editing one by hand, a credential leaking, and the
 * database accepting an inconsistent row.
 */
class OrderSecurityTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_database_refuses_an_order_whose_money_or_state_is_inconsistent(): void
    {
        [, , , , $order] = $this->paidOrder();
        $row = (array) DB::table('orders')->where('public_id', $order['id'])->first();
        unset($row['id']);
        $refused = function (array $changes, string $constraint) use ($row): void {
            try {
                DB::transaction(fn () => DB::table('orders')->insert([...$row, ...$changes, 'public_id' => Str::uuid()->toString(), 'order_number' => \App\Services\Order\OrderNumbers::generate(now(), 'Asia/Kolkata'), 'checkout_public_id' => Str::uuid()->toString(), 'checkout_session_id' => null, 'payment_attempt_id' => null, 'payment_public_id' => array_key_exists('payment_public_id', $changes) ? $changes['payment_public_id'] : Str::uuid()->toString()]));
                $this->fail("A row violating {$constraint} was accepted");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        };
        $refused(['total_minor' => 49999], 'orders_total_check');                                           // total ≠ subtotal − discount + tax + fee
        $refused(['status' => 'CREATED'], 'orders_status_check');                                           // CREATED never persists
        $refused(['status' => 'SHIPPED'], 'orders_status_check');
        $refused(['payment_state' => 'PAYMENT_NOT_REQUIRED'], 'orders_paid_check');                         // not required but a payment reference
        $refused(['payment_state' => 'PAYMENT_NOT_REQUIRED', 'payment_public_id' => null], 'orders_paid_total_check');   // not required but a total
        $refused(['status' => 'REJECTED'], 'orders_rejection_check');                                       // rejected without a reason and a time
        $refused(['rejection_reason' => 'ITEM_UNAVAILABLE'], 'orders_rejection_check');                    // a reason on an order that is not rejected
        $refused(['status' => 'REJECTED', 'rejection_reason' => 'CUSTOMER_IS_RUDE', 'rejected_at' => now()], 'orders_rejection_reason_check');   // a reason that is not one of the five
        $refused(['status' => 'CANCELLED'], 'orders_cancellation_check');
        $refused(['refund_status' => 'MAYBE'], 'orders_refund_check');
        $refused(['version' => 0], 'orders_version_check');
        $refused(['currency' => 'inr'], 'orders_currency_check');
        // The number's form is a constraint too, and a number is unique.
        try {
            DB::transaction(fn () => DB::table('orders')->insert([...$row, 'public_id' => Str::uuid()->toString(), 'order_number' => 'FOTG-20261007-0O1I00', 'checkout_public_id' => Str::uuid()->toString(), 'checkout_session_id' => null, 'payment_attempt_id' => null]));
            $this->fail('A number with ambiguous characters was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('orders_number_check', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('orders')->insert([...$row, 'public_id' => Str::uuid()->toString(), 'checkout_public_id' => Str::uuid()->toString(), 'checkout_session_id' => null, 'payment_attempt_id' => null]));
            $this->fail('A duplicate order number was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('orders_order_number_unique', $e->getMessage());
        }
        // One order per checkout, one per payment, one credential per order, one open refund per order.
        foreach ([['checkout_public_id' => $order['id']], ['payment_public_id' => null]] as $dup) {
            // (covered by the unique indexes asserted below)
            $this->assertIsArray($dup);
        }
        $indexes = collect(DB::select('select indexname from pg_indexes where tablename = ?', ['orders']))->pluck('indexname')->all();
        foreach (['orders_order_number_unique', 'orders_checkout_session_id_unique', 'orders_checkout_public_id_unique', 'orders_payment_attempt_id_unique', 'orders_public_id_unique'] as $name) {
            $this->assertContains($name, $indexes);
        }
        $this->assertContains('order_pickup_credentials_order_id_unique', collect(DB::select('select indexname from pg_indexes where tablename = ?', ['order_pickup_credentials']))->pluck('indexname')->all());
        $this->assertContains('order_refunds_one_open_per_order', collect(DB::select('select indexname from pg_indexes where tablename = ?', ['order_refunds']))->pluck('indexname')->all());
        $this->assertContains('order_fulfillments_checkout_public_id_unique', collect(DB::select('select indexname from pg_indexes where tablename = ?', ['order_fulfillments']))->pluck('indexname')->all());
    }

    public function test_an_order_is_never_deleted_with_its_checkout_cart_or_payment(): void
    {
        [, $headers, $checkout, $payment, $order] = $this->paidOrder();
        $session = $this->sessionModel($checkout['id']);
        $cartId = $session->cart_id;

        DB::table('checkout_sessions')->where('id', $session->getKey())->delete();
        DB::table('carts')->where('id', $cartId)->delete();
        DB::table('payment_attempts')->where('public_id', $payment['id'])->delete();

        $model = $this->orderModel($order['order_number']);
        $this->assertNull($model->checkout_session_id);
        $this->assertNull($model->payment_attempt_id);
        $this->assertSame($checkout['id'], (string) $model->checkout_public_id);   // the references by public id stay
        $this->assertSame($payment['id'], (string) $model->payment_public_id);
        $this->assertSame(50000, (int) $model->total_minor);
        $this->assertCount(1, $model->items);
        $this->showOrder($headers, $order['order_number'])->assertOk()->assertJsonPath('order.payment.id', $payment['id']);

        // The restaurant and the customer cannot be deleted while they have orders.
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('restaurant_locations')->where('id', $model->location_id)->delete()), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('customers')->where('id', $model->customer_id)->delete()), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $model->getKey())->update(['total_minor' => 1])), QueryException::class);   // the total equation holds on update too
    }

    public function test_customers_cannot_cancel_or_change_orders_and_administrators_cannot_edit_them_by_hand(): void
    {
        [, $headers, , , $order] = $this->paidOrder();
        $n = $order['order_number'];

        // Nothing a customer can send changes an order.
        foreach (['/api/v1/customer/orders/'.$n.'/cancel', '/api/v1/customer/orders/'.$n.'/accept', '/api/v1/orders/'.$n.'/cancel'] as $url) {
            $this->postJson($url, [], $this->using($headers))->assertNotFound();
        }
        $this->patchJson('/api/v1/customer/orders/'.$n, ['status' => 'CANCELLED'], $this->using($headers))->assertStatus(405);
        $this->deleteJson('/api/v1/customer/orders/'.$n, [], $this->using($headers))->assertStatus(405);
        $this->assertSame('WAITING_FOR_RESTAURANT', $this->orderModel($n)->status->value);

        // An administrator cancels with a reason — audited — and cannot set a status, an amount or a refund state.
        $admin = $this->adminFor();
        $this->adminPost('/orders/'.$n.'/cancel')->assertStatus(422);
        $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'Support request', 'refund_status' => 'REFUNDED'])->assertStatus(422);
        $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'Support request', 'status' => 'COMPLETED'])->assertStatus(422);
        $this->patchJson('/api/v1/admin/orders/'.$n, ['status' => 'COMPLETED'])->assertStatus(405);
        $cancelled = $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'Support request: customer cannot travel'])->assertOk()->json('order');
        $this->assertSame(['CANCELLED', 'PAID', 'REQUIRED', ['cancel' => false, 'refund' => 'NOT_IMPLEMENTED']], [$cancelled['status'], $cancelled['payment_state'], $cancelled['refund_status'], $cancelled['actions']]);
        $this->assertSame(['reason' => 'Support request: customer cannot travel'], $cancelled['cancellation']);
        $this->assertCount(1, $cancelled['refunds']);
        $this->assertSame(['REQUIRED', 'ADMIN_CANCELLED', 'NOT_IMPLEMENTED'], [$cancelled['refunds'][0]['status'], $cancelled['refunds'][0]['reason'], $cancelled['refunds'][0]['processing']]);
        $this->assertSame('RELEASED', PickupSlotReservation::query()->where('reference', $order['id'])->sole()->status);
        $audit = AuditEvent::query()->where('action', 'order.cancelled')->sole();
        $this->assertSame([(string) $admin->public_id, 'Support request: customer cannot travel'], [(string) $audit->actor_public_id, $audit->reason]);
        // A finished order cannot be cancelled again, and a second cancel does not add a refund.
        $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'again'])->assertOk()->assertJsonPath('order.status', 'CANCELLED');
        $this->assertSame(1, OrderRefund::query()->count());
        // The customer sees the cancellation and the refund it requires; the restaurant sees it closed.
        $mine = $this->showOrder($headers, $n)->assertOk()->json('order');
        $this->assertSame(['CANCELLED', 'REQUIRED', 'AWAIT_REFUND', null], [$mine['status'], $mine['refund_status'], $mine['next_action'], $mine['pickup_credential']]);
        $this->assertSame('This order was cancelled. If you paid, the amount will be refunded.', $mine['cancellation']['message']);
        $this->orderStaff();
        $this->step($n, 'accept')->assertStatus(409)->assertJsonPath('error.code', 'order_invalid_transition');

        // Without admin.orders.override: 403 — and an administrator of another market sees nothing.
        $this->asActor($this->adminWith([Permission::AdminOrdersView]));
        $this->adminGet('/orders/'.$n)->assertOk();
        $this->adminPost('/orders/'.$n.'/cancel', ['reason' => 'x'])->assertForbidden();
        $other = Market::factory()->create(['slug' => 'elsewhere', 'status' => 'ACTIVE']);
        $this->asActor($this->adminWith([Permission::AdminOrdersView, Permission::AdminOrdersOverride], $other));
        $this->adminGet('/orders/'.$n)->assertForbidden();
        $this->assertSame([], $this->adminGet('/orders')->assertOk()->json('data'));
    }

    public function test_the_pickup_credential_is_opaque_and_stored_hashed_and_the_answers_carry_no_secret(): void
    {
        [, $headers, , $payment, $order] = $this->paidOrder();
        $credential = $this->orderModel($order['order_number'])->pickupCredential;
        $raw = (array) DB::table('order_pickup_credentials')->where('id', $credential->getKey())->first();

        // The code is encrypted at rest; the token is never stored, only its hash; neither is derivable from the row.
        $this->assertNotSame($order['pickup_credential']['code'], $raw['display_code']);
        $this->assertSame(hash('sha256', $order['id'].':'.$order['pickup_credential']['code']), $raw['display_code_hash']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $raw['token_hash']);
        $this->assertStringNotContainsString($raw['token_hash'], json_encode($order));
        $this->assertStringNotContainsString($order['order_number'], $order['pickup_credential']['qr_payload']);
        $this->assertStringNotContainsString($payment['id'], $order['pickup_credential']['qr_payload']);

        // Neither the restaurant nor an administrator is given the code.
        $this->orderStaff();
        $this->assertStringNotContainsString($order['pickup_credential']['code'], $this->restaurantOrder($order['order_number'])->assertOk()->getContent());
        $this->adminFor();
        $detail = $this->adminGet('/orders/'.$order['order_number'])->assertOk()->getContent();
        $this->assertStringNotContainsString($order['pickup_credential']['code'], $detail);
        $this->assertStringNotContainsString($raw['token_hash'], $detail);
        // No provider secret, signature or card can be in any order answer or in the stored record.
        foreach ([json_encode($order), $detail, json_encode($this->orderModel($order['order_number'])->snapshot)] as $text) {
            foreach (['key_secret', 'webhook_secret', 'provider_signature', 'rzp_test_', 'rzp_live_', 'card_number', 'cvv'] as $never) {
                $this->assertStringNotContainsString($never, $text);
            }
        }
        // The customer's own answer carries the customer's data, nobody else's; the admin list carries a masked number only.
        $this->assertSame('+91******3210', $this->adminGet('/orders')->assertOk()->json('data.0.customer.phone_masked'));
        $this->assertSame(1, Order::query()->count());
        $this->showOrder($headers, $order['order_number'])->assertOk();
    }

    public function test_order_reads_and_steps_are_rate_limited_per_identity(): void
    {
        [, $headers, , , $order] = $this->paidOrder();
        config(['rate_limits.order_read' => 3, 'rate_limits.order_write' => 2]);
        // paidOrder() read the order once already: two more reads fit the budget of three, the next one does not.
        foreach (range(1, 2) as $i) {
            $this->showOrder($headers, $order['order_number'])->assertOk();
        }
        $this->showOrder($headers, $order['order_number'])->assertStatus(429);
        // Another customer has their own budget.
        [, $other] = $this->signedIn('+919811122233');
        $this->listOrders($other)->assertOk();

        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->step($order['order_number'], 'ready')->assertStatus(429);
    }
}
