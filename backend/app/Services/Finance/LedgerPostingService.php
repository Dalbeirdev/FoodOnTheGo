<?php

namespace App\Services\Finance;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Exceptions\LedgerException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\RestaurantLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The ONLY writer of the financial ledger (Module 36). There is no route, no controller and no model method that
 * creates a ledger entry another way.
 *
 * CODE INVARIANTS (not policy — these never depend on configuration):
 *
 *   - one business event is one transaction: the posting key is unique, and posting the same key again returns the
 *     transaction that exists (a repeated event, a retried job or two workers at once post once);
 *   - a transaction has a source record, at least two lines, ONE currency, positive amounts, and debits = credits —
 *     checked here, and checked again by the database before the transaction can be committed;
 *   - an entry is in the currency of its account: balances never mix currencies;
 *   - nothing posted is changed or removed: a correction is a new transaction (reverse() makes the exact opposite).
 */
final class LedgerPostingService
{
    public function __construct(private readonly FinanceMetrics $metrics) {}

    /**
     * Posts the draft once. The returned transaction has wasRecentlyCreated = true only when THIS call wrote it.
     *
     * @throws LedgerException when the draft is not a valid, balanced, single-currency transaction
     */
    public function post(LedgerDraft $draft): LedgerTransaction
    {
        if (($existing = $this->find($draft->key)) !== null) {
            $this->metrics->record('posting_repeated');

            return $existing;
        }
        $this->assertValid($draft);

        try {
            // Its own transaction (a savepoint inside the caller's): a lost race on the key undoes only this posting.
            $transaction = DB::transaction(fn (): LedgerTransaction => $this->write($draft));
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->find($draft->key) ?? throw $e;
            $this->metrics->record('posting_repeated');

            return $existing;
        }
        $this->metrics->record('posting');
        $this->metrics->record('posting.'.strtolower($draft->type->value));
        Log::info('finance.ledger_posted', ['transaction' => $transaction->public_id, 'type' => $draft->type->value, 'key' => $draft->key, 'amount_minor' => $transaction->amount_minor, 'currency' => $draft->currency]);

        return $transaction;
    }

    public function find(string $key): ?LedgerTransaction
    {
        return LedgerTransaction::query()->where('posting_key', $key)->first();
    }

    public function exists(string $key): bool
    {
        return LedgerTransaction::query()->where('posting_key', $key)->exists();
    }

    /**
     * The exact opposite of a posted transaction, as a transaction of its own. A transaction is reversed at most once.
     */
    public function reverse(LedgerTransaction $transaction, string $key, string $memo, ?int $adminId = null): LedgerTransaction
    {
        $draft = LedgerDraft::for($key, LedgerTransactionType::Reversal, (string) $transaction->currency, CarbonImmutable::now())
            ->source((string) $transaction->source_type, (int) $transaction->source_id, $transaction->source_public_id, $transaction->source_reference)
            ->dimensions($transaction->market_id, $transaction->organization_id, $transaction->location_id)
            ->explained($memo, $adminId, ['reverses' => $transaction->public_id])
            ->reverses((int) $transaction->getKey());
        foreach ($transaction->entries()->with('account')->get() as $entry) {
            $entry->direction === LedgerEntry::DEBIT
                ? $draft->credit($entry->account, (int) $entry->amount_minor, 'REVERSAL')
                : $draft->debit($entry->account, (int) $entry->amount_minor, 'REVERSAL');
        }

        return $this->post($draft);
    }

    /**
     * The platform's account for a code and a currency — made the first time something is posted to it.
     */
    public function platform(LedgerAccountCode $code, string $currency): LedgerAccount
    {
        return $this->account($code, $currency, LedgerAccount::OWNER_PLATFORM);
    }

    /**
     * What the payment provider holds for the platform, in a currency.
     */
    public function provider(string $provider, string $currency): LedgerAccount
    {
        return $this->account(LedgerAccountCode::ProviderClearing, $currency, LedgerAccount::OWNER_PROVIDER, provider: strtolower($provider));
    }

    /**
     * What the platform owes a restaurant location, in a currency.
     */
    public function payable(RestaurantLocation $location, string $currency): LedgerAccount
    {
        return $this->account(LedgerAccountCode::RestaurantPayable, $currency, LedgerAccount::OWNER_LOCATION, $location);
    }

    public function payableOf(int $locationId, string $currency): ?LedgerAccount
    {
        return LedgerAccount::query()->where('code', LedgerAccountCode::RestaurantPayable->value)->where('currency', $currency)->where('location_id', $locationId)->first();
    }

