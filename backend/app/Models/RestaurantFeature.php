<?php

namespace App\Models;

use App\Enums\TaxonomyStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Facilities, dietary statements and services a location may declare (parking, pure veg, quick pickup …).
 * A taxonomy like cuisines: one row per feature instead of a boolean column per idea.
 */
#[Fillable(['code', 'name', 'slug', 'category', 'status', 'display_order'])]
class RestaurantFeature extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const CATEGORIES = ['FACILITY', 'DIETARY', 'SERVICE'];

    /**
     * @param  Builder<RestaurantFeature>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('restaurant_features.status', TaxonomyStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TaxonomyStatus::class, 'display_order' => 'integer'];
    }
}
