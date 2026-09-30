<?php

namespace App\Models;

use App\Enums\DistanceUnit;
use App\Enums\MarketStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Database\Factories\MarketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'country_code', 'slug', 'name', 'status', 'default_currency', 'supported_currencies', 'default_locale',
    'supported_locales', 'timezone_strategy', 'default_timezone', 'distance_unit', 'phone_country_code', 'phone_national_pattern', 'phone_trunk_prefix',
    'features', 'launched_at',
])]
#[Hidden(['bounds'])]
class Market extends Model
{
    /** @use HasFactory<MarketFactory> */
    use HasFactory, HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    /** Coarse envelope of India (west, south, east, north) — reference data, not the legal border. */
    public const INDIA_BOUNDS = [68.0, 6.5, 97.5, 37.1];

    /**
     * Markets that may serve customers (ACTIVE or PILOT).
     *
     * @param  Builder<Market>  $query
     */
    public function scopeServingCustomers(Builder $query): void
    {
        $query->whereIn('status', [MarketStatus::Active->value, MarketStatus::Pilot->value]);
    }

    /**
     * Sets the coarse envelope used to decide which market a point belongs to (WGS84 degrees).
     */
    public function setBounds(float $west, float $south, float $east, float $north): void
    {
        $this->updateSpatial('bounds', ['ST_MakeEnvelope(?, ?, ?, ?, 4326)', [$west, $south, $east, $north]]);
    }

    /**
     * @return HasOne<MarketConfiguration, $this>
     */
    public function configuration(): HasOne
    {
        return $this->hasOne(MarketConfiguration::class);
    }

    /**
     * @return HasMany<MarketRegion, $this>
     */
    public function regions(): HasMany
    {
        return $this->hasMany(MarketRegion::class);
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function featureEnabled(string $key): bool
    {
        return ($this->features[$key] ?? false) === true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MarketStatus::class,
            'distance_unit' => DistanceUnit::class,
            'supported_currencies' => 'array',
            'supported_locales' => 'array',
            'features' => 'array',
            'launched_at' => 'datetime',
        ];
    }
}
