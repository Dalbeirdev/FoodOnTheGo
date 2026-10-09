<?php

namespace Tests\Feature\Order;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Events\Order\OrderEvent;
use App\Events\Order\OrderUpdated;
use App\Listeners\BroadcastOrderUpdate;
use App\Models\Order;
use App\Models\OrderEvent as Line;
use App\Services\Order\OrderMetrics;
use App\Services\Order\OrderWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * The order event stream and the realtime layer (Module 32): a deterministic per-order sequence, the cursor API for
 * resync (customer-visible lines for the customer, every line for the restaurant), the broadcast notice on the two
 * private channels — sent after the commit, with a minimal payload — and the channel authorization: the owner only,
 * the location's staff only, nobody else. The transport (Reverb) is not running in tests: what is proven here is what
 * the backend sends and to whom it says yes.
 */
class RealtimeOrderEventsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_every_timeline_line_has_a_deterministic_sequence_and_a_stable_event_id_and_the_database_keeps_them_unique(): void
    {
        [, $headers, , , $order] = $this->paidOrder();
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();

        $seen = $this->showOrder($headers, $order['order_number'])->assertOk()->json('order');
        $this->assertSame([1, 2, 3], array_column($seen['timeline'], 'sequence'));
        $this->assertSame([$order['id'].'-1', $order['id'].'-2', $order['id'].'-3'], array_column($seen['timeline'], 'event_id'));
        $this->assertSame(3, $seen['version']);   // one line per state change: the version and the sequence walk together here

        $model = $this->orderModel($order['order_number']);
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($model): void {
            (new Line)->forceFill(['order_id' => $model->id, 'sequence' => 3, 'type' => 'STEP_REFUSED', 'actor_type' => 'SYSTEM', 'occurred_at' => now()])->save();
        });
    }

    public function test_the_customer_event_stream_is_read_by_cursor_shows_customer_visible_lines_only_and_carries_the_current_state(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        $this->verifyQr($payload)->assertOk();

        $all = $this->customerEvents($headers, $order['order_number'])->assertOk()->json();
        $this->assertSame(['COMPLETED', 6], [$all['order']['status'], $all['order']['version']]);
        $this->assertSame(['ORDER_CREATED', 'RESTAURANT_ACCEPTED', 'PREPARATION_STARTED', 'READY_FOR_PICKUP', 'CUSTOMER_PICKED_UP', 'ORDER_COMPLETED'], array_column($all['events'], 'type'));   // PICKUP_VERIFIED is internal
        $this->assertSame([1, 2, 3, 4, 5, 7], array_column($all['events'], 'sequence'));
        $this->assertSame(['after_sequence' => 0, 'last_sequence' => 7, 'has_more' => false], $all['cursor']);
        $this->assertSame('customer.order.'.$order['id'], $all['realtime']['channel']);
        $this->assertSame(['order.updated', '/customer/broadcasting/auth', true], [$all['realtime']['event'], $all['realtime']['auth_endpoint'], $all['realtime']['terminal']]);
        foreach ($all['events'] as $line) {
            $this->assertSame('RESTAURANT', $line['actor'] === 'FOODONTHEGO' ? 'RESTAURANT' : $line['actor']);   // never a staff member's id
            $this->assertArrayNotHasKey('verified_by', (array) $line['detail']);
            $this->assertArrayNotHasKey('credential', (array) $line['detail']);
        }
        // From a cursor: only what is newer. Past the end: nothing, same state.
        $tail = $this->customerEvents($headers, $order['order_number'], 4)->assertOk()->json();
        $this->assertSame([5, 7], array_column($tail['events'], 'sequence'));
        $this->assertSame([], $this->customerEvents($headers, $order['order_number'], 7)->assertOk()->json('events'));
        $this->assertSame(2, app(OrderMetrics::class)->report()['realtime']['events_resync']);
        // The restaurant's stream has every line, with the staff member on the pickup line.
        $mine = $this->restaurantEvents($order['order_number'], 4)->assertOk()->json();
        $this->assertSame(['CUSTOMER_PICKED_UP', 'PICKUP_VERIFIED', 'ORDER_COMPLETED'], array_column($mine['events'], 'type'));
        $this->assertSame('restaurant.location.'.$this->burgerHub->public_id, $mine['realtime']['channel']);
        // Somebody else's order: not found, for a customer and for another restaurant.
        [, $other] = $this->customerReadyForCheckout(phone: '+919811122233');
        $this->customerEvents($other, $order['order_number'])->assertNotFound();
        $this->asActor($this->member($this->organization('Pizza People'), 'OWNER'));
        $this->restaurantEvents($order['order_number'])->assertNotFound();
        $this->getJson('/api/v1/customer/orders/'.$order['order_number'].'/events?after_sequence=-1', $this->using($headers))->assertStatus(422);
    }

    public function test_each_state_change_is_broadcast_after_its_commit_on_the_two_private_channels_with_a_minimal_payload(): void
    {
        [, $headers, , , $order] = $this->paidOrder();
        $this->orderStaff();
        Event::fake([OrderUpdated::class]);
        $this->step($order['order_number'], 'accept')->assertOk();

        Event::assertDispatchedTimes(OrderUpdated::class, 2);
        $sent = [];
        Event::assertDispatched(OrderUpdated::class, function (OrderUpdated $e) use (&$sent): bool {
            $sent[] = $e;

            return true;
        });
        $customer = collect($sent)->first(fn (OrderUpdated $e): bool => $e->channels === ['customer.order.'.$order['id']]);
        $restaurant = collect($sent)->first(fn (OrderUpdated $e): bool => $e->channels === ['restaurant.location.'.$this->burgerHub->public_id]);
        $this->assertNotNull($customer);
        $this->assertNotNull($restaurant);
        $this->assertSame('private-customer.order.'.$order['id'], $customer->broadcastOn()[0]->name);
        $this->assertSame('order.updated', $customer->broadcastAs());
        $p = $customer->broadcastWith();
        $this->assertSame(['schema_version', 'order_public_id', 'order_number', 'version', 'status', 'event_type', 'event_sequence', 'event_id', 'payment_state', 'refund_status', 'pickup_at', 'ready_at', 'occurred_at', 'message_key'], array_keys($p));
        $this->assertSame([1, $order['order_number'], 2, 'ACCEPTED', 'order.restaurant_accepted', 2, $order['id'].'-2', 'PAID', 'NONE', 'order.accepted'], [$p['schema_version'], $p['order_number'], $p['version'], $p['status'], $p['event_type'], $p['event_sequence'], $p['event_id'], $p['payment_state'], $p['refund_status'], $p['message_key']]);
        $r = $restaurant->broadcastWith();
        $this->assertSame(2, $r['item_count']);
        $this->assertArrayNotHasKey('message_key', $r);
        // Nothing a person could be found by, nothing that is money: the clients read the order for that.
        $json = json_encode([$p, $r]);
        foreach (['+9198', 'Asha', 'Classic Burger', '50000', 'phone', 'customer_name', 'items', 'total', 'payment_id', 'provider', 'journey', 'note', 'staff', 'token', 'code'] as $never) {
            $this->assertStringNotContainsString($never, $json, $never);
        }
    }

    public function test_a_broadcaster_that_is_down_costs_a_counted_failure_and_never_the_order_step(): void
    {
        [, $headers, , , $order] = $this->paidOrder();
        $this->orderStaff();
        Broadcast::shouldReceive('event')->andThrow(new \RuntimeException('reverb unreachable'));
        $this->step($order['order_number'], 'accept')->assertOk()->assertJsonPath('order.status', 'ACCEPTED');
        $this->assertSame('ACCEPTED', $this->orderModel($order['order_number'])->status->value);
        $report = app(OrderMetrics::class)->report()['realtime'];
        $this->assertSame([2, 1], [$report['broadcast_sent'], $report['broadcast_failed']]);   // the two of order.created went out before the outage
        // Switched off: nothing is attempted, the order still moves.
        config(['orders.realtime.enabled' => false]);
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->assertSame(1, app(OrderMetrics::class)->report()['realtime']['broadcast_failed']);
    }

    public function test_the_broadcast_happens_after_the_commit_never_for_a_change_that_rolled_back(): void
    {
        [, , , , $order] = $this->paidOrder();
        $model = $this->orderModel($order['order_number']);
        Event::fake([OrderUpdated::class]);
        try {
            DB::transaction(function () use ($model): void {
                app(OrderWorkflowService::class)->accept($model, $this->orderStaff());
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('WAITING_FOR_RESTAURANT', $model->fresh()->status->value);
        Event::assertNotDispatched(OrderUpdated::class);
        // The listener alone, asked directly with a committed order, sends — the only path there is.
        $listener = app(BroadcastOrderUpdate::class);
        $listener->handle(new OrderEvent(OrderEvent::ORDER_CREATED, $model->fresh()));
        Event::assertDispatchedTimes(OrderUpdated::class, 2);
    }

    public function test_the_customer_channel_is_the_owners_only_and_the_location_channel_the_locations_staff_only(): void
    {
        [$customer, $headers, , , $order] = $this->paidOrder();
        [, $other] = $this->customerReadyForCheckout(phone: '+919811122233');
        $socket = '1234.5678';
        $mine = ['socket_id' => $socket, 'channel_name' => 'private-customer.order.'.$order['id']];

        // The owner: an auth signature for the key. Another customer: 403. A guest: 401. A restaurant token: 401.
        $granted = $this->postJson('/api/v1/customer/broadcasting/auth', $mine, $this->using($headers))->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[^:]+:[0-9a-f]{64}$/', $granted['auth']);
        $this->postJson('/api/v1/customer/broadcasting/auth', $mine, $this->using($other))->assertForbidden()->assertJsonPath('error.code', 'channel_forbidden');
        $this->app['auth']->forgetGuards();   // a bare request: no token (the test client keeps the last guard otherwise)
        $this->postJson('/api/v1/customer/broadcasting/auth', $mine)->assertUnauthorized();
        $this->postJson('/api/v1/customer/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => 'private-restaurant.location.'.$this->burgerHub->public_id], $this->using($headers))->assertForbidden();
        $this->postJson('/api/v1/customer/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => 'customer.order.'.$order['id']], $this->using($headers))->assertStatus(422);   // never a public channel
        $this->postJson('/api/v1/customer/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => 'private-orders'], $this->using($headers))->assertStatus(422);

        // The location channel: the organization's owner and the location's staff — not another location's staff,
        // not another organization, not a suspended member, not a customer.
        $location = ['socket_id' => $socket, 'channel_name' => 'private-restaurant.location.'.$this->burgerHub->public_id];
        $this->actingAsPrincipal($this->member($this->organization, 'OWNER'));
        $this->postJson('/api/v1/restaurant/broadcasting/auth', $location)->assertOk();
        $this->actingAsPrincipal($this->member($this->organization, 'VIEWER', [$this->burgerHub]));
        $this->postJson('/api/v1/restaurant/broadcasting/auth', $location)->assertOk();   // orders.view is enough to listen
        $second = $this->eligible('Burger Hub Gurgaon', 28.46, 77.03);
        $this->actingAsPrincipal($this->member($this->organization, 'ORDER_STAFF', [$second]));
        $this->postJson('/api/v1/restaurant/broadcasting/auth', $location)->assertForbidden();
        $this->actingAsPrincipal($this->member($this->organization('Pizza People'), 'OWNER'));
        $this->postJson('/api/v1/restaurant/broadcasting/auth', $location)->assertForbidden();
        $this->actingAsPrincipal($this->member($this->organization, 'OWNER', null, MembershipStatus::Suspended));
        $this->postJson('/api/v1/restaurant/broadcasting/auth', $location)->assertForbidden();
        $this->postJson('/api/v1/restaurant/broadcasting/auth', $location, $this->using($headers))->assertUnauthorized();
        $this->postJson('/api/v1/restaurant/broadcasting/auth', $mine, $this->bearer($this->member($this->organization, 'OWNER')))->assertForbidden();   // staff cannot listen to a customer's channel
        $this->assertGreaterThanOrEqual(6, app(OrderMetrics::class)->report()['realtime']['channel_auth_denied']);

        // A revoked token cannot authorize any more (the socket's next subscription or reconnection is refused).
        $this->postJson('/api/v1/auth/logout', [], $this->using($headers))->assertNoContent();
        $this->postJson('/api/v1/customer/broadcasting/auth', $mine, $this->using($headers))->assertUnauthorized();
        $this->assertSame(Permission::RestaurantOrdersView->value, 'restaurant.orders.view');
    }
}
