<?php

namespace App\Models;

use App\Enums\DifferenceStatus;
use App\Enums\DifferenceType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place where two financial records that should agree do not (Module 36). It stays in the finance queue until a
 * person — or a deterministic rule that sees the records agree again — closes it with a reason.
 */
class ReconciliationDifference extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const SOURCE_PROVIDER = 'PROVIDER';

    public const SOURCE_INTERNAL = 'INTERNAL';

    public const RESOLUTION_AUTO = 'AUTO_MATCHED';

    public const RESOLUTIONS = ['CORRECTED', 'ADJUSTMENT_POSTED', 'PROVIDER_CORRECTED', 'EXPLAINED'];

    protected $guarded = ['*'];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_admin_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereIn('status', [DifferenceStatus::Open->value, DifferenceStatus::Investigating->value]);
    }

    protected function casts(): array
    {
        return [
            'type' => DifferenceType::class,
            'status' => DifferenceStatus::class,
            'expected_minor' => 'integer', 'actual_minor' => 'integer', 'difference_minor' => 'integer', 'seen_count' => 'integer', 'version' => 'integer',
            'detail' => 'array',
            'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }
}
