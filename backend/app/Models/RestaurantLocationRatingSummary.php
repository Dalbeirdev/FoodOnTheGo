<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The published-review aggregate of one location: counts per star, the sum, the category totals and the average. It is
 * derived data — recomputed from restaurant_reviews whenever a change affects it — and can always be rebuilt.
 */
class RestaurantLocationRatingSummary extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'location_id';

    protected $guarded = ['*'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    /**
     * @return array<int, int> star → count, 5 first
     */
    public function distribution(): array
    {
        return [5 => (int) $this->star_5, 4 => (int) $this->star_4, 3 => (int) $this->star_3, 2 => (int) $this->star_2, 1 => (int) $this->star_1];
    }

    protected function casts(): array
    {
        return [
            'published_count' => 'integer',
            'rating_sum' => 'integer',
            'category_totals' => 'array',
            'last_published_at' => 'datetime',
            'recalculated_at' => 'datetime',
        ];
    }
}
