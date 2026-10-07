<?php

namespace App\Models;

use App\Enums\FinancialRuleStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configured tax rule of a market (Module 29), optionally of one restaurant location. It says what it taxes
 * (merchandise or fees), how the price relates to the tax (added at checkout, or already inside the price), whether
 * the promotion discount comes off before the tax, and from when to when it applies. The rates are in its components.
 *
 * No rate is written in code: where no ACTIVE rule exists, no tax is calculated. A rule marked TEST is a development
 * configuration and is ignored where test rules are switched off (config checkout.financial_rules.allow_test).
 */
class TaxRule extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const APPLIES_MERCHANDISE = 'MERCHANDISE';

    public const APPLIES_FEES = 'FEES';

    /** Tax is added on top of the prices at checkout. */
    public const PRICE_EXCLUSIVE = 'EXCLUSIVE';

    /** The prices already contain the tax; it is shown, not added. */
    public const PRICE_INCLUSIVE = 'INCLUSIVE';

    public const DISCOUNT_AFTER = 'AFTER_DISCOUNT';

    public const DISCOUNT_BEFORE = 'BEFORE_DISCOUNT';

    public const APPROVAL_TEST = 'TEST';

    public const APPROVAL_APPROVED = 'APPROVED';

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
     * @return HasMany<TaxRuleComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(TaxRuleComponent::class)->orderBy('sequence')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => FinancialRuleStatus::class, 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'version' => 'integer'];
    }
}
