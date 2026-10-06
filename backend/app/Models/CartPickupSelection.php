<?php

namespace App\Models;

use App\Enums\PickupMethod;
use App\Enums\PickupMode;
use App\Enums\PickupSelectionStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pickup time a customer chose for a cart (Module 28): the mode (ASAP or a scheduled slot), the pickup method
 * and what the choice was last validated against. One per cart; choosing again replaces it.
 *
 * It is a CHOICE, not a reservation — no capacity is held for it. Every read validates it again against the cart,
 * the restaurant and the clock (PickupSelectionService), and checkout / order creation will do so once more before
 * anything is reserved. Nothing is mass-assignable: a request can never set a time, a status or a restaurant.
 */
class CartPickupSelection extends Model
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => PickupMode::class,
            'pickup_method' => PickupMethod::class,
            'status' => PickupSelectionStatus::class,
            'pickup_at' => 'datetime',
            'slot_ends_at' => 'datetime',
            'traveler_arrival_at' => 'datetime',
            'validated_at' => 'datetime',
            'expires_at' => 'datetime',
            'slot_interval_minutes' => 'integer',
            'cart_version' => 'integer',
            'settings_version' => 'integer',
            'estimated_prep_minutes' => 'integer',
            'version' => 'integer',
        ];
    }
}
