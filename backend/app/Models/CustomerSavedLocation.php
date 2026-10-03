<?php

namespace App\Models;

use App\Enums\SavedLocationKind;
use App\Exceptions\ApiException;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Support\Geo\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's saved journey location (Module 25): a shortcut such as Home or Work for planning a journey —
 * never a delivery address. Flexible address fields (not every place has a street or a postal code), an
 * optional geography(Point, 4326) and the market / city the point resolved to. Coordinates are private to
 * the owner; the raw column is never selected, every query adds latitude / longitude.
 *
 * @property-read float|null $latitude
 * @property-read float|null $longitude
 */
class CustomerSavedLocation extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    protected static function booted(): void
    {
        static::addGlobalScope('coordinates', function (Builder $query): void {
            self::selectSummary($query, ['customer_saved_locations.*'], 'ST_Y(customer_saved_locations.location::geometry) as latitude, ST_X(customer_saved_locations.location::geometry) as longitude');
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

    public function hasPoint(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function point(): ?Location
    {
        return $this->hasPoint() ? new Location((float) $this->latitude, (float) $this->longitude, $this->country_code) : null;
    }

    public function assertVersion(int $expected): void
    {
        if ((int) $this->version !== $expected) {
            throw ApiException::conflict('stale_update', 'This record was changed by someone else. Reload it and try again.', ['current_version' => (int) $this->version]);
        }
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
        return ['kind' => SavedLocationKind::class, 'is_default' => 'boolean', 'version' => 'integer'];
    }
}
