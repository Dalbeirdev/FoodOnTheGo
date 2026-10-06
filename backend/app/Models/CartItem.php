<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a cart: a menu item in one configuration (selected options + special instructions) and a quantity.
 *
 * `item_name`, `base_price_minor` and `unit_price_minor` are the snapshot of what the customer saw when the line
 * was written; they are display context and the baseline for "the price changed" — never the price an order is
 * charged. `signature` identifies the configuration: adding the same configuration again raises the quantity of
 * this line instead of creating another one (unique per cart).
 */
class CartItem extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    /**
     * @return HasMany<CartItemOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(CartItemOption::class)->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'base_price_minor' => 'integer', 'unit_price_minor' => 'integer', 'item_version' => 'integer'];
    }
}
