<?php

namespace App\Models;

use App\Enums\PromotionScope;
use App\Enums\PromotionStatus;
use App\Enums\PromotionType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promotion code (Module 29): a fixed amount or a percentage off the merchandise subtotal, valid in a scope
 * (platform, market, restaurant organization or restaurant location), a currency and a time window, with optional
 * minimum spend, maximum discount and usage limits.
 *
 * Whether it applies to a checkout is decided by PromotionEligibilityService on the server clock; what it takes off
 * is calculated by the backend only. Nothing is mass-assignable: administrators change a promotion through
 * PromotionAdminService, which validates, versions and audits every change.
 */
class Promotion extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const FUNDING_PLATFORM = 'PLATFORM';

    protected $guarded = ['*'];

    /**
     * The form a code is stored and compared in: trimmed, upper case. "save10 " and "SAVE10" are the same code.
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function effectiveStatus(?CarbonInterface $at = null): PromotionStatus
    {
        return PromotionStatus::effective($this->status, $this->starts_at, $this->ends_at, CarbonImmutable::instance($at ?? now()));
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return BelongsTo<RestaurantOrganization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    /**
     * @return BelongsTo<RestaurantLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    /**
     * @return HasMany<PromotionReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(PromotionReservation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'status' => PromotionStatus::class,
            'scope' => PromotionScope::class,
            'amount_minor' => 'integer',
            'percent_basis_points' => 'integer',
            'maximum_discount_minor' => 'integer',
            'minimum_spend_minor' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'redeemed_count' => 'integer',
            'version' => 'integer',
        ];
    }
}
