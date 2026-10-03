<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Controlled dietary label a restaurant may attach to an item (Vegetarian, Non-vegetarian, Vegan, Jain, Contains
 * egg, Gluten-free …). Reference data chosen by code; information provided by the restaurant, never a food-safety
 * or allergy guarantee by FoodOnTheGo.
 */
class DietaryTag extends Model
{
    use StoresUtcTimestamps;

    /**
     * @param  Builder<DietaryTag>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('dietary_tags.status', 'ACTIVE');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['display_order' => 'integer'];
    }
}
