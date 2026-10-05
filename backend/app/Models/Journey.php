<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Services\Journey\Data\RouteGeometry;
use App\Services\Journey\Data\RoutePlan;
use App\Support\Geo\Location;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A journey context (Module 26): two ends, a departure, the provider's route and the coverage answer, owned by
 * a customer or by whoever holds the guest token. Ephemeral — it expires and is pruned. Nothing is
 * mass-assignable: JourneyService writes every column after validation.
 *
 * The raw spatial columns are never serialized; the route travels as the encoded polyline / GeoJSON.
 *
 * @property-read string|null $route_geojson
 */
#[Hidden(['route_geometry', 'search_geometry', 'origin_point', 'destination_point', 'guest_token_hash'])]
class Journey extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    public const MODE_DEVELOPMENT = 'development';

    public const MODE_LIVE = 'live';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::addGlobalScope('geometry', function (Builder $query): void {
            self::selectSummary($query, ['journeys.*'], 'ST_AsGeoJSON(journeys.route_geometry) as route_geojson');
        });
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
     * @param  Builder<Journey>  $query
     */
    public function scopeExpired(Builder $query, ?CarbonInterface $before = null): void
    {
        $query->where('journeys.expires_at', '<', $before ?? now());
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function ownedBy(?Customer $customer): bool
    {
        return $customer !== null && $this->customer_id !== null && (int) $this->customer_id === (int) $customer->getKey();
    }

    public function acceptsGuestToken(?string $token): bool
    {
        return $this->isGuest() && is_string($token) && $token !== '' && $this->guest_token_hash !== null
            && hash_equals((string) $this->guest_token_hash, hash('sha256', $token));
    }

    /**
     * The departure the estimates are built on: the given instant, otherwise "now" at the time of the search.
     */
    public function departure(?CarbonInterface $now = null): CarbonImmutable
    {
        return $this->departure_at !== null ? CarbonImmutable::instance($this->departure_at) : CarbonImmutable::instance($now ?? now());
    }

    public function originLocation(): Location
    {
        return self::locationFrom((array) $this->origin);
    }

    public function destinationLocation(): Location
    {
        return self::locationFrom((array) $this->destination);
    }

    public function geometry(): RouteGeometry
    {
        return RouteGeometry::fromEncodedPolyline((string) $this->encoded_polyline);
    }

    /**
     * The route as the provider delivered it, rebuilt for detour calculation.
     */
    public function routePlan(): RoutePlan
    {
        return new RoutePlan(
            $this->originLocation(), $this->destinationLocation(), (int) $this->distance_meters, (int) $this->duration_seconds, $this->geometry(),
            (array) $this->legs, (string) $this->provider, (bool) $this->traffic_aware, CarbonImmutable::instance($this->route_calculated_at), (array) $this->flags, (array) $this->warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $end
     */
    private static function locationFrom(array $end): Location
    {
        return new Location(
            (float) $end['lat'], (float) $end['lng'], $end['country_code'] ?? null, $end['formatted_address'] ?? null,
            $end['place']['id'] ?? null, $end['region'] ?? null, $end['locality'] ?? null, $end['postal_code'] ?? null, $end['timezone'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => 'array',
            'destination' => 'array',
            'bounds' => 'array',
            'legs' => 'array',
            'flags' => 'array',
            'warnings' => 'array',
            'preferences' => 'array',
            'coverage' => 'array',
            'departure_at' => 'datetime',
            'departure_given' => 'boolean',
            'traffic_aware' => 'boolean',
            'route_calculated_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'distance_meters' => 'integer',
            'duration_seconds' => 'integer',
            'corridor_width_meters' => 'integer',
            'route_points' => 'integer',
            'search_points' => 'integer',
            'shortlist_count' => 'integer',
            'detour_candidates_count' => 'integer',
            'provider_latency_ms' => 'integer',
        ];
    }
}
