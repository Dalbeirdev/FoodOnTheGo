<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax component applied to an order, exactly as the checkout applied it. Never recalculated from the rules.
 */
class OrderTaxLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['rate_basis_points' => 'integer', 'taxable_minor' => 'integer', 'amount_minor' => 'integer', 'included_in_prices' => 'boolean', 'position' => 'integer'];
    }
}
