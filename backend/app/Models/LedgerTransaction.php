<?php

namespace App\Models;

use App\Enums\LedgerTransactionType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One financial event in the ledger (Module 36): written once by LedgerPostingService, balanced, never changed and
 * never removed — the model refuses it and so does the database.
 */
class LedgerTransaction extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A ledger transaction is immutable: post a reversal or an adjustment instead.'));
        static::deleting(fn () => throw new LogicException('A ledger transaction is never deleted.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->orderBy('line_no');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'amount_minor' => 'integer',
            'detail' => 'array',
            'occurred_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }
}
