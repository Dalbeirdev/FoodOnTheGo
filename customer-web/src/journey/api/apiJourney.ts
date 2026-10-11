/**
 * Journeys on the backend (Module 26): places, journey planning and route-based restaurant discovery.
 *
 *   GET  /places/search?q=              suggestions of the backend's places provider, inside the market's country
 *   POST /places/resolve {place_id}     coordinates, time zone and the coverage answer for one suggestion
 *   POST /journeys/plan                 the route from the routing provider + an EXPIRING journey context
 *   GET  /journeys/{id}                 the journey, for its owner only
 *   GET  /journeys/{id}/restaurants     restaurants along the route with ACTUAL detours and arrival estimates
 *
 * Nothing about a route is calculated here: distances, durations, geometry, detours, arrival times and "open at
 * arrival" are the backend's. A guest's journey is readable only with the token the backend issued when it was
 * planned; it is kept for this browser tab (sessionStorage) and sent as X-Journey-Token — never in a URL.
 */
import { api, ApiError, tokens } from '../../api/client'
import { toRestaurant, type PublicRestaurantDto, type RestaurantAvailabilityDto } from '../../restaurants/api/restaurantData'
import type { Availability, DiscoveryQuery, FilterDefinition, JourneyLike, ResultPage, RouteRestaurantResult, SearchMeta, SortKey } from '../../repositories/types'
import { JourneyError, type Journey, type JourneyInput, type JourneyRepository, type Location, type LocationKind, type LocationRepository, type PlaceCoverage, type RouteRepository, type RouteSummary } from '../repositories'

/* ------------------------------------------------------------------ wire formats (OpenAPI: PlaceSuggestion, ResolvedPlace, Journey, JourneyRestaurantPage) */
export type ProviderRefDto = { name: string; mode: 'development' | 'live' }
export type PlaceSuggestionDto = { provider: string; place_id: string; name: string; formatted_address: string; country_code: string | null; kind: 'city' | 'station' | 'airport' | 'landmark' | 'address'; region: string | null; locality: string | null }
export type PlaceSearchDto = { data: PlaceSuggestionDto[]; country_code: string; provider: ProviderRefDto }
export type CoverageDto = { supported: boolean; reason: string | null; market?: string | null; city: string | null; service_area: string | null }
export type ResolvedPlaceDto = {
  place: { provider: string; id: string; name: string; kind: string }
  location: { latitude: number; longitude: number; formatted_address: string | null; country_code: string | null; region: string | null; locality: string | null; postal_code: string | null; timezone: string | null }
  coverage: CoverageDto
  provider: ProviderRefDto
}
export type JourneyEndDto = {
  label: string | null; formatted_address: string | null; location: { latitude: number; longitude: number }
  country_code: string | null; region: string | null; locality: string | null; timezone: string | null
  place: { provider: string | null; id: string } | null; source: 'place' | 'coordinates' | 'saved_location'; kind: string
}
export type JourneyDto = {
  id: string; status: 'ACTIVE' | 'EXPIRED'; owner: 'guest' | 'customer'; market: string
  origin: JourneyEndDto; destination: JourneyEndDto
  departure: { at: string | null; given: boolean; timezone: string | null }
  route: {
    distance_meters: number; duration_seconds: number; encoded_polyline: string; geometry: { type: 'LineString'; coordinates: [number, number][] }
    bounds: { west: number; south: number; east: number; north: number }; legs: Array<{ distance_meters: number; duration_seconds: number }>
    flags: string[]; warnings: string[]; provider: ProviderRefDto; traffic_aware: boolean; preferences: Record<string, boolean>; calculated_at: string; points: number
  }
  coverage: { origin: CoverageDto; destination: CoverageDto; along_route: 'COVERED' | 'NO_SERVICE_COVERAGE'; serviceable_areas: number; corridor_width_meters: number; corridors: Array<{ id: string; name: string; highway: string | null }> }
  search: { corridor_width_meters: number; corridor_min_meters: number; corridor_max_meters: number; max_detour_minutes: number; max_detour_max_minutes: number; candidate_cap: number; page_size: number; sorts: string[] }
  expires_at: string; created_at: string
  /** Guests only, once, in the answer to POST /journeys/plan. */
  guest_token?: string
}
export type RouteContextDto = {
  distance_to_route_meters: number; route_progress: number; segment: number
  detour: { seconds: number; minutes: number; distance_meters: number }
  arrival: { estimated_at: string; local_time: string; local_date: string; timezone: string; leg_seconds: number; leg_distance_meters: number; is_estimate: true }
  open_at_arrival: boolean; orderable_at_arrival: boolean; availability_at_arrival: RestaurantAvailabilityDto; accepting_orders_now: boolean; prep_minutes: number | null
}
export type JourneyRestaurantDto = { restaurant: PublicRestaurantDto; route_context: RouteContextDto }
export type EmptyReasonDto = 'NO_SERVICE_COVERAGE' | 'NO_RESTAURANTS_IN_CORRIDOR' | 'NO_REACHABLE_RESTAURANTS' | 'FILTERS' | null
export type JourneyRestaurantPageDto = {
  data: JourneyRestaurantDto[]
  meta: {
    current_page: number; per_page: number; total: number; last_page: number; journey_id: string
    search: { sort: string; corridor_width_meters: number; max_detour_minutes: number; open_at_arrival: boolean; cuisine: string[]; feature: string[]; pickup_method: string | null; q: string | null; corridor_min_meters: number; corridor_max_meters: number; max_detour_max_minutes: number; candidate_cap: number }
    departure_at: string; calculated_at: string; cached: boolean; provider: ProviderRefDto
    coverage: { along_route: 'COVERED' | 'NO_SERVICE_COVERAGE'; serviceable_areas: number }
    counts: { corridor_rows: number; shortlist: number; detour_requested: number; unreachable: number; provider_calls: number; matching: number; dropped_since_calculation: number }
    empty_reason: EmptyReasonDto
  }
}
type RecentLocationDto = { id: string; label: string; formatted_address: string | null; location: { latitude: number; longitude: number }; place: { provider: string | null; id: string } | null; country_code: string | null; timezone: string | null }

