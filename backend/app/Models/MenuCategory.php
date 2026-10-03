<?php

namespace App\Models;

use App\Enums\MenuCategoryStatus;
use App\Exceptions\ApiException;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A section of a menu (Starters, Breads, Rice & Biryani …). Names are restaurant data in any script. The order
 * is an explicit `display_order`, never the creation date. INACTIVE hides the section and makes its items
 * unorderable without touching them; ARCHIVED is final.
 */
#[Fillable(['name', 'description'])]
class MenuCategory extends Model
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
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id')->orderBy('display_order')->orderBy('id');
    }

    /**
     * @param  Builder<MenuCategory>  $query
     */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where('menu_categories.status', '!=', MenuCategoryStatus::Archived->value);
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
        return ['status' => MenuCategoryStatus::class, 'display_order' => 'integer', 'version' => 'integer'];
    }
}
