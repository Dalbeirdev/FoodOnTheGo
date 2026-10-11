<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a review (subject REVIEW) or a restaurant response (subject RESPONSE) said before a change. Append-only and
 * never part of a public or a customer-facing answer of somebody else's review.
 */
class ReviewRevision extends Model
{
    public const SUBJECT_REVIEW = 'REVIEW';

    public const SUBJECT_RESPONSE = 'RESPONSE';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(RestaurantReview::class, 'review_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'overall_rating' => 'integer',
            'category_ratings' => 'array',
            'tags' => 'array',
            'item_feedback' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
