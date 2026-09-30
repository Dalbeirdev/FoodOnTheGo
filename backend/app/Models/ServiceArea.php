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
 * Operational coverage inside a city: geometry(MultiPolygon, 4326). A city being active does not make the
 * whole city serviceable — only points covered by an ACTIVE service area are.
 *
 * Lists never load the polygon (summary = area in m² and the bounding box); `withGeometry()` adds GeoJSON.
 *
 * @property-read float $area_m2
 * @property-read string|null $geojson
 */
#[Fillable(['name', 'slug', 'priority', 'launch_stage', 'effective_from', 'effective_until'])]
#[Hidden(['geometry'])]
class ServiceArea extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    /** Columns without the polygon itself. */
    private const COLUMNS = ['id', 'public_id', 'market_id', 'city_id', 'name', 'slug', 'status', 'priority', 'launch_stage', 'effective_from', 'effective_until', 'version', 'created_at', 'updated_at'];

    protected static function booted(): void
    {
        static::addGlobalScope('summary', function (Builder $query): void {
            self::selectSummary($query, array_map(fn (string $c): string => 'service_areas.'.$c, self::COLUMNS),
                'ST_Area(service_areas.geometry::geography) as area_m2, ST_NPoints(service_areas.geometry) as vertices, ST_AsGeoJSON(ST_Envelope(service_areas.geometry), 6) as bbox_geojson');
        });
    }

    /**
     * @param  Builder<ServiceArea>  $query
     */
    public function scopeWithGeometry(Builder $query): void
    {
        $query->selectRaw('ST_AsGeoJSON(service_areas.geometry, 6) as geojson');
    }

    /**
     * Customer-serving now: ACTIVE and inside its effective dates (server clock).
     *
     * @param  Builder<ServiceArea>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->where('service_areas.status', CoverageStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('service_areas.effective_from')->orWhere('service_areas.effective_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('service_areas.effective_until')->orWhere('service_areas.effective_until', '>', now()));
    }

    /**
     * MultiPolygon expression from validated GeoJSON (a Polygon is promoted with ST_Multi).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function geometryExpression(string $geoJson): array
    {
        return ['ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))', [$geoJson]];
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
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => CoverageStatus::class, 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'area_m2' => 'float', 'vertices' => 'integer'];
    }
}
