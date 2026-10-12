<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of what the platform owes a restaurant location (Module 36): an order's earning, or an adjustment (a
 * refund the restaurant bears, a dispute, a manual correction). Written together with the ledger posting it mirrors,
 * and consumed by exactly one settlement. Its amount never changes.
 */
class RestaurantPayableItem extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const TYPE_ORDER_EARNING = 'ORDER_EARNING';

    public const TYPE_REFUND_ADJUSTMENT = 'REFUND_ADJUSTMENT';

    public const TYPE_DISPUTE_ADJUSTMENT = 'DISPUTE_ADJUSTMENT';

    public const TYPE_MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_SETTLED = 'SETTLED';

    protected $guarded = ['*'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RestaurantSettlement::class, 'settlement_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(FinancialHold::class, 'payable_item_id');
    }

    /**
     * Lines that may be settled now: not consumed, not held themselves, and their location not held.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSettleable(Builder $query): void
    {
        $query->where('restaurant_payable_items.status', self::STATUS_AVAILABLE)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('financial_holds as h')
            ->where('h.status', FinancialHold::STATUS_ACTIVE)
            ->where(fn ($w) => $w->whereColumn('h.payable_item_id', 'restaurant_payable_items.id')
                ->orWhere(fn ($l) => $l->where('h.scope', FinancialHold::SCOPE_LOCATION)->whereColumn('h.location_id', 'restaurant_payable_items.location_id'))));
    }

    /**
     * Lines that are not consumed and ARE held (by a hold of their own or of their location).
     *
     * @param  Builder<self>  $query
     */
    public function scopeHeld(Builder $query): void
    {
        $query->where('restaurant_payable_items.status', self::STATUS_AVAILABLE)->whereExists(fn ($q) => $q->selectRaw('1')->from('financial_holds as h')
            ->where('h.status', FinancialHold::STATUS_ACTIVE)
            ->where(fn ($w) => $w->whereColumn('h.payable_item_id', 'restaurant_payable_items.id')
                ->orWhere(fn ($l) => $l->where('h.scope', FinancialHold::SCOPE_LOCATION)->whereColumn('h.location_id', 'restaurant_payable_items.location_id'))));
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'gross_minor' => 'integer', 'restaurant_discount_minor' => 'integer', 'commission_minor' => 'integer',
            'tax_passed_minor' => 'integer', 'fee_passed_minor' => 'integer',
            'available_at' => 'datetime', 'settled_at' => 'datetime',
        ];
    }
}
