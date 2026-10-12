<?php

namespace Tests\Feature\Finance;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Exceptions\LedgerException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Finance\FinancialPostingService;
use App\Services\Finance\LedgerDraft;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — the ledger's own rules: every transaction balances, in one currency, once, and for ever.
 *
 * These are CODE INVARIANTS: no configuration changes them, and the database enforces them by itself.
 */
class LedgerTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_posting_service_refuses_a_transaction_that_does_not_balance_has_no_source_or_mixes_currencies(): void
    {
        $ledger = app(LedgerPostingService::class);
        $inr = $ledger->platform(LedgerAccountCode::PaymentClearing, 'INR');
        $fee = $ledger->platform(LedgerAccountCode::PlatformFeeRevenue, 'INR');
        $usd = $ledger->platform(LedgerAccountCode::PlatformFeeRevenue, 'USD');
        $draft = fn (string $key) => LedgerDraft::for($key, LedgerTransactionType::ManualAdjustment, 'INR', now())->source('order', 1, null, 'TEST');

        foreach ([
            'unbalanced' => $draft('test:unbalanced')->debit($inr, 1000, 'X')->credit($fee, 900, 'X'),
            'one line' => $draft('test:one-line')->debit($inr, 1000, 'X'),
            'no source' => LedgerDraft::for('test:no-source', LedgerTransactionType::ManualAdjustment, 'INR', now())->debit($inr, 1000, 'X')->credit($fee, 1000, 'X'),
            // INR and USD never meet in one transaction: there is no implicit conversion anywhere.
            'two currencies' => $draft('test:currencies')->debit($inr, 1000, 'X')->credit($usd, 1000, 'X'),
        ] as $case => $bad) {
            try {
                $ledger->post($bad);
                $this->fail($case.' was posted');
            } catch (LedgerException) {
                $this->assertFalse($ledger->exists($bad->key), $case);
            }
        }
        $this->assertSame(0, LedgerTransaction::query()->count());

        // A negative amount turns its line around instead of being stored as a negative number; a zero line is left out.
        $posted = $ledger->post($draft('test:signs')->debit($inr, -500, 'A')->credit($fee, -500, 'B')->credit($fee, 0, 'C'));
        $this->assertSame(['C PAYMENT_CLEARING 500', 'D PLATFORM_FEE_REVENUE 500'], $this->linesOf('test:signs'));
        $this->assertSame(500, $posted->amount_minor);
        $this->assertSame(0, LedgerEntry::query()->where('amount_minor', '<=', 0)->count());
        $this->assertLedgerBalanced();
    }

    public function test_the_database_itself_refuses_an_unbalanced_transaction_and_every_change_to_a_posted_one(): void
    {
        $this->commissionPlan(1000);
        [, , $order] = $this->completedPaidOrder();
        $transaction = $this->posting('order:'.$order->public_id.':recognized');
        $entry = $transaction->entries->first();

        // The model refuses …
        foreach ([fn () => $transaction->forceFill(['memo' => 'edited'])->save(), fn () => $transaction->delete(), fn () => $entry->forceFill(['amount_minor' => 1])->save(), fn () => $entry->delete()] as $change) {
            try {
                $change();
                $this->fail('A posted ledger row was changed through its model.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        // … and so does the database, for somebody who goes around the application.
        foreach ([
            'update ledger_entries set amount_minor = amount_minor + 1 where id = '.$entry->id,
            'delete from ledger_entries where id = '.$entry->id,
            "update ledger_transactions set memo = 'edited' where id = ".$transaction->id,
            'delete from ledger_transactions where id = '.$transaction->id,
        ] as $sql) {
            DB::beginTransaction();
            try {
                DB::statement($sql);
                $this->fail('The database accepted: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
        $this->assertSame(45000, $this->payableBalance());

        // An unbalanced set of entries written straight into the tables cannot be committed.
        DB::beginTransaction();
        try {
            $id = DB::table('ledger_transactions')->insertGetId(['public_id' => (string) Str::uuid(), 'posting_key' => 'test:raw', 'type' => 'MANUAL_ADJUSTMENT', 'source_type' => 'order', 'source_id' => $order->id,
                'currency' => 'INR', 'amount_minor' => 100, 'occurred_at' => now(), 'posted_at' => now()]);
            DB::table('ledger_entries')->insert(['ledger_transaction_id' => $id, 'account_id' => $entry->account_id, 'line_no' => 1, 'direction' => 'D', 'amount_minor' => 100, 'currency' => 'INR', 'description_code' => 'RAW', 'occurred_at' => now(), 'created_at' => now()]);
            DB::statement('set constraints ledger_entries_balanced immediate');
            $this->fail('An unbalanced transaction passed the database check.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('does not balance', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        // An entry in another currency than its transaction or its account is refused by a foreign key.
        DB::beginTransaction();
        try {
            DB::table('ledger_entries')->insert(['ledger_transaction_id' => $transaction->id, 'account_id' => $entry->account_id, 'line_no' => 99, 'direction' => 'D', 'amount_minor' => 100, 'currency' => 'USD', 'description_code' => 'RAW', 'occurred_at' => now(), 'created_at' => now()]);
            $this->fail('A USD entry entered an INR transaction.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    public function test_the_same_event_delivered_again_posts_once_whoever_confirms_the_payment(): void
    {
        $this->commissionPlan(1000);
        [, , , $payment, $order] = $this->paidOrder();
        $attempt = $this->attempt($payment['id']);
        $postings = app(FinancialPostingService::class);
        $ledger = app(LedgerPostingService::class);

        // The client's verification posted it. The webhook, the reconciliation and the sweep confirm the SAME payment.
        $first = $ledger->find('payment:'.$payment['id'].':verified');
        $again = $postings->paymentVerified($attempt);
        $this->assertSame($first->id, $again->id);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->providerSends('payment.captured', $this->fakeProvider()->payment((string) $attempt->provider_payment_reference))->assertOk();
        $postings->sweep();
        $postings->orderChanged($this->orderModel($order['order_number']));

        $this->assertSame(1, LedgerTransaction::query()->where('type', 'PAYMENT_VERIFIED')->count());
        $this->assertSame(50000, $this->providerClearing());
        $this->assertSame(50000, $this->platformBalance(LedgerAccountCode::PaymentClearing));
        // One account per code, currency and owner: asked for twice, it is the same row.
        $this->assertSame($ledger->platform(LedgerAccountCode::PaymentClearing, 'INR')->id, $ledger->platform(LedgerAccountCode::PaymentClearing, 'INR')->id);
        $this->assertNotSame($ledger->platform(LedgerAccountCode::PaymentClearing, 'INR')->id, $ledger->platform(LedgerAccountCode::PaymentClearing, 'USD')->id);
    }

    public function test_a_correction_is_a_new_transaction_and_a_reversal_is_the_exact_opposite_once(): void
    {
        $this->commissionPlan(1000);
        [, , $order] = $this->completedPaidOrder();
        $ledger = app(LedgerPostingService::class);
        $original = $ledger->find('order:'.$order->public_id.':recognized');
        $before = $original->entries()->get()->map(fn ($e) => [$e->direction, $e->amount_minor])->all();

        $reversal = $ledger->reverse($original, 'order:'.$order->public_id.':recognized:reversal', 'TEST: reversing to show the mechanism');
        $this->assertSame(['C PAYMENT_CLEARING 50000', 'D RESTAURANT_PAYABLE 45000', 'D PLATFORM_COMMISSION_REVENUE 5000'], $this->linesOf($reversal->posting_key));
        $this->assertSame([LedgerTransactionType::Reversal, $original->id], [$reversal->type, $reversal->reverses_transaction_id]);
        // The original is untouched; the two together are zero.
        $this->assertSame($before, $original->entries()->get()->map(fn ($e) => [$e->direction, $e->amount_minor])->all());
        $this->assertSame(0, $this->payableBalance());
        $this->assertSame(0, $this->platformBalance(LedgerAccountCode::PlatformCommissionRevenue));
        // Asked again, the same reversal; a second reversal of the same transaction under another key is refused.
        $this->assertSame($reversal->id, $ledger->reverse($original, $reversal->posting_key, 'again')->id);
        try {
            $ledger->reverse($original, 'another-key', 'twice');
            $this->fail('A transaction was reversed twice.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertLedgerBalanced();
    }

    public function test_no_route_writes_the_ledger_edits_a_balance_or_marks_anything_as_paid(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r): string => implode('|', $r->methods()).' '.$r->uri());

        // The ledger is read-only through the API: three GET routes and nothing else.
        $this->assertSame(['GET|HEAD api/v1/admin/finance/ledger/accounts', 'GET|HEAD api/v1/admin/finance/ledger/transactions', 'GET|HEAD api/v1/admin/finance/ledger/transactions/{ledgerTransaction}'],
            $routes->filter(fn (string $r): bool => str_contains($r, 'ledger'))->sort()->values()->all());
        foreach (['ledger-entry', 'ledger-entries', 'ledger_entries', 'balance', 'mark-paid', 'mark_paid', 'markpaid', 'set-status', 'force'] as $word) {
            $this->assertSame([], $routes->filter(fn (string $r): bool => str_contains(strtolower($r), $word))->values()->all(), $word);
        }
        // Nothing of finance can be replaced or removed, and a customer has no finance route at all.
        $this->assertSame([], $routes->filter(fn (string $r): bool => str_contains($r, '/finance') && preg_match('/^(PUT|PATCH|DELETE)/', $r) === 1)->values()->all());
        $this->assertSame([], $routes->filter(fn (string $r): bool => str_contains($r, 'api/v1/customer') && preg_match('/finance|settlement|payout|commission|earning|ledger/', $r) === 1)->values()->all());

        $this->asFinance();
        foreach (['/ledger/transactions', '/ledger/accounts', '/ledger/entries'] as $path) {
            $this->financePost($path, ['account' => 'RESTAURANT_PAYABLE', 'direction' => 'C', 'amount_minor' => 100000])->assertStatus($path === '/ledger/entries' ? 404 : 405);
        }
        $this->assertSame(0, LedgerTransaction::query()->count());
    }
}
