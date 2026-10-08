<?php

namespace App\Models;

use App\Enums\OrderPaymentState;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderRejectionReason;
use App\Enums\OrderStatus;
use App\Enums\PickupMethod;
use App\Enums\PickupMode;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An order (Module 31): the immutable record of what was placed, with an operational state that moves only through
 * OrderStateMachine. Every amount, name, option and time was copied from the confirmed checkout when the order was
 * created and is never read again from the menu, the tax rules or the restaurant. Orders are never deleted.
 */
class Order extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function total(): Money
    {
        return new Money((int) $this->total_minor, (string) $this->currency);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function pickupReservation(): BelongsTo
    {
        return $this->belongsTo(PickupSlotReservation::class, 'pickup_reservation_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(OrderTaxLine::class)->orderBy('position');
    }

    public function feeLines(): HasMany
    {
        return $this->hasMany(OrderFeeLine::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    public function pickupCredential(): HasOne
    {
        return $this->hasOne(OrderPickupCredential::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class)->orderBy('id');
    }

    public function fulfillment(): HasOne
    {
        return $this->hasOne(OrderFulfillment::class);
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'refund_status' => OrderRefundStatus::class,
            'payment_state' => OrderPaymentState::class,
            'rejection_reason' => OrderRejectionReason::class,
            'pickup_mode' => PickupMode::class,
            'pickup_method' => PickupMethod::class,
            'snapshot' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'tax_included_minor' => 'integer',
            'fee_minor' => 'integer',
            'total_minor' => 'integer',
            'item_count' => 'integer',
            'estimated_prep_minutes' => 'integer',
            'version' => 'integer',
            'pickup_at' => 'datetime',
            'pickup_slot_starts_at' => 'datetime',
            'pickup_slot_ends_at' => 'datetime',
            'traveler_arrival_at' => 'datetime',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