    /**
     * The account's balance on its own side: positive is the normal state (an asset held, a liability owed).
     */
    public function balance(LedgerAccount $account): int
    {
        $sums = DB::table('ledger_entries')->where('account_id', $account->getKey())
            ->selectRaw("coalesce(sum(case when direction = 'D' then amount_minor else 0 end), 0) as debits, coalesce(sum(case when direction = 'C' then amount_minor else 0 end), 0) as credits")->first();
        $debits = (int) ($sums->debits ?? 0);
        $credits = (int) ($sums->credits ?? 0);

        return $account->normal_side === LedgerEntry::DEBIT ? $debits - $credits : $credits - $debits;
    }

    /* ------------------------------------------------------------------ internals */

    private function write(LedgerDraft $draft): LedgerTransaction
    {
        $now = CarbonImmutable::now();
        $transaction = new LedgerTransaction;
        $transaction->forceFill([
            'posting_key' => $draft->key,
            'type' => $draft->type,
            'source_type' => $draft->sourceType,
            'source_id' => $draft->sourceId,
            'source_public_id' => $draft->sourcePublicId,
            'source_reference' => $draft->sourceReference,
            'currency' => $draft->currency,
            'amount_minor' => $draft->debits(),
            'market_id' => $draft->marketId,
            'organization_id' => $draft->organizationId,
            'location_id' => $draft->locationId,
            'reverses_transaction_id' => $draft->reversesTransactionId,
            'created_by_admin_id' => $draft->adminId,
            'memo' => $draft->memo,
            'detail' => $draft->detail === [] ? null : $draft->detail,
            'occurred_at' => $draft->occurredAt,
            'posted_at' => $now,
        ])->save();

        $occurred = CarbonImmutable::instance($draft->occurredAt)->utc()->format('Y-m-d H:i:sP');
        $rows = [];
        foreach ($draft->lines() as $index => $line) {
            $account = $line['account'];
            $rows[] = [
                'ledger_transaction_id' => $transaction->getKey(),
                'account_id' => $account->getKey(),
                'line_no' => $index + 1,
                'direction' => $line['direction'],
                'amount_minor' => $line['amount'],
                'currency' => $draft->currency,
                'description_code' => $line['code'],
                'market_id' => $draft->marketId,
                // A restaurant's payable line is the restaurant's whatever the transaction is about.
                'organization_id' => $account->organization_id ?? $draft->organizationId,
                'location_id' => $account->location_id ?? $draft->locationId,
                'occurred_at' => $occurred,
                'created_at' => $now->utc()->format('Y-m-d H:i:sP'),
            ];
        }
        DB::table('ledger_entries')->insert($rows);
        // The database's own check, now — not only at the commit: an unbalanced posting never leaves this method.
        DB::statement('set constraints ledger_entries_balanced, ledger_transactions_has_entries immediate');
        DB::statement('set constraints ledger_entries_balanced, ledger_transactions_has_entries deferred');

        return $transaction;
    }

    private function assertValid(LedgerDraft $draft): void
    {
        try {
            if ($draft->sourceType === '' || $draft->sourceId <= 0) {
                throw LedgerException::invalid($draft->key, 'it has no source record');
            }
            if (count($draft->lines()) < 2) {
                throw LedgerException::invalid($draft->key, 'it has fewer than two lines');
            }
            foreach ($draft->lines() as $line) {
                if ($line['account']->currency !== $draft->currency) {
                    throw LedgerException::currency($draft->key, $draft->currency, (string) $line['account']->currency);
                }
            }
            if ($draft->debits() !== $draft->credits()) {
                throw LedgerException::unbalanced($draft->key, $draft->debits(), $draft->credits());
            }
        } catch (LedgerException $e) {
            $this->metrics->record('ledger_refused');
            Log::critical('finance.ledger_posting_refused', ['key' => $draft->key, 'type' => $draft->type->value, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function account(LedgerAccountCode $code, string $currency, string $ownerType, ?RestaurantLocation $location = null, ?string $provider = null): LedgerAccount
    {
        $find = fn (): ?LedgerAccount => LedgerAccount::query()->where('code', $code->value)->where('currency', $currency)->where('owner_type', $ownerType)
            ->when($location !== null, fn ($q) => $q->where('location_id', $location->getKey()), fn ($q) => $q->whereNull('location_id'))
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider), fn ($q) => $q->whereNull('provider'))
            ->first();
        if (($account = $find()) !== null) {
            return $account;
        }
        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:sP');
        DB::table('ledger_accounts')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'code' => $code->value,
            'normal_side' => $code->normalSide(),
            'currency' => $currency,
            'owner_type' => $ownerType,
            'location_id' => $location?->getKey(),
            'organization_id' => $location?->organization_id,
            'provider' => $provider,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $find() ?? throw LedgerException::invalid($code->value, 'its account could not be made');
    }
}
