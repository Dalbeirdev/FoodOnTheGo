<?php

namespace App\Services\Analytics;

use App\Models\Journey;
use App\Services\Journey\DiscoveryResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What the BACKEND records about its own answers (Module 38): a journey was planned, discovery found nothing, these
 * restaurants were returned for a journey, a pickup time was refused.
 *
 * A journey lives for about an hour and is then deleted — "no permanent journey history is kept". What stays here is
 * deliberately less than a journey: a keyed hash instead of its id, the BAND of its length and duration, the static
 * corridors it runs along and the city near each end. No address, no coordinate, no polyline.
 *
 * Every method swallows its own failure: a journey, a discovery answer or a pickup choice is never slower to fail,
 * and never fails, because analytics could not write a row.
 */
final class ServerEventRecorder
{
    public function __construct(private readonly Pseudonymizer $pseudonyms, private readonly AnalyticsMetrics $metrics) {}

    public function journeyPlanned(Journey $journey): void
    {
        $this->safely(function () use ($journey): void {
            $coverage = (array) $journey->coverage;
            $corridorIds = array_values(array_filter(array_map(fn ($c): ?string => is_array($c) && isset($c['id']) ? (string) $c['id'] : null, (array) ($coverage['corridors'] ?? []))));
            $corridors = $corridorIds === [] ? [] : DB::table('route_corridors')->whereIn('public_id', $corridorIds)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $radius = (int) config('analytics.privacy.city_radius_km') * 1000;
            // The city near each end, or nothing: the journey's own points stay in the journey.
            $cities = DB::selectOne(
                'select (select c.id from cities c where c.market_id = j.market_id and ST_DWithin(c.center, j.origin_point, ?) order by ST_Distance(c.center, j.origin_point) limit 1) as origin_city,
                        (select c.id from cities c where c.market_id = j.market_id and ST_DWithin(c.center, j.destination_point, ?) order by ST_Distance(c.center, j.destination_point) limit 1) as destination_city
                 from journeys j where j.id = ?',
                [$radius, $radius, $journey->getKey()],
            );
            $properties = array_filter([
                'distance_band' => Bands::distance($journey->distance_meters === null ? null : (int) $journey->distance_meters),
                'duration_band' => Bands::duration($journey->duration_seconds === null ? null : (int) $journey->duration_seconds),
                'coverage' => in_array($coverage['along_route'] ?? null, ['COVERED', 'NO_SERVICE_COVERAGE'], true) ? $coverage['along_route'] : null,
                'corridors' => $corridors,
                'origin_city' => $cities?->origin_city === null ? null : (int) $cities->origin_city,
                'destination_city' => $cities?->destination_city === null ? null : (int) $cities->destination_city,
            ], fn ($v): bool => $v !== null);

            $this->store([$this->row('journey_search_succeeded', $journey, null, $properties)]);
        });
    }

    public function journeyDiscovered(Journey $journey, DiscoveryResult $result): void
    {
        $this->safely(function () use ($journey, $result): void {
            $reason = $result->meta['empty_reason'] ?? null;
            if ($result->total === 0) {
                // "FILTERS" is the traveller's own choice, not a gap in the service: it is not a no-result search.
                if (in_array($reason, ['NO_SERVICE_COVERAGE', 'NO_RESTAURANTS_IN_CORRIDOR', 'NO_REACHABLE_RESTAURANTS'], true)) {
                    $this->store([$this->row('journey_search_no_result', $journey, null, ['reason' => $reason])]);
                }

                return;
            }
            $rows = [];
            foreach ($result->items as $item) {
                $locationId = (int) $item['restaurant']->getKey();
                $band = Bands::detour(isset($item['candidate']['detour_seconds']) ? (int) $item['candidate']['detour_seconds'] : null);
                $rows[] = $this->row('restaurant_impression', $journey, $locationId, $band === null ? [] : ['detour_band' => $band]);
            }
            $this->store($rows);
        });
    }

    public function pickupRefused(?string $locationPublicId, ?string $reason): void
    {
        if ($locationPublicId === null) {
            return;
        }
        $this->safely(function () use ($locationPublicId, $reason): void {
            $location = DB::table('restaurant_locations')->where('public_id', $locationPublicId)->first(['id', 'market_id']);
            if ($location === null) {
                return;
            }
            $code = is_string($reason) && preg_match('/\A[A-Z0-9_]{1,40}\z/', $reason) === 1 ? $reason : 'OTHER';
            $now = CarbonImmutable::now('UTC');
            $this->store([[
                'event_id' => (string) Str::uuid(), 'event_name' => 'pickup_slot_refused', 'schema_version' => (int) config('analytics.events.schema_version'), 'source' => 'SERVER',
                'platform' => null, 'app_version' => null, 'occurred_at' => $now, 'received_at' => $now, 'session_id' => null, 'customer_key' => null,
                'market_id' => (int) $location->market_id, 'location_id' => (int) $location->id, 'journey_key' => null, 'checkout_ref' => null, 'item_ref' => null, 'properties' => json_encode(['reason' => $code]),
            ]]);
        });
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function row(string $name, Journey $journey, ?int $locationId, array $properties): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            // Derived from what it describes: recording the same thing again is refused by the unique index.
            'event_id' => $this->pseudonyms->eventId($name, (string) $journey->public_id, (string) ($locationId ?? '')),
            'event_name' => $name,
            'schema_version' => (int) config('analytics.events.schema_version'),
            'source' => 'SERVER',
            'platform' => null,
            'app_version' => null,
            'occurred_at' => $now,
            'received_at' => $now,
            'session_id' => null,
            'customer_key' => $journey->customer_id === null ? null : $this->pseudonyms->customer((int) $journey->customer_id),
            'market_id' => (int) $journey->market_id,
            'location_id' => $locationId,
            'journey_key' => $this->pseudonyms->journey((string) $journey->public_id),
            'checkout_ref' => null,
            'item_ref' => null,
            'properties' => json_encode((object) $properties),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): void
    {
        if ($rows === [] || ! (bool) config('analytics.events.enabled')) {
            return;
        }
        $this->metrics->record('events_server', max(0, (int) DB::table('analytics_events')->insertOrIgnore($rows)));
    }

    private function safely(\Closure $work): void
    {
        try {
            // A savepoint of its own: a refused row must not poison a transaction the caller may be in.
            DB::transaction($work);
        } catch (\Throwable $e) {
            Log::warning('analytics.server_event_failed', ['error' => $e::class]);
            try {
                $this->metrics->record('ingest_failed');
            } catch (\Throwable) {
                // Nothing an analytics failure does may reach the caller.
            }
        }
    }
}
