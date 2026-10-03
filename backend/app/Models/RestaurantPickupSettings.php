<?php

namespace App\Models;

use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pickup configuration of one location: modes, timing and (optional) capacity. These are settings; pickup
 * slots, slot capacity checks and the dynamic preparation estimate are built by the pickup-availability
 * module from these values.
 */
#[Fillable([
    'pickup_enabled', 'asap_enabled', 'scheduled_enabled', 'default_prep_minutes', 'minimum_lead_minutes', 'buffer_minutes',
    'schedule_horizon_minutes', 'slot_interval_minutes', 'order_cutoff_minutes', 'capacity_per_slot',
])]
class RestaurantPickupSettings extends Model
{
    use HasSpatialColumns, StoresUtcTimestamps;

    protected $table = 'restaurant_pickup_settings';

    /**
     * @return BelongsTo<RestaurantLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pickup_enabled' => 'boolean', 'asap_enabled' => 'boolean', 'scheduled_enabled' => 'boolean',
            'default_prep_minutes' => 'integer', 'minimum_lead_minutes' => 'integer', 'buffer_minutes' => 'integer', 'schedule_horizon_minutes' => 'integer',
            'slot_interval_minutes' => 'integer', 'order_cutoff_minutes' => 'integer', 'capacity_per_slot' => 'integer', 'version' => 'integer',
        ];
    }
}
