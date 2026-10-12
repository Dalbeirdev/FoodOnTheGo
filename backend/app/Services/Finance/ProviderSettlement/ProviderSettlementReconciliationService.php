<?php

namespace App\Services\Finance\ProviderSettlement;

use App\Contracts\Finance\ProviderSettlementSource;
use App\Enums\DifferenceType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ProviderException;
use App\Models\AdminUser;
use App\Models\FinanceRun;
use App\Models\PaymentAttempt;
use App\Models\ProviderSettlement;
use App\Models\ProviderSettlementItem;
use App\Models\ReconciliationDifference;
use App\Models\Refund;
use App\Services\Finance\DifferenceService;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\LedgerDraft;
use App\Services\Finance\LedgerPostingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Imports the payment provider's settlement records and compares them with the platform's own (Module 36):
 * "does what FoodOnTheGo recorded match what the provider reports?"
 *
 * IMPORT
 *   - the provider's lines of a day are read page by page and kept as reported; a line is ONE row for ever (unique by
 *     provider, type and the provider's id) — imported twice, it is one record;
 *   - a line that comes back with other numbers than the first time is not overwritten: it is a difference;
 *   - one import per provider runs at a time.
 *
 * RECONCILIATION — each rule is deterministic, and each difference stays until the rule sees agreement or a person
 * closes it with a reason:
 *   payment line    ↔ our verified payment with the provider's payment reference: exists, same amount, same currency
 *   refund line     ↔ our completed refund with the provider's refund reference:  exists, same amount, same currency
 *   anything else   → a line we cannot interpret: a person looks
 *   provider fee    → posted from the line's own fee and tax (Dr PROVIDER_FEE_EXPENSE + PROVIDER_TAX_EXPENSE /
 *                     Cr PROVIDER_CLEARING), for a matched line only. No fee is ever calculated: the platform bears
 *                     what the provider reported, and nothing when it reported nothing.
 *   settlement      ↔ its lines: the provider's credits minus its debits are the settlement's amount; every line matched.
 *                     A settlement that reconciles and was PROCESSED is posted as received
 *                     (Dr PLATFORM_BANK / Cr PROVIDER_CLEARING).
 *   our payment /
 *   refund          → mentioned by no provider line after the expected number of days: missing at the provider.
 */
final class ProviderSettlementReconciliationService
{
    public function __construct(
        private readonly ProviderSettlementSources $sources,
        private readonly LedgerPostingService $ledger,
        private readonly DifferenceService $differences,
        private readonly FinanceMetrics $metrics,
    ) {}

    /**
     * Imports the provider's records of one day and reconciles.
     *
     * @return FinanceRun the record of the run, with what it found
     *
     * @throws ApiException 409 when no provider settlement source can be used, or an import is running
     */
    public function import(CarbonInterface $day, ?AdminUser $admin = null, string $trigger = FinanceRun::BY_COMMAND): FinanceRun
    {
        $source = $this->sources->current() ?? throw ApiException::conflict('provider_settlements_unavailable', 'No provider settlement records can be read here: the payment provider is not configured for it.');
        $lock = Cache::lock('finance:provider-import:'.$source->name(), 600);
        if (! $lock->get()) {
            throw ApiException::conflict('import_running', 'An import of this provider\'s settlement records is running.');
        }
        $day = CarbonImmutable::instance($day)->utc()->startOfDay();
        $run = new FinanceRun;
        $run->forceFill(['kind' => FinanceRun::PROVIDER_IMPORT, 'status' => 'RUNNING', 'triggered_by' => $admin === null ? $trigger : FinanceRun::BY_ADMIN, 'admin_user_id' => $admin?->getKey(),
            'parameters' => ['provider' => $source->name(), 'day' => $day->toDateString()], 'started_at' => now()])->save();
        $result = ['provider' => $source->name(), 'day' => $day->toDateString(), 'lines_read' => 0, 'lines_new' => 0, 'lines_repeated' => 0, 'lines_changed' => 0, 'settlements_read' => 0, 'settlements_new' => 0];
        try {
            $size = max(1, min(1000, (int) config('finance.provider_reconciliation.page_size')));
            for ($page = 0; $page < (int) config('finance.provider_reconciliation.max_pages'); $page++) {
                $lines = $source->lines($day, $page * $size, $size);
                foreach ($lines as $line) {
                    $result['lines_read']++;
                    $result[$this->keepLine($source, $line)]++;
                }
                if (count($lines) < $size) {
                    break;
                }
            }
            for ($page = 0; $page < (int) config('finance.provider_reconciliation.max_pages'); $page++) {
                $records = $source->settlements($day, $day->endOfDay(), $page * 100, 100);
                foreach ($records as $record) {
                    $result['settlements_read']++;
                    $result['settlements_new'] += $this->keepSettlement($source, $record) ? 1 : 0;
                }
                if (count($records) < 100) {
                    break;
                }
            }
            $result['reconciliation'] = $this->reconcile($source->name());
            $problems = (int) $result['lines_changed'] + (int) $result['reconciliation']['differences_open'];
            $run->forceFill(['status' => $problems > 0 ? 'PROBLEMS' : 'OK', 'result' => $result, 'problems' => $problems, 'finished_at' => now()])->save();
            $this->metrics->record('provider_import');
        } catch (ProviderException $e) {
            $run->forceFill(['status' => 'FAILED', 'result' => [...$result, 'failure' => $e->failure->value], 'problems' => 1, 'finished_at' => now()])->save();
            Log::warning('finance.provider_import_failed', ['provider' => $source->name(), 'day' => $day->toDateString(), 'failure' => $e->failure->value]);
        } finally {
            $lock->release();
        }

        return $run;
    }

