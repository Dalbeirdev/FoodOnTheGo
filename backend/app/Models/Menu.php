<?php

namespace App\Models;

use App\Enums\MenuStatus;
use App\Exceptions\ApiException;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A menu of one restaurant location (Module 24). The currency is the location's; every category, item and
 * option below it inherits it. `catalog_version` changes on every customer-visible change in the tree and is
 * the cache key and the stale-detection token clients carry; `version` protects edits of the menu row itself.
 *
 * V1 keeps one menu per location (created on first use); the model allows more later (display_order, status).
 */
#[Fillable(['name', 'description'])]
class Menu extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return BelongsTo<RestaurantLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    /**
     * @return HasMany<MenuCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class, 'menu_id')->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id');
    }

    /**
     * @return HasManyThrough<MenuOptionGroup, MenuItem, $this>
     */
    public function optionGroups(): HasManyThrough
    {
        return $this->hasManyThrough(MenuOptionGroup::class, MenuItem::class, 'menu_id', 'item_id');
    }

    /**
     * @param  Builder<Menu>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('menus.status', MenuStatus::Active->value);
    }

    /**
     * Optimistic concurrency: the caller states the version it edited.
     */
    public function assertVersion(int $expected): void
    {
        if ((int) $this->version !== $expected) {
            throw ApiException::conflict('stale_update', 'This record was changed by someone else. Reload it and try again.', ['current_version' => (int) $this->version]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => MenuStatus::class, 'catalog_version' => 'integer', 'version' => 'integer', 'display_order' => 'integer'];
    }
}
