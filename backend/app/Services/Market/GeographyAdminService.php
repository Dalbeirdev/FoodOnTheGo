<?php

namespace App\Services\Market;

use App\Auth\Principal;
use App\Enums\CityStatus;
use App\Enums\CoverageStatus;
use App\Enums\MarketStatus;
use App\Enums\RegionStatus;
use App\Exceptions\ApiException;
use App\Models\City;
use App\Models\Market;
use App\Models\MarketConfiguration;
use App\Models\MarketRegion;
use App\Models\RouteCorridor;
use App\Models\ServiceArea;
use App\Services\Audit\AuditRecorder;
use App\Support\Geo\GeoJsonGeometry;
use App\Support\Logging\SensitiveDataRedactor;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every administrative change to markets and their geography goes through here, so the rules are applied
 * in one place:
 *
 *  - optimistic concurrency: the caller sends the `version` it edited; a stale version writes nothing (409);
 *  - the row is locked for the duration of the change;
 *  - status changes follow the allowed transitions of the status enum (409 otherwise);
 *  - taking something away from customers needs a reason; market status and feature changes always do;
 *  - new records always start PLANNED — nothing is created live;
 *  - geometry is validated before it is stored and must lie inside the market's bounds;
 *  - one audit event per change, with the actor, the reason and the before / after values;
 *  - the public market cache is invalidated.
 *
 * Authorization (permission + market scope) is the controller's job and happens before any of this.
 */
