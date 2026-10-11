<?php

namespace App\Services\Journey;

use App\Enums\DetourStatus;
use App\Enums\JourneySort;
use App\Enums\RestaurantStatus;
use App\Enums\RouteCoverage;
use App\Exceptions\ApiException;
use App\Exceptions\ProviderException;
use App\Models\Journey;
use App\Models\RestaurantLocation;
use App\Services\Journey\Data\DetourResult;
use App\Services\Journey\Data\RouteOptions;
use App\Services\Journey\Data\RoutePlan;
use App\Services\Journey\Providers\ProviderManager;
use App\Services\Restaurant\RestaurantAvailability;
use App\Services\Restaurant\RestaurantAvailabilityService;
use App\Services\Restaurant\RestaurantVisibility;
use App\Support\Geo\Location;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * Route-based restaurant discovery (Module 26 pipeline, steps 8–18):
 *
 *   PostGIS shortlist   restaurants within the search corridor of the journey's route (ST_DWithin on geography,
 *                       GiST), with their distance to the route and their progress along it, eligible (visible,
 *                       serviceable, active menu) and cheaply filtered — bounded by sql_limit
 *   bounded selection   per route segment, nearest first, round robin across segments, never above the cap
 *   actual detours      ONLY the shortlist goes to the routing provider, in batches; a straight line is never
 *                       passed off as a detour
 *   arrival + hours     departure + first leg, evaluated in the restaurant's own time zone
 *   ranking             deterministic rules; result filters; bounded pages from a short-lived cached candidate
 *                       set (paging never recalculates detours), with restaurant status re-checked on every page
 */