    /**
     * Compares what was imported with our own records. Repeatable: a matched line stays matched, a difference found
     * again is the same difference.
     *
     * @return array{items_checked: int, items_matched: int, fees_posted: int, settlements_checked: int, settlements_matched: int, missing_at_provider: int, differences_open: int}
     */
    public function reconcile(string $provider): array
    {
        $done = ['items_checked' => 0, 'items_matched' => 0, 'fees_posted' => 0, 'settlements_checked' => 0, 'settlements_matched' => 0, 'missing_at_provider' => 0, 'differences_open' => 0];

        ProviderSettlementItem::query()->where('provider', $provider)->where('match_status', '<>', ProviderSettlementItem::MATCH_MATCHED)->orderBy('id')
            ->chunkById(200, function ($items) use (&$done): void {
                foreach ($items as $item) {
                    $done['items_checked']++;
                    DB::transaction(function () use ($item, &$done): void {
                        $locked = ProviderSettlementItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
                        if ($locked->match_status === ProviderSettlementItem::MATCH_MATCHED) {
                            return;
                        }
                        $status = $this->matchItem($locked);
                        if ($status === ProviderSettlementItem::MATCH_MATCHED) {
                            $done['items_matched']++;
                            $done['fees_posted'] += $this->postFee($locked) ? 1 : 0;
                        }
                        $locked->forceFill(['match_status' => $status])->save();
                    });
                }
            });

        foreach (ProviderSettlement::query()->where('provider', $provider)->where('reconciliation_status', '<>', ProviderSettlement::RECON_MATCHED)->orderBy('id')->get() as $settlement) {
            $done['settlements_checked']++;
            $done['settlements_matched'] += DB::transaction(fn (): bool => $this->matchSettlement(ProviderSettlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail())) ? 1 : 0;
        }

        $done['missing_at_provider'] = $this->missingAtProvider($provider);
        $done['differences_open'] = ReconciliationDifference::query()->where('source', ReconciliationDifference::SOURCE_PROVIDER)->where('provider', $provider)->unresolved()->count();

        return $done;
    }

    /* ------------------------------------------------------------------ import */

