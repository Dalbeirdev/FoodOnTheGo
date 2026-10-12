<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a provider's settlement report (Module 36): a payment, a refund or something else the provider moved,
 * with the provider's own fee and tax for it. Kept as reported; matched to our payment or refund by reconciliation.
 */
class ProviderSettlementItem extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const TYPE_PAYMENT = 'PAYMENT';

    public const TYPE_REFUND = 'REFUND';

    public const TYPE_TRANSFER = 'TRANSFER';

    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const TYPE_OTHER = 'OTHER';

    public const MATCH_PENDING = 'PENDING';

    public const MATCH_MATCHED = 'MATCHED';

    public const MATCH_DIFFERENCE = 'DIFFERENCE';

    public const MATCH_UNMATCHED = 'UNMATCHED';

    protected $guarded = ['*'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ProviderSettlement::class, 'provider_settlement_id');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'credit_minor' => 'integer', 'debit_minor' => 'integer', 'fee_minor' => 'integer', 'tax_minor' => 'integer',
            'settled' => 'boolean', 'on_hold' => 'boolean', 'snapshot' => 'array',
            'provider_created_at' => 'datetime', 'settled_at' => 'datetime', 'imported_at' => 'datetime',
        ];
    }
}
