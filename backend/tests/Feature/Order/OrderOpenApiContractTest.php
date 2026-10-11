<?php

namespace Tests\Feature\Order;

use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderRejectionReason;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Events\Payment\PaymentVerified;
use App\Models\PickupSlotReservation;
use App\Services\Order\OrderFulfillmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsOrders;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * The order API is what its document says (Module 31): every route is documented, every answer matches its schema in
 * every state, and no request body has room for an amount, a status, an item or "picked up".
 */
class OrderOpenApiContractTest extends TestCase
{
    use BuildsOrders, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_customer_restaurant_and_admin_answers_match_their_schemas_in_every_state(): void
    {
        [, $customer, , $payment, $order] = $this->paidOrder();
        $this->assertMatchesSchema('PaymentEnvelope', $this->showPayment($customer, $payment['id'])->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($customer, $order['order_number'])->assertOk()->json());
        $this->assertMatchesSchema('OrderSummaryPage', $this->listOrders($customer)->assertOk()->json());
        [, $free, $checkout, $zero] = $this->zeroTotalOrder('+919811122233');
        $this->assertMatchesSchema('OrderEnvelope', $this->fulfillCheckout($free, $checkout['id'])->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($free, $zero['id'])->assertOk()->json());

        $this->orderStaff();
        $this->assertMatchesSchema('RestaurantOrderPage', $this->restaurantOrders()->assertOk()->json());
        $this->assertMatchesSchema('RestaurantOrderEnvelope', $this->restaurantOrder($order['order_number'])->assertOk()->json());
        foreach ([['accept', 1], ['start-preparing', 2], ['ready', 3]] as [$step, $version]) {
            $this->assertMatchesSchema('RestaurantOrderEnvelope', $this->step($order['order_number'], $step, ['expected_version' => $version])->assertOk()->json());
        }
        $this->assertMatchesSchema('RestaurantOrderEnvelope', $this->step($zero['order_number'], 'reject', ['reason' => 'CLOSING', 'note' => 'contract'])->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($customer, $order['order_number'])->assertOk()->json());   // READY_FOR_PICKUP, credential present
        // Module 32: the credential, the event streams, the channel authorization, the verification and the completed order.
        $credential = $this->getJson('/api/v1/customer/orders/'.$order['order_number'].'/pickup-credential', $this->using($customer))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertMatchesSchema('PickupCredentialEnvelope', $credential->json());
        $this->assertMatchesSchema('PickupCredentialEnvelope', $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/pickup-credential/reissue', [], $this->using($customer))->assertCreated()->json());
        $this->assertMatchesSchema('OrderEventsEnvelope', $this->customerEvents($customer, $order['order_number'], 1)->assertOk()->json());
        $this->assertMatchesSchema('BroadcastingAuth', $this->postJson('/api/v1/customer/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-customer.order.'.$order['id']], $this->using($customer))->assertOk()->json());
        // A third order goes all the way: READY → verified pickup → COMPLETED (the first one stays READY for the cancel below).
        [, $third, $o3] = $this->readyOrder('+919833344455', '13:45');
        $payload = $this->credentialOf($third, $o3['order_number'])['qr_payload'];
        $this->assertMatchesSchema('OrderEventsEnvelope', $this->restaurantEvents($order['order_number'])->assertOk()->json());
        $this->assertMatchesSchema('BroadcastingAuth', $this->postJson('/api/v1/restaurant/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-restaurant.location.'.$this->burgerHub->public_id])->assertOk()->json());
        $this->assertMatchesSchema('PickupVerifyEnvelope', $this->verifyQr($payload)->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($third, $o3['order_number'])->assertOk()->assertJsonPath('order.status', 'COMPLETED')->json());
        $this->assertMatchesSchema('RestaurantOrderEnvelope', $this->restaurantOrder($o3['order_number'])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->verifyQr($payload)->assertStatus(409)->json());
        $this->adminFor();
        $this->assertMatchesSchema('AdminOrderEnvelope', $this->adminGet('/orders/'.$o3['order_number'])->assertOk()->json());
        $this->assertMatchesSchema('RealtimeSystem', $this->adminGet('/system/realtime')->assertOk()->json());
        $this->assertMatchesSchema('PickupAttemptPage', $this->adminGet('/pickup-attempts?filter[failed]=1')->assertOk()->json());
        $this->assertMatchesSchema('OrderMetrics', $this->adminGet('/system/orders')->assertOk()->json());
        $this->orderStaff();
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($free, $zero['id'])->assertOk()->json());               // REJECTED, no credential
        foreach ([
            $this->step($order['order_number'], 'accept', ['expected_version' => 1])->assertStatus(409),
            $this->step($order['order_number'], 'reject', ['reason' => 'nope'])->assertStatus(422),
            $this->restaurantOrder('FOTG-20261007-ZZZZZZ')->assertNotFound(),
            $this->showOrder($free, $order['order_number'])->assertNotFound(),
            $this->fulfillCheckout($customer, $checkout['id'])->assertNotFound(),
        ] as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }

        // A paid checkout that did not become an order, for the admin screens.
        $this->settings(['capacity_per_slot' => 1]);
        $real = Event::getFacadeRoot();
        Event::fake([PaymentVerified::class]);
        [, $h3, $c3, $p3] = $this->openPayment('+919822233344');
        $this->completePayment($h3, $p3['id'], $this->proofOf($p3['id'], $this->providerPays($p3['id'])))->assertOk();
        Event::swap($real);
        PickupSlotReservation::query()->where('checkout_session_id', $this->sessionModel($c3['id'])->getKey())->update(['status' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $this->reserve(CarbonImmutable::instance($this->sessionModel($c3['id'])->pickup_slot_starts_at)->setTimezone('Asia/Kolkata')->format('H:i'));
        $blocked = app(OrderFulfillmentService::class)->fulfillPayment($p3['id']);
        $this->assertSame('REVIEW_REQUIRED', $blocked->status->value);

        $this->adminFor();
        $this->assertMatchesSchema('AdminOrderPage', $this->adminGet('/orders')->assertOk()->json());
        $this->assertMatchesSchema('AdminOrderEnvelope', $this->adminGet('/orders/'.$order['order_number'])->assertOk()->json());
        $this->assertMatchesSchema('AdminOrderEnvelope', $this->adminGet('/orders/'.$zero['id'])->assertOk()->json());
        $this->assertMatchesSchema('OrderFulfillmentPage', $this->adminGet('/order-fulfillments?filter[attention]=1')->assertOk()->json());
        $this->assertMatchesSchema('OrderFulfillmentEnvelope', $this->adminPost('/order-fulfillments/'.$blocked->public_id.'/recover', ['reason' => 'Contract check'])->assertOk()->json());
        $this->assertMatchesSchema('AdminOrderEnvelope', $this->adminPost('/orders/'.$order['order_number'].'/cancel', ['reason' => 'Contract check'])->assertOk()->json());
        $this->assertMatchesSchema('OrderMetrics', $this->adminGet('/system/orders')->assertOk()->json());
        $this->adminFor([Permission::AdminSystemView]);
        $this->assertMatchesSchema('PaymentMetrics', $this->adminGet('/system/payments')->assertOk()->json());
    }

    public function test_every_order_route_is_documented_and_no_request_has_room_for_an_amount_a_status_or_a_pickup(): void
    {
        $api = $this->openApi();
        $paths = $api['paths'];
        $mine = fn (string $uri): bool => (bool) preg_match('#^customer/orders|^restaurant/orders|^admin/orders|^admin/order-fulfillments|^admin/system/orders|/fulfill$|^restaurant/pickup|^customer/broadcasting|^restaurant/broadcasting|^admin/system/realtime|^admin/pickup-attempts#', $uri);
        $documented = [];
        foreach ($paths as $path => $operations) {
            if ($mine(ltrim($path, '/'))) {
                foreach (array_keys($operations) as $method) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => [preg_replace('#^api/v1/#', '', $r->uri()), array_diff($r->methods(), ['HEAD'])])->filter(fn (array $r): bool => $mine($r[0]))
            ->flatMap(fn (array $r) => array_map(fn (string $m): string => $m.' /'.$r[0], $r[1]))->values()->all();
        $this->assertEqualsCanonicalizing($routes, $documented);
        $this->assertCount(26, $routes);   // + 2 (Module 34: GET / POST customer/orders/{order}/review)
        // previously 24:   // 15 (Module 31) + 9 (Module 32: events ×2, pickup credential ×2, verify, channel auth ×2, system realtime, pickup attempts)

        // Who may call what.
        foreach (['/customer/orders', '/customer/orders/{order}', '/checkouts/{checkout}/fulfill', '/customer/orders/{order}/events', '/customer/orders/{order}/pickup-credential', '/customer/orders/{order}/pickup-credential/reissue', '/customer/broadcasting/auth'] as $path) {
            foreach ($paths[$path] as $operation) {
                $this->assertSame([['customer' => []]], $operation['security'], $path);
            }
        }
        foreach ($paths as $path => $operations) {
            if (str_starts_with($path, '/restaurant/orders')) {
                foreach ($operations as $operation) {
                    $this->assertSame([['restaurant' => []]], $operation['security'], $path);
                }
            }
            if (str_starts_with($path, '/admin/order')) {
                foreach ($operations as $operation) {
                    $this->assertSame([['admin' => []]], $operation['security'], $path);
                }
            }
        }
        // No request body has room for what the server decides.
        $schemas = $api['components']['schemas'];
        $forbidden = ['amount', 'amount_minor', 'total', 'total_minor', 'currency', 'status', 'payment_state', 'payment_required', 'paid', 'payment_id', 'order_number', 'items', 'customer', 'customer_id', 'pickup_at', 'picked_up', 'refund_status', 'refund', 'code', 'token', 'qr_payload'];
        foreach (['CheckoutFulfillRequest', 'OrderStepRequest', 'OrderRejectRequest', 'AdminOrderCancelRequest', 'OrderRecoverRequest', 'PickupCredentialReissueRequest', 'BroadcastingAuthRequest'] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], $name);
            $this->assertSame([], array_values(array_intersect(array_keys($schemas[$name]['properties'] ?? []), $forbidden)), $name);
        }
        $this->assertSame([], $schemas['CheckoutFulfillRequest']['properties']);
        // The pickup verification request names the order (manual code) and carries the customer's credential — and nothing the server decides.
        $this->assertFalse($schemas['PickupVerifyRequest']['additionalProperties']);
        $this->assertSame(['location', 'method', 'payload', 'order_number', 'code'], array_keys($schemas['PickupVerifyRequest']['properties']));
        $this->assertSame([], array_values(array_intersect(array_keys($schemas['PickupVerifyRequest']['properties']), array_diff($forbidden, ['order_number', 'code']))));
        $this->assertSame(['reason'], $schemas['OrderRejectRequest']['required']);
        // The documented states are the states of the code; what does not exist is said so.
        $visible = array_values(array_filter(array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::cases()), fn (string $s): bool => $s !== 'CREATED'));
        $this->assertSame($visible, $schemas['Order']['properties']['status']['enum']);
        $this->assertSame(array_map(fn (OrderRefundStatus $s): string => $s->value, OrderRefundStatus::cases()), $schemas['Order']['properties']['refund_status']['enum']);
        $this->assertSame(array_map(fn (OrderRejectionReason $s): string => $s->value, OrderRejectionReason::cases()), $schemas['OrderRejectRequest']['properties']['reason']['enum']);
        $this->assertSame(array_map(fn (OrderFulfillmentStatus $s): string => $s->value, OrderFulfillmentStatus::cases()), $schemas['OrderFulfillment']['properties']['status']['enum']);
        $this->assertSame(['accept', 'reject', 'start_preparing', 'ready', 'verify_pickup'], $schemas['RestaurantOrderSummary']['properties']['allowed_actions']['items']['enum']);   // Module 32: READY → the pickup is verified
        $this->assertSame('#/components/schemas/OrderPickupVerification', $schemas['Order']['properties']['pickup_verification']['$ref']);   // Module 32: a real state, not a marker
        $this->assertSame(['NOT_YET', 'AVAILABLE', 'VERIFIED', 'NOT_POSSIBLE'], $schemas['OrderPickupVerification']['properties']['status']['enum']);
        $this->assertSame(['NOT_IMPLEMENTED'], $schemas['AdminOrderDetail']['properties']['actions']['properties']['refund']['enum']);
        $this->assertSame(['NOT_IMPLEMENTED'], $schemas['OrderMetrics']['properties']['refund_processing']['enum']);
        $this->assertSame('^FOTG-[0-9]{8}-[A-HJ-NP-Z2-9]{6}$', $schemas['Order']['properties']['order_number']['pattern']);
        // Money is integer minor units everywhere; the restaurant's order has no credential and no payment method.
        $numbers = [];
        foreach (['Order', 'OrderSummary', 'RestaurantOrder', 'AdminOrder', 'AdminOrderDetail', 'OrderItem', 'OrderPricing'] as $name) {
            array_walk_recursive($schemas[$name], function ($value, $key) use (&$numbers): void {
                if ($key === 'type' && $value === 'number') {
                    $numbers[] = $value;
                }
            });
        }
        $this->assertSame([], $numbers);
        $this->assertArrayNotHasKey('pickup_credential', $schemas['RestaurantOrder']['properties']);
        $this->assertArrayNotHasKey('payment', $schemas['RestaurantOrder']['properties']);
        $this->assertArrayNotHasKey('code', $schemas['AdminOrderDetail']['properties']['pickup_credential']['properties']);
        // The payment now says which order it became — and nothing else about it.
        $this->assertSame(['status', 'order_id', 'order_number'], array_keys($schemas['Payment']['properties']['fulfillment']['properties']));
        $text = json_encode($api);
        foreach (['rzp_live_', 'rzp_test_', 'key_secret":"', 'whsec_'] as $secretLike) {
            $this->assertStringNotContainsString($secretLike, $text);
        }
    }
}
