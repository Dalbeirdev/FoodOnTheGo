<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
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
 * A checkout session (Module 29): the short-lived, server-priced quote of what a signed-in customer is about to pay
 * for — one cart at one version, one pickup selection, one optional promotion, the taxes and fees configured at that
 * moment, and the total that follows.
 *
 * It is NOT an order. It expires, and it goes stale as soon as anything it was priced from changes; it is then
 * replaced, never repaired. Payment (Module 30) takes its amount and currency from here and from nowhere else.
 * Nothing is mass-assignable: a request can never set an amount, a currency, a status or an owner.
 */
class CheckoutSession extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function total(): Money
    {
        return new Money((int) $this->total_minor, (string) $this->currency);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<RestaurantLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<CustomerPaymentMethod, $this>
     */
    public function savedPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentMethod::class, 'customer_payment_method_id');
    }

    /**
     * @return HasMany<CheckoutLegalAcceptance, $this>
     */
    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(CheckoutLegalAcceptance::class);
    }

    /**
     * The pickup time held for this checkout, while it is held.
     *
     * @return HasOne<PickupSlotReservation, $this>
     */
    public function hold(): HasOne
    {
        return $this->hasOne(PickupSlotReservation::class)->where('status', PickupSlotReservation::STATUS_ACTIVE);
    }

    /**
     * @return HasOne<PromotionReservation, $this>
     */
    public function promotionReservation(): HasOne
    {
        return $this->hasOne(PromotionReservation::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_number');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'pickup_mode' => PickupMode::class,
            'pickup_method' => PickupMethod::class,
            'pickup_at' => 'datetime',
            'pickup_slot_starts_at' => 'datetime',
            'pickup_slot_ends_at' => 'datetime',
            'cart_version' => 'integer',
            'pickup_selection_version' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'tax_included_minor' => 'integer',
            'fee_minor' => 'integer',
            'total_minor' => 'integer',
            'payment_required' => 'boolean',
            'snapshot' => 'array',
            'confirmed_at' => 'datetime',
            'payment_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'expires_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