/* ------------------------------------------------------------------ errors */
const MESSAGES: Partial<Record<JourneyError['code'], string>> = {
  'no-route': 'No road route is available between these locations.',
  'market-unsupported': "FoodOnTheGo isn't available at one of these locations yet. Choose places in a supported area.",
  'cross-border': 'Journeys between two countries are not supported yet.',
  'provider-unavailable': 'Route planning is temporarily unavailable. Please try again in a moment.',
  'rate-limited': 'Too many requests. Please wait a moment and try again.',
  network: 'Cannot reach FoodOnTheGo right now. Check your connection and try again.',
  expired: 'This journey has expired. Plan it again to see current restaurants.',
  'place-not-found': 'This place could not be found. Pick another suggestion.',
}

/** The backend's error vocabulary as the journey screens understand it. The server's own message is kept when it explains a field. */
export function toJourneyError(e: unknown, fallback = 'Could not prepare the journey. Please try again.'): JourneyError {
  if (e instanceof JourneyError) return e
  if (!(e instanceof ApiError)) return new JourneyError('unexpected', fallback)
  const code: JourneyError['code'] =
    e.kind === 'network' ? 'network'
      : e.code === 'route_not_found' ? 'no-route'
        : e.code === 'market_unsupported' || e.code === 'market_unavailable' ? 'market-unsupported'
          : e.code === 'cross_border_unsupported' ? 'cross-border'
            : e.code === 'place_not_found' ? 'place-not-found'
              : e.code === 'journey_expired' ? 'expired'
                : e.code === 'journey_not_found' ? 'not-found'
                  : e.status === 429 || e.code === 'provider_rate_limited' ? 'rate-limited'
                    : e.status === 503 || e.kind === 'server' ? 'provider-unavailable'
                      : e.kind === 'validation' ? 'invalid'
                        : 'unexpected'
  const firstField = Object.values(e.errors)[0]?.[0]
  return new JourneyError(code, code === 'invalid' ? firstField ?? e.message : MESSAGES[code] ?? e.message ?? fallback)
}

