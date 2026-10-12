<?php

namespace Tests\Support;

use App\Enums\LedgerAccountCode;
use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\CommissionPlan;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\OrderFinancialAllocation;
use App\Models\Refund;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantPayoutProfile;
use App\Models\RestaurantSettlement;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Payout\FakePayoutProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Finance fixtures for tests (Module 36), on top of the refund, order, payment and checkout fixtures.
 *
 * EVERY RATE HERE IS A TEST FIXTURE. "1000 basis points = 10 %" is the specification's example for a test — it is
 * not FoodOnTheGo's commission, and nothing in the application code knows the number.
 *
 * No test writes a ledger entry, a settlement total or a payout status by hand: everything moves through the real
 * path — a paid order completed by the pickup verification, a refund confirmed by the (fake) provider, a payout
 * confirmed by the (fake) payout provider.
 */
trait BuildsFinance
{
    use BuildsRefunds;

    /** Every finance permission, with what the surrounding screens need. */
    protected const FINANCE_ADMIN = [
        Permission::AdminFinanceView, Permission::AdminFinanceCommissionView, Permission::AdminFinanceCommissionManage,
        Permission::AdminSettlementsView, Permission::AdminFinanceSettlementsManage, Permission::AdminFinanceSettlementsApprove,
        Permission::AdminFinancePayoutsView, Permission::AdminFinancePayoutsExecute, Permission::AdminFinancePayoutsRecordExternal,
        Permission::AdminFinanceReconciliationView, Permission::AdminFinanceReconciliationManage, Permission::AdminFinanceAdjustmentsCreate,
        Permission::AdminFinanceHoldsManage, Permission::AdminFinanceLedgerView, Permission::AdminFinanceExport,
        Permission::AdminRefundsView, Permission::AdminRefundsCreate, Permission::AdminRefundsApprove, Permission::AdminRefundsIssue, Permission::AdminRefundsReconcile,
        Permission::AdminDisputesView, Permission::AdminOrdersView, Permission::AdminOrdersOverride, Permission::AdminPaymentsView, Permission::AdminSystemView,
    ];

    /**
     * A TEST FIXTURE commission plan, in force since before the test's clock.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function commissionPlan(int $rateBps = 1000, string $base = 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', ?RestaurantLocation $location = null, bool $organization = false, array $attributes = []): CommissionPlan
    {
        $scope = $location !== null && ! $organization ? CommissionPlan::SCOPE_LOCATION : ($organization ? CommissionPlan::SCOPE_ORGANIZATION : CommissionPlan::SCOPE_MARKET);
        $plan = new CommissionPlan;
        $plan->forceFill([
            'name' => 'TEST FIXTURE '.$rateBps.' bps ('.strtolower($scope).')',
            'scope_type' => $scope,
            'market_id' => $this->market->getKey(),
            'organization_id' => $scope === CommissionPlan::SCOPE_MARKET ? null : ($location?->organization_id ?? $this->organization->getKey()),
            'location_id' => $scope === CommissionPlan::SCOPE_LOCATION ? $location->getKey() : null,
            'type' => 'PERCENTAGE',
            'rate_bps' => $rateBps,
            'base_policy' => $base,
            'effective_from' => CarbonImmutable::parse('2026-01-01 00:00', 'Asia/Kolkata'),
            'approval' => CommissionPlan::APPROVAL_TEST,
            'source' => CommissionPlan::SOURCE_FIXTURE,
            'reason' => 'TEST FIXTURE — not a production commission.',
            ...$attributes,
        ])->save();

        return $plan;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    protected function financeAdmin(array $permissions = self::FINANCE_ADMIN): AdminUser
    {
        return $this->adminFor($permissions);
    }

    protected function financeGet(string $path = ''): TestResponse
    {
        return $this->adminGet('/finance'.$path);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function financePost(string $path, array $body = []): TestResponse
    {
        return $this->adminPost('/finance'.$path, $body);
    }

    protected function allocationOf(Order|string $order): OrderFinancialAllocation
    {
        return OrderFinancialAllocation::query()->where('order_number', $order instanceof Order ? $order->order_number : $order)->firstOrFail();
    }

    protected function earningOf(Order|string $order): RestaurantPayableItem
    {
        return RestaurantPayableItem::query()->where('order_number', $order instanceof Order ? $order->order_number : $order)->where('type', RestaurantPayableItem::TYPE_ORDER_EARNING)->firstOrFail();
    }

    protected function posting(string $key): ?LedgerTransaction
    {
        return LedgerTransaction::query()->with('entries.account')->where('posting_key', $key)->first();
    }

    /**
     * A platform account's balance on its own side (0 when nothing was ever posted to it).
     */
    protected function platformBalance(LedgerAccountCode $code, string $currency = 'INR'): int
    {
        $account = LedgerAccount::query()->where('code', $code->value)->where('currency', $currency)->where('owner_type', LedgerAccount::OWNER_PLATFORM)->first();

        return $account === null ? 0 : app(LedgerPostingService::class)->balance($account);
    }

