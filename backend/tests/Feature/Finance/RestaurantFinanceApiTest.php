<?php

namespace Tests\Feature\Finance;

use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\SettlementStatus;
use App\Models\AuditEvent;
use App\Models\FinancialAdjustment;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayout;
use App\Models\RestaurantSettlement;
use App\Support\Csv;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — the restaurant dashboard's finance API: a restaurant reads its OWN earnings, statements and payouts.
 *
 * Nothing here can change an amount. Another restaurant's data does not exist for the reader; a location outside the
 * membership neither. The 10 % commission is a TEST FIXTURE.
 */
class RestaurantFinanceApiTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    private RestaurantLocation $pizzaPoint;

    private RestaurantLocation $secondKitchen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
        // A second location of the same organization, and another organization altogether.
        [$this->pizzaPoint] = $this->otherRestaurant();
        $this->secondKitchen = $this->eligible('Second Kitchen', 28.57, 77.37, organization: $this->organization('Second Kitchen'));
    }

    public function test_the_dashboard_reads_real_gross_commission_adjustments_net_pending_available_and_paid(): void
    {
        // One order is still being prepared (pending), two are completed, one of them with a refund the restaurant bears in part.
        [, , , , $open] = $this->paidOrder($this->nextPhone(), '15:00');
        $done = $this->completedOrderWith();
        [$refunded] = $this->refundedCompletedOrder();
        $this->financePost('/adjustments/'.FinancialAdjustment::query()->firstOrFail()->public_id.'/decide', ['restaurant_share_minor' => 10000, 'reason' => 'TEST FIXTURE: one item was the kitchen\'s mistake'])->assertOk();

        $this->asActor($this->member($this->organization, 'OWNER'));
        $summary = $this->restaurantFinance('/summary', $this->burgerHub)->assertOk()->json();
        $inr = $summary['currencies'][0];
        // Pending: the order that is not completed — an estimate, not an earning. Available: what was earned.
        $this->assertSame(['INR', 45000, 1, 80000, 0, 0, 0, 80000], [$inr['currency'], $inr['pending']['amount'], $inr['pending_orders'], $inr['available']['amount'], $inr['held']['amount'], $inr['in_settlement']['amount'], $inr['paid_out']['amount'], $inr['balance']['amount']]);
        $this->assertSame(['orders' => 2, 'gross_sales' => ['amount' => 100000, 'currency' => 'INR'], 'restaurant_discount' => ['amount' => 0, 'currency' => 'INR'], 'commission' => ['amount' => 10000, 'currency' => 'INR'],
            'adjustments' => ['amount' => -10000, 'currency' => 'INR'], 'net_earnings' => ['amount' => 80000, 'currency' => 'INR']], $inr['periods']['today']);
        $this->assertSame($inr['periods']['today'], $inr['periods']['this_month']);
        $this->assertSame(['Asia/Kolkata', false, 'ORDER_COMPLETED', 'PENDING BUSINESS APPROVAL'], [$summary['timezone'], $summary['payouts']['live'], $summary['settlement_policy']['earnings_available_when'], $summary['settlement_policy']['approval']]);
        $this->assertSame([['day' => '2026-10-07', 'currency' => 'INR', 'orders' => 2, 'net_earnings' => ['amount' => 80000, 'currency' => 'INR']]], array_map(fn (array $d): array => array_intersect_key($d, array_flip(['day', 'currency', 'orders', 'net_earnings'])), $summary['series']));
        // Every figure is explained in words, so a screen cannot call gross sales "earnings".
        $this->assertSame(['gross_sales', 'commission', 'adjustments', 'net_earnings', 'pending', 'available', 'held', 'in_settlement', 'paid_out', 'balance'], array_keys($summary['definitions']));

        // The lines behind the figures: order numbers and amounts — never a customer.
        $lines = $this->restaurantFinance('/earnings', $this->burgerHub)->assertOk()->json('data');
        $this->assertCount(3, $lines);
        $earning = collect($lines)->firstWhere('order_number', $done->order_number);
        $this->assertSame(['ORDER_EARNING', 50000, 5000, 45000, 'AVAILABLE', null], [$earning['type'], $earning['gross_sales']['amount'], $earning['commission']['amount'], $earning['amount']['amount'], $earning['state'], $earning['settlement']]);
        $this->assertSame(-10000, collect($lines)->firstWhere('type', 'REFUND_ADJUSTMENT')['amount']['amount']);
        $this->assertCount(1, $this->restaurantFinance('/earnings', $this->burgerHub, 'type=REFUND_ADJUSTMENT')->assertOk()->json('data'));
        $everything = json_encode([$summary, $lines]);
        foreach (['customer', 'phone', '+9198', 'email', 'payment_id', 'provider_payment', 'fa_test_', 'approved_by', 'ledger_transaction', 'posting_key'] as $private) {
            $this->assertStringNotContainsString($private, $everything, $private);
        }

        // The statement and the payout, once they exist.
        $this->asFinance();
        $this->nextWeek();
        $this->fakePayouts()->payoutsBecome('processed');
        $this->payoutProfile();
        $settlement = $this->approvedSettlement();
        $this->asActor($owner = $this->member($this->organization, 'OWNER'));
        $inr = $this->restaurantFinance('/summary', $this->burgerHub)->assertOk()->json('currencies.0');
        $this->assertSame([0, 80000, 0, 80000], [$inr['available']['amount'], $inr['in_settlement']['amount'], $inr['paid_out']['amount'], $inr['balance']['amount']]);
        $listed = $this->restaurantFinance('/settlements', $this->burgerHub)->assertOk()->json('data.0');
        $this->assertSame([$settlement->reference, 'APPROVED', 2, 100000, 10000, -10000, 80000, 0, 80000, false], [$listed['reference'], $listed['status'], $listed['summary']['completed_orders'], $listed['summary']['gross_sales']['amount'],
            $listed['summary']['commission']['amount'], $listed['summary']['refund_adjustments']['amount'], $listed['summary']['net_payable']['amount'], $listed['summary']['amount_paid']['amount'], $listed['summary']['amount_pending']['amount'], $listed['document']['tax_invoice']]);
        $this->assertArrayNotHasKey('approved_by', $listed);
        // The restaurant reads its OWN words: the finance team's internal steps (CALCULATED, REVIEW_REQUIRED, FAILED,
        // UNKNOWN …) never reach it, and its filter speaks the same words.
        $this->assertCount(1, $this->restaurantFinance('/settlements', $this->burgerHub, 'status=APPROVED')->assertOk()->json('data'));
        $this->assertCount(0, $this->restaurantFinance('/settlements', $this->burgerHub, 'status=IN_REVIEW')->assertOk()->json('data'));
        $this->restaurantFinance('/settlements', $this->burgerHub, 'status=CALCULATED')->assertStatus(422);
        $this->restaurantFinance('/settlements', $this->burgerHub, 'status=REVIEW_REQUIRED')->assertStatus(422);
        $this->assertSame(['IN_REVIEW', 'IN_REVIEW', 'APPROVED', 'PAYMENT_PENDING', 'PAYMENT_PENDING', 'PAID', 'CLOSED'], array_map(fn (string $s): string => SettlementStatus::from($s)->forRestaurant(), ['CALCULATED', 'REVIEW_REQUIRED', 'APPROVED', 'PAYMENT_PENDING', 'FAILED', 'PAID', 'CLOSED']));
        $this->assertSame(['PROCESSING', 'PROCESSING', 'PROCESSING', 'PROCESSING', 'PROCESSING', 'PAID', 'FAILED'], array_map(fn (string $s): string => PayoutStatus::from($s)->forRestaurant(), ['REQUIRED', 'SUBMITTING', 'PROCESSING', 'UNKNOWN', 'REVIEW_REQUIRED', 'PAID', 'FAILED']));
        $detail = $this->restaurantFinance('/settlements/'.$settlement->public_id)->assertOk()->json('settlement');
        // The lines reconcile to the total.
        $this->assertSame([3, 80000, []], [count($detail['lines']), array_sum(array_map(fn (array $l): int => $l['amount']['amount'], $detail['lines'])), $detail['payouts']]);

        $this->asFinance();
        $this->financePost('/settlements/'.$settlement->public_id.'/payouts', ['confirm' => true])->assertCreated()->assertJsonPath('payout.status', 'PAID');
        $this->asActor($owner);
        // PAYOUT → DASHBOARD: paid goes up, pending and balance go down.
        $inr = $this->restaurantFinance('/summary', $this->burgerHub)->assertOk()->json('currencies.0');
        $this->assertSame([0, 0, 80000, 0], [$inr['available']['amount'], $inr['in_settlement']['amount'], $inr['paid_out']['amount'], $inr['balance']['amount']]);
        $paid = $this->restaurantFinance('/settlements/'.$settlement->public_id)->assertOk()->json('settlement');
        $this->assertSame(['PAID', 80000, 0, 'PAID', 80000], [$paid['status'], $paid['summary']['amount_paid']['amount'], $paid['summary']['amount_pending']['amount'], $paid['payouts'][0]['status'], $paid['payouts'][0]['amount']['amount']]);
        $payouts = $this->restaurantFinance('/payouts', $this->burgerHub)->assertOk()->json();
        $this->assertSame([1, 'PAID', '•••• 4321', false, 'VERIFIED', '•••• 4321', true], [count($payouts['data']), $payouts['data'][0]['status'], $payouts['data'][0]['destination'], $payouts['live_payouts'], $payouts['payout_accounts'][0]['status'], $payouts['payout_accounts'][0]['destination'], $payouts['payout_accounts'][0]['test_only']]);
        // A restaurant never sees the provider's references, who paid, or the token of its destination.
        foreach (['provider_payout_reference', 'requested_by', 'fa_test_', 'idempotency', 'provider_mode'] as $private) {
            $this->assertStringNotContainsString($private, json_encode([$payouts, $paid]), $private);
        }
        $this->assertSame('PAID', $this->restaurantFinance('/earnings', $this->burgerHub)->assertOk()->json('data.0.state'));
        $this->assertSame(1, RestaurantPayout::query()->count());
        unset($open, $refunded);
    }

    public function test_a_restaurant_never_reads_another_restaurants_finances(): void
    {
        $this->asFinance();
        $this->manualCredit($this->secondKitchen, 70000)->assertCreated();
        $this->manualCredit($this->burgerHub, 30000)->assertCreated();
        $this->commissionPlan(450, location: $this->secondKitchen);
        $this->nextWeek();
        $this->fakePayouts()->payoutsBecome('processed');
        $this->payoutProfile($this->secondKitchen);
        $theirs = $this->approvedSettlement($this->secondKitchen);
        $this->financePost('/settlements/'.$theirs->public_id.'/payouts', ['confirm' => true])->assertCreated();
        $mine = $this->approvedSettlement($this->burgerHub);

        // The owner of Riverside asks for Second Kitchen's data in every way there is.
        $this->asActor($this->member($this->organization, 'OWNER'));
        foreach (['/summary', '/earnings', '/settlements', '/payouts', '/terms'] as $path) {
            $this->restaurantFinance($path, $this->secondKitchen)->assertNotFound();
        }
        $this->restaurantFinance('/settlements/'.$theirs->public_id)->assertNotFound()->assertJsonPath('error.code', 'settlement_not_found');
        $this->restaurantFinance('/settlements/'.$theirs->public_id.'/export')->assertNotFound();
        $this->restaurantFinance('/earnings/export', $this->secondKitchen, 'from=2026-10-01&to=2026-10-12')->assertNotFound();
        // Without a location they get their own organization — and nothing of the other.
        $all = json_encode([
            $this->restaurantFinance('/summary')->assertOk()->json(), $this->restaurantFinance('/earnings')->assertOk()->json(), $this->restaurantFinance('/settlements')->assertOk()->json(),
            $this->restaurantFinance('/payouts')->assertOk()->json(), $this->restaurantFinance('/terms')->assertOk()->json(),
        ]);
        foreach (['Second Kitchen', (string) $this->secondKitchen->public_id, (string) $theirs->public_id, $theirs->reference, '70000', '"rate_bps":450'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $all, $foreign);
        }
        $this->assertStringContainsString($mine->reference, $all);
        // COMMISSION TERMS: their own (the market's standard) — never the other restaurant's negotiated 4.5 %.
        $terms = $this->restaurantFinance('/terms', $this->burgerHub)->assertOk()->json();
        $this->assertSame([true, 1000, 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 'MARKET_STANDARD', true, 'PLATFORM'], [$terms['visible'], $terms['terms'][0]['commission']['rate_bps'], $terms['terms'][0]['commission']['base_policy'], $terms['terms'][0]['commission']['applies_to'], $terms['terms'][0]['commission']['test_fixture'], $terms['provider_fees']['borne_by']]);
        $this->assertArrayNotHasKey('reason', $terms['terms'][0]['commission']);

        // And the other way round.
        $this->asActor($this->member($this->secondKitchen->organization()->firstOrFail(), 'OWNER'));
        $this->restaurantFinance('/settlements/'.$mine->public_id)->assertNotFound();
        $this->restaurantFinance('/summary', $this->burgerHub)->assertNotFound();
        $own = $this->restaurantFinance('/terms')->assertOk()->json('terms');
        $this->assertSame([1, 450, 'YOUR_RESTAURANT'], [count($own), $own[0]['commission']['rate_bps'], $own[0]['commission']['applies_to']]);
        $this->assertSame(70000, $this->restaurantFinance('/summary')->assertOk()->json('currencies.0.paid_out.amount'));

        // The terms can be switched off for restaurants altogether: the amounts stay, the rate does not.
        config(['finance.restaurant.terms_visible' => false]);
        $this->assertSame([false, null], [$this->restaurantFinance('/terms')->assertOk()->json('visible'), $this->restaurantFinance('/terms')->assertOk()->json('terms.0.commission')]);
    }

    public function test_a_member_limited_to_one_location_reads_only_that_location_and_the_owner_reads_all(): void
    {
        $this->asFinance();
        $this->manualCredit($this->burgerHub, 30000)->assertCreated();
        $this->manualCredit($this->pizzaPoint, 55000)->assertCreated();

        // MULTI-LOCATION: the owner sees the organization, filtered or added up per currency.
        $this->asActor($this->member($this->organization, 'OWNER'));
        $all = $this->restaurantFinance('/summary')->assertOk()->json();
        $this->assertSame([2, true, 85000], [count($all['scope']['locations']), $all['scope']['all_readable'], $all['currencies'][0]['available']['amount']]);
        $this->assertSame(55000, $this->restaurantFinance('/summary', $this->pizzaPoint)->assertOk()->json('currencies.0.available.amount'));
        $this->assertCount(2, $this->restaurantFinance('/earnings')->assertOk()->json('data'));

        // A manager of Burger Hub only: Pizza Point of the SAME organization does not exist for them.
        $this->asActor($this->member($this->organization, 'MANAGER', [$this->burgerHub]));
        $limited = $this->restaurantFinance('/summary')->assertOk()->json();
        $this->assertSame([[(string) $this->burgerHub->public_id], 30000], [array_column($limited['scope']['locations'], 'id'), $limited['currencies'][0]['available']['amount']]);
        $this->restaurantFinance('/summary', $this->pizzaPoint)->assertNotFound();
        $this->restaurantFinance('/earnings', $this->pizzaPoint)->assertNotFound();
        $this->assertCount(1, $this->restaurantFinance('/earnings')->assertOk()->json('data'));
        $this->assertStringNotContainsString('Pizza Point', json_encode([$limited, $this->restaurantFinance('/terms')->assertOk()->json(), $this->restaurantFinance('/payouts')->assertOk()->json()]));
    }

    public function test_finance_needs_its_permission_and_the_payout_destination_is_the_owners_to_see(): void
    {
        $this->asFinance();
        $this->manualCredit($this->burgerHub, 30000)->assertCreated();
        $this->payoutProfile();

        // The seeded roles: owner and manager read finances; order staff, menu manager and viewer do not.
        $roles = RoleSeeder::restaurantRoles();
        $has = fn (string $role, Permission $p): bool => in_array($p, $roles[$role][1], true);
        $this->assertSame([true, true, false, false, false], array_map(fn (string $r): bool => $has($r, Permission::RestaurantFinanceView), ['OWNER', 'MANAGER', 'ORDER_STAFF', 'MENU_MANAGER', 'VIEWER']));
        $this->assertSame([true, false, false], array_map(fn (string $r): bool => $has($r, Permission::RestaurantPayoutAccountView), ['OWNER', 'MANAGER', 'VIEWER']));
        foreach (Permission::for(PrincipalType::RestaurantUser) as $p) {
            // A restaurant only ever READS finance: no permission of theirs approves, pays, adjusts or manages it.
            $this->assertDoesNotMatchRegularExpression('/(finance|payout|settlement).*\.(manage|approve|execute|create|update)/', $p->value);
        }

        foreach (['ORDER_STAFF', 'MENU_MANAGER', 'VIEWER'] as $role) {
            $this->asActor($this->member($this->organization, $role));
            foreach (['/summary', '/earnings', '/settlements', '/payouts', '/terms'] as $path) {
                $this->restaurantFinance($path, $this->burgerHub)->assertForbidden();
                $this->restaurantFinance($path)->assertForbidden();
            }
        }
        // A manager reads the earnings and the payouts — not where the payouts go.
        $this->asActor($this->member($this->organization, 'MANAGER'));
        $this->restaurantFinance('/summary', $this->burgerHub)->assertOk();
        $this->assertSame([], $this->restaurantFinance('/payouts', $this->burgerHub)->assertOk()->json('payout_accounts'));
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->assertSame('•••• 4321', $this->restaurantFinance('/payouts', $this->burgerHub)->assertOk()->json('payout_accounts.0.destination'));

        // A customer and a guest have no finance API at all; an administrator's session is not a restaurant's.
        [, $customer] = $this->signedIn($this->nextPhone());
        foreach (['/api/v1/restaurant/finance/summary', '/api/v1/admin/finance/overview', '/api/v1/admin/finance/settlements', '/api/v1/admin/finance/ledger/transactions'] as $url) {
            $this->app['auth']->forgetGuards();
            $this->getJson($url, $customer)->assertUnauthorized();
            $this->app['auth']->forgetGuards();
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['/api/v1/customer/finance', '/api/v1/customer/earnings', '/api/v1/customer/settlements', '/api/v1/customer/commission'] as $url) {
            $this->getJson($url, $customer)->assertNotFound();
        }
        // Nothing in the restaurant's finance API can be written.
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->act();
        foreach (['/summary', '/earnings', '/settlements', '/payouts'] as $path) {
            $this->postJson('/api/v1/restaurant/finance'.$path, ['amount_minor' => 99999999, 'status' => 'PAID'])->assertStatus(405);
        }
    }

    public function test_exports_are_scoped_bounded_audited_and_safe_against_spreadsheet_formulas(): void
    {
        // CSV formula injection: a cell that would run as a formula is written as text; a number stays a number.
        $this->assertSame(['"\'=SUM(A1:A9)"', '"\'+91 98765"', '"\'-cmd|calc"', '"\'@import"', '"-450.00"', '"45000"', '"Burger ""Hub"""', '""', "\"'\tx\""],
            array_map(Csv::cell(...), ['=SUM(A1:A9)', '+91 98765', '-cmd|calc', '@import', '-450.00', 45000, 'Burger "Hub"', null, "\tx"]));
        $this->assertSame(['450.00', '-450.05', '0.07', '1200'], [Csv::amount(45000, 'INR'), Csv::amount(-45005, 'INR'), Csv::amount(7, 'INR'), Csv::amount(1200, 'JPY')]);

        // A restaurant whose NAME is a formula: its own statement must not run it.
        $this->burgerHub->forceFill(['name' => '=HYPERLINK("http://evil.example","Burger Hub")'])->save();
        $order = $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $settlement = $this->approvedSettlement();

        $this->asActor($this->member($this->organization, 'OWNER'));
        $statement = $this->restaurantFinance('/settlements/'.$settlement->public_id.'/export')->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $statement->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="settlement-'.$settlement->reference.'.csv"', (string) $statement->headers->get('Content-Disposition'));
        $csv = (string) $statement->getContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil.example"",""Burger Hub"")"', $csv);
        $this->assertStringNotContainsString("\n\"=", $csv);
        $this->assertStringNotContainsString(',"=', $csv);
        // It says what it is — and that it is not a tax invoice.
        $this->assertStringContainsString('this is not a tax invoice', $csv);
        $this->assertStringContainsString('"'.$order->order_number.'","500.00","0.00","50.00","450.00"', $csv);
        $this->assertStringNotContainsString('+9198', $csv);

        // The earnings export: a period is required and bounded; the rows are the reader's own.
        $this->restaurantFinance('/earnings/export', $this->burgerHub)->assertStatus(422);
        $this->restaurantFinance('/earnings/export', $this->burgerHub, 'from=2026-01-01&to=2026-10-12')->assertStatus(422)->assertJsonPath('error.code', 'range_too_long');
        $this->restaurantFinance('/earnings/export', $this->burgerHub, 'from=2026-10-12&to=2026-10-01')->assertStatus(422);
        $export = (string) $this->restaurantFinance('/earnings/export', $this->burgerHub, 'from=2026-10-01&to=2026-10-12')->assertOk()->getContent();
        $this->assertStringContainsString('"ORDER_EARNING","'.$order->order_number.'","INR","500.00","0.00","50.00","450.00","IN_SETTLEMENT","'.$settlement->reference.'"', $export);
        $this->assertSame(2, AuditEvent::query()->where('action', 'finance.exported')->where('actor_type', 'restaurant_user')->count());

        // Without the finance permission there is no export; the administrators' export needs its own permission.
        $this->asActor($this->member($this->organization, 'VIEWER'));
        $this->restaurantFinance('/settlements/'.$settlement->public_id.'/export')->assertForbidden();
        $this->restaurantFinance('/earnings/export', $this->burgerHub, 'from=2026-10-01&to=2026-10-12')->assertForbidden();
        $this->adminFor([Permission::AdminSettlementsView]);
        $this->act();
        $this->get('/api/v1/admin/finance/settlements/'.$settlement->public_id.'/export', ['Accept' => 'application/json'])->assertForbidden();
        $this->asFinance();
        $this->act();
        $admin = (string) $this->get('/api/v1/admin/finance/settlements/'.$settlement->public_id.'/export')->assertOk()->getContent();
        $this->assertStringContainsString('"\'=HYPERLINK(', $admin);
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.exported')->where('actor_type', 'admin_user')->count());
        $this->assertSame(1, RestaurantSettlement::query()->count());
    }
}
