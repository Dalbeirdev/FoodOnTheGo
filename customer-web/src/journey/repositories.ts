/**
 * Journey planning abstractions (Module 05).
 *
 * Pages never touch storage or providers directly. During development the Mock* implementations
 * in ./mock supply controlled data; ApiLocationRepository / GooglePlacesLocationRepository,
 * ApiJourneyRepository and ApiRouteRepository / GoogleRouteRepository replace them later without
 * rebuilding the screens.
 *
 * PRODUCT BOUNDARY: a journey is TRAVEL + restaurant discovery + pre-order + PICKUP at the restaurant.
 * There is no delivery destination anywhere in this model.
 */

export type LocationKind = 'city' | 'station' | 'airport' | 'landmark' | 'saved' | 'recent' | 'current'

export type Location = {
  id: string
  /** Primary label, e.g. "Chandigarh". */
  name: string
  /** Secondary label, e.g. "Chandigarh, India" or "Railway station". */
  sub: string
  kind: LocationKind
  lat: number | null
  lng: number | null
  /**
   * Where the data came from: 'mock' / 'dev-location' = the in-browser development providers; 'place' = a suggestion of
   * the backend's places provider; 'saved-address' = one of the customer's saved places; 'recent' = a recent place kept
   * by the backend; 'device' = the device's own position (asked for only when the traveller taps the button).
   */
  source: 'mock' | 'saved-address' | 'dev-location' | 'place' | 'recent' | 'device'
  /* Global place fields (WGS84 / SRID 4326). Optional because providers differ; never assume a country's format. */
  /** Provider place identifier. */
  placeId?: string
  formattedAddress?: string
  /** ISO 3166-1 alpha-2 */
  countryCode?: string
  adminArea?: string
  locality?: string
  postalCode?: string
  /** IANA time zone where known. */
  timezone?: string
  /** Backend: the id of the customer's saved place this location stands for (sent instead of coordinates). */
  savedLocationId?: string
  /** Backend: whether FoodOnTheGo serves this point (answered when the place was resolved). */
  coverage?: PlaceCoverage
}

export type PlaceCoverage = { supported: boolean; reason: string | null; city: string | null; serviceArea: string | null }

/** Generic global place — journeys, saved addresses and restaurants all resolve to this shape. */
export type Place = Location

export type JourneyStatus = 'draft' | 'ready' | 'route-available' | 'error'

/** mock = the browser's straight-line development estimate; development = the backend's synthetic routing model; live = a real routing provider. */
export type RouteMode = 'mock' | 'development' | 'live'

export type RouteSummary = {
  /** 'mock' in the browser's development mode, otherwise the backend's routing driver. */
  provider: string
  mode?: RouteMode
  distanceKm: number
  durationMin: number
  /** Ordered [lat, lng] pairs for a schematic preview. Real geometry (encoded polyline) arrives with the Routes API. */
  geometry: [number, number][]
  /** Named places the corridor passes — text alternative to the map. */
  waypoints: string[]
  /** True while the figures are development estimates (mock or the backend's development routing model). */
  isEstimate: boolean
  /** Backend: exact figures, flags the traveller should know about, and whether the provider used a traffic forecast. */
  distanceM?: number
  durationS?: number
  flags?: string[]
  trafficAware?: boolean
}

export type Journey = {
  id: string
  origin: Location
  destination: Location
  originLat: number | null
  originLng: number | null
  destinationLat: number | null
  destinationLng: number | null
  /** ISO date-time or null when the traveller did not set one. Optional by spec. */
  departureAt: string | null
  createdAt: string
  status: JourneyStatus
  route: RouteSummary | null
  /** Reference for future stored geometry (PostGIS) — null until the backend exists. */
  routeGeometryRef: string | null
  /** Backend journeys (Module 26): the journey lives on the server, expires, and carries its coverage and search defaults. */
  backend?: boolean
  owner?: 'guest' | 'customer'
  expiresAt?: string | null
  coverage?: JourneyCoverage
  search?: JourneySearchDefaults
}

export type JourneyCoverage = {
  /** NO_SERVICE_COVERAGE = the route exists but no served area lies along it. */
  alongRoute: 'COVERED' | 'NO_SERVICE_COVERAGE'
  serviceableAreas: number
  origin: PlaceCoverage
  destination: PlaceCoverage
  corridors: Array<{ name: string; highway: string | null }>
}
export type JourneySearchDefaults = { corridorM: number; corridorMinM: number; corridorMaxM: number; maxDetourMin: number; maxDetourMaxMin: number; candidateCap: number; pageSize: number; sorts: string[] }

export type JourneyInput = { origin: Location; destination: Location; departureAt: string | null }

export interface LocationRepository {
  /** Free-text search. Resolves to [] when nothing matches; rejects on lookup failure. */
  search(query: string): Promise<Location[]>
  /** Backend: a chosen suggestion gets its coordinates, time zone and coverage answer from the server. */
  resolve?(location: Location): Promise<Location>
  /** Recently used locations for this device / user. */
  recent(): Promise<Location[]>
  remember(location: Location): Promise<void>
}

export interface JourneyRepository {
  create(input: JourneyInput): Promise<Journey>
  get(id: string): Promise<Journey | null>
  update(journey: Journey): Promise<Journey>
  /** Most recent first. */
  recent(): Promise<Journey[]>
  remove(id: string): Promise<void>
}

export interface RouteRepository {
  getRoute(origin: Location, destination: Location): Promise<RouteSummary>
}

export type JourneyErrorCode =
  | 'lookup-failed' | 'no-route' | 'network' | 'not-found' | 'unexpected'
  /* backend */ | 'market-unsupported' | 'cross-border' | 'provider-unavailable' | 'rate-limited' | 'expired' | 'invalid' | 'place-not-found'

export class JourneyError extends Error {
  code: JourneyErrorCode
  constructor(code: JourneyError['code'], message: string) {
    super(message)
    this.name = 'JourneyError'
    this.code = code
  }
}

export type ValidationErrors = { origin?: string; destination?: string; form?: string }

/** Pure validation shared by the page, the state and the tests. */
export function validateJourney(origin: Location | null, destination: Location | null, originText = '', destinationText = ''): ValidationErrors {
  const err: ValidationErrors = {}
  if (!origin) err.origin = originText.trim() ? 'Choose your starting point from the suggestions' : 'Enter your starting point'
  if (!destination) err.destination = destinationText.trim() ? 'Choose your destination from the suggestions' : 'Enter your destination'
  if (origin && destination) {
    const same = origin.id === destination.id || (origin.lat !== null && origin.lat === destination.lat && origin.lng === destination.lng) || origin.name.trim().toLowerCase() === destination.name.trim().toLowerCase()
    if (same) err.destination = 'Destination must be different from the starting point'
  }
  if ((origin && origin.lat === null) || (destination && destination.lat === null)) err.form = 'This location has no coordinates yet — pick another suggestion'
  return err
}