/* ------------------------------------------------------------------ guest journey tokens (this tab only) */
const TOKENS_KEY = 'fotg.journey.tokens'
const readTokens = (): Record<string, string> => { try { return JSON.parse(sessionStorage.getItem(TOKENS_KEY) ?? '{}') as Record<string, string> } catch { return {} } }
export const journeyTokens = {
  get(id: string): string | null { return readTokens()[id] ?? null },
  set(id: string, token: string) {
    // The newest five: a guest token is only useful while its journey is alive (30 minutes).
    const kept = Object.entries(readTokens()).filter(([k]) => k !== id).slice(-4)
    try { sessionStorage.setItem(TOKENS_KEY, JSON.stringify(Object.fromEntries([...kept, [id, token]]))) } catch { /* storage unavailable: the journey lasts for this page only */ }
  },
  forget(id: string) { const all = readTokens(); delete all[id]; try { sessionStorage.setItem(TOKENS_KEY, JSON.stringify(all)) } catch { /* ignore */ } },
  headers(id: string): Record<string, string> { const t = journeyTokens.get(id); return t ? { 'X-Journey-Token': t } : {} },
}

/* ------------------------------------------------------------------ mapping */
const KINDS: Record<string, LocationKind> = { city: 'city', station: 'station', airport: 'airport', landmark: 'landmark', address: 'landmark', saved: 'saved', point: 'landmark' }
const coverage = (c: CoverageDto): PlaceCoverage => ({ supported: c.supported, reason: c.reason, city: c.city, serviceArea: c.service_area })

/** A suggestion is text only: it has no coordinates until the backend resolves it. */
export function toSuggestion(d: PlaceSuggestionDto): Location {
  return { id: d.place_id, name: d.name, sub: d.formatted_address, kind: KINDS[d.kind] ?? 'landmark', lat: null, lng: null, source: 'place', placeId: d.place_id, formattedAddress: d.formatted_address, countryCode: d.country_code ?? undefined, adminArea: d.region ?? undefined, locality: d.locality ?? undefined }
}
export function toResolved(l: Location, d: ResolvedPlaceDto): Location {
  return {
    ...l, name: l.name || d.place.name, lat: d.location.latitude, lng: d.location.longitude, formattedAddress: d.location.formatted_address ?? l.formattedAddress,
    countryCode: d.location.country_code ?? l.countryCode, adminArea: d.location.region ?? l.adminArea, locality: d.location.locality ?? l.locality,
    postalCode: d.location.postal_code ?? undefined, timezone: d.location.timezone ?? undefined, coverage: coverage(d.coverage),
  }
}
const toRecent = (d: RecentLocationDto): Location => ({ id: `recent-${d.id}`, name: d.label, sub: d.formatted_address ?? '', kind: 'recent', lat: d.location.latitude, lng: d.location.longitude, source: 'recent', placeId: d.place?.id, formattedAddress: d.formatted_address ?? undefined, countryCode: d.country_code ?? undefined, timezone: d.timezone ?? undefined })

function toEnd(d: JourneyEndDto, cov: CoverageDto): Location {
  return {
    id: d.place?.id ?? `${d.source}:${d.location.latitude},${d.location.longitude}`, name: d.label ?? d.formatted_address ?? 'Pinned location', sub: d.formatted_address ?? '',
    kind: KINDS[d.kind] ?? 'landmark', lat: d.location.latitude, lng: d.location.longitude, source: d.source === 'saved_location' ? 'saved-address' : d.source === 'place' ? 'place' : 'device',
    placeId: d.place?.id, formattedAddress: d.formatted_address ?? undefined, countryCode: d.country_code ?? undefined, adminArea: d.region ?? undefined, locality: d.locality ?? undefined,
    timezone: d.timezone ?? undefined, coverage: coverage(cov),
  }
}

/** Kilometres for the summary line: one decimal on short trips, whole numbers on long ones. */
const km = (meters: number) => (meters < 100_000 ? Math.round(meters / 100) / 10 : Math.round(meters / 1000))