    protected function providerClearing(string $provider = 'fake', string $currency = 'INR'): int
    {
        $account = LedgerAccount::query()->where('code', LedgerAccountCode::ProviderClearing->value)->where('currency', $currency)->where('provider', $provider)->first();

        return $account === null ? 0 : app(LedgerPostingService::class)->balance($account);
    }

    protected function payableBalance(?RestaurantLocation $location = null, string $currency = 'INR'): int
    {
        $account = app(LedgerPostingService::class)->payableOf((int) ($location ?? $this->burgerHub)->getKey(), $currency);

        return $account === null ? 0 : app(LedgerPostingService::class)->balance($account);
    }

    /**
     * The lines of a posting as "D ACCOUNT amount" / "C ACCOUNT amount", in the order they were posted.
     *
     * @return list<string>
     */
    protected function linesOf(string $key): array
    {
        return $this->posting($key)?->entries->map(fn ($e): string => $e->direction.' '.$e->account->code->value.' '.$e->amount_minor)->all() ?? [];
    }

    /**
     * Every transaction in the ledger balances, per currency — asked of the database itself.
     */
    protected function assertLedgerBalanced(): void
    {
        $unbalanced = DB::table('ledger_entries')->selectRaw("ledger_transaction_id, currency, sum(case direction when 'D' then amount_minor else -amount_minor end) as difference")
            ->groupBy('ledger_transaction_id', 'currency')->havingRaw("sum(case direction when 'D' then amount_minor else -amount_minor end) <> 0")->get();
        $this->assertCount(0, $unbalanced, 'every ledger transaction balances');
        $this->assertSame(0, (int) DB::table('ledger_entries as e')->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')->whereColumn('e.currency', '<>', 't.currency')->count());
    }

    /**
     * A TEST FIXTURE payout destination at the fake payout provider — a token and a mask, never bank details.
     */
    protected function payoutProfile(?RestaurantLocation $location = null, string $status = RestaurantPayoutProfile::VERIFIED): RestaurantPayoutProfile
    {
        $location ??= $this->burgerHub;
        $profile = new RestaurantPayoutProfile;
        $profile->forceFill([
            'organization_id' => $location->organization_id,
            'location_id' => $location->getKey(),
            'provider' => FakePayoutProvider::NAME,
            'provider_account_reference' => 'fa_test_'.substr(md5((string) $location->public_id), 0, 14),
            'destination_mask' => '•••• 4321',
            'status' => $status,
            'provider_status' => 'activated',
            'verified_at' => $status === RestaurantPayoutProfile::VERIFIED ? now() : null,
        ])->save();

        return $profile;
    }

    protected function settlementOf(?RestaurantLocation $location = null): RestaurantSettlement
    {
        return RestaurantSettlement::query()->where('location_id', ($location ?? $this->burgerHub)->getKey())->orderByDesc('id')->firstOrFail();
    }

    /**
     * The fake payout provider is the configured one (it never is by default: PAYOUT_DRIVER is empty).
     */
    protected function fakePayouts(): FakePayoutProvider
    {
        config(['finance.payouts.driver' => FakePayoutProvider::NAME]);

        return new FakePayoutProvider;
    }

    /**
     * The week is over: the clock moves to the next Monday morning, so that the default cutoff (the end of the last
     * finished week, in the market's time zone) lies after everything the test did so far.
     */
    protected function nextWeek(string $at = '2026-10-12 09:00'): void
    {
        $this->clock($at);
    }

    /**
     * Generates the settlements that are due for a location, through the administrators' API.
     *
     * @return array<string, mixed> the API's answer
     */
    protected function generateSettlements(?RestaurantLocation $location = null, bool $dryRun = false): array
    {
        return $this->financePost('/settlements/generate', ['restaurant' => (string) ($location ?? $this->burgerHub)->public_id, 'dry_run' => $dryRun])->assertSuccessful()->json();
    }

    /**
     * A settlement that was generated and approved (by the signed-in finance administrator).
     */
    protected function approvedSettlement(?RestaurantLocation $location = null): RestaurantSettlement
    {
        $this->generateSettlements($location);
        $settlement = $this->settlementOf($location);
        $this->financePost('/settlements/'.$settlement->public_id.'/approve', ['note' => 'Checked against the orders'])->assertOk()->assertJsonPath('settlement.status', 'APPROVED');

        return $settlement->refresh();
    }

    /**
     * A manual credit for a location: a real payable line without an order (for locations the order fixtures do not reach).
     */
    protected function manualCredit(RestaurantLocation $location, int $amountMinor = 30000, string $direction = 'CREDIT_RESTAURANT'): TestResponse
    {
        return $this->financePost('/adjustments', [
            'restaurant' => (string) $location->public_id, 'direction' => $direction, 'amount_minor' => $amountMinor, 'currency' => (string) $location->currency,
            'reason_code' => 'CORRECTION', 'reason' => 'TEST FIXTURE: a correction agreed in writing.', 'supporting_reference' => 'TICKET-'.random_int(1000, 9999),
        ]);
    }

    /**
     * The restaurant dashboard's finance API, as the signed-in staff member.
     */
    protected function restaurantFinance(string $path, ?RestaurantLocation $location = null, string $query = ''): TestResponse
    {
        $this->act();
        $parts = array_filter([$location === null ? '' : 'location='.$location->public_id, $query]);

        return $this->get('/api/v1/restaurant/finance'.$path.($parts === [] ? '' : '?'.implode('&', $parts)), ['Accept' => 'application/json']);
    }

    private ?AdminUser $financeActor = null;

    /**
     * The finance administrator is the actor again (creating an order makes the order staff the actor).
     */
    protected function asFinance(): AdminUser
    {
        $this->financeActor ??= $this->adminWith(self::FINANCE_ADMIN);

        return $this->asActor($this->financeActor);
    }

    /**
     * A paid order with a checkout of its own (a promotion code, a quantity), made, collected and completed.
     *
     * @param  array<string, mixed>  $checkoutBody  e.g. ['promotion_code' => 'SAVE100']
     */
    protected function completedOrderWith(array $checkoutBody = [], int $quantity = 2, ?string $phone = null): Order
    {
        [, $headers] = $this->customerReadyForCheckout($quantity, ['Size' => ['Large']], $this->nextFinanceSlot(), $phone ?? $this->nextPhone());
        $draft = $this->openCheckout($headers, $checkoutBody)->assertCreated()->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->assertJsonPath('checkout.status', 'READY')->json('checkout');
        $payment = $this->startPayment($headers, $ready)->assertCreated()->json('payment');
        $verified = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->json('payment');
        $number = $verified['fulfillment']['order_number'];
        $this->orderStaff();
        foreach (['accept', 'start-preparing', 'ready'] as $step) {
            $this->step($number, $step)->assertOk();
        }
        $this->verifyQr($this->credentialOf($headers, $number)['qr_payload'])->assertOk()->assertJsonPath('order.status', 'COMPLETED');

        return $this->orderModel($number);
    }

    private int $financeSlot = 0;

    private int $financePhone = 0;

    /** A phone number no other fixture of the test uses: one customer per order. */
    protected function nextPhone(): string
    {
        return '+9198765440'.str_pad((string) (10 + $this->financePhone++), 2, '0', STR_PAD_LEFT);
    }

    private function nextFinanceSlot(): string
    {
        $slots = ['16:00', '16:15', '16:30', '16:45', '17:00', '17:15', '17:30', '17:45', '18:00', '18:15'];

        return $slots[$this->financeSlot++ % count($slots)];
    }

    /**
     * A completed, paid order whose refund the (fake) provider confirmed — the whole payment, or a part.
     *
     * @return array{0: Order, 1: Refund}
     */
    protected function refundedCompletedOrder(?int $partMinor = null, string $reason = 'OTHER'): array
    {
        [, , $order] = $this->completedPaidOrder($this->nextPhone());
        $this->asFinance();
        $this->askRefund($order->order_number, $partMinor === null
            ? ['type' => 'FULL', 'reason_code' => $reason, 'note' => 'TEST FIXTURE: the customer complained.']
            : ['type' => 'PARTIAL', 'amount_minor' => $partMinor, 'reason_code' => $reason, 'note' => 'TEST FIXTURE: one item was wrong.'])->assertCreated();
        $refund = $this->refundOf($order->order_number);
        $this->approveAndExecute($refund);
        $this->providerSendsRefund('refund.processed', $this->providerSettlesRefund($refund))->assertOk();

        return [$order->refresh(), $refund->refresh()];
    }
}
