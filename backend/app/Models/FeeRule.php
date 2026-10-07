<?php

namespace App\Models;

use App\Enums\FinancialRuleStatus;
use App\Enums\PickupMethod;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configured fee of a market (Module 29), optionally of one restaurant location or one pickup method: a fixed
 * amount or a percentage with optional minimum and maximum. A fee exists only when an administrator configures it —
 * none is seeded, there is no delivery fee in a pickup product, and a restaurant's commission is never a customer fee.
 */
class FeeRule extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const TYPE_FIXED = 'FIXED';

    public const TYPE_PERCENTAGE = 'PERCENTAGE';

    /** A percentage of the merchandise subtotal before the promotion discount (a promotion does not reduce the fee). */
    public const OF_SUBTOTAL = 'SUBTOTAL';

    /** A percentage of the merchandise subtotal after the promotion discount. */
    public const OF_DISCOUNTED_SUBTOTAL = 'DISCOUNTED_SUBTOTAL';

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
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
        return [
            'status' => FinancialRuleStatus::class,
            'pickup_method' => PickupMethod::class,
            'amount_minor' => 'integer',
            'percent_basis_points' => 'integer',
            'minimum_minor' => 'integer',
            'maximum_minor' => 'integer',
            'taxable' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'version' => 'integer',
        ];
    }
}