    /**
     * @return 'lines_new'|'lines_repeated'|'lines_changed'
     */
    private function keepLine(ProviderSettlementSource $source, ProviderSettlementLine $line): string
    {
        $existing = ProviderSettlementItem::query()->where('provider', $source->name())->where('type', $line->type)->where('entity_reference', $line->entityReference)->first();
        if ($existing === null) {
            $inserted = DB::table('provider_settlement_items')->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'provider' => $source->name(),
                'provider_settlement_reference' => $line->settlementReference,
                'entity_reference' => $line->entityReference,
                'type' => $line->type,
                'currency' => $line->currency,
                'amount_minor' => $line->amountMinor,
                'credit_minor' => $line->creditMinor,
                'debit_minor' => $line->debitMinor,
                'fee_minor' => $line->feeMinor,
                'tax_minor' => $line->taxMinor,
                'settled' => $line->settled,
                'on_hold' => $line->onHold,
                'payment_reference' => $line->paymentReference,
                'bank_reference' => $line->bankReference,
                'provider_created_at' => $line->createdAt?->format('Y-m-d H:i:sP'),
                'settled_at' => $line->settledAt?->format('Y-m-d H:i:sP'),
                'match_status' => ProviderSettlementItem::MATCH_PENDING,
                'snapshot' => json_encode($line->snapshot, JSON_THROW_ON_ERROR),
                'imported_at' => now()->utc()->format('Y-m-d H:i:sP'),
                'created_at' => now()->utc()->format('Y-m-d H:i:sP'),
                'updated_at' => now()->utc()->format('Y-m-d H:i:sP'),
            ]);
            $this->metrics->record($inserted > 0 ? 'provider_item_imported' : 'provider_item_repeated');

