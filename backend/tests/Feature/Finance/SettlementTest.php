<?php

namespace Tests\Feature\Finance;

use App\Enums\DifferenceType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Enums\Permission;
use App\Enums\SettlementStatus;
use App\Models\AuditEvent;
use App\Models\FinanceRun;
use App\Models\FinancialAdjustment;
use App\Models\LedgerTransaction;
use App\Models\ReconciliationDifference;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantPayout;
use App\Models\RestaurantSettlement;
use App\Models\RestaurantSettlementLine;
use App\Services\Finance\LedgerDraft;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\SettlementCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — restaurant settlements: what the platform owes a location for a period, line by line, each line once.
 *
 * A settlement is a calculation. Nothing in this file sends money. The weekly cadence and the 10 % commission are
 * TEST FIXTURES.
 */
class SettlementTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    public function test_a_settlement_takes_every_eligible_line_exactly_once_and_its_lines_explain_its_total(): void
    {
        $first = $this->completedOrderWith();
        $second = $this->completedOrderWith([], 4);
        [$third, $refund] = $this->refundedCompletedOrder();
        $adjustment = FinancialAdjustment::query()->firstOrFail();
        $this->financePost('/adjustments/'.$adjustment->public_id.'/decide', ['restaurant_share_minor' => 20000, 'reason' => 'TEST FIXTURE: part of it was the kitchen'])->assertOk();

        // Nothing is due while the week is not over: the cutoff is the end of the last finished week (Asia/Kolkata).
        $this->assertSame([], $this->generateSettlements()['results']);
        $this->nextWeek();

        // A dry run calculates and writes nothing.
        $dry = $this->generateSettlements(dryRun: true);
        $this->assertSame([true, 'SETTLEMENT', 4, 160000, null], [$dry['dry_run'], $dry['results'][0]['outcome'], $dry['results'][0]['line_count'], $dry['results'][0]['net_payable']['amount'], $dry['results'][0]['settlement']]);
        $this->assertSame([0, 0], [RestaurantSettlement::query()->count(), RestaurantPayableItem::query()->where('status', 'SETTLED')->count()]);

        $made = $this->generateSettlements();
        $this->assertSame(['settlements' => 1, 'lines' => 4], array_intersect_key($made['summary'], ['settlements' => 0, 'lines' => 0]));
        $s = $this->settlementOf();
        // ₹500 + ₹1,000 + ₹500 of sales; 10 % commission; ₹200 of a refund borne by the restaurant.
        $this->assertSame([SettlementStatus::Calculated, 'INR', 3, 4, 200000, 0, 20000, -20000, 0, 160000, 'WEEKLY', 'Asia/Kolkata'],
            [$s->status, $s->currency, $s->order_count, $s->line_count, $s->gross_sales_minor, $s->restaurant_discount_minor, $s->commission_minor, $s->refund_adjustments_minor, $s->other_adjustments_minor, $s->net_payable_minor, $s->cadence, $s->timezone]);
        $this->assertMatchesRegularExpression('/\ASTL-20261012-[A-Z0-9]{6}\z/', $s->reference);
        $this->assertTrue($s->period_end->equalTo(CarbonImmutable::parse('2026-10-12 00:00', 'Asia/Kolkata')), 'the cutoff is midnight of the market\'s time zone, stored in UTC');

        // Every line leads back to its source, and the lines add up to every total.
        $lines = $s->lines()->get();
        $this->assertSame(4, $lines->count());
        $this->assertSame(160000, (int) $lines->sum('amount_minor'));
        $this->assertSame(200000, (int) $lines->sum('gross_minor'));
        $this->assertSame(20000, (int) $lines->sum('commission_minor'));
        $this->assertEqualsCanonicalizing([$first->order_number, $second->order_number, $third->order_number, $third->order_number], $lines->pluck('order_number')->all());
        $this->assertEqualsCanonicalizing(['ORDER_EARNING', 'ORDER_EARNING', 'ORDER_EARNING', 'REFUND_ADJUSTMENT'], $lines->pluck('type')->all());
        foreach ($lines as $line) {
            $item = RestaurantPayableItem::query()->findOrFail($line->payable_item_id);
            $this->assertSame([(int) $s->id, 'SETTLED', (int) $line->amount_minor, (string) $item->public_id], [(int) $item->settlement_id, $item->status, (int) $item->amount_minor, $line->source_public_id]);
            $this->assertNotNull(LedgerTransaction::query()->find($item->ledger_transaction_id), 'the line is backed by a ledger posting');
        }

        // Generating again settles nothing twice: every line already belongs to a settlement.
        $again = $this->generateSettlements();
        $this->assertSame([[], 0], [$again['results'], $again['summary']['settlements']]);
        app(SettlementCalculationService::class)->generate(collect([$this->burgerHub]));
        $this->assertSame([1, 4], [RestaurantSettlement::query()->count(), RestaurantSettlementLine::query()->count()]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.settlement_generated')->count());
        // Every run is recorded — the dry run as a dry run.
        $this->assertSame([4, 1], [FinanceRun::query()->where('kind', 'SETTLEMENT_GENERATION')->where('dry_run', false)->count(), FinanceRun::query()->where('kind', 'SETTLEMENT_GENERATION')->where('dry_run', true)->count()]);
        // Generating sent no money and made no payout; the restaurant is still owed everything.
        $this->assertSame([0, 160000], [RestaurantPayout::query()->count(), $this->payableBalance()]);
    }

    public function test_approval_rechecks_the_ledger_and_an_approved_settlement_is_never_edited(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $this->generateSettlements();
        $s = $this->settlementOf();
        $url = '/settlements/'.$s->public_id;

        $shown = $this->financeGet($url)->assertOk()->json('settlement');
        $this->assertSame(['CALCULATED', 45000, 1, true, true, false, false, 'DISABLED'], [$shown['status'], $shown['summary']['net_payable']['amount'], count($shown['lines']), $shown['can']['approve'], $shown['can']['cancel'], $shown['can']['start_payout'], $shown['can']['record_external_payout'], $shown['live_payouts']]);
        $this->assertSame(['kind' => 'SETTLEMENT_STATEMENT', 'tax_invoice' => false], $shown['document']);

        // No amount, line or status can be sent: the totals are sums, and "paid" does not exist as an action.
        $this->financePost($url.'/approve', ['net_payable_minor' => 99999999, 'status' => 'PAID', 'paid_at' => now()->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors(['net_payable_minor', 'status', 'paid_at'], 'error.details.fields');
        foreach (['mark-paid', 'paid', 'pay', 'status', 'settle', 'complete'] as $action) {
            $this->financePost($url.'/'.$action, ['reason' => 'x'])->assertNotFound();
        }
        $this->act();
        $this->patchJson('/api/v1/admin/finance'.$url, ['net_payable_minor' => 1])->assertStatus(405);
        $this->deleteJson('/api/v1/admin/finance'.$url)->assertStatus(405);

        $approved = $this->financePost($url.'/approve', ['note' => 'Checked against the orders', 'expected_version' => 1])->assertOk()->json('settlement');
        $this->assertSame(['APPROVED', 2, 'Checked against the orders', false], [$approved['status'], $approved['version'], $approved['approval_note'], $approved['can']['approve']]);
        // Approving again is approving once; approving sent no money.
        $this->financePost($url.'/approve')->assertOk()->assertJsonPath('settlement.version', 2);
        $this->assertSame([1, 0], [AuditEvent::query()->where('action', 'finance.settlement_approved')->count(), RestaurantPayout::query()->count()]);

        // The lines of a statement cannot be changed or removed — not even at the database.
        $line = RestaurantSettlementLine::query()->firstOrFail();
        foreach (['update restaurant_settlement_lines set amount_minor = 1 where id = '.$line->id, 'delete from restaurant_settlement_lines where id = '.$line->id] as $sql) {
            DB::beginTransaction();
            try {
                DB::statement($sql);
                $this->fail('A settlement line was changed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
        // The statement's own arithmetic is the database's too: a total that is not the sum of its parts is refused.
        DB::beginTransaction();
        try {
            DB::statement('update restaurant_settlements set net_payable_minor = net_payable_minor + 1 where id = '.$s->id);
            $this->fail('A settlement total was typed in.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }

        // A later refund of an order in an approved settlement changes nothing in it: it is a new line for the next one.
        $this->assertSame([45000, 1], [$s->refresh()->net_payable_minor, $s->lines()->count()]);
    }

    public function test_a_settlement_that_no_longer_agrees_with_the_ledger_cannot_be_approved(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $this->generateSettlements();
        $s = $this->settlementOf();
        // Something outside the application changed a payable line (the application itself cannot).
        DB::table('restaurant_payable_items')->where('settlement_id', $s->id)->update(['amount_minor' => 44000, 'commission_minor' => 6000]);

        $this->financePost('/settlements/'.$s->public_id.'/approve')->assertStatus(409)->assertJsonPath('error.code', 'settlement_review_required');
        $this->assertSame([SettlementStatus::ReviewRequired, 'LINE_NOT_BACKED_BY_ITS_PAYABLE'], [$s->refresh()->status, $s->review_reason]);
        $this->assertSame(1, ReconciliationDifference::query()->where('type', DifferenceType::PayableMismatch->value)->where('status', 'OPEN')->count());
        $this->financePost('/settlements/'.$s->public_id.'/approve')->assertStatus(409)->assertJsonPath('error.code', 'settlement_not_approvable');
        $this->financeGet('/settlements/'.$s->public_id)->assertOk()->assertJsonPath('settlement.can.approve', false)->assertJsonPath('settlement.can.start_payout', false);
    }

    public function test_a_negative_total_is_never_a_settlement_it_is_carried_forward_and_finance_is_told(): void
    {
        // The restaurant earned ₹450 and bears a ₹500 refund: it owes ₹50.
        config(['finance.refund_liability.by_reason' => ['ITEM_UNAVAILABLE' => ['RESTAURANT', null]]]);
        $this->refundedCompletedOrder(null, 'ITEM_UNAVAILABLE');
        $this->assertSame(-5000, $this->payableBalance());

        $this->nextWeek();
        $result = $this->generateSettlements()['results'][0];
        $this->assertSame(['NEGATIVE_CARRIED_FORWARD', -5000, null], [$result['outcome'], $result['net_payable']['amount'], $result['settlement']]);
        // No settlement, no payout, no debit: the lines stay where they are, for the next period.
        $this->assertSame([0, 0, 2], [RestaurantSettlement::query()->count(), RestaurantPayout::query()->count(), RestaurantPayableItem::query()->where('status', 'AVAILABLE')->count()]);
        $difference = ReconciliationDifference::query()->where('type', DifferenceType::NegativeRestaurantBalance->value)->firstOrFail();
        $this->assertSame(['OPEN', 'INTERNAL', -5000, 'CARRY_FORWARD'], [$difference->status->value, $difference->source, $difference->actual_minor, $difference->detail['policy']]);
        $this->assertStringContainsString('FINANCE REVIEW REQUIRED', $difference->detail['note']);
        $payables = $this->financeGet('/payables')->assertOk()->json('data.0');
        $this->assertSame([-5000, true, true], [$payables['balance']['amount'], $payables['negative'], $payables['finance_review_required']]);

        // New earnings cover it: the next settlement carries the old lines, and the finding closes by itself.
        $this->clock('2026-10-13 12:00');
        $this->completedOrderWith();
        $this->asFinance();
        $this->clock('2026-10-19 09:00');
        $this->assertSame('SETTLEMENT', $this->generateSettlements()['results'][0]['outcome']);
        $s = $this->settlementOf();
        $this->assertSame([3, 2, 100000, 10000, -50000, 40000], [$s->line_count, $s->order_count, $s->gross_sales_minor, $s->commission_minor, $s->refund_adjustments_minor, $s->net_payable_minor]);
        $this->assertSame(['RESOLVED', 'AUTO_MATCHED'], [$difference->refresh()->status->value, $difference->resolution]);
        // The database refuses a negative settlement whatever the code does.
        DB::beginTransaction();
        try {
            DB::statement('update restaurant_settlements set net_payable_minor = -1, gross_sales_minor = gross_sales_minor - 40001 where id = '.$s->id);
            $this->fail('A negative settlement was stored.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_zero_total_is_a_closed_statement_with_nothing_to_pay(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        // A manual debit of exactly what was earned: the lines add up to zero.
        $this->manualCredit($this->burgerHub, 45000, 'DEBIT_RESTAURANT')->assertCreated()->assertJsonPath('adjustment.status', 'POSTED');
        $this->assertSame(0, $this->payableBalance());
        $this->nextWeek();
        $this->clock('2026-10-19 09:00');

        $this->assertSame('ZERO_STATEMENT', $this->generateSettlements()['results'][0]['outcome']);
        $s = $this->settlementOf();
        $this->assertSame([SettlementStatus::Closed, 0, 2, -45000], [$s->status, $s->net_payable_minor, $s->line_count, $s->other_adjustments_minor]);
        // Nothing to approve and nothing to pay — by any route.
        $this->financePost('/settlements/'.$s->public_id.'/approve')->assertStatus(409);
        $this->fakePayouts();
        $this->payoutProfile();
        $this->financePost('/settlements/'.$s->public_id.'/payouts', ['confirm' => true])->assertStatus(409)->assertJsonPath('error.code', 'settlement_not_payable');
        $this->financePost('/settlements/'.$s->public_id.'/external-payouts', ['confirm' => true, 'external_reference' => 'UTR0000001', 'paid_on' => '2026-10-19', 'amount_minor' => 1, 'currency' => 'INR', 'reason' => 'TEST FIXTURE: nothing to pay', 'evidence_reference' => 'DOC-1'])->assertStatus(409);
        $this->assertSame(0, RestaurantPayout::query()->count());
    }

    public function test_a_withdrawn_settlement_frees_its_lines_and_holds_keep_lines_and_locations_out(): void
    {
        $order = $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $this->generateSettlements();
        $s = $this->settlementOf();

        $this->financePost('/settlements/'.$s->public_id.'/cancel', ['reason' => 'short'])->assertStatus(422);
        $this->financePost('/settlements/'.$s->public_id.'/cancel', ['reason' => 'TEST FIXTURE: generated too early'])->assertOk()->assertJsonPath('settlement.status', 'CANCELLED');
        $this->assertSame(['AVAILABLE', null], [$this->earningOf($order)->status, $this->earningOf($order)->settlement_id]);
        // Its lines stay as its history; the earning is settled by the next run — once.
        $this->assertSame(1, $s->lines()->count());

        // A person holds the whole location: nothing of it is settled.
        $hold = $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'reason' => 'COMPLIANCE', 'note' => 'TEST FIXTURE: documents are being checked'])->assertCreated()->json('hold');
        $this->assertSame(['LOCATION', 'COMPLIANCE', 'ACTIVE', 'ADMIN'], [$hold['scope'], $hold['reason'], $hold['status'], $hold['source']]);
        $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'reason' => 'COMPLIANCE', 'note' => 'TEST FIXTURE: the same hold again'])->assertStatus(409);
        $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'reason' => 'COMPLIANCE'])->assertStatus(422)->assertJsonValidationErrors('note', 'error.details.fields');
        $this->assertSame('LOCATION_HELD', $this->generateSettlements()['results'][0]['outcome']);
        $payable = $this->financeGet('/payables')->assertOk()->json('data.0');
        $this->assertSame([45000, 0, 45000, true], [$payable['balance']['amount'], $payable['available']['amount'], $payable['held']['amount'], $payable['location_held']]);

        $this->financePost('/holds/'.$hold['id'].'/release', ['reason' => 'TEST FIXTURE: documents are in order'])->assertOk()->assertJsonPath('hold.status', 'RELEASED');
        // A hold on ONE line keeps only that line out.
        $line = $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'line' => (string) $this->earningOf($order)->public_id, 'reason' => 'MANUAL_REVIEW', 'note' => 'TEST FIXTURE: this order is being looked at'])->assertCreated()->json('hold');
        $this->assertSame(['ITEM', $order->order_number], [$line['scope'], $line['line']['order_number']]);
        $this->assertSame('NOTHING_TO_SETTLE', $this->generateSettlements()['results'][0]['outcome']);
        $this->financePost('/holds/'.$line['id'].'/release', ['reason' => 'TEST FIXTURE: nothing wrong with it'])->assertOk();
        $this->assertSame('SETTLEMENT', $this->generateSettlements()['results'][0]['outcome']);
        $this->assertSame(2, RestaurantSettlement::query()->count());
        $this->assertSame([2, 2], [AuditEvent::query()->where('action', 'finance.hold_placed')->count(), AuditEvent::query()->where('action', 'finance.hold_released')->count()]);

        // Holds need their own permission.
        $this->adminFor([Permission::AdminFinanceView, Permission::AdminSettlementsView]);
        $this->financeGet('/holds')->assertOk()->assertJsonCount(2, 'data');
        $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'reason' => 'MANUAL_REVIEW', 'note' => 'TEST FIXTURE: without the permission'])->assertForbidden();
    }

    public function test_currencies_are_never_added_together_each_currency_is_its_own_settlement(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        // A second currency for the same location can only come from data nobody should have: it still must not mix.
        $item = RestaurantPayableItem::query()->firstOrFail();
        $usd = app(LedgerPostingService::class);
        $posted = $usd->post(LedgerDraft::for('test:usd', LedgerTransactionType::ManualAdjustment, 'USD', now())->source('order', (int) $item->order_id)
            ->debit($usd->platform(LedgerAccountCode::PlatformAdjustments, 'USD'), 700, 'TEST')->credit($usd->payable($this->burgerHub, 'USD'), 700, 'TEST'));
        (new RestaurantPayableItem)->forceFill(['source_key' => 'test:usd-line', 'type' => 'MANUAL_ADJUSTMENT', 'organization_id' => $item->organization_id, 'location_id' => $item->location_id, 'market_id' => $item->market_id,
            'currency' => 'USD', 'amount_minor' => 700, 'ledger_transaction_id' => $posted->id, 'description_code' => 'TEST', 'status' => 'AVAILABLE', 'available_at' => now()])->save();

        $this->nextWeek();
        $results = $this->generateSettlements()['results'];
        $this->assertSame([['INR', 45000], ['USD', 700]], array_map(fn (array $r): array => [$r['currency'], $r['net_payable']['amount']], $results));
        $this->assertSame(['INR' => 45000, 'USD' => 700], RestaurantSettlement::query()->orderBy('currency')->pluck('net_payable_minor', 'currency')->map(fn ($v): int => (int) $v)->all());
        foreach (RestaurantSettlement::query()->get() as $s) {
            $this->assertSame([$s->currency], $s->lines()->pluck('currency')->unique()->values()->all());
        }
        $this->assertSame([45000, 700], [$this->payableBalance(), $this->payableBalance(currency: 'USD')]);
        $summary = $this->financeGet('/overview')->assertOk()->json('currencies');
        $this->assertSame(['INR', 'USD'], array_column($summary, 'currency'));
    }

    public function test_generating_approving_and_withdrawing_each_need_their_own_permission_and_the_four_eyes_rule_holds(): void
    {
        $this->completedOrderWith();
        $this->nextWeek();
        $viewer = $this->adminFor([Permission::AdminSettlementsView]);
        $this->financePost('/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id])->assertForbidden();
        $this->financeGet('/settlements')->assertOk()->assertJsonCount(0, 'data');

        $maker = $this->adminFor([Permission::AdminSettlementsView, Permission::AdminFinanceSettlementsManage, Permission::AdminFinanceSettlementsApprove]);
        $this->generateSettlements();
        $s = $this->settlementOf();
        $this->assertSame(['ADMIN', (int) $maker->id], [$s->generated_by_type, (int) $s->generated_by_admin_id]);

        $this->asActor($viewer);
        $list = $this->financeGet('/settlements')->assertOk()->json();
        $this->assertSame([1, 1, 0], [count($list['data']), $list['queues']['calculated'], $list['queues']['approved']]);
        $this->financePost('/settlements/'.$s->public_id.'/approve')->assertForbidden();
        $this->financePost('/settlements/'.$s->public_id.'/cancel', ['reason' => 'TEST FIXTURE: without the permission'])->assertForbidden();

        // With the four-eyes policy, who generated a settlement cannot approve it.
        config(['finance.settlements.four_eyes' => true]);
        $this->asActor($maker);
        $this->financePost('/settlements/'.$s->public_id.'/approve')->assertForbidden()->assertJsonPath('error.code', 'four_eyes');
        $this->adminFor([Permission::AdminSettlementsView, Permission::AdminFinanceSettlementsApprove]);
        $this->financePost('/settlements/'.$s->public_id.'/approve')->assertOk()->assertJsonPath('settlement.status', 'APPROVED');
        $this->assertSame(SettlementStatus::Approved, $s->refresh()->status);
    }
}
