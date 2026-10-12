<?php

namespace App\Models;

use App\Enums\LedgerAccountCode;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account of the financial ledger (Module 36): a code, ONE currency and an owner — the platform, a restaurant
 * location (its payable) or a provider (its clearing). It has no balance column: a balance is the sum of its entries.
 */
class LedgerAccount extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const OWNER_PLATFORM = 'PLATFORM';

    public const OWNER_LOCATION = 'LOCATION';

    public const OWNER_PROVIDER = 'PROVIDER';

    protected $guarded = ['*'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    protected function casts(): array
    {
        return ['code' => LedgerAccountCode::class];
    }
}
