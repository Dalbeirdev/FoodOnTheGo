<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fee applied to an order, exactly as the checkout applied it.
 */
class OrderFeeLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'taxable' => 'boolean', 'position' => 'integer'];
    }
}
