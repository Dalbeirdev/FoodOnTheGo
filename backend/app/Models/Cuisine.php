<?php

namespace App\Models;

use App\Enums\TaxonomyStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuisine taxonomy — data, not columns on the restaurant. `code` is the stable identifier clients filter by;
 * an entry no longer offered becomes INACTIVE and is never deleted while a restaurant still references it.
 */
#[Fillable(['code', 'name', 'slug', 'status', 'display_order'])]
class Cuisine extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @param  Builder<Cuisine>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('cuisines.status', TaxonomyStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TaxonomyStatus::class, 'display_order' => 'integer'];
    }
}
