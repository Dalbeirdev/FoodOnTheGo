<?php

namespace Tests\Feature\Finance;

use App\Enums\DifferenceType;
use App\Enums\LedgerAccountCode;
use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Enums\ProviderFailure;
use App\Enums\SettlementStatus;
use App\Jobs\Finance\SubmitPayoutJob;
use App\Models\AuditEvent;
use App\Models\FinancialAdjustment;
use App\Models\LedgerTransaction;
use App\Models\PayoutWebhookEvent;
use App\Models\ReconciliationDifference;
use App\Models\Refund;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantPayout;
use App\Models\RestaurantPayoutProfile;
use App\Models\RestaurantSettlement;
use App\Services\Finance\Payout\FakePayoutProvider;
use App\Services\Finance\Payout\PayoutService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — restaurant payouts. LIVE PAYOUT = DISABLED: every payout here goes through the development
 * FakePayoutProvider (no money moves) or is the record of a payment made outside. Nothing in this file is evidence
 * about a real payout provider.
 */
class PayoutTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    private FakePayoutProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    /**
     * A completed order, the week over, its settlement approved, and the fake payout provider configured.
     */
    private function approved(): RestaurantSettlement
    {
        $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $this->fake = $this->fakePayouts();
        $this->payoutProfile();

        return $this->approvedSettlement();
    }

    private function startPayout(RestaurantSettlement $settlement): TestResponse
    {
        return $this->financePost('/settlements/'.$settlement->public_id.'/payouts', ['confirm' => true]);
    }

    private function providerSendsPayout(string $type, RestaurantPayout $payout, ?string $eventId = null, ?int $createdAt = null): TestResponse
    {
        [$body, $headers] = $this->fake->webhook($type, $this->fake->findPayoutByKey((string) $payout->idempotency_key), $eventId, $createdAt);
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/v1/webhooks/payouts/fake', [], [], [], $server, $body);
    }

    public function test_an_approved_settlement_is_paid_only_when_the_provider_confirms_it(): void
    {
        $settlement = $this->approved();
        $this->financeGet('/settlements/'.$settlement->public_id)->assertOk()->assertJsonPath('settlement.can.start_payout', true);

        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'PROCESSING')->assertJsonPath('payout.test_only', true);
        $payout = RestaurantPayout::query()->firstOrFail();
        // The amount is the settlement's; the destination is a mask; the provider has it — and nothing is paid yet.
        $this->assertSame([PayoutStatus::Processing, 45000, 'INR', 'PROVIDER', 'fake', '•••• 4321', 'po-'.$payout->public_id, null], [$payout->status, $payout->amount_minor, $payout->currency, $payout->method, $payout->provider, $payout->destination_mask, $payout->idempotency_key, $payout->ledger_transaction_id]);
        $this->assertNotNull($payout->provider_payout_reference);
        $this->assertMatchesRegularExpression('/\APO-[0-9A-F]{10}\z/', $payout->supportReference());
        $this->assertSame([SettlementStatus::PaymentPending, null, 0], [$settlement->refresh()->status, $settlement->paid_at, $settlement->paid_minor]);
        $this->assertSame([45000, 0], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::PayoutClearing)]);

        // The bank finishes it and the provider says so.
        $this->fake->settle((string) $payout->provider_payout_reference, 'processed');
        $this->providerSendsPayout('payout.processed', $payout)->assertOk()->assertJson(['received' => true]);

        $payout->refresh();
        $this->assertSame([PayoutStatus::Paid, 'processed'], [$payout->status, $payout->provider_status]);
        $this->assertSame(['D RESTAURANT_PAYABLE 45000', 'C PAYOUT_CLEARING 45000'], $this->linesOf('payout:'.$payout->public_id.':paid'));
        $this->assertSame([SettlementStatus::Paid, 45000], [$settlement->refresh()->status, $settlement->paid_minor]);
        $this->assertNotNull($settlement->paid_at);
        $this->assertSame([0, 45000], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::PayoutClearing)]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.payout_paid')->count());
        $shown = $this->financeGet('/payouts/'.$payout->public_id)->assertOk()->json('payout');
        $this->assertSame(['PAID', 45000, 'fake', 'test', '•••• 4321', false], [$shown['status'], $shown['amount']['amount'], $shown['provider'], $shown['provider_mode'], $shown['destination'], $shown['can']['retry']]);
        // No token and no bank detail leaves the API.
        $this->assertStringNotContainsString('fa_test_', json_encode($shown));
        $this->assertLedgerBalanced();
    }

    public function test_two_requests_for_the_same_settlement_make_one_payout(): void
    {
        $settlement = $this->approved();

        $first = $this->startPayout($settlement)->assertCreated()->json('payout.id');
        // A double click, a second administrator, a retried request: the payout that exists is the answer.
        $second = $this->startPayout($settlement)->assertCreated()->json('payout.id');
        $third = app(PayoutService::class)->start($settlement->refresh(), $this->asFinance());
        $this->assertSame([$first, $first], [$second, (string) $third->public_id]);
        $this->assertSame(1, RestaurantPayout::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.payout_started')->count());

        // The database refuses a second live payout for the settlement even when the application is bypassed.
        $payout = RestaurantPayout::query()->firstOrFail();
        DB::beginTransaction();
        try {
            DB::table('restaurant_payouts')->insert([...collect($payout->getAttributes())->except(['id'])->all(), 'public_id' => (string) Str::uuid(), 'idempotency_key' => 'po-second', 'provider_payout_reference' => 'another']);
            $this->fail('A second live payout was stored for one settlement.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('restaurant_payouts_one_active', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        // The submission job run again sends nothing again: the provider made one payout.
        (new SubmitPayoutJob((int) $payout->id))->handle(app(PayoutService::class));
        $this->assertSame(1, $payout->refresh()->submit_attempts);
        $this->assertSame((string) $payout->provider_payout_reference, $this->fake->findPayoutByKey((string) $payout->idempotency_key)->reference);
    }

    public function test_a_submission_without_an_answer_is_unknown_and_is_looked_up_never_sent_again(): void
    {
        $settlement = $this->approved();
        // The provider makes the payout and the answer is lost.
        $this->fake->failNext('create_payout', ProviderFailure::Timeout, 1, true);

        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'UNKNOWN')->assertJsonPath('payout.needs_review', true);
        $payout = RestaurantPayout::query()->firstOrFail();
        $this->assertSame([PayoutStatus::Unknown, null, 1, SettlementStatus::PaymentPending], [$payout->status, $payout->provider_payout_reference, $payout->submit_attempts, $settlement->refresh()->status]);
        // Nothing is paid, and a second payout cannot be started while this one is not understood.
        $this->assertSame([45000, 0], [$this->payableBalance(), LedgerTransaction::query()->where('type', 'PAYOUT_PAID')->count()]);
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.id', (string) $payout->public_id);
        $this->financePost('/payouts/'.$payout->public_id.'/retry', ['reason' => 'Trying again', 'confirm' => true])->assertStatus(409)->assertJsonPath('error.code', 'payout_not_retryable');
        $this->assertSame(1, $payout->refresh()->submit_attempts, 'it was not sent again');

        // Asking the provider what it has for our key finds the payout it made.
        $this->financePost('/payouts/'.$payout->public_id.'/reconcile', ['reason' => 'Looking for the lost answer'])->assertOk()->assertJsonPath('payout.status', 'PROCESSING');
        $this->assertSame($this->fake->findPayoutByKey((string) $payout->idempotency_key)->reference, $payout->refresh()->provider_payout_reference);
        $this->fake->settle((string) $payout->provider_payout_reference, 'processed');
        // The scheduled look settles it when it is due.
        $this->travel(2)->minutes();
        $this->assertSame(1, app(PayoutService::class)->sweep()['checked']);
        $this->assertSame([PayoutStatus::Paid, SettlementStatus::Paid, 0], [$payout->refresh()->status, $settlement->refresh()->status, $this->payableBalance()]);
        $this->assertSame(1, RestaurantPayout::query()->count());
    }

    public function test_a_timeout_before_the_provider_did_anything_needs_a_person_before_the_same_request_is_sent_again(): void
    {
        $settlement = $this->approved();
        $this->fake->failNext('create_payout', ProviderFailure::Timeout);

        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'UNKNOWN');
        $payout = RestaurantPayout::query()->firstOrFail();
        // The provider has nothing for the key. It is still not sent again by itself.
        $this->financePost('/payouts/'.$payout->public_id.'/reconcile', ['reason' => 'Looking for the lost answer'])->assertOk()->assertJsonPath('payout.status', 'REVIEW_REQUIRED')->assertJsonPath('payout.review_reason', 'PROVIDER_HAS_NO_RECORD')->assertJsonPath('payout.can.retry', true);
        $this->assertSame([1, 45000], [$payout->refresh()->submit_attempts, $this->payableBalance()]);

        // A person sends the SAME request again — the same idempotency key.
        $key = $payout->idempotency_key;
        $this->financePost('/payouts/'.$payout->public_id.'/retry', ['reason' => 'The provider has no record of it'])->assertStatus(422)->assertJsonValidationErrors('confirm', 'error.details.fields');
        $this->financePost('/payouts/'.$payout->public_id.'/retry', ['reason' => 'The provider has no record of it', 'confirm' => true])->assertOk()->assertJsonPath('payout.status', 'PROCESSING');
        $this->assertSame([$key, 2, 1], [$payout->refresh()->idempotency_key, $payout->submit_attempts, RestaurantPayout::query()->count()]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'finance.payout_retried')->count());
    }

    public function test_a_failed_payout_pays_nothing_and_the_settlement_can_be_paid_by_a_new_one(): void
    {
        $settlement = $this->approved();
        $this->fake->payoutsBecome('failed');
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'FAILED')->assertJsonPath('payout.failure.code', 'beneficiary_bank_declined');
        $this->assertSame([SettlementStatus::Failed, 45000, 0], [$settlement->refresh()->status, $this->payableBalance(), LedgerTransaction::query()->where('type', 'PAYOUT_PAID')->count()]);

        // A provider that refuses outright is the same: FAILED, nothing sent.
        $this->fake->payoutsBecome('processed');
        $this->fake->failNext('create_payout', ProviderFailure::Rejected);
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'FAILED')->assertJsonPath('payout.failure.code', 'PROVIDER_REJECTED');

        // A new payout — a new record with a new key — pays it.
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'PAID');
        $payouts = RestaurantPayout::query()->orderBy('id')->get();
        $this->assertSame(['FAILED', 'FAILED', 'PAID'], $payouts->map(fn (RestaurantPayout $p): string => $p->status->value)->all());
        $this->assertSame(3, $payouts->pluck('idempotency_key')->unique()->count());
        $this->assertSame([SettlementStatus::Paid, 0, 1], [$settlement->refresh()->status, $this->payableBalance(), LedgerTransaction::query()->where('type', 'PAYOUT_PAID')->count()]);
        $queues = $this->financeGet('/payouts')->assertOk()->json('queues');
        $this->assertSame([2, 1, 0], [$queues['failed'], $queues['paid'], $queues['processing']]);
    }

    public function test_a_payout_webhook_is_verified_counted_once_and_decides_nothing_by_its_body(): void
    {
        $settlement = $this->approved();
        $this->startPayout($settlement)->assertCreated();
        $payout = RestaurantPayout::query()->firstOrFail();
        $held = $this->fake->findPayoutByKey((string) $payout->idempotency_key);

        // No signature, a wrong signature, an unknown provider: refused, nothing recorded.
        [$body, $headers] = $this->fake->webhook('payout.processed', $held);
        $this->call('POST', '/api/v1/webhooks/payouts/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_PAYOUT_EVENT_ID' => 'evt_1'], $body)->assertStatus(401);
        $this->call('POST', '/api/v1/webhooks/payouts/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_PAYOUT_EVENT_ID' => 'evt_1', 'HTTP_X_FAKE_PAYOUT_SIGNATURE' => str_repeat('0', 64)], $body)->assertStatus(401);
        $this->call('POST', '/api/v1/webhooks/payouts/razorpayx', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_PAYOUT_EVENT_ID' => 'evt_1', 'HTTP_X_FAKE_PAYOUT_SIGNATURE' => $headers[FakePayoutProvider::SIGNATURE_HEADER]], $body)->assertNotFound();
        // A body changed after it was signed is refused.
        $this->call('POST', '/api/v1/webhooks/payouts/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_PAYOUT_EVENT_ID' => 'evt_1', 'HTTP_X_FAKE_PAYOUT_SIGNATURE' => $headers[FakePayoutProvider::SIGNATURE_HEADER]], str_replace('processing', 'processed', $body).' ')->assertStatus(401);
        $this->assertSame(0, PayoutWebhookEvent::query()->count());

        // A signed "processed" event while the provider's own record still says processing: the body decides nothing.
        $this->providerSendsPayout('payout.processed', $payout, 'evt_early')->assertOk();
        $this->assertSame([PayoutStatus::Processing, 45000], [$payout->refresh()->status, $this->payableBalance()]);

        // The provider finishes it. The event arrives twice: the payout is paid once.
        $this->fake->settle($held->reference, 'processed');
        $this->providerSendsPayout('payout.processed', $payout, 'evt_done')->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->providerSendsPayout('payout.processed', $payout, 'evt_done')->assertOk()->assertJson(['duplicate' => true]);
        // An earlier event that arrives late changes nothing: PAID stays PAID.
        $this->providerSendsPayout('payout.processing', $payout, 'evt_late', now()->subHour()->getTimestamp())->assertOk();
        $this->assertSame([PayoutStatus::Paid, 0, 1], [$payout->refresh()->status, $this->payableBalance(), LedgerTransaction::query()->where('type', 'PAYOUT_PAID')->count()]);
        $this->assertSame(3, PayoutWebhookEvent::query()->count());
        // An event dated in the future is refused.
        $this->providerSendsPayout('payout.processed', $payout, 'evt_future', now()->addHour()->getTimestamp())->assertStatus(400);
    }

    public function test_a_provider_record_that_is_not_our_payout_is_never_adopted(): void
    {
        $settlement = $this->approved();
        $this->startPayout($settlement)->assertCreated();
        $payout = RestaurantPayout::query()->firstOrFail();
        // The provider's record of the payout has another amount than ours.
        $this->fake->tamper((string) $payout->provider_payout_reference, 46000);
        $this->fake->settle((string) $payout->provider_payout_reference, 'processed');

        $this->financePost('/payouts/'.$payout->public_id.'/reconcile', ['reason' => 'Checking the payout'])->assertOk()->assertJsonPath('payout.status', 'REVIEW_REQUIRED')->assertJsonPath('payout.review_reason', 'PROVIDER_RECORD_DIFFERS');
        $this->assertSame([45000, 0, SettlementStatus::PaymentPending], [$this->payableBalance(), LedgerTransaction::query()->where('type', 'PAYOUT_PAID')->count(), $settlement->refresh()->status]);
        $difference = ReconciliationDifference::query()->where('type', DifferenceType::PayoutSettlementMismatch->value)->firstOrFail();
        $this->assertSame(['OPEN', 45000, 46000, 1000], [$difference->status->value, $difference->expected_minor, $difference->actual_minor, $difference->difference_minor]);
    }

    public function test_a_payout_the_bank_returns_is_reversed_by_a_new_transaction_and_the_paid_posting_stays(): void
    {
        $settlement = $this->approved();
        $this->fake->payoutsBecome('processed');
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'PAID');
        $payout = RestaurantPayout::query()->firstOrFail();
        $paid = $this->linesOf('payout:'.$payout->public_id.':paid');

        $this->fake->settle((string) $payout->provider_payout_reference, 'reversed');
        $this->providerSendsPayout('payout.reversed', $payout)->assertOk();

        $this->assertSame([PayoutStatus::Failed, 'REVERSED'], [$payout->refresh()->status, $payout->failure_code]);
        $this->assertSame(['C RESTAURANT_PAYABLE 45000', 'D PAYOUT_CLEARING 45000'], $this->linesOf('payout:'.$payout->public_id.':reversed'));
        $this->assertSame($paid, $this->linesOf('payout:'.$payout->public_id.':paid'), 'the paid posting was not touched');
        // The restaurant is owed again, and the settlement can be paid by a new payout.
        $this->assertSame([45000, 0, SettlementStatus::Failed, null], [$this->payableBalance(), $this->platformBalance(LedgerAccountCode::PayoutClearing), $settlement->refresh()->status, $settlement->paid_at]);
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'PAID');
        $this->assertSame([0, SettlementStatus::Paid], [$this->payableBalance(), $settlement->refresh()->status]);
        $this->assertLedgerBalanced();
    }

    public function test_a_refund_after_the_payout_never_changes_the_payout_it_lowers_what_is_owed_next_time(): void
    {
        // A daily cadence (a TEST FIXTURE like the weekly one): the fake payment provider forgets a payment after two days,
        // so the refund has to follow the payout within that time.
        config(['finance.refund_liability.by_reason' => ['ITEM_UNAVAILABLE' => ['RESTAURANT', null]], 'finance.holds.refund_pending' => false, 'finance.settlements.cadence' => 'DAILY']);
        $this->completedOrderWith();
        $this->asFinance();
        $this->clock('2026-10-08 09:00');
        $this->fake = $this->fakePayouts();
        $this->payoutProfile();
        $settlement = $this->approvedSettlement();
        $this->fake->payoutsBecome('processed');
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'PAID');
        $payout = RestaurantPayout::query()->firstOrFail();
        $order = $this->orderModel((string) $settlement->lines()->firstOrFail()->order_number);
        $before = [$payout->getAttributes(), $settlement->refresh()->getAttributes(), $settlement->lines()->get()->map->getAttributes()->all(), $this->linesOf('payout:'.$payout->public_id.':paid')];

        // The customer is refunded AFTER the restaurant was paid, and the policy says the restaurant bears it.
        $this->askRefund($order->order_number, ['type' => 'PARTIAL', 'amount_minor' => 20000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated();
        $refund = $this->refundOf($order->order_number);
        $this->approveAndExecute($refund);
        $this->providerSendsRefund('refund.processed', $this->providerSettlesRefund($refund))->assertOk();

        // The historical payout, its settlement, its lines and its posting are byte for byte what they were.
        $this->assertSame($before, [$payout->refresh()->getAttributes(), $settlement->refresh()->getAttributes(), $settlement->lines()->get()->map->getAttributes()->all(), $this->linesOf('payout:'.$payout->public_id.':paid')]);
        // What exists is a NEW negative line: the restaurant owes ₹200 against its future earnings. No bank debit, no payout.
        $line = RestaurantPayableItem::query()->where('type', 'REFUND_ADJUSTMENT')->firstOrFail();
        $this->assertSame([-20000, 'AVAILABLE', null, $order->order_number], [$line->amount_minor, $line->status, $line->settlement_id, $line->order_number]);
        $this->assertSame([-20000, 1], [$this->payableBalance(), RestaurantPayout::query()->count()]);

        // The next settlement includes the adjustment.
        $this->clock('2026-10-08 12:00');
        $this->completedOrderWith();
        $this->asFinance();
        $this->clock('2026-10-09 09:00');
        $this->generateSettlements();
        $next = $this->settlementOf();
        $this->assertNotSame($settlement->id, $next->id);
        $this->assertSame([2, 50000, 5000, -20000, 25000], [$next->line_count, $next->gross_sales_minor, $next->commission_minor, $next->refund_adjustments_minor, $next->net_payable_minor]);

        // When the platform bears a refund after the payout, the restaurant's payable is not touched at all.
        config(['finance.refund_liability.by_reason' => ['ITEM_UNAVAILABLE' => ['PLATFORM', null]]]);
        $balance = $this->payableBalance();
        $this->askRefund($order->order_number, ['type' => 'PARTIAL', 'amount_minor' => 10000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated();
        $second = Refund::query()->orderByDesc('id')->firstOrFail();
        $this->approveAndExecute($second);
        $this->providerSendsRefund('refund.processed', $this->providerSettlesRefund($second))->assertOk();
        $this->assertSame([$balance, 'PLATFORM'], [$this->payableBalance(), FinancialAdjustment::query()->where('refund_id', $second->id)->firstOrFail()->liability]);
        $this->assertLedgerBalanced();
    }

    public function test_a_payout_made_outside_is_recorded_with_its_evidence_and_only_for_the_settlements_own_amount(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $settlement = $this->approvedSettlement();
        // No payout provider is configured: a provider payout is impossible, an external one can be recorded.
        $this->financeGet('/settlements/'.$settlement->public_id)->assertOk()->assertJsonPath('settlement.can.start_payout', false)->assertJsonPath('settlement.can.record_external_payout', true);
        $this->startPayout($settlement)->assertStatus(409)->assertJsonPath('error.code', 'payout_provider_not_configured');

        $url = '/settlements/'.$settlement->public_id.'/external-payouts';
        $body = ['confirm' => true, 'external_reference' => 'UTR-HDFC-0012345', 'paid_on' => '2026-10-12', 'amount_minor' => 45000, 'currency' => 'INR', 'reason' => 'TEST FIXTURE: paid by bank transfer', 'evidence_reference' => 'DRIVE-FILE-778'];
        // Every part of the evidence is required, and the amount only CONFIRMS the settlement's.
        foreach (['external_reference', 'paid_on', 'reason', 'evidence_reference', 'confirm', 'amount_minor', 'currency'] as $missing) {
            $this->financePost($url, array_diff_key($body, [$missing => 0]))->assertStatus(422)->assertJsonValidationErrors($missing, 'error.details.fields');
        }
        $this->financePost($url, [...$body, 'amount_minor' => 44999])->assertStatus(422)->assertJsonPath('error.code', 'amount_mismatch');
        $this->financePost($url, [...$body, 'currency' => 'USD'])->assertStatus(422)->assertJsonPath('error.code', 'amount_mismatch');
        $this->financePost($url, [...$body, 'paid_on' => '2026-10-13'])->assertStatus(422)->assertJsonPath('error.code', 'paid_on_invalid');
        $this->financePost($url, [...$body, 'paid_on' => '2026-10-01'])->assertStatus(422)->assertJsonPath('error.code', 'paid_on_invalid');
        // No bank detail is accepted anywhere.
        $this->financePost($url, [...$body, 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001'])->assertStatus(422)->assertJsonValidationErrors(['account_number', 'ifsc'], 'error.details.fields');
        $this->assertSame(0, RestaurantPayout::query()->count());

        $recorded = $this->financePost($url, $body)->assertCreated()->json('payout');
        $this->assertSame(['PAID', 'EXTERNAL', 'external', 45000, ['reference' => 'UTR-HDFC-0012345', 'paid_on' => '2026-10-12', 'evidence_reference' => 'DRIVE-FILE-778'], false],
            [$recorded['status'], $recorded['method'], $recorded['provider'], $recorded['amount']['amount'], $recorded['external'], $recorded['test_only']]);
        // It posts a payout transaction — it does not edit a balance.
        $payout = RestaurantPayout::query()->firstOrFail();
        $this->assertSame(['D RESTAURANT_PAYABLE 45000', 'C PAYOUT_CLEARING 45000'], $this->linesOf('payout:'.$payout->public_id.':paid'));
        $this->assertSame([0, SettlementStatus::Paid], [$this->payableBalance(), $settlement->refresh()->status]);
        $audit = AuditEvent::query()->where('action', 'finance.payout_recorded_external')->firstOrFail();
        $this->assertSame(['UTR-HDFC-0012345', 'DRIVE-FILE-778', 'TEST FIXTURE: paid by bank transfer'], [$audit->changes['external_reference']['to'], $audit->changes['evidence_reference']['to'], $audit->reason]);
        // The settlement is paid: it cannot be paid again, and the same bank reference cannot pay another one.
        $this->financePost($url, [...$body, 'external_reference' => 'UTR-HDFC-0099999'])->assertStatus(409);
        $this->clock('2026-10-13 12:00');
        $this->completedOrderWith();
        $this->asFinance();
        $this->clock('2026-10-19 09:00');
        $other = $this->approvedSettlement();
        $this->financePost('/settlements/'.$other->public_id.'/external-payouts', [...$body, 'paid_on' => '2026-10-19', 'external_reference' => 'utr-hdfc-0012345'])->assertStatus(409)->assertJsonPath('error.code', 'external_reference_used');
        $this->assertSame(1, RestaurantPayout::query()->count());
    }

    public function test_viewing_paying_and_recording_are_separate_permissions_and_nothing_marks_a_payout_as_paid(): void
    {
        $settlement = $this->approved();
        $this->startPayout($settlement)->assertCreated();
        $payout = RestaurantPayout::query()->firstOrFail();
        $external = ['confirm' => true, 'external_reference' => 'UTR-1', 'paid_on' => '2026-10-12', 'amount_minor' => 45000, 'currency' => 'INR', 'reason' => 'TEST FIXTURE: without the permission', 'evidence_reference' => 'DOC-1'];

        // A viewer reads and can do nothing.
        $this->adminFor([Permission::AdminFinancePayoutsView, Permission::AdminSettlementsView]);
        $this->financeGet('/payouts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('provider.live_payout', 'DISABLED')->assertJsonPath('provider.status', 'FAKE_PROVIDER');
        $this->financeGet('/payouts/'.$payout->public_id)->assertOk()->assertJsonPath('payout.can.reconcile', false);
        $this->startPayout($settlement)->assertForbidden();
        $this->financePost('/payouts/'.$payout->public_id.'/reconcile', ['reason' => 'Looking'])->assertForbidden();
        $this->financePost('/payouts/'.$payout->public_id.'/cancel', ['reason' => 'Stopping'])->assertForbidden();
        $this->financePost('/settlements/'.$settlement->public_id.'/external-payouts', $external)->assertForbidden();
        // Paying through the provider does not include recording an external payment — and the other way round.
        $this->adminFor([Permission::AdminFinancePayoutsView, Permission::AdminFinancePayoutsExecute]);
        $this->financePost('/settlements/'.$settlement->public_id.'/external-payouts', $external)->assertForbidden();
        $this->adminFor([Permission::AdminFinancePayoutsView, Permission::AdminFinancePayoutsRecordExternal]);
        $this->startPayout($settlement)->assertForbidden();
        // Somebody without the view permission sees nothing.
        $this->adminFor([Permission::AdminSettlementsView, Permission::AdminFinanceView]);
        $this->financeGet('/payouts')->assertForbidden();

        // There is no "mark as paid": the routes do not exist and the fields are refused.
        $this->asFinance();
        foreach (['mark-paid', 'mark_paid', 'paid', 'complete', 'status', 'settle', 'force'] as $action) {
            $this->financePost('/payouts/'.$payout->public_id.'/'.$action, ['reason' => 'x'])->assertNotFound();
        }
        $this->act();
        $this->patchJson('/api/v1/admin/finance/payouts/'.$payout->public_id, ['status' => 'PAID'])->assertStatus(405);
        $this->financePost('/payouts/'.$payout->public_id.'/reconcile', ['reason' => 'Setting it by hand', 'status' => 'PAID', 'paid_at' => now()->toIso8601String(), 'amount_minor' => 1])->assertStatus(422)->assertJsonValidationErrors(['status', 'paid_at', 'amount_minor'], 'error.details.fields');
        $this->financePost('/settlements/'.$settlement->public_id.'/payouts', ['confirm' => true, 'amount_minor' => 1, 'destination' => 'fa_other'])->assertStatus(422)->assertJsonValidationErrors(['amount_minor', 'destination'], 'error.details.fields');
        $this->financePost('/settlements/'.$settlement->public_id.'/payouts', [])->assertStatus(422)->assertJsonValidationErrors('confirm', 'error.details.fields');
        $this->assertSame(PayoutStatus::Processing, $payout->refresh()->status);
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r): string => implode('|', $r->methods()).' '.$r->uri())->filter(fn (string $r): bool => str_contains($r, 'payouts'))->sort()->values()->all();
        $this->assertSame([
            'GET|HEAD api/v1/admin/finance/payouts', 'GET|HEAD api/v1/admin/finance/payouts/{payout}', 'GET|HEAD api/v1/restaurant/finance/payouts',
            'POST api/v1/admin/finance/payouts/{payout}/cancel', 'POST api/v1/admin/finance/payouts/{payout}/reconcile', 'POST api/v1/admin/finance/payouts/{payout}/retry',
            'POST api/v1/admin/finance/settlements/{settlement}/external-payouts', 'POST api/v1/admin/finance/settlements/{settlement}/payouts',
            'POST api/v1/dev/payouts/{payout}/simulate',   // the FAKE provider's side, never registered in production
            'POST api/v1/webhooks/payouts/{payoutProvider}',
        ], $routes);
    }

    public function test_no_payout_is_sent_without_a_verified_destination_or_while_the_restaurant_is_held(): void
    {
        $this->completedOrderWith();
        $this->asFinance();
        $this->nextWeek();
        $this->fake = $this->fakePayouts();
        $settlement = $this->approvedSettlement();

        // No destination at the provider — and one that is not verified.
        $this->startPayout($settlement)->assertStatus(409)->assertJsonPath('error.code', 'payout_destination_not_verified');
        $profile = $this->payoutProfile(status: RestaurantPayoutProfile::PENDING_VERIFICATION);
        $this->startPayout($settlement)->assertStatus(409)->assertJsonPath('error.code', 'payout_destination_not_verified');
        $profile->forceFill(['status' => RestaurantPayoutProfile::VERIFIED, 'verified_at' => now()])->save();

        // An active hold on the restaurant stops every payout.
        $hold = $this->financePost('/holds', ['restaurant' => (string) $this->burgerHub->public_id, 'reason' => 'COMPLIANCE', 'note' => 'TEST FIXTURE: documents are being checked'])->assertCreated()->json('hold.id');
        $this->startPayout($settlement)->assertStatus(409)->assertJsonPath('error.code', 'location_held');
        $this->assertSame([0, SettlementStatus::Approved], [RestaurantPayout::query()->count(), $settlement->refresh()->status]);
        $this->financePost('/holds/'.$hold.'/release', ['reason' => 'TEST FIXTURE: documents are in order'])->assertOk();

        // A payout that was made and not sent yet can be withdrawn; the settlement is approved again.
        Queue::fake();
        $this->startPayout($settlement)->assertCreated()->assertJsonPath('payout.status', 'REQUIRED')->assertJsonPath('payout.can.cancel', true);
        $payout = RestaurantPayout::query()->firstOrFail();
        $this->financePost('/payouts/'.$payout->public_id.'/cancel', ['reason' => 'TEST FIXTURE: started by mistake'])->assertOk()->assertJsonPath('payout.status', 'CANCELLED');
        $this->assertSame([SettlementStatus::Approved, 45000], [$settlement->refresh()->status, $this->payableBalance()]);
        // With the four-eyes policy, who approved a settlement cannot pay it.
        config(['finance.payouts.four_eyes' => true]);
        $this->startPayout($settlement)->assertForbidden()->assertJsonPath('error.code', 'four_eyes');
    }
}
