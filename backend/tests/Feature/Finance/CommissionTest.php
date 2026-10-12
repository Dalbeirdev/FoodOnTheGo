<?php

namespace Tests\Feature\Finance;

use App\Enums\AllocationStatus;
use App\Enums\CommissionBasePolicy;
use App\Enums\LedgerAccountCode;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\CommissionPlan;
use App\Models\RestaurantPayableItem;
use App\Services\Finance\CommissionCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — commission: configuration, never code.
 *
 * EVERY RATE IN THIS FILE IS A TEST FIXTURE. The specification's example — ₹1,000 gross, 10 % → ₹100 — is a
 * development test; nothing here is FoodOnTheGo's commission.
 */
class CommissionTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_specifications_example_a_thousand_rupees_at_a_ten_percent_test_fixture(): void
    {
        $this->commissionPlan(1000);
        // Four Large burgers = ₹1,000 (100000 minor units).
        $order = $this->completedOrderWith([], 4);

        $allocation = $this->allocationOf($order);
        $this->assertSame([100000, 100000, 1000, 10000, 90000], [$allocation->merchandise_gross_minor, $allocation->commission_base_minor, $allocation->commission_rate_bps, $allocation->commission_minor, $allocation->restaurant_net_minor]);
        $this->assertSame(['PLAN', 'MARKET', CommissionPlan::query()->firstOrFail()->public_id], [$allocation->commission_source, $allocation->commission_scope, $allocation->commission_plan_public_id]);
        $this->assertSame(90000, $this->payableBalance());
        $this->assertSame(10000, $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue));
    }

    public function test_commission_is_exact_integer_arithmetic_rounded_half_up_and_follows_the_plans_base_policy(): void
    {
        // rate × base, half a minor unit rounds up — no float is involved anywhere.
        foreach ([[10000, 1000, 1000], [9999, 1250, 1250], [333, 1500, 50], [1, 5000, 1], [1, 4999, 0], [50000, 1, 5], [49999, 1, 5], [44999, 1, 4], [0, 1000, 0], [123456789, 9999, 123444443]] as [$base, $bps, $expected]) {
            $this->assertSame($expected, CommissionCalculationService::percentage($base, $bps), "{$base} at {$bps} bps");
        }
        // The base is explicit: ₹1,000 of merchandise, ₹100 paid for by the restaurant, ₹50 by the platform.
        $service = app(CommissionCalculationService::class);
        foreach ([['MERCHANDISE_GROSS', 100000, 12500], ['MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 90000, 11250], ['MERCHANDISE_NET_OF_ALL_DISCOUNTS', 85000, 10625]] as [$policy, $base, $amount]) {
            $this->assertSame(['base_minor' => $base, 'rate_bps' => 1250, 'amount_minor' => $amount, 'base_policy' => $policy], $service->calculate(1250, CommissionBasePolicy::from($policy), 100000, 10000, 5000));
        }
        // Tax added to the order and customer fees are never in the base: no policy includes them.
        $this->taxRule();
        $this->feeRule();
        $this->commissionPlan(1000, 'MERCHANDISE_GROSS');
        $order = $this->completedOrderWith();
        $allocation = $this->allocationOf($order);
        $this->assertGreaterThan(0, $allocation->tax_minor + $allocation->fee_minor);
        $this->assertSame([50000, 5000], [$allocation->commission_base_minor, $allocation->commission_minor]);
        $this->assertSame($allocation->customer_total_minor, $allocation->merchandise_gross_minor + $allocation->tax_minor + $allocation->fee_minor);
        // The tax is held (its owner is not decided), the customer fee is the platform's, the restaurant gets neither.
        $this->assertSame(['UNASSIGNED', $allocation->tax_minor, 0, 'PLATFORM', $allocation->fee_minor, 0], [$allocation->tax_owner, $allocation->tax_held_minor, $allocation->tax_restaurant_minor, $allocation->fee_owner, $allocation->fee_platform_minor, $allocation->fee_restaurant_minor]);
        $this->assertSame(45000, $allocation->restaurant_net_minor);
        $this->assertSame($allocation->tax_minor, $this->platformBalance(LedgerAccountCode::TaxLiability));
        $this->assertSame($allocation->fee_minor, $this->platformBalance(LedgerAccountCode::PlatformFeeRevenue));
        $this->assertLedgerBalanced();
    }

    public function test_the_most_specific_plan_wins_location_then_organization_then_market(): void
    {
        $service = app(CommissionCalculationService::class);
        $at = now();
        $ids = [(int) $this->market->id, (int) $this->organization->id, (int) $this->burgerHub->id];
        $this->assertNull($service->planFor(...[...$ids, $at]), 'no plan: no commission is calculated at all');

        $market = $this->commissionPlan(1000);
        $this->assertSame($market->id, $service->planFor(...[...$ids, $at])->id);
        $organization = $this->commissionPlan(800, organization: true);
        $this->assertSame($organization->id, $service->planFor(...[...$ids, $at])->id);
        $location = $this->commissionPlan(600, location: $this->burgerHub);
        $this->assertSame($location->id, $service->planFor(...[...$ids, $at])->id);

        // Another location of the same organization gets the organization's terms; another organization the market's.
        [$pizza] = $this->otherRestaurant();
        $this->assertSame($organization->id, $service->planFor((int) $this->market->id, (int) $this->organization->id, (int) $pizza->id, $at)->id);
        $other = $this->eligible('Second Kitchen', 28.57, 77.37, organization: $this->organization('Second Kitchen'));
        $this->assertSame($market->id, $service->planFor((int) $this->market->id, (int) $other->organization_id, (int) $other->id, $at)->id);

        // A withdrawn plan never applies, and a plan applies from its start (inclusive) to its end (exclusive).
        $location->forceFill(['withdrawn_at' => now()])->save();
        $this->assertSame($organization->id, $service->planFor(...[...$ids, $at])->id);
        $organization->forceFill(['effective_until' => $at])->save();
        $this->assertSame($market->id, $service->planFor(...[...$ids, $at])->id);
        $this->assertSame($organization->id, $service->planFor(...[...$ids, $at->subSecond()])->id);

        $order = $this->completedOrderWith();
        $this->assertSame([1000, 'MARKET', 5000], [$this->allocationOf($order)->commission_rate_bps, $this->allocationOf($order)->commission_scope, $this->allocationOf($order)->commission_minor]);
    }

    public function test_new_terms_start_in_the_future_and_an_order_keeps_the_terms_it_was_placed_under(): void
    {
        $old = $this->commissionPlan(1200);
        $before = $this->completedOrderWith();
        $this->assertSame([1200, 6000, 44000], [$this->allocationOf($before)->commission_rate_bps, $this->allocationOf($before)->commission_minor, $this->allocationOf($before)->restaurant_net_minor]);

        $this->asFinance();
        $body = ['name' => 'TEST FIXTURE 15 %', 'market' => $this->market->slug, 'rate_bps' => 1500, 'base_policy' => 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 'approval' => 'TEST', 'reason' => 'TEST FIXTURE: new terms from tomorrow.'];
        // No retroactive terms: a plan cannot start now or in the past.
        $this->financePost('/commission-plans', [...$body, 'effective_from' => now()->subDay()->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'effective_from_in_the_past');
        $this->financePost('/commission-plans', [...$body, 'effective_from' => now()->toIso8601String()])->assertStatus(422);
        $start = CarbonImmutable::parse('2026-10-08 00:00', 'Asia/Kolkata');
        $created = $this->financePost('/commission-plans', [...$body, 'effective_from' => $start->toIso8601String()])->assertCreated()->json('plan');
        $this->assertSame([1500, 'MARKET', 'SCHEDULED', 'TEST', 'ADMIN'], [$created['rate_bps'], $created['scope'], $created['state'], $created['approval'], $created['source']]);
        // The plan in force ends exactly where the new one starts — and that is audited with the old and the new value.
        $this->assertTrue($old->refresh()->effective_until->equalTo($start));
        $audit = AuditEvent::query()->where('action', 'finance.commission_plan_created')->firstOrFail();
        $this->assertSame([1200, 1500], [$audit->changes['rate_bps']['from'], $audit->changes['rate_bps']['to']]);
        $this->assertSame('TEST FIXTURE: new terms from tomorrow.', $audit->reason);
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.commission_plan_ended')->count());

        // The same day: still the old terms.
        $sameDay = $this->completedOrderWith();
        $this->assertSame(1200, $this->allocationOf($sameDay)->commission_rate_bps);

        // The next day: the new terms. The earlier orders — and what was posted for them — did not move.
        $this->clock('2026-10-08 12:00');
        $after = $this->completedOrderWith();
        $this->assertSame([1500, 7500, 42500, $created['id']], [$this->allocationOf($after)->commission_rate_bps, $this->allocationOf($after)->commission_minor, $this->allocationOf($after)->restaurant_net_minor, $this->allocationOf($after)->commission_plan_public_id]);
        $this->assertSame([1200, 6000, 44000, AllocationStatus::Recognized], [$this->allocationOf($before)->commission_rate_bps, $this->allocationOf($before)->commission_minor, $this->allocationOf($before)->restaurant_net_minor, $this->allocationOf($before)->status]);
        $this->assertSame(['D PAYMENT_CLEARING 50000', 'C RESTAURANT_PAYABLE 44000', 'C PLATFORM_COMMISSION_REVENUE 6000'], $this->linesOf('order:'.$before->public_id.':recognized'));
        $this->assertSame(44000 + 44000 + 42500, $this->payableBalance());

        // A settlement of the old orders is made of the old terms, whatever the plan is today.
        $this->asFinance();
        $this->nextWeek();
        $this->generateSettlements();
        $settlement = $this->settlementOf();
        $this->assertSame([150000, 6000 + 6000 + 7500, 130500], [$settlement->gross_sales_minor, $settlement->commission_minor, $settlement->net_payable_minor]);
    }

    public function test_a_plan_is_never_edited_it_is_ended_withdrawn_or_followed_and_only_with_the_permission(): void
    {
        $active = $this->commissionPlan(1000);
        $this->asFinance();
        $id = (string) $active->public_id;

        // There is no way to change a rate: no PUT / PATCH route, and the fields are refused on the actions that exist.
        $this->act();
        $this->patchJson('/api/v1/admin/finance/commission-plans/'.$id, ['rate_bps' => 1])->assertStatus(405);
        $this->putJson('/api/v1/admin/finance/commission-plans/'.$id, ['rate_bps' => 1])->assertStatus(405);
        $this->deleteJson('/api/v1/admin/finance/commission-plans/'.$id)->assertStatus(405);
        $this->financePost('/commission-plans/'.$id.'/end', ['effective_until' => now()->addDay()->toIso8601String(), 'reason' => 'Ending the test plan', 'rate_bps' => 1])->assertStatus(422)->assertJsonValidationErrors('rate_bps', 'error.details.fields');
        $this->assertSame(1000, $active->refresh()->rate_bps);

        // A plan that has started cannot be withdrawn (orders were placed under it); it is ended at a future moment.
        $this->financePost('/commission-plans/'.$id.'/withdraw', ['reason' => 'Trying to withdraw'])->assertStatus(409)->assertJsonPath('error.code', 'plan_already_started');
        $this->financePost('/commission-plans/'.$id.'/end', ['effective_until' => now()->subHour()->toIso8601String(), 'reason' => 'Ending in the past'])->assertStatus(422);
        $ended = $this->financePost('/commission-plans/'.$id.'/end', ['effective_until' => now()->addDays(3)->toIso8601String(), 'reason' => 'TEST FIXTURE: the terms end'])->assertOk()->json('plan');
        $this->assertSame(['ACTIVE', 2], [$ended['state'], $ended['version']]);

        // A plan that has not started can be withdrawn; it then never applies.
        $scheduled = $this->financePost('/commission-plans', ['name' => 'TEST FIXTURE location terms', 'market' => $this->market->slug, 'restaurant' => (string) $this->burgerHub->public_id, 'rate_bps' => 700,
            'base_policy' => 'MERCHANDISE_GROSS', 'effective_from' => now()->addDays(5)->toIso8601String(), 'approval' => 'TEST', 'reason' => 'TEST FIXTURE: negotiated terms'])->assertCreated()->json('plan');
        $this->assertSame(['LOCATION', (string) $this->burgerHub->public_id, true, false], [$scheduled['scope'], $scheduled['location']['id'], $scheduled['can']['withdraw'], $scheduled['can']['end']]);
        // A second plan for the same scope inside that period is refused.
        $this->financePost('/commission-plans', ['name' => 'TEST FIXTURE overlapping', 'market' => $this->market->slug, 'restaurant' => (string) $this->burgerHub->public_id, 'rate_bps' => 650,
            'base_policy' => 'MERCHANDISE_GROSS', 'effective_from' => now()->addDays(2)->toIso8601String(), 'approval' => 'TEST', 'reason' => 'TEST FIXTURE: overlapping terms'])->assertStatus(409)->assertJsonPath('error.code', 'plan_overlaps');
        $this->financePost('/commission-plans/'.$scheduled['id'].'/withdraw', ['reason' => 'TEST FIXTURE: not agreed after all'])->assertOk()->assertJsonPath('plan.state', 'WITHDRAWN');
        // An approved plan needs the reference of its approval; no rate is ever defaulted.
        $this->financePost('/commission-plans', ['name' => 'TEST FIXTURE approved', 'market' => $this->market->slug, 'rate_bps' => 900, 'base_policy' => 'MERCHANDISE_GROSS', 'effective_from' => now()->addDays(9)->toIso8601String(), 'approval' => 'APPROVED', 'reason' => 'TEST FIXTURE: approved terms'])
            ->assertStatus(422)->assertJsonValidationErrors('approval_reference', 'error.details.fields');
        $this->financePost('/commission-plans', ['name' => 'TEST FIXTURE no rate', 'market' => $this->market->slug, 'base_policy' => 'MERCHANDISE_GROSS', 'effective_from' => now()->addDays(9)->toIso8601String(), 'approval' => 'TEST', 'reason' => 'TEST FIXTURE: no rate'])
            ->assertStatus(422)->assertJsonValidationErrors('rate_bps', 'error.details.fields');

        // Reading plans needs admin.finance.commission.view; changing them admin.finance.commission.manage. The finance
        // overview permission alone opens neither: negotiated terms are confidential.
        $this->adminFor([Permission::AdminFinanceView, Permission::AdminSettlementsView, Permission::AdminFinancePayoutsView]);
        $this->financeGet('/commission-plans')->assertForbidden();
        $this->financeGet('/commission-plans/'.$id)->assertForbidden();
        $this->adminFor([Permission::AdminFinanceCommissionView]);
        $this->financeGet('/commission-plans')->assertOk()->assertJsonCount(2, 'data');
        $this->financeGet('/commission-plans?filter[state]=WITHDRAWN')->assertOk()->assertJsonCount(1, 'data');
        $this->financePost('/commission-plans/'.$id.'/end', ['effective_until' => now()->addDay()->toIso8601String(), 'reason' => 'Without the permission'])->assertForbidden();
        $this->financePost('/commission-plans', ['name' => 'Without permission', 'market' => $this->market->slug, 'rate_bps' => 1, 'base_policy' => 'MERCHANDISE_GROSS', 'effective_from' => now()->addDays(20)->toIso8601String(), 'approval' => 'TEST', 'reason' => 'Without the permission'])->assertForbidden();
        $this->assertSame(2, CommissionPlan::query()->count());
    }

    public function test_without_a_plan_an_order_is_never_treated_as_zero_commission_it_waits_for_a_decision(): void
    {
        // No commission plan exists.
        $order = $this->completedOrderWith();
        $allocation = $this->allocationOf($order);
        $this->assertSame([AllocationStatus::ReviewRequired, 'NO_COMMISSION_PLAN', null, null], [$allocation->status, $allocation->review_reason, $allocation->commission_minor, $allocation->restaurant_net_minor]);
        // Nothing was earned and nothing was taken as revenue: the customer's money stays in clearing.
        $this->assertSame(0, RestaurantPayableItem::query()->count());
        $this->assertSame([0, 0, 50000], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue), $this->platformBalance(LedgerAccountCode::PaymentClearing)]);

        $this->asFinance();
        $listed = $this->financeGet('/allocations?filter[status]=REVIEW_REQUIRED')->assertOk()->json('data');
        $this->assertSame([$order->order_number, 'NO_COMMISSION_PLAN', null], [$listed[0]['order']['number'], $listed[0]['review_reason'], $listed[0]['commission']]);

        // A person decides the terms of this ONE order — with a reason; the order's own amounts cannot be sent.
        $url = '/allocations/'.$allocation->public_id.'/decide';
        $this->financePost($url, ['rate_bps' => 1000, 'base_policy' => 'MERCHANDISE_GROSS'])->assertStatus(422)->assertJsonValidationErrors('reason', 'error.details.fields');
        $this->financePost($url, ['rate_bps' => 1000, 'base_policy' => 'MERCHANDISE_GROSS', 'reason' => 'TEST FIXTURE: agreed by e-mail', 'commission_minor' => 1, 'merchandise_gross_minor' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['commission_minor', 'merchandise_gross_minor'], 'error.details.fields');
        $decided = $this->financePost($url, ['rate_bps' => 1000, 'base_policy' => 'MERCHANDISE_GROSS', 'reason' => 'TEST FIXTURE: agreed by e-mail'])->assertOk()->json('allocation');
        $this->assertSame(['RECOGNIZED', 'ADMIN_DECISION', 1000, 5000, 45000], [$decided['status'], $decided['commission']['source'], $decided['commission']['rate_bps'], $decided['commission']['amount']['amount'], $decided['restaurant_net']['amount']]);
        $this->assertSame([45000, 5000, 0], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue), $this->platformBalance(LedgerAccountCode::PaymentClearing)]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.allocation_decided')->where('reason', 'TEST FIXTURE: agreed by e-mail')->count());
        // Decided once: it is not decided again.
        $this->financePost($url, ['rate_bps' => 2000, 'base_policy' => 'MERCHANDISE_GROSS', 'reason' => 'TEST FIXTURE: a second opinion'])->assertStatus(409);
        $this->assertSame(5000, $this->allocationOf($order)->commission_minor);
        $this->assertLedgerBalanced();
    }
}
