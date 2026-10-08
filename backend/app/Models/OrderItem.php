<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of an order as it was placed: name, quantity and prices copied from the confirmed checkout. The link to
 * the menu item is a convenience that may go away; the snapshot is the record.
 */
class OrderItem extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_subtotal_minor' => 'integer', 'position' => 'integer'];
    }
}