            return $inserted > 0 ? 'lines_new' : 'lines_repeated';
        }
        $this->metrics->record('provider_item_repeated');
        $same = (int) $existing->amount_minor === $line->amountMinor && (int) $existing->credit_minor === $line->creditMinor && (int) $existing->debit_minor === $line->debitMinor
            && (int) $existing->fee_minor === $line->feeMinor && (int) $existing->tax_minor === $line->taxMinor && $existing->currency === $line->currency;
        if (! $same) {
            // The first report stays. What the provider says now is a difference a person looks at.
            $this->differences->report(DifferenceType::ProviderRecordChanged, 'provider-item:'.$source->name().':'.$line->type.':'.$line->entityReference.':changed', [
                'provider' => $source->name(), 'provider_reference' => $line->entityReference, 'subject_type' => 'provider_settlement_item', 'subject_id' => (int) $existing->getKey(),
                'subject_public_id' => (string) $existing->public_id, 'currency' => (string) $existing->currency, 'expected_minor' => (int) $existing->amount_minor, 'actual_minor' => $line->amountMinor,
                'detail' => ['first' => ['credit' => (int) $existing->credit_minor, 'debit' => (int) $existing->debit_minor, 'fee' => (int) $existing->fee_minor, 'tax' => (int) $existing->tax_minor, 'currency' => $existing->currency],
                    'now' => ['credit' => $line->creditMinor, 'debit' => $line->debitMinor, 'fee' => $line->feeMinor, 'tax' => $line->taxMinor, 'currency' => $line->currency]],
            ]);

            return 'lines_changed';
        }
        // A line that was reported before it was settled is the same line, further along.
        if (! $existing->settled && $line->settled || $existing->provider_settlement_reference === null && $line->settlementReference !== null) {
            $existing->forceFill(['settled' => $line->settled, 'on_hold' => $line->onHold, 'settled_at' => $line->settledAt, 'provider_settlement_reference' => $line->settlementReference, 'bank_reference' => $line->bankReference])->save();
        }

        return 'lines_repeated';
    }

    private function keepSettlement(ProviderSettlementSource $source, ProviderSettlementRecord $record): bool
    {
        $existing = ProviderSettlement::query()->where('provider', $source->name())->where('provider_settlement_reference', $record->reference)->first();
        if ($existing !== null) {
            // The provider's settlement moves from created to processed (or failed): its state is followed, its numbers are not rewritten.
            if ((int) $existing->amount_minor !== $record->amountMinor || $existing->currency !== $record->currency) {
                $this->differences->report(DifferenceType::ProviderRecordChanged, 'provider-settlement:'.$source->name().':'.$record->reference.':changed', [
                    'provider' => $source->name(), 'provider_reference' => $record->reference, 'subject_type' => 'provider_settlement', 'subject_id' => (int) $existing->getKey(), 'subject_public_id' => (string) $existing->public_id,
                    'currency' => (string) $existing->currency, 'expected_minor' => (int) $existing->amount_minor, 'actual_minor' => $record->amountMinor,
                ]);
            } elseif ($existing->status !== $record->status) {
                $existing->forceFill(['status' => $record->status, 'provider_status' => $record->providerStatus, 'bank_reference' => $existing->bank_reference ?? $record->bankReference,
                    'settled_at' => $record->status === 'PROCESSED' ? ($existing->settled_at ?? $record->createdAt) : $existing->settled_at,
                    'reconciliation_status' => $existing->reconciliation_status === ProviderSettlement::RECON_MATCHED && $existing->ledger_transaction_id === null ? ProviderSettlement::RECON_PENDING : $existing->reconciliation_status])->save();
            }

            return false;
        }
        $now = now()->utc()->format('Y-m-d H:i:sP');

        return DB::table('provider_settlements')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'provider' => $source->name(),
            'provider_mode' => strtolower($source->mode()),
            'provider_settlement_reference' => $record->reference,
            'currency' => $record->currency,
            'status' => $record->status,
            'provider_status' => $record->providerStatus,
            'amount_minor' => $record->amountMinor,
            'fees_minor' => $record->feesMinor,
            'tax_minor' => $record->taxMinor,
            'bank_reference' => $record->bankReference,
            'provider_created_at' => $record->createdAt?->format('Y-m-d H:i:sP'),
            'settled_at' => $record->status === 'PROCESSED' ? $record->createdAt?->format('Y-m-d H:i:sP') : null,
            'snapshot' => json_encode($record->snapshot, JSON_THROW_ON_ERROR),
            'imported_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]) > 0;
    }

    /* ------------------------------------------------------------------ reconciliation */

    /**
     * @return string the line's match status
     */
    private function matchItem(ProviderSettlementItem $item): string
    {
        $key = 'provider-item:'.$item->provider.':'.$item->type.':'.$item->entity_reference;
        $facts = ['provider' => (string) $item->provider, 'provider_reference' => (string) $item->entity_reference, 'currency' => (string) $item->currency];

        if ($item->type === ProviderSettlementItem::TYPE_PAYMENT) {
            $payment = PaymentAttempt::query()->where('provider', $item->provider)->where('provider_payment_reference', $item->entity_reference)->first();
            if ($payment === null || $payment->status !== PaymentStatus::Verified) {
                $this->differences->report(DifferenceType::MissingInternalPayment, $key.':missing-internal', [...$facts, 'actual_minor' => (int) $item->amount_minor, 'expected_minor' => null,
                    'subject_type' => $payment === null ? null : 'payment', 'subject_id' => $payment?->getKey(), 'subject_public_id' => $payment?->public_id, 'market_id' => $payment?->market_id,
                    'detail' => ['internal_status' => $payment?->status->value ?? 'NO_SUCH_PAYMENT']]);

                return ProviderSettlementItem::MATCH_UNMATCHED;
            }
            $this->differences->settled($key.':missing-internal', 'The payment exists and is verified.');
            $item->forceFill(['payment_attempt_id' => $payment->getKey()]);
            $subject = ['subject_type' => 'payment', 'subject_id' => (int) $payment->getKey(), 'subject_public_id' => (string) $payment->public_id, 'market_id' => $payment->market_id, 'location_id' => $payment->location_id];
            $currencyOk = $this->check($payment->currency === $item->currency, DifferenceType::CurrencyMismatch, $key.':currency', [...$facts, ...$subject, 'detail' => ['internal_currency' => $payment->currency, 'provider_currency' => $item->currency]]);
            $amountOk = $this->check((int) $payment->amount_minor === (int) $item->amount_minor, DifferenceType::AmountMismatch, $key.':amount', [...$facts, ...$subject, 'expected_minor' => (int) $payment->amount_minor, 'actual_minor' => (int) $item->amount_minor]);
            $this->differences->settled('payment:'.$payment->public_id.':missing-provider', 'The provider\'s settlement records mention the payment.');

            return $currencyOk && $amountOk && $this->feeOk($item, $key, [...$facts, ...$subject]) ? ProviderSettlementItem::MATCH_MATCHED : ProviderSettlementItem::MATCH_DIFFERENCE;
        }

        if ($item->type === ProviderSettlementItem::TYPE_REFUND) {
            $refund = Refund::query()->where('provider', $item->provider)->where('provider_refund_reference', $item->entity_reference)->first();
            if ($refund === null) {
                $this->differences->report(DifferenceType::MissingRefund, $key.':missing-internal', [...$facts, 'actual_minor' => (int) $item->amount_minor, 'expected_minor' => null, 'detail' => ['provider_payment_reference' => $item->payment_reference]]);

                return ProviderSettlementItem::MATCH_UNMATCHED;
            }
            $this->differences->settled($key.':missing-internal', 'The refund exists.');
            $item->forceFill(['refund_id' => $refund->getKey(), 'payment_attempt_id' => $refund->payment_attempt_id]);
            if ($refund->status !== RefundStatus::Refunded) {
                // The provider settled it before our own confirmation arrived: looked at again on the next run.
                return ProviderSettlementItem::MATCH_PENDING;
            }
            $subject = ['subject_type' => 'refund', 'subject_id' => (int) $refund->getKey(), 'subject_public_id' => (string) $refund->public_id, 'subject_reference' => $refund->order_number, 'market_id' => $refund->market_id, 'location_id' => $refund->location_id];
            $currencyOk = $this->check($refund->currency === $item->currency, DifferenceType::CurrencyMismatch, $key.':currency', [...$facts, ...$subject, 'detail' => ['internal_currency' => $refund->currency, 'provider_currency' => $item->currency]]);
            $amountOk = $this->check($refund->amountMinor() === (int) $item->amount_minor, DifferenceType::RefundAmountMismatch, $key.':amount', [...$facts, ...$subject, 'expected_minor' => $refund->amountMinor(), 'actual_minor' => (int) $item->amount_minor]);
            $this->differences->settled('refund:'.$refund->public_id.':missing-provider', 'The provider\'s settlement records mention the refund.');

            return $currencyOk && $amountOk && $this->feeOk($item, $key, [...$facts, ...$subject]) ? ProviderSettlementItem::MATCH_MATCHED : ProviderSettlementItem::MATCH_DIFFERENCE;
        }

        $this->differences->report(DifferenceType::UnknownReference, $key.':unknown', [...$facts, 'actual_minor' => (int) $item->credit_minor - (int) $item->debit_minor, 'expected_minor' => null, 'detail' => ['type' => $item->type]]);

        return ProviderSettlementItem::MATCH_UNMATCHED;
    }

    /**
     * The provider's fee for a line, as the provider reported it: a fee is not negative and the tax is a part of it.
     * Anything else is not posted — it is a difference.
     *
     * @param  array<string, mixed>  $facts
     */
    private function feeOk(ProviderSettlementItem $item, string $key, array $facts): bool
    {
        $fee = (int) $item->fee_minor;
        $tax = (int) $item->tax_minor;

        return $this->check($fee >= 0 && $tax >= 0 && $tax <= $fee, DifferenceType::FeeMismatch, $key.':fee', [...$facts, 'expected_minor' => null, 'actual_minor' => $fee, 'detail' => ['fee' => $fee, 'tax' => $tax, 'rule' => '0 <= tax <= fee']]);
    }

    private function postFee(ProviderSettlementItem $item): bool
    {
        $fee = (int) $item->fee_minor;
        if ($fee <= 0 || $item->fee_transaction_id !== null) {
            return false;
        }
        $tax = (int) $item->tax_minor;
        $currency = (string) $item->currency;
        $payment = $item->payment_attempt_id === null ? null : PaymentAttempt::query()->find($item->payment_attempt_id);
        $transaction = $this->ledger->post(
            LedgerDraft::for('provider-item:'.$item->provider.':'.$item->type.':'.$item->entity_reference.':fee', LedgerTransactionType::ProviderFee, $currency, CarbonImmutable::instance($item->settled_at ?? $item->provider_created_at ?? now()))
                ->source('provider_settlement_item', (int) $item->getKey(), (string) $item->public_id, (string) $item->entity_reference)
                ->dimensions($payment?->market_id, null, $payment?->location_id)
                ->explained(null, null, ['provider' => (string) $item->provider, 'policy' => (string) config('finance.provider_fees.policy'), 'source' => 'PROVIDER_REPORTED'])
                ->debit($this->ledger->platform(LedgerAccountCode::ProviderFeeExpense, $currency), $fee - $tax, 'PROVIDER_FEE')
                ->debit($this->ledger->platform(LedgerAccountCode::ProviderTaxExpense, $currency), $tax, 'TAX_ON_PROVIDER_FEE')
                ->credit($this->ledger->provider((string) $item->provider, $currency), $fee, 'FEE_KEPT_BY_PROVIDER'),
        );
        $item->forceFill(['fee_transaction_id' => $transaction->getKey()]);
        if ($transaction->wasRecentlyCreated) {
            $this->metrics->record('provider_fee_posted');
        }

        return $transaction->wasRecentlyCreated;
    }

    private function matchSettlement(ProviderSettlement $settlement): bool
    {
        ProviderSettlementItem::query()->where('provider', $settlement->provider)->where('provider_settlement_reference', $settlement->provider_settlement_reference)->whereNull('provider_settlement_id')->update(['provider_settlement_id' => $settlement->getKey()]);
        $sums = ProviderSettlementItem::query()->where('provider_settlement_id', $settlement->getKey())
            ->selectRaw("count(*) as n, coalesce(sum(credit_minor), 0) as credit, coalesce(sum(debit_minor), 0) as debit, coalesce(sum(fee_minor), 0) as fee, coalesce(sum(tax_minor), 0) as tax, count(*) filter (where match_status <> 'MATCHED') as unmatched, count(*) filter (where currency <> ?) as foreign_currency", [$settlement->currency])
            ->toBase()->first();
        $settlement->forceFill(['item_count' => (int) $sums->n, 'items_credit_minor' => (int) $sums->credit, 'items_debit_minor' => (int) $sums->debit, 'items_fee_minor' => (int) $sums->fee, 'items_tax_minor' => (int) $sums->tax]);
        if ((int) $sums->n === 0) {
            // Its lines were not imported yet: nothing can be said.
            $settlement->forceFill(['reconciliation_status' => ProviderSettlement::RECON_PENDING])->save();

            return false;
        }
        $key = 'provider-settlement:'.$settlement->provider.':'.$settlement->provider_settlement_reference;
        $facts = ['provider' => (string) $settlement->provider, 'provider_reference' => (string) $settlement->provider_settlement_reference, 'subject_type' => 'provider_settlement', 'subject_id' => (int) $settlement->getKey(),
            'subject_public_id' => (string) $settlement->public_id, 'currency' => (string) $settlement->currency];
        $net = (int) $sums->credit - (int) $sums->debit;
        // A difference a person closed with a reason is accepted: the settlement is not held back by it again.
        $accepted = fn (string $k): bool => ReconciliationDifference::query()->where('dedupe_key', $k)->whereNotNull('resolved_by_admin_id')->exists();
        $totalOk = $accepted($key.':total') || $this->check($net === (int) $settlement->amount_minor && (int) $sums->foreign_currency === 0, DifferenceType::SettlementTotalMismatch, $key.':total', [...$facts, 'expected_minor' => $net, 'actual_minor' => (int) $settlement->amount_minor,
            'detail' => ['lines' => (int) $sums->n, 'credits' => (int) $sums->credit, 'debits' => (int) $sums->debit, 'lines_in_another_currency' => (int) $sums->foreign_currency]]);
        // The settlement entity reports fees only for some settlement kinds: compared when it reports any.
        $feeOk = (int) $settlement->fees_minor === 0 || $accepted($key.':fee') || $this->check((int) $settlement->fees_minor === (int) $sums->fee, DifferenceType::FeeMismatch, $key.':fee', [...$facts, 'expected_minor' => (int) $sums->fee, 'actual_minor' => (int) $settlement->fees_minor]);
        $matched = $totalOk && $feeOk && (int) $sums->unmatched === 0;
        $settlement->forceFill(['reconciliation_status' => $matched ? ProviderSettlement::RECON_MATCHED : ProviderSettlement::RECON_DIFFERENCES, 'reconciled_at' => now()]);

        if ($matched && $settlement->status === 'PROCESSED' && (int) $settlement->amount_minor > 0 && $settlement->ledger_transaction_id === null) {
            $currency = (string) $settlement->currency;
            $transaction = $this->ledger->post(
                LedgerDraft::for($key.':received', LedgerTransactionType::ProviderSettlementReceived, $currency, CarbonImmutable::instance($settlement->settled_at ?? $settlement->provider_created_at ?? now()))
                    ->source('provider_settlement', (int) $settlement->getKey(), (string) $settlement->public_id, (string) $settlement->provider_settlement_reference)
                    ->explained(null, null, ['provider' => (string) $settlement->provider, 'bank_reference' => $settlement->bank_reference])
                    ->debit($this->ledger->platform(LedgerAccountCode::PlatformBank, $currency), (int) $settlement->amount_minor, 'SETTLED_BY_PROVIDER')
                    ->credit($this->ledger->provider((string) $settlement->provider, $currency), (int) $settlement->amount_minor, 'SETTLED_TO_BANK'),
            );
            $settlement->forceFill(['ledger_transaction_id' => $transaction->getKey()]);
            $this->metrics->record('provider_settlement_minor', max(1, (int) $settlement->amount_minor));
        }
        $settlement->save();

        return $matched;
    }

    /**
     * Our verified payments and completed refunds that no provider line mentions after the expected time. Checked only
     * for a provider whose records were imported at least once — otherwise everything would be "missing".
     */
    private function missingAtProvider(string $provider): int
    {
        if (! ProviderSettlementItem::query()->where('provider', $provider)->exists()) {
            return 0;
        }
        $before = now()->subDays(max(0, (int) config('finance.provider_reconciliation.expected_within_days')));
        $found = 0;
        $payments = PaymentAttempt::query()->where('provider', $provider)->where('status', PaymentStatus::Verified->value)->whereNotNull('provider_payment_reference')->where('verified_at', '<', $before)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('provider_settlement_items as i')->where('i.provider', $provider)->where('i.type', ProviderSettlementItem::TYPE_PAYMENT)->whereColumn('i.entity_reference', 'payment_attempts.provider_payment_reference'))
            ->orderBy('id')->limit(500)->get();
        foreach ($payments as $payment) {
            $this->differences->report(DifferenceType::MissingProviderPayment, 'payment:'.$payment->public_id.':missing-provider', [
                'provider' => $provider, 'provider_reference' => (string) $payment->provider_payment_reference, 'subject_type' => 'payment', 'subject_id' => (int) $payment->getKey(), 'subject_public_id' => (string) $payment->public_id,
                'market_id' => $payment->market_id, 'location_id' => $payment->location_id, 'currency' => (string) $payment->currency, 'expected_minor' => (int) $payment->amount_minor, 'actual_minor' => null,
            ]);
            $found++;
        }
        $refunds = Refund::query()->where('provider', $provider)->where('status', RefundStatus::Refunded->value)->whereNotNull('provider_refund_reference')->where('completed_at', '<', $before)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('provider_settlement_items as i')->where('i.provider', $provider)->where('i.type', ProviderSettlementItem::TYPE_REFUND)->whereColumn('i.entity_reference', 'refunds.provider_refund_reference'))
            ->orderBy('id')->limit(500)->get();
        foreach ($refunds as $refund) {
            $this->differences->report(DifferenceType::MissingProviderRefund, 'refund:'.$refund->public_id.':missing-provider', [
                'provider' => $provider, 'provider_reference' => (string) $refund->provider_refund_reference, 'subject_type' => 'refund', 'subject_id' => (int) $refund->getKey(), 'subject_public_id' => (string) $refund->public_id,
                'subject_reference' => $refund->order_number, 'market_id' => $refund->market_id, 'location_id' => $refund->location_id, 'currency' => (string) $refund->currency, 'expected_minor' => $refund->amountMinor(), 'actual_minor' => null,
            ]);
            $found++;
        }

        return $found;
    }

    /**
     * One deterministic rule: agreement closes the difference it had opened, disagreement opens (or sees again) it.
     *
     * @param  array<string, mixed>  $facts
     */
    private function check(bool $agrees, DifferenceType $type, string $key, array $facts): bool
    {
        $agrees ? $this->differences->settled($key, 'The records agree.') : $this->differences->report($type, $key, $facts);

        return $agrees;
    }
}