export function toRoute(d: JourneyDto['route']): RouteSummary {
  return {
    provider: d.provider.name, mode: d.provider.mode, distanceKm: km(d.distance_meters), durationMin: Math.max(1, Math.round(d.duration_seconds / 60)),
    // GeoJSON positions are [lng, lat]; the map components take [lat, lng].
    geometry: d.geometry.coordinates.map(([lng, lat]) => [lat, lng] as [number, number]), waypoints: [], isEstimate: d.provider.mode === 'development',
    distanceM: d.distance_meters, durationS: d.duration_seconds, flags: d.flags, trafficAware: d.traffic_aware,
  }
}

export function toJourney(d: JourneyDto): Journey {
  const origin = toEnd(d.origin, d.coverage.origin), destination = toEnd(d.destination, d.coverage.destination)
  return {
    id: d.id, origin, destination, originLat: origin.lat, originLng: origin.lng, destinationLat: destination.lat, destinationLng: destination.lng,
    departureAt: d.departure.given ? d.departure.at : null, createdAt: d.created_at, status: 'route-available', route: toRoute(d.route), routeGeometryRef: d.id,
    backend: true, owner: d.owner, expiresAt: d.expires_at,
    coverage: { alongRoute: d.coverage.along_route, serviceableAreas: d.coverage.serviceable_areas, origin: coverage(d.coverage.origin), destination: coverage(d.coverage.destination), corridors: d.coverage.corridors.map((c) => ({ name: c.name, highway: c.highway })) },
    search: { corridorM: d.search.corridor_width_meters, corridorMinM: d.search.corridor_min_meters, corridorMaxM: d.search.corridor_max_meters, maxDetourMin: d.search.max_detour_minutes, maxDetourMaxMin: d.search.max_detour_max_minutes, candidateCap: d.search.candidate_cap, pageSize: d.search.page_size, sorts: d.search.sorts },
  }
}

/**
 * One end of a journey as the API takes it — exactly one form: a saved place by its id, a provider place by its id
 * (the backend resolves it again: the text and coordinates the browser holds are never trusted), or coordinates.
 */
export function toEndBody(l: Location): Record<string, unknown> {
  if (l.savedLocationId) return { saved_location_id: l.savedLocationId, label: l.name }
  // A searched place — also when it comes back from the recent places — travels as its id: the backend resolves it again.
  if ((l.source === 'place' || l.source === 'recent') && l.placeId) return { place_id: l.placeId }
  return { lat: l.lat, lng: l.lng, label: l.name, ...(l.formattedAddress ? { formatted_address: l.formattedAddress } : {}) }
}

/* ------------------------------------------------------------------ repositories */
const signedIn = () => tokens.get('customer') !== null
const DEVICE_RECENT = 'fotg.journey.recent-places'
const DEVICE_JOURNEYS = 'fotg.journey.recent'
const session = {
  read<T>(key: string, fallback: T): T { try { const raw = sessionStorage.getItem(key); return raw ? (JSON.parse(raw) as T) : fallback } catch { return fallback } },
  write(key: string, value: unknown) { try { sessionStorage.setItem(key, JSON.stringify(value)) } catch { /* storage unavailable */ } },
}

