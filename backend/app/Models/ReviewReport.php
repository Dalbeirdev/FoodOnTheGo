<?php

namespace App\Models;

use App\Enums\ReviewReportReason;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's report of one review. The pair is unique: reporting again changes nothing.
 */
class ReviewReport extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(RestaurantReview::class, 'review_id');
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ReviewModerationCase::class, 'case_id');
    }

    protected function casts(): array
    {
        return ['reason' => ReviewReportReason::class, 'created_at' => 'datetime'];
    }
}
