<?php

namespace App\Models;

use App\Enums\MenuOptionGroupKind;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One selected option of a cart line — relational, not a JSON blob: the group and the option are real menu rows
 * (so the selection can be validated and audited), `kind` keeps the variant / modifier distinction of Module 24,
 * and the names and the adjustment are the snapshot the customer saw.
 */
class CartItemOption extends Model
{
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<CartItem, $this>
     */
    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    /**
     * @return BelongsTo<MenuOptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MenuOptionGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<MenuOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(MenuOption::class, 'option_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => MenuOptionGroupKind::class, 'price_adjustment_minor' => 'integer', 'display_order' => 'integer'];
    }
}