final class GeographyAdminService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly MarketContext $markets,
    ) {}

    /**
     * @param  array{version: int, status?: string, reason?: string|null}  $input
     */
    public function updateMarket(Market $market, array $input, Principal $actor): Market
    {
        return $this->change($market, $market, $input, $actor, 'market.updated', MarketStatus::class, reasonAlways: true);
    }

    /**
     * @param  array{version: int, features: array<string, bool>, reason: string}  $input
     */
    public function updateFeatures(Market $market, array $input, Principal $actor): Market
    {
        $locked = $this->configuration($market)->locked_features ?? [];

        foreach ($input['features'] as $key => $enabled) {
            if (! array_key_exists($key, $market->features ?? [])) {
                throw ValidationException::withMessages(["features.{$key}" => ['This market has no such feature.']]);
            }
            if ($enabled && in_array($key, $locked, true)) {
                throw ApiException::conflict('feature_locked', 'This feature is locked for the market and cannot be enabled.', ['feature' => $key]);
            }
        }

        return $this->change($market, $market, ['version' => $input['version'], 'reason' => $input['reason'], 'features' => [...$market->features, ...$input['features']]], $actor, 'market.features_updated', null, reasonAlways: true, fields: ['features']);
    }

    public function configuration(Market $market): MarketConfiguration
    {
        $query = MarketConfiguration::query()->where('market_id', $market->getKey());

        if (! $query->exists()) {
            (new MarketConfiguration)->forceFill(['market_id' => $market->getKey()])->save();
        }

        return $query->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $input  version, reason and any of the configuration categories
     */
    public function updateConfiguration(Market $market, array $input, Principal $actor): MarketConfiguration
    {
        // Provider credentials live in server configuration, never in the database: refuse secret-looking keys.
        $redactor = new SensitiveDataRedactor(config('logging.redact_keys', []));
        if ($redactor->redact($input) !== $input) {
            throw new ApiException(422, 'secrets_not_allowed', 'Market configuration must not contain credentials or secrets.');
        }

        // A payment method is PLANNED, ENABLED or NOT_APPROVED — and one that is locked for the market (cash at
        // pickup) can never be switched on through configuration either.
        $locked = $input['locked_features'] ?? $this->configuration($market)->locked_features ?? [];
        foreach ((array) ($input['payment']['methods'] ?? []) as $method => $status) {
            if (! in_array($status, ['PLANNED', 'ENABLED', 'NOT_APPROVED'], true)) {
                throw ValidationException::withMessages(["payment.methods.{$method}" => ['The status must be PLANNED, ENABLED or NOT_APPROVED.']]);
            }
            if ($status === 'ENABLED' && in_array($method, $locked, true)) {
                throw ApiException::conflict('feature_locked', 'This payment method is locked for the market and cannot be enabled.', ['feature' => $method]);
            }
        }

        return $this->change($this->configuration($market), $market, $input, $actor, 'market.configuration_updated', null, reasonAlways: true, fields: ['payment', 'tax', 'legal', 'address', 'ordering', 'locked_features']);
    }

    /**
     * @param  array{code: string, name: string, type: string}  $input
     */
    public function createRegion(Market $market, array $input, Principal $actor): MarketRegion
    {
        $this->assertUnique(MarketRegion::query()->where('market_id', $market->getKey())->where('code', $input['code']), 'code');

        $region = (new MarketRegion)->forceFill(['market_id' => $market->getKey(), 'status' => RegionStatus::Planned, ...$input]);

        return $this->created($region, $market, $actor, 'region.created', fn () => $region->save());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateRegion(MarketRegion $region, Market $market, array $input, Principal $actor): MarketRegion
    {
        return $this->change($region, $market, $input, $actor, 'region.updated', RegionStatus::class, fields: ['name', 'type']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createCity(Market $market, MarketRegion $region, array $input, Principal $actor): City
    {
        $slug = Str::slug($input['slug'] ?? $input['name']);
        $this->assertUnique(City::query()->where('cities.market_id', $market->getKey())->where('cities.slug', $slug), 'name');
        $center = City::centerExpression((float) $input['latitude'], (float) $input['longitude']);
        $this->assertInsideMarket($market, $center[0].'::geometry', $center[1], 'latitude');

        $city = (new City)->forceFill([
            'market_id' => $market->getKey(),
            'region_id' => $region->getKey(),
            'name' => $input['name'],
            'slug' => $slug,
            'aliases' => array_values($input['aliases'] ?? []),
            'timezone' => $input['timezone'] ?? $market->default_timezone,
            'status' => CityStatus::Planned->value,
            'launch_stage' => $input['launch_stage'] ?? null,
        ]);

        return $this->created($city, $market, $actor, 'city.created', fn () => $city->insertWithSpatial(['center' => $center]));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateCity(City $city, Market $market, array $input, Principal $actor): City
    {
        $center = null;
        if (isset($input['latitude'], $input['longitude'])) {
            $center = City::centerExpression((float) $input['latitude'], (float) $input['longitude']);
            $this->assertInsideMarket($market, $center[0].'::geometry', $center[1], 'latitude');
        }

        return $this->change($city, $market, $input, $actor, 'city.updated', CityStatus::class, fields: ['name', 'aliases', 'timezone', 'launch_stage'],
            spatial: $center === null ? null : ['center', $center, ['latitude' => (float) $input['latitude'], 'longitude' => (float) $input['longitude']]]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createServiceArea(Market $market, City $city, array $input, GeoJsonGeometry $geometry, Principal $actor): ServiceArea
    {
        $slug = Str::slug($input['slug'] ?? $input['name']);
        $this->assertUnique(ServiceArea::query()->where('service_areas.city_id', $city->getKey())->where('service_areas.slug', $slug), 'name');
        $expression = ServiceArea::geometryExpression($geometry->json);
        $this->assertInsideMarket($market, ...$expression);

        $area = (new ServiceArea)->forceFill([
            'market_id' => $market->getKey(),
            'city_id' => $city->getKey(),
            'name' => $input['name'],
            'slug' => $slug,
            'status' => CoverageStatus::Planned->value,
            'priority' => (int) ($input['priority'] ?? 0),
            'launch_stage' => $input['launch_stage'] ?? null,
            'effective_from' => $input['effective_from'] ?? null,
            'effective_until' => $input['effective_until'] ?? null,
        ]);

        return $this->created($area, $market, $actor, 'service_area.created', fn () => $area->insertWithSpatial(['geometry' => $expression]));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateServiceArea(ServiceArea $area, Market $market, array $input, ?GeoJsonGeometry $geometry, Principal $actor): ServiceArea
    {
        $spatial = null;
        if ($geometry !== null) {
            $expression = ServiceArea::geometryExpression($geometry->json);
            $this->assertInsideMarket($market, ...$expression);
            $spatial = ['geometry', $expression, ['type' => $geometry->type, 'positions' => $geometry->positions]];
        }

        return $this->change($area, $market, $input, $actor, 'service_area.updated', CoverageStatus::class, fields: ['name', 'priority', 'launch_stage', 'effective_from', 'effective_until'], spatial: $spatial);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createRouteCorridor(Market $market, array $input, GeoJsonGeometry $geometry, Principal $actor): RouteCorridor
    {
        $slug = Str::slug($input['slug'] ?? $input['name']);
        $this->assertUnique(RouteCorridor::query()->where('route_corridors.market_id', $market->getKey())->where('route_corridors.slug', $slug), 'name');
        $expression = RouteCorridor::centerlineExpression($geometry->json);
        $this->assertInsideMarket($market, ...$expression);

        $corridor = (new RouteCorridor)->forceFill([
            'market_id' => $market->getKey(),
            'origin_city_id' => $input['origin_city_id'] ?? null,
            'destination_city_id' => $input['destination_city_id'] ?? null,
            'via_city_ids' => array_values($input['via_city_ids'] ?? []),
            'name' => $input['name'],
            'slug' => $slug,
            'highway' => $input['highway'] ?? null,
            'status' => CoverageStatus::Planned->value,
            'corridor_width_meters' => (int) $input['corridor_width_meters'],
            'effective_from' => $input['effective_from'] ?? null,
            'effective_until' => $input['effective_until'] ?? null,
        ]);

        return $this->created($corridor, $market, $actor, 'route_corridor.created', fn () => $corridor->insertWithSpatial(['centerline' => $expression]));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateRouteCorridor(RouteCorridor $corridor, Market $market, array $input, ?GeoJsonGeometry $geometry, Principal $actor): RouteCorridor
    {
        $spatial = null;
        if ($geometry !== null) {
            $expression = RouteCorridor::centerlineExpression($geometry->json);
            $this->assertInsideMarket($market, ...$expression);
            $spatial = ['centerline', $expression, ['type' => $geometry->type, 'positions' => $geometry->positions]];
        }

        return $this->change($corridor, $market, $input, $actor, 'route_corridor.updated', CoverageStatus::class, fields: ['name', 'highway', 'corridor_width_meters', 'effective_from', 'effective_until'], spatial: $spatial);
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $record
     * @param  callable(): mixed  $insert
     * @return TModel
     */
    private function created(Model $record, Market $market, Principal $actor, string $action, callable $insert): Model
    {
        return DB::transaction(function () use ($record, $market, $actor, $action, $insert): Model {
            $insert();
            $fresh = $record->newQuery()->where('public_id', $record->getAttribute('public_id'))->firstOrFail();
            $this->audit->record($action, $fresh, $actor, ['name' => ['from' => null, 'to' => $fresh->getAttribute('name')], 'status' => ['from' => null, 'to' => 'PLANNED']], null, (int) $market->getKey());
            $this->markets->flush();

            return $fresh;
        });
    }

    /**
     * The one update path. Returns the record as it is after the change (re-read, so derived values such
     * as area or length are current).
     *
     * @template TModel of Model
     *
     * @param  TModel  $record
     * @param  array<string, mixed>  $input
     * @param  class-string<BackedEnum>|null  $statusEnum
     * @param  list<string>  $fields  attributes the caller may change besides the status
     * @param  array{0: string, 1: array{0: string, 1: list<mixed>}, 2: array<string, mixed>}|null  $spatial  column, expression, audit summary
     * @return TModel
     */
    private function change(Model $record, Market $market, array $input, Principal $actor, string $action, ?string $statusEnum, bool $reasonAlways = false, array $fields = [], ?array $spatial = null): Model
    {
        return DB::transaction(function () use ($record, $market, $input, $actor, $action, $statusEnum, $reasonAlways, $fields, $spatial): Model {
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);

            $reason = trim((string) ($input['reason'] ?? ''));
            $needsReason = false;

            if ($statusEnum !== null && isset($input['status']) && $input['status'] !== $locked->status->value) {
                $target = $statusEnum::from($input['status']);

                if (! $locked->status->canBecome($target)) {
                    throw ApiException::conflict('invalid_status_transition', __('Status cannot change from :from to :to.', ['from' => $locked->status->value, 'to' => $target->value]), [
                        'from' => $locked->status->value, 'allowed' => $statusEnum::transitions()[$locked->status->value] ?? [],
                    ]);
                }

                $needsReason = $locked->status->needsReasonToBecome($target);
                $locked->setAttribute('status', $target);
                if ($target->servesCustomers() && array_key_exists('launched_at', $locked->getAttributes()) && $locked->getAttribute('launched_at') === null) {
                    $locked->setAttribute('launched_at', now());
                }
            }

            foreach ($fields as $field) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, $input[$field]);
                }
            }

            $changes = [];
            foreach (array_keys($locked->getDirty()) as $key) {
                $changes[$key] = ['from' => $this->plain($locked->getOriginal($key)), 'to' => $this->plain($locked->getAttribute($key))];
            }
            if ($spatial !== null) {
                $changes[$spatial[0]] = ['from' => 'replaced', 'to' => $spatial[2]];
            }

            if ($changes === []) {
                return $locked;
            }
            if (($reasonAlways || $needsReason) && $reason === '') {
                throw ValidationException::withMessages(['reason' => ['A reason is required for this change.']]);
            }

            $locked->setAttribute('version', (int) $locked->getAttribute('version') + 1);
            $locked->save();
            if ($spatial !== null) {
                $locked->updateSpatial($spatial[0], $spatial[1]);
            }

            $fresh = $locked->newQuery()->whereKey($locked->getKey())->firstOrFail();
            $this->audit->record($action, $fresh instanceof MarketConfiguration ? $market : $fresh, $actor, $changes, $reason, (int) $market->getKey());
            $this->markets->flush();

            return $fresh;
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function assertUnique($query, string $field): void
    {
        if ($query->exists()) {
            throw ValidationException::withMessages([$field => ['This already exists.']]);
        }
    }

    /**
     * Geometry must lie inside the market's bounds: a polygon drawn in the wrong country (or with latitude
     * and longitude swapped) is refused instead of stored.
     *
     * @param  list<mixed>  $bindings
     */
    private function assertInsideMarket(Market $market, string $expression, array $bindings, string $field = 'geometry'): void
    {
        $inside = DB::selectOne("select bounds is null or ST_Covers(bounds, {$expression}) as inside from markets where id = ?", [...$bindings, $market->getKey()]);

        if (! $inside->inside) {
            throw new ApiException(422, 'geometry_outside_market', __('The :field lies outside the market. Coordinates are [longitude, latitude] in WGS84.', ['field' => $field]));
        }
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default => $value,
        };
    }
}
