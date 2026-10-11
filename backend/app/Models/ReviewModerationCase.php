<?php

namespace App\Models;

use App\Enums\ReviewCaseResolution;
use App\Enums\ReviewCaseSource;
use App\Enums\ReviewCaseStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question about one review for an administrator to answer: opened by the automated screening, by a report or by
 * an administrator. At most one case of a review is open at a time; further reports join it.
 *
 * @property ReviewCaseStatus $status
 */
class ReviewModerationCase extends Model
{
    use HasPublicId;
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(RestaurantReview::class, 'review_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_admin_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class, 'case_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'status' => ReviewCaseStatus::class,
            'source' => ReviewCaseSource::class,
            'resolution' => ReviewCaseResolution::class,
            'report_count' => 'integer',
            'version' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }
}
