<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer's review of ONE completed order (Module 34). The order decides the customer, the organization, the
 * location and the market — the row copies them and a request can never set them. Every row is a verified order
 * review by construction: there is no other way to create one.
 *
 * @property ReviewStatus $status
 */
class RestaurantReview extends Model
{
    use HasPublicId;
    use StoresUtcTimestamps;

    public const REMOVED_BY_CUSTOMER = 'CUSTOMER';

    public const REMOVED_BY_ADMIN = 'ADMIN';

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(RestaurantReviewResponse::class, 'review_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ReviewRevision::class, 'review_id')->orderBy('id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ReviewModerationCase::class, 'review_id')->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class, 'review_id')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('restaurant_reviews.status', ReviewStatus::Published->value);
    }

    /**
     * Category ratings are a JSON OBJECT (code → stars), also when there is none — never the list an empty PHP array
     * would become.
     *
     * @param  array<string, int>|null  $value
     */
    public function setCategoryRatingsAttribute(?array $value): void
    {
        $this->attributes['category_ratings'] = json_encode(($value ?? []) === [] ? new \stdClass : $value);
    }

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'overall_rating' => 'integer',
            'category_ratings' => 'array',
            'tags' => 'array',
            'item_feedback' => 'array',
            'flags' => 'array',
            'version' => 'integer',
            'edit_count' => 'integer',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'edited_at' => 'datetime',
            'moderated_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