final class RouteDiscoveryService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly ProviderCall $call,
        private readonly RestaurantVisibility $visibility,
        private readonly RestaurantAvailabilityService $availability,
        private readonly JourneyPolicy $policy,
    ) {}

    public function search(Journey $journey, DiscoveryFilters $filters): DiscoveryResult
    {
        $started = hrtime(true);
        $journey->loadMissing('market');
        $policy = $this->policy->forMarket($journey->market);
        $corridor = $this->policy->clampCorridor($filters->corridorWidthMeters ?? (int) $journey->corridor_width_meters);
        $maxDetour = $this->policy->clampDetour($filters->maxDetourMinutes ?? $policy['max_detour_minutes']);

        $key = self::cacheKey($journey, $filters, $corridor);
        $computed = Cache::get($key);
        $cached = is_array($computed);
        if (! $cached) {
            [$computed, $cached] = $this->computeOnce($key, fn (): array => $this->compute($journey, $filters, $corridor, $policy));
        }

        $candidates = array_values(array_filter($computed['candidates'], fn (array $c): bool => $c['detour_seconds'] <= $maxDetour * 60 && (! $filters->openAtArrival || $c['open_at_arrival'])));
        if ($filters->sort === JourneySort::HighestRated || $filters->minRating !== null) {
            // Ratings are read now, never from the cached candidate set: a review published a minute ago counts.
            $ratings = DB::table('restaurant_location_rating_summaries')->whereIn('location_id', array_column($candidates, 'location_id'))->where('published_count', '>', 0)
                ->get(['location_id', 'average_rating', 'published_count'])->keyBy('location_id');
            foreach ($candidates as &$candidate) {
                $row = $ratings[$candidate['location_id']] ?? null;
                $candidate['rating_hundredths'] = $row === null ? null : (int) round(((float) $row->average_rating) * 100);
                $candidate['rating_count'] = $row === null ? 0 : (int) $row->published_count;
            }
            unset($candidate);
            if ($filters->minRating !== null) {
                $floor = (int) round($filters->minRating * 100);
                $candidates = array_values(array_filter($candidates, fn (array $c): bool => $c['rating_hundredths'] !== null && $c['rating_hundredths'] >= $floor));
            }
        }
        $this->sort($candidates, $filters->sort);
        $total = count($candidates);
        $pageRows = array_slice($candidates, ($filters->page - 1) * $filters->pageSize, $filters->pageSize);
        [$items, $dropped] = $this->freshen($pageRows);

        $alongRoute = (string) (((array) $journey->coverage)['along_route'] ?? RouteCoverage::Covered->value);
        $candidateFilters = $filters->cuisines !== [] || $filters->features !== [] || $filters->pickupMethod !== null || $filters->query !== null;
        $emptyReason = $total > 0 ? null : match (true) {
            $alongRoute === RouteCoverage::NoServiceCoverage->value => RouteCoverage::NoServiceCoverage->value,
            $computed['counts']['corridor_rows'] === 0 && ! $candidateFilters => 'NO_RESTAURANTS_IN_CORRIDOR',
            $computed['counts']['corridor_rows'] === 0 => 'FILTERS',
            $computed['candidates'] === [] => 'NO_REACHABLE_RESTAURANTS',
            default => 'FILTERS',
        };

        Log::info('journey.discovery', [
            'request_id' => Context::get('request_id'), 'journey' => $journey->public_id, 'cached' => $cached, 'page' => $filters->page, 'results' => $total, 'page_items' => count($items), 'dropped' => $dropped,
            'counts' => $computed['counts'], 'provider_ms' => $computed['provider_ms'], 'total_ms' => (int) round((hrtime(true) - $started) / 1_000_000), 'empty_reason' => $emptyReason,
        ]);

        return new DiscoveryResult($items, $total, $filters->page, $filters->pageSize, [
            'journey_id' => $journey->public_id,
            'search' => $filters->toArray($corridor, $maxDetour) + ['corridor_min_meters' => $policy['corridor_min_meters'], 'corridor_max_meters' => $policy['corridor_max_meters'], 'max_detour_max_minutes' => $policy['max_detour_max_minutes'], 'candidate_cap' => $policy['candidate_cap']],
            'departure_at' => $computed['departure_at'],
            'calculated_at' => $computed['calculated_at'],
            'cached' => $cached,
            'provider' => ['name' => $journey->provider, 'mode' => $journey->provider_mode],
            'coverage' => ['along_route' => $alongRoute, 'serviceable_areas' => (int) (((array) $journey->coverage)['serviceable_areas'] ?? 0)],
            'counts' => $computed['counts'] + ['matching' => $total, 'dropped_since_calculation' => $dropped],
            'empty_reason' => $emptyReason,
        ]);
    }

    /**
     * Cache key of a journey's candidate set (journey + everything that changes the shortlist).
     */
    public static function cacheKey(Journey $journey, DiscoveryFilters $filters, int $corridorWidthMeters): string
    {
        return 'journey:'.$journey->public_id.':results:'.$filters->candidateKey($corridorWidthMeters);
    }

    /**
     * Runs the computation under an atomic lock, so concurrent first reads of the same journey do not each send
     * the shortlist to the routing provider: the others wait and reuse the result. A lock that cannot be obtained
     * in time never fails a search — that request computes on its own.
     *
     * @param  Closure(): array<string, mixed>  $compute
     * @return array{0: array<string, mixed>, 1: bool} candidate set, whether it came from another computation
     */
    private function computeOnce(string $key, Closure $compute): array
    {
        $store = function () use ($key, $compute): array {
            $computed = $compute();
            Cache::put($key, $computed, (int) config('journey.cache.results_seconds'));

            return [$computed, false];
        };

        try {
            return Cache::lock($key.':lock', max(1, (int) config('journey.cache.compute_lock_seconds')))
                ->block(max(0, (int) config('journey.cache.compute_wait_seconds')), function () use ($key, $store): array {
                    $existing = Cache::get($key);

                    return is_array($existing) ? [$existing, true] : $store();
                });
        } catch (LockTimeoutException) {
            return $store();
        }
    }

    /**
     * The expensive part, run once per journey + candidate-set filters and cached: shortlist, detours, arrival.
     *
     * @param  array<string, int>  $policy
     * @return array{calculated_at: string, departure_at: string, candidates: list<array<string, mixed>>, counts: array<string, int>, provider_ms: int}
     */
    private function compute(Journey $journey, DiscoveryFilters $filters, int $corridor, array $policy): array
    {
        $departure = $journey->departure();
        [$selected, $corridorRows] = $this->shortlist($journey, $filters, $corridor, $departure, $policy);
        $counts = ['corridor_rows' => $corridorRows, 'shortlist' => count($selected), 'detour_requested' => 0, 'unreachable' => 0, 'provider_calls' => 0];
        $candidates = [];
        $providerMs = 0;

        if ($selected !== []) {
            $locations = $this->load(array_column($selected, 'id'));
            $waypoints = [];
            foreach ($selected as $row) {
                $location = $locations->get((int) $row['id']);
                if ($location !== null) {
                    $waypoints[$location->public_id] = $location->position();
                }
            }
            // A detour depends on the journey and the restaurant only — not on the filters or the corridor width. The ones
            // this journey already has are reused; only restaurants not yet calculated go to the routing provider.
            $detours = $this->knownDetours($journey);
            $missing = array_diff_key($waypoints, $detours);
            $counts['detour_requested'] = count($missing);
            if ($missing !== []) {
                $this->limitDetourRequests($journey);
                $started = hrtime(true);
                [$fresh, $counts['provider_calls']] = $this->detours($journey->routePlan(), $missing, RouteOptions::fromPreferences((array) $journey->preferences, $journey->departure_given ? $departure : null), $policy['candidate_cap']);
                $providerMs = (int) round((hrtime(true) - $started) / 1_000_000);
                $detours = $this->rememberDetours($journey, $detours, $fresh);
            }

            foreach ($selected as $row) {
                $location = $locations->get((int) $row['id']);
                $detour = $location === null ? null : ($detours[$location->public_id] ?? null);
                if ($location === null || $detour === null || ! $detour->usable()) {
                    $counts['unreachable']++;

                    continue;
                }
                $arrival = $departure->addSeconds((int) $detour->legToWaypointSeconds);
                $atArrival = $this->availability->evaluate($location, $arrival);
                $candidates[] = [
                    'location_id' => (int) $location->getKey(),
                    'public_id' => $location->public_id,
                    'distance_to_route_m' => (int) round((float) $row['distance_to_route_m']),
                    'route_progress' => round((float) $row['route_progress'], 4),
                    'segment' => (int) $row['segment'],
                    'detour_seconds' => (int) $detour->detourSeconds,
                    'detour_meters' => (int) $detour->detourDistanceMeters,
                    'leg_seconds' => (int) $detour->legToWaypointSeconds,
                    'leg_meters' => (int) $detour->legToWaypointMeters,
                    'arrival_at' => $arrival->utc()->toIso8601String(),
                    'open_at_arrival' => $atArrival->isOpen(),
                    'orderable_at_arrival' => $atArrival->orderable,
                    'prep_minutes' => $location->pickupSettings?->default_prep_minutes === null ? null : (int) $location->pickupSettings->default_prep_minutes,
                ];
            }
        }

        $journey->newQuery()->whereKey($journey->getKey())->update(['shortlist_count' => $counts['shortlist'], 'detour_candidates_count' => $counts['detour_requested'], 'last_accessed_at' => now()]);

        return ['calculated_at' => now()->utc()->toIso8601String(), 'departure_at' => $departure->utc()->toIso8601String(), 'candidates' => $candidates, 'counts' => $counts, 'provider_ms' => $providerMs];
    }

    /**
     * The bounded shortlist, in three steps. Every value is a bound parameter and the geometry is read from the
     * journey row itself.
     *
     *   corridor     restaurant locations of the market within the corridor of the journey's search geometry:
     *                ST_DWithin on geography (metres on the spheroid, GiST), probed once per compact PIECE of the
     *                route so every probe has a tight bounding box whatever the direction of the route; with the
     *                distance to the route and the progress along it (ST_LineLocatePoint, 0..1 from origin to
     *                destination). A restaurant matched by two pieces is listed once.
     *   eligibility  of those, the ones a customer may order from on this journey: visible, in an area being
     *                served, with an ACTIVE menu, passing the cheap filters (cuisine, feature, pickup method, text)
     *   bound        the route is cut into segments; a segment keeps at most its share (the configured per-segment
     *                cap, or an even share of the overall cap when the route has few segments) and candidates are
     *                taken nearest first, round robin across the segments, up to candidate_cap — a long route is
     *                represented from start to end and a dense city never crowds the rest of the route out
     *
     * @param  array<string, int>  $policy
     * @return array{0: list<array{id: int, distance_to_route_m: float, route_progress: float, segment: int}>, 1: int} shortlist, eligible rows in the corridor
     */
    private function shortlist(Journey $journey, DiscoveryFilters $filters, int $corridor, CarbonImmutable $departure, array $policy): array
    {
        $near = $this->corridor($journey, $corridor);
        if ($near->isEmpty()) {
            return [[], 0];
        }

        $eligible = array_flip($this->eligible($near->pluck('id')->all(), $filters, $departure));
        $rows = $near->filter(fn (object $row): bool => isset($eligible[(int) $row->id]))->values();

        return [$this->selectBounded($rows, (int) $journey->distance_meters, $policy), $rows->count()];
    }

    /**
     * Step 1 — the corridor, nearest first. The route is cut into compact pieces (vertices at most
     * piece_segment_meters apart, a few vertices per piece): one spatial-index probe per piece instead of one probe
     * with the bounding box of the whole route, which for a long diagonal route covers far more than the corridor.
     *
     * @return Collection<int, object{id: int, distance_to_route_m: float, route_progress: float}>
     */
    private function corridor(Journey $journey, int $corridor): Collection
    {
        // The pieces are MATERIALIZED: computed exactly once, whatever join order the planner prefers.
        return collect(DB::select(
            'with pieces as materialized ('
            .' select j.search_geometry, (ST_Subdivide(ST_Segmentize(j.search_geometry::geography, ?)::geometry, ?))::geography as piece from journeys j where j.id = ?'
            .') select l.id,'
            .' min(ST_Distance(l.location, p.piece)) as distance_to_route_m,'
            .' min(greatest(0.0, least(1.0, ST_LineLocatePoint(p.search_geometry, l.location::geometry)))) as route_progress'
            .' from pieces p join restaurant_locations l on ST_DWithin(l.location, p.piece, ?)'
            // l.status is a cheap pre-filter; the visibility rule itself is applied in step 2
            .' where l.market_id = ? and l.status = ?'
            // a restaurant near the joint of two pieces matches both: grouped, it is listed once with its nearest distance
            .' group by l.id order by distance_to_route_m, l.id'
            // safety valve for a pathologically dense corridor: only the nearest N go on
            .' limit ?',
            [
                max(100, (int) config('journey.geometry.piece_segment_meters')), max(5, (int) config('journey.geometry.piece_max_vertices')), $journey->getKey(),
                $corridor, $journey->market_id, RestaurantStatus::Approved->value, max(1, (int) config('journey.candidates.sql_limit')),
            ],
        ));
    }

    /**
     * Step 2 — which of the corridor's restaurants are eligible for this journey.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function eligible(array $ids, DiscoveryFilters $filters, CarbonImmutable $departure): array
    {
        $query = RestaurantLocation::query()->withoutGlobalScope('coordinates')->whereIntegerInRaw('restaurant_locations.id', $ids);

        $this->visibility->scopeVisible($query);
        $this->visibility->whereServiceableAt($query, $departure);

        if (config('journey.eligibility.require_active_menu')) {
            $query->whereExists(fn ($menu) => $menu->selectRaw('1')->from('menus')->whereColumn('menus.location_id', 'restaurant_locations.id')->where('menus.status', 'ACTIVE'));
        }
        if ($filters->cuisines !== []) {
            $query->whereExists(fn ($cuisine) => $cuisine->selectRaw('1')->from('restaurant_location_cuisines as fc')->join('cuisines as c', 'c.id', '=', 'fc.cuisine_id')
                ->whereColumn('fc.location_id', 'restaurant_locations.id')->whereIn('c.code', $filters->cuisines));
        }
        foreach ($filters->features as $code) {
            $query->whereExists(fn ($feature) => $feature->selectRaw('1')->from('restaurant_location_features as ff')->join('restaurant_features as f', 'f.id', '=', 'ff.feature_id')
                ->whereColumn('ff.location_id', 'restaurant_locations.id')->where('f.code', $code));
        }
        if ($filters->pickupMethod !== null) {
            $query->whereExists(fn ($method) => $method->selectRaw('1')->from('restaurant_location_pickup_methods as pm')
                ->whereColumn('pm.location_id', 'restaurant_locations.id')->where('pm.method', $filters->pickupMethod)->where('pm.enabled', true));
        }
        if ($filters->query !== null) {
            $like = '%'.addcslashes($filters->query, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('restaurant_locations.name', 'ilike', $like)->orWhere('restaurant_locations.branch_label', 'ilike', $like));
        }

        return array_map('intval', $query->pluck('restaurant_locations.id')->all());
    }

    /**
     * Step 3 — the bounded, deterministic selection along the route (rows arrive nearest first).
     *
     * @param  Collection<int, object>  $rows
     * @param  array<string, int>  $policy
     * @return list<array{id: int, distance_to_route_m: float, route_progress: float, segment: int}>
     */
    private function selectBounded(Collection $rows, int $routeLengthMeters, array $policy): array
    {
        $routeLength = max(1, $routeLengthMeters);
        $segmentLength = max(1000, $policy['segment_length_meters']);
        $segmentCount = max(1, (int) ceil($routeLength / $segmentLength));
        $perSegment = max($policy['per_segment_cap'], (int) ceil($policy['candidate_cap'] / $segmentCount));

        $segments = [];
        foreach ($rows as $row) {
            $segment = min($segmentCount - 1, (int) floor((float) $row->route_progress * $routeLength / $segmentLength));
            if (count($segments[$segment] ?? []) < $perSegment) {
                $segments[$segment][] = ['id' => (int) $row->id, 'distance_to_route_m' => (float) $row->distance_to_route_m, 'route_progress' => (float) $row->route_progress, 'segment' => $segment];
            }
        }
        ksort($segments);

        $selected = [];
        for ($rank = 0; $rank < $perSegment && count($selected) < $policy['candidate_cap']; $rank++) {
            foreach ($segments as $list) {
                if (isset($list[$rank])) {
                    $selected[] = $list[$rank];
                    if (count($selected) >= $policy['candidate_cap']) {
                        break;
                    }
                }
            }
        }

        return $selected;
    }

    /**
     * The detour this journey already has for one restaurant, when it was calculated recently — a read of the
     * journey's own results, never a provider call. The cart (Module 27) keeps it as the context of its restaurant.
     */
    public function knownDetourFor(Journey $journey, string $locationPublicId): ?DetourResult
    {
        return $this->knownDetours($journey)[$locationPublicId] ?? null;
    }

    /**
     * The detours this journey already has (a restaurant unreachable by road included), not older than the result
     * cache lifetime. A provider answer that failed for one restaurant is never kept: it is asked again.
     *
     * @return array<string, DetourResult>
     */
    private function knownDetours(Journey $journey): array
    {
        $oldest = now()->getTimestamp() - (int) config('journey.cache.results_seconds');
        $known = [];
        foreach ((array) Cache::get(self::detourKey($journey), []) as $key => $entry) {
            $detour = is_array($entry) && is_array($entry[1] ?? null) && (int) ($entry[0] ?? 0) >= $oldest ? DetourResult::fromArray($entry[1]) : null;
            if ($detour !== null) {
                $known[(string) $key] = $detour;
            }
        }

        return $known;
    }

    /**
     * @param  array<string, DetourResult>  $known
     * @param  array<string, DetourResult>  $fresh
     * @return array<string, DetourResult>
     */
    private function rememberDetours(Journey $journey, array $known, array $fresh): array
    {
        $now = now()->getTimestamp();
        $stored = (array) Cache::get(self::detourKey($journey), []);
        foreach ($fresh as $key => $detour) {
            if ($detour->status !== DetourStatus::Failed) {
                $stored[(string) $key] = [$now, $detour->toArray()];
                $known[(string) $key] = $detour;
            }
        }
        Cache::put(self::detourKey($journey), $stored, (int) config('journey.cache.results_seconds'));

        return $known + $fresh;
    }

    private static function detourKey(Journey $journey): string
    {
        return 'journey:'.$journey->public_id.':detours';
    }

    /**
     * Reads of calculated results are cheap; sending a shortlist to the routing provider is not. The latter is limited
     * per journey (rate_limits.journey_detours per minute) — a client that keeps changing the search cannot turn one
     * journey into an unbounded stream of provider calls.
     */
    private function limitDetourRequests(Journey $journey): void
    {
        $key = 'journey-detours:'.$journey->public_id;
        $max = max(1, (int) config('rate_limits.journey_detours'));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);
            throw new ApiException(429, 'rate_limited', 'Too many searches for this journey. Please try again shortly.', ['retry_after_seconds' => $seconds]);
        }
        RateLimiter::hit($key, 60);
    }

    /**
     * Actual detours from the routing provider for the shortlist, in batches — and ONLY for the shortlist: the
     * cap is asserted here as well, so no code path can ever hand the provider the whole restaurant table.
     *
     * @param  array<string, Location>  $waypoints
     * @return array{0: array<string, DetourResult>, 1: int} results, provider calls made
     */
    private function detours(RoutePlan $plan, array $waypoints, RouteOptions $options, int $cap): array
    {
        if (count($waypoints) > $cap) {
            throw new LogicException('Detour calculation received '.count($waypoints)." candidates; the shortlist cap is {$cap}.");
        }
        $results = [];
        $calls = 0;
        try {
            $provider = $this->providers->routing();
            foreach (array_chunk($waypoints, max(1, (int) config('journey.providers.detour_batch_size')), true) as $chunk) {
                $results += $this->call->run($provider->name(), 'routing.detours', fn (): array => $provider->detours($plan, $chunk, $options));
                $calls++;
            }
        } catch (ProviderException $e) {
            if ($e->failure->value === 'MISCONFIGURED') {
                Log::error('journey.provider_misconfigured', ['provider' => $e->provider, 'request_id' => Context::get('request_id')]);
            }
            throw $e->toApi('routing');
        }

        return [$results, $calls];
    }

    /**
     * The shortlisted locations with everything the availability rule reads, in a few queries (never one per row).
     *
     * @param  list<int>  $ids
     * @return Collection<int, RestaurantLocation>
     */
    private function load(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }
        $locations = RestaurantLocation::query()->whereIn('restaurant_locations.id', $ids)->with($this->relations())->get();
        $this->availability->preloadCoverage($locations);

        return $locations->keyBy(fn (RestaurantLocation $l): int => (int) $l->getKey());
    }

    /**
     * Re-reads the page's restaurants with the visibility rule and evaluates them at their arrival instant, so a
     * restaurant suspended, paused or sold out since the candidate set was calculated is dropped or shown as it
     * is now — the cache never keeps a restaurant orderable.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array{restaurant: RestaurantLocation, candidate: array<string, mixed>, arrival: RestaurantAvailability}>, 1: int}
     */
    private function freshen(array $rows): array
    {
        if ($rows === []) {
            return [[], 0];
        }
        $query = RestaurantLocation::query()->whereIn('restaurant_locations.id', array_column($rows, 'location_id'))->with($this->relations());
        $this->visibility->scopeVisible($query);
        $locations = $query->get();
        $this->availability->preloadCoverage($locations);
        $byId = $locations->keyBy(fn (RestaurantLocation $l): int => (int) $l->getKey());

        $items = [];
        $dropped = 0;
        foreach ($rows as $row) {
            $location = $byId->get((int) $row['location_id']);
            if ($location === null) {
                $dropped++;

                continue;
            }
            $arrival = $this->availability->evaluate($location, CarbonImmutable::parse($row['arrival_at']));
            $row['open_at_arrival'] = $arrival->isOpen();
            $row['orderable_at_arrival'] = $arrival->orderable;
            $items[] = ['restaurant' => $location, 'candidate' => $row, 'arrival' => $arrival];
        }

        return [$items, $dropped];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function sort(array &$candidates, JourneySort $sort): void
    {
        $key = fn (array $c): array => match ($sort) {
            JourneySort::Recommended => [$c['orderable_at_arrival'] ? 0 : 1, $c['open_at_arrival'] ? 0 : 1, $c['detour_seconds'], $c['route_progress'], $c['location_id']],
            JourneySort::LowestDetour => [$c['detour_seconds'], $c['route_progress'], $c['location_id']],
            JourneySort::RouteOrder => [$c['route_progress'], $c['detour_seconds'], $c['location_id']],
            JourneySort::NearestToRoute => [$c['distance_to_route_m'], $c['detour_seconds'], $c['location_id']],
            JourneySort::FastestPickup => [$c['prep_minutes'] ?? PHP_INT_MAX, $c['detour_seconds'], $c['location_id']],
            JourneySort::HighestRated => [($c['rating_hundredths'] ?? null) === null ? 1 : 0, -($c['rating_hundredths'] ?? 0), -($c['rating_count'] ?? 0), $c['detour_seconds'], $c['location_id']],
        };
        usort($candidates, fn (array $a, array $b): int => $key($a) <=> $key($b));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function relations(): array
    {
        return [
            'organization', 'market', 'city', 'region', 'cuisines', 'features', 'images', 'hours', 'pickupSettings', 'pickupMethods', 'ratingSummary',
            'specialHours' => fn ($query) => $query->where('date', '>=', now()->subDays(2)->toDateString()),
            'specialHours.periods',
        ];
    }
}
