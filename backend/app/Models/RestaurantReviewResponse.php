<?php

namespace App\Models;

use App\Enums\ReviewResponseStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The restaurant's one official answer to a review. It never changes the review it answers.
 *
 * @property ReviewResponseStatus $status
 */
class RestaurantReviewResponse extends Model
{
    use HasPublicId;
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(RestaurantReview::class, 'review_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(RestaurantUser::class, 'restaurant_user_id');
    }

    public function isPublic(): bool
    {
        return $this->status === ReviewResponseStatus::Published;
    }

    protected function casts(): array
    {
        return [
            'status' => ReviewResponseStatus::class,
            'version' => 'integer',
            'published_at' => 'datetime',
            'edited_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }
}
