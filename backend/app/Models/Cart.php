<?php

namespace App\Models;

use App\Enums\CartCloseReason;
use App\Enums\CartStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Carbon\CarbonInterface;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cart (Module 27): the items one customer — or one guest — intends to order from ONE restaurant location.
 *
 * Owned by a customer, or by whoever holds the guest token (only its hash is stored). At most one cart per
 * owner is ACTIVE. Every change goes through CartService inside a transaction and raises `version`; nothing is
 * mass-assignable, so a request can never set an owner, a restaurant, a currency, a status or a price.
 *
 * The cart keeps what the customer saw (names, prices) as a snapshot; the price that counts is recalculated from
 * the menu on every read (CartValidationService).
 */
#[Hidden(['guest_token_hash', 'spent_token_hash'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory, HasPublicId, StoresUtcTimestamps;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<RestaurantLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'restaurant_location_id');
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * @param  Builder<Cart>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('carts.status', CartStatus::Active->value);
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * Past its lifetime: the row is still ACTIVE until its owner clears it or the cleanup closes it.
     */
    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->status === CartStatus::Active && $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function effectiveStatus(?CarbonInterface $at = null): CartStatus
    {
        return $this->isExpired($at) ? CartStatus::Expired : $this->status;
    }

    /**
     * Ends the cart. The caller saves inside its transaction.
     */
    public function close(CartCloseReason $reason, ?CarbonInterface $at = null): void
    {
        $this->status = $reason->status();
        $this->closed_reason = $reason;
        $this->closed_at = $at ?? now();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'closed_reason' => CartCloseReason::class,
            'journey_summary' => 'array',
            'version' => 'integer',
            'menu_catalog_version' => 'integer',
            'expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }
}
