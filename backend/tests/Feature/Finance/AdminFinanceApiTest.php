<?php

namespace Tests\Feature\Finance;

use App\Enums\DifferenceType;
use App\Enums\LedgerAccountCode;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\CommissionPlan;
use App\Models\FinanceRun;
use App\Models\FinancialAdjustment;
use App\Models\LedgerTransaction;
use App\Models\Market;
use App\Models\OrderFinancialAllocation;
use App\Models\ReconciliationDifference;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantPayout;
use App\Models\RestaurantSettlement;
use App\Services\Finance\FinancialPostingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — finance for administrators: the overview, manual adjustments, permissions, market scope, the checks
 * and the commands. The 10 % commission is a TEST FIXTURE.
 */
class AdminFinanceApiTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    public function test_the_overview_reads_the_ledger_and_never_calls_gross_marketplace_sales_revenue(): void
    {
        $this->feeRule();
        $this->promotion(['code' => 'PLATFORM100', 'funding_source' => 'PLATFORM']);
        $this->completedOrderWith(['promotion_code' => 'PLATFORM100']);
        $this->completedOrderWith();
        [, , , , $open] = $this->paidOrder($this->nextPhone(), '15:00');
        [, $refund] = $this->refundedCompletedOrder(10000);
        $this->asFinance();

        $overview = $this->financeGet('/overview?from=2026-10-01&to=2026-10-07')->assertOk()->json();
        $inr = $overview['currencies'][0];
        $p = $inr['period'];
        // Four payments of ₹515 (one ₹415 with the discount): customer money, not revenue.
        $this->assertSame(['INR', 41500 + 51500 * 3, 10000, 150000, 15000, 4500, 19500, 10000, 135000, 3], [$inr['currency'], $p['customer_payments']['amount'], $p['refunds']['amount'], $p['gross_marketplace_sales']['amount'],
            $p['platform_commission']['amount'], $p['platform_fee_revenue']['amount'], $p['platform_revenue']['amount'], $p['promotion_expense']['amount'], $p['restaurant_earnings']['amount'], $p['orders_recognized']]);
        // Revenue is commission + the platform's fees — the ₹1,500 of sales are not in it.
        $this->assertSame($p['platform_revenue']['amount'], $p['platform_commission']['amount'] + $p['platform_fee_revenue']['amount']);
        $this->assertStringContainsString('not FoodOnTheGo revenue', $overview['terms']['gross_marketplace_sales']);
        // No provider record was imported: the provider fee is zero — and says it is not an estimate.
        $this->assertSame([0, 'PROVIDER_REPORTED', false, 0], [$p['provider_fees']['amount'], $overview['provider_fees']['source'], $overview['provider_fees']['estimated'], $overview['provider_fees']['records_imported']]);
        // What is held and owed right now.
        $s = $inr['standing'];
        $this->assertSame([135000, 51500, 41500 + 51500 * 3 - 10000, 10000, 0], [$s['restaurant_payables']['amount'], $s['payment_clearing']['amount'], $s['provider_clearing']['amount'], $s['refund_clearing']['amount'], $s['tax_held']['amount']]);
        $this->assertSame([1, 0, 0], [$overview['queues']['liability_decisions_pending'], $overview['queues']['allocations_awaiting_terms'], $overview['queues']['differences_open']['total']]);
        $this->assertSame(['NEVER_CHECKED', 'NEVER_RUN', 'FAKE_PROVIDER', 'NOT_CONFIGURED', 'DISABLED'], [$overview['health']['ledger'], $overview['health']['internal_reconciliation'], $overview['health']['provider_reconciliation'], $overview['health']['payout_provider'], $overview['health']['live_payouts']]);
        // A period outside the activity is empty; a period that is too long or upside down is refused.
        $this->assertSame(0, $this->financeGet('/overview?from=2026-09-01&to=2026-09-30')->assertOk()->json('currencies.0.period.customer_payments.amount'));
        $this->financeGet('/overview?from=2026-10-07&to=2026-10-01')->assertStatus(422);
        $this->financeGet('/overview?from=2024-01-01&to=2026-10-07')->assertStatus(422);

        // COMMISSION REPORT by day, restaurant, market and plan.
        $byDay = $this->financeGet('/reports/commission?from=2026-10-01&to=2026-10-07')->assertOk()->json();
        $this->assertSame(['day', '2026-10-07', 3, 150000, 15000, 135000, 10000], [$byDay['group_by'], $byDay['rows'][0]['key'], $byDay['rows'][0]['orders'], $byDay['rows'][0]['gross_marketplace_sales']['amount'], $byDay['rows'][0]['commission']['amount'], $byDay['rows'][0]['restaurant_earnings']['amount'], $byDay['rows'][0]['platform_funded_discount']['amount']]);
        $this->assertSame(['Burger Hub', 15000], [$this->financeGet('/reports/commission?group_by=location')->assertOk()->json('rows.0.label'), $this->financeGet('/reports/commission?group_by=location&restaurant='.$this->burgerHub->public_id)->assertOk()->json('rows.0.commission.amount')]);
        $this->assertSame($this->market->slug, $this->financeGet('/reports/commission?group_by=market')->assertOk()->json('rows.0.key'));
        $this->assertSame([1000, 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT'], [$this->financeGet('/reports/commission?group_by=plan')->assertOk()->json('rows.0.rate_bps'), $this->financeGet('/reports/commission?group_by=plan')->assertOk()->json('rows.0.base_policy')]);
        // Rates are confidential: without the commission permission the plan grouping and the terms of an allocation are closed.
        $this->adminFor([Permission::AdminFinanceView]);
        $this->financeGet('/reports/commission?group_by=plan')->assertForbidden();
        $this->financeGet('/reports/commission?group_by=day')->assertOk();
        $this->assertSame(['amount'], array_keys($this->financeGet('/allocations?filter[status]=RECOGNIZED')->assertOk()->json('data.0.commission')));
        $this->financeGet('/commission-plans')->assertForbidden();
        unset($open, $refund);
    }

    public function test_a_manual_adjustment_needs_permission_reason_and_reference_and_is_one_balanced_posting(): void
    {
        $this->asFinance();
        $body = ['restaurant' => (string) $this->burgerHub->public_id, 'direction' => 'CREDIT_RESTAURANT', 'amount_minor' => 12000, 'currency' => 'INR', 'reason_code' => 'GOODWILL',
            'reason' => 'TEST FIXTURE: compensation agreed for a platform outage', 'supporting_reference' => 'TICKET-4711'];
        // Each of permission, reason, supporting reference, amount and currency is required; nothing names an account.
        foreach (['reason', 'supporting_reference', 'amount_minor', 'currency', 'direction', 'reason_code', 'restaurant'] as $missing) {
            $this->financePost('/adjustments', array_diff_key($body, [$missing => 0]))->assertStatus(422)->assertJsonValidationErrors($missing, 'error.details.fields');
        }
        $this->financePost('/adjustments', [...$body, 'reason' => 'short'])->assertStatus(422);
        $this->financePost('/adjustments', [...$body, 'amount_minor' => -5])->assertStatus(422);
        $this->financePost('/adjustments', [...$body, 'amount_minor' => 0])->assertStatus(422);
        $this->financePost('/adjustments', [...$body, 'currency' => 'USD'])->assertStatus(422)->assertJsonPath('error.code', 'currency_mismatch');
        $this->financePost('/adjustments', [...$body, 'ledger_account' => 'PLATFORM_BANK', 'debit' => 1, 'credit' => 1, 'balance' => 999, 'status' => 'POSTED', 'entries' => [['account' => 'PLATFORM_BANK', 'direction' => 'D', 'amount_minor' => 1]]])->assertStatus(422)->assertJsonValidationErrors(['ledger_account', 'debit', 'credit', 'balance', 'status', 'entries'], 'error.details.fields');
        $this->assertSame([0, 0], [FinancialAdjustment::query()->count(), LedgerTransaction::query()->count()]);

        $this->act();
        $created = $this->postJson('/api/v1/admin/finance/adjustments', $body, ['Idempotency-Key' => 'adjustment-key-0000000001'])->assertCreated()->json('adjustment');
        $this->assertSame(['MANUAL', 'POSTED', 'CREDIT_RESTAURANT', 12000, 'TICKET-4711', 'ADMIN'], [$created['kind'], $created['status'], $created['direction'], $created['amount']['amount'], $created['supporting_reference'], $created['policy_source']]);
        $this->assertSame(['D PLATFORM_ADJUSTMENTS 12000', 'C RESTAURANT_PAYABLE 12000'], $this->linesOf('adjustment:'.$created['id']));
        $this->assertSame([12000, 12000], [$this->payableBalance(), (int) RestaurantPayableItem::query()->where('type', 'MANUAL_ADJUSTMENT')->sum('amount_minor')]);
        // The same request again (a retried click) is the same adjustment.
        $this->act();
        $this->postJson('/api/v1/admin/finance/adjustments', $body, ['Idempotency-Key' => 'adjustment-key-0000000001'])->assertCreated()->assertJsonPath('adjustment.id', $created['id']);
        $this->assertSame([1, 12000], [FinancialAdjustment::query()->count(), $this->payableBalance()]);
        $audit = AuditEvent::query()->where('action', 'finance.adjustment_created')->firstOrFail();
        $this->assertSame(['TEST FIXTURE: compensation agreed for a platform outage', 12000, 'TICKET-4711'], [$audit->reason, $audit->changes['amount_minor']['to'], $audit->changes['supporting_reference']['to']]);

        // A debit lowers what is owed — also below zero; it is never a bank debit.
        $this->manualCredit($this->burgerHub, 20000, 'DEBIT_RESTAURANT')->assertCreated();
        $this->assertSame(-8000, $this->payableBalance());
        $this->assertSame(0, RestaurantPayout::query()->count());

        // FOUR EYES: the adjustment waits; who made it cannot approve it; a second administrator posts it.
        config(['finance.adjustments.four_eyes' => true]);
        $waiting = $this->manualCredit($this->burgerHub, 5000)->assertCreated()->json('adjustment');
        $this->assertSame(['PENDING_APPROVAL', false, true, -8000], [$waiting['status'], $waiting['can']['approve'], $waiting['can']['cancel'], $this->payableBalance()]);
        $this->financePost('/adjustments/'.$waiting['id'].'/approve', ['reason' => 'Approving my own'])->assertForbidden()->assertJsonPath('error.code', 'four_eyes');
        $this->asActor($this->adminWith(self::FINANCE_ADMIN));
        $this->financePost('/adjustments/'.$waiting['id'].'/approve', ['reason' => 'Checked the ticket'])->assertOk()->assertJsonPath('adjustment.status', 'POSTED');
        $this->assertSame(-3000, $this->payableBalance());
        $other = $this->manualCredit($this->burgerHub, 999)->assertCreated()->json('adjustment.id');
        $this->financePost('/adjustments/'.$other.'/cancel', ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('adjustment.status', 'CANCELLED');
        $this->financePost('/adjustments/'.$other.'/approve', ['reason' => 'After the withdrawal'])->assertStatus(409);
        $this->assertSame(-3000, $this->payableBalance());

        // Without admin.finance.adjustments.create nothing can be adjusted.
        $this->adminFor([Permission::AdminFinanceView, Permission::AdminSettlementsView, Permission::AdminFinancePayoutsView, Permission::AdminFinancePayoutsExecute]);
        $this->financeGet('/adjustments')->assertOk()->assertJsonCount(4, 'data');
        $this->financePost('/adjustments', $body)->assertForbidden();
        $this->financePost('/adjustments/'.$other.'/approve', ['reason' => 'Without the permission'])->assertForbidden();
        $this->assertLedgerBalanced();
    }

    public function test_every_finance_action_needs_its_own_permission_and_the_seeded_roles_keep_them_apart(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $this->generateSettlements();
        $settlement = $this->settlementOf();
        $allocation = OrderFinancialAllocation::query()->firstOrFail();

        // Each read needs its permission …
        $reads = [
            '/overview' => Permission::AdminFinanceView, '/payables' => Permission::AdminFinanceView, '/allocations' => Permission::AdminFinanceView, '/adjustments' => Permission::AdminFinanceView,
            '/holds' => Permission::AdminFinanceView, '/runs' => Permission::AdminFinanceView, '/reports/commission' => Permission::AdminFinanceView, '/reports/customer-returns' => Permission::AdminFinanceView,
            '/reports/provider-costs' => Permission::AdminFinanceView, '/commission-plans' => Permission::AdminFinanceCommissionView, '/settlements' => Permission::AdminSettlementsView,
            '/settlements/'.$settlement->public_id => Permission::AdminSettlementsView, '/payouts' => Permission::AdminFinancePayoutsView, '/provider-settlements' => Permission::AdminFinanceReconciliationView,
            '/differences' => Permission::AdminFinanceReconciliationView, '/ledger/accounts' => Permission::AdminFinanceLedgerView, '/ledger/transactions' => Permission::AdminFinanceLedgerView,
        ];
        $every = array_values(array_unique(array_values($reads), SORT_REGULAR));
        foreach ($reads as $path => $needed) {
            $this->adminFor([$needed]);
            $this->financeGet($path)->assertOk();
            $this->adminFor(array_values(array_filter($every, fn (Permission $p): bool => $p !== $needed)));
            $this->financeGet($path)->assertForbidden();
        }
        // … and each action too: holding every OTHER finance permission is not enough.
        $actions = [
            ['/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id], Permission::AdminFinanceSettlementsManage],
            ['/settlements/'.$settlement->public_id.'/approve', [], Permission::AdminFinanceSettlementsApprove],
            ['/settlements/'.$settlement->public_id.'/cancel', ['reason' => 'TEST FIXTURE: without the permission'], Permission::AdminFinanceSettlementsManage],
            ['/settlements/'.$settlement->public_id.'/payouts', ['confirm' => true], Permission::AdminFinancePayoutsExecute],
            ['/settlements/'.$settlement->public_id.'/external-payouts', ['confirm' => true, 'external_reference' => 'UTR-1', 'paid_on' => '2026-10-12', 'amount_minor' => 45000, 'currency' => 'INR', 'reason' => 'TEST FIXTURE: without the permission', 'evidence_reference' => 'DOC-1'], Permission::AdminFinancePayoutsRecordExternal],
            ['/commission-plans', ['name' => 'x'], Permission::AdminFinanceCommissionManage],
            ['/adjustments', ['restaurant' => (string) $this->burgerHub->public_id, 'direction' => 'CREDIT_RESTAURANT', 'amount_minor' => 1, 'currency' => 'INR', 'reason_code' => 'OTHER', 'reason' => 'TEST FIXTURE: without the permission', 'supporting_reference' => 'TICKET-1'], Permission::AdminFinanceAdjustmentsCreate],
            ['/allocations/'.$allocation->public_id.'/decide', ['rate_bps' => 1, 'base_policy' => 'MERCHANDISE_GROSS', 'reason' => 'TEST FIXTURE: without the permission'], Permission::AdminFinanceAdjustmentsCreate],
            ['/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'reason' => 'MANUAL_REVIEW', 'note' => 'TEST FIXTURE: without the permission'], Permission::AdminFinanceHoldsManage],
            ['/provider-settlements/import', ['day' => '2026-10-07'], Permission::AdminFinanceReconciliationManage],
            ['/checks', ['check' => 'LEDGER'], Permission::AdminFinanceReconciliationManage],
        ];
        $all = array_values(array_filter(self::FINANCE_ADMIN, fn (Permission $p): bool => str_starts_with($p->value, 'admin.finance.') || $p === Permission::AdminSettlementsView));
        foreach ($actions as [$path, $payload, $needed]) {
            $this->adminFor(array_values(array_filter($all, fn (Permission $p): bool => $p !== $needed)));
            $this->financePost($path, $payload)->assertForbidden();
        }
        $this->assertSame(['CALCULATED', 1, 0], [$settlement->refresh()->status->value, RestaurantSettlement::query()->count(), FinancialAdjustment::query()->count()]);

        // THE SEEDED ROLES. Finance holds the whole workflow; operations and the analyst read; support holds nothing
        // of finance — and SUPER_ADMIN is not the only way in.
        $roles = RoleSeeder::adminRoles();
        $has = fn (string $role, Permission $p): bool => in_array($p, $roles[$role][1], true);
        foreach ($all as $p) {
            $this->assertTrue($has('FINANCE_ADMIN', $p), 'finance: '.$p->value);
            $this->assertTrue($has('SUPER_ADMIN', $p));
            $this->assertFalse($has('SUPPORT_ADMIN', $p), 'support must not hold '.$p->value);
            $this->assertFalse($has('MODERATION_ADMIN', $p));
            $this->assertFalse($has('RESTAURANT_ONBOARDING', $p));
        }
        foreach (['OPERATIONS_ADMIN', 'ANALYST'] as $role) {
            $this->assertTrue($has($role, Permission::AdminFinanceView));
            foreach ([Permission::AdminFinanceCommissionView, Permission::AdminFinanceCommissionManage, Permission::AdminFinanceSettlementsManage, Permission::AdminFinanceSettlementsApprove, Permission::AdminFinancePayoutsExecute,
                Permission::AdminFinancePayoutsRecordExternal, Permission::AdminFinanceReconciliationManage, Permission::AdminFinanceAdjustmentsCreate, Permission::AdminFinanceHoldsManage] as $p) {
                $this->assertFalse($has($role, $p), $role.' must not hold '.$p->value);
            }
        }
    }

    public function test_an_administrator_of_one_market_never_reads_or_changes_another_markets_finances(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $this->generateSettlements();
        $settlement = $this->settlementOf();
        $plan = CommissionPlan::query()->firstOrFail();
        $elsewhere = Market::factory()->create(['country_code' => 'AE']);

        // All finance permissions — for ANOTHER market only.
        $this->asActor($this->adminWith(self::FINANCE_ADMIN, $elsewhere));
        foreach (['/settlements', '/payouts', '/payables', '/allocations', '/adjustments', '/holds', '/commission-plans', '/differences', '/ledger/transactions', '/ledger/accounts'] as $list) {
            $this->financeGet($list)->assertOk()->assertJsonCount(0, 'data');
        }
        $this->assertSame([], $this->financeGet('/overview')->assertOk()->json('currencies'));
        $this->assertSame([], $this->financeGet('/reports/commission')->assertOk()->json('rows'));
        $this->financeGet('/settlements/'.$settlement->public_id)->assertForbidden();
        $this->financeGet('/commission-plans/'.$plan->public_id)->assertForbidden();
        $this->financePost('/settlements/'.$settlement->public_id.'/approve')->assertForbidden();
        $this->financePost('/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id])->assertForbidden();
        $this->financePost('/settlements/generate', ['market' => $this->market->slug])->assertForbidden();
        $this->manualCredit($this->burgerHub)->assertForbidden();
        $this->financePost('/commission-plans', ['name' => 'TEST FIXTURE other market', 'market' => $this->market->slug, 'rate_bps' => 1, 'base_policy' => 'MERCHANDISE_GROSS', 'effective_from' => now()->addDay()->toIso8601String(), 'approval' => 'TEST', 'reason' => 'TEST FIXTURE: another market'])->assertForbidden();
        $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'reason' => 'MANUAL_REVIEW', 'note' => 'TEST FIXTURE: another market'])->assertForbidden();
        // Generating "everything I may see" reaches nothing of the other market.
        $this->assertSame(0, $this->financePost('/settlements/generate', [])->assertCreated()->json('summary.locations'));
        // Provider records carry no market: they need the permission for all markets.
        $this->financeGet('/provider-settlements')->assertForbidden();
        $this->financeGet('/reports/provider-costs')->assertForbidden();
        $this->financePost('/checks', ['check' => 'LEDGER'])->assertForbidden();

        // The administrator of THIS market reaches it.
        $this->asActor($this->adminWith(self::FINANCE_ADMIN, $this->market));
        $this->financeGet('/settlements')->assertOk()->assertJsonCount(1, 'data');
        $this->financePost('/settlements/'.$settlement->public_id.'/approve')->assertOk()->assertJsonPath('settlement.status', 'APPROVED');
    }

    public function test_the_integrity_check_and_the_internal_reconciliation_find_what_is_wrong_and_repair_nothing(): void
    {
        $order = $this->completedOrderWith();
        $this->asFinance();

        // A clean ledger.
        $clean = $this->financePost('/checks', ['check' => 'LEDGER'])->assertOk()->json('run');
        $this->assertSame(['VERIFY_LEDGER', 'OK', 0, 2], [$clean['kind'], $clean['status'], $clean['problems'], $clean['result']['transactions']]);
        $this->assertSame(0, array_sum($clean['result']['checks']));
        $this->assertSame(['INTERNAL', 'OK'], [$this->financePost('/checks', ['check' => 'INTERNAL'])->assertOk()->json('check'), $this->financePost('/checks', ['check' => 'INTERNAL'])->assertOk()->json('run.status')]);
        $this->financePost('/checks', ['check' => 'EVERYTHING'])->assertStatus(422);
        $this->assertSame('BALANCED', $this->financeGet('/overview')->assertOk()->json('health.ledger'));

        // Somebody with a database console and the triggers switched off damages the books.
        DB::statement('alter table ledger_entries disable trigger user');
        DB::table('ledger_entries')->where('ledger_transaction_id', $this->posting('order:'.$order->public_id.':recognized')->id)->where('direction', 'C')->orderBy('id')->limit(1)->update(['amount_minor' => 44000]);
        DB::statement('alter table ledger_entries enable trigger user');
        DB::table('orders')->where('id', $order->id)->update(['total_minor' => 49000, 'subtotal_minor' => 49000]);

        $this->assertSame(1, Artisan::call('finance:verify-ledger'), 'the command fails when it finds something');
        $run = FinanceRun::query()->where('kind', 'VERIFY_LEDGER')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['PROBLEMS', 1, 1], [$run->status, $run->result['checks']['unbalanced_transactions'], $run->result['checks']['payable_mismatches']]);
        $imbalance = ReconciliationDifference::query()->where('type', DifferenceType::LedgerImbalance->value)->firstOrFail();
        $this->assertSame(['CRITICAL', 'OPEN', 1000], [$imbalance->severity, $imbalance->status->value, $imbalance->actual_minor]);
        $this->assertSame(1, ReconciliationDifference::query()->where('type', DifferenceType::PayableMismatch->value)->count());
        // A critical difference cannot be ignored.
        $this->financePost('/differences/'.$imbalance->public_id.'/ignore', ['note' => 'TEST FIXTURE: trying to look away'])->assertStatus(409)->assertJsonPath('error.code', 'difference_cannot_be_ignored');
        $this->assertSame('PROBLEMS', $this->financeGet('/overview')->assertOk()->json('health.ledger'));

        // The internal reconciliation: the payment no longer is the order's amount.
        Artisan::call('finance:reconcile-internal');
        $mismatch = ReconciliationDifference::query()->where('type', DifferenceType::PaymentOrderAmountMismatch->value)->firstOrFail();
        $this->assertSame(['HIGH', 49000, 50000, $order->order_number], [$mismatch->severity, $mismatch->expected_minor, $mismatch->actual_minor, $mismatch->subject_reference]);
        // Nothing was repaired by either check.
        $this->assertSame([49000, 44000], [(int) DB::table('orders')->where('id', $order->id)->value('total_minor'), $this->payableBalance()]);

        // The data is put right: the same checks close what they had opened.
        DB::table('orders')->where('id', $order->id)->update(['total_minor' => 50000, 'subtotal_minor' => 50000]);
        DB::statement('alter table ledger_entries disable trigger user');
        DB::table('ledger_entries')->where('ledger_transaction_id', $this->posting('order:'.$order->public_id.':recognized')->id)->where('amount_minor', 44000)->update(['amount_minor' => 45000]);
        DB::statement('alter table ledger_entries enable trigger user');
        $this->assertSame(0, Artisan::call('finance:verify-ledger'));
        Artisan::call('finance:reconcile-internal');
        $this->assertSame(['RESOLVED', 'AUTO_MATCHED', 'RESOLVED'], [$imbalance->refresh()->status->value, $imbalance->resolution, $mismatch->refresh()->status->value]);
        $this->assertSame(0, ReconciliationDifference::query()->unresolved()->count());
    }

    public function test_the_sweep_is_the_backfill_it_posts_what_has_no_posting_and_is_safe_to_run_again(): void
    {
        // Orders that existed BEFORE this module: nothing of finance exists for them (what the listeners wrote is
        // taken away again — at the database, the only place where that is possible).
        $this->completedOrderWith();
        $this->rejectedPaidOrder($this->nextPhone());
        DB::statement('alter table ledger_entries disable trigger user');
        DB::statement('alter table ledger_transactions disable trigger user');
        DB::table('restaurant_payable_items')->delete();
        DB::table('order_financial_allocations')->delete();
        DB::table('ledger_entries')->delete();
        DB::table('ledger_transactions')->delete();
        DB::statement('alter table ledger_entries enable trigger user');
        DB::statement('alter table ledger_transactions enable trigger user');
        $this->assertSame(0, LedgerTransaction::query()->count());

        $this->assertSame(0, Artisan::call('finance:post-pending', ['--backfill' => true]));
        $run = FinanceRun::query()->where('kind', 'BACKFILL')->firstOrFail();
        // The counts are recorded: two payments, two orders, one recognized, one void.
        $this->assertSame(['OK', 2, 2, 1, 1, 0], [$run->status, $run->result['payments'], $run->result['allocations'], $run->result['recognized'], $run->result['voided'], $run->result['failed']]);
        // A backfilled order gets the plan that applied when it was PLACED, and says its discount basis is a backfill.
        $this->assertSame([3, 45000, 5000, 100000], [LedgerTransaction::query()->count(), $this->payableBalance(), $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue), $this->providerClearing()]);
        $this->assertSame(1000, OrderFinancialAllocation::query()->where('status', 'RECOGNIZED')->firstOrFail()->commission_rate_bps);

        // Running it again posts nothing.
        Artisan::call('finance:post-pending', ['--backfill' => true]);
        $again = FinanceRun::query()->where('kind', 'BACKFILL')->orderByDesc('id')->firstOrFail();
        $this->assertSame([0, 0, 0, 0], [$again->result['payments'], $again->result['allocations'], $again->result['recognized'], $again->result['voided']]);
        $this->assertSame([3, 2, 1], [LedgerTransaction::query()->count(), OrderFinancialAllocation::query()->count(), RestaurantPayableItem::query()->count()]);
        $this->assertSame(['payments' => 0, 'allocations' => 0, 'recognized' => 0, 'voided' => 0, 'refunds' => 0, 'disputes' => 0, 'holds_released' => 0, 'failed' => 0], app(FinancialPostingService::class)->sweep());
        $this->assertLedgerBalanced();
    }

    public function test_the_settlement_command_has_a_dry_run_and_the_health_page_hides_nothing(): void
    {
        $this->completedOrderWith();
        $this->nextWeek();

        Artisan::call('finance:generate-settlements', ['--dry-run' => true]);
        $dry = json_decode(trim(Artisan::output()), true);
        $this->assertSame([true, 1, 'SETTLEMENT', null], [$dry['dry_run'], $dry['summary']['settlements'], $dry['results'][0]['outcome'], $dry['results'][0]['settlement']]);
        $this->assertSame(0, RestaurantSettlement::query()->count());
        Artisan::call('finance:generate-settlements', ['--until' => '2026-10-12']);
        $this->assertSame([1, 'SYSTEM', 45000], [RestaurantSettlement::query()->count(), $this->settlementOf()->generated_by_type, $this->settlementOf()->net_payable_minor]);
        Artisan::call('finance:generate-settlements');
        $this->assertSame(1, RestaurantSettlement::query()->count());
        $this->assertSame(2, Artisan::call('finance:generate-settlements', ['--until' => '2030-01-01']), 'a cutoff in the future is refused');
        // The provider reconciliation says when it has nothing to read from, instead of pretending.
        config(['services.payment.driver' => null]);
        Artisan::call('finance:reconcile-provider');
        $this->assertSame('NOT_AVAILABLE', json_decode(trim(Artisan::output()), true)['status']);

        // HEALTH: needs admin.system.view; says what is not configured, not approved and not live.
        $this->adminFor([Permission::AdminFinanceView]);
        $this->adminGet('/system/finance')->assertForbidden();
        $this->adminFor([Permission::AdminSystemView]);
        $health = $this->adminGet('/system/finance')->assertOk()->json();
        $this->assertSame(['NEVER_CHECKED', 'NEVER_RUN', 'NOT_CONFIGURED', 'DISABLED', 'NOT_IMPLEMENTED', 'PENDING PROVIDER ACCOUNT ENABLEMENT', false],
            [$health['ledger']['status'], $health['internal_reconciliation']['status'], $health['payout_provider']['status'], $health['payout_provider']['live_payout'], $health['payout_provider']['real_adapter'], $health['payout_provider']['provider_account'], $health['payout_provider']['live_enabled']]);
        $this->assertSame(['DISABLED', 'NOT_IMPLEMENTED', false, false, 'PENDING TAX / ACCOUNTING DESIGN', 'PENDING TAX / ACCOUNTING DESIGN', 'PENDING PROFESSIONAL TAX REVIEW', 'NO'],
            [$health['flags']['live_payouts'], $health['flags']['restaurant_kyc'], $health['flags']['gst_rate_hardcoded'], $health['flags']['tds_tcs_hardcoded'], $health['flags']['tax_invoice'], $health['flags']['credit_note'], $health['flags']['withholding'], $health['flags']['production']]);
        // Every policy says what it is today and that it is not approved.
        foreach ($health['policies'] as $name => $policy) {
            $this->assertMatchesRegularExpression('/PENDING/', (string) $policy['approval'], $name);
        }
        $this->assertSame(['ORDER_COMPLETED', 'UNASSIGNED', 'PLATFORM', 'PLATFORM_BORNE', 'WEEKLY', null, 'UNDEFINED'], [$health['policies']['recognition']['value'], $health['policies']['tax_owner']['value'], $health['policies']['fee_owner']['value'],
            $health['policies']['provider_fees']['value'], $health['policies']['settlement_cadence']['value'], $health['policies']['minimum_payout']['value'], $health['policies']['shared_promotion_split']['value']]);
        $this->assertSame(['UP_TO_DATE', 0], [$health['queue']['status'], array_sum($health['queue']['unposted'])]);
        // No secret, key or token is part of it.
        $this->assertDoesNotMatchRegularExpression('/secret|password|rzp_|key_id|token/i', json_encode($health));
    }
}
