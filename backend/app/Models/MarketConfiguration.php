<?php

namespace App\Models;

use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Structured, per-market configuration that does not belong on the market row itself: payment, tax, legal,
 * address and ordering settings, grouped by category. Administrative data — never sent to customers as is.
 */
#[Fillable(['payment', 'tax', 'legal', 'address', 'ordering', 'locked_features'])]
class MarketConfiguration extends Model
{
    use HasSpatialColumns, StoresUtcTimestamps;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payment' => 'array', 'tax' => 'array', 'legal' => 'array', 'address' => 'array', 'ordering' => 'array', 'locked_features' => 'array'];
    }
}
