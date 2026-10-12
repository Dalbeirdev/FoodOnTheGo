<?php

namespace App\Services\Finance;

use App\Enums\LedgerTransactionType;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A ledger transaction that is being put together (Module 36): the event's key, its source and its lines. Nothing is
 * written until LedgerPostingService::post() takes it — and refuses it when it does not balance.
 *
 * Amounts are signed while drafting: a NEGATIVE debit becomes a credit (and the other way round) and a zero line is
 * left out, so the caller writes the accounting sentence once and unusual signs take care of themselves.
 */
final class LedgerDraft
{
    /** @var list<array{account: LedgerAccount, direction: string, amount: int, code: string}> */
    private array $lines = [];

    public string $sourceType = '';

    public int $sourceId = 0;

    public ?string $sourcePublicId = null;

    public ?string $sourceReference = null;

    public ?int $marketId = null;

    public ?int $organizationId = null;

    public ?int $locationId = null;

    public ?string $memo = null;

    public ?int $adminId = null;

    public ?int $reversesTransactionId = null;

    /** @var array<string, mixed> */
    public array $detail = [];

    private function __construct(
        public readonly string $key,
        public readonly LedgerTransactionType $type,
        public readonly string $currency,
        public readonly CarbonInterface $occurredAt,
    ) {}

    public static function for(string $key, LedgerTransactionType $type, string $currency, CarbonInterface $occurredAt): self
    {
        if ($key === '' || strlen($key) > 160) {
            throw new InvalidArgumentException('A posting key is 1 to 160 characters.');
        }

        return new self($key, $type, $currency, $occurredAt);
    }

    public function source(string $type, int $id, ?string $publicId = null, ?string $reference = null): self
    {
        $this->sourceType = $type;
        $this->sourceId = $id;
        $this->sourcePublicId = $publicId;
        $this->sourceReference = $reference === null ? null : mb_substr($reference, 0, 80);

        return $this;
    }

    public function dimensions(?int $marketId, ?int $organizationId = null, ?int $locationId = null): self
    {
        $this->marketId = $marketId;
        $this->organizationId = $organizationId;
        $this->locationId = $locationId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function explained(?string $memo, ?int $adminId = null, array $detail = []): self
    {
        $this->memo = $memo === null ? null : mb_substr($memo, 0, 500);
        $this->adminId = $adminId;
        $this->detail = $detail;

        return $this;
    }

    public function reverses(int $transactionId): self
    {
        $this->reversesTransactionId = $transactionId;

        return $this;
    }

    public function debit(LedgerAccount $account, int $amountMinor, string $descriptionCode): self
    {
        return $this->line($account, LedgerEntry::DEBIT, $amountMinor, $descriptionCode);
    }

    public function credit(LedgerAccount $account, int $amountMinor, string $descriptionCode): self
    {
        return $this->line($account, LedgerEntry::CREDIT, $amountMinor, $descriptionCode);
    }

    /**
     * @return list<array{account: LedgerAccount, direction: string, amount: int, code: string}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function debits(): int
    {
        return array_sum(array_map(fn (array $l): int => $l['direction'] === LedgerEntry::DEBIT ? $l['amount'] : 0, $this->lines));
    }

    public function credits(): int
    {
        return array_sum(array_map(fn (array $l): int => $l['direction'] === LedgerEntry::CREDIT ? $l['amount'] : 0, $this->lines));
    }

    private function line(LedgerAccount $account, string $direction, int $amountMinor, string $descriptionCode): self
    {
        if ($amountMinor === 0) {
            return $this;
        }
        if ($amountMinor < 0) {
            $direction = $direction === LedgerEntry::DEBIT ? LedgerEntry::CREDIT : LedgerEntry::DEBIT;
            $amountMinor = -$amountMinor;
        }
        $this->lines[] = ['account' => $account, 'direction' => $direction, 'amount' => $amountMinor, 'code' => $descriptionCode];

        return $this;
    }
}
