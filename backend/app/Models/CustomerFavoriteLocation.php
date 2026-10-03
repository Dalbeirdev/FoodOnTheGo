<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's favorite restaurant location (Module 25). Preference state only: unique per customer and
 * location, deleted when the customer removes it. Whether the restaurant may be shown is decided per request
 * by RestaurantVisibility — a favorite of a restaurant that was suspended later stays, marked unavailable.
 */
class CustomerFavoriteLocation extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<RestaurantLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'restaurant_location_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
