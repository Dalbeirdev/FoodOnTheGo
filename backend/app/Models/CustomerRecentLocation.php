<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place the customer explicitly chose recently (Module 25): bounded to the newest few, deduplicated by place
 * or rounded coordinates, clearable at any time, pruned after the configured retention. Never a movement
 * history — only selections the customer made in the app.
 *
 * @property-read float $latitude
 * @property-read float $longitude
 */
class CustomerRecentLocation extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    protected static function booted(): void
    {
        static::addGlobalScope('coordinates', function (Builder $query): void {
            self::selectSummary($query, ['customer_recent_locations.*'], 'ST_Y(customer_recent_locations.location::geometry) as latitude, ST_X(customer_recent_locations.location::geometry) as longitude');
        });
    }

    /**
     * SQL expression + bindings for the point column (longitude first in PostGIS).
     *
     * @return array{0: string, 1: list<float>}
     */
    public static function pointExpression(float $latitude, float $longitude): array
    {
        return ['ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography', [$longitude, $latitude]];
    }

    /**
     * The row as the API reads it (with the coordinate columns): refresh() runs without the global scopes.
     */
    public function reload(): static
    {
        return static::query()->whereKey($this->getKey())->firstOrFail();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'times_used' => 'integer'];
    }
}
