<?php

namespace Tests\Feature\Order;

use App\Enums\Permission;
use App\Events\Order\OrderEvent;
use App\Models\AuditEvent;
use App\Models\PickupSlotReservation;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * The restaurant's side of an order (Module 31): the queue, accept / reject / start preparing / ready, structured
 * rejection reasons, version conflicts, idempotent repeats, tenant isolation and permissions — and what a rejection
 * of a PAID order does to the money state (REFUND REQUIRED, nothing refunded here).
 */
class RestaurantOrderWorkflowTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_queue_shows_the_locations_active_orders_with_counts_and_the_steps_each_one_allows(): void
    {
        [, , , , $first] = $this->paidOrder('+919876543210');
        [, , , , $second] = $this->paidOrder('+919811122233', '13:15');
        $this->orderStaff();

        $queue = $this->restaurantOrders('filter[queue]=1')->assertOk()->json();
        $this->assertSame([$first['order_number'], $second['order_number']], array_column($queue['data'], 'order_number'));   // by pickup time
        $this->assertSame(['waiting_for_restaurant' => 2, 'accepted' => 0, 'preparing' => 0, 'ready_for_pickup' => 0], $queue['counts']);
        $row = $queue['data'][0];
        $this->assertSame(['accept', 'reject'], $row['allowed_actions']);
        $this->assertSame(1, $row['version']);
        $this->assertSame($this->inr(50000), $row['total']);
        $this->assertSame('ASAP', $row['pickup']['mode']);
        $this->assertSame('+919876543210', $row['customer']['phone']);   // how to reach the customer about THIS order
        $this->assertArrayNotHasKey('pickup_credential', $row);
        $this->assertArrayNotHasKey('payment', $row);

        $detail = $this->restaurantOrder($first['order_number'])->assertOk()->json('order');
        $this->assertSame('Classic Burger', $detail['items'][0]['name']);
        $this->assertSame([['kind' => 'VARIANT', 'group' => 'Size', 'name' => 'Large', 'price_adjustment' => $this->inr(5000)]], $detail['items'][0]['options']);
        $this->assertArrayNotHasKey('pickup_credential', $detail);   // the restaurant verifies it (Module 32), it does not read it
        $this->assertArrayNotHasKey('payment', $detail);
        $this->assertSame(['ORDER_CREATED'], array_column($detail['timeline'], 'type'));

        // By status, by number prefix, by day; a day in the wrong form is refused.
        $this->assertCount(2, $this->restaurantOrders('filter[status]=WAITING_FOR_RESTAURANT')->assertOk()->json('data'));
        $this->assertCount(0, $this->restaurantOrders('filter[status]=ACCEPTED')->assertOk()->json('data'));
        $this->assertSame([$second['order_number']], array_column($this->restaurantOrders('q='.$second['order_number'])->assertOk()->json('data'), 'order_number'));
        $this->assertCount(2, $this->restaurantOrders('filter[day]=2026-10-07')->assertOk()->json('data'));
        $this->assertCount(0, $this->restaurantOrders('filter[day]=2026-10-08')->assertOk()->json('data'));
        $this->restaurantOrders('filter[day]=yesterday')->assertStatus(422);
        // The location is required and must be the caller's.
        $this->actingAsPrincipal($this->actor);
        $this->getJson('/api/v1/restaurant/orders')->assertStatus(422);
        $this->actingAsPrincipal($this->actor);
        $this->getJson('/api/v1/restaurant/orders?location='.$this->market->public_id)->assertNotFound();
    }

    public function test_accept_prepare_and_ready_move_the_order_forward_and_each_step_is_announced_and_audited(): void
    {
        Event::fake([OrderEvent::class]);
        [, $customer, , , $order] = $this->paidOrder();
        $staff = $this->orderStaff();
        $n = $order['order_number'];

        $accepted = $this->step($n, 'accept', ['expected_version' => 1])->assertOk()->json('order');
        $this->assertSame(['ACCEPTED', 2, ['start_preparing']], [$accepted['status'], $accepted['version'], $accepted['allowed_actions']]);
        $this->assertNotNull($accepted['times']['accepted_at']);
        $this->assertSame(['ORDER_CREATED', 'RESTAURANT_ACCEPTED'], array_column($accepted['timeline'], 'type'));
        $this->assertSame('RESTAURANT_USER', $accepted['timeline'][1]['actor']);

        $preparing = $this->step($n, 'start-preparing', ['expected_version' => 2])->assertOk()->json('order');
        $this->assertSame(['PREPARING', 3, ['ready']], [$preparing['status'], $preparing['version'], $preparing['allowed_actions']]);

        $ready = $this->step($n, 'ready')->assertOk()->json('order');   // no expected_version: allowed, the step is still checked
        $this->assertSame(['READY_FOR_PICKUP', 4, ['verify_pickup']], [$ready['status'], $ready['version'], $ready['allowed_actions']]);   // Module 32: the pickup is verified, not clicked
        $this->assertNotNull($ready['times']['ready_at']);

        // The customer sees the same journey, in their own words.
        $mine = $this->showOrder($customer, $n)->assertOk()->json('order');
        $this->assertSame('READY_FOR_PICKUP', $mine['status']);
        $this->assertSame('GO_TO_PICKUP', $mine['next_action']);
        $this->assertSame(['ORDER_CREATED', 'RESTAURANT_ACCEPTED', 'PREPARATION_STARTED', 'READY_FOR_PICKUP'], array_column($mine['timeline'], 'type'));
        $this->assertSame(['FOODONTHEGO', 'RESTAURANT', 'RESTAURANT', 'RESTAURANT'], array_column($mine['timeline'], 'actor'));
        $this->assertNotNull($mine['pickup_credential']);

        // There is no "picked up" step for the restaurant (Module 32).
        $this->step($n, 'picked-up')->assertNotFound();
        $this->step($n, 'complete')->assertNotFound();

        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::RESTAURANT_ACCEPTED && $e->orderNumber === $n);
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::PREPARING);
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::READY);
        $this->assertSame(['order.accepted', 'order.preparing', 'order.ready'], AuditEvent::query()->where('action', 'like', 'order.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame((string) $staff->public_id, (string) AuditEvent::query()->where('action', 'order.accepted')->sole()->actor_public_id);
    }

    public function test_a_step_is_idempotent_and_a_stale_version_is_refused_with_the_current_state(): void
    {
        [, , , , $order] = $this->paidOrder();
        $this->orderStaff();
        $n = $order['order_number'];

        $this->step($n, 'accept', ['expected_version' => 1])->assertOk()->assertJsonPath('order.version', 2);
        // The same click again (a retried request, a second tab): already there — the order as it is, no second event.
        $this->step($n, 'accept', ['expected_version' => 1])->assertOk()->assertJsonPath('order.status', 'ACCEPTED')->assertJsonPath('order.version', 2);
        $this->step($n, 'accept')->assertOk()->assertJsonPath('order.version', 2);
        $this->assertSame(1, \App\Models\OrderEvent::query()->where('type', 'RESTAURANT_ACCEPTED')->count());

        // A colleague's screen still shows version 1 and tries to reject: refused, told what the order is now.
        $conflict = $this->step($n, 'reject', ['expected_version' => 1, 'reason' => 'CLOSING'])->assertStatus(409)->json('error');
        $this->assertSame('order_version_conflict', $conflict['code']);
        $this->assertSame(['current_version' => 2, 'current_status' => 'ACCEPTED'], $conflict['details']);
        // A step the machine does not allow from here.
        $this->step($n, 'ready', ['expected_version' => 2])->assertStatus(409)->assertJsonPath('error.code', 'order_invalid_transition');
        $this->step($n, 'reject', ['expected_version' => 2, 'reason' => 'CLOSING'])->assertStatus(409)->assertJsonPath('error.code', 'order_invalid_transition');
        $this->assertSame('ACCEPTED', $this->orderModel($n)->status->value);

        // With an Idempotency-Key the exact request is replayed, not applied twice.
        $this->step($n, 'start-preparing', ['expected_version' => 2], ['Idempotency-Key' => 'order-step-0000000001'])->assertOk()->assertJsonPath('order.version', 3);
        $this->step($n, 'start-preparing', ['expected_version' => 2], ['Idempotency-Key' => 'order-step-0000000001'])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(3, (int) $this->orderModel($n)->version);
    }

    public function test_rejecting_a_paid_order_requires_a_structured_reason_frees_the_pickup_place_and_makes_a_refund_required(): void
    {
        Event::fake([OrderEvent::class]);
        [, $customer, , $payment, $order] = $this->paidOrder();
        $this->orderStaff();
        $n = $order['order_number'];

        $this->step($n, 'reject')->assertStatus(422);
        $this->step($n, 'reject', ['reason' => 'CUSTOMER_IS_RUDE'])->assertStatus(422);
        $this->step($n, 'reject', ['reason' => 'ITEM_UNAVAILABLE', 'refund_status' => 'REFUNDED'])->assertStatus(422);

        $rejected = $this->step($n, 'reject', ['expected_version' => 1, 'reason' => 'ITEM_UNAVAILABLE', 'note' => 'Out of buns until 3pm'])->assertOk()->json('order');
        $this->assertSame(['REJECTED', 'PAID', 'REQUIRED', 2, []], [$rejected['status'], $rejected['payment_state'], $rejected['refund_status'], $rejected['version'], $rejected['allowed_actions']]);
        $this->assertSame(['reason' => 'ITEM_UNAVAILABLE', 'note' => 'Out of buns until 3pm'], $rejected['rejection']);
        $this->assertSame(['ORDER_CREATED', 'RESTAURANT_REJECTED', 'REFUND_REQUIRED'], array_column($rejected['timeline'], 'type'));

        // The customer is told in neutral words, never the note; the amount was NOT changed; nothing was refunded yet.
        $mine = $this->showOrder($customer, $n)->assertOk()->json('order');
        $this->assertSame('REJECTED', $mine['status']);
        $this->assertSame(['reason' => 'ITEM_UNAVAILABLE', 'message' => 'The restaurant could not accept your order: an item is no longer available.'], $mine['rejection']);
        $this->assertStringNotContainsString('buns', json_encode($mine));
        $this->assertSame($this->inr(50000), $mine['total']);
        $this->assertSame('REQUIRED', $mine['refund_status']);
        $this->assertSame('AWAIT_REFUND', $mine['next_action']);
        $this->assertNull($mine['pickup_credential']);
        $this->assertSame('REFUND_REQUIRED', $mine['timeline'][2]['type']);

        // The refund record: the whole amount, REQUIRED, for the refund module; the payment itself untouched.
        // Module 35: the refund domain's record (it replaced the Module 31 foundation row).
        $refund = Refund::query()->sole();
        $this->assertSame([50000, 'INR', 'REQUIRED', 'RESTAURANT_REJECTED', 'ITEM_UNAVAILABLE', 'RESTAURANT_USER'], [(int) $refund->requested_amount_minor, $refund->currency, $refund->status->value, $refund->reason_code->value, $refund->reason_detail, $refund->requested_by_type]);
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
        $this->assertSame('ORDER_CREATED', $this->attempt($payment['id'])->fulfillment_status->value);
        // The pickup place is given back and the credential revoked.
        $this->assertSame('RELEASED', PickupSlotReservation::query()->where('reference', $order['id'])->sole()->status);
        $this->assertSame('REVOKED', $this->orderModel($n)->pickupCredential->status->value);
        // Rejecting again: already rejected — no second refund record, no second event.
        $this->step($n, 'reject', ['reason' => 'OTHER'])->assertOk()->assertJsonPath('order.status', 'REJECTED');
        $this->assertSame(1, Refund::query()->count());
        Event::assertDispatchedTimes(OrderEvent::class, 3);   // created, rejected, refund required
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::REFUND_REQUIRED && $e->detail['amount_minor'] === 50000);
        $this->assertSame('Out of buns until 3pm', AuditEvent::query()->where('action', 'order.rejected')->sole()->reason);
    }

    public function test_rejecting_an_order_that_was_not_paid_requires_no_refund(): void
    {
        [, $customer, , $order] = $this->zeroTotalOrder();
        $this->orderStaff();

        $rejected = $this->step($order['order_number'], 'reject', ['reason' => 'KITCHEN_CAPACITY'])->assertOk()->json('order');
        $this->assertSame(['REJECTED', 'PAYMENT_NOT_REQUIRED', 'NONE'], [$rejected['status'], $rejected['payment_state'], $rejected['refund_status']]);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame('ORDER_AGAIN', $this->showOrder($customer, $order['order_number'])->json('order.next_action'));
    }

    public function test_orders_are_isolated_by_tenant_and_permission(): void
    {
        [, , , , $order] = $this->paidOrder();
        $n = $order['order_number'];

        // Another organization's staff: the order does not exist for them, whatever they try.
        $other = $this->organization('Second Kitchen');
        $this->asActor($this->member($other, 'OWNER'));
        $this->restaurantOrder($n)->assertNotFound();
        $this->restaurantOrder($order['id'])->assertNotFound();
        $this->step($n, 'accept')->assertNotFound();
        $this->restaurantOrders()->assertNotFound();

        // A viewer of the right organization may look and not touch.
        $this->asActor($this->member($this->organization, 'VIEWER'));
        $this->restaurantOrder($n)->assertOk();
        $this->step($n, 'accept')->assertForbidden();
        $this->assertSame('WAITING_FOR_RESTAURANT', $this->orderModel($n)->status->value);

        // Staff of another location of the same organization: not theirs either.
        $brewBites = $this->eligible('Brew Bites', 28.56, 77.36);
        $this->asActor($this->member($this->organization, 'ORDER_STAFF', [$brewBites]));
        $this->restaurantOrder($n)->assertNotFound();
        $this->step($n, 'accept')->assertNotFound();

        // A customer's token is not a restaurant token.
        $this->app['auth']->forgetGuards();
        [, $headers] = $this->signedIn('+919811122233');
        $this->getJson('/api/v1/restaurant/orders/'.$n, $this->using($headers))->assertUnauthorized();
        $this->postJson('/api/v1/restaurant/orders/'.$n.'/accept', [], $this->using($headers))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/restaurant/orders/'.$n)->assertUnauthorized();

        // Nothing about an order can be set by a restaurant request.
        $this->orderStaff();
        foreach (['status' => 'COMPLETED', 'total' => 1, 'total_minor' => 1, 'items' => [['name' => 'x']], 'customer' => ['phone' => 'x'], 'payment_state' => 'PAID', 'picked_up' => true] as $field => $value) {
            $this->step($n, 'accept', [$field => $value])->assertStatus(422);
        }
        $this->assertSame(Permission::RestaurantOrdersUpdate->value, 'restaurant.orders.update');
    }

    public function test_the_customer_lists_their_own_orders_and_cannot_see_or_name_anybody_elses(): void
    {
        [, $rahul, , , $first] = $this->paidOrder('+919876543210');
        [, $asha, , , $second] = $this->paidOrder('+919876543212', '13:15');

        $mine = $this->listOrders($rahul)->assertOk()->json();
        $this->assertSame([$first['order_number']], array_column($mine['data'], 'order_number'));
        $this->assertSame(1, $mine['meta']['total']);
        $this->assertSame([$second['order_number']], array_column($this->listOrders($asha, 'filter[active]=1')->assertOk()->json('data'), 'order_number'));
        $this->assertSame([], $this->listOrders($asha, 'filter[active]=0')->assertOk()->json('data'));
        // By number or by id — the caller's own only; anybody else's is "not found", never 403 (no existence leak).
        $this->showOrder($rahul, $first['id'])->assertOk();
        $this->showOrder($rahul, strtolower($first['order_number']))->assertOk();
        $this->showOrder($rahul, $second['order_number'])->assertNotFound();
        $this->showOrder($rahul, $second['id'])->assertNotFound();
        $this->showOrder($rahul, 'FOTG-20261007-ZZZZZZ')->assertNotFound();
        $this->showOrder($rahul, 'not-an-order')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customer/orders/'.$first['order_number'])->assertUnauthorized();
        // There is no way for a customer to change an order. Module 35: the cancellation route exists and refuses —
        // customer cancellation is disabled until a policy is approved.
        $this->postJson('/api/v1/customer/orders/'.$first['order_number'].'/cancel', ['reason_code' => 'CHANGED_MIND'], $this->using($rahul))->assertStatus(409)->assertJsonPath('error.code', 'order_cancellation_not_allowed');
        $this->assertSame($first['status'], $this->showOrder($rahul, $first['order_number'])->json('order.status'));
        $this->patchJson('/api/v1/customer/orders/'.$first['order_number'], ['status' => 'CANCELLED'], $this->using($rahul))->assertStatus(405);
    }
}
