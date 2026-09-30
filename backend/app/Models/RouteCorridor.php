<?php

namespace App\Models;

use App\Enums\CoverageStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operational route / highway corridor: a centreline geometry(LineString, 4326) plus a width in metres.
 * It says where route-based availability may exist; it is not a navigation route — a customer's actual
 * journey comes from the routing provider later.
 *
 * @property-read float $length_m
 * @property-read string|null $geojson
 */
#[Fillable(['name', 'slug', 'highway', 'corridor_width_meters', 'via_city_ids', 'effective_from', 'effective_until'])]
#[Hidden(['centerline'])]
class RouteCorridor extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    private const COLUMNS = ['id', 'public_id', 'market_id', 'origin_city_id', 'destination_city_id', 'via_city_ids', 'name', 'slug', 'highway', 'status', 'corridor_width_meters', 'effective_from', 'effective_until', 'version', 'created_at', 'updated_at'];

    protected static function booted(): void
    {
        static::addGlobalScope('summary', function (Builder $query): void {
            self::selectSummary($query, array_map(fn (string $c): string => 'route_corridors.'.$c, self::COLUMNS), 'ST_Length(route_corridors.centerline::geography) as length_m');
        });
    }

    /**
     * @param  Builder<RouteCorridor>  $query
     */
    public function scopeWithGeometry(Builder $query): void
    {
        $query->selectRaw('ST_AsGeoJSON(route_corridors.centerline, 6) as geojson');
    }

    /**
     * @param  Builder<RouteCorridor>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->where('route_corridors.status', CoverageStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('route_corridors.effective_from')->orWhere('route_corridors.effective_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('route_corridors.effective_until')->orWhere('route_corridors.effective_until', '>', now()));
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function centerlineExpression(string $geoJson): array
    {
        return ['ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)', [$geoJson]];
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function originCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'origin_city_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function destinationCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'destination_city_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => CoverageStatus::class, 'via_city_ids' => 'array', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'length_m' => 'float'];
    }
}
