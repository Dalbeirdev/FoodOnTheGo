<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A variant or modifier chosen on an order line, as it was named and priced when the order was placed.
 */
class OrderItemOption extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    protected function casts(): array
    {
        return ['price_adjustment_minor' => 'integer', 'position' => 'integer'];
    }
}
