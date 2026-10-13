<?php

namespace Tests\Feature\Refund;

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\RefundStatus;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\Refund;
use App\Models\RefundAttempt;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — who may do what to a refund, and what nobody may do.
 *
 * Reading, asking, approving, sending and reconciling are five permissions; an administrator holds each one for a
 * market or for all. A customer sees only their own refunds; a restaurant sees a state and no control. No request
 * can set a refund's status, amount, currency, payment or provider reference — and there is no "mark as refunded".
 */
class RefundSecurityTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        // These tests send far more refund actions a minute than a person does; the limit itself is tested on its own.
        config(['rate_limits.refund_action' => 1000]);
    }

    public function test_refund_actions_are_rate_limited_per_administrator(): void
    {
        config(['rate_limits.refund_action' => 3]);
        [, , , , $refund] = $this->rejectedPaidOrder();
        $this->finance();
        foreach ([1, 2, 3] as $n) {
            $this->refundPost($refund, 'reconcile', ['reason' => 'Look '.$n])->assertOk();
        }
        $this->refundPost($refund, 'reconcile', ['reason' => 'Look 4'])->assertStatus(429);
        $this->refundPost($refund, 'approve')->assertStatus(429);
        // Another administrator has their own allowance; reading is not limited with it.
        $this->refundGet('/'.$refund->public_id)->assertOk();
        $this->finance();
        $this->refundPost($refund, 'approve')->assertOk();
    }

    public function test_each_refund_action_needs_its_own_permission(): void
    {
        [, , $order, , $refund] = $this->rejectedPaidOrder();
        $id = (string) $refund->public_id;

        // No session at all, a customer's token, a restaurant's token: not administrators.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/refunds')->assertStatus(401);
        $this->postJson('/api/v1/admin/refunds/'.$id.'/execute')->assertStatus(401);

        // An administrator without any refund permission.
        $this->adminFor([Permission::AdminOrdersView, Permission::AdminPaymentsView]);
        $this->refundGet()->assertForbidden();
        $this->refundGet('/'.$id)->assertForbidden();
        $this->refundGet('/queues')->assertForbidden();
        foreach (['approve', 'reject', 'execute', 'retry', 'reconcile'] as $action) {
            $this->refundPost($id, $action, ['reason' => 'No permission'])->assertForbidden();
        }
        $this->askRefund($order['order_number'], ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'x'])->assertForbidden();

        // View only (operations, support): reads everything, changes nothing.
        $this->adminFor([Permission::AdminRefundsView]);
        $seen = $this->refundGet('/'.$id)->assertOk()->json('refund');
        $this->assertSame(['approve' => false, 'reject' => false, 'execute' => false, 'retry' => false, 'reconcile' => false, 'mark_refunded' => 'NOT_PROVIDED'], $seen['actions']);
        foreach (['approve', 'reject', 'execute', 'retry', 'reconcile'] as $action) {
            $this->refundPost($id, $action, ['reason' => 'View only'])->assertForbidden();
        }
        $this->askRefund($order['order_number'], ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'x'])->assertForbidden();

        // May ask, may not approve; may approve, may not send; may send, may not approve.
        $this->adminFor([Permission::AdminRefundsView, Permission::AdminRefundsCreate]);
        $this->refundPost($id, 'approve')->assertForbidden();
        $this->adminFor([Permission::AdminRefundsView, Permission::AdminRefundsIssue]);
        $this->refundPost($id, 'approve')->assertForbidden();
        $this->refundPost($id, 'execute')->assertStatus(409)->assertJsonPath('error.code', 'refund_not_approved');   // allowed to send — but nothing is approved
        $this->adminFor([Permission::AdminRefundsView, Permission::AdminRefundsApprove]);
        $this->refundPost($id, 'approve')->assertOk()->assertJsonPath('refund.status', 'APPROVED')->assertJsonPath('refund.actions.execute', false);
        $this->refundPost($id, 'execute')->assertForbidden();
        $this->refundPost($id, 'reconcile', ['reason' => 'Look'])->assertForbidden();
        $this->assertSame(RefundStatus::Approved, $refund->refresh()->status);
        $this->assertSame(0, RefundAttempt::query()->count(), 'nothing reached the provider');

        // The seeded roles: finance holds the whole workflow; operations and support read; nobody else sees refunds.
        $roles = RoleSeeder::adminRoles();
        $has = fn (string $role, Permission $p): bool => in_array($p, $roles[$role][1], true);
        foreach ([Permission::AdminRefundsView, Permission::AdminRefundsCreate, Permission::AdminRefundsApprove, Permission::AdminRefundsIssue, Permission::AdminRefundsReconcile, Permission::AdminDisputesView] as $p) {
            $this->assertTrue($has('FINANCE_ADMIN', $p), 'finance: '.$p->value);
        }
        foreach (['OPERATIONS_ADMIN', 'SUPPORT_ADMIN'] as $role) {
            $this->assertTrue($has($role, Permission::AdminRefundsView));
            foreach ([Permission::AdminRefundsCreate, Permission::AdminRefundsApprove, Permission::AdminRefundsIssue, Permission::AdminRefundsReconcile] as $p) {
                $this->assertFalse($has($role, $p), $role.' must not hold '.$p->value);
            }
        }
        foreach (['MODERATION_ADMIN', 'ANALYST', 'RESTAURANT_ONBOARDING'] as $role) {
            $this->assertFalse($has($role, Permission::AdminRefundsIssue));
            $this->assertFalse($has($role, Permission::AdminRefundsApprove));
        }
        // No restaurant role holds any refund permission: they are administrators' permissions.
        foreach (Permission::for(PrincipalType::RestaurantUser) as $p) {
            $this->assertStringNotContainsString('refund', $p->value);
        }
    }

    public function test_a_permission_for_one_market_does_not_reach_another_markets_refunds(): void
    {
        [, , $order, , $refund] = $this->rejectedPaidOrder();
        $other = Market::factory()->create(['country_code' => 'AE', 'slug' => 'elsewhere']);

        $this->asActor($this->adminWith(self::FINANCE, $other));
        $this->assertSame([], $this->refundGet()->assertOk()->json('data'));
        $this->assertSame(0, $this->refundGet('/queues')->assertOk()->json('total'));
        $this->refundGet('/'.$refund->public_id)->assertForbidden();
        $this->refundPost($refund, 'approve')->assertForbidden();
        $this->askRefund($order['order_number'], ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'x'])->assertForbidden();

        $this->asActor($this->adminWith(self::FINANCE, $refund->market));
        $this->assertCount(1, $this->refundGet()->assertOk()->json('data'));
        $this->refundPost($refund, 'approve')->assertOk();
    }

    public function test_a_customer_sees_only_their_own_refunds_and_can_do_nothing_to_them(): void
    {
        [, $rahul, $order, , $refund] = $this->rejectedPaidOrder();
        [, $asha] = $this->signedIn('+919876543212');
        $n = $order['order_number'];

        // Somebody else's order — and with it its refund — is "not found", exactly as an order that does not exist.
        $this->showOrder($asha, $n)->assertNotFound();
        $this->getJson('/api/v1/customer/orders/'.$n.'/cancellation', $this->using($asha))->assertNotFound();
        $this->postJson('/api/v1/customer/orders/'.$n.'/cancel', ['reason_code' => 'CHANGED_MIND'], $this->using($asha))->assertNotFound();
        $this->assertStringNotContainsString((string) $refund->public_id, (string) $this->listOrders($asha)->getContent());

        // The owner reads it — and there is no customer route that creates, approves or executes a refund.
        $this->assertSame((string) $refund->public_id, $this->showOrder($rahul, $n)->json('order.refund.items.0.id'));
        foreach (['/api/v1/customer/orders/'.$n.'/refunds', '/api/v1/customer/orders/'.$n.'/refund', '/api/v1/customer/refunds/'.$refund->public_id.'/execute', '/api/v1/refunds/'.$refund->public_id] as $url) {
            $this->postJson($url, ['amount_minor' => 50000], $this->using($rahul))->assertNotFound();
            $this->getJson($url, $this->using($rahul))->assertNotFound();
        }
        // A customer's token is not an administrator's.
        $this->getJson('/api/v1/admin/refunds/'.$refund->public_id, $this->using($rahul))->assertStatus(401);
        $this->postJson('/api/v1/admin/refunds/'.$refund->public_id.'/approve', [], $this->using($rahul))->assertStatus(401);
        $this->assertSame(RefundStatus::Required, $refund->refresh()->status);
    }

    public function test_nothing_about_a_refund_can_be_set_by_hand_and_there_is_no_mark_refunded(): void
    {
        [, , $order, , $refund] = $this->rejectedPaidOrder();
        [, , $completed] = $this->completedPaidOrder('+919876543212');
        $this->finance();

        // No field of a decision can carry a state, an amount, a currency, a payment or a provider reference.
        foreach ([
            ['status' => 'REFUNDED'], ['refund_status' => 'REFUNDED'], ['provider_refund_reference' => 'rfnd_Forged00000001'], ['provider_payment_reference' => 'pay_Forged000000001'],
            ['completed_at' => '2026-10-07T12:00:00Z'], ['approved_amount_minor' => 1], ['amount_minor' => 1], ['currency' => 'USD'], ['payment_id' => (string) $refund->payment_public_id], ['customer_id' => 1], ['provider' => 'razorpay'], ['refunded' => true],
        ] as $i => $forged) {
            // Every action refuses every one of them; each field is sent to one action here and all five take the same rule.
            $action = ['approve', 'execute', 'reconcile', 'reject', 'retry'][$i % 5];
            $this->refundPost($refund, $action, ['reason' => 'Forged field'] + $forged)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        foreach (['approve', 'execute', 'reconcile', 'reject', 'retry'] as $action) {
            $this->refundPost($refund, $action, ['reason' => 'Forged field', 'status' => 'REFUNDED', 'provider_refund_reference' => 'rfnd_Forged00000001'])->assertStatus(422);
        }
        // … nor can a request.
        foreach ([['status' => 'REFUNDED'], ['provider_refund_reference' => 'rfnd_Forged00000001'], ['payment_id' => (string) $refund->payment_public_id], ['approved_amount_minor' => 100], ['customer_id' => 7], ['completed_at' => 'now']] as $forged) {
            $this->askRefund((string) $completed->order_number, ['type' => 'FULL', 'reason_code' => 'ITEM_UNAVAILABLE'] + $forged)->assertStatus(422);
        }
        $this->assertSame([RefundStatus::Required, null, 1], [$refund->refresh()->status, $refund->provider_refund_reference, Refund::query()->count()]);

        // There is no way in beside the documented actions: no PATCH / PUT / DELETE, no "mark refunded", no status editor.
        $id = (string) $refund->public_id;
        $this->patchJson('/api/v1/admin/refunds/'.$id, ['status' => 'REFUNDED'])->assertStatus(405);
        $this->putJson('/api/v1/admin/refunds/'.$id, ['status' => 'REFUNDED'])->assertStatus(405);
        $this->deleteJson('/api/v1/admin/refunds/'.$id)->assertStatus(405);
        foreach (['mark-refunded', 'mark_refunded', 'complete', 'refunded', 'status', 'settle', 'force'] as $action) {
            $this->postJson('/api/v1/admin/refunds/'.$id.'/'.$action, ['reason' => 'x'])->assertNotFound();
        }
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->filter(fn (string $r): bool => str_contains($r, 'refund') && ! str_contains($r, '/analytics/'))->values()->all();   // Module 38: a read-only report about refunds is not a refund route
        sort($routes);
        $this->assertSame([
            'GET|HEAD api/v1/admin/refunds',
            'GET|HEAD api/v1/admin/refunds/queues',
            'GET|HEAD api/v1/admin/refunds/{refund}',
            'GET|HEAD api/v1/admin/system/refunds',
            'POST api/v1/admin/orders/{order}/refunds',
            'POST api/v1/admin/payments/{payment}/refunds',
            'POST api/v1/admin/refunds/{refund}/approve',
            'POST api/v1/admin/refunds/{refund}/execute',
            'POST api/v1/admin/refunds/{refund}/reconcile',
            'POST api/v1/admin/refunds/{refund}/reject',
            'POST api/v1/admin/refunds/{refund}/retry',
            'POST api/v1/dev/refunds/{refund}/simulate',   // the FAKE provider's side, never registered in production
        ], $routes);

        // The development simulator cannot set a refund's status either: with nothing at the fake provider it has nothing to settle.
        $this->devPost('/refunds/'.$id.'/simulate', ['scenario' => 'PROCESSED'])->assertStatus(409)->assertJsonPath('error.code', 'refund_not_with_provider');
        $this->assertSame(RefundStatus::Required, $refund->refresh()->status);

        // The answers carry no secret and no payment credential: there is none to carry.
        $this->refundPost($refund, 'approve')->assertOk();
        $body = (string) $this->refundPost($refund, 'execute')->assertOk()->getContent();
        foreach (['key_secret', 'webhook_secret', 'signature', 'card_number', 'cvv', 'upi_pin', 'password', '+91'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $body);
        }
        $this->assertStringNotContainsString((string) $order['id'].'x', $body);
    }

    public function test_every_decision_is_audited_with_who_and_why_and_four_eyes_can_be_required(): void
    {
        [, , $order] = $this->completedPaidOrder();
        $asker = $this->finance();
        $asked = $this->askRefund((string) $order->order_number, ['type' => 'PARTIAL', 'amount_minor' => 5000, 'reason_code' => 'OTHER', 'note' => 'Wrong drink'])->assertCreated()->json('refund');
        $refund = Refund::query()->where('public_id', $asked['id'])->sole();
        $this->assertSame(['ADMIN', (string) $asker->public_id], [$refund->requested_by_type, $refund->requested_by_public_id]);

        // With four eyes switched on, the person who asked cannot be the one who approves.
        config(['refunds.policy.four_eyes' => true]);
        $this->refundPost($refund, 'approve', ['reason' => 'My own request'])->assertForbidden()->assertJsonPath('error.code', 'refund_not_authorized');
        $approver = $this->finance();
        $this->fakeProvider()->refundsBecome('pending');
        $approved = $this->refundPost($refund, 'approve', ['reason' => 'Confirmed with the restaurant'])->assertOk()->json('refund');
        $this->assertSame([(string) $approver->public_id, 'MANUAL'], [$approved['approval']['by']['id'], $approved['approval']['mode']]);
        $this->assertNotSame($approved['requested_by']['id'], $approved['approval']['by']['id'], 'who asked is not who approved');

        // A client that acts on an old version is told so.
        $this->refundPost($refund, 'reject', ['reason' => 'Stale', 'expected_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'refund_version_conflict');

        $this->refundPost($refund, 'execute', ['reason' => 'Approved by finance'])->assertOk()->assertJsonPath('refund.status', 'PROCESSING');
        $this->refundPost($refund, 'reconcile', ['reason' => 'Customer asked for the status'])->assertOk();
        $trail = AuditEvent::query()->where('target_type', 'refunds')->where('target_public_id', $refund->public_id)->orderBy('id')->get();
        $this->assertSame(['refund.requested', 'refund.approved', 'refund.executed', 'refund.reconciled'], $trail->pluck('action')->all());
        $this->assertSame([(string) $asker->public_id, (string) $approver->public_id, (string) $approver->public_id, (string) $approver->public_id], $trail->pluck('actor_public_id')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['Wrong drink', 'Confirmed with the restaurant', 'Approved by finance', 'Customer asked for the status'], $trail->pluck('reason')->all());
        $this->assertSame((int) $refund->market_id, (int) $trail[0]->market_id);
        // The administrator's detail shows the trail.
        $this->assertSame(['refund.requested', 'refund.approved', 'refund.executed', 'refund.reconciled'], array_column($this->refundGet('/'.$refund->public_id)->json('refund.audit'), 'action'));

        // Turning a request down needs a reason, and is audited as well.
        $second = $this->askRefund((string) $order->order_number, ['type' => 'PARTIAL', 'amount_minor' => 1000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated()->json('refund.id');
        $this->refundPost($second, 'reject')->assertStatus(422);
        $this->refundPost($second, 'reject', ['reason' => 'Already compensated'])->assertOk()->assertJsonPath('refund.status', 'CANCELLED')->assertJsonPath('refund.closed.note', 'Already compensated');
        $this->assertSame('Already compensated', AuditEvent::query()->where('action', 'refund.rejected')->sole()->reason);
    }

    public function test_the_list_filters_and_searches_by_safe_references(): void
    {
        $this->fakeProvider()->refundsBecome('processed');
        [, , $a, $payment, $first] = $this->rejectedPaidOrder();
        [, , $b, , $second] = $this->rejectedPaidOrder('+919876543212', 'CLOSING');
        [, , $completed] = $this->completedPaidOrder('+919876543213');
        $this->finance();
        $this->approveAndExecute($first);
        $partial = $this->askRefund((string) $completed->order_number, ['type' => 'PARTIAL', 'amount_minor' => 5000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated()->json('refund');

        $ids = fn (string $query): array => array_column($this->refundGet($query)->assertOk()->json('data'), 'id');
        $this->assertCount(3, $ids(''));
        $this->assertSame([(string) $first->public_id], $ids('?filter[status]=REFUNDED'));
        $this->assertSame([(string) $first->public_id], $ids('?filter[queue]=completed'));
        $this->assertSame([(string) $second->public_id], $ids('?filter[queue]=required'));
        $this->assertEqualsCanonicalizing([(string) $second->public_id, $partial['id']], $ids('?filter[queue]=awaiting_approval'));
        $this->assertSame([$partial['id']], $ids('?filter[type]=PARTIAL'));
        $this->assertSame([$partial['id']], $ids('?filter[reason]=ITEM_UNAVAILABLE'));
        $this->assertCount(3, $ids('?filter[provider]=fake'));
        $this->assertSame([], $ids('?filter[provider]=razorpay'));
        $this->assertSame([], $ids('?filter[review]=1'));
        $this->assertCount(3, $ids('?filter[market]='.$first->market->slug));
        $this->assertCount(3, $ids('?filter[restaurant]='.$this->burgerHub->public_id));
        $this->assertCount(3, $ids('?filter[from]=2026-10-07&filter[to]=2026-10-07'));
        $this->assertSame([], $ids('?filter[from]=2026-10-08'));
        $this->refundGet('?filter[colour]=red')->assertStatus(422);
        // Exact references only: the refund, its support reference, the order number, the payment, the provider's refund.
        $this->assertSame([(string) $first->public_id], $ids('?q='.$first->public_id));
        $this->assertSame([(string) $first->public_id], $ids('?q='.$first->supportReference()));
        $this->assertSame([(string) $first->public_id], $ids('?q='.$a['order_number']));
        $this->assertSame([(string) $first->public_id], $ids('?q='.$payment['id']));
        $this->assertSame([(string) $first->public_id], $ids('?q='.$first->refresh()->provider_refund_reference));
        $this->assertSame([(string) $second->public_id], $ids('?q='.strtolower($b['order_number'])));
        $this->assertSame([], $ids('?q=Burger'));
        $this->assertSame([], $ids('?q=%25'));

        $row = collect($this->refundGet()->json('data'))->firstWhere('id', (string) $first->public_id);
        $this->assertSame(['id', 'reference', 'type', 'status', 'needs_review', 'awaits_approval', 'amount', 'approved_amount', 'currency', 'reason', 'provider', 'provider_mode', 'order', 'payment', 'customer', 'restaurant', 'market', 'requested_by', 'policy', 'requested_at', 'completed_at', 'updated_at', 'version'], array_keys($row));
        // A customer is a reference in the list: no name, no phone.
        $this->assertSame(['id'], array_keys($row['customer']));
        $this->assertSame(['required' => 1, 'awaiting_approval' => 2, 'approved' => 0, 'processing' => 0, 'failed' => 0, 'review_required' => 0, 'completed' => 1, 'cancelled' => 0], $this->refundGet('/queues')->json('queues'));
    }
}
