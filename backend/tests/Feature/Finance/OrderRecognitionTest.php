<?php

namespace Tests\Feature\Finance;

use App\Enums\AllocationStatus;
use App\Enums\LedgerAccountCode;
use App\Models\LedgerTransaction;
use App\Models\OrderFinancialAllocation;
use App\Models\RestaurantPayableItem;
use App\Services\Finance\FinancialPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — a verified payment, an order and its completion in the ledger.
 *
 * The default test order is two Large burgers = ₹500 (50000 minor units). The 10 % commission is a TEST FIXTURE.
 */
class OrderRecognitionTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_verified_payment_is_held_in_clearing_and_the_order_is_allocated_but_nothing_is_earned_before_completion(): void
    {
        $this->commissionPlan(1000);
        [, , , $payment, $order] = $this->paidOrder();

        // PAYMENT → LEDGER: the provider holds the customer's money for the platform; it is nobody's revenue yet.
        $this->assertSame(['D PROVIDER_CLEARING 50000', 'C PAYMENT_CLEARING 50000'], $this->linesOf('payment:'.$payment['id'].':verified'));
        $this->assertSame(50000, $this->providerClearing());
        $this->assertSame(50000, $this->platformBalance(LedgerAccountCode::PaymentClearing));

        // ORDER → ALLOCATION: frozen with the terms of the moment; PENDING — not an earning.
        $allocation = $this->allocationOf($order['order_number']);
        $this->assertSame(AllocationStatus::Pending, $allocation->status);
        $this->assertSame([50000, 50000, 0, 0, 0, 1000, 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 50000, 5000, 45000, 5000],
            [$allocation->customer_total_minor, $allocation->merchandise_gross_minor, $allocation->discount_minor, $allocation->tax_minor, $allocation->fee_minor, $allocation->commission_rate_bps,
                $allocation->commission_base_policy->value, $allocation->commission_base_minor, $allocation->commission_minor, $allocation->restaurant_net_minor, $allocation->platform_net_minor]);
        $this->assertSame(0, RestaurantPayableItem::query()->count(), 'an order that is not completed is not an earning');
        $this->assertSame(0, $this->payableBalance());
        $this->assertSame(0, $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue));
        $this->assertLedgerBalanced();
    }

    public function test_completion_recognizes_the_earning_and_the_commission_in_one_balanced_transaction(): void
    {
        $this->commissionPlan(1000);
        [, , $order] = $this->completedPaidOrder();

        $allocation = $this->allocationOf($order);
        $this->assertSame(AllocationStatus::Recognized, $allocation->status);
        $this->assertSame(['D PAYMENT_CLEARING 50000', 'C RESTAURANT_PAYABLE 45000', 'C PLATFORM_COMMISSION_REVENUE 5000'], $this->linesOf('order:'.$order->public_id.':recognized'));
        // ₹500 gross, 10 % TEST FIXTURE commission = ₹50, the restaurant's earning = ₹450.
        $this->assertSame(45000, $this->payableBalance());
        $this->assertSame(5000, $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue));
        $this->assertSame(0, $this->platformBalance(LedgerAccountCode::PaymentClearing), 'the customer\'s money left clearing');
        $this->assertSame(50000, $this->providerClearing(), 'the provider still holds it');

        $earning = $this->earningOf($order);
        $this->assertSame([45000, 50000, 0, 5000, 'AVAILABLE', null], [$earning->amount_minor, $earning->gross_minor, $earning->restaurant_discount_minor, $earning->commission_minor, $earning->status, $earning->settlement_id]);
        $this->assertSame((int) $allocation->ledger_transaction_id, (int) $earning->ledger_transaction_id);
        $this->assertLedgerBalanced();

        // The same events again — and the sweep on top — post nothing twice.
        $before = LedgerTransaction::query()->count();
        app(FinancialPostingService::class)->orderChanged($order->refresh());
        app(FinancialPostingService::class)->sweep();
        $this->assertSame($before, LedgerTransaction::query()->count());
        $this->assertSame(1, RestaurantPayableItem::query()->count());
        $this->assertSame(1, OrderFinancialAllocation::query()->count());
    }
}
