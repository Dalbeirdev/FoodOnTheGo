<?php

namespace App\Services\Finance\ProviderSettlement;

use App\Contracts\Finance\ProviderSettlementSource;
use App\Enums\ProviderFailure;
use App\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * DEVELOPMENT ONLY — the fake payment provider's settlement report (Module 36). It reports exactly what a test or
 * the development simulator told it to: payments, refunds and the FEE AND TAX IT WAS GIVEN for each. It has no fee
 * formula of its own, because no real fee is known here — every fee in a fake report is a TEST FIXTURE.
 *
 * Its own convention (a fake's, not a real provider's): a payment line's credit is its amount minus its fee, a refund
 * line's debit is its amount, and a settlement's amount is the sum of the credits minus the sum of the debits.
 */
final class FakeSettlementSource implements ProviderSettlementSource
{
    public const NAME = 'fake';

    private const PREFIX = 'settlements:fake:';

    private const TTL = 2592000;

    public function name(): string
    {
        return self::NAME;
    }

    public function mode(): string
    {
        return 'test';
    }

    public function missingConfiguration(): array
    {
        return [];
    }

    public function lines(CarbonInterface $day, int $skip, int $count): array
    {
        $this->maybeFail();
        $lines = array_values((array) Cache::get(self::PREFIX.'lines:'.CarbonImmutable::instance($day)->utc()->format('Ymd'), []));

        return array_map(fn (array $l): ProviderSettlementLine => self::line($l), array_slice($lines, max(0, $skip), max(1, $count)));
    }

    public function settlements(CarbonInterface $from, CarbonInterface $to, int $skip, int $count): array
    {
        $this->maybeFail();
        $all = [];
        for ($day = CarbonImmutable::instance($from)->utc()->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            foreach ((array) Cache::get(self::PREFIX.'settlements:'.$day->format('Ymd'), []) as $record) {
                $all[] = $record;
            }
        }

        return array_map(fn (array $s): ProviderSettlementRecord => new ProviderSettlementRecord(
            (string) $s['id'], strtoupper((string) $s['status']), (string) $s['status'], (string) $s['currency'], (int) $s['amount'], (int) $s['fees'], (int) $s['tax'],
            $s['utr'] ?? null, CarbonImmutable::createFromTimestampUTC((int) $s['created_at']), array_intersect_key($s, array_flip(['id', 'status', 'amount', 'fees', 'tax', 'utr', 'created_at', 'currency'])),
        ), array_slice($all, max(0, $skip), max(1, $count)));
    }

    /* ------------------------------------------------------------------ the fake provider's own side (tests, the development simulator) */

