<?php

namespace Tests\Feature\Refund;

use App\Enums\DisputeStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\PaymentDispute;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsRefunds;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every refund answer matches the schema documented for it, every refund route is documented, and no documented
 * request has room for a status, an approved amount, a payment or a provider reference (Module 35).
 */
class RefundOpenApiContractTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.refund_action' => 1000, 'rate_limits.order_cancel' => 1000]);
    }

    public function test_the_answers_of_every_side_match_their_schemas_in_every_refund_state(): void
    {
        [, $customer, $order, $payment, $refund] = $this->rejectedPaidOrder();
        $n = $order['order_number'];
        $id = (string) $refund->public_id;

        // REQUIRED.
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($customer, $n)->assertOk()->json());
        $this->assertMatchesSchema('OrderSummaryPage', $this->listOrders($customer)->assertOk()->json());
        $this->assertMatchesSchema('OrderCancellationQuoteEnvelope', $this->getJson('/api/v1/customer/orders/'.$n.'/cancellation', $this->using($customer))->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/customer/orders/'.$n.'/cancel', ['reason_code' => 'CHANGED_MIND'], $this->using($customer))->assertStatus(409)->json());
        $this->assertMatchesSchema('RestaurantOrderEnvelope', $this->restaurantOrder($n)->assertOk()->json());
        $this->assertMatchesSchema('RestaurantOrderPage', $this->restaurantOrders('filter[queue]=history')->assertOk()->json());

        $this->finance();
        $this->assertMatchesSchema('AdminRefundPage', $this->refundGet()->assertOk()->json());
        $this->assertMatchesSchema('RefundQueues', $this->refundGet('/queues')->assertOk()->json());
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundGet('/'.$id)->assertOk()->json());
        $this->assertMatchesSchema('AdminOrderEnvelope', $this->adminGet('/orders/'.$n)->assertOk()->json());
        $this->assertMatchesSchema('AdminOrderPage', $this->adminGet('/orders?filter[attention]=1')->assertOk()->json());
        $this->assertMatchesSchema('AdminPaymentEnvelope', $this->adminGet('/payments/'.$payment['id'])->assertOk()->json());
        $this->assertMatchesSchema('RefundMetrics', $this->adminGet('/system/refunds')->assertOk()->json());
        $this->assertMatchesSchema('PaymentMetrics', $this->adminGet('/system/payments')->assertOk()->json());
        $this->assertMatchesSchema('OrderMetrics', $this->adminGet('/system/orders')->assertOk()->json());

        // APPROVED → PROCESSING → REFUNDED, each as every side sees it.
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundPost($refund, 'approve', ['reason' => 'Checked'])->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($customer, $n)->assertOk()->json());
        $this->finance();
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundPost($refund, 'execute')->assertOk()->json());
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundPost($refund, 'reconcile', ['reason' => 'Look'])->assertOk()->json());
        $this->assertMatchesSchema('FakeRefundSimulation', $this->devPost('/refunds/'.$id.'/simulate', ['scenario' => 'PROCESSED', 'webhook' => 'deliver'])->assertOk()->json());
        $this->assertSame(RefundStatus::Refunded, $refund->refresh()->status);
        $this->finance();
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundGet('/'.$id)->assertOk()->json());
        $this->assertMatchesSchema('AdminOrderEnvelope', $this->adminGet('/orders/'.$n)->assertOk()->json());
        $this->assertMatchesSchema('AdminPaymentEnvelope', $this->adminGet('/payments/'.$payment['id'])->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($customer, $n)->assertOk()->json());
        $this->orderStaff();
        $this->assertMatchesSchema('RestaurantOrderEnvelope', $this->restaurantOrder($n)->assertOk()->json());

        // A request, its refusals, a rejection, a failure, a retry.
        [, $other, $completed] = $this->completedPaidOrder('+919876543212');
        $this->finance();
        $asked = $this->askRefund((string) $completed->order_number, ['type' => 'PARTIAL', 'amount_minor' => 5000, 'reason_code' => 'OTHER', 'note' => 'Cold fries'])->assertCreated();
        $this->assertMatchesSchema('AdminRefundEnvelope', $asked->json());
        $this->assertMatchesSchema('Error', $this->askRefund((string) $completed->order_number, ['type' => 'PARTIAL', 'amount_minor' => 99999999, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(409)->json());
        $this->assertMatchesSchema('Error', $this->askRefund((string) $completed->order_number, ['type' => 'PARTIAL', 'amount_minor' => 0, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422)->json());
        $this->assertMatchesSchema('Error', $this->askRefund((string) $completed->order_number, ['type' => 'FULL', 'reason_code' => 'ITEM_UNAVAILABLE', 'status' => 'REFUNDED'])->assertStatus(422)->json());
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundPost($asked->json('refund.id'), 'reject', ['reason' => 'Not justified'])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->refundPost($asked->json('refund.id'), 'execute')->assertStatus(409)->json());
        $this->assertMatchesSchema('Error', $this->refundGet('/00000000-0000-4000-8000-000000000000')->assertNotFound()->json());
        $again = Refund::query()->where('public_id', $this->askRefund((string) $completed->order_number, ['type' => 'FULL', 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated()->json('refund.id'))->sole();
        $this->assertMatchesSchema('FakeRefundSimulation', $this->devPost('/refunds/'.$again->public_id.'/simulate', ['scenario' => 'NEXT_FAILED'])->assertOk()->json());
        $this->refundPost($again, 'approve')->assertOk();
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundPost($again, 'execute')->assertOk()->assertJsonPath('refund.status', 'FAILED')->json());
        $this->devPost('/refunds/'.$again->public_id.'/simulate', ['scenario' => 'PROVIDER_UP'])->assertOk();
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundPost($again, 'retry', ['reason' => 'Provider is back'])->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($other, (string) $completed->order_number)->assertOk()->json());

        // Disputes.
        $this->finance();
        $opened = $this->devPost('/payments/'.$payment['id'].'/dispute', ['scenario' => 'OPEN'])->assertOk();
        $this->assertMatchesSchema('FakeRefundSimulation', $opened->json());
        $this->assertMatchesSchema('FakeRefundSimulation', $this->devPost('/payments/'.$payment['id'].'/dispute', ['scenario' => 'LOST', 'dispute_reference' => $opened->json('simulation.dispute_reference')])->assertOk()->json());
        $this->assertSame(DisputeStatus::Lost, PaymentDispute::query()->sole()->status);
        $this->assertMatchesSchema('AdminDisputePage', $this->adminGet('/disputes')->assertOk()->json());
        $this->assertMatchesSchema('AdminDisputeEnvelope', $this->adminGet('/disputes/'.PaymentDispute::query()->sole()->public_id)->assertOk()->json());
        $this->assertMatchesSchema('AdminPaymentEnvelope', $this->adminGet('/payments/'.$payment['id'])->assertOk()->json());
        $this->assertMatchesSchema('AdminRefundEnvelope', $this->refundGet('/'.$id)->assertOk()->json());
        $this->assertMatchesSchema('RefundMetrics', $this->adminGet('/system/refunds')->assertOk()->json());
    }

    public function test_a_customers_cancellation_answers_match_their_schemas_when_a_policy_is_enabled(): void
    {
        config(['refunds.customer_cancellation.enabled' => true, 'refunds.customer_cancellation.financial_effect' => 'FULL_REFUND']);
        [, $headers, , , $order] = $this->paidOrder('+919876543210', $this->nextSlot());

        $this->assertMatchesSchema('OrderCancellationQuoteEnvelope', $this->getJson('/api/v1/customer/orders/'.$order['order_number'].'/cancellation', $this->using($headers))->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/cancel', ['reason_code' => 'CHANGED_MIND', 'note' => 'Plans changed'], $this->using($headers))->assertOk()->json());
        $this->assertMatchesSchema('OrderCancellationQuoteEnvelope', $this->getJson('/api/v1/customer/orders/'.$order['order_number'].'/cancellation', $this->using($headers))->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/cancel', ['reason_code' => 'CHANGED_MIND', 'refund_amount' => 1], $this->using($headers))->assertStatus(422)->json());
        $this->finance();
        $this->assertMatchesSchema('AdminOrderEnvelope', $this->adminGet('/orders/'.$order['order_number'])->assertOk()->json());
    }

    public function test_every_refund_route_is_documented_and_no_request_has_room_for_a_status_or_a_provider_reference(): void
    {
        $doc = $this->openApi();
        $documented = [];
        foreach ($doc['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        $mine = fn (string $route): bool => (bool) preg_match('#refund|dispute|/customer/orders/\{order\}/cancel#', $route);
        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn (string $m): bool => $m === 'HEAD')->map(fn (string $m): string => $m.' '.preg_replace('#^/api/v1#', '', '/'.ltrim($r->uri(), '/'))))
            ->filter($mine)->unique()->sort()->values()->all();
        $this->assertSame([
            'GET /admin/disputes', 'GET /admin/disputes/{dispute}', 'GET /admin/refunds', 'GET /admin/refunds/queues', 'GET /admin/refunds/{refund}', 'GET /admin/system/refunds',
            'GET /customer/orders/{order}/cancellation',
            'POST /admin/orders/{order}/refunds', 'POST /admin/payments/{payment}/refunds',
            'POST /admin/refunds/{refund}/approve', 'POST /admin/refunds/{refund}/execute', 'POST /admin/refunds/{refund}/reconcile', 'POST /admin/refunds/{refund}/reject', 'POST /admin/refunds/{refund}/retry',
            'POST /customer/orders/{order}/cancel', 'POST /dev/payments/{payment}/dispute', 'POST /dev/refunds/{refund}/simulate',
        ], $registered);
        $this->assertSame($registered, collect($documented)->filter($mine)->sort()->values()->all(), 'every refund route is documented and nothing else is');

        $schemas = $doc['components']['schemas'];
        // The enums of the documentation are the enums of the code.
        $values = fn (array $cases): array => array_map(fn ($c): string => $c->value, $cases);
        $this->assertSame($values(RefundStatus::cases()), $schemas['AdminRefund']['properties']['status']['enum']);
        // The CUSTOMER's order carries the customer's words: FAILED / REVIEW_REQUIRED are UNDER_REVIEW there; the restaurant's and
        // the administrator's order carry the states of the code.
        $forCustomer = array_values(array_unique(array_map(fn (OrderRefundStatus $s): string => $s->forCustomer(), OrderRefundStatus::cases())));
        $this->assertSame($forCustomer, $schemas['Order']['properties']['refund_status']['enum']);
        $this->assertSame($forCustomer, $schemas['CustomerOrderRefunds']['properties']['status']['enum']);
        $this->assertNotContains('FAILED', $forCustomer);
        $this->assertSame($values(OrderRefundStatus::cases()), $schemas['AdminOrder']['properties']['refund_status']['enum']);
        $this->assertSame($values(OrderRefundStatus::cases()), $schemas['RestaurantOrder']['properties']['refund_status']['enum']);
        $this->assertSame($values(RefundReason::cases()), $schemas['AdminRefund']['properties']['reason']['properties']['code']['enum']);
        $this->assertSame($values(DisputeStatus::cases()), $schemas['AdminDispute']['properties']['status']['enum']);
        $this->assertSame($values(RefundReason::forOrderRequests()), $schemas['AdminRefundRequestForOrder']['properties']['reason_code']['enum']);
        $this->assertSame($values(RefundReason::forCancellations()), $schemas['AdminOrderCancelRequest']['properties']['reason_code']['enum']);
        $this->assertSame((array) config('refunds.customer_cancellation.reasons'), $schemas['CustomerCancelOrderRequest']['properties']['reason_code']['enum']);

        // No request can carry what the server derives or the provider decides.
        $forbidden = ['status', 'refund_status', 'approved_amount', 'approved_amount_minor', 'provider', 'provider_refund_reference', 'provider_payment_reference', 'payment_id', 'order_id', 'customer_id', 'completed_at', 'refunded', 'financial_effect', 'refund_amount', 'refund_amount_minor'];
        foreach (['AdminRefundRequestForOrder', 'AdminRefundRequestForPayment', 'AdminRefundDecisionRequest', 'CustomerCancelOrderRequest', 'AdminOrderCancelRequest'] as $request) {
            $this->assertFalse($schemas[$request]['additionalProperties'], $request.' is closed');
            $this->assertSame([], array_values(array_intersect($forbidden, array_keys($schemas[$request]['properties']))), $request.' has no room for a decided field');
        }
        $this->assertArrayNotHasKey('amount_minor', $schemas['AdminRefundDecisionRequest']['properties']);
        $this->assertArrayNotHasKey('amount_minor', $schemas['AdminRefundRequestForPayment']['properties']);
        $this->assertArrayNotHasKey('amount_minor', $schemas['CustomerCancelOrderRequest']['properties']);
        // "Mark refunded" is documented as what it is: not provided.
        $this->assertSame(['NOT_PROVIDED'], $schemas['AdminRefundDetail']['properties']['actions']['properties']['mark_refunded']['enum']);
        $this->assertSame(['NOT_PROVIDED'], $schemas['AdminOrderDetail']['properties']['actions']['properties']['mark_refunded']['enum']);
        $this->assertSame(['NOT_PROVIDED'], $schemas['AdminPaymentDetail']['properties']['actions']['properties']['mark_refunded']['enum']);
        // What the customer is shown of a refund has no provider field.
        $this->assertSame(['id', 'reference', 'type', 'amount', 'status', 'reason', 'requested_at', 'completed_at'], array_keys($schemas['CustomerRefund']['properties']));
        $this->assertSame(['status', 'amount', 'refunded', 'controls'], array_keys($schemas['RestaurantOrderRefund']['properties']));
        // No secret is documented anywhere in the refund schemas.
        $text = json_encode(array_intersect_key($schemas, array_flip(['AdminRefund', 'AdminRefundDetail', 'CustomerRefund', 'CustomerOrderRefunds', 'RestaurantOrderRefund', 'AdminDispute', 'RefundMetrics'])));
        foreach (['key_secret', 'webhook_secret', 'card_number', 'cvv', 'upi_pin', 'password'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $text);
        }
    }
}
