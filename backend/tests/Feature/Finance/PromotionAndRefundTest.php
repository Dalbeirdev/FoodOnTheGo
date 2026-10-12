<?php

namespace Tests\Feature\Finance;

use App\Enums\AllocationStatus;
use App\Enums\DisputeStatus;
use App\Enums\LedgerAccountCode;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\FinancialAdjustment;
use App\Models\FinancialHold;
use App\Models\LedgerTransaction;
use App\Models\PaymentDispute;
use App\Models\RestaurantPayableItem;
use App\Services\Finance\FinancialPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — who pays for a discount, and who bears a refund.
 *
 * Both are POLICY: the promotion's own funding source, and the refund-liability policy per reason — which has no
 * approved value, so by default a person decides each refund. The 10 % commission is a TEST FIXTURE.
 */
class PromotionAndRefundTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    public function test_a_platform_funded_discount_does_not_lower_the_restaurants_earning(): void
    {
        $this->promotion(['code' => 'PLATFORM100', 'funding_source' => 'PLATFORM']);
        $order = $this->completedOrderWith(['promotion_code' => 'PLATFORM100']);

        $a = $this->allocationOf($order);
        // ₹500 of burgers, ₹100 off paid by the platform: the customer pays ₹400, the restaurant earns as if nothing was off.
        $this->assertSame([40000, 50000, 10000, 'PLATFORM', 'ORDER_CREATED', 0, 10000, 50000, 5000, 45000, -5000],
            [$a->customer_total_minor, $a->merchandise_gross_minor, $a->discount_minor, $a->promotion_funding, $a->promotion_funding_basis, $a->restaurant_discount_minor, $a->platform_discount_minor, $a->commission_base_minor, $a->commission_minor, $a->restaurant_net_minor, $a->platform_net_minor]);
        $this->assertSame(['D PAYMENT_CLEARING 40000', 'D PROMOTION_EXPENSE 10000', 'C RESTAURANT_PAYABLE 45000', 'C PLATFORM_COMMISSION_REVENUE 5000'], $this->linesOf('order:'.$order->public_id.':recognized'));
        $this->assertSame([45000, 10000, 0], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::PromotionExpense), $this->platformBalance(LedgerAccountCode::PaymentClearing)]);
        $this->assertLedgerBalanced();
    }

    public function test_a_restaurant_funded_discount_lowers_the_restaurants_earning_and_the_commission_base_by_the_plans_policy(): void
    {
        $this->promotion(['code' => 'OURTREAT', 'funding_source' => 'RESTAURANT']);
        $order = $this->completedOrderWith(['promotion_code' => 'OURTREAT']);

        $a = $this->allocationOf($order);
        // The plan's base is "merchandise net of the restaurant's discount": 10 % of ₹400.
        $this->assertSame([40000, 'RESTAURANT', 10000, 0, 40000, 4000, 36000, 4000], [$a->customer_total_minor, $a->promotion_funding, $a->restaurant_discount_minor, $a->platform_discount_minor, $a->commission_base_minor, $a->commission_minor, $a->restaurant_net_minor, $a->platform_net_minor]);
        $this->assertSame(['D PAYMENT_CLEARING 40000', 'C RESTAURANT_PAYABLE 36000', 'C PLATFORM_COMMISSION_REVENUE 4000'], $this->linesOf('order:'.$order->public_id.':recognized'));
        $this->assertSame(0, $this->platformBalance(LedgerAccountCode::PromotionExpense));
        $earning = $this->earningOf($order);
        $this->assertSame([36000, 50000, 10000, 4000], [$earning->amount_minor, $earning->gross_minor, $earning->restaurant_discount_minor, $earning->commission_minor]);
    }

    public function test_a_shared_discount_is_split_by_the_configured_share_and_without_one_the_order_waits(): void
    {
        $this->promotion(['code' => 'SHARED100', 'funding_source' => 'SHARED']);
        // No split is defined for SHARED: nothing is guessed — the order waits for a person.
        $waiting = $this->completedOrderWith(['promotion_code' => 'SHARED100']);
        $this->assertSame([AllocationStatus::ReviewRequired, 'SHARED_PROMOTION_SPLIT_UNDEFINED', 'SHARED'], [$this->allocationOf($waiting)->status, $this->allocationOf($waiting)->review_reason, $this->allocationOf($waiting)->promotion_funding]);
        $this->assertSame(0, RestaurantPayableItem::query()->count());

        // The decision states the restaurant's part of THIS order's discount; it cannot exceed the discount.
        $this->asFinance();
        $url = '/allocations/'.$this->allocationOf($waiting)->public_id.'/decide';
        $this->financePost($url, ['rate_bps' => 1000, 'base_policy' => 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 'reason' => 'TEST FIXTURE: split agreed'])->assertStatus(422)->assertJsonPath('error.code', 'discount_split_required');
        $this->financePost($url, ['rate_bps' => 1000, 'base_policy' => 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 'restaurant_discount_minor' => 10001, 'reason' => 'TEST FIXTURE: split agreed'])->assertStatus(422);
        $this->financePost($url, ['rate_bps' => 1000, 'base_policy' => 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 'restaurant_discount_minor' => 2500, 'reason' => 'TEST FIXTURE: split agreed'])->assertOk()->assertJsonPath('allocation.status', 'RECOGNIZED');
        $decided = $this->allocationOf($waiting);
        $this->assertSame([2500, 7500, 47500, 4750, 42750], [$decided->restaurant_discount_minor, $decided->platform_discount_minor, $decided->commission_base_minor, $decided->commission_minor, $decided->restaurant_net_minor]);

        // With a configured share (40 % restaurant — a TEST FIXTURE) a shared discount is split at once.
        config(['finance.promotions.shared_restaurant_share_bps' => 4000]);
        $split = $this->completedOrderWith(['promotion_code' => 'SHARED100']);
        $a = $this->allocationOf($split);
        $this->assertSame([AllocationStatus::Recognized, 4000, 6000, 46000, 4600, 41400], [$a->status, $a->restaurant_discount_minor, $a->platform_discount_minor, $a->commission_base_minor, $a->commission_minor, $a->restaurant_net_minor]);
        $this->assertLedgerBalanced();
    }

    public function test_the_refund_of_an_order_that_was_never_completed_returns_the_customers_money_and_touches_no_earning(): void
    {
        [, , $order, $payment, $refund] = $this->rejectedPaidOrder($this->nextPhone());
        $this->assertSame(AllocationStatus::Void, $this->allocationOf($order['order_number'])->status);
        $this->assertSame(50000, $this->platformBalance(LedgerAccountCode::PaymentClearing), 'the money waits in clearing until it goes back');

        $this->asFinance();
        $this->approveAndExecute($refund);
        $this->providerSendsRefund('refund.processed', $this->providerSettlesRefund($refund))->assertOk();

        $this->assertSame(['D PAYMENT_CLEARING 50000', 'C PROVIDER_CLEARING 50000'], $this->linesOf('refund:'.$refund->public_id.':completed'));
        $this->assertSame([0, 0, 0, 0], [$this->platformBalance(LedgerAccountCode::PaymentClearing), $this->providerClearing(), $this->payableBalance(), $this->platformBalance(LedgerAccountCode::RefundClearing)]);
        // Nobody had earned anything: there is no liability to decide and no payable line.
        $this->assertSame([0, 0], [FinancialAdjustment::query()->count(), RestaurantPayableItem::query()->count()]);
        // The webhook again, the reconciliation, the sweep: one posting.
        $this->providerSendsRefund('refund.processed', $this->fakeProvider()->refundByReceipt((string) $refund->refresh()->provider_receipt))->assertOk();
        app(FinancialPostingService::class)->sweep();
        $this->assertSame(1, LedgerTransaction::query()->where('type', 'REFUND_COMPLETED')->count());
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
        $this->assertLedgerBalanced();
    }

    public function test_who_bears_the_refund_of_a_completed_order_is_decided_by_a_person_unless_a_policy_exists(): void
    {
        [$order, $refund] = $this->refundedCompletedOrder();

        // The refund went back to the customer; nobody was charged for it yet.
        $this->assertSame(['D REFUND_CLEARING 50000', 'C PROVIDER_CLEARING 50000'], $this->linesOf('refund:'.$refund->public_id.':completed'));
        $this->assertSame([45000, 50000, 5000], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::RefundClearing), $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue)]);
        $adjustment = FinancialAdjustment::query()->firstOrFail();
        $this->assertSame(['REFUND_LIABILITY', 'PENDING_DECISION', 'UNASSIGNED', 50000, null, $order->order_number], [$adjustment->kind, $adjustment->status, $adjustment->liability, $adjustment->amount_minor, $adjustment->restaurant_share_minor, $adjustment->order_number]);
        // The order's own allocation is history: it did not change.
        $this->assertSame([AllocationStatus::Recognized, 45000, 5000], [$this->allocationOf($order)->status, $this->allocationOf($order)->restaurant_net_minor, $this->allocationOf($order)->commission_minor]);

        $url = '/adjustments/'.$adjustment->public_id;
        $shown = $this->financeGet($url)->assertOk()->json('adjustment');
        $this->assertSame(['REFUND_LIABILITY', 'PENDING_DECISION', ['type' => 'REFUND', 'id' => (string) $refund->public_id], true], [$shown['kind'], $shown['status'], $shown['source'], $shown['can']['decide']]);
        // The decision is the restaurant's share, explicitly — never more than the refund, never a typed account.
        $this->financePost($url.'/decide', ['restaurant_share_minor' => 50001, 'reason' => 'TEST FIXTURE: restaurant error'])->assertStatus(422)->assertJsonPath('error.code', 'share_invalid');
        $this->financePost($url.'/decide', ['restaurant_share_minor' => 30000, 'reason' => 'TEST FIXTURE: restaurant error', 'ledger_account' => 'PLATFORM_FEE_REVENUE', 'amount_minor' => 1])->assertStatus(422)->assertJsonValidationErrors(['ledger_account', 'amount_minor'], 'error.details.fields');
        $this->financePost($url.'/decide', ['restaurant_share_minor' => 30000])->assertStatus(422)->assertJsonValidationErrors('reason', 'error.details.fields');
        $decided = $this->financePost($url.'/decide', ['restaurant_share_minor' => 30000, 'reason' => 'TEST FIXTURE: restaurant error, goodwill for the rest'])->assertOk()->json('adjustment');
        $this->assertSame(['POSTED', 'SHARED', 30000, 20000, 'ADMIN'], [$decided['status'], $decided['liability'], $decided['restaurant_share']['amount'], $decided['platform_share']['amount'], $decided['policy_source']]);

        $this->assertSame(['D RESTAURANT_PAYABLE 30000', 'D PLATFORM_REFUND_EXPENSE 20000', 'C REFUND_CLEARING 50000'], $this->linesOf('refund:'.$refund->public_id.':liability'));
        $this->assertSame([15000, 0, 20000, 5000], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::RefundClearing), $this->platformBalance(LedgerAccountCode::PlatformRefundExpense), $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue)]);
        // The restaurant's side is a NEW payable line; the earning line is what it was.
        $line = RestaurantPayableItem::query()->where('type', 'REFUND_ADJUSTMENT')->firstOrFail();
        $this->assertSame([-30000, $order->order_number, 'AVAILABLE'], [$line->amount_minor, $line->order_number, $line->status]);
        $this->assertSame(45000, $this->earningOf($order)->amount_minor);
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.adjustment_posted')->where('reason', 'TEST FIXTURE: restaurant error, goodwill for the rest')->count());
        // Decided once.
        $this->financePost($url.'/decide', ['restaurant_share_minor' => 0, 'reason' => 'TEST FIXTURE: a second decision'])->assertOk()->assertJsonPath('adjustment.restaurant_share.amount', 30000);
        $this->assertSame(1, LedgerTransaction::query()->where('type', 'REFUND_LIABILITY')->count());
        // Deciding needs admin.finance.adjustments.create.
        $this->adminFor([Permission::AdminFinanceView]);
        $this->financeGet($url)->assertOk()->assertJsonPath('adjustment.can.decide', false);
        $this->financePost($url.'/decide', ['restaurant_share_minor' => 0, 'reason' => 'TEST FIXTURE: without the permission'])->assertForbidden();
        $this->assertLedgerBalanced();
    }

    public function test_a_configured_liability_policy_assigns_a_refund_by_its_reason(): void
    {
        // TEST FIXTURES for the three policies: none of them is an approved rule.
        config(['finance.refund_liability.by_reason' => ['ITEM_UNAVAILABLE' => ['RESTAURANT', null], 'ORDER_CANNOT_BE_FULFILLED' => ['PLATFORM', null], 'RESTAURANT_CANCELLATION' => ['SHARED', 2500]]]);

        // RESTAURANT: the whole refunded amount is the restaurant's — a partial refund of ₹200.
        [$first, $r1] = $this->refundedCompletedOrder(20000, 'ITEM_UNAVAILABLE');
        $a1 = FinancialAdjustment::query()->where('refund_id', $r1->id)->firstOrFail();
        $this->assertSame(['POSTED', 'RESTAURANT', 20000, 0, 'CONFIG'], [$a1->status, $a1->liability, $a1->restaurant_share_minor, $a1->platform_share_minor, $a1->policy_source]);
        $this->assertSame(25000, $this->payableBalance(), '₹450 earned − ₹200 borne');

        // PLATFORM: the restaurant's payable is not touched.
        [, $r2] = $this->refundedCompletedOrder(null, 'ORDER_CANNOT_BE_FULFILLED');
        $a2 = FinancialAdjustment::query()->where('refund_id', $r2->id)->firstOrFail();
        $this->assertSame(['POSTED', 'PLATFORM', 0, 50000], [$a2->status, $a2->liability, $a2->restaurant_share_minor, $a2->platform_share_minor]);
        $this->assertSame(25000 + 45000, $this->payableBalance());
        $this->assertSame(50000, $this->platformBalance(LedgerAccountCode::PlatformRefundExpense));
        $this->assertSame(1, RestaurantPayableItem::query()->where('type', 'REFUND_ADJUSTMENT')->count(), 'no line for a refund the platform bears');

        // SHARED 25 % restaurant: ₹125 of a ₹500 refund.
        [, $r3] = $this->refundedCompletedOrder(null, 'RESTAURANT_CANCELLATION');
        $a3 = FinancialAdjustment::query()->where('refund_id', $r3->id)->firstOrFail();
        $this->assertSame(['POSTED', 'SHARED', 12500, 37500], [$a3->status, $a3->liability, $a3->restaurant_share_minor, $a3->platform_share_minor]);
        $this->assertSame(70000 + 45000 - 12500, $this->payableBalance());
        // Commission is never given back automatically: three orders, three commissions.
        $this->assertSame(15000, $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue));
        $this->assertSame(0, $this->platformBalance(LedgerAccountCode::RefundClearing));

        $this->asFinance();
        $impact = $this->financeGet('/reports/customer-returns')->assertOk()->json('rows.0');
        $this->assertSame([3, 120000, 32500, 87500, 0], [$impact['refund_count'], $impact['refund_amount']['amount'], $impact['restaurant_funded']['amount'], $impact['platform_funded']['amount'], $impact['awaiting_decision']['amount']]);
        $this->assertLedgerBalanced();
    }

    public function test_an_open_refund_holds_the_orders_unsettled_earning_until_it_is_over(): void
    {
        [, , $order] = $this->completedPaidOrder($this->nextPhone());
        $this->asFinance();
        $this->askRefund($order->order_number, ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'TEST FIXTURE: complaint'])->assertCreated();
        $refund = $this->refundOf($order->order_number);

        $hold = FinancialHold::query()->firstOrFail();
        $this->assertSame(['ITEM', 'REFUND_PENDING', 'ACTIVE', 'SYSTEM', 'refund:'.$refund->public_id, $this->earningOf($order)->id], [$hold->scope, $hold->reason->value, $hold->status, $hold->source, $hold->source_key, $hold->payable_item_id]);
        // Held is still the restaurant's — it is only not settled. A settlement run leaves the line out.
        $this->assertSame(45000, $this->payableBalance());
        $this->nextWeek();
        $this->assertSame('NOTHING_TO_SETTLE', $this->generateSettlements()['results'][0]['outcome'] ?? 'NOTHING_TO_SETTLE');
        $this->assertSame(0, RestaurantPayableItem::query()->where('status', 'SETTLED')->count());

        // The refund is turned down: the hold ends and the line is settleable again.
        $this->refundPost($refund, 'reject', ['reason' => 'TEST FIXTURE: not justified'])->assertOk()->assertJsonPath('refund.status', 'CANCELLED');
        $this->assertSame(['RELEASED', 'The refund was cancelled.'], [$hold->refresh()->status, $hold->release_reason]);
        $this->assertSame('SETTLEMENT', $this->generateSettlements()['results'][0]['outcome']);

        // The policy is a switch: without it an open refund holds nothing.
        config(['finance.holds.refund_pending' => false]);
        [, , $second] = $this->completedPaidOrder($this->nextPhone());
        $this->asFinance();
        $this->askRefund($second->order_number, ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'TEST FIXTURE: complaint'])->assertCreated();
        $this->assertSame(1, FinancialHold::query()->count());
    }

    public function test_a_lost_dispute_is_posted_and_never_charged_to_the_restaurant_without_a_decision(): void
    {
        [, , $order, $payment] = $this->completedPaidOrder($this->nextPhone());
        $paymentId = $order->payment_public_id;
        $fake = $this->fakeProvider();
        $attempt = $this->attempt((string) $paymentId);
        $dispute = $fake->openDispute((string) $attempt->provider_payment_reference);
        $this->providerSendsDispute('payment.dispute.created', $dispute)->assertOk();

        // While it is open the unsettled earning is held.
        $hold = FinancialHold::query()->firstOrFail();
        $this->assertSame(['DISPUTE', 'ACTIVE'], [$hold->reason->value, $hold->status]);

        $this->providerSendsDispute('payment.dispute.lost', $fake->settleDispute($dispute->reference, DisputeStatus::Lost))->assertOk();
        $row = PaymentDispute::query()->firstOrFail();
        $this->assertSame(['D DISPUTE_CLEARING 50000', 'C PROVIDER_CLEARING 50000'], $this->linesOf('dispute:'.$row->public_id.':lost'));
        $this->assertSame('RELEASED', $hold->refresh()->status);
        // The provider took the money; who bears it is a decision — the restaurant's payable is untouched.
        $this->assertSame([45000, 50000, 0], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::DisputeClearing), $this->providerClearing()]);
        $adjustment = FinancialAdjustment::query()->where('kind', 'DISPUTE_LIABILITY')->firstOrFail();
        $this->assertSame(['PENDING_DECISION', 'UNASSIGNED', 50000], [$adjustment->status, $adjustment->liability, $adjustment->amount_minor]);

        $this->asFinance();
        $this->financePost('/adjustments/'.$adjustment->public_id.'/decide', ['restaurant_share_minor' => 0, 'reason' => 'TEST FIXTURE: the platform bears this dispute'])->assertOk()->assertJsonPath('adjustment.liability', 'PLATFORM');
        $this->assertSame(['D PLATFORM_DISPUTE_EXPENSE 50000', 'C DISPUTE_CLEARING 50000'], $this->linesOf('dispute:'.$row->public_id.':liability'));
        $this->assertSame([45000, 0], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::DisputeClearing)]);
        $this->assertLedgerBalanced();
    }
}
