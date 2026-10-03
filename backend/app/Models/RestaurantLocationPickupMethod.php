<?php

namespace App\Models;

use App\Enums\PickupMethod;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A pickup method of one location. A row may exist disabled; only enabled methods that the market allows
 * are offered to customers.
 */
class RestaurantLocationPickupMethod extends Model
{
    use StoresUtcTimestamps;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['method' => PickupMethod::class, 'enabled' => 'boolean', 'requires_vehicle_info' => 'boolean'];
    }
}