export class ApiLocationRepository implements LocationRepository {
  async search(query: string): Promise<Location[]> {
    if (query.trim().length < 2) return []
    try { return (await api<PlaceSearchDto>('/places/search', { query: { q: query.trim() } })).data.map(toSuggestion) } catch (e) { throw toJourneyError(e, 'Location search failed. Please try again.') }
  }
  async resolve(location: Location): Promise<Location> {
    if (location.source !== 'place' || !location.placeId || location.lat !== null) return location
    try { return toResolved(location, await api<ResolvedPlaceDto>('/places/resolve', { method: 'POST', body: { place_id: location.placeId } })) } catch (e) { throw toJourneyError(e, 'This place could not be loaded. Please try again.') }
  }
  /** Signed in: the recent places the backend keeps (Module 25 — bounded, clearable in the account). Guests: this tab only. */
  async recent(): Promise<Location[]> {
    if (!signedIn()) return session.read<Location[]>(DEVICE_RECENT, [])
    try { return (await api<RecentLocationDto[]>('/customer/recent-locations')).slice(0, 5).map(toRecent) } catch { return [] }
  }
  /**
   * Signed in: the backend records the searched places of a planned journey itself. Guests: remembered for this tab, never sent
   * anywhere. Only a place that was searched for is remembered — never the device's position, never a saved place.
   */
  async remember(location: Location): Promise<void> {
    if (signedIn() || location.lat === null || !location.placeId || (location.source !== 'place' && location.source !== 'recent')) return
    const rest = session.read<Location[]>(DEVICE_RECENT, []).filter((l) => l.id !== location.id)
    session.write(DEVICE_RECENT, [{ ...location, kind: 'recent' }, ...rest].slice(0, 5))
  }
}

export class ApiJourneyRepository implements JourneyRepository {
  async create(input: JourneyInput): Promise<Journey> {
    let dto: JourneyDto
    try {
      dto = await api<JourneyDto>('/journeys/plan', { method: 'POST', body: { origin: toEndBody(input.origin), destination: toEndBody(input.destination), ...(input.departureAt ? { departure_at: input.departureAt } : {}) } })
    } catch (e) { throw toJourneyError(e) }
    if (dto.guest_token) journeyTokens.set(dto.id, dto.guest_token)
    const journey = toJourney(dto)
    // "Recent journeys" in this mode is a list for this browser tab: the backend keeps no journey history.
    const rest = session.read<Journey[]>(DEVICE_JOURNEYS, []).filter((j) => !(j.origin.name === journey.origin.name && j.destination.name === journey.destination.name))
    session.write(DEVICE_JOURNEYS, [{ ...journey, route: journey.route ? { ...journey.route, geometry: [] } : null }, ...rest].slice(0, 5))
    return journey
  }
  /**
   * null = not this visitor's journey (or gone). An expired journey rejects with code 'expired' — its token is KEPT: the
   * backend says "expired" only to the owner, so without the token the same link would read "not found" next time.
   */
  async get(id: string): Promise<Journey | null> {
    try { return toJourney(await api<JourneyDto>(`/journeys/${encodeURIComponent(id)}`, { headers: journeyTokens.headers(id) })) } catch (e) {
      const error = toJourneyError(e, 'The journey could not be loaded. Please try again.')
      if (error.code === 'not-found' || (e instanceof ApiError && e.status === 404)) return null
      throw error
    }
  }
  /** The backend owns the journey: there is nothing to save from the browser. */
  async update(journey: Journey): Promise<Journey> { return journey }
  async recent(): Promise<Journey[]> { return session.read<Journey[]>(DEVICE_JOURNEYS, []) }
  async remove(id: string): Promise<void> { session.write(DEVICE_JOURNEYS, session.read<Journey[]>(DEVICE_JOURNEYS, []).filter((j) => j.id !== id)); journeyTokens.forget(id) }
}

/** In this mode the route arrives with the planned journey; a separate route lookup never happens. */
export class ApiRouteRepository implements RouteRepository {
  async getRoute(): Promise<RouteSummary> { throw new JourneyError('unexpected', 'The route is part of the planned journey.') }
}

/* ------------------------------------------------------------------ discovery along the journey */
const SORTS: Partial<Record<SortKey, string>> = { recommended: 'recommended', lowestDetour: 'lowest_detour', routeOrder: 'route_order', nearestToRoute: 'nearest_to_route', fastestPickup: 'fastest_pickup', highestRated: 'highest_rated' }
const EMPTY: Record<Exclude<EmptyReasonDto, null>, NonNullable<ResultPage['emptyReason']>> = { NO_SERVICE_COVERAGE: 'coverage', NO_RESTAURANTS_IN_CORRIDOR: 'corridor', NO_REACHABLE_RESTAURANTS: 'unreachable', FILTERS: 'filters' }

