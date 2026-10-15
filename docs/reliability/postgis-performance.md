# PostGIS performance

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources:
- `backend/app/Services/Journey/RouteDiscoveryService.php` (corridor shortlist)
- `backend/app/Services/Journey/JourneyService.php` (coverage along the route)
- `backend/app/Services/Market/MarketLocationResolver.php`
- `backend/config/journey.php`
- EXPLAIN ANALYZE on `foodonthego_perf` (PostgreSQL 18.6, PostGIS 3.6.2), medium dataset
- `docs/local-review/test-results/m40-query-profile-medium-after.json`
- `m40-baseline-medium.json`

## 1. Spatial queries and their indexes

| Query | Where | Index | Shape |
|---|---|---|---|
| Restaurants along a journey (corridor shortlist) | `RouteDiscoveryService::corridor` | `restaurant_locations_location_gist` (GiST on the `geography` column) | The route is segmentized (2 km) and subdivided into compact pieces (≤ 5 vertices). Then one `ST_DWithin(l.location, piece, corridor_m)` index probe per piece, grouped per location, nearest first, `LIMIT journey.candidates.sql_limit` (2,000) |
| Service-area coverage along the route | `JourneyService::coverageAlong` | none used (see §3) | `count(*)` of active service areas with `ST_DWithin(sa.geometry::geography, route::geography, corridor_m)` |
| Market of a point | `MarketLocationResolver` | `markets.bounds` (`ST_Covers`) | point in polygon, few rows |
| Service area of a point / of a restaurant | `MarketLocationResolver`, restaurant visibility | `service_areas_geometry_gist` | `ST_Covers(sa.geometry, point)` |
| City near a point | `MarketLocationResolver` | `cities.center` | `ST_DWithin(center, point, city_match_radius)` |

Distances are computed on the spheroid (`geography`, metres). Coordinates are never logged or cached against a person (Module 26 / 38).

## 2. Measurements (medium dataset: 339 restaurant locations, 319 of them synthetic and spread along five corridors; 15 service areas)

| What | Result |
|---|---|
| Corridor shortlist, longest route planned in the runs (586 km) | 171 pieces, **one GiST index scan per piece at 0.057 ms**; whole statement **16.8 ms** execution, 13.3 ms planning |
| Corridor shortlist inside `GET /journeys/{journey}/restaurants` (cached candidates) | p50 171.7 ms / p95 188.8 ms for the whole request, 18 queries (`m40-baseline-medium.json`); the statement does not reach the top statements because the candidate set is cached for 300 s per journey and filter set |
| `POST /journeys/plan` (Delhi → Chandigarh / Ahmedabad → Mumbai, DEVELOPMENT routing provider) | p50 164 / 148 ms, 21 queries; the most expensive statement is the coverage count (10.1 ms mean) |
| Coverage count, 586 km route | **sequential scan** of `service_areas`, 18.8 ms for 15 areas |

The corridor shortlist is bounded three times:
- by the corridor width (`JOURNEY_CORRIDOR_*`: 500–10,000 m, default 3,000);
- by the SQL limit (2,000 nearest);
- by the shortlist cap (40) with a per-segment cap (8 per 25 km), so a dense city never crowds out the rest of a long route.

The routing provider's detour calls are made only for the shortlist, in batches (`JOURNEY_DETOUR_BATCH_SIZE` 25).

## 3. Finding: the coverage check does not use an index (REL-029)

- `ST_DWithin(sa.geometry::geography, …)` casts the column, so the GiST index on `geometry` cannot be used. Every active service area of the market is measured exactly against the whole route.
- With 15 areas this costs 10–19 ms. The cost grows linearly with the number of service areas, at roughly 1 ms per area for a long route, measured.
- Trial on the perf database: a GiST **expression** index on `(geometry::geography)` is usable, but brings the long route only from 18.8 to 11.9 ms. The bounding box of a long diagonal route overlaps most areas, so the exact distance still runs on most of them. The trial index was dropped and not shipped.
- **Recommendation:** before the market has more than about 100 service areas, rewrite the coverage count like the corridor query: probe per compact piece of the route against a geography GiST expression index, then `exists` per area. Measure it with a generated service-area set. Status: OPEN, not needed at the current size (15 areas).

## 4. Other notes

- `restaurant_locations.location` is `geography(Point)` with a GiST index. Restaurant listing reads latitude and longitude with `ST_Y` / `ST_X` (no spatial predicate). Visibility uses `ST_Covers(service_area, location)` per location through the GiST index on service areas.
- Long routes: the route geometry is simplified (25 m tolerance) and capped at 2,000 points before it is stored (`journey.geometry.*`). Pieces are built from the stored search geometry, so the planner never sees an unbounded line.
- The `with pieces as materialized` CTE is deliberate: the pieces are computed once whatever join order the planner chooses.
- **Restore check:** a restored database keeps its PostGIS objects. The restore tool verifies the PostGIS version, that every service area is `ST_IsValid`, and the number of GiST indexes (5) after every restore (`backup-restore.md`).