    /**
     * The provider settles a day: the given payments and refunds become lines of ONE settlement.
     *
     * @param  list<array{reference: string, amount: int, fee?: int, tax?: int, currency?: string}>  $payments
     * @param  list<array{reference: string, payment_reference?: string|null, amount: int, currency?: string}>  $refunds
     * @param  array{status?: string, amount?: int|null, fees?: int, tax?: int, extra?: list<array<string, mixed>>}  $options  to report something that does NOT add up
     * @return array{settlement: string, amount: int, lines: int}
     */
    public function settleDay(CarbonInterface $day, array $payments, array $refunds = [], array $options = []): array
    {
        $day = CarbonImmutable::instance($day)->utc()->startOfDay();
        $at = $day->addHours(12)->getTimestamp();
        $settlement = 'fsetl_'.Str::lower(Str::random(14));
        $utr = 'FAKEUTR'.random_int(100000000, 999999999);
        $lines = [];
        $net = 0;
        $currency = 'INR';
        foreach ($payments as $p) {
            $fee = (int) ($p['fee'] ?? 0);
            $currency = (string) ($p['currency'] ?? $currency);
            $lines[] = ['entity_id' => $p['reference'], 'type' => 'payment', 'amount' => (int) $p['amount'], 'credit' => (int) $p['amount'] - $fee, 'debit' => 0, 'fee' => $fee, 'tax' => (int) ($p['tax'] ?? 0),
                'currency' => $currency, 'settled' => true, 'on_hold' => false, 'settlement_id' => $settlement, 'settlement_utr' => $utr, 'payment_id' => null, 'created_at' => $at, 'settled_at' => $at];
            $net += (int) $p['amount'] - $fee;
        }
        foreach ($refunds as $r) {
            $currency = (string) ($r['currency'] ?? $currency);
            $lines[] = ['entity_id' => $r['reference'], 'type' => 'refund', 'amount' => (int) $r['amount'], 'credit' => 0, 'debit' => (int) $r['amount'], 'fee' => 0, 'tax' => 0,
                'currency' => $currency, 'settled' => true, 'on_hold' => false, 'settlement_id' => $settlement, 'settlement_utr' => $utr, 'payment_id' => $r['payment_reference'] ?? null, 'created_at' => $at, 'settled_at' => $at];
            $net -= (int) $r['amount'];
        }
        foreach ((array) ($options['extra'] ?? []) as $extra) {
            $lines[] = ['entity_id' => 'fx_'.Str::lower(Str::random(10)), 'type' => 'adjustment', 'amount' => 0, 'credit' => 0, 'debit' => 0, 'fee' => 0, 'tax' => 0, 'currency' => $currency, 'settled' => true,
                'on_hold' => false, 'settlement_id' => $settlement, 'settlement_utr' => $utr, 'payment_id' => null, 'created_at' => $at, 'settled_at' => $at, ...$extra];
            $net += (int) ($extra['credit'] ?? 0) - (int) ($extra['debit'] ?? 0);
        }
        $key = self::PREFIX.'lines:'.$day->format('Ymd');
        Cache::put($key, [...(array) Cache::get($key, []), ...$lines], self::TTL);
        $record = ['id' => $settlement, 'status' => (string) ($options['status'] ?? 'processed'), 'amount' => (int) ($options['amount'] ?? $net), 'fees' => (int) ($options['fees'] ?? 0), 'tax' => (int) ($options['tax'] ?? 0),
            'utr' => $utr, 'created_at' => $at, 'currency' => $currency];
        $key = self::PREFIX.'settlements:'.$day->format('Ymd');
        Cache::put($key, [...(array) Cache::get($key, []), $record], self::TTL);

        return ['settlement' => $settlement, 'amount' => $record['amount'], 'lines' => count($lines)];
    }

    /**
     * Changes a line the provider already reported — to try a provider record that comes back different.
     *
     * @param  array<string, mixed>  $changes
     */
    public function alter(CarbonInterface $day, string $entityReference, array $changes): void
    {
        $key = self::PREFIX.'lines:'.CarbonImmutable::instance($day)->utc()->format('Ymd');
        Cache::put($key, array_map(fn (array $l): array => $l['entity_id'] === $entityReference ? [...$l, ...$changes] : $l, (array) Cache::get($key, [])), self::TTL);
    }

    public function failNext(ProviderFailure $failure, int $times = 1): void
    {
        Cache::put(self::PREFIX.'fail', ['failure' => $failure->value, 'times' => max(1, $times)], 3600);
    }

    private function maybeFail(): void
    {
        $script = Cache::get(self::PREFIX.'fail');
        if (! is_array($script)) {
            return;
        }
        $script['times'] > 1 ? Cache::put(self::PREFIX.'fail', [...$script, 'times' => $script['times'] - 1], 3600) : Cache::forget(self::PREFIX.'fail');

        throw new ProviderException(ProviderFailure::from($script['failure']), self::NAME);
    }

    /**
     * @param  array<string, mixed>  $l
     */
    private static function line(array $l): ProviderSettlementLine
    {
        return new ProviderSettlementLine(
            (string) $l['entity_id'],
            match ($l['type']) {
                'payment' => 'PAYMENT', 'refund' => 'REFUND', 'transfer' => 'TRANSFER', 'adjustment' => 'ADJUSTMENT', default => 'OTHER',
            },
            (string) $l['currency'], (int) $l['amount'], (int) $l['credit'], (int) $l['debit'], (int) $l['fee'], (int) $l['tax'], (bool) $l['settled'], (bool) $l['on_hold'],
            $l['settlement_id'] ?? null, $l['settlement_utr'] ?? null, $l['payment_id'] ?? null,
            isset($l['created_at']) ? CarbonImmutable::createFromTimestampUTC((int) $l['created_at']) : null,
            isset($l['settled_at']) ? CarbonImmutable::createFromTimestampUTC((int) $l['settled_at']) : null,
            array_intersect_key($l, array_flip(['entity_id', 'type', 'amount', 'credit', 'debit', 'fee', 'tax', 'currency', 'settled', 'on_hold', 'settlement_id', 'settlement_utr', 'payment_id', 'created_at', 'settled_at'])),
        );
    }
}