/** The restaurant's state at the expected arrival, in the vocabulary of the cards. */
export function arrivalAvailability(c: RouteContextDto): Availability {
  const a = c.availability_at_arrival, arrival = Date.parse(c.arrival.estimated_at)
  const within = (iso: string | null, minutes: number) => iso !== null && Date.parse(iso) - arrival <= minutes * 60_000
  const status: Availability['status'] = a.open_state === 'TEMPORARILY_CLOSED' ? 'temporarily_closed' : a.open_now ? (within(a.closes_at, 45) ? 'closing_soon' : 'open') : within(a.opens_next_at, 60) ? 'opening_soon' : 'closed'
  return { status, acceptingOrders: a.accepting_orders && a.reason !== 'AREA_UNAVAILABLE' && a.reason !== 'PICKUP_UNAVAILABLE', nextChangeAt: a.open_now ? a.closes_at : a.opens_next_at, localTime: c.arrival.estimated_at }
}

export function toRouteResult(d: JourneyRestaurantDto): RouteRestaurantResult {
  const c = d.route_context
  return {
    // Module 34: the backend's own rating of the location. Without it (an older backend) nothing is shown along a backend
    // journey — the development fixtures' sample ratings are never used there.
    restaurant: d.restaurant.rating ? toRestaurant(d.restaurant) : { ...toRestaurant(d.restaurant), rating: 0, reviewCount: 0, ratingIsSample: false },
    distanceFromRouteM: c.distance_to_route_meters, detourDistanceM: c.detour.distance_meters, detourDurationMin: c.detour.minutes,
    estimatedArrival: c.arrival.estimated_at,
    // When the food is ready is a pickup-slot question (a later module): nothing is promised here.
    estimatedPickupReady: null, routePosition: c.route_progress, availability: arrivalAvailability(c), atArrival: true, orderableAtArrival: c.orderable_at_arrival,
  }
}

/** The discovery query of the page as request parameters. Only filters the backend really applies are sent. */
export function toDiscoveryQuery(journey: JourneyLike, q: DiscoveryQuery): Record<string, string | number | boolean | undefined> {
  const f = q.filters ?? {}, s = journey.search
  const clamp = (v: number, min: number, max: number) => Math.round(Math.max(min, Math.min(max, v)))
  return {
    sort: SORTS[q.sort ?? 'recommended'] ?? 'recommended',
    corridor_width_meters: q.corridorM !== undefined && q.corridorM !== null ? clamp(q.corridorM, s?.corridorMinM ?? 500, s?.corridorMaxM ?? 10_000) : undefined,
    max_detour_minutes: typeof f.detourTime === 'number' ? clamp(f.detourTime, 1, s?.maxDetourMaxMin ?? 60) : undefined,
    open_at_arrival: f.openNow === true ? true : undefined,
    cuisine: Array.isArray(f.cuisine) && f.cuisine.length ? f.cuisine.join(',') : undefined,
    min_rating: typeof f.rating === 'number' ? Math.max(1, Math.min(5, f.rating)) : undefined,
    q: q.search?.trim() ? q.search.trim().slice(0, 80) : undefined,
    'page[number]': q.cursor && /^\d+$/.test(q.cursor) ? Number(q.cursor) : 1,
    'page[size]': Math.min(q.limit ?? 6, 25),
  }
}

/** The filters of a backend journey: exactly the ones the API applies — no control here is decoration. */
export function journeyFilterDefinitions(journey: JourneyLike, cuisines: Array<{ code: string; name: string; restaurants: number }>): FilterDefinition[] {
  return [
    { id: 'cuisine', labelKey: 'filter.cuisine', kind: 'multi', options: cuisines.filter((c) => c.restaurants > 0).map((c) => ({ value: c.code, label: c.name })) },
    { id: 'openNow', labelKey: 'filter.openAtArrival', kind: 'toggle', default: false },
    { id: 'detourTime', labelKey: 'filter.detourTime', kind: 'max', unit: 'minutes', min: 5, max: journey.search?.maxDetourMaxMin ?? 60, step: 5, journeyOnly: true },
    // Module 34: applied by the backend on the real averages — only offered when the backend can sort by rating at all.
    ...(journey.search?.sorts?.includes('highest_rated') ? [{ id: 'rating', labelKey: 'filter.rating', kind: 'min', unit: 'rating', min: 3, max: 4.5, step: 0.5 } as FilterDefinition] : []),
  ]
}

