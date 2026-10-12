<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a settlement statement (Module 36): a copy of the payable line it consumed, written once with the
 * settlement. The database refuses every later change — what was approved is what stays.
 */
class RestaurantSettlementLine extends Model
{
    use StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RestaurantSettlement::class, 'settlement_id');
    }

    public function payableItem(): BelongsTo
    {
        return $this->belongsTo(RestaurantPayableItem::class, 'payable_item_id');
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer', 'gross_minor' => 'integer', 'restaurant_discount_minor' => 'integer', 'commission_minor' => 'integer',
            'tax_passed_minor' => 'integer', 'fee_passed_minor' => 'integer', 'amount_minor' => 'integer',
            'occurred_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
