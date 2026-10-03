<?php

namespace App\Models;

use App\Auth\Scope;
use App\Enums\RejectionCategory;
use App\Enums\RestaurantStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The business behind one or more restaurant locations (legal entity / brand owner). It is never a location
 * itself: address, coordinates, hours, pickup settings and staff access belong to RestaurantLocation.
 *
 * Status, market and ownership are never mass-assignable — they change only through RestaurantLifecycleService
 * and the administrator APIs.
 */
#[Fillable(['legal_name', 'display_name'])]
class RestaurantOrganization extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    public const SINGLE_MARKET = 'SINGLE_MARKET';

    public const MULTI_MARKET = 'MULTI_MARKET';

    /**
     * The authorization scope of the whole organization.
     */
    public function scope(): Scope
    {
        return Scope::organization($this->public_id);
    }

    /**
     * @return HasMany<RestaurantLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(RestaurantLocation::class, 'organization_id');
    }

    /**
     * @return HasMany<RestaurantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(RestaurantMembership::class, 'organization_id');
    }

    /**
     * @return HasMany<RestaurantAdminNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(RestaurantAdminNote::class, 'organization_id');
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function primaryMarket(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'primary_market_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RestaurantStatus::class,
            'rejection_category' => RejectionCategory::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }
}