export async function discoverAlongJourney(journey: JourneyLike, q: DiscoveryQuery): Promise<ResultPage> {
  let page: JourneyRestaurantPageDto
  try {
    page = await api<JourneyRestaurantPageDto>(`/journeys/${encodeURIComponent(journey.id)}/restaurants`, { query: toDiscoveryQuery(journey, q), headers: journeyTokens.headers(journey.id) })
  } catch (e) { throw toJourneyError(e, 'Restaurants along this journey could not be loaded. Please try again.') }

  const items = page.data.map(toRouteResult)
  rememberRouteContext(journey.id, items)
  const m = page.meta
  const searchMeta: SearchMeta = { calculatedAt: m.calculated_at, departureAt: m.departure_at, cached: m.cached, providerName: m.provider.name, providerMode: m.provider.mode, maxDetourMin: m.search.max_detour_minutes, corridorM: m.search.corridor_width_meters, corridorRows: m.counts.corridor_rows, detourRequested: m.counts.detour_requested }
  const emptyReason = m.empty_reason ? EMPTY[m.empty_reason] : null
  return {
    items, total: m.total, nextCursor: m.current_page < m.last_page ? String(m.current_page + 1) : null, corridorM: m.search.corridor_width_meters, emptyReason, searchMeta,
    // A route with no served area along it is the "we do not cover this route yet" state — an answer, not an error.
    availability: emptyReason === 'coverage' ? { supported: false, reason: 'area', messageKey: 'market.unavailable.route' } : undefined,
  }
}

/* ------------------------------------------------------------------ route context of the restaurants a journey found */
/**
 * What the last discovery of a journey said about each restaurant (detour, arrival) — so the restaurant page, the cart
 * and the pickup screens can show the backend's figures instead of estimating anything. Kept for this tab; replaced when
 * another journey is searched. A restaurant the journey did not find has no route context.
 */
type StoredContext = { distanceFromRouteM: number; detourDistanceM: number; detourDurationMin: number; estimatedArrival: string; routePosition: number; orderableAtArrival: boolean; availability: Availability }
const CONTEXT_KEY = 'fotg.journey.context'
let contextCache: { journeyId: string; bySlug: Record<string, StoredContext> } | null = null
const loadContext = () => contextCache ?? (contextCache = session.read<{ journeyId: string; bySlug: Record<string, StoredContext> } | null>(CONTEXT_KEY, null))

export function rememberRouteContext(journeyId: string, items: RouteRestaurantResult[]) {
  const current = loadContext()
  const bySlug = current?.journeyId === journeyId ? { ...current.bySlug } : {}
  for (const x of items) {
    if (x.distanceFromRouteM === null || x.detourDurationMin === null || x.estimatedArrival === null) continue
    bySlug[x.restaurant.slug] = { distanceFromRouteM: x.distanceFromRouteM, detourDistanceM: x.detourDistanceM ?? 0, detourDurationMin: x.detourDurationMin, estimatedArrival: x.estimatedArrival, routePosition: x.routePosition ?? 0, orderableAtArrival: x.orderableAtArrival ?? false, availability: x.availability }
  }
  contextCache = { journeyId, bySlug }
  session.write(CONTEXT_KEY, contextCache)
}
export function storedRouteContext(journeyId: string, slug: string): StoredContext | null {
  const current = loadContext()
  return current?.journeyId === journeyId ? current.bySlug[slug] ?? null : null
}
export function resetJourneyApiState() { contextCache = null; try { sessionStorage.removeItem(CONTEXT_KEY); sessionStorage.removeItem(TOKENS_KEY); sessionStorage.removeItem(DEVICE_RECENT); sessionStorage.removeItem(DEVICE_JOURNEYS) } catch { /* ignore */ } }
