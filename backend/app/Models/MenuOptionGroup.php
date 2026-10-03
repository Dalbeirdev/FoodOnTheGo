<?php

namespace App\Models;

use App\Enums\MenuOptionGroupKind;
use App\Enums\MenuOptionGroupStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A selection group of an item — a variant group (Size, Portion) or a modifier group (Add-ons, Spice level),
 * same rules for both: required, min_selections, max_selections. Groups belong to their item in V1; the model
 * does not prevent shared groups later (an item ↔ group link table would replace item_id).
 */
#[Fillable(['name', 'description', 'required', 'min_selections', 'max_selections'])]
class MenuOptionGroup extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'item_id');
    }

    /**
     * @return HasMany<MenuOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(MenuOption::class, 'group_id')->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => MenuOptionGroupKind::class, 'status' => MenuOptionGroupStatus::class, 'required' => 'boolean', 'min_selections' => 'integer', 'max_selections' => 'integer', 'display_order' => 'integer'];
    }
}
