<?php

namespace Tests\Feature\Finance;

use App\Enums\Permission;
use App\Models\FinancialAdjustment;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Models\ProviderSettlement;
use App\Models\ReconciliationDifference;
use App\Models\RestaurantPayout;
use App\Services\Finance\ProviderSettlement\FakeSettlementSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\BuildsFinance;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Module 36 — the finance API is what its OpenAPI document says: every route is documented, every answer matches its
 * schema, and no request schema has room for a status, a total, a ledger account or "paid".
 */
class FinanceOpenApiContractTest extends TestCase
{
    use BuildsFinance, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    public function test_the_answers_of_the_restaurant_and_the_administrator_match_their_schemas(): void
    {
        $this->completedOrderWith();
        [$order, $refund] = $this->refundedCompletedOrder(20000);
        $adjustment = FinancialAdjustment::query()->firstOrFail();
        $this->assertMatchesSchema('FinanceAdjustmentEnvelope', $this->financeGet('/adjustments/'.$adjustment->public_id)->assertOk()->json());
        $this->assertMatchesSchema('FinanceAdjustmentEnvelope', $this->financePost('/adjustments/'.$adjustment->public_id.'/decide', ['restaurant_share_minor' => 5000, 'reason' => 'TEST FIXTURE: a part was the kitchen'])->assertOk()->json());
        $this->assertMatchesSchema('FinanceAdjustmentEnvelope', $this->manualCredit($this->burgerHub, 1000)->assertCreated()->json());
        $this->assertMatchesSchema('FinanceAdjustmentPage', $this->financeGet('/adjustments')->assertOk()->json());
        $hold = $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'line' => (string) $this->earningOf($order)->public_id, 'reason' => 'MANUAL_REVIEW', 'note' => 'TEST FIXTURE: looking at this order'])->assertCreated()->json();
        $this->assertMatchesSchema('FinanceHoldEnvelope', $hold);
        $this->assertMatchesSchema('FinanceHoldPage', $this->financeGet('/holds')->assertOk()->json());
        $this->assertMatchesSchema('FinanceHoldEnvelope', $this->financePost('/holds/'.$hold['hold']['id'].'/release', ['reason' => 'TEST FIXTURE: nothing wrong with it'])->assertOk()->json());

        // The provider's records.
        $payment = PaymentAttempt::query()->findOrFail($order->payment_attempt_id);
        (new FakeSettlementSource)->settleDay(CarbonImmutable::parse('2026-10-07'), [['reference' => (string) $payment->provider_payment_reference, 'amount' => 49000, 'fee' => 1000, 'tax' => 153]],
            [['reference' => (string) $refund->provider_refund_reference, 'payment_reference' => (string) $refund->provider_payment_reference, 'amount' => 20000]]);
        $this->assertMatchesSchema('FinanceRunEnvelope', $this->financePost('/provider-settlements/import', ['day' => '2026-10-07'])->assertCreated()->json());
        $this->assertMatchesSchema('ProviderSettlementPage', $this->financeGet('/provider-settlements')->assertOk()->json());
        $this->assertMatchesSchema('ProviderSettlementEnvelope', $this->financeGet('/provider-settlements/'.ProviderSettlement::query()->firstOrFail()->public_id)->assertOk()->json());
        $difference = ReconciliationDifference::query()->where('type', 'AMOUNT_MISMATCH')->firstOrFail();
        $this->assertMatchesSchema('ReconciliationDifferencePage', $this->financeGet('/differences')->assertOk()->json());
        $this->assertMatchesSchema('ReconciliationDifferenceEnvelope', $this->financeGet('/differences/'.$difference->public_id)->assertOk()->json());
        $this->assertMatchesSchema('ReconciliationDifferenceEnvelope', $this->financePost('/differences/'.$difference->public_id.'/investigate')->assertOk()->json());
        $this->assertMatchesSchema('ReconciliationDifferenceEnvelope', $this->financePost('/differences/'.$difference->public_id.'/resolve', ['resolution' => 'EXPLAINED', 'note' => 'TEST FIXTURE: a known rounding of the test report'])->assertOk()->json());
        $this->assertMatchesSchema('FinanceCheckResult', $this->financePost('/checks', ['check' => 'LEDGER'])->assertOk()->json());
        $this->assertMatchesSchema('FinanceCheckResult', $this->financePost('/checks', ['check' => 'PROVIDER'])->assertOk()->json());
        $this->assertMatchesSchema('FinanceRunPage', $this->financeGet('/runs')->assertOk()->json());

        // Settlement and payout.
        $this->nextWeek();
        $this->fakePayouts();
        $this->payoutProfile();
        $this->assertMatchesSchema('SettlementGenerationResult', $this->financePost('/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id, 'dry_run' => true])->assertOk()->json());
        $this->assertMatchesSchema('SettlementGenerationResult', $this->financePost('/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id])->assertCreated()->json());
        $settlement = $this->settlementOf();
        $this->assertMatchesSchema('AdminSettlementPage', $this->financeGet('/settlements')->assertOk()->json());
        $this->assertMatchesSchema('AdminSettlementEnvelope', $this->financeGet('/settlements/'.$settlement->public_id)->assertOk()->json());
        $this->assertMatchesSchema('AdminSettlementEnvelope', $this->financePost('/settlements/'.$settlement->public_id.'/approve')->assertOk()->json());
        $this->assertMatchesSchema('AdminPayoutEnvelope', $this->financePost('/settlements/'.$settlement->public_id.'/payouts', ['confirm' => true])->assertCreated()->json());
        $payout = RestaurantPayout::query()->firstOrFail();
        $this->assertMatchesSchema('AdminPayoutEnvelope', $this->financePost('/payouts/'.$payout->public_id.'/reconcile', ['reason' => 'Looking'])->assertOk()->json());
        $this->assertMatchesSchema('AdminPayoutPage', $this->financeGet('/payouts')->assertOk()->json());
        $this->assertMatchesSchema('AdminSettlementEnvelope', $this->financeGet('/settlements/'.$settlement->public_id)->assertOk()->json());

        // Overview, reports, payables, allocations, plans, the ledger and the health page.
        $this->assertMatchesSchema('FinanceOverview', $this->financeGet('/overview')->assertOk()->json());
        foreach (['/reports/commission', '/reports/commission?group_by=plan', '/reports/customer-returns', '/reports/provider-costs'] as $report) {
            $this->assertMatchesSchema('FinanceReport', $this->financeGet($report)->assertOk()->json());
        }
        $this->assertMatchesSchema('FinancePayablePage', $this->financeGet('/payables')->assertOk()->json());
        $this->assertMatchesSchema('FinanceAllocationPage', $this->financeGet('/allocations')->assertOk()->json());
        $plans = $this->financeGet('/commission-plans')->assertOk()->json();
        $this->assertMatchesSchema('CommissionPlanPage', $plans);
        $this->assertMatchesSchema('CommissionPlanEnvelope', $this->financeGet('/commission-plans/'.$plans['data'][0]['id'])->assertOk()->json());
        $this->assertMatchesSchema('CommissionPlanEnvelope', $this->financePost('/commission-plans', ['name' => 'TEST FIXTURE next terms', 'market' => $this->market->slug, 'rate_bps' => 1100, 'base_policy' => 'MERCHANDISE_GROSS',
            'effective_from' => now()->addDays(3)->toIso8601String(), 'approval' => 'TEST', 'reason' => 'TEST FIXTURE: the next terms'])->assertCreated()->json());
        $this->assertMatchesSchema('LedgerAccountPage', $this->financeGet('/ledger/accounts')->assertOk()->json());
        $this->assertMatchesSchema('LedgerTransactionPage', $this->financeGet('/ledger/transactions')->assertOk()->json());
        $this->assertMatchesSchema('LedgerTransactionEnvelope', $this->financeGet('/ledger/transactions/'.LedgerTransaction::query()->where('type', 'ORDER_RECOGNIZED')->firstOrFail()->public_id)->assertOk()->assertJsonPath('transaction.balanced', true)->json());
        $this->adminFor([Permission::AdminSystemView]);
        $this->assertMatchesSchema('FinanceHealth', $this->adminGet('/system/finance')->assertOk()->json());
        $this->assertMatchesSchema('RefundMetrics', $this->adminGet('/system/refunds')->assertOk()->json());

        // The restaurant's side.
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->assertMatchesSchema('RestaurantEarningsSummary', $this->restaurantFinance('/summary')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantEarningPage', $this->restaurantFinance('/earnings')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantSettlementPage', $this->restaurantFinance('/settlements')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantSettlementEnvelope', $this->restaurantFinance('/settlements/'.$settlement->public_id)->assertOk()->json());
        $this->assertMatchesSchema('RestaurantPayoutPage', $this->restaurantFinance('/payouts')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantCommercialTerms', $this->restaurantFinance('/terms')->assertOk()->json());
        // Errors are the API's error envelope.
        $this->assertMatchesSchema('Error', $this->restaurantFinance('/settlements/'.Str::uuid())->assertNotFound()->json());
        $this->asFinance();
        $this->assertMatchesSchema('Error', $this->financePost('/settlements/'.$settlement->public_id.'/approve', ['status' => 'PAID'])->assertStatus(422)->json());
    }

    public function test_every_finance_route_is_documented_and_no_request_has_room_for_a_status_a_total_or_a_ledger_entry(): void
    {
        $doc = $this->openApi();
        $documented = [];
        foreach ($doc['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        $mine = fn (string $route): bool => (bool) preg_match('#/finance|/payouts#', $route);
        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn (string $m): bool => $m === 'HEAD')->map(fn (string $m): string => $m.' '.preg_replace('#^/api/v1#', '', '/'.ltrim($r->uri(), '/'))))
            ->filter($mine)->unique()->sort()->values()->all();
        $this->assertSame($registered, collect($documented)->filter($mine)->sort()->values()->all(), 'every finance route is documented and nothing else is');
        // 61 = 8 restaurant (read-only) + 49 administrator finance + the health page + the payout webhook + 3 development simulators − 1 … counted, not assumed:
        $this->assertCount(count($registered), array_unique($registered));
        $this->assertSame(8, collect($registered)->filter(fn (string $r): bool => str_contains($r, '/restaurant/finance'))->count());
        $this->assertSame([], collect($registered)->filter(fn (string $r): bool => str_contains($r, '/restaurant/finance') && ! str_starts_with($r, 'GET '))->values()->all(), 'a restaurant only reads');
        $this->assertSame([], collect($registered)->filter(fn (string $r): bool => preg_match('#^(PUT|PATCH|DELETE) #', $r) === 1)->values()->all(), 'nothing of finance is replaced or removed');
        $this->assertSame([], collect($registered)->filter(fn (string $r): bool => str_contains($r, '/ledger') && ! str_starts_with($r, 'GET '))->values()->all(), 'the ledger is read-only');

        // No finance request schema can carry what the server derives.
        $schemas = $doc['components']['schemas'];
        $forbidden = ['status', 'commission_minor', 'net_payable_minor', 'restaurant_net_minor', 'payout_status', 'paid_at', 'paid_minor', 'provider_payout_reference', 'reconciliation_status', 'match_status',
            'ledger_account', 'account', 'debit', 'credit', 'entries', 'posting_key', 'balance', 'balance_minor', 'platform_share_minor', 'liability'];
        $requests = ['FinanceAllocationDecisionRequest', 'CommissionPlanRequest', 'CommissionPlanEndRequest', 'FinanceReasonRequest', 'SettlementGenerationRequest', 'SettlementApprovalRequest', 'FinanceLongReasonRequest', 'PayoutStartRequest',
            'ExternalPayoutRequest', 'ManualAdjustmentRequest', 'LiabilityDecisionRequest', 'FinanceHoldRequest', 'ProviderSettlementImportRequest', 'FinanceCheckRequest', 'DifferenceNoteRequest', 'DifferenceResolveRequest', 'DifferenceIgnoreRequest'];
        foreach ($requests as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], $name.' is closed');
            $this->assertSame([], array_values(array_intersect(array_keys($schemas[$name]['properties']), $forbidden)), $name);
        }
        // The only amounts a request can state: an adjustment's size, the restaurant's share of a refund, and the
        // CONFIRMATION of an external payout's amount.
        $withAmount = array_values(array_filter($requests, fn (string $n): bool => array_intersect(array_keys($schemas[$n]['properties']), ['amount_minor', 'restaurant_share_minor', 'restaurant_discount_minor', 'rate_bps']) !== []));
        $this->assertSame(['FinanceAllocationDecisionRequest', 'CommissionPlanRequest', 'ExternalPayoutRequest', 'ManualAdjustmentRequest', 'LiabilityDecisionRequest'], $withAmount);
        // What the document states about the things that are not there.
        $this->assertSame([false], $schemas['RestaurantSettlement']['properties']['document']['properties']['tax_invoice']['enum']);
        $this->assertSame(['DISABLED'], $schemas['AdminSettlementDetail']['properties']['live_payouts']['enum']);
        $this->assertSame(['DISABLED'], $schemas['AdminPayoutPage']['properties']['provider']['properties']['live_payout']['enum']);
        $this->assertSame(['NOT_IMPLEMENTED'], $schemas['AdminPayoutPage']['properties']['provider']['properties']['real_adapter']['enum']);
        $this->assertSame(['PENDING PROVIDER VERIFICATION'], $schemas['ProviderSettlementPage']['properties']['source']['properties']['razorpay_settlement_api']['enum']);
        $this->assertSame([false], $schemas['FinanceOverview']['properties']['provider_fees']['properties']['estimated']['enum']);
        $this->assertSame([false], $schemas['FinanceHealth']['properties']['flags']['properties']['gst_rate_hardcoded']['enum']);
        $this->assertSame([false], $schemas['FinanceHealth']['properties']['flags']['properties']['tds_tcs_hardcoded']['enum']);
        $this->assertSame(['IMPLEMENTED'], $schemas['RefundMetrics']['properties']['settlement']['enum']);
        // No customer path of the API is a finance path.
        $this->assertSame([], array_values(array_filter(array_keys($doc['paths']), fn (string $p): bool => str_starts_with($p, '/customer') && preg_match('#finance|settlement|payout|commission|earning|ledger#', $p) === 1)));
    }
}
