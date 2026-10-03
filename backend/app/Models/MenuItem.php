<?php

namespace App\Models;

use App\Enums\MenuItemStatus;
use App\Enums\MenuOptionGroupKind;
use App\Exceptions\ApiException;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One thing a customer can order. Price is integer minor units in the menu's currency; the configured price
 * (variants, modifiers) is calculated by MenuPricingService from the database — never from a client.
 *
 * The slug is the item's address under its restaurant (/restaurants/{restaurant}/item/{slug}) and is unique
 * per menu. Renaming an item keeps its slug (deep links keep working); the slug changes only when the
 * restaurant asks for it explicitly, and the old one is never reused while the item exists.
 */
#[Fillable(['name', 'description', 'allergen_information', 'ingredients', 'featured', 'preparation_minutes'])]
class MenuItem extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /**
     * @return BelongsTo<MenuCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    /**
     * @return HasMany<MenuOptionGroup, $this>
     */
    public function optionGroups(): HasMany
    {
        return $this->hasMany(MenuOptionGroup::class, 'item_id')->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return HasMany<MenuItemImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(MenuItemImage::class, 'item_id')->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<DietaryTag, $this>
     */
    public function dietaryTags(): BelongsToMany
    {
        return $this->belongsToMany(DietaryTag::class, 'menu_item_dietary_tags', 'item_id', 'dietary_tag_id')->orderBy('dietary_tags.display_order');
    }

    /**
     * @param  Builder<MenuItem>  $query
     */
    public function scopeCustomerVisible(Builder $query): void
    {
        $query->whereIn('menu_items.status', [MenuItemStatus::Active->value, MenuItemStatus::SoldOut->value, MenuItemStatus::TemporarilyUnavailable->value]);
    }

    /**
     * @param  Builder<MenuItem>  $query
     */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where('menu_items.status', '!=', MenuItemStatus::Archived->value);
    }

    /**
     * @return Collection<int, MenuOptionGroup>
     */
    public function groupsOfKind(MenuOptionGroupKind $kind): Collection
    {
        return $this->optionGroups->where('kind', $kind)->values();
    }

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
        return [
            'status' => MenuItemStatus::class, 'base_price_minor' => 'integer', 'featured' => 'boolean', 'preparation_minutes' => 'integer',
            'min_quantity' => 'integer', 'max_quantity' => 'integer', 'display_order' => 'integer', 'version' => 'integer', 'archived_at' => 'immutable_datetime',
        ];
    }
}
