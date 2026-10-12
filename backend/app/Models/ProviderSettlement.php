<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the PAYMENT PROVIDER says it settled to the platform (Module 36): the provider's own record, kept as it was
 * reported. It is not a restaurant settlement and not a payout.
 */
class ProviderSettlement extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const RECON_PENDING = 'PENDING';

    public const RECON_MATCHED = 'MATCHED';

    public const RECON_DIFFERENCES = 'DIFFERENCES';

    protected $guarded = ['*'];

    public function items(): HasMany
    {
        return $this->hasMany(ProviderSettlementItem::class, 'provider_settlement_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'fees_minor' => 'integer', 'tax_minor' => 'integer', 'item_count' => 'integer',
            'items_credit_minor' => 'integer', 'items_debit_minor' => 'integer', 'items_fee_minor' => 'integer', 'items_tax_minor' => 'integer',
            'snapshot' => 'array',
            'provider_created_at' => 'datetime', 'settled_at' => 'datetime', 'reconciled_at' => 'datetime', 'imported_at' => 'datetime',
        ];
    }
}
