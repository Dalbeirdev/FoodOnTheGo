<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacity used in one pickup slot of one location, in ORDERS (Module 28).
 *
 *   ORDER    an order that will be collected in the slot — written by the orders module (Module 31)
 *   HOLD     a short hold while a customer checks out — written by checkout (Module 29), ends at `expires_at`
 *   FIXTURE  deterministic development / test data
 *
 * Until those modules exist only FIXTURE rows are written. Read and written through PickupCapacityRepository.
 */
class PickupSlotReservation extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const SOURCE_FIXTURE = 'FIXTURE';

    public const SOURCE_HOLD = 'HOLD';

    public const SOURCE_ORDER = 'ORDER';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RELEASED = 'RELEASED';

    /** A hold whose time ran out (set by the clean-up; a run-out hold stops counting the moment it runs out). */
    public const STATUS_EXPIRED = 'EXPIRED';

    protected $guarded = ['*'];

    /**
     * The checkout this pickup time is held for (source HOLD, Module 29).
     *
     * @return BelongsTo<CheckoutSession, $this>
     */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

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
        return ['slot_starts_at' => 'datetime', 'expires_at' => 'datetime', 'units' => 'integer'];
    }
}
