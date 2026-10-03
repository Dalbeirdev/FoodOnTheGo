<?php

namespace App\Models;

use App\Auth\Scope;
use App\Enums\OperationalStatus;
use App\Enums\RejectionCategory;
use App\Enums\RestaurantImageStatus;
use App\Enums\RestaurantStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Support\Geo\Location;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One physical restaurant outlet — what a customer sees as "a restaurant". `location` is
 * geography(Point, 4326); every query also selects it as latitude / longitude (the raw column is never
 * serialised).
 *
 * Three independent states, never mixed:
 *   status              administrative approval (RestaurantLifecycleService, administrators only)
 *   operational_status  the outlet's own "we are closed until further notice" switch
 *   accepting_orders    whether FoodOnTheGo orders are taken right now (pause / resume)
 * Whether it is open at an instant comes from its hours (RestaurantHoursService); whether a customer can see
 * it or order from it is RestaurantAvailabilityService.
 *
 * Only descriptive profile fields are mass-assignable. Status, geography, organization, currency and time
 * zone are set by services after validation — never from a request array.
 *
 * @property-read float $latitude
 * @property-read float $longitude
 */
#[Fillable(['name', 'branch_label', 'short_description', 'description', 'public_email', 'website', 'pickup_instructions', 'price_level'])]
#[Hidden(['location'])]
class RestaurantLocation extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    protected static function booted(): void
    {
        static::addGlobalScope('coordinates', function (Builder $query): void {
            self::selectSummary($query, ['restaurant_locations.*'], 'ST_Y(restaurant_locations.location::geometry) as latitude, ST_X(restaurant_locations.location::geometry) as longitude');
        });
    }

    /**
     * SQL expression + bindings for the position column (longitude first in PostGIS).
     *
     * @return array{0: string, 1: list<float>}
     */
    public static function pointExpression(float $latitude, float $longitude): array
    {
        return ['ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography', [$longitude, $latitude]];
    }

    /**
     * Authorization scope of this location; a role held for the whole organization covers it.
     * Needs the organization relation (loaded by every query that authorises).
     */
    public function scope(): Scope
    {
        return Scope::location($this->public_id, $this->organization->public_id);
    }

    public function position(): Location
    {
        return new Location((float) $this->latitude, (float) $this->longitude);
    }

    /**
     * Whether FoodOnTheGo orders are taken at this instant: the switch is on, or a timed pause has run out.
     */
    public function acceptsOrdersAt(CarbonInterface $at): bool
    {
        return $this->accepting_orders || ($this->paused_until !== null && $this->paused_until->lessThanOrEqualTo($at));
    }

    /**
     * @return BelongsTo<RestaurantOrganization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return BelongsTo<MarketRegion, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(MarketRegion::class, 'region_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<ServiceArea, $this>
     */
    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /**
     * @return BelongsToMany<Cuisine, $this>
     */
    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(Cuisine::class, 'restaurant_location_cuisines', 'location_id', 'cuisine_id')->withPivot('position')->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<RestaurantFeature, $this>
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(RestaurantFeature::class, 'restaurant_location_features', 'location_id', 'feature_id')->orderBy('restaurant_features.display_order')->orderBy('restaurant_features.name');
    }

    /**
     * Images that are shown (archived ones are kept for history only).
     *
     * @return HasMany<RestaurantImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(RestaurantImage::class, 'location_id')->where('status', RestaurantImageStatus::Active->value)->orderBy('display_order')->orderBy('id');
    }

    /**
     * Weekly opening periods, in day and sequence order.
     *
     * @return HasMany<RestaurantLocationHour, $this>
     */
    public function hours(): HasMany
    {
        return $this->hasMany(RestaurantLocationHour::class, 'location_id')->where('kind', RestaurantLocationHour::OPENING)->orderBy('day_of_week')->orderBy('sequence');
    }

    /**
     * @return HasMany<RestaurantSpecialHour, $this>
     */
    public function specialHours(): HasMany
    {
        return $this->hasMany(RestaurantSpecialHour::class, 'location_id')->orderBy('date');
    }

    /**
     * @return HasOne<RestaurantPickupSettings, $this>
     */
    public function pickupSettings(): HasOne
    {
        return $this->hasOne(RestaurantPickupSettings::class, 'location_id');
    }

    /**
     * @return HasMany<RestaurantLocationPickupMethod, $this>
     */
    public function pickupMethods(): HasMany
    {
        return $this->hasMany(RestaurantLocationPickupMethod::class, 'location_id')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RestaurantStatus::class,
            'operational_status' => OperationalStatus::class,
            'rejection_category' => RejectionCategory::class,
            'accepting_orders' => 'boolean',
            'paused_at' => 'datetime',
            'paused_until' => 'datetime',
            'service_area_resolved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'price_level' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}
