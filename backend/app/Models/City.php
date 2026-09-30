<?php

namespace App\Models;

use App\Enums\CityStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city of a market. `center` is geography(Point, 4326); every query also selects it as
 * latitude / longitude (the raw column is never serialised).
 *
 * @property-read float $latitude
 * @property-read float $longitude
 */
#[Fillable(['name', 'slug', 'aliases', 'timezone', 'launch_stage', 'launched_at'])]
#[Hidden(['center'])]
class City extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    protected static function booted(): void
    {
        static::addGlobalScope('coordinates', function (Builder $query): void {
            self::selectSummary($query, ['cities.*'], 'ST_Y(cities.center::geometry) as latitude, ST_X(cities.center::geometry) as longitude');
        });
    }

    /**
     * SQL expression + bindings for the centre column.
     *
     * @return array{0: string, 1: list<float>}
     */
    public static function centerExpression(float $latitude, float $longitude): array
    {
        return ['ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography', [$longitude, $latitude]];
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
     * @return HasMany<ServiceArea, $this>
     */
    public function serviceAreas(): HasMany
    {
        return $this->hasMany(ServiceArea::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => CityStatus::class, 'aliases' => 'array', 'launched_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }
}
