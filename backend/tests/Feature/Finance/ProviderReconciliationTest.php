<?php

namespace Tests\Feature\Finance;

use App\Contracts\Finance\ProviderSettlementSource;
use App\Enums\DifferenceType;
use App\Enums\LedgerAccountCode;
use App\Enums\Permission;
use App\Enums\ProviderFailure;
use App\Exceptions\ProviderException;
use App\Models\AuditEvent;
use App\Models\FinanceRun;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ProviderSettlement;
use App\Models\ProviderSettlementItem;
use App\Models\ReconciliationDifference;
use App\Models\Refund;
use App\Services\Finance\ProviderSettlement\FakeSettlementSource;
use App\Services\Finance\ProviderSettlement\ProviderSettlementReconciliationService;
use App\Services\Finance\ProviderSettlement\ProviderSettlementSources;
use App\Services\Payment\Providers\RazorpayPaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — the payment provider's settlement records against the platform's own.
 *
 * The provider here is the development fake: its report says exactly what the test tells it, including the fee and
 * tax of each payment. EVERY FEE IN THIS FILE IS A TEST FIXTURE — no fee is calculated anywhere in the application.
 * The Razorpay reader is exercised against a faked HTTP layer only (PENDING PROVIDER VERIFICATION).
 */
class ProviderReconciliationTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    private const DAY = '2026-10-07';

    private FakeSettlementSource $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
        $this->provider = new FakeSettlementSource;
    }

    private function paymentOf(Order $order): PaymentAttempt
    {
        return PaymentAttempt::query()->findOrFail($order->payment_attempt_id);
    }

    /**
     * @return array{reference: string, amount: int, fee: int, tax: int}
     */
    private function line(Order $order, int $fee = 1000, int $tax = 153): array
    {
        $payment = $this->paymentOf($order);

        return ['reference' => (string) $payment->provider_payment_reference, 'amount' => (int) $payment->amount_minor, 'fee' => $fee, 'tax' => $tax];
    }

    private function import(string $day = self::DAY): array
    {
        return $this->financePost('/provider-settlements/import', ['day' => $day])->assertCreated()->json('run');
    }

    public function test_an_import_matches_payments_and_refunds_posts_the_providers_own_fees_and_the_settlement_received(): void
    {
        $first = $this->completedOrderWith();
        [$second, $refund] = $this->refundedCompletedOrder(20000);
        $this->asFinance();
        // The provider settles the day: two payments (each with ITS fee and the tax inside it) and one refund.
        $report = $this->provider->settleDay(CarbonImmutable::parse(self::DAY), [$this->line($first), $this->line($second, 1180, 180)],
            [['reference' => (string) $refund->provider_refund_reference, 'payment_reference' => (string) $refund->provider_payment_reference, 'amount' => 20000]]);
        $this->assertSame(50000 - 1000 + 50000 - 1180 - 20000, $report['amount']);
        $before = $this->providerClearing();
        $this->assertSame(100000 - 20000, $before, 'the provider holds two payments minus one refund');

        $run = $this->import();
        $this->assertSame(['PROVIDER_IMPORT', 'OK', 0, 3, 3, 0, 1, 1], [$run['kind'], $run['status'], $run['problems'], $run['result']['lines_read'], $run['result']['lines_new'], $run['result']['lines_repeated'], $run['result']['settlements_read'], $run['result']['settlements_new']]);
        $this->assertSame(['items_matched' => 3, 'fees_posted' => 2, 'settlements_matched' => 1, 'differences_open' => 0], array_intersect_key($run['result']['reconciliation'], array_flip(['items_matched', 'fees_posted', 'settlements_matched', 'differences_open'])));

        // PAYMENT and REFUND reconciliation: each provider line found its record, with the same amount and currency.
        $items = ProviderSettlementItem::query()->orderBy('id')->get();
        $this->assertSame(['MATCHED', 'MATCHED', 'MATCHED'], $items->pluck('match_status')->all());
        $this->assertSame([(int) $this->paymentOf($first)->id, (int) $this->paymentOf($second)->id, (int) $refund->payment_attempt_id], $items->pluck('payment_attempt_id')->map(fn ($v): int => (int) $v)->all());
        $this->assertSame((int) $refund->id, (int) $items[2]->refund_id);

        // PROVIDER FEES: exactly what the provider reported — fee net of its tax, and the tax — never a formula.
        $this->assertSame(['D PROVIDER_FEE_EXPENSE 847', 'D PROVIDER_TAX_EXPENSE 153', 'C PROVIDER_CLEARING 1000'], $this->linesOf('provider-item:fake:PAYMENT:'.$items[0]->entity_reference.':fee'));
        $this->assertSame(['D PROVIDER_FEE_EXPENSE 1000', 'D PROVIDER_TAX_EXPENSE 180', 'C PROVIDER_CLEARING 1180'], $this->linesOf('provider-item:fake:PAYMENT:'.$items[1]->entity_reference.':fee'));
        $this->assertSame([1847, 333], [$this->platformBalance(LedgerAccountCode::ProviderFeeExpense), $this->platformBalance(LedgerAccountCode::ProviderTaxExpense)]);
        // The platform bears them: no restaurant's payable moved.
        $this->assertSame(45000 + 45000, $this->payableBalance());

        // NET SETTLEMENT: the provider's settlement is its lines, and what it settled left its clearing for the bank.
        $settlement = ProviderSettlement::query()->firstOrFail();
        $this->assertSame(['PROCESSED', 'MATCHED', 77820, 3, 97820, 20000, 2180, 333], [$settlement->status, $settlement->reconciliation_status, $settlement->amount_minor, $settlement->item_count, $settlement->items_credit_minor, $settlement->items_debit_minor, $settlement->items_fee_minor, $settlement->items_tax_minor]);
        $this->assertSame(['D PLATFORM_BANK 77820', 'C PROVIDER_CLEARING 77820'], $this->linesOf('provider-settlement:fake:'.$settlement->provider_settlement_reference.':received'));
        $this->assertSame([0, 77820], [$this->providerClearing(), $this->platformBalance(LedgerAccountCode::PlatformBank)]);
        $this->assertLedgerBalanced();

        // IMPORTED TWICE: one logical record of everything, nothing posted again.
        $transactions = LedgerTransaction::query()->count();
        $again = $this->import();
        $this->assertSame([3, 0, 3, 1, 0], [$again['result']['lines_read'], $again['result']['lines_new'], $again['result']['lines_repeated'], $again['result']['settlements_read'], $again['result']['settlements_new']]);
        $this->assertSame([3, 1, $transactions], [ProviderSettlementItem::query()->count(), ProviderSettlement::query()->count(), LedgerTransaction::query()->count()]);

        // What an administrator reads.
        $list = $this->financeGet('/provider-settlements')->assertOk()->json();
        $this->assertSame([1, 'FAKE_PROVIDER', 'PENDING PROVIDER VERIFICATION', 'MATCHED', true], [count($list['data']), $list['source']['status'], $list['source']['razorpay_settlement_api'], $list['data'][0]['reconciliation_status'], $list['data'][0]['posted_to_ledger']]);
        $detail = $this->financeGet('/provider-settlements/'.$settlement->public_id)->assertOk()->json('provider_settlement');
        $this->assertSame([3, 1000, 153, (string) $this->paymentOf($first)->public_id, true], [count($detail['items']), $detail['items'][0]['fee']['amount'], $detail['items'][0]['tax_on_fee']['amount'], $detail['items'][0]['matched_payment'], $detail['items'][0]['fee_posted']]);
        $costs = $this->financeGet('/reports/provider-costs?from=2026-10-01&to=2026-10-12')->assertOk()->json();
        $this->assertSame(['PROVIDER_REPORTED', false, 'PLATFORM_BORNE', 2180, 333], [$costs['source'], $costs['estimated'], $costs['policy'], $costs['rows'][0]['fee']['amount'], $costs['rows'][0]['tax_on_fee']['amount']]);
        $overview = $this->financeGet('/overview?from=2026-10-01&to=2026-10-12')->assertOk()->json();
        $this->assertSame([1847, 333, 77820, false], [$overview['currencies'][0]['period']['provider_fees']['amount'], $overview['currencies'][0]['period']['provider_fee_tax']['amount'], $overview['currencies'][0]['period']['provider_settlements_received']['amount'], $overview['provider_fees']['estimated']]);
    }

    public function test_what_does_not_agree_becomes_a_difference_that_stays_until_the_records_agree_or_a_person_closes_it(): void
    {
        $known = $this->completedOrderWith();
        $short = $this->completedOrderWith();
        $absent = $this->completedOrderWith();
        [$fourth, $refund] = $this->refundedCompletedOrder(20000);
        $this->asFinance();

        // The provider's report: one payment as we have it, one with another amount, one we never heard of, a refund with
        // another amount, a refund we do not have, a line we cannot interpret — and a total that is not its lines.
        $wrong = [...$this->line($short), 'amount' => 49000];
        $this->provider->settleDay(CarbonImmutable::parse(self::DAY), [$this->line($known), $wrong, $this->line($fourth), ['reference' => 'fpay_never_seen', 'amount' => 12300, 'fee' => 0, 'tax' => 0]], [
            ['reference' => (string) $refund->provider_refund_reference, 'payment_reference' => (string) $refund->provider_payment_reference, 'amount' => 25000],
            ['reference' => 'frfnd_never_seen', 'payment_reference' => 'fpay_never_seen', 'amount' => 700],
        ], ['extra' => [['type' => 'adjustment', 'credit' => 500, 'amount' => 500]], 'amount' => 123456]);

        $run = $this->import();
        $this->assertSame(['PROBLEMS', 6], [$run['status'], $run['result']['reconciliation']['differences_open']]);
        $types = ReconciliationDifference::query()->orderBy('type')->pluck('type')->map(fn (DifferenceType $t): string => $t->value)->all();
        $this->assertSame(['AMOUNT_MISMATCH', 'MISSING_INTERNAL_PAYMENT', 'MISSING_REFUND', 'REFUND_AMOUNT_MISMATCH', 'SETTLEMENT_TOTAL_MISMATCH', 'UNKNOWN_REFERENCE'], $types);
        $amount = ReconciliationDifference::query()->where('type', 'AMOUNT_MISMATCH')->firstOrFail();
        $this->assertSame(['OPEN', 'HIGH', 'PROVIDER', 'fake', 50000, 49000, -1000, 'payment', (string) $this->paymentOf($short)->public_id], [$amount->status->value, $amount->severity, $amount->source, $amount->provider, $amount->expected_minor, $amount->actual_minor, $amount->difference_minor, $amount->subject_type, $amount->subject_public_id]);
        $this->assertSame([20000, 25000], [ReconciliationDifference::query()->where('type', 'REFUND_AMOUNT_MISMATCH')->firstOrFail()->expected_minor, ReconciliationDifference::query()->where('type', 'REFUND_AMOUNT_MISMATCH')->firstOrFail()->actual_minor]);
        $this->assertSame('LOW', ReconciliationDifference::query()->where('type', 'UNKNOWN_REFERENCE')->firstOrFail()->severity);

        // Only what matched is posted: two fees, and NOT the settlement (its total does not reconcile).
        $settlement = ProviderSettlement::query()->firstOrFail();
        $this->assertSame(['DIFFERENCES', null], [$settlement->reconciliation_status, $settlement->ledger_transaction_id]);
        $this->assertSame([2, 0], [LedgerTransaction::query()->where('type', 'PROVIDER_FEE')->count(), LedgerTransaction::query()->where('type', 'PROVIDER_SETTLEMENT_RECEIVED')->count()]);
        $this->assertEquals(['MATCHED' => 2, 'DIFFERENCE' => 2, 'UNMATCHED' => 3], ProviderSettlementItem::query()->selectRaw('match_status, count(*) as n')->groupBy('match_status')->pluck('n', 'match_status')->map(fn ($n): int => (int) $n)->all());

        // A payment we verified that NO provider record mentions, after the expected days: missing at the provider.
        $this->travel(6)->days();
        $result = app(ProviderSettlementReconciliationService::class)->reconcile('fake');
        $this->assertSame(1, $result['missing_at_provider']);
        $missing = ReconciliationDifference::query()->where('type', 'MISSING_PROVIDER_PAYMENT')->firstOrFail();
        $this->assertSame(['MEDIUM', (string) $this->paymentOf($absent)->public_id, 50000, null], [$missing->severity, $missing->subject_public_id, $missing->expected_minor, $missing->actual_minor]);
        // Found again, a difference is the same difference — seen again, not opened again.
        app(ProviderSettlementReconciliationService::class)->reconcile('fake');
        $this->assertSame([7, 2], [ReconciliationDifference::query()->count(), $missing->refresh()->seen_count]);

        // The provider reports the missing payment later: the rule that found the difference closes it.
        $this->provider->settleDay(CarbonImmutable::parse('2026-10-12'), [$this->line($absent)]);
        $this->import('2026-10-12');
        $this->assertSame(['RESOLVED', 'AUTO_MATCHED', 'The provider\'s settlement records mention the payment.'], [$missing->refresh()->status->value, $missing->resolution, $missing->resolution_note]);
        $this->assertNull($missing->resolved_by_admin_id);

        // THE FINANCE QUEUE: differences stay visible; a person takes one up and closes it with a resolution and a note.
        $queue = $this->financeGet('/differences?filter[open]=true&sort=-severity')->assertOk()->json();
        $this->assertSame([6, 6], [count($queue['data']), $queue['open']['total']]);
        $url = '/differences/'.$amount->public_id;
        $this->assertSame([true, true, true], array_values($this->financeGet($url)->assertOk()->json('difference.can')));
        $this->financePost($url.'/investigate', ['note' => 'Asked the provider'])->assertOk()->assertJsonPath('difference.status', 'INVESTIGATING');
        $this->financePost($url.'/resolve', ['resolution' => 'PROVIDER_CORRECTED'])->assertStatus(422)->assertJsonValidationErrors('note', 'error.details.fields');
        $this->financePost($url.'/resolve', ['resolution' => 'MAGIC', 'note' => 'TEST FIXTURE: not a resolution'])->assertStatus(422);
        // The numbers of a difference cannot be edited away.
        $this->financePost($url.'/resolve', ['resolution' => 'EXPLAINED', 'note' => 'TEST FIXTURE: editing the numbers', 'actual_minor' => 50000, 'difference_minor' => 0])->assertStatus(422)->assertJsonValidationErrors(['actual_minor', 'difference_minor'], 'error.details.fields');
        $resolved = $this->financePost($url.'/resolve', ['resolution' => 'PROVIDER_CORRECTED', 'note' => 'TEST FIXTURE: the provider confirmed a typing error in its report'])->assertOk()->json('difference');
        $this->assertSame(['RESOLVED', 'PROVIDER_CORRECTED', 49000, false], [$resolved['status'], $resolved['resolution'], $resolved['actual']['amount'], $resolved['can']['resolve']]);
        $audit = AuditEvent::query()->where('action', 'finance.difference_resolved')->whereNotNull('actor_public_id')->firstOrFail();
        $this->assertSame(['TEST FIXTURE: the provider confirmed a typing error in its report', 'INVESTIGATING', 'RESOLVED'], [$audit->reason, $audit->changes['status']['from'], $audit->changes['status']['to']]);
        $this->financePost($url.'/resolve', ['resolution' => 'EXPLAINED', 'note' => 'TEST FIXTURE: closing it twice'])->assertStatus(409);
        // Ignoring needs a reason too.
        $unknown = ReconciliationDifference::query()->where('type', 'UNKNOWN_REFERENCE')->firstOrFail();
        $this->financePost('/differences/'.$unknown->public_id.'/ignore', ['note' => 'no'])->assertStatus(422);
        $this->financePost('/differences/'.$unknown->public_id.'/ignore', ['note' => 'TEST FIXTURE: a provider test line, agreed to ignore'])->assertOk()->assertJsonPath('difference.status', 'IGNORED_WITH_REASON');
        // Resolved by a person and found AGAIN: it was not resolved — it is open again. Ignored stays ignored.
        app(ProviderSettlementReconciliationService::class)->reconcile('fake');
        $this->assertSame(['OPEN', 'IGNORED_WITH_REASON'], [$amount->refresh()->status->value, $unknown->refresh()->status->value]);

        // Reading needs admin.finance.reconciliation.view; importing and closing admin.finance.reconciliation.manage.
        $this->adminFor([Permission::AdminFinanceReconciliationView]);
        $this->financeGet('/differences')->assertOk();
        $this->financeGet('/provider-settlements')->assertOk();
        $this->financePost('/provider-settlements/import', ['day' => self::DAY])->assertForbidden();
        $this->financePost($url.'/resolve', ['resolution' => 'EXPLAINED', 'note' => 'TEST FIXTURE: without the permission'])->assertForbidden();
        $this->financePost('/checks', ['check' => 'LEDGER'])->assertForbidden();
        $this->adminFor([Permission::AdminFinanceView]);
        $this->financeGet('/differences')->assertForbidden();
        $this->financeGet('/provider-settlements')->assertForbidden();
    }

    public function test_a_provider_record_is_kept_as_it_was_first_reported_and_a_changed_one_is_a_difference(): void
    {
        $order = $this->completedOrderWith();
        $this->asFinance();
        $this->provider->settleDay(CarbonImmutable::parse(self::DAY), [$this->line($order)]);
        $this->import();
        $item = ProviderSettlementItem::query()->firstOrFail();
        $snapshot = $item->snapshot;
        $this->assertSame([50000, 49000, 1000, 153, 'payment'], [$snapshot['amount'], $snapshot['credit'], $snapshot['fee'], $snapshot['tax'], $snapshot['type']]);

        // The provider reports the same line with another fee.
        $this->provider->alter(CarbonImmutable::parse(self::DAY), (string) $item->entity_reference, ['fee' => 2500, 'credit' => 47500]);
        $run = $this->import();
        $this->assertSame([1, 'PROBLEMS'], [$run['result']['lines_changed'], $run['status']]);
        // The first report stays, and so does what was posted from it.
        $this->assertSame([1000, 49000, $snapshot], [$item->refresh()->fee_minor, $item->credit_minor, $item->snapshot]);
        $this->assertSame(1000, $this->platformBalance(LedgerAccountCode::ProviderFeeExpense) + $this->platformBalance(LedgerAccountCode::ProviderTaxExpense));
        $changed = ReconciliationDifference::query()->where('type', 'PROVIDER_RECORD_CHANGED')->firstOrFail();
        $this->assertSame([1000, 2500], [$changed->detail['first']['fee'], $changed->detail['now']['fee']]);

        // A fee the provider reports nonsensically (its tax larger than itself) is never posted.
        $other = $this->completedOrderWith();
        $this->asFinance();
        $this->provider->settleDay(CarbonImmutable::parse(self::DAY), [$this->line($other, 100, 500)]);
        $this->import();
        $this->assertSame(1, ReconciliationDifference::query()->where('type', 'FEE_MISMATCH')->count());
        $this->assertSame(1, LedgerTransaction::query()->where('type', 'PROVIDER_FEE')->count());

        // A provider that does not answer leaves a failed run and changes nothing; no import means no source.
        $this->provider->failNext(ProviderFailure::Timeout);
        $this->assertSame('FAILED', $this->import()['status']);
        $this->assertSame('TIMEOUT', FinanceRun::query()->where('kind', 'PROVIDER_IMPORT')->orderByDesc('id')->firstOrFail()->result['failure']);
        $this->financePost('/provider-settlements/import', ['day' => now()->addDay()->toDateString()])->assertStatus(422);
        $this->financePost('/provider-settlements/import', ['day' => self::DAY, 'items' => [['fee' => 1]], 'fee_minor' => 5])->assertStatus(422)->assertJsonValidationErrors(['items', 'fee_minor'], 'error.details.fields');
        config(['services.payment.driver' => null]);
        $this->financePost('/provider-settlements/import', ['day' => self::DAY])->assertStatus(409)->assertJsonPath('error.code', 'provider_settlements_unavailable');
    }

    public function test_the_razorpay_settlement_reader_maps_the_documented_fields_on_a_faked_http_layer_only(): void
    {
        // PENDING PROVIDER VERIFICATION: this proves the reader against the DOCUMENTED shape, not against Razorpay.
        // The reader is part of the Razorpay adapter: the one class that may hold the provider's credentials.
        $config = ['key_id' => 'rzp_test_'.'FixtureKeyId01', 'key_secret' => 'fixture-secret-not-real', 'webhook_secret' => 'fixture-webhook-not-real', 'base_url' => 'https://api.razorpay.test/v1', 'timeout' => 5, 'allow_live' => false];
        $source = new RazorpayPaymentProvider($config);
        $this->assertSame(['razorpay', 'TEST', []], [$source->name(), $source->mode(), $source->missingConfiguration()]);
        $this->assertInstanceOf(ProviderSettlementSource::class, $source);
        Http::fake([
            'api.razorpay.test/v1/settlements/recon/combined*' => Http::response(['entity' => 'collection', 'count' => 2, 'items' => [
                ['entity_id' => 'pay_FixtureAAA', 'type' => 'payment', 'debit' => 0, 'credit' => 97640, 'amount' => 100000, 'currency' => 'INR', 'fee' => 2360, 'tax' => 360, 'on_hold' => false, 'settled' => true,
                    'created_at' => 1759818600, 'settled_at' => 1759991400, 'settlement_id' => 'setl_FixtureSSS', 'settlement_utr' => 'UTR000111', 'payment_id' => null, 'card_network' => 'Visa', 'card_issuer' => 'HDFC', 'notes' => ['x' => 'y'], 'method' => 'card'],
                ['entity_id' => 'rfnd_FixtureBBB', 'type' => 'refund', 'debit' => 20000, 'credit' => 0, 'amount' => 20000, 'currency' => 'INR', 'fee' => 0, 'tax' => 0, 'on_hold' => false, 'settled' => true,
                    'created_at' => 1759818700, 'settled_at' => 1759991400, 'settlement_id' => 'setl_FixtureSSS', 'settlement_utr' => 'UTR000111', 'payment_id' => 'pay_FixtureAAA'],
            ]]),
            'api.razorpay.test/v1/settlements?*' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
                ['id' => 'setl_FixtureSSS', 'entity' => 'settlement', 'amount' => 77640, 'status' => 'processed', 'fees' => 0, 'tax' => 0, 'utr' => 'UTR000111', 'created_at' => 1759991400],
            ]]),
        ]);

        $lines = $source->lines(CarbonImmutable::parse('2026-10-09'), 0, 200);
        $this->assertCount(2, $lines);
        $this->assertSame(['pay_FixtureAAA', 'PAYMENT', 'INR', 100000, 97640, 0, 2360, 360, true, 'setl_FixtureSSS', 'UTR000111'], [$lines[0]->entityReference, $lines[0]->type, $lines[0]->currency, $lines[0]->amountMinor, $lines[0]->creditMinor, $lines[0]->debitMinor, $lines[0]->feeMinor, $lines[0]->taxMinor, $lines[0]->settled, $lines[0]->settlementReference, $lines[0]->bankReference]);
        $this->assertSame(['rfnd_FixtureBBB', 'REFUND', 20000, 20000, 'pay_FixtureAAA'], [$lines[1]->entityReference, $lines[1]->type, $lines[1]->amountMinor, $lines[1]->debitMinor, $lines[1]->paymentReference]);
        // Card, bank and free-text fields of a line are never kept.
        $this->assertSame([], array_intersect(array_keys($lines[0]->snapshot), ['card_network', 'card_issuer', 'notes', 'method']));
        $records = $source->settlements(CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-09')->endOfDay(), 0, 100);
        $this->assertSame(['setl_FixtureSSS', 'PROCESSED', 77640, 0, 'UTR000111'], [$records[0]->reference, $records[0]->status, $records[0]->amountMinor, $records[0]->feesMinor, $records[0]->bankReference]);

        // The documented request: basic authentication, the day as year / month / day, a count of at most 1000.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/settlements/recon/combined') && $r['year'] === 2026 && $r['month'] === 10 && $r['day'] === 9 && $r['count'] === 200 && $r['skip'] === 0
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_'.'FixtureKeyId01:fixture-secret-not-real')));

        // Live is refused, and so is a key that is not a test key; without credentials there is no source at all.
        $this->assertNotSame([], (new RazorpayPaymentProvider([...$config, 'key_id' => 'rzp_live_'.'FixtureKeyId01']))->missingConfiguration());
        $this->assertNotSame([], (new RazorpayPaymentProvider([...$config, 'key_id' => 'something_else']))->missingConfiguration());
        config(['services.payment.driver' => 'razorpay', 'services.razorpay.key_id' => null, 'services.razorpay.key_secret' => null]);
        $this->assertNull(app(ProviderSettlementSources::class)->current());
        $this->assertSame(['UNAVAILABLE', 'PENDING PROVIDER VERIFICATION'], [app(ProviderSettlementSources::class)->report()['status'], app(ProviderSettlementSources::class)->report()['razorpay_settlement_api']]);
        // A failing provider is a provider failure, never a guess.
        Http::fake(['down.razorpay.test/*' => Http::response(['error' => ['code' => 'SERVER_ERROR']], 503)]);
        $this->expectException(ProviderException::class);
        (new RazorpayPaymentProvider([...$config, 'base_url' => 'https://down.razorpay.test/v1']))->lines(CarbonImmutable::parse('2026-10-09'), 0, 10);
    }
}
