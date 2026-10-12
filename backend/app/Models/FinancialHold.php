<?php

namespace App\Models;

use App\Enums\HoldReason;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit hold on a restaurant's money (Module 36): on one payable line, or on everything of a location. While it
 * is ACTIVE the line (or the location) is left out of settlements and payouts. It moves nothing in the ledger.
 */
class FinancialHold extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const SCOPE_LOCATION = 'LOCATION';

    public const SCOPE_ITEM = 'ITEM';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RELEASED = 'RELEASED';

    public const SOURCE_SYSTEM = 'SYSTEM';

    public const SOURCE_ADMIN = 'ADMIN';

    protected $guarded = ['*'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function payableItem(): BelongsTo
    {
        return $this->belongsTo(RestaurantPayableItem::class, 'payable_item_id');
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'placed_by_admin_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'released_by_admin_id');
    }

    protected function casts(): array
    {
        return ['reason' => HoldReason::class, 'placed_at' => 'datetime', 'released_at' => 'datetime', 'version' => 'integer'];
    }
}
