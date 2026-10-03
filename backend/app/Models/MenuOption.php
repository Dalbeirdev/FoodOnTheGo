<?php

namespace App\Models;

use App\Enums\MenuOptionStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice inside a group. `price_adjustment_minor` is in the menu's currency (0, positive, or — when the
 * platform allows it — negative for a smaller portion; never below the item's base price).
 */
#[Fillable(['name', 'price_adjustment_minor', 'default_selected'])]
class MenuOption extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return BelongsTo<MenuOptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MenuOptionGroup::class, 'group_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => MenuOptionStatus::class, 'price_adjustment_minor' => 'integer', 'default_selected' => 'boolean', 'display_order' => 'integer'];
    }
}
