<?php

namespace Tests\Feature\Support;

use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RestaurantPayout;
use App\Models\RestaurantSettlement;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseLink;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — a case COORDINATES; the order, the payment, the refund and the money stay authoritative.
 *
 * The panels of a case read the linked records live and only for a viewer who may read that domain; nothing a case
 * can do changes them; a shortcut to a domain action needs that domain's own permission.
 */
class SupportDomainAuthorityTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_panels_read_each_linked_record_live_and_only_for_a_viewer_who_may_read_its_domain(): void
    {
        [, $headers, , $payment, $order] = $this->paidOrder('+919876543210', '13:00');
        $case = $this->customerCase($headers, ['category' => 'ORDER', 'subcategory' => 'ORDER_NOT_ACCEPTED', 'subject' => 'Nobody accepted my order', 'order' => $order['order_number'], 'payment' => $payment['id']]);

        // A desk agent without any domain permission: the references are there, the records' states are not.
        $this->agent();
        $panels = $this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links');
        $this->assertSame([['ORDER', false, 'PERMISSION', 'admin.orders.view'], ['PAYMENT', false, 'PERMISSION', 'admin.payments.view']],
            array_map(fn (array $p): array => [$p['type'], $p['visible'], $p['reason'], $p['required_permission']], $panels));
        $this->assertSame($order['order_number'], $panels[0]['reference']);
        $this->assertArrayNotHasKey('record', $panels[0]);
        $this->assertStringNotContainsString('WAITING_FOR_RESTAURANT', json_encode($panels));
        // …and cannot attach a record of a domain they may not read, or one that does not exist.
        $this->desk('POST', '/cases/'.$case['id'].'/links', ['type' => 'PAYMENT', 'reference' => $payment['id']])->assertForbidden()->assertJsonPath('error.code', 'linked_record_not_authorized')->assertJsonPath('error.details.required_permission', 'admin.payments.view');
        $this->desk('POST', '/cases/'.$case['id'].'/links', ['type' => 'ORDER', 'reference' => 'FOTG-20261007-ZZZZZZ'])->assertNotFound()->assertJsonPath('error.code', 'linked_record_not_found');
        $this->desk('POST', '/cases/'.$case['id'].'/links', ['type' => 'LEDGER', 'reference' => 'x'])->assertStatus(422);

        // With the orders permission the order is read from the order itself — at this moment.
        $this->agent([...self::DESK, Permission::AdminOrdersView]);
        $panels = $this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links');
        $this->assertSame([true, 'WAITING_FOR_RESTAURANT', 'NONE', 50000, 'Burger Hub'], [$panels[0]['visible'], $panels[0]['record']['status'], $panels[0]['record']['refund_status'], $panels[0]['record']['total']['amount'], $panels[0]['record']['restaurant']]);
        $this->assertFalse($panels[1]['visible']);

        // The restaurant rejects the order: the SAME case now shows the order rejected — nothing was copied into the case.
        $this->orderStaff();
        $this->step($order['order_number'], 'reject', ['reason' => 'ITEM_UNAVAILABLE'])->assertOk();
        $this->agent([...self::DESK, Permission::AdminOrdersView, Permission::AdminPaymentsView, Permission::AdminRefundsView]);
        $refund = Refund::query()->sole();
        $this->desk('POST', '/cases/'.$case['id'].'/links', ['type' => 'REFUND', 'reference' => $refund->supportReference()])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/links', ['type' => 'REFUND', 'reference' => (string) $refund->public_id])->assertCreated();
        $this->assertSame(3, SupportCaseLink::query()->count(), 'the same record is linked once');
        $panels = collect($this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links'))->keyBy('type');
        $this->assertSame(['REJECTED', 'REQUIRED'], [$panels['ORDER']['record']['status'], $panels['ORDER']['record']['refund_status']]);
        $this->assertSame(['VERIFIED', 50000, 'fake'], [$panels['PAYMENT']['record']['status'], $panels['PAYMENT']['record']['amount']['amount'], $panels['PAYMENT']['record']['provider']]);
        $this->assertSame(['REQUIRED', 50000, $order['order_number']], [$panels['REFUND']['record']['status'], $panels['REFUND']['record']['amount']['amount'], $panels['REFUND']['record']['order_number']]);
        // The customer reads the same refund in the customer's own words; an internal link kind stays internal.
        $own = collect($this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk()->json('case.links'))->keyBy('type');
        $this->assertSame(['REJECTED', 'REQUIRED', 'REQUIRED'], [$own['ORDER']['status'], $own['ORDER']['refund_status'], $own['REFUND']['status']]);
        $this->assertStringNotContainsString('provider', json_encode($own));
        // No copy of a state exists in the case tables.
        $this->assertSame([], array_values(array_intersect(['status', 'amount_minor', 'state', 'snapshot'], Schema::getColumnListing('support_case_links'))));
    }

    public function test_nothing_a_case_does_changes_the_order_the_payment_or_the_refund(): void
    {
        [, $headers, $order, $payment, $refund] = $this->rejectedPaidOrder('+919876543210');
        $case = $this->customerCase($headers, ['category' => 'REFUND', 'subcategory' => 'REFUND_NOT_RECEIVED', 'subject' => 'No refund yet', 'order' => $order['order_number'], 'refund' => $refund->supportReference(), 'payment' => $payment['id']]);
        $facts = fn (): array => [
            Order::query()->sole()->only(['status', 'payment_state', 'refund_status', 'total_minor', 'version', 'updated_at']),
            PaymentAttempt::query()->sole()->only(['status', 'fulfillment_status', 'amount_minor', 'updated_at']),
            Refund::query()->sole()->only(['status', 'version', 'approved_amount_minor', 'provider_refund_reference', 'updated_at']),
        ];
        $before = $facts();
        $audits = AuditEvent::query()->whereNotIn('action', AuditEvent::query()->where('action', 'like', 'support.%')->select('action'))->count();

        $this->agent();
        $v = fn (): int => $this->version($case['id']);
        // A case may not claim what the refund does not say.
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'REFUND_COMPLETED', 'note' => 'It should be there by now.', 'expected_version' => $v()])->assertStatus(409)->assertJsonPath('error.code', 'resolution_not_supported_by_record');
        // Every action a case has: reply, note, assign, priority, wait, escalate, resolve, close, reopen, cancel.
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'We are checking with finance.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'Refund still REQUIRED.', 'visibility' => 'INTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/priority', ['priority' => 'URGENT', 'reason' => 'Money is owed.', 'expected_version' => $v()])->assertOk();
        $this->desk('POST', '/cases/'.$case['id'].'/wait', ['for' => 'FINANCE', 'expected_version' => $v()])->assertOk();
        $this->desk('POST', '/cases/'.$case['id'].'/escalate', ['reason' => 'FINANCIAL_RISK', 'level' => 'L2', 'note' => 'Please approve the refund.', 'expected_version' => $v()])->assertOk();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Told the customer what to expect.', 'expected_version' => $v()])->assertOk();
        $this->desk('POST', '/cases/'.$case['id'].'/close', ['expected_version' => $v()])->assertOk();
        $this->desk('POST', '/cases/'.$case['id'].'/reopen', ['reason' => 'Still open on the money side.', 'expected_version' => $v()])->assertOk();
        $this->assertEquals($before, $facts(), 'the order, the payment and the refund are exactly as they were');
        $this->assertSame($audits, AuditEvent::query()->where('action', 'not like', 'support.%')->count(), 'no audit record of another domain was written');

        // The refund is completed in ITS domain: the case does not close by itself — a person resolves a case.
        $this->finance();
        $this->approveAndExecute($refund);
        $this->providerSendsRefund('refund.processed', $this->providerSettlesRefund($refund->refresh()))->assertOk();
        $this->assertSame(RefundStatus::Refunded, $refund->refresh()->status);
        $this->assertSame('REOPENED', $this->caseModel($case['id'])->status->value);
        $this->assertSame('REFUNDED', collect($this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->json('case.links'))->firstWhere('type', 'REFUND')['status']);
        // Now the case may say so — and saying so refunds nothing a second time.
        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'REFUND_COMPLETED', 'note' => 'The provider confirmed the refund.', 'expected_version' => $v()])->assertOk()->assertJsonPath('case.resolution.code', 'REFUND_COMPLETED');
        $this->assertSame([1, RefundStatus::Refunded], [Refund::query()->count(), Refund::query()->sole()->status]);

        // The answer says what a case cannot do, and no route of the API offers it.
        $detail = $this->desk('GET', '/cases/'.$case['id'])->assertOk()->json('case');
        $this->assertSame(['mark_payment_verified' => 'NOT_PROVIDED', 'mark_refund_completed' => 'NOT_PROVIDED', 'edit_settlement' => 'NOT_PROVIDED', 'edit_payout' => 'NOT_PROVIDED'], $detail['domain_authority']);
        $support = array_values(array_filter(array_map(fn ($r): string => implode('|', $r->methods()).' '.$r->uri(), Route::getRoutes()->getRoutes()), fn (string $r): bool => str_contains($r, '/support') && ! str_contains($r, '/analytics/')));   // Module 38: a read-only report about cases is not a route of the case domain
        $this->assertCount(43, $support);
        foreach ($support as $route) {
            $this->assertDoesNotMatchRegularExpression('#PUT|PATCH|DELETE#', explode(' ', $route)[0], $route);
            $this->assertDoesNotMatchRegularExpression('#verify|verified|refund|payout|settle|mark|paid|capture|status|orders|ledger|balance#i', explode(' ', $route)[1], $route);
        }
    }

    public function test_a_shortcut_runs_the_domains_own_action_and_needs_the_domains_own_permission(): void
    {
        $this->seed(RoleSeeder::class);
        [, $headers, $order, $payment, $refund] = $this->rejectedPaidOrder('+919876543210');
        $case = $this->customerCase($headers, ['category' => 'PAYMENT', 'subject' => 'Was I charged twice?', 'payment' => $payment['id'], 'refund' => $refund->supportReference()]);
        $other = $this->customerCase($headers, ['subject' => 'Another case']);
        $attempt = PaymentAttempt::query()->sole();

        // The seeded support agent reads payments and refunds, and may reconcile neither.
        $this->seededAgent('SUPPORT_ADMIN');
        $panels = collect($this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links'))->keyBy('type');
        $this->assertSame([['action' => 'PAYMENT_RECONCILE', 'allowed' => false, 'required_permission' => 'admin.payments.reconcile']], $panels['PAYMENT']['shortcuts']);
        $this->assertSame([['action' => 'REFUND_RECONCILE', 'allowed' => false, 'required_permission' => 'admin.refunds.reconcile']], $panels['REFUND']['shortcuts']);
        $link = fn (string $type): string => $panels[$type]['id'];
        foreach ([['PAYMENT_RECONCILE', 'PAYMENT', 'admin.payments.reconcile'], ['REFUND_RECONCILE', 'REFUND', 'admin.refunds.reconcile']] as [$action, $type, $permission]) {
            $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => $action, 'link' => $link($type), 'reason' => 'The customer asked.'])
                ->assertForbidden()->assertJsonPath('error.code', 'domain_permission_required')->assertJsonPath('error.details.required_permission', $permission);
        }
        $this->assertSame(0, AuditEvent::query()->where('action', 'payment.reconciled')->count());
        $this->assertSame(0, SupportCaseEvent::query()->where('type', SupportCaseEvent::DOMAIN_ACTION)->count());

        // Finance holds the payment's own permission: the PAYMENT domain reconciles, writes ITS audit record, and
        // the case only notes that somebody asked and what the domain answered.
        $finance = $this->seededAgent('FINANCE_ADMIN');
        $allowed = collect($this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links'))->keyBy('type');
        $this->assertTrue($allowed['PAYMENT']['shortcuts'][0]['allowed']);
        $result = $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => 'PAYMENT_RECONCILE', 'link' => $link('PAYMENT'), 'reason' => 'Checking the capture with the provider.'])->assertOk()->json();
        $this->assertSame(['PAYMENT_RECONCILE', 'VERIFIED', 'VERIFIED', false], [$result['result']['action'], $result['result']['before'], $result['result']['after'], $result['result']['changed']]);
        $audit = AuditEvent::query()->where('action', 'payment.reconciled')->sole();
        $this->assertSame(['payment_attempts', (string) $attempt->public_id, (string) $finance->public_id, 'Checking the capture with the provider.'], [$audit->target_type, $audit->target_public_id, $audit->actor_public_id, $audit->reason]);
        $event = SupportCaseEvent::query()->where('type', SupportCaseEvent::DOMAIN_ACTION)->sole();
        $this->assertSame(['INTERNAL', 'PAYMENT_RECONCILE', 'VERIFIED'], [$event->audience, $event->detail['action'], $event->detail['after']]);
        $this->assertSame(['VERIFIED', 'OPEN'], [$attempt->refresh()->status->value, $this->caseModel($case['id'])->status->value]);

        // What a shortcut cannot be: an action on a record of another case, an action that does not fit the record,
        // an action without a reason, or anything that sets a state.
        $this->desk('POST', '/cases/'.$other['id'].'/domain-actions', ['action' => 'PAYMENT_RECONCILE', 'link' => $link('PAYMENT'), 'reason' => 'Wrong case'])->assertNotFound()->assertJsonPath('error.code', 'case_link_not_found');
        $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => 'REFUND_RECONCILE', 'link' => $link('PAYMENT'), 'reason' => 'Wrong kind'])->assertStatus(422);
        $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => 'PAYMENT_RECONCILE', 'link' => $link('PAYMENT')])->assertStatus(422);
        foreach (['MARK_PAYMENT_VERIFIED', 'MARK_REFUNDED', 'REFUND_EXECUTE', 'PAYOUT_PAY', 'ORDER_CANCEL'] as $unknown) {
            $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => $unknown, 'link' => $link('PAYMENT'), 'reason' => 'Trying'])->assertStatus(422);
        }
        $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => 'PAYMENT_RECONCILE', 'link' => $link('PAYMENT'), 'reason' => 'Trying', 'status' => 'VERIFIED'])->assertStatus(422);
        $this->assertSame([$refund->refresh()->status, $order['order_number']], [RefundStatus::Required, Order::query()->sole()->order_number]);
    }

    public function test_a_restaurants_money_case_shows_finance_the_settlement_and_support_only_that_it_exists(): void
    {
        $this->seed(RoleSeeder::class);
        $this->commissionPlan(1000);
        $this->completedOrderWith();
        $this->nextWeek();
        $this->fakePayouts();
        $this->payoutProfile();
        $this->asFinance();
        $this->financePost('/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id])->assertCreated();
        $settlement = $this->settlementOf();
        $this->financePost('/settlements/'.$settlement->public_id.'/approve')->assertOk();
        $this->financePost('/settlements/'.$settlement->public_id.'/payouts', ['confirm' => true])->assertCreated();
        $payout = RestaurantPayout::query()->sole();
        $money = fn (): array => [RestaurantSettlement::query()->sole()->only(['status', 'net_payable_minor', 'paid_minor', 'version']), RestaurantPayout::query()->sole()->only(['status', 'amount_minor', 'version'])];
        $before = $money();

        $this->asActor($this->member($this->organization, 'OWNER'));
        $case = $this->restaurantCase($this->burgerHub, ['category' => 'PAYOUT', 'subcategory' => 'PAYOUT_PENDING', 'subject' => 'When is the payout coming?', 'message' => 'It says processing.', 'settlement' => $settlement->reference, 'payout' => $payout->supportReference()]);

        // The seeded support agent sees the case (it is a restaurant's question) and none of the money.
        $this->seededAgent('SUPPORT_ADMIN');
        $panels = $this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links');
        $this->assertSame([['SETTLEMENT', false, 'admin.settlements.view'], ['PAYOUT', false, 'admin.finance.payouts.view']], array_map(fn (array $p): array => [$p['type'], $p['visible'], $p['required_permission']], $panels));
        $this->assertStringNotContainsString((string) $settlement->net_payable_minor, json_encode($panels));

        // Finance reads the settlement line by line and the payout — from the finance records, now.
        $this->seededAgent('FINANCE_ADMIN');
        $this->assertSame('FINANCE', $this->desk('GET', '/cases/'.$case['id'])->assertOk()->json('case.assigned.team'));
        $panels = collect($this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links'))->keyBy('type');
        $s = $panels['SETTLEMENT']['record'];
        $this->assertSame([$settlement->reference, 'PAYMENT_PENDING', 50000, 5000, 45000, 0], [$s['reference'], $s['status'], $s['gross_sales']['amount'], $s['commission']['amount'], $s['net_payable']['amount'], $s['paid']['amount']]);
        $this->assertSame(['PROCESSING', 45000], [$panels['PAYOUT']['record']['status'], $panels['PAYOUT']['record']['amount']['amount']]);
        $this->assertSame([], $panels['PAYOUT']['shortcuts'], 'a case offers no payout action at all');

        // Finance works and resolves the case; the settlement and the payout are untouched by it.
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'The payout is with the bank; it usually arrives within the bank\'s own time.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Explained the payout state.', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->assertEquals($before, $money());
        foreach (['PAYOUT_PAY', 'SETTLEMENT_APPROVE', 'PAYOUT_RETRY'] as $nothing) {
            $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => $nothing, 'link' => $panels['PAYOUT']['id'], 'reason' => 'Trying'])->assertStatus(422);
        }
    }
}
