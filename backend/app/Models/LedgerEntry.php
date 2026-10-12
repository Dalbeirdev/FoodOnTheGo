<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One debit or credit of a ledger transaction (Module 36). Append-only.
 */
class LedgerEntry extends Model
{
    use StoresUtcTimestamps;

    public const DEBIT = 'D';

    public const CREDIT = 'C';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A ledger entry is immutable.'));
        static::deleting(fn () => throw new LogicException('A ledger entry is never deleted.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'line_no' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
